<?php

namespace Drupal\Tests\commerce_payment\Kernel;

use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentMethod;

/**
 * Tests the access to the fields defined by the payment method types.
 *
 * @coversDefaultClass \Drupal\commerce_payment\PaymentMethodAccessControlHandler
 * @group commerce
 */
class PaymentMethodTypeFieldAccessTest extends OrderKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_payment',
    'commerce_payment_example',
    'commerce_payment_field_access_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('commerce_payment_method');
    $this->installConfig(['commerce_payment']);
    // Create uid 1 here, it bypasses access checks.
    $this->createUser();

    PaymentGateway::create([
      'id' => 'onsite',
      'label' => 'On-site',
      'plugin' => 'example_onsite',
    ])->save();
  }

  /**
   * Tests that only staff can edit the fields of the payment method types.
   *
   * @covers ::checkFieldAccess
   */
  public function testFieldAccess(): void {
    $customer = $this->createUser(['manage own commerce_payment_method']);
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $payment_method = PaymentMethod::create([
      'type' => 'test_token',
      'payment_gateway' => 'onsite',
      'uid' => $customer->id(),
      'test_token' => 'token',
      'test_nickname' => 'My card',
    ]);
    $payment_method->save();
    $credit_card = PaymentMethod::create([
      'type' => 'credit_card',
      'payment_gateway' => 'onsite',
      'uid' => $customer->id(),
    ]);
    $credit_card->save();

    // The payment gateways set the fields of their payment method types,
    // without being listed in the "restricted_fields" entity type property.
    $restricted_fields = $payment_method->getEntityType()->get('restricted_fields');
    $this->assertNotContains('test_token', $restricted_fields);
    $this->assertTrue($payment_method->access('update', $customer));
    $this->assertFalse($payment_method->get('test_token')->access('edit', $customer));
    $this->assertTrue($payment_method->get('test_token')->access('view', $customer));
    // The fields of the core payment method types are restricted too.
    foreach (['card_type', 'card_number', 'card_exp_month', 'card_exp_year'] as $field_name) {
      $this->assertFalse($credit_card->get($field_name)->access('edit', $customer), $field_name);
    }
    // Modules can still allow editing a field of their payment method type.
    $this->assertTrue($payment_method->get('test_nickname')->access('edit', $customer));
    // Base fields that customers manage are unchanged.
    $this->assertTrue($payment_method->get('billing_profile')->access('edit', $customer));
    $this->assertTrue($payment_method->get('is_default')->access('edit', $customer));

    $accounts = [
      $this->createUser(['administer commerce_payment_method']),
      $this->createUser(['update any commerce_payment_method']),
    ];
    foreach ($accounts as $account) {
      $this->assertTrue($payment_method->get('test_token')->access('edit', $account));
    }

    // The fields are also restricted when checked without an entity, e.g.
    // by Views field handlers.
    $access_control_handler = $this->container->get('entity_type.manager')->getAccessControlHandler('commerce_payment_method');
    $field_definition = $payment_method->getFieldDefinition('test_token');
    $this->assertFalse($access_control_handler->fieldAccess('edit', $field_definition, $customer));
    $this->assertTrue($access_control_handler->fieldAccess('edit', $field_definition, $accounts[1]));
  }

}
