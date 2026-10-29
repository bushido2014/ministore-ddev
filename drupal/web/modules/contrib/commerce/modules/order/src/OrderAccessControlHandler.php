<?php

namespace Drupal\commerce_order;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\entity\EntityAccessControlHandler;

/**
 * Controls access based on the Order entity permissions.
 */
class OrderAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $entity */
    $account = $this->prepareUser($account);
    // Unlocking an order requires the same permissions as 'update', with an
    // additional check to ensure that the order is actually locked.
    $additional_operation = '';
    if ($operation == 'unlock') {
      $operation = 'update';
      $additional_operation = 'unlock';
    }
    // Resending an order receipt requires the same permissions as 'view', with
    // an additional check to ensure that the order has been placed.
    elseif ($operation == 'resend_receipt') {
      if ($entity->getState()->getId() == 'draft') {
        return AccessResult::forbidden()->addCacheableDependency($entity);
      }
      $operation = 'view';
      $additional_operation = 'resend_receipt';
    }

    /** @var \Drupal\Core\Access\AccessResult $result */
    $result = parent::checkAccess($entity, $operation, $account);

    /** @var \Drupal\commerce_order\Entity\OrderInterface $entity */
    if ($result->isNeutral() && $operation == 'view') {
      if ($account->isAuthenticated() && $account->id() == $entity->getCustomerId() && empty($additional_operation)) {
        $result = AccessResult::allowedIfHasPermissions($account, ['view own commerce_order']);
        $result = $result->cachePerUser()->addCacheableDependency($entity);
      }
    }
    elseif (in_array($operation, ['update', 'delete'])) {
      if ($additional_operation == 'unlock') {
        // Check unlock operation.
        $result = AccessResult::allowedIf($entity->isLocked())
          ->andIf(AccessResult::allowedIfHasPermission($account, 'unlock orders'))
          ->andIf($result);
      }
      else {
        // Check update or delete operations.
        $result = AccessResult::allowedIf(!$entity->isLocked())->andIf($result);
      }

      $result = $result->addCacheableDependency($entity);
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  protected function checkFieldAccess($operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL) {
    // Only users who can update orders through their permissions can edit
    // restricted fields. Customers can be granted "update" access to
    // their own carts (e.g. by commerce_cart), which must not allow changing
    // the order state, totals, etc. (e.g. through JSON:API or REST).
    // Modules add their own fields to the "restricted_fields" entity
    // type property, in hook_entity_type_build().
    $restricted_fields = $this->entityType->get('restricted_fields') ?? [];
    if ($operation == 'edit' && in_array($field_definition->getName(), $restricted_fields, TRUE)) {
      $permissions = [$this->entityType->getAdminPermission()];
      if ($items) {
        $order = $items->getEntity();
        $permissions[] = "update {$order->bundle()} commerce_order";
        // Users who can create orders can set these fields on new orders
        // (e.g. through JSON:API or REST).
        if ($order->isNew()) {
          $permissions[] = "create {$order->bundle()} commerce_order";
        }
      }
      return AccessResult::allowedIfHasPermissions($account, $permissions, 'OR');
    }

    return parent::checkFieldAccess($operation, $field_definition, $account, $items);
  }

}
