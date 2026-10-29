<?php

namespace Drupal\Tests\commerce_cart\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;

/**
 * Tests that cart access does not allow editing restricted fields.
 *
 * @group commerce
 */
class CartFieldAccessTest extends CartKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create uid: 1 here so that it's skipped in test cases.
    $this->createUser();
  }

  /**
   * Tests the order and order item field access.
   */
  public function testFieldAccess(): void {
    $customer = $this->createUser();
    $cart = $this->cartProvider->createCart('default', $this->store, $customer);
    $order_item = OrderItem::create([
      'type' => 'test',
      'quantity' => 1,
      'unit_price' => ['number' => '10.00', 'currency_code' => 'USD'],
    ]);
    $order_item->save();
    $this->cartManager->addOrderItem($cart, $order_item);
    $this->assertTrue($cart->access('update', $customer));
    $this->assertTrue($order_item->access('update', $customer));

    $order_fields = ['state', 'total_price', 'adjustments', 'uid', 'store_id', 'order_items', 'locked', 'placed', 'version', 'created'];
    $order_item_fields = ['unit_price', 'overridden_unit_price', 'total_price', 'adjustments', 'order_id', 'purchased_entity', 'title', 'overridden_title', 'created'];
    foreach ($order_fields as $field_name) {
      $this->assertFalse($cart->get($field_name)->access('edit', $customer), $field_name);
    }
    foreach ($order_item_fields as $field_name) {
      $this->assertFalse($order_item->get($field_name)->access('edit', $customer), $field_name);
    }
    // Core doesn't allow editing the changed time.
    $this->assertFalse($cart->get('changed')->access('edit', $customer));
    $this->assertFalse($order_item->get('changed')->access('edit', $customer));
    // Fields set by customers during checkout remain editable.
    $this->assertTrue($cart->get('mail')->access('edit', $customer));
    $this->assertTrue($cart->get('billing_profile')->access('edit', $customer));
    $this->assertTrue($order_item->get('quantity')->access('edit', $customer));

    // The purchased entity and unit price of new order items can be set (on
    // the "Add to cart" form: the variation selector, and e.g. donations).
    $new_order_item = OrderItem::create([
      'type' => 'test',
      'quantity' => 1,
    ]);
    $this->assertTrue($new_order_item->get('unit_price')->access('edit', $customer));
    $this->assertTrue($new_order_item->get('overridden_unit_price')->access('edit', $customer));
    $this->assertTrue($new_order_item->get('purchased_entity')->access('edit', $customer));
    // The title is set from the purchased entity.
    $this->assertFalse($new_order_item->get('title')->access('edit', $customer));
    $this->assertFalse($new_order_item->get('overridden_title')->access('edit', $customer));

    $admins = [
      $this->createUser(['administer commerce_order']),
      $this->createUser(['update default commerce_order', 'manage test commerce_order_item']),
    ];
    foreach ($admins as $admin) {
      foreach ($order_fields as $field_name) {
        $this->assertTrue($cart->get($field_name)->access('edit', $admin), $field_name);
      }
      foreach ($order_item_fields as $field_name) {
        $this->assertTrue($order_item->get($field_name)->access('edit', $admin), $field_name);
      }
    }

    // Users who can create orders can set the fields on new orders only.
    $creator = $this->createUser(['create default commerce_order']);
    $new_order = Order::create(['type' => 'default']);
    foreach ($order_fields as $field_name) {
      $this->assertTrue($new_order->get($field_name)->access('edit', $creator), $field_name);
      $this->assertFalse($cart->get($field_name)->access('edit', $creator), $field_name);
    }
  }

}
