<?php

namespace Drupal\entity_usage;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Utility\Error;
use Drupal\entity_usage\Events\EntityUsageEvent;
use Drupal\entity_usage\Events\Events;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Manages Entity Usage integration with Batch API.
 *
 * @phpstan-type BatchContext array{sandbox: array{progress?: int, total?: int<0, max>, current_item?: int, current_id?: int|string|null, revision_ids?: list<int>, entity_ids?: array<int|string, string>, batch_entity_revision?: array{status: int, current_vid: int, start: int}}, results: int[], finished: int|float, message: string|\Drupal\Core\StringTranslation\TranslatableMarkup}
 */
class EntityUsageBatchManager implements LoggerAwareInterface {

  use LoggerAwareTrait;
  use StringTranslationTrait;

  /**
   * Table name to bulk entity usage data to.
   */
  const BULK_TABLE_NAME = 'entity_usage_bulk';

  /**
   * The size of the batch for the revision queries.
   */
  const REVISION_BATCH_SIZE = 15;

  /**
   * The number of revisions to load when in bulk mode.
   */
  const BULK_BATCH_SIZE = 200;

  /**
   * The number of IDs to load when in bulk mode.
   */
  const BULK_ID_LOAD = 100000;

  /**
   * How much of an exception message is kept when logging a failed chunk.
   */
  const MAX_LOGGED_MESSAGE_LENGTH = 4096;

