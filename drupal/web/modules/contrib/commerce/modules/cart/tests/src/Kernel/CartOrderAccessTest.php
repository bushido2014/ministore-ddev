<?php

namespace Drupal\Tests\commerce_cart\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemInterface;

/**
 * Tests that customers only have access to draft orders that are carts.
 *
 * @group commerce
 */
class CartOrderAccessTest extends CartKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create uid: 1 here so that it's skipped in test cases.
    $this->createUser();
  }

  /**
   * Tests access to an authenticated customer's draft orders.
   */
  public function testAuthenticatedAccess(): void {
    $customer = $this->createUser();
    $cart = $this->cartProvider->createCart('default', $this->store, $customer);
    $cart_item = $this->addItem($cart);
    $this->assertOrderAccess(TRUE, $cart, $cart_item, $customer);
    $cache_contexts = $cart->access('update', $customer, TRUE)->getCacheContexts();
    $this->assertContains('user', $cache_contexts);
    $this->assertNotContains('session', $cache_contexts);

    // A draft order that isn't a cart, e.g. created by staff for the customer,
    // or a recurring order.
    $order = Order::create([
      'type' => 'default',
      'state' => 'draft',
      'uid' => $customer->id(),
      'store_id' => $this->store->id(),
      'cart' => FALSE,
    ]);
    $order->save();
    $order_item = $this->addItem($order);
    $this->assertOrderAccess(FALSE, $order, $order_item, $customer);

    // Turning the order into a cart grants access.
    $order->set('cart', TRUE);
    $order->save();
    $this->resetAccessCache();
    $this->assertOrderAccess(TRUE, $order, $order_item, $customer);

    // Placed orders remain viewable by the customer.
    $this->cartProvider->finalizeCart($cart);
    $cart->getState()->applyTransitionById('place');
    $cart->save();
    $this->resetAccessCache();
    $this->assertFalse((bool) $cart->get('cart')->value);
    $this->assertTrue($cart->access('view', $customer));
    $this->assertFalse($cart->access('update', $customer));
  }

  /**
   * Tests access to an anonymous customer's draft orders.
   */
  public function testAnonymousAccess(): void {
    $anonymous = new AnonymousUserSession();
    $cart = $this->cartProvider->createCart('default', $this->store, $anonymous);
    $cart_item = $this->addItem($cart);
    $this->assertOrderAccess(TRUE, $cart, $cart_item, $anonymous);
    // Anonymous customers share the same user ID, the result depends on the
    // cart IDs in their session.
    foreach (['view', 'update'] as $operation) {
      $this->assertContains('session', $cart->access($operation, $anonymous, TRUE)->getCacheContexts());
    }

    // The cart flag is cleared while the order is still in the cart session.
    $cart->set('cart', FALSE);
    $cart->save();
    $this->resetAccessCache();
    $this->assertOrderAccess(FALSE, $cart, $cart_item, $anonymous);
  }

  /**
   * Tests access to the items of a locked cart.
   */
  public function testLockedCartAccess(): void {
    $customer = $this->createUser();
    $cart = $this->cartProvider->createCart('default', $this->store, $customer);
    $cart_item = $this->addItem($cart);

    // Checkout locks the order on the payment step.
    $cart->lock();
    $cart->save();
    $this->resetAccessCache();
    $cart_item = $this->reloadEntity($cart_item);
    $this->assertTrue($cart_item->access('view', $customer));
    $this->assertFalse($cart_item->access('update', $customer));
    // Payment routes on the payment step (e.g. PayPal's approve route)
    // require update access to the order itself.
    $this->assertTrue($cart->access('update', $customer));

    $cart->unlock();
    $cart->save();
    $this->resetAccessCache();
    $this->assertTrue($this->reloadEntity($cart_item)->access('update', $customer));
  }

  /**
   * Tests access to locked order items.
   */
  public function testLockedOrderItemAccess(): void {
    $customer = $this->createUser();
    $cart = $this->cartProvider->createCart('default', $this->store, $customer);
    $cart_item = $this->addItem($cart);

    // E.g. an order item added by a promotion.
    $cart_item->lock();
    $cart_item->save();
    $this->resetAccessCache();
    $this->assertTrue($cart_item->access('view', $customer));
    $this->assertFalse($cart_item->access('update', $customer));
    $this->assertTrue($cart->access('update', $customer));

    $cart_item->unlock();
    $cart_item->save();
    $this->resetAccessCache();
    $this->assertTrue($cart_item->access('update', $customer));
  }

  /**
   * Adds an order item to the given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface
   *   The order item.
   */
  protected function addItem(OrderInterface $order): OrderItemInterface {
    $order_item = OrderItem::create([
      'type' => 'test',
      'quantity' => 1,
      'unit_price' => ['number' => '10.00', 'currency_code' => 'USD'],
    ]);
    $order_item->save();
    $order->addItem($order_item);
    $order->save();
    return $this->reloadEntity($order_item);
  }

  /**
   * Asserts the view and update access of an order and its order item.
   *
   * @param bool $expected
   *   The expected access.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   */
  protected function assertOrderAccess(bool $expected, OrderInterface $order, OrderItemInterface $order_item, AccountInterface $account): void {
    foreach (['view', 'update'] as $operation) {
      $this->assertSame($expected, $order->access($operation, $account), "Order $operation");
      $this->assertSame($expected, $order_item->access($operation, $account), "Order item $operation");
    }
  }

  /**
   * Resets the order and order item access caches.
   */
  protected function resetAccessCache(): void {
    $this->entityTypeManager->getAccessControlHandler('commerce_order')->resetCache();
    $this->entityTypeManager->getAccessControlHandler('commerce_order_item')->resetCache();
  }

}
