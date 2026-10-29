<?php

namespace Drupal\Tests\commerce_cart\Functional;

use Drupal\Tests\commerce_order\Functional\OrderBrowserTestBase;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\profile\Entity\Profile;

/**
 * Tests that customers can't change the items of a locked cart.
 *
 * While the customer is on the payment step the order is locked, so it is no
 * longer refreshed on load. A customer who could still update their order
 * items (e.g. through JSON:API or REST) could change them in that window, and
 * the off-site payment would cover a stale total. Each test tries the change
 * only if the customer is allowed to make it, then checks the payment.
 *
 * @group commerce
 */
class LockedCartTest extends OrderBrowserTestBase {

  /**
   * The cart.
   */
  protected OrderInterface $cart;

  /**
   * A more expensive variation.
   */
  protected ProductVariationInterface $expensiveVariation;

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
      'id' => 'offsite',
      'label' => 'Example',
      'plugin' => 'example_offsite_redirect',
      'configuration' => [
        'redirect_method' => 'post_manual',
        'payment_method_types' => ['credit_card'],
      ],
    ]);

    $this->expensiveVariation = $this->createEntity('commerce_product_variation', [
      'type' => 'default',
      'sku' => $this->randomMachineName(),
      'price' => [
        'number' => 9999,
        'currency_code' => 'USD',
      ],
    ]);
    $this->createEntity('commerce_product', [
      'type' => 'default',
      'title' => $this->randomMachineName(),
      'stores' => [$this->store],
      'variations' => [$this->expensiveVariation],
    ]);

    $customer = $this->createUser(['view commerce_product', 'access checkout']);
    $profile = Profile::create([
      'type' => 'customer',
      'uid' => $customer->id(),
      'address' => [
        'country_code' => 'US',
        'administrative_area' => 'SC',
        'locality' => 'Greenville',
        'postal_code' => '53140',
        'address_line1' => '9 Drupal Ave',
        'given_name' => 'Bryan',
        'family_name' => 'Centarro',
      ],
    ]);
    $profile->save();

    $order_item = $this->container->get('entity_type.manager')
      ->getStorage('commerce_order_item')
      ->createFromPurchasableEntity($this->variation);
    $order_item->save();
    $cart = $this->createEntity('commerce_order', [
      'type' => 'default',
      'store_id' => $this->store->id(),
      'state' => 'draft',
      'cart' => TRUE,
      'mail' => $customer->getEmail(),
      'uid' => $customer->id(),
      'ip_address' => '127.0.0.1',
      'billing_profile' => $profile,
      'order_items' => [$order_item],
    ]);
    $this->cart = $this->reloadEntity($cart);
    $this->drupalLogin($customer);
  }

  /**
   * Tests changing the quantity on the payment step.
   */
  public function testQuantity(): void {
    $order_item = $this->goToPaymentStep();
    if ($this->canEdit($order_item, 'quantity')) {
      $order_item->setQuantity('10');
      $order_item->save();
    }
    $this->assertPaymentCoversOrder();
  }

  /**
   * Tests swapping the purchased entity on the payment step.
   */
  public function testPurchasedEntity(): void {
    $order_item = $this->goToPaymentStep();
    if ($this->canEdit($order_item, 'purchased_entity')) {
      $order_item->set('purchased_entity', $this->expensiveVariation);
      $order_item->save();
    }
    $this->assertPaymentCoversOrder();
  }

  /**
   * Walks the customer through checkout to the off-site payment page.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface
   *   The cart's order item.
   */
  protected function goToPaymentStep(): OrderItemInterface {
    $this->drupalGet("checkout/{$this->cart->id()}");
    $this->submitForm([], 'Continue to review');
    $this->submitForm([], 'Pay and complete purchase');
    $this->assertSession()->addressEquals("checkout/{$this->cart->id()}/payment");
    $this->cart = $this->reloadEntity($this->cart);
    $this->assertTrue($this->cart->isLocked());
    return $this->reloadEntity($this->cart->getItems()[0]);
  }

  /**
   * Checks whether the customer can edit the given order item field.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item.
   * @param string $field_name
   *   The field name.
   *
   * @return bool
   *   TRUE if the customer could change the field through an entity write
   *   API, FALSE otherwise.
   */
  protected function canEdit(OrderItemInterface $order_item, string $field_name): bool {
    return $order_item->access('update', $this->loggedInUser) && $order_item->get($field_name)->access('edit', $this->loggedInUser);
  }

  /**
   * Returns from the gateway and asserts the payment covers the order.
   */
  protected function assertPaymentCoversOrder(): void {
    $this->submitForm([], 'Proceed to Example');
    $this->assertSession()->pageTextContains('Your order number is');

    $this->cart = $this->reloadEntity($this->cart);
    $order_item = $this->reloadEntity($this->cart->getItems()[0]);
    $payment = Payment::load(1);
    $this->assertNotNull($payment);
    $purchased_price = $order_item->getPurchasedEntity()->getPrice()->multiply($order_item->getQuantity());
    $this->assertTrue($payment->getAmount()->greaterThanOrEqual($purchased_price), sprintf('Order is paid (%s) with a payment of %s for %s x %s priced at %s.', $this->cart->isPaid() ? 'yes' : 'no', $payment->getAmount(), $order_item->getQuantity(), $order_item->getPurchasedEntity()->getSku(), $purchased_price));
    $this->assertTrue($this->cart->getBalance()->isZero());
  }

}
