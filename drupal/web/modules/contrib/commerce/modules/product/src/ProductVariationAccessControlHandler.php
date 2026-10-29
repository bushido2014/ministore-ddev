<?php

namespace Drupal\commerce_product;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler as CoreEntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Provides an access control handler for product variations.
 *
 * Product variations are always managed in the scope of their parent
 * (the product), so they have a simplified permission set, and rely on
 * parent access when possible:
 * - A product variation can be viewed if the parent product can be viewed.
 * - A product variation can be created, updated or deleted if the user has the
 *   "manage $bundle commerce_product_variation" permission.
 *
 * The "administer commerce_product" permission is also respected.
 */
class ProductVariationAccessControlHandler extends CoreEntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($account->hasPermission($this->entityType->getAdminPermission())) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    $bundle = $entity->bundle();
    $manage_bundle_permission = "manage $bundle commerce_product_variation";
    /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $entity */
    if ($operation === 'view' && !$entity->isPublished()) {
      return AccessResult::allowedIfHasPermission($account, $manage_bundle_permission)->addCacheableDependency($entity);
    }

    $product = $entity->getProduct();
    if (!$product) {
      return AccessResult::forbidden('The product variation is malformed.')->addCacheableDependency($entity);
    }

    if ($operation === 'view') {
      $result = $product->access('view', $account, TRUE);
      assert($result instanceof AccessResult);
      $result->addCacheableDependency($entity);
    }
    else {
      $result = AccessResult::allowedIfHasPermission($account, $manage_bundle_permission);
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    // Create access depends on the "manage" permission because the full entity
    // is not passed, making it impossible to determine the parent product.
    $result = AccessResult::allowedIfHasPermissions($account, [
      $this->entityType->getAdminPermission(),
      "manage $entity_bundle commerce_product_variation",
    ], 'OR');

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  protected function checkFieldAccess($operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL) {
    // Only product administrators can edit the restricted fields of
    // existing variations. The "manage" permission allows updating every
    // variation of a type, which must not allow moving a variation to another
    // product (e.g. through JSON:API or REST).
    // Modules add their own fields to the "restricted_fields" entity
    // type property, in hook_entity_type_build().
    $restricted_fields = $this->entityType->get('restricted_fields') ?? [];
    if ($operation == 'edit' && in_array($field_definition->getName(), $restricted_fields, TRUE)) {
      $result = AccessResult::allowedIfHasPermission($account, $this->entityType->getAdminPermission());
      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface|null $variation */
      $variation = $items?->getEntity();
      if ($result->isAllowed() || !$variation?->isNew()) {
        return $result;
      }
      // Users who can create variations can set these fields on new variations
      // (e.g. through JSON:API or REST), as on the variation add form, which
      // allows adding variations to any product.
      return $result->orIf(AccessResult::allowedIfHasPermission($account, "manage {$variation->bundle()} commerce_product_variation"));
    }

    return parent::checkFieldAccess($operation, $field_definition, $account, $items);
  }

}
