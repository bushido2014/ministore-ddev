<?php

namespace Drupal\Tests\commerce_order\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;

/**
 * Tests that update access alone doesn't grant access to order admin routes.
 *
 * @group commerce
 */
class OrderAdminRouteAccessTest extends OrderKernelTestBase {

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
   * Tests the order admin routes access.
   */
  public function testAccess(): void {
    $customer = $this->createUser(['update default commerce_order']);
    $order_item = OrderItem::create([
      'type' => 'test',
      'quantity' => 1,
      'unit_price' => ['number' => '10.00', 'currency_code' => 'USD'],
    ]);
    $order_item->save();
    $order = Order::create([
      'type' => 'default',
      'state' => 'draft',
      'store_id' => $this->store,
      'uid' => $customer,
      'order_items' => [$order_item],
    ]);
    $order->save();
    $this->assertTrue($order->access('update', $customer));

    $order_parameters = ['commerce_order' => $order->id()];
    $order_item_parameters = $order_parameters + ['commerce_order_item' => $order_item->id()];
    $routes = [
      'entity.commerce_order.state_transition_form' => $order_parameters + [
        'field_name' => 'state',
        'transition_id' => 'place',
      ],
      'commerce_order.entity_form.form_mode' => $order_parameters + ['form_mode' => 'order_details'],
      'entity.commerce_order.manage_coupons_form' => $order_parameters,
      'entity.commerce_order_item.edit_form' => $order_item_parameters,
      'entity.commerce_order_item.duplicate' => $order_item_parameters,
      'entity.commerce_order_item.delete_form' => $order_item_parameters,
    ];
    $admin = $this->createUser([
      'access commerce administration pages',
      'administer commerce_order',
    ]);
    /** @var \Drupal\Core\Access\AccessManagerInterface $access_manager */
    $access_manager = $this->container->get('access_manager');
    foreach ($routes as $route_name => $parameters) {
      $this->assertFalse($access_manager->checkNamedRoute($route_name, $parameters, $customer), $route_name);
      $this->assertTrue($access_manager->checkNamedRoute($route_name, $parameters, $admin), $route_name);
    }

    // Ensure any order admin route requires a permission, including routes
    // that aren't listed above.
    foreach ($this->container->get('router.route_provider')->getAllRoutes() as $route_name => $route) {
      $path = $route->getPath();
      if (str_starts_with($path, '/admin/') && str_contains($path, '{commerce_order}')) {
        $this->assertTrue($route->hasRequirement('_permission'), $route_name);
      }
    }
  }

}
