<?php

namespace Drupal\Tests\commerce_order\Unit\EventSubscriber;

use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\Tests\UnitTestCase;
use Drupal\commerce_order\EventSubscriber\OrderAdminRouteSubscriber;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * @coversDefaultClass \Drupal\commerce_order\EventSubscriber\OrderAdminRouteSubscriber
 * @group commerce
 */
class OrderAdminRouteSubscriberTest extends UnitTestCase {

  /**
   * @covers ::alterRoutes
   */
  public function testAlterRoutes(): void {
    $collection = new RouteCollection();
    $collection->add('contrib_admin', new Route('/admin/commerce/orders/{commerce_order}/contrib/{transition_id}', [], [
      '_entity_access' => 'commerce_order.update',
    ]));
    $collection->add('contrib_admin_permission', new Route('/admin/commerce/orders/{commerce_order}/payments', [], [
      '_permission' => 'administer commerce_payment',
    ]));
    $collection->add('contrib_frontend', new Route('/checkout/{commerce_order}/contrib', [], [
      '_entity_access' => 'commerce_order.update',
    ]));
    $collection->add('admin_no_order', new Route('/admin/commerce/products/{commerce_product}', [], [
      '_entity_access' => 'commerce_product.update',
    ]));

    $subscriber = new OrderAdminRouteSubscriber();
    $subscriber->onAlterRoutes(new RouteBuildEvent($collection));

    $this->assertEquals('access commerce administration pages', $collection->get('contrib_admin')->getRequirement('_permission'));
    $this->assertEquals('administer commerce_payment', $collection->get('contrib_admin_permission')->getRequirement('_permission'));
    $this->assertNull($collection->get('contrib_frontend')->getRequirement('_permission'));
    $this->assertNull($collection->get('admin_no_order')->getRequirement('_permission'));
  }

}
