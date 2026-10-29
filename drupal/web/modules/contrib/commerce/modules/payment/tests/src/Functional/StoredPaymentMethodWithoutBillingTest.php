<?php

namespace Drupal\Tests\commerce_payment\Functional;

use Drupal\Tests\commerce_order\Functional\OrderBrowserTestBase;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;

/**
 * Tests reusing a stored payment method that has no billing profile.
 *
 * A gateway that does not collect billing information stores its payment
 * methods without a billing profile. Choosing such a payment method on a
 * later checkout must not try to copy a missing profile onto the order.
 *
 * @group commerce
 */
class StoredPaymentMethodWithoutBillingTest extends OrderBrowserTestBase {

  /**
   * The order being checked out.
   */
  protected OrderInterface $order;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_product',
    'commerce_cart',
    'commerce_checkout',
    'commerce_payment',
    'commerce_payment_example',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->createEntity('commerce_payment_gateway', [
      'id' => 'stored_offsite',
      'label' => 'Stored off-site',
      'plugin' => 'example_stored_offsite_redirect',
      'configuration' => [
        'redirect_method' => 'post',
        'payment_method_types' => ['credit_card'],
        'collect_billing_information' => FALSE,
      ],
    ]);

    $payment_method = $this->createEntity('commerce_payment_method', [
      'uid' => $this->adminUser->id(),
      'type' => 'credit_card',
      'payment_gateway' => 'stored_offsite',
      'card_type' => 'visa',
      'card_number' => '1111',
      'remote_id' => '789',
      'reusable' => TRUE,
      'expires' => strtotime('+1 year'),
    ]);
    $this->assertNull($payment_method->getBillingProfile());

    $order_item = $this->container->get('entity_type.manager')
      ->getStorage('commerce_order_item')
      ->createFromPurchasableEntity($this->variation);
    $order_item->save();
    $this->order = $this->createEntity('commerce_order', [
      'type' => 'default',
      'store_id' => $this->store->id(),
      'state' => 'draft',
      'cart' => TRUE,
      'mail' => $this->adminUser->getEmail(),
      'uid' => $this->adminUser->id(),
      'ip_address' => '127.0.0.1',
      'order_items' => [$order_item],
    ]);
  }

  /**
   * Tests checking out with a stored payment method without a profile.
   */
  public function testCheckoutWithStoredPaymentMethodWithoutBilling(): void {
    $this->drupalGet('checkout/' . $this->order->id());
    $this->assertSession()->fieldExists('Visa ending in 1111');
    $this->submitForm([], 'Continue to review');
    $this->assertSession()->pageTextContains('Visa ending in 1111');
    $this->submitForm([], 'Pay and complete purchase');
    $this->assertSession()->pageTextContains('Your order number is 1.');

    $order = Order::load($this->order->id());
    $this->assertEquals('stored_offsite', $order->get('payment_gateway')->target_id);
    $this->assertEquals(1, $order->get('payment_method')->target_id);
    $this->assertNull($order->getBillingProfile());
  }

}
