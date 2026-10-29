<?php

namespace Drupal\Tests\commerce_order\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Url;
use Drupal\commerce_order\Form\OrderAddForm;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that customers are only listed to users who can assign them.
 *
 * @coversDefaultClass \Drupal\commerce_order\Plugin\EntityReferenceSelection\UserSearch
 * @group commerce
 */
class OrderCustomerAutocompleteTest extends OrderKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create uid: 1 here so that it's skipped in test cases.
    $this->createUser();
    $this->createUser([], 'customer', FALSE, ['mail' => 'customer@example.com']);
  }

  /**
   * Tests the order add form's customer autocomplete without a session.
   */
  public function testAnonymousAutocomplete(): void {
    // Get the autocomplete URL from the order add form, as rendered for staff.
    $this->setCurrentUser($this->createUser(['create default commerce_order', 'view commerce_store']));
    $form = $this->container->get('form_builder')->getForm(OrderAddForm::class);
    $element = $form['customer']['uid'];
    $this->assertSame('commerce:user', $element['#selection_handler']);
    $url = Url::fromRoute($element['#autocomplete_route_name'], $element['#autocomplete_route_parameters'])->toString();

    // The URL works without a session, but must not list any customers.
    $this->setCurrentUser(new AnonymousUserSession());
    $request = Request::create($url, 'GET', ['q' => 'customer@example']);
    $response = $this->container->get('http_kernel')->handle($request);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([], Json::decode((string) $response->getContent()));
  }

  /**
   * @covers ::getReferenceableEntities
   */
  public function testReferenceableEntities(): void {
    $users = [
      'create orders' => [$this->createUser(['create default commerce_order']), TRUE],
      'reassign orders' => [$this->createUser(['reassign commerce_order']), TRUE],
      'administer orders' => [$this->createUser(['administer commerce_order']), TRUE],
      'no order permissions' => [$this->createUser(['view commerce_store']), FALSE],
      'anonymous' => [new AnonymousUserSession(), FALSE],
    ];
    $selection_manager = $this->container->get('plugin.manager.entity_reference_selection');
    foreach ($users as $label => [$account, $expected]) {
      $this->setCurrentUser($account);
      $handler = $selection_manager->getInstance([
        'target_type' => 'user',
        'handler' => 'commerce:user',
        'match_operator' => 'CONTAINS',
        'include_anonymous' => FALSE,
      ]);
      $result = $handler->getReferenceableEntities('customer@example');
      $this->assertSame($expected, !empty($result), $label);
    }
  }

}
