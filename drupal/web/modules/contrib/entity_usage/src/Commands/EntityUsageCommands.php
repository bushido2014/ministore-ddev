<?php

namespace Drupal\entity_usage\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\entity_usage\EntityUsageBatchManager;
use Drush\Commands\DrushCommands;

/**
 * Entity Usage drush commands.
 */
class EntityUsageCommands extends DrushCommands {

  /**
   * The Entity Usage batch manager.
   *
   * @var \Drupal\entity_usage\EntityUsageBatchManager
   */
  protected $batchManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity usage configuration.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $entityUsageConfig;

  /**
   * The database service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  public function __construct(EntityUsageBatchManager $batch_manager, EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, Connection $database) {
    parent::__construct();
    $this->batchManager = $batch_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityUsageConfig = $config_factory->get('entity_usage.settings');
    $this->database = $database;
  }

  /**
   * Recreate all entity usage statistics.
   *
   * @command entity-usage:recreate
   * @aliases eu-r,entity-usage-recreate
   * @option keep-existing-records
   *   When --keep-existing-records is used, existing entity usage records
   *   won't be deleted.
   * @option entity-types
   *   A comma-separated list of entity type IDs to recreate usage statistics
   *   for, e.g. "node,media". If omitted, statistics are recreated for all
   *   entity types enabled for tracking. When provided, only usage records
   *   for these entity types are deleted and rebuilt.
   */
  public function recreate(array $options = ['keep-existing-records' => FALSE, 'entity-types' => NULL]): void {
    $entity_types = NULL;
    if (!empty($options['entity-types'])) {
      $entity_types = array_map('trim', explode(',', $options['entity-types']));
    }
    $this->batchManager->recreate($options['keep-existing-records'], $entity_types);
    drush_backend_batch_process();
  }

}
