<?php

namespace Drupal\commerce_payment;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsUpdatingStoredPaymentMethodsInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the access control handler for payment methods.
 */
class PaymentMethodAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  /**
   * The payment method type manager.
   *
   * @var \Drupal\commerce_payment\PaymentMethodTypeManager
   */
  protected $paymentMethodTypeManager;

  /**
   * The names of the fields defined by the payment method types.
   *
   * @var string[]|null
   */
  protected $paymentMethodTypeFields;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = new static($entity_type);
    $instance->paymentMethodTypeManager = $container->get('plugin.manager.commerce_payment_method_type');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $entity */
    if ($operation == 'update') {
      $payment_gateway = $entity->getPaymentGateway();
      // Deny access if the gateway is missing or doesn't support updates.
      if (!$payment_gateway) {
        return AccessResult::forbidden()->addCacheableDependency($entity);
      }
      if (!($payment_gateway->getPlugin() instanceof SupportsUpdatingStoredPaymentMethodsInterface)) {
        return AccessResult::forbidden()->addCacheableDependency($entity);
      }
    }

    $any_result = AccessResult::allowedIfHasPermissions($account, [
      "$operation any commerce_payment_method",
      $this->entityType->getAdminPermission(),
    ], 'OR');

    if ($any_result->isAllowed()) {
      return $any_result;
    }

    if ($account->id() == $entity->getOwnerId()) {
      $own_result = AccessResult::allowedIfHasPermission($account, 'manage own commerce_payment_method')
        ->addCacheableDependency($entity);
    }
    else {
      $own_result = AccessResult::neutral()->cachePerPermissions();
    }

    return $own_result->cachePerUser();
  }

  /**
   * {@inheritdoc}
   */
  public function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermissions($account, [
      $this->entityType->getAdminPermission(),
      'manage own commerce_payment_method',
    ], 'OR');
  }

  /**
   * {@inheritdoc}
   */
  protected function checkFieldAccess($operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL) {
    // Only users who can update any payment method can edit restricted
    // fields. Customers can manage their own payment methods, which must not
    // allow changing the payment gateway, the remote ID, etc. (e.g. through
    // JSON:API or REST). The payment gateways set these fields in code.
    // Modules add their own fields to the "restricted_fields" entity
    // type property, in hook_entity_type_build(). The fields defined by the
    // payment method types (e.g. the card details) are restricted too, so
    // that the payment gateway modules don't need to list them. Modules can
    // still allow editing a field through hook_entity_field_access().
    $restricted_fields = $this->entityType->get('restricted_fields') ?? [];
    $restricted_fields = array_merge($restricted_fields, $this->getPaymentMethodTypeFields());
    if ($operation == 'edit' && in_array($field_definition->getName(), $restricted_fields, TRUE)) {
      return AccessResult::allowedIfHasPermissions($account, [
        $this->entityType->getAdminPermission(),
        'update any commerce_payment_method',
      ], 'OR');
    }
    // The remote ID is internal: the payment gateway uses it, and the
    // merchant uses it with the payment provider. Customers must not see it,
    // not even on their own payment methods.
    if ($operation == 'view' && $field_definition->getName() === 'remote_id') {
      return AccessResult::allowedIfHasPermissions($account, [
        $this->entityType->getAdminPermission(),
        'view any commerce_payment_method',
        'update any commerce_payment_method',
      ], 'OR');
    }

    return parent::checkFieldAccess($operation, $field_definition, $account, $items);
  }

  /**
   * Gets the names of the fields defined by the payment method types.
   *
   * @return string[]
   *   The field names.
   */
  protected function getPaymentMethodTypeFields(): array {
    if (!isset($this->paymentMethodTypeFields)) {
      $this->paymentMethodTypeFields = [];
      foreach (array_keys($this->paymentMethodTypeManager->getDefinitions()) as $plugin_id) {
        /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentMethodType\PaymentMethodTypeInterface $payment_method_type */
        $payment_method_type = $this->paymentMethodTypeManager->createInstance($plugin_id);
        $field_names = array_keys($payment_method_type->buildFieldDefinitions());
        $this->paymentMethodTypeFields = array_merge($this->paymentMethodTypeFields, $field_names);
      }
      $this->paymentMethodTypeFields = array_values(array_unique($this->paymentMethodTypeFields));
    }
    return $this->paymentMethodTypeFields;
  }

}
