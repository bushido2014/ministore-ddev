<?php

namespace Drupal\Tests\commerce_cart\Kernel;

use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests that customers can't access any admin route for their own carts.
 *
 * Unlike CartAdminRouteAccessTest, this checks every admin route, so that
 * new routes are covered without having to be listed.
 *
 * @group commerce
 */
class CartAllAdminRoutesAccessTest extends CartKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_checkout',
    'commerce_log',
    'commerce_payment',
    'commerce_promotion',
    'commerce_tax',
  ];

  /**
   * The entities used to fill in route parameters, keyed by entity type ID.
   *
   * @var \Drupal\Core\Entity\EntityInterface[]
   */
  protected array $entities = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('commerce_log');
    $this->installEntitySchema('commerce_payment');
    $this->installEntitySchema('commerce_payment_method');
    $this->installEntitySchema('commerce_promotion');
    $this->installEntitySchema('commerce_promotion_coupon');
    $this->installSchema('commerce_promotion', ['commerce_promotion_usage']);
    $this->installConfig([
      'commerce_checkout',
      'commerce_log',
      'commerce_payment',
      'commerce_promotion',
      'commerce_tax',
    ]);
    // Create uid: 1 here so that it's skipped in test cases.
    $this->createUser();

    PaymentGateway::create([
      'id' => 'manual',
      'label' => 'Manual',
      'plugin' => 'manual',
    ])->save();
  }

  /**
   * Tests the admin routes access for an anonymous customer.
   */
  public function testAnonymousAccess(): void {
    $this->assertNoAdminRouteAccess(new AnonymousUserSession());
  }

  /**
   * Tests the admin routes access for an authenticated customer.
   */
  public function testAuthenticatedAccess(): void {
    $this->assertNoAdminRouteAccess($this->createUser());
  }

  /**
   * Asserts that the customer can't access any admin route for their cart.
   *
   * @param \Drupal\Core\Session\AccountInterface $customer
   *   The customer.
   */
  protected function assertNoAdminRouteAccess(AccountInterface $customer): void {
    $cart = $this->cartProvider->createCart('default', $this->store, $customer);
    $order_item = OrderItem::create([
      'type' => 'test',
      'quantity' => 1,
      'unit_price' => ['number' => '10.00', 'currency_code' => 'USD'],
    ]);
    $order_item->save();
    $this->cartManager->addOrderItem($cart, $order_item);
    $payment = Payment::create([
      'state' => 'pending',
      'amount' => ['number' => '10.00', 'currency_code' => 'USD'],
      'payment_gateway' => 'manual',
      'order_id' => $cart->id(),
    ]);
    $payment->save();
    // The customer has access to the cart, which must not grant access to the
    // admin routes.
    $this->assertTrue($cart->access('update', $customer));
    $this->assertTrue($order_item->access('update', $customer));

    $this->entities = [
      'commerce_order' => $cart,
      'commerce_order_item' => $order_item,
      'commerce_payment' => $payment,
    ];

    // Admin routes that customers can access regardless of their cart.
    $customer_routes = [
      'system.batch_page.html',
      'system.batch_page.json',
      // The customer's own account.
      'entity.user.edit_form',
    ];
    $checked = [];
    $allowed = [];
    $unchecked = [];
    foreach ($this->container->get('router.route_provider')->getAllRoutes() as $route_name => $route) {
      if (!$this->isAdminRoute($route) || in_array($route_name, $customer_routes, TRUE)) {
        continue;
      }
      $parameter_sets = $this->getParameterSets($route);
      if ($parameter_sets === NULL) {
        // Routes for orders and their related entities must be checked.
        if ($this->isOrderRoute($route)) {
          $unchecked[] = $route_name;
        }
        continue;
      }
      foreach ($parameter_sets as $parameters) {
        $checked[$route_name] = $route_name;
        if ($this->checkRouteAccess($route_name, $route, $parameters, $customer)) {
          $allowed[] = $route_name . ' ' . json_encode($parameters);
        }
      }
    }

    $this->assertSame([], $allowed, 'The customer has access to admin routes.');
    $this->assertSame([], $unchecked, 'Order admin routes could not be checked, their parameters need to be added to the test.');
    // Confirm that the routes reachable through the cart access in the past
    // were checked.
    $expected_routes = [
      'entity.commerce_order.edit_form',
      'entity.commerce_order.state_transition_form',
      'commerce_order.entity_form.form_mode',
      'entity.commerce_order.manage_coupons_form',
      'entity.commerce_order_item.edit_form',
      'entity.commerce_order_item.duplicate',
      'entity.commerce_order_item.delete_form',
      'entity.commerce_payment.collection',
    ];
    foreach ($expected_routes as $route_name) {
      $this->assertContains($route_name, $checked);
    }
  }

  /**
   * Checks whether the given route is an admin route.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   *
   * @return bool
   *   TRUE if the route is an admin route, FALSE otherwise.
   */
  protected function isAdminRoute(Route $route): bool {
    return $route->getOption('_admin_route') || str_starts_with($route->getPath(), '/admin');
  }

  /**
   * Checks whether the given route is for an order or a related entity.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   *
   * @return bool
   *   TRUE if the route is for an order or a related entity, FALSE otherwise.
   */
  protected function isOrderRoute(Route $route): bool {
    if (str_starts_with($route->getPath(), '/admin/commerce/orders')) {
      return TRUE;
    }
    foreach ($route->compile()->getPathVariables() as $variable) {
      if (isset($this->entities[$this->getEntityTypeId($route, $variable)])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Gets the sets of parameters to check the given route with.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   *
   * @return array[]|null
   *   The sets of parameters, or NULL if a parameter can't be filled in.
   */
  protected function getParameterSets(Route $route): ?array {
    $candidates = [];
    foreach ($route->compile()->getPathVariables() as $variable) {
      $values = $this->getParameterValues($route, $variable);
      if (!$values) {
        return NULL;
      }
      $candidates[$variable] = $values;
    }

    // Build every combination of the candidate values.
    $parameter_sets = [[]];
    foreach ($candidates as $variable => $values) {
      $new_sets = [];
      foreach ($parameter_sets as $parameters) {
        foreach ($values as $value) {
          $new_sets[] = $parameters + [$variable => $value];
        }
      }
      $parameter_sets = $new_sets;
    }
    return $parameter_sets;
  }

  /**
   * Gets the values to check for the given route parameter.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   * @param string $variable
   *   The route parameter.
   *
   * @return array
   *   The values, or an empty array if the parameter can't be filled in.
   */
  protected function getParameterValues(Route $route, string $variable): array {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $entity_type_id = $this->getEntityTypeId($route, $variable);
    if ($entity_type_id) {
      if (isset($this->entities[$entity_type_id])) {
        return [$this->entities[$entity_type_id]->id()];
      }
      // Use any existing entity, e.g. the store or the order type.
      try {
        $ids = $entity_type_manager->getStorage($entity_type_id)->getQuery()
          ->accessCheck(FALSE)
          ->range(0, 1)
          ->execute();
      }
      catch (DatabaseExceptionWrapper) {
        // The entity type's schema isn't installed in this test.
        $ids = [];
      }
      return array_values($ids);
    }

    $order_entity_type_ids = ['commerce_order', 'commerce_order_item', 'commerce_payment'];
    switch ($variable) {
      case 'field_name':
        return ['state'];

      case 'transition_id':
        return ['place', 'cancel'];

      case 'form_mode':
        $form_modes = [];
        foreach ($order_entity_type_ids as $id) {
          $definition = $entity_type_manager->getDefinition($id);
          $form_modes = array_merge($form_modes, array_keys($definition->getHandlerClasses()['form'] ?? []));
          $form_modes = array_merge($form_modes, array_keys($this->container->get('entity_display.repository')->getFormModes($id)));
        }
        return array_values(array_unique($form_modes));

      case 'operation':
        $payment = $this->entities['commerce_payment'];
        return array_keys($payment->getPaymentGateway()->getPlugin()->buildPaymentOperations($payment));
    }
    return [];
  }

  /**
   * Gets the entity type ID of the given route parameter.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   * @param string $variable
   *   The route parameter.
   *
   * @return string|null
   *   The entity type ID, or NULL if the parameter isn't an entity.
   */
  protected function getEntityTypeId(Route $route, string $variable): ?string {
    $parameters = $route->getOption('parameters') ?? [];
    $type = $parameters[$variable]['type'] ?? '';
    if (str_starts_with($type, 'entity:')) {
      return substr($type, 7);
    }
    $entity_type_manager = $this->container->get('entity_type.manager');
    if ($entity_type_manager->hasDefinition($variable)) {
      return $variable;
    }
    return NULL;
  }

  /**
   * Checks access to the given route, as if it was the current route.
   *
   * @param string $route_name
   *   The route name.
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   * @param array $parameters
   *   The route parameters.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return bool
   *   TRUE if the account has access, FALSE otherwise.
   */
  protected function checkRouteAccess(string $route_name, Route $route, array $parameters, AccountInterface $account): bool {
    $request = Request::create('/');
    // Some access checks use the session, e.g. the "delete multiple" one.
    $request->setSession($this->container->get('session'));
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $this->container->get('request_stack')->push($request);
    $entity_type_manager = $this->container->get('entity_type.manager');
    foreach ($entity_type_manager->getDefinitions() as $entity_type) {
      if ($entity_type instanceof ContentEntityTypeInterface) {
        $entity_type_manager->getAccessControlHandler($entity_type->id())->resetCache();
      }
    }
    try {
      return $this->container->get('access_manager')->checkNamedRoute($route_name, $parameters, $account);
    }
    finally {
      $this->container->get('request_stack')->pop();
    }
  }

}
