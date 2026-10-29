<?php

namespace Drupal\commerce_order\EventSubscriber;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\RouteCollection;

/**
 * Restricts the order admin routes to administrators.
 *
 * Customers can be granted "update" access to their own draft orders (e.g.
 * by commerce_cart), so admin routes that only check order access would be
 * reachable by them. This applies to routes provided by state_machine and by
 * contrib modules, which is why the permission is enforced here.
 *
 * Routes that already require a permission are left untouched, to avoid
 * overriding or weakening it.
 *
 * @see \Drupal\state_machine\Routing\RouteSubscriber::alterRoutes()
 * @see \Drupal\commerce_cart\Hook\CommerceCartHooks::orderAccess()
 */
class OrderAdminRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach ($collection as $route) {
      $path = $route->getPath();
      if (!str_starts_with($path, '/admin/') ||
        !str_contains($path, '{commerce_order}') ||
        $route->hasRequirement('_permission')) {
        continue;
      }
      $route->setRequirement('_permission', 'access commerce administration pages');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run late, so that routes added by other subscribers are included.
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', -1024];
    return $events;
  }

}
