<?php

namespace Drupal\Tests\commerce_promotion\Kernel;

use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use Drupal\commerce_promotion\Entity\Coupon;
use Drupal\commerce_promotion\Entity\Promotion;

/**
 * Tests the coupon selection handler.
 *
 * @coversDefaultClass \Drupal\commerce_promotion\Plugin\EntityReferenceSelection\CouponSelection
 * @group commerce
 */
class CouponSelectionTest extends OrderKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_promotion',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('commerce_promotion');
    $this->installEntitySchema('commerce_promotion_coupon');
    $this->installSchema('commerce_promotion', ['commerce_promotion_usage']);
    $this->installConfig(['commerce_promotion']);
    // Create uid: 1 here so that it's skipped in test cases.
    $this->createUser();
  }

  /**
   * @covers ::getReferenceableEntities
   */
  public function testSelection(): void {
    $promotion = Promotion::create([
      'order_types' => ['default'],
      'stores' => [$this->store->id()],
      'start_date' => '2017-01-01',
      'status' => TRUE,
    ]);
    $promotion->save();
    $coupon = Coupon::create([
      'promotion_id' => $promotion->id(),
      'code' => 'SECRET_CODE',
      'status' => TRUE,
    ]);
    $coupon->save();

    /** @var \Drupal\Core\Entity\EntityAutocompleteMatcherInterface $matcher */
    $matcher = $this->container->get('entity.autocomplete_matcher');
    $selection_settings = ['match_operator' => 'CONTAINS'];

    foreach ([[], ['view commerce_promotion']] as $permissions) {
      $this->setCurrentUser($this->createUser($permissions));
      $this->assertEmpty($matcher->getMatches('commerce_promotion_coupon', 'default', $selection_settings, 'SECRET'));
    }
    // Validating a reference to the coupon, e.g. when redeeming it, still
    // works for customers.
    /** @var \Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface $selection_manager */
    $selection_manager = $this->container->get('plugin.manager.entity_reference_selection');
    $handler = $selection_manager->getInstance([
      'target_type' => 'commerce_promotion_coupon',
      'handler' => 'default',
    ]);
    $this->assertEquals([$coupon->id()], array_values($handler->validateReferenceableEntities([$coupon->id()])));

    foreach (['administer commerce_promotion', 'update any commerce_promotion'] as $permission) {
      $this->setCurrentUser($this->createUser([$permission]));
      $matches = $matcher->getMatches('commerce_promotion_coupon', 'default', $selection_settings, 'SECRET');
      $this->assertCount(1, $matches, $permission);
      $this->assertEquals('SECRET_CODE', $matches[0]['label'], $permission);
    }
  }

}
