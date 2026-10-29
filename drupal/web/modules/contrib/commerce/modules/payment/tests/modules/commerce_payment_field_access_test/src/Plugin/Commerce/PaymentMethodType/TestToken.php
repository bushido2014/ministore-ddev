<?php

namespace Drupal\commerce_payment_field_access_test\Plugin\Commerce\PaymentMethodType;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_payment\Attribute\CommercePaymentMethodType;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentMethodType\PaymentMethodTypeBase;
use Drupal\entity\BundleFieldDefinition;

/**
 * Provides a payment method type with its own fields, as gateway modules do.
 */
#[CommercePaymentMethodType(
  id: "test_token",
  label: new TranslatableMarkup('Test token'),
)]
class TestToken extends PaymentMethodTypeBase {

  /**
   * {@inheritdoc}
   */
  public function buildLabel(PaymentMethodInterface $payment_method) {
    return $this->t('Test token');
  }

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions() {
    $fields = parent::buildFieldDefinitions();

    $fields['test_token'] = BundleFieldDefinition::create('string')
      ->setLabel(t('Test token'));
    $fields['test_nickname'] = BundleFieldDefinition::create('string')
      ->setLabel(t('Test nickname'));

    return $fields;
  }

}
