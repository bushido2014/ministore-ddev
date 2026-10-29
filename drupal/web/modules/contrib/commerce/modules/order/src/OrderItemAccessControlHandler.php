<?php

namespace Drupal\commerce_order;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler as CoreEntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Provides an access control handler for order items.
 *
 * Order items are always managed in the scope of their parent (the order),
 * so they have a simplified permission set, and rely on parent access
 * when possible:
 * - An order item can be viewed if the parent order can be viewed.
 * - An order item can be created, updated or deleted if the user has the
 *   "manage $bundle commerce_order_item" permission.
 *
 * The "administer commerce_order" permission is also respected.
 */
class OrderItemAccessControlHandler extends CoreEntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($account->hasPermission($this->entityType->getAdminPermission())) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $entity */
    $order = $entity->getOrder();
    if (!$order) {
      // The order item is malformed.
      return AccessResult::forbidden()->addCacheableDependency($entity);
    }

    if ($operation == 'view') {
      $result = $order->access('view', $account, TRUE);
    }
    else {
      $bundle = $entity->bundle();
      $result = AccessResult::allowedIfHasPermission($account, "manage $bundle commerce_order_item");
      // For draft orders, grant access if the order item is not locked.
      if ($order->getState()->getId() === 'draft') {
        $result = $result->andIf(AccessResult::allowedIf(!$entity->isLocked()));
      }
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    // Create access depends on the "manage" permission because the full entity
    // is not passed, making it impossible to determine the parent order.
    $result = AccessResult::allowedIfHasPermissions($account, [
      $this->entityType->getAdminPermission(),
      "manage $entity_bundle commerce_order_item",
    ], 'OR');

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  protected function checkFieldAccess($operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL) {
    // Only users who can manage order items through their permissions can
    // edit restricted fields. Customers can be granted "update" access to
    // their own cart items (e.g. by commerce_cart), which must not allow
    // changing the prices, adjustments, etc. (e.g. through JSON:API or REST).
    // Modules add their own fields to the "restricted_fields" entity
    // type property, in hook_entity_type_build().
    $restricted_fields = $this->entityType->get('restricted_fields') ?? [];
    $field_name = $field_definition->getName();
    if ($operation == 'edit' && in_array($field_name, $restricted_fields, TRUE)) {
      $order_item = $items?->getEntity();
      // Allow setting the purchased entity and the unit price of new order
      // items, which the "Add to cart" form exposes (the variation selector,
      // and the unit price e.g. for donations).
      if ($order_item?->isNew() && in_array($field_name, ['purchased_entity', 'unit_price', 'overridden_unit_price'], TRUE)) {
        return parent::checkFieldAccess($operation, $field_definition, $account, $items);
      }
      $permissions = [$this->entityType->getAdminPermission()];
      if ($order_item) {
        $permissions[] = "manage {$order_item->bundle()} commerce_order_item";
      }
      return AccessResult::allowedIfHasPermissions($account, $permissions, 'OR');
    }

    return parent::checkFieldAccess($operation, $field_definition, $account, $items);
  }

}
