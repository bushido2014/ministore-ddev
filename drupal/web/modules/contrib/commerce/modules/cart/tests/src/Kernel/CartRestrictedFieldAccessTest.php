<?php

namespace Drupal\Tests\commerce_cart\Kernel;

/**
 * Tests the restricted order fields added by other modules.
 *
 * @group commerce
 */
class CartRestrictedFieldAccessTest extends CartKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_checkout',
    'commerce_payment',
    'commerce_promotion',
    'commerce_order_field_access_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('commerce_payment_method');
    $this->installEntitySchema('commerce_promotion');
    $this->installEntitySchema('commerce_promotion_coupon');
    $this->installSchema('commerce_promotion', ['commerce_promotion_usage']);
    $this->installConfig(['commerce_checkout', 'commerce_promotion']);
    // Create uid: 1 here so that it's skipped in test cases.
    $this->createUser();
  }

  /**
   * Tests the field access of fields added by other modules.
   */
  public function testFieldAccess(): void {
    $customer = $this->createUser();
    $cart = $this->cartProvider->createCart('default', $this->store, $customer);
    $this->assertTrue($cart->access('update', $customer));

    $field_names = [
      'cart',
      'checkout_flow',
      'checkout_step',
      'payment_gateway',
      'payment_method',
      'coupons',
      'test_admin_field',
    ];
    $restricted_fields = $cart->getEntityType()->get('restricted_fields');
    $this->assertContains('state', $restricted_fields);
    foreach ($field_names as $field_name) {
      $this->assertContains($field_name, $restricted_fields);
      $this->assertFalse($cart->get($field_name)->access('edit', $customer), $field_name);
    }

    $admins = [
      $this->createUser(['administer commerce_order']),
      $this->createUser(['update default commerce_order']),
    ];
    foreach ($admins as $admin) {
      foreach ($field_names as $field_name) {
        $this->assertTrue($cart->get($field_name)->access('edit', $admin), $field_name);
      }
    }

    // Other modules can still grant access to a restricted field.
    $editor = $this->createUser(['edit test admin field']);
    $this->assertTrue($cart->get('test_admin_field')->access('edit', $editor));
    $this->assertFalse($cart->get('state')->access('edit', $editor));
  }

}
