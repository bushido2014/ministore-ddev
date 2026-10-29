<?php

namespace Drupal\commerce_store;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\entity\EntityAccessControlHandler;

/**
 * Controls access based on the Store entity permissions.
 */
class StoreAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkFieldAccess($operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL) {
    // Only store administrators can edit restricted fields. Users can be
    // granted "update" access to some stores, which must not allow changes
    // affecting the whole site (e.g. making their store the default store,
    // which unsets the flag on the previous default store).
    // Modules add their own fields to the "restricted_fields" entity
    // type property, in hook_entity_type_build().
    $restricted_fields = $this->entityType->get('restricted_fields') ?? [];
    if ($operation == 'edit' && in_array($field_definition->getName(), $restricted_fields, TRUE)) {
      return AccessResult::allowedIfHasPermission($account, $this->entityType->getAdminPermission());
    }

    return parent::checkFieldAccess($operation, $field_definition, $account, $items);
  }

}
