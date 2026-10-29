<?php

namespace Drupal\commerce_promotion;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Provides an access control handler for coupons.
 *
 * Coupons are always managed in the scope of their parent (the promotion),
 * so they have a simplified permission set, and rely on parent access:
 * - A coupon can be viewed if the parent promotion can be viewed.
 * - A coupon can be updated or deleted if the parent promotion can be updated.
 * - A coupon can be created if the user has permission to update promotions.
 *
 * The "administer commerce_promotion" permission is also respected.
 */
class CouponAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  public function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($account->hasPermission($this->entityType->getAdminPermission())) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    /** @var \Drupal\commerce_promotion\Entity\CouponInterface $entity */
    $promotion = $entity->getPromotion();
    if (!$promotion) {
      // The coupon is malformed.
      return AccessResult::forbidden()->addCacheableDependency($entity);
    }
    $parent_operation = ($operation == 'view') ? 'view' : 'update';
    $result = $promotion->access($parent_operation, $account, TRUE);

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermissions($account, [
      'administer commerce_promotion',
      'update any commerce_promotion',
    ], 'OR');
  }

  /**
   * {@inheritdoc}
   */
  protected function checkFieldAccess($operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL) {
    // Only users who can update any promotion can edit restricted fields.
    // Users who can update their own promotions can update their coupons,
    // which must not allow moving a coupon to another promotion (e.g. through
    // JSON:API or REST).
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
