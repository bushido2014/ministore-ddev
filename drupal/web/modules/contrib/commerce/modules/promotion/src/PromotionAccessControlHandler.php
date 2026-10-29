<?php

namespace Drupal\commerce_promotion;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\entity\EntityAccessControlHandler;

/**
 * Controls access based on the Promotion entity permissions.
 */
class PromotionAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkFieldAccess($operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL) {
    // Only users who can update any promotion can edit restricted fields.
    // Users who can update their own promotions must not be able to reference
    // the coupons of other promotions (e.g. through JSON:API or REST).
    // Modules add their own fields to the "restricted_fields" entity
    // type property, in hook_entity_type_build().
    $restricted_fields = $this->entityType->get('restricted_fields') ?? [];
    if ($operation == 'edit' && in_array($field_definition->getName(), $restricted_fields, TRUE)) {
      return AccessResult::allowedIfHasPermissions($account, [
        $this->entityType->getAdminPermission(),
        'update any commerce_promotion',
      ], 'OR');
    }

    return parent::checkFieldAccess($operation, $field_definition, $account, $items);
  }

}
