<?php

namespace Drupal\Tests\commerce_promotion\Kernel;

use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use Drupal\commerce_promotion\Entity\Promotion;

/**
 * Tests the promotion access control.
 *
 * @coversDefaultClass \Drupal\commerce_promotion\PromotionAccessControlHandler
 * @group commerce
 */
class PromotionAccessTest extends OrderKernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array
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

    // Create uid: 0 and 1 here so that it's skipped in test cases.
    $this->setUpCurrentUser();
  }

  /**
   * @covers ::checkFieldAccess
   */
  public function testFieldAccess() {
    $owner = $this->createUser([
      'view commerce_promotion',
      'create commerce_promotion',
      'update own commerce_promotion',
    ]);
    $promotion = Promotion::create([
      'order_types' => ['default'],
      'stores' => [$this->store->id()],
      'uid' => $owner->id(),
      'status' => TRUE,
    ]);
    $promotion->save();
    $new_promotion = Promotion::create([
      'order_types' => ['default'],
      'stores' => [$this->store->id()],
      'uid' => $owner->id(),
    ]);

    // Users who can update their own promotions can't reference coupons,
    // which could belong to other promotions.
    $this->assertTrue($promotion->access('update', $owner));
    $this->assertTrue($promotion->get('name')->access('edit', $owner));
    $this->assertTrue($promotion->get('offer')->access('edit', $owner));
    $this->assertFalse($promotion->get('coupons')->access('edit', $owner));
    $this->assertFalse($new_promotion->get('coupons')->access('edit', $owner));
    $this->assertTrue($promotion->get('coupons')->access('view', $owner));

    $account = $this->createUser(['update any commerce_promotion']);
    $this->assertTrue($promotion->get('coupons')->access('edit', $account));

    $account = $this->createUser(['administer commerce_promotion']);
    $this->assertTrue($promotion->get('coupons')->access('edit', $account));
    $this->assertTrue($new_promotion->get('coupons')->access('edit', $account));
  }

}
