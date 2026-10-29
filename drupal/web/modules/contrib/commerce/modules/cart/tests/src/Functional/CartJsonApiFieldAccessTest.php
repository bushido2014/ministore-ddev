<?php

namespace Drupal\Tests\commerce_cart\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Tests\commerce_order\Functional\OrderBrowserTestBase;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests that customers can't change restricted fields through JSON:API.
 *
 * @group commerce
 */
class CartJsonApiFieldAccessTest extends OrderBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_cart',
    'jsonapi',
    'basic_auth',
  ];

  /**
   * The customer.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $customer;

  /**
   * The customer's cart item.
   *
   * @var \Drupal\commerce_order\Entity\OrderItemInterface
   */
  protected $orderItem;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config('jsonapi.settings')->set('read_only', FALSE)->save(TRUE);

    $this->customer = $this->drupalCreateUser();
    $cart = $this->container->get('commerce_cart.cart_provider')->createCart('default', $this->store, $this->customer);
    $this->orderItem = $this->container->get('commerce_cart.cart_manager')->addEntity($cart, $this->variation);
  }

  /**
   * Tests changing the order item title.
   */
  public function testOrderItemTitle(): void {
    $original_title = $this->orderItem->getTitle();
    $response = $this->patchOrderItem([
      'title' => '$500 gift card <a href="https://example.invalid">link</a>',
      'overridden_title' => TRUE,
    ]);
    $this->assertSame(403, $response->getStatusCode());
    $order_item = $this->reloadEntity($this->orderItem);
    $this->assertEquals($original_title, $order_item->getTitle());
    $this->assertFalse($order_item->isTitleOverridden());

    // The quantity can still be changed.
    $response = $this->patchOrderItem(['quantity' => '2']);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertEquals(2, $this->reloadEntity($this->orderItem)->getQuantity());
  }

  /**
   * Changes attributes of the customer's order item through JSON:API.
   *
   * @param array $attributes
   *   The attributes.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
   */
  protected function patchOrderItem(array $attributes): ResponseInterface {
    assert($this->orderItem instanceof OrderItemInterface);
    $resource_type = 'commerce_order_item--' . $this->orderItem->bundle();
    $url = $this->buildUrl('/jsonapi/commerce_order_item/' . $this->orderItem->bundle() . '/' . $this->orderItem->uuid());
    return $this->getHttpClient()->request('PATCH', $url, [
      'auth' => [$this->customer->getAccountName(), $this->customer->passRaw],
      'headers' => [
        'Accept' => 'application/vnd.api+json',
        'Content-Type' => 'application/vnd.api+json',
      ],
      'body' => Json::encode([
        'data' => [
          'type' => $resource_type,
          'id' => $this->orderItem->uuid(),
          'attributes' => $attributes,
        ],
      ]),
      'http_errors' => FALSE,
    ]);
  }

}
