<?php

declare(strict_types=1);

namespace Drupal\Tests\entity_usage\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;

/**
 * Tests the usage operation added to entity listings.
 *
 * @group entity_usage
 *
 * @see entity_usage_entity_operation()
 */
class EntityOperationTest extends EntityKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['entity_usage'];

  /**
   * A content entity to get the operations of.
   */
  protected EntityTest $entity;

  /**
   * A config entity to get the operations of.
   */
  protected FilterFormat $configEntity;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['entity_usage']);

    $this->entity = EntityTest::create(['name' => 'Test entity']);
    $this->entity->save();

    // Config entities have no canonical link template, so this also covers the
    // edit-form fallback of the usage route.
    $this->configEntity = FilterFormat::create([
      'format' => 'test_format',
      'name' => 'Test format',
    ]);
    $this->configEntity->save();

    $this->setLocalTaskEntityTypes(['entity_test', 'filter_format']);
  }

  /**
   * Tests the operation on content and config entities.
   */
  public function testUsageOperation(): void {
    $this->setUpCurrentUser([], ['view test entity', 'administer filters', 'access entity usage statistics']);
    $this->assertSame('/entity_test/1/usage', $this->getUsageOperationUrl($this->entity));
    $this->assertSame('/admin/config/content/formats/manage/test_format/usage', $this->getUsageOperationUrl($this->configEntity));

    $this->setUpCurrentUser([], ['view test entity', 'administer filters']);
    $this->assertNull($this->getUsageOperationUrl($this->entity));
    $this->assertNull($this->getUsageOperationUrl($this->configEntity));

    $this->setUpCurrentUser([], ['access entity usage statistics']);
    $this->assertNull($this->getUsageOperationUrl($this->entity));
    $this->assertNull($this->getUsageOperationUrl($this->configEntity));

    $this->setUpCurrentUser([], ['view test entity', 'administer filters', 'access entity usage statistics']);
    $this->setLocalTaskEntityTypes(['entity_test']);
    $this->assertSame('/entity_test/1/usage', $this->getUsageOperationUrl($this->entity));
    $this->assertNull($this->getUsageOperationUrl($this->configEntity));
  }

  /**
   * Tests the cacheability the operation adds to the one it is given.
   */
  public function testUsageOperationCacheability(): void {
    $this->setUpCurrentUser([], ['view test entity', 'access entity usage statistics']);

    $cacheability = new CacheableMetadata();
    entity_usage_entity_operation($this->entity, $cacheability);
    $this->assertContains('user.permissions', $cacheability->getCacheContexts());
    $this->assertContains('config:entity_usage.settings', $cacheability->getCacheTags());

    // Entity types without a usage page still depend on the configuration.
    $this->setLocalTaskEntityTypes(['filter_format']);
    $cacheability = new CacheableMetadata();
    entity_usage_entity_operation($this->entity, $cacheability);
    $this->assertSame(['config:entity_usage.settings'], $cacheability->getCacheTags());
  }

  /**
   * Returns the URL of the usage operation of an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to get the operations of.
   *
   * @return string|null
   *   The URL of the usage operation, or NULL if there is no such operation.
   */
  protected function getUsageOperationUrl(EntityInterface $entity): ?string {
    $operations = $this->entityTypeManager
      ->getListBuilder($entity->getEntityTypeId())
      ->getOperations($entity);

    return isset($operations['entity-usage']) ? $operations['entity-usage']['url']->toString() : NULL;
  }

  /**
   * Enables the usage local task for the given entity types.
   *
   * @param string[] $entity_type_ids
   *   The entity type IDs to enable the usage local task for.
   */
  protected function setLocalTaskEntityTypes(array $entity_type_ids): void {
    $this->config('entity_usage.settings')
      ->set('local_task_enabled_entity_types', $entity_type_ids)
      ->save();

    $this->container->get('router.builder')->rebuild();
  }

}
