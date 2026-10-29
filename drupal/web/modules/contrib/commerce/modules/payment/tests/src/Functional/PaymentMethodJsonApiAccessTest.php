<?php

namespace Drupal\Tests\commerce_payment\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentMethod;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests that customers can't change restricted fields through JSON:API.
 *
 * @group commerce
 */
class PaymentMethodJsonApiAccessTest extends CommerceBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_payment',
    'commerce_payment_example',
    'jsonapi',
    'basic_auth',
  ];

  /**
   * The payment gateway.
   *
   * @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface
   */
  protected $paymentGateway;

  /**
   * The customer.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $customer;

  /**
   * The customer's payment method.
   *
   * @var \Drupal\commerce_payment\Entity\PaymentMethodInterface
   */
  protected $paymentMethod;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config('jsonapi.settings')->set('read_only', FALSE)->save(TRUE);

    $this->paymentGateway = PaymentGateway::create([
      'id' => 'onsite',
      'label' => 'On-site',
      'plugin' => 'example_onsite',
    ]);
    $this->paymentGateway->save();

    $this->customer = $this->drupalCreateUser(['manage own commerce_payment_method']);
    $this->paymentMethod = PaymentMethod::create([
      'type' => 'credit_card',
      'payment_gateway' => $this->paymentGateway->id(),
      'uid' => $this->customer->id(),
      'remote_id' => 'customer-token',
      'reusable' => TRUE,
    ]);
    $this->paymentMethod->save();
  }

  /**
   * Tests that the customer can't see the remote ID of their payment method.
   */
  public function testView(): void {
    $response = $this->request('GET', '/' . $this->paymentMethod->uuid());
    $this->assertSame(200, $response->getStatusCode());
    $document = Json::decode((string) $response->getBody());
    $this->assertArrayNotHasKey('remote_id', $document['data']['attributes']);
    $this->assertArrayHasKey('card_type', $document['data']['attributes']);
  }

  /**
   * Tests changing the customer's payment method.
   */
  public function testUpdate(): void {
    $response = $this->request('PATCH', '/' . $this->paymentMethod->uuid(), [
      'id' => $this->paymentMethod->uuid(),
      'attributes' => ['remote_id' => 'other-customer-token'],
    ]);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertEquals('customer-token', $this->reloadEntity($this->paymentMethod)->getRemoteId());

    // The default flag can still be changed.
    $response = $this->request('PATCH', '/' . $this->paymentMethod->uuid(), [
      'id' => $this->paymentMethod->uuid(),
      'attributes' => ['is_default' => TRUE],
    ]);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertTrue($this->reloadEntity($this->paymentMethod)->isDefault());
  }

  /**
   * Tests creating a payment method.
   */
  public function testCreate(): void {
    $response = $this->request('POST', '', [
      'attributes' => [
        'payment_gateway_mode' => 'test',
        'remote_id' => 'other-customer-token',
        'reusable' => TRUE,
        'card_type' => 'visa',
        'card_number' => '1111',
      ],
      'relationships' => [
        'payment_gateway' => [
          'data' => [
            'type' => 'commerce_payment_gateway--commerce_payment_gateway',
            'id' => $this->paymentGateway->uuid(),
          ],
        ],
      ],
    ]);
    $this->assertSame(403, $response->getStatusCode());
    $payment_method_ids = $this->container->get('entity_type.manager')
      ->getStorage('commerce_payment_method')
      ->getQuery()
      ->accessCheck(FALSE)
      ->execute();
    $this->assertEquals([$this->paymentMethod->id()], array_values($payment_method_ids));
  }

  /**
   * Makes a JSON:API request for a credit card payment method as the customer.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   The path after the resource type, e.g. "/{uuid}".
   * @param array|null $data
   *   The resource object data, without the type. NULL for no request body.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
   */
  protected function request(string $method, string $path, ?array $data = NULL): ResponseInterface {
    $url = $this->buildUrl('/jsonapi/commerce_payment_method/credit_card' . $path);
    $options = [
      'auth' => [$this->customer->getAccountName(), $this->customer->passRaw],
      'headers' => [
        'Accept' => 'application/vnd.api+json',
        'Content-Type' => 'application/vnd.api+json',
      ],
      'http_errors' => FALSE,
    ];
    if ($data !== NULL) {
      $options['body'] = Json::encode([
        'data' => ['type' => 'commerce_payment_method--credit_card'] + $data,
      ]);
    }
    return $this->getHttpClient()->request($method, $url, $options);
  }

}
