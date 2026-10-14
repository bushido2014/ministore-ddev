<?php

namespace Drupal\commerce_order;

use Drupal\commerce_order\Controller\OrderItemController;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\entity\Routing\AdminHtmlRouteProvider;
use Symfony\Component\Routing\Route;

/**
 * Provides routes for the Order item entity.
 */
class OrderItemRouteProvider extends AdminHtmlRouteProvider {

  /**
   * {@inheritdoc}
   */
  public function getRoutes(EntityTypeInterface $entity_type) {
    $collection = parent::getRoutes($entity_type);
    $entity_type_id = $entity_type->id();

    if ($duplicate_route = $this->getDuplicateRoute($entity_type)) {
      $collection->add("entity.{$entity_type_id}.duplicate", $duplicate_route);
    }

    return $collection;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditFormRoute(EntityTypeInterface $entity_type): ?Route {
    $route = parent::getEditFormRoute($entity_type);
    if (!$route) {
      return NULL;
    }

    $route->setDefault('_title_callback', OrderItemController::class . '::editTitle');
    $this->addDefaultOptions($route);

    return $route;
  }

  /**
   * {@inheritdoc}
   */
  protected function getDeleteFormRoute(EntityTypeInterface $entity_type): ?Route {
    $route = parent::getDeleteFormRoute($entity_type);
    if (!$route) {
      return NULL;
    }

    $this->addDefaultOptions($route);

    return $route;
  }

  /**
   * Adds default options to the order item routes.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   */
  private function addDefaultOptions(Route $route): void {
    $route->setOption('parameters', [
      'commerce_order' => [
        'type' => 'entity:commerce_order',
      ],
      'commerce_order_item' => [
        'type' => 'entity:commerce_order_item',
      ],
    ]);
    $route->setOption('_admin_route', TRUE);
  }

  /**
   * Gets the duplicate-form route.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   *
   * @return \Symfony\Component\Routing\Route|null
   *   The generated route, if available.
   */
  private function getDuplicateRoute(EntityTypeInterface $entity_type): ?Route {
    if (!$entity_type->hasLinkTemplate('duplicate-form')) {
      return NULL;
    }

    $entity_type_id = $entity_type->id();
    $route = new Route($entity_type->getLinkTemplate('duplicate-form'));
    $route->addDefaults([
      '_entity_form' => "{$entity_type_id}.duplicate",
      '_title_callback' => '\Drupal\commerce_order\Form\OrderItemDuplicateForm::duplicateTitle',
    ]);
    $route->setRequirement('_entity_access', "{$entity_type_id}.duplicate");
    $this->addDefaultOptions($route);

    return $route;
  }

}
