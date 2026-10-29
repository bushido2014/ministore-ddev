<?php

namespace Drupal\Tests\commerce_promotion\Functional;

use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_promotion\Entity\CouponInterface;
use Drupal\commerce_promotion\Entity\PromotionInterface;

/**
 * Tests the "Manage coupons" order form.
 *
 * @group commerce
 */
class ManageCouponsFormTest extends CommerceBrowserTestBase {

  /**
   * The order.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  protected OrderInterface $order;

  /**
   * The promotion that allows only one coupon per order.
   *
   * @var \Drupal\commerce_promotion\Entity\PromotionInterface
   */
  protected PromotionInterface $promotion;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_product',
    'commerce_promotion',
  ];

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return array_merge([
      'administer commerce_order',
      'administer commerce_promotion',
    ], parent::getAdministratorPermissions());
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->promotion = $this->createPromotion('First promotion');
    $this->order = $this->createEntity('commerce_order', [
      'type' => 'default',
      'state' => 'draft',
      'mail' => 'customer@example.com',
      'store_id' => $this->store->id(),
    ]);
  }

  /**
   * Tests applying coupons without the permission to list them.
   */
  public function testApplyCouponWithoutListing(): void {
    $first_coupon = $this->createCoupon($this->promotion, 'FIRST1');
    $second_coupon = $this->createCoupon($this->promotion, 'FIRST2');
    $other_coupon = $this->createCoupon($this->createPromotion('Other promotion'), 'OTHER1');
    // A coupon applied by someone else, e.g. a promotion manager.
    $this->order->get('coupons')->appendItem($first_coupon);
    $this->order->save();

    $account = $this->drupalCreateUser([
      'access commerce administration pages',
      'view default commerce_order',
      'update default commerce_order',
    ]);
    $this->drupalLogin($account);
    $this->drupalGet($this->order->toUrl('canonical')->toString() . '/manage-coupons');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('FIRST1');
    // The coupon codes can't be listed, so there is no autocomplete.
    $this->assertSession()->fieldExists('coupon');
    $this->assertSession()->elementNotExists('css', 'input[name="coupon"][data-autocomplete-path]');

    $this->submitForm(['coupon' => 'INVALID'], 'Apply coupon');
    $this->assertSession()->pageTextContains('The provided coupon code is invalid.');

    // The promotion allows only one coupon per order.
    $this->submitForm(['coupon' => $second_coupon->getCode()], 'Apply coupon');
    $this->assertSession()->pageTextContains('The provided coupon code cannot be applied to your order.');

    $this->submitForm(['coupon' => $other_coupon->getCode()], 'Apply coupon');
    $this->assertSession()->pageTextNotContains('The provided coupon code');
    $this->submitForm([], 'Save');

    $order = $this->reloadEntity($this->order);
    $coupon_ids = array_column($order->get('coupons')->getValue(), 'target_id');
    $this->assertEquals([$first_coupon->id(), $other_coupon->id()], $coupon_ids);
  }

  /**
   * Tests applying coupons with the permission to list them.
   */
  public function testApplyCouponWithListing(): void {
    $coupon = $this->createCoupon($this->promotion, 'FIRST1');

    $this->drupalGet($this->order->toUrl('canonical')->toString() . '/manage-coupons');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', 'input[name="coupon"][data-autocomplete-path]');

    $this->submitForm(['coupon' => 'FIRST1 (' . $coupon->id() . ')'], 'Apply coupon');
    $this->assertSession()->pageTextNotContains('The provided coupon code');
    $this->submitForm([], 'Save');

    $order = $this->reloadEntity($this->order);
    $this->assertEquals([$coupon->id()], array_column($order->get('coupons')->getValue(), 'target_id'));
  }

  /**
   * Creates a promotion that requires a coupon.
   *
   * @param string $name
   *   The promotion name.
   *
   * @return \Drupal\commerce_promotion\Entity\PromotionInterface
   *   The promotion.
   */
  protected function createPromotion(string $name): PromotionInterface {
    return $this->createEntity('commerce_promotion', [
      'name' => $name,
      'order_types' => ['default'],
      'stores' => [$this->store->id()],
      'offer' => [
        'target_plugin_id' => 'order_percentage_off',
        'target_plugin_configuration' => [
          'percentage' => '0.10',
        ],
      ],
      'start_date' => '2017-01-01',
      'require_coupon' => TRUE,
      'status' => TRUE,
    ]);
  }

  /**
   * Creates a coupon.
   *
   * @param \Drupal\commerce_promotion\Entity\PromotionInterface $promotion
   *   The promotion.
   * @param string $code
   *   The coupon code.
   *
   * @return \Drupal\commerce_promotion\Entity\CouponInterface
   *   The coupon.
   */
  protected function createCoupon(PromotionInterface $promotion, string $code): CouponInterface {
    $coupon = $this->createEntity('commerce_promotion_coupon', [
      'promotion_id' => $promotion->id(),
      'code' => $code,
      'start_date' => '2017-01-01',
      'status' => TRUE,
    ]);
    $promotion->addCoupon($coupon)->save();
    return $coupon;
  }

}
