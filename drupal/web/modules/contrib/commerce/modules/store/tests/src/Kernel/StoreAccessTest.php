<?php

namespace Drupal\Tests\commerce_store\Kernel;

use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use Drupal\commerce_store\Entity\Store;

/**
 * Tests the store access control.
 *
 * @coversDefaultClass \Drupal\commerce_store\StoreAccessControlHandler
 *
 * @group commerce
 */
class StoreAccessTest extends CommerceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create uid: 1 here so that it's skipped in test cases.
    $this->createUser();
  }

  /**
   * @covers ::checkFieldAccess
   */
  public function testDefaultFieldAccess() {
    $vendor = $this->createUser([
      'create online commerce_store',
      'update own online commerce_store',
    ]);
    $store = Store::create([
      'type' => 'online',
      'uid' => $vendor->id(),
    ]);
    $store->save();
    $new_store = Store::create([
      'type' => 'online',
      'uid' => $vendor->id(),
    ]);

    $this->assertTrue($store->access('update', $vendor));
    $this->assertTrue($store->get('name')->access('edit', $vendor));
    $this->assertFalse($store->get('is_default')->access('edit', $vendor));
    $this->assertFalse($new_store->get('is_default')->access('edit', $vendor));
    $this->assertTrue($store->get('is_default')->access('view', $vendor));

    $admin = $this->createUser(['administer commerce_store']);
    $this->assertTrue($store->get('is_default')->access('edit', $admin));
    $this->assertTrue($new_store->get('is_default')->access('edit', $admin));

    // Users who can update any store of a type can't change the default store
    // either, since the previous default store might be of another type.
    $store_manager = $this->createUser(['update any online commerce_store']);
    $this->assertTrue($store->access('update', $store_manager));
    $this->assertFalse($store->get('is_default')->access('edit', $store_manager));
  }

  /**
   * Tests that modules can add restricted fields.
   *
   * @covers ::checkFieldAccess
   */
  public function testRestrictedFields() {
    $vendor = $this->createUser(['update own online commerce_store']);
    $store = Store::create([
      'type' => 'online',
      'uid' => $vendor->id(),
    ]);
    $store->save();
    $this->assertTrue($store->get('name')->access('edit', $vendor));

    // Modules add their fields in hook_entity_type_build().
    $entity_type = $this->entityTypeManager->getDefinition('commerce_store');
    $entity_type->set('restricted_fields', array_merge($entity_type->get('restricted_fields'), ['name']));
    $this->entityTypeManager->getAccessControlHandler('commerce_store')->resetCache();
    $this->assertFalse($store->get('name')->access('edit', $vendor));
    $this->assertFalse($store->get('is_default')->access('edit', $vendor));
  }

}
