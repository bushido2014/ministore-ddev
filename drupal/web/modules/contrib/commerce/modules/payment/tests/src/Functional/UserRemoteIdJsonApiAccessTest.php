<?php

namespace Drupal\Tests\commerce_payment\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;
use Drupal\user\UserInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests that customers can't see or change their remote IDs through JSON:API.
 *
 * @group commerce
 */
class UserRemoteIdJsonApiAccessTest extends CommerceBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_payment',
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config('jsonapi.settings')->set('read_only', FALSE)->save(TRUE);

    $this->customer = $this->drupalCreateUser();
    $this->customer->get('commerce_remote_id')->setByProvider('example|test', 'cus_123');
    $this->customer->save();
  }

  /**
   * Tests changing the customer's own remote IDs.
   */
  public function testUpdate(): void {
    $response = $this->request('PATCH', $this->customer, [
      'id' => $this->customer->uuid(),
      'attributes' => [
        'commerce_remote_id' => [
          ['provider' => 'example|test', 'remote_id' => 'cus_other'],
        ],
      ],
    ]);
    $this->assertSame(403, $response->getStatusCode());
    $customer = $this->reloadEntity($this->customer);
    $this->assertEquals('cus_123', $customer->get('commerce_remote_id')->getByProvider('example|test'));
  }

  /**
   * Tests viewing the remote IDs.
   */
  public function testView(): void {
    // The customer can't see their own remote IDs.
    $attributes = $this->getAttributes($this->customer, $this->customer);
    $this->assertArrayNotHasKey('commerce_remote_id', $attributes);
    $this->assertArrayHasKey('mail', $attributes);

    // Neither can users who can view other users' profiles.
    $profile_viewer = $this->drupalCreateUser(['access user profiles']);
    $attributes = $this->getAttributes($this->customer, $profile_viewer);
    $this->assertArrayNotHasKey('commerce_remote_id', $attributes);
    $this->assertArrayHasKey('name', $attributes);

    $admin = $this->drupalCreateUser(['administer users']);
    $attributes = $this->getAttributes($this->customer, $admin);
    $this->assertEquals('cus_123', $attributes['commerce_remote_id'][0]['remote_id']);
  }

  /**
   * Gets the attributes of a user's JSON:API resource object.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user to get.
   * @param \Drupal\user\UserInterface $account
   *   The account making the request.
   *
   * @return array
   *   The attributes.
   */
  protected function getAttributes(UserInterface $user, UserInterface $account): array {
    $response = $this->request('GET', $user, NULL, $account);
    $this->assertSame(200, $response->getStatusCode());
    $document = Json::decode((string) $response->getBody());
    return $document['data']['attributes'];
  }

  /**
   * Makes a JSON:API request for a user.
   *
   * @param string $method
   *   The HTTP method.
   * @param \Drupal\user\UserInterface $user
   *   The user the request is about.
   * @param array|null $data
   *   The resource object data, without the type. NULL for no request body.
   * @param \Drupal\user\UserInterface|null $account
   *   The account making the request. Defaults to the customer.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
   */
  protected function request(string $method, UserInterface $user, ?array $data = NULL, ?UserInterface $account = NULL): ResponseInterface {
    $account = $account ?? $this->customer;
    $url = $this->buildUrl('/jsonapi/user/user/' . $user->uuid());
    $options = [
      'auth' => [$account->getAccountName(), $account->passRaw],
      'headers' => [
        'Accept' => 'application/vnd.api+json',
        'Content-Type' => 'application/vnd.api+json',
      ],
      'http_errors' => FALSE,
    ];
    if ($data !== NULL) {
      $options['body'] = Json::encode([
        'data' => ['type' => 'user--user'] + $data,
      ]);
    }
    return $this->getHttpClient()->request($method, $url, $options);
  }

}