  /**
   * Creates a EntityUsageBatchManager object.
   */
  final public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    TranslationInterface $stringTranslation,
    private ConfigFactoryInterface $configFactory,
    private ?EntityUsageInterface $entityUsage = NULL,
  ) {
    $this->setStringTranslation($stringTranslation);
    if ($entityUsage === NULL) {
      // @phpstan-ignore-next-line
      $this->entityUsage = \Drupal::service('entity_usage.usage');
    }
  }

  /**
   * Recreate the entity usage statistics.
   *
   * Generate a batch to recreate the statistics for all entities.
   * Note that if we force all statistics to be created, there is no need to
   * separate them between source/target cases. If all entities are going to
   * be re-tracked, tracking all of them as source is enough, because there
   * could never be a target without a source.
   *
   * @param bool $keep_existing_records
   *   (optional) If TRUE, existing usage records won't be deleted. Defaults to
   *   FALSE.
   * @param string[]|null $entity_types
   *   (optional) A list of entity type IDs to recreate statistics for. If
   *   NULL (the default), all entity types enabled for tracking are
   *   recreated. If provided, only usage records for these entity types are
   *   deleted and rebuilt; other entity types are left untouched.
   *
   * @throws \InvalidArgumentException
   *   Thrown if any of the given entity types is not enabled for tracking.
   */
  public function recreate($keep_existing_records = FALSE, ?array $entity_types = NULL): void {
    $batch = $this->generateBatch($keep_existing_records, $entity_types);
    batch_set($batch);
  }

  /**
   * Create a batch to process the entity types in bulk.
   *
   * @param bool $keep_existing_records
   *   (optional) If TRUE existing usage records won't be deleted. Defaults to
   *   FALSE.
   * @param string[]|null $entity_types
   *   (optional) A list of entity type IDs to recreate statistics for. If
   *   NULL (the default), all entity types enabled for tracking are
   *   recreated. If provided, only usage records for these entity types are
   *   deleted and rebuilt; other entity types are left untouched.
   *
   * @return array
   *   The batch array.
   *
   * @throws \InvalidArgumentException
   *   Thrown if any of the given entity types is not enabled for tracking.
   */
  public function generateBatch($keep_existing_records = FALSE, ?array $entity_types = NULL): array {
    $trackable_entity_types = self::getEntityTypesToTrack($this->configFactory->get('entity_usage.settings'), $this->entityTypeManager);

    if ($entity_types !== NULL) {
      $invalid_entity_types = array_diff($entity_types, $trackable_entity_types);
      if ($invalid_entity_types) {
        throw new \InvalidArgumentException(sprintf('The following entity types are not enabled for tracking by Entity Usage: %s', implode(', ', $invalid_entity_types)));
      }
      $entity_types_to_process = array_values(array_unique($entity_types));
    }
    else {
      $entity_types_to_process = $trackable_entity_types;
    }

    $batch = new BatchBuilder();
    $batch
      ->setTitle($this->t('Updating entity usage statistics.'))
      ->setProgressMessage($this->t('Processed @current of @total entity types.'))
      ->setErrorMessage($this->t('This batch encountered an error.'))
      ->setFinishCallback('\Drupal\entity_usage\EntityUsageBatchManager::batchFinished');

    if (!$keep_existing_records) {
      if ($entity_types === NULL) {
        $batch->addOperation('\Drupal\entity_usage\EntityUsageBatchManager::truncateTable');
      }
      else {
        foreach ($entity_types_to_process as $entity_type_id) {
          $batch->addOperation(
            '\Drupal\entity_usage\EntityUsageBatchManager::deleteSourcesForEntityType',
            [$entity_type_id],
          );
        }
      }
    }

    $bulk_mode = !$keep_existing_records && $this->entityUsage instanceof EntityUsageBulkInterface;

    if ($bulk_mode) {
      $batch->addOperation('\Drupal\entity_usage\EntityUsageBatchManager::createBulkTable');
    }

    foreach ($entity_types_to_process as $entity_type_id) {
      $batch->addOperation(
        '\Drupal\entity_usage\EntityUsageBatchManager::updateSourcesBatchWorker',
        [$entity_type_id, $keep_existing_records],
      );
    }

    if ($bulk_mode) {
      $batch->addOperation('\Drupal\entity_usage\EntityUsageBatchManager::copyBulkTable');
      $batch->addOperation('\Drupal\entity_usage\EntityUsageBatchManager::triggerEvents');
      $batch->addOperation('\Drupal\entity_usage\EntityUsageBatchManager::dropBulkTable');
    }

    return $batch->toArray();
  }

  /**
   * Batch operation worker to create the bulk loading table.
   */
  public static function createBulkTable(array &$context): void {
    \Drupal::moduleHandler()->loadInclude('entity_usage', 'install');
    $entity_usage_schema = entity_usage_schema();
    $entity_usage_schema['entity_usage']['description'] = 'Copy of the entity_usage table for bulk loading';
    unset($entity_usage_schema['entity_usage']['indexes']);
    $db_schema = \Drupal::database()->schema();
    if ($db_schema->tableExists(static::BULK_TABLE_NAME)) {
      $db_schema->dropTable(static::BULK_TABLE_NAME);
    }
    $db_schema->createTable(static::BULK_TABLE_NAME, $entity_usage_schema['entity_usage']);
    $context['message'] = t('Created the entity usage bulk table');
  }

  /**
   * Batch operation worker to copy the bulk loading table.
   */
  public static function copyBulkTable(array &$context): void {
    \Drupal::moduleHandler()->loadInclude('entity_usage', 'install');
    $database = \Drupal::database();
    $query = match ($database->databaseType()) {
      'sqlite' => 'INSERT OR IGNORE INTO {entity_usage} SELECT * FROM {' . static::BULK_TABLE_NAME . '};',
      'pgsql' => 'INSERT INTO {entity_usage} SELECT * FROM {' . static::BULK_TABLE_NAME . '} ON CONFLICT DO NOTHING;',
      'mysql' => 'INSERT IGNORE INTO {entity_usage} SELECT * FROM {' . static::BULK_TABLE_NAME . '};',
      default => 'INSERT INTO {entity_usage} SELECT * FROM {' . static::BULK_TABLE_NAME . '};',
    };
    $database->query($query)->execute();
    $context['message'] = t('Loaded the entity usage table from the bulk table');
  }

  /**
   * Batch operation to trigger events after bulk loading.
   */
  public static function triggerEvents(array &$context): void {
    $database = \Drupal::database();
    /** @var \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $dispatcher */
    $dispatcher = \Drupal::service('event_dispatcher');
    $dispatch_event = !$dispatcher instanceof EventDispatcherInterface || $dispatcher->hasListeners(Events::USAGE_REGISTER);
    if ($dispatch_event) {
      if (empty($context['sandbox']['total'])) {
        $context['sandbox']['progress'] = 0;
        $context['sandbox']['total'] = $database->select(static::BULK_TABLE_NAME)->countQuery()->execute()->fetchField();
      }
      $results = $database
        ->select(static::BULK_TABLE_NAME)
        ->fields(static::BULK_TABLE_NAME)
        ->range($context['sandbox']['progress'], 200)
        ->orderBy('target_id')
        ->orderBy('target_id_string')
        ->orderBy('target_type')
        ->orderBy('source_id')
        ->orderBy('source_id_string')
        ->orderBy('source_type')
        ->orderBy('source_type')
        ->orderBy('source_langcode')
        ->orderBy('source_vid')
        ->orderBy('method')
        ->orderBy('field_name')
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);
      foreach ($results as $insert) {
        $context['sandbox']['progress']++;
        $target_id_column = (int) $insert['target_id'] > 0 ? 'target_id' : 'target_id_string';
        $source_id_column = (int) $insert['source_id'] > 0 ? 'source_id' : 'source_id_string';
        $event = new EntityUsageEvent($insert[$target_id_column], $insert['target_type'], $insert[$source_id_column], $insert['source_type'], $insert['source_langcode'], $insert['source_vid'], $insert['method'], $insert['field_name'], $insert['count']);
        $dispatcher->dispatch($event, Events::USAGE_REGISTER);
      }

      if ($context['sandbox']['progress'] < $context['sandbox']['total']) {
        $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['total'];
      }
      else {
        $context['finished'] = 1;
      }

      $context['message'] = t('Triggering entity usage insert event: @current of @total', [
        '@current' => $context['sandbox']['progress'],
        '@total' => $context['sandbox']['total'],
      ]);
    }
  }

  /**
   * Logs an exception thrown by a bulk chunk, without the whole failed query.
   *
   * A failed multi-row insert puts the full SQL and every placeholder in its
   * message. One chunk holds hundreds of rows, so a single entry can weigh
   * tens of MB. Keep the head of the message: that is where the error is.
   *
   * @param \Exception $e
   *   The exception to log.
   */
  private static function logBulkException(\Exception $e): void {
    $variables = Error::decodeException($e);
    $length = mb_strlen($variables['@message']);
    if ($length > static::MAX_LOGGED_MESSAGE_LENGTH) {
      $variables['@message'] = mb_substr($variables['@message'], 0, static::MAX_LOGGED_MESSAGE_LENGTH)
        . ' ... [cut, ' . $length . ' characters in total]';
    }
    \Drupal::service('logger.channel.entity_usage')->error(Error::DEFAULT_ERROR_MESSAGE, $variables);
  }

  /**
   * Batch operation worker to drop the bulk loading table.
   */
  public static function dropBulkTable(array &$context): void {
    $db_schema = \Drupal::database()->schema();
    if ($db_schema->tableExists(static::BULK_TABLE_NAME)) {
      $db_schema->dropTable(static::BULK_TABLE_NAME);
    }
    $context['message'] = t('Dropped the entity usage bulk table');
  }

  /**
   * Batch operation worker to delete existing records for one entity type.
   *
   * Used instead of truncateTable() when recreating usage statistics for a
   * subset of entity types, so that usage records for entity types not
   * being processed are left untouched.
   *
   * @param string $entity_type_id
   *   The source entity type id to delete existing usage records for.
   * @param BatchContext $context
   *   Batch context.
   */
  public static function deleteSourcesForEntityType(string $entity_type_id, array &$context): void {
    \Drupal::service('entity_usage.usage')->bulkDeleteSources($entity_type_id);
    $context['message'] = t('Deleted existing entity usage records for @entity_type', ['@entity_type' => $entity_type_id]);
  }

  /**
   * Batch operation worker to truncate the table.
   */
  public static function truncateTable(array &$context): void {
    $service = \Drupal::service('entity_usage.usage');
    if ($service instanceof EntityUsageBulkInterface) {
      \Drupal::service('entity_usage.usage')->truncateTable();
      $context['message'] = t('Truncated the entity usage table');
    }
  }

  /**
   * Batch operation worker for recreating statistics for source entities.
   *
   * @param string $entity_type_id
   *   The entity type id, for example 'node'.
   * @param bool $keep_existing_records
   *   If TRUE existing usage records won't be deleted.
   * @param BatchContext $context
   *   Batch context.
   */
  public static function updateSourcesBatchWorker($entity_type_id, $keep_existing_records, &$context): void {
    $entity_type = \Drupal::entityTypeManager()->getDefinition($entity_type_id);
    $entity_storage = \Drupal::entityTypeManager()->getStorage($entity_type->id());
    $entity_usage = \Drupal::service('entity_usage.usage');

    $bulk_mode = !$keep_existing_records && $entity_usage instanceof EntityUsageBulkInterface;

    match(TRUE) {
      $bulk_mode && $entity_type->isRevisionable() && $entity_storage instanceof RevisionableStorageInterface => static::doBulkRevisionable($entity_storage, $entity_usage, $entity_type, $context),
      $bulk_mode && !$entity_type->isRevisionable() => static::doBulkNonRevisionable($entity_storage, $entity_usage, $entity_type, $context),
      default => static::doOneByOne($entity_storage, $entity_type, $context)
    };

    if ($context['sandbox']['progress'] < $context['sandbox']['total']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['total'];
    }
    else {
      $context['finished'] = 1;
    }

    $context['message'] = t('Updating entity usage for @entity_type: @current of @total', [
      '@entity_type' => $entity_type_id,
      '@current' => $context['sandbox']['progress'],
      '@total' => $context['sandbox']['total'],
    ]);

    if ($context['finished'] === 1) {
      // Record the total so we can say how many records we have processed.
      $context['results'][] = $context['sandbox']['total'];
    }
  }

  /**
   * Process multiple revisions in one go.
   *
   * @param \Drupal\Core\Entity\RevisionableStorageInterface $entity_storage
   *   The entity storage.
   * @param \Drupal\entity_usage\EntityUsageBulkInterface $entity_usage
   *   The entity usage service.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   * @param BatchContext $context
   *   Batch context.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public static function doBulkRevisionable(RevisionableStorageInterface $entity_storage, EntityUsageInterface $entity_usage, EntityTypeInterface $entity_type, array &$context): void {
    $entity_type_key = $entity_type->getKey('revision');
    if (empty($context['sandbox']['total'])) {
      $id_definition = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions($entity_type->id())[$entity_type_key];

      $context['sandbox']['progress'] = 0;
      $context['sandbox']['current_id'] = '';
      if (($id_definition instanceof FieldStorageDefinitionInterface) && $id_definition->getType() === 'integer') {
        $context['sandbox']['current_id'] = -1;
      }
      $context['sandbox']['revision_ids'] = array_keys(
        $entity_storage->getQuery()->allRevisions()
          ->accessCheck(FALSE)
          ->sort($entity_type->getKey('revision'), 'ASC')
          ->range(0, static::BULK_ID_LOAD)
          ->execute()
      );
      $context['sandbox']['total'] = $entity_storage->getQuery()->allRevisions()
        ->accessCheck(FALSE)
        ->sort($entity_type->getKey('revision'), 'ASC')
        ->count()
        ->execute();
    }

    $revision_ids = array_slice($context['sandbox']['revision_ids'], 0, static::BULK_BATCH_SIZE);
    $context['sandbox']['revision_ids'] = array_slice($context['sandbox']['revision_ids'], static::BULK_BATCH_SIZE);
    if (!empty($revision_ids)) {
      /** @var \Drupal\entity_usage\EntityUsageBulkInterface $entity_usage */
      $entity_usage->enableBulkInsert('entity_usage_bulk');

      try {
        foreach ($entity_storage->loadMultipleRevisions($revision_ids) as $entity_revision) {
          \Drupal::service('entity_usage.entity_update_manager')->trackUpdateOnCreation($entity_revision);
        }
        $entity_usage->bulkInsert();
      }
      catch (\Exception $e) {
        self::logBulkException($e);
      }
      // The ids are sorted ASC, so the last one is the highest of the chunk.
      // loadMultipleRevisions() gives no order, so reading the id inside the
      // loop could leave current_id below an id already tracked, and the next
      // query ("> current_id") would return revisions tracked already: the
      // same primary key would be inserted twice. Setting it outside the try
      // also keeps a failed chunk from being replayed for ever.
      $context['sandbox']['current_id'] = end($revision_ids);
    }
    $context['sandbox']['progress'] += count($revision_ids);

    if ($context['sandbox']['progress'] === $context['sandbox']['total']) {
      // Recalculate the total so that any new revisions created while bulk
      // processing are included.
      $context['sandbox']['revision_ids'] = array_keys(
        $entity_storage->getQuery()->allRevisions()
          ->condition($entity_type_key, $context['sandbox']['current_id'], '>')
          ->accessCheck(FALSE)
          ->sort($entity_type->getKey('revision'), 'ASC')
          ->range(0, static::BULK_BATCH_SIZE)
          ->execute()
      );
      $context['sandbox']['total'] = $context['sandbox']['total'] + count($context['sandbox']['revision_ids']);
    }
    elseif (empty($context['sandbox']['revision_ids'])) {
      $context['sandbox']['revision_ids'] = array_keys(
        $entity_storage->getQuery()->allRevisions()
          ->condition($entity_type_key, $context['sandbox']['current_id'], '>')
          ->accessCheck(FALSE)
          ->sort($entity_type->getKey('revision'), 'ASC')
          ->range(0, static::BULK_ID_LOAD)
          ->execute()
      );
      $context['sandbox']['total'] = $entity_storage->getQuery()->allRevisions()
        ->accessCheck(FALSE)
        ->sort($entity_type->getKey('revision'), 'ASC')
        ->count()
        ->execute();
    }
  }

  /**
   * Process multiple non-revisionable entities in one go.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $entity_storage
   *   The entity storage.
   * @param \Drupal\entity_usage\EntityUsageBulkInterface $entity_usage
   *   The entity usage service.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   * @param BatchContext $context
   *   Batch context.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public static function doBulkNonRevisionable(EntityStorageInterface $entity_storage, EntityUsageInterface $entity_usage, EntityTypeInterface $entity_type, array &$context): void {
    $entity_type_key = $entity_type->getKey('id');
    if (empty($context['sandbox']['total'])) {
      $id_definition = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions($entity_type->id())[$entity_type_key];

      $context['sandbox']['progress'] = 0;
      $context['sandbox']['current_id'] = '';
      if (($id_definition instanceof FieldStorageDefinitionInterface) && $id_definition->getType() === 'integer') {
        $context['sandbox']['current_id'] = -1;
      }
      $context['sandbox']['total'] = $entity_storage->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->sort($entity_type_key)
        ->execute();
    }

    $entity_ids = $entity_storage->getQuery()
      ->condition($entity_type_key, $context['sandbox']['current_id'], '>')
      ->range(0, static::BULK_BATCH_SIZE)
      ->accessCheck(FALSE)
      ->sort($entity_type_key)
      ->execute();

    if (!empty($entity_ids)) {
      $entity_usage->enableBulkInsert('entity_usage_bulk');
      try {
        foreach ($entity_storage->loadMultiple($entity_ids) as $entity) {
          // Sources are tracked as if they were new entities.
          \Drupal::service('entity_usage.entity_update_manager')->trackUpdateOnCreation($entity);
        }
        $entity_usage->bulkInsert();
      }
      catch (\Exception $e) {
        self::logBulkException($e);
      }
      // Same as in doBulkRevisionable(): loadMultiple() gives no order, so
      // the last queried id is the only safe value here.
      $context['sandbox']['current_id'] = end($entity_ids);
    }
    $context['sandbox']['progress'] += count($entity_ids);

    if ($context['sandbox']['progress'] === $context['sandbox']['total']) {
      // Recalculate the total so that any new entities created while bulk
      // processing are included.
      $context['sandbox']['total'] = $entity_storage->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->sort($entity_type_key)
        ->execute();
    }
  }

  /**
   * Process each entity one at a time.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $entity_storage
   *   The entity storage.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   * @param BatchContext $context
   *   Batch context.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public static function doOneByOne(EntityStorageInterface $entity_storage, EntityTypeInterface $entity_type, array &$context): void {
    $entity_type_key = $entity_type->getKey('id');
    $entity_type_id = $entity_type->id();
    if (empty($context['sandbox']['total'])) {
      $id_definition = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions($entity_type_id)[$entity_type_key];

      $context['sandbox']['progress'] = 0;
      $context['sandbox']['current_id'] = '';
      if (($id_definition instanceof FieldStorageDefinitionInterface) && $id_definition->getType() === 'integer') {
        $context['sandbox']['current_id'] = -1;
      }
      $context['sandbox']['total'] = (int) $entity_storage->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->execute();
      $context['sandbox']['batch_entity_revision'] = [
        'status' => 0,
        'current_vid' => 0,
        'start' => 0,
      ];
    }
    if ($context['sandbox']['batch_entity_revision']['status']) {
      $op = '=';
    }
    else {
      $op = '>';
    }

    $entity_ids = $entity_storage->getQuery()
      ->condition($entity_type_key, $context['sandbox']['current_id'], $op)
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->sort($entity_type_key)
      ->execute();
    $entity_id = reset($entity_ids);

    if ($entity_id !== FALSE) {
      try {
        if ($entity_type->isRevisionable()) {
          assert($entity_storage instanceof RevisionableStorageInterface);

          // We cannot query the revisions due to this bug
          // https://www.drupal.org/project/drupal/issues/2766135
          // so we will use offsets.
          $start = $context['sandbox']['batch_entity_revision']['start'];
          // Track all revisions and translations of the source entity. Sources
          // are tracked as if they were new entities.
          $result = $entity_storage->getQuery()->allRevisions()
            ->condition($entity_type->getKey('id'), $entity_id)
            ->accessCheck(FALSE)
            ->sort($entity_type->getKey('revision'), 'DESC')
            ->range($start, static::REVISION_BATCH_SIZE)
            ->execute();
          $revision_ids = array_keys($result);
          if (count($revision_ids) === static::REVISION_BATCH_SIZE) {
            $context['sandbox']['batch_entity_revision'] = [
              'status' => 1,
              'current_vid' => min($revision_ids),
              'start' => $start + static::REVISION_BATCH_SIZE,
            ];
          }
          else {
            $context['sandbox']['batch_entity_revision'] = [
              'status' => 0,
              'current_vid' => 0,
              'start' => 0,
            ];
          }

          foreach ($entity_storage->loadMultipleRevisions($revision_ids) as $entity_revision) {
            /** @var \Drupal\Core\Entity\EntityInterface $entity_revision */
            \Drupal::service('entity_usage.entity_update_manager')->trackUpdateOnCreation($entity_revision);
          }
        }
        else {
          // Sources are tracked as if they were new entities.
          $entity = $entity_storage->load($entity_id);
          \Drupal::service('entity_usage.entity_update_manager')->trackUpdateOnCreation($entity);
        }
      }
      catch (\Exception $e) {
        self::logBulkException($e);
      }

      if (
        $context['sandbox']['batch_entity_revision']['status'] === 0 ||
        intval($context['sandbox']['progress']) === 0
      ) {
        $context['sandbox']['progress']++;
      }
      $context['sandbox']['current_id'] = $entity_id;
    }
  }

  /**
   * Gets the list of entity type IDs to track.
   *
   * @param \Drupal\Core\Config\Config $entity_usage_config
   *   The entity usage config.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   *
   * @return string[]
   *   The list of entity type IDs to track.
   */
  private static function getEntityTypesToTrack(Config $entity_usage_config, EntityTypeManagerInterface $entity_type_manager): array {
    $entity_types = [];
    $to_track = $entity_usage_config->get('track_enabled_source_entity_types');
    foreach (\Drupal::entityTypeManager()->getDefinitions() as $entity_type_id => $entity_type) {
      // Only look for entities enabled for tracking on the settings form.
      if (
        !is_array($to_track) &&
        $entity_type->hasKey('id') &&
        $entity_type->entityClassImplements('\Drupal\Core\Entity\ContentEntityInterface')
      ) {
        // When no settings are defined, track all content entities by default,
        // except for Files and Users.
        if (!in_array($entity_type_id, ['file', 'user'])) {
          $entity_types[] = $entity_type_id;
        }
      }
      elseif (is_array($to_track) && in_array($entity_type_id, $to_track, TRUE)) {
        $entity_types[] = $entity_type_id;
      }
    }
    return $entity_types;
  }

  /**
   * Finish callback for our batch processing.
   *
   * @param bool $success
   *   Whether the batch completed successfully.
   * @param mixed[] $results
   *   The results array.
   * @param mixed[] $operations
   *   The operations array.
   */
  public static function batchFinished(bool $success, array $results, array $operations): void {
    if ($success) {
      \Drupal::messenger()->addMessage(t('Recreated entity usage for @count entities.', ['@count' => array_sum($results)]));
    }
    else {
      // An error occurred.
      // $operations contains the operations that remained unprocessed.
      $error_operation = reset($operations);
      \Drupal::messenger()->addMessage(
        t('An error occurred while processing @operation with arguments : @args',
          [
            '@operation' => $error_operation[0],
            '@args' => print_r($error_operation[1], TRUE),
          ]
        )
      );
    }
  }

}
