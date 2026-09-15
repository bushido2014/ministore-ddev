<?php

namespace Drupal\Tests\entity_usage\Kernel;

use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\Entity\EntityTestMulRevPub;
use Drupal\entity_usage\EntityUsageBatchManager;
use Drupal\entity_usage_test\TestEntityUsageBatchManager;
use Drupal\entity_usage_test\TestLogger;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\entity_usage\EntityUsageInterface;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;

/**
 * Tests the entity_usage.batch_manager service's entity type scoping.
 *
 * @group entity_usage
 */
class EntityUsageBatchManagerTest extends EntityKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['entity_test', 'entity_usage', 'entity_usage_test'];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    TestLogger::register($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('entity_usage', ['entity_usage']);
    $this->installConfig('entity_usage');
  }

  /**
   * Tests that a full recreate truncates the whole table.
   */
  public function testGenerateBatchAllEntityTypes(): void {
    $batch = $this->container->get(EntityUsageBatchManager::class)->generateBatch();
    $callbacks = array_column($batch['operations'], 0);

    $this->assertContains('\Drupal\entity_usage\EntityUsageBatchManager::truncateTable', $callbacks);
    $this->assertNotContains('\Drupal\entity_usage\EntityUsageBatchManager::deleteSourcesForEntityType', $callbacks);
    $this->assertContains('\Drupal\entity_usage\EntityUsageBatchManager::updateSourcesBatchWorker', $callbacks);

    // Confirm that other content entity types provided by entity_test are
    // also scheduled for processing when no filter is applied.
    $processed_entity_types = $this->getOperationEntityTypes($batch, 'updateSourcesBatchWorker');
    $this->assertContains('entity_test', $processed_entity_types);
    $this->assertContains('entity_test_mulrevpub', $processed_entity_types);
  }

  /**
   * Tests that scoping to an entity type skips the whole-table truncate.
   */
  public function testGenerateBatchScopedEntityTypes(): void {
    $batch = $this->container->get(EntityUsageBatchManager::class)->generateBatch(FALSE, ['entity_test']);
    $callbacks = array_column($batch['operations'], 0);

    $this->assertNotContains('\Drupal\entity_usage\EntityUsageBatchManager::truncateTable', $callbacks);
    $this->assertContains('\Drupal\entity_usage\EntityUsageBatchManager::deleteSourcesForEntityType', $callbacks);

    $this->assertEquals(['entity_test'], $this->getOperationEntityTypes($batch, 'deleteSourcesForEntityType'));
    $this->assertEquals(['entity_test'], $this->getOperationEntityTypes($batch, 'updateSourcesBatchWorker'));
  }

  /**
   * Tests that requesting an untracked entity type throws an exception.
   */
  public function testGenerateBatchInvalidEntityType(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('bogus_entity_type_xyz');
    $this->container->get(EntityUsageBatchManager::class)->generateBatch(FALSE, ['bogus_entity_type_xyz']);
  }

  /**
   * Tests that a scoped recreate only deletes records for requested types.
   */
  public function testRecreateScopedDeletesOnlyRequestedType(): void {
    $entity = EntityTest::create(['name' => $this->randomMachineName()]);
    $entity->save();

    // Seed a fake usage record for the entity type we are about to scope the
    // rebuild to, and one for an entity type outside the scope.
    $eu_service = $this->container->get(EntityUsageInterface::class);
    $eu_service->registerUsage(999, 'node', $entity->id(), 'entity_test', 'en', 1, 'entity_reference', 'field_test', 1);
    $eu_service->registerUsage(999, 'node', 1, 'user', 'en', 1, 'entity_reference', 'field_test', 1);

    // Call the batch worker methods directly rather than driving the whole
    // batch returned by generateBatch(): a fresh $context per call mirrors
    // how the Batch API itself invokes each operation.
    $context = ['sandbox' => [], 'results' => [], 'finished' => 0, 'message' => ''];
    EntityUsageBatchManager::deleteSourcesForEntityType('entity_test', $context);

    $context = ['sandbox' => [], 'results' => [], 'finished' => 0, 'message' => ''];
    EntityUsageBatchManager::updateSourcesBatchWorker('entity_test', FALSE, $context);

    $database = $this->container->get(Connection::class);
    $entity_test_count = $database->select('entity_usage', 'e')
      ->condition('e.source_type', 'entity_test')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(0, $entity_test_count, 'The fake entity_test source record was deleted by the scoped rebuild.');

    $user_count = $database->select('entity_usage', 'e')
      ->condition('e.source_type', 'user')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $user_count, 'The user source record outside the requested scope was left untouched.');
  }

  /**
   * Tests that a chunk failing to bulk insert is logged and not replayed.
   *
   * @covers \Drupal\entity_usage\EntityUsageBatchManager::doBulkNonRevisionable
   * @covers \Drupal\entity_usage\EntityUsage::bulkInsert
   */
  public function testBulkInsertExceptionNonRevisionable(): void {
    $logger = $this->container->get(TestLogger::class);
    $logger->clear();
    $this->setUpTrackedTextField('entity_test');

    EntityTest::create(['type' => 'entity_test', 'name' => '1', 'text' => 'tracked'])->save();
    EntityTest::create(['type' => 'entity_test', 'name' => '2'])->save();
    EntityTest::create(['type' => 'entity_test', 'name' => '3', 'text' => 'tracked'])->save();

    $this->container->get('keyvalue')->get('entity_usage_test')
      ->set('returns', [['entity_test|100'], ['entity_test|200']]);

    // The bulk table does not exist yet, so the first chunk of two entities
    // fails to insert.
    $context = ['sandbox' => [], 'results' => [], 'finished' => 0, 'message' => ''];
    TestEntityUsageBatchManager::updateSourcesBatchWorker('entity_test', FALSE, $context);

    $logs = $logger->getLogs('error');
    $this->assertCount(1, $logs);
    $this->assertStringContainsString('entity_usage_bulk', $logs[0]);
    // The worker moves on to the next chunk in spite of the failure.
    $this->assertEquals(2, $context['sandbox']['current_id']);
    $this->assertLessThan(1, $context['finished']);

    $table_context = [];
    EntityUsageBatchManager::createBulkTable($table_context);
    TestEntityUsageBatchManager::updateSourcesBatchWorker('entity_test', FALSE, $context);
    $this->assertSame(1, $context['finished']);
    $this->assertCount(1, $logger->getLogs('error'));

    // Only the row of the second chunk was inserted: the row queued by the
    // failed chunk was discarded, not replayed.
    $rows = $this->container->get('database')
      ->select(EntityUsageBatchManager::BULK_TABLE_NAME, 'e')
      ->fields('e', ['target_id', 'source_id'])
      ->execute()
      ->fetchAllKeyed();
    $this->assertEquals([200 => 3], $rows);
  }

  /**
   * Tests a failing chunk on the revisionable bulk path.
   *
   * @covers \Drupal\entity_usage\EntityUsageBatchManager::doBulkRevisionable
   * @covers \Drupal\entity_usage\EntityUsage::bulkInsert
   */
  public function testBulkInsertExceptionRevisionable(): void {
    $logger = $this->container->get(TestLogger::class);
    $logger->clear();
    $this->installEntitySchema('entity_test_mulrevpub');
    $this->setUpTrackedTextField('entity_test_mulrevpub');

    $entity = EntityTestMulRevPub::create([
      'type' => 'entity_test_mulrevpub',
      'name' => '1',
      'text' => 'tracked',
    ]);
    $entity->save();
    $entity->setNewRevision(TRUE);
    $entity->save();
    $entity->setNewRevision(TRUE);
    $entity->save();

    // The order the two revisions of the failing chunk are processed in is
    // not defined, so both return the same target.
    $this->container->get('keyvalue')->get('entity_usage_test')
      ->set('returns', [
        ['entity_test|100'],
        ['entity_test|100'],
        ['entity_test|200'],
      ]);

    // The bulk table does not exist yet, so the first chunk of two revisions
    // fails to insert.
    $context = ['sandbox' => [], 'results' => [], 'finished' => 0, 'message' => ''];
    TestEntityUsageBatchManager::updateSourcesBatchWorker('entity_test_mulrevpub', FALSE, $context);

    $logs = $logger->getLogs('error');
    $this->assertCount(1, $logs);
    $this->assertStringContainsString('entity_usage_bulk', $logs[0]);
    // current_id is the highest revision id of the failed chunk.
    $this->assertEquals(2, $context['sandbox']['current_id']);
    $this->assertLessThan(1, $context['finished']);

    $table_context = [];
    EntityUsageBatchManager::createBulkTable($table_context);
    TestEntityUsageBatchManager::updateSourcesBatchWorker('entity_test_mulrevpub', FALSE, $context);
    $this->assertSame(1, $context['finished']);
    $this->assertCount(1, $logger->getLogs('error'));

    // Only the row of the second chunk, tracking the last revision, was
    // inserted. The rows queued by the failed chunk were discarded.
    $rows = $this->container->get('database')
      ->select(EntityUsageBatchManager::BULK_TABLE_NAME, 'e')
      ->fields('e', ['target_id', 'source_vid'])
      ->execute()
      ->fetchAllKeyed();
    $this->assertEquals([200 => 3], $rows);
  }

  /**
   * Tests that a long exception message is truncated when logged.
   *
   * @covers \Drupal\entity_usage\EntityUsageBatchManager::logBulkException
   */
  public function testLogBulkExceptionTruncation(): void {
    $logger = $this->container->get(TestLogger::class);
    $logger->clear();
    $log_bulk_exception = new \ReflectionMethod(EntityUsageBatchManager::class, 'logBulkException');
    $max = EntityUsageBatchManager::MAX_LOGGED_MESSAGE_LENGTH;

    $log_bulk_exception->invoke(NULL, new \Exception('Short message.'));
    $logs = $logger->getLogs('error');
    $this->assertCount(1, $logs);
    $this->assertStringContainsString('</em>: Short message. in', $logs[0]);
    $this->assertStringNotContainsString('[cut,', $logs[0]);

    $logger->clear();
    $log_bulk_exception->invoke(NULL, new \Exception(str_repeat('a', $max + 10)));
    $logs = $logger->getLogs('error');
    $this->assertCount(1, $logs);
    $this->assertMatchesRegularExpression('/<\/em>: a{' . $max . '} \.\.\. \[cut, ' . ($max + 10) . ' characters in total\] in /', $logs[0]);
  }

  /**
   * Creates a text field tracked by the entity_usage_test plugin.
   *
   * @param string $entity_type_id
   *   The entity type to create the field on and to enable as a tracked
   *   source. The bundle is assumed to have the same name.
   */
  private function setUpTrackedTextField(string $entity_type_id): void {
    FieldStorageConfig::create([
      'type' => 'text_long',
      'entity_type' => $entity_type_id,
      'field_name' => 'text',
    ])->save();
    FieldConfig::create([
      'entity_type' => $entity_type_id,
      'bundle' => $entity_type_id,
      'field_name' => 'text',
      'label' => 'Text',
    ])->save();

    $this->config('entity_usage.settings')
      ->set('track_enabled_source_entity_types', [$entity_type_id])
      ->set('track_enabled_target_entity_types', ['entity_test'])
      ->set('track_enabled_plugins', ['entity_usage_test'])
      ->save();
  }

  /**
   * Gets the entity type IDs a batch passes to a given operation method.
   *
   * @param array $batch
   *   A batch array as returned by EntityUsageBatchManager::generateBatch().
   * @param string $method
   *   The unqualified EntityUsageBatchManager method name to match, e.g.
   *   'updateSourcesBatchWorker'.
   *
   * @return array
   *   The entity type ID passed as the first argument to each operation
   *   calling that method, in batch order.
   */
  protected function getOperationEntityTypes(array $batch, string $method): array {
    $callback = '\\' . EntityUsageBatchManager::class . '::' . $method;
    $matching_operations = array_filter($batch['operations'], static fn (array $operation): bool => $operation[0] === $callback);
    return array_column(array_column($matching_operations, 1), 0);
  }

}
