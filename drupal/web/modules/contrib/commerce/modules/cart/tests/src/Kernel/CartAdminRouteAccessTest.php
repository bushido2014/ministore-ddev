<?php

namespace Drupal\Tests\commerce_cart\Kernel;

use Drupal\commerce_order\Entity\OrderItem;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that cart access does not grant access to the admin order routes.
 *
 * @group commerce
 */
class CartAdminRouteAccessTest extends CartKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_promotion',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('commerce_promotion');
    $this->installEntitySchema('commerce_promotion_coupon');
    $this->installSchema('commerce_promotion', ['commerce_promotion_usage']);
    $this->installConfig(['commerce_promotion']);
    // Create uid: 1 here so that it's skipped in test cases.
    $this->createUser();
  }

  /**
   * Tests the admin order routes access for customers and an administrator.
   */
  public function testAccess(): void {
    $admin = $this->createUser([
      'access commerce administration pages',
      'administer commerce_order',
    ]);
    // A customer, an anonymous customer, and a customer who can access the
    // admin pages without being allowed to update orders (e.g. a customer
    // service member who shops).
    $customers = [
      $this->createUser(),
      new AnonymousUserSession(),
      $this->createUser(['access commerce administration pages']),
    ];
    foreach ($customers as $customer) {
      $cart = $this->cartProvider->createCart('default', $this->store, $customer);
      $order_item = OrderItem::create([
        'type' => 'test',
        'quantity' => 1,
        'unit_price' => ['number' => '10.00', 'currency_code' => 'USD'],
      ]);
      $order_item->save();
      $this->cartManager->addOrderItem($cart, $order_item);
      // The customer can still update the cart outside the admin pages. The
      // result must vary by route, so it isn't reused on the admin pages.
      $result = $cart->access('update', $customer, TRUE);
      $this->assertTrue($result->isAllowed());
      $this->assertContains('route.name', $result->getCacheContexts());

      $order_parameters = ['commerce_order' => $cart->id()];
      $order_item_parameters = $order_parameters + ['commerce_order_item' => $order_item->id()];
      // A list of route name and parameters pairs, since the form mode route
      // is used more than once.
      $routes = [
        ['entity.commerce_order.edit_form', $order_parameters],
        [
          'entity.commerce_order.state_transition_form',
          $order_parameters + ['field_name' => 'state', 'transition_id' => 'place'],
        ],
        ['commerce_order.entity_form.form_mode', $order_parameters + ['form_mode' => 'order_details']],
        ['commerce_order.entity_form.form_mode', $order_parameters + ['form_mode' => 'add-items']],
        ['entity.commerce_order.manage_coupons_form', $order_parameters],
        ['entity.commerce_order_item.edit_form', $order_item_parameters],
        ['entity.commerce_order_item.duplicate', $order_item_parameters],
        ['entity.commerce_order_item.delete_form', $order_item_parameters],
      ];
      foreach ($routes as [$route_name, $parameters]) {
        $this->assertFalse($this->checkRouteAccess($route_name, $parameters, $customer), $route_name);
        $this->assertTrue($this->checkRouteAccess($route_name, $parameters, $admin), $route_name);
      }
    }
  }

  /**
   * Checks access to the given route, as if it was the current route.
   *
   * @param string $route_name
   *   The route name.
   * @param array $parameters
   *   The route parameters.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return bool
   *   TRUE if the account has access, FALSE otherwise.
   */
  protected function checkRouteAccess(string $route_name, array $parameters, AccountInterface $account): bool {
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $request = Request::create('/');
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $this->container->get('request_stack')->push($request);
    $this->container->get('entity_type.manager')->getAccessControlHandler('commerce_order')->resetCache();
    $this->container->get('entity_type.manager')->getAccessControlHandler('commerce_order_item')->resetCache();
    $result = $this->container->get('access_manager')->checkNamedRoute($route_name, $parameters, $account);
    $this->container->get('request_stack')->pop();
    return $result;
  }

}
