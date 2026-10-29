<?php

namespace Drupal\Tests\commerce_payment\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;

/**
 * Tests the access to the remote IDs of the customer at the payment gateways.
 *
 * @covers \Drupal\commerce_payment\Hook\CommercePaymentHooks::entityFieldAccess
 * @group commerce
 */
class UserRemoteIdAccessTest extends OrderKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_payment',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['commerce_payment']);
    // Create uid 1 here, it bypasses access checks.
    $this->createUser();
  }

  /**
   * Tests that only user administrators can view and edit the remote IDs.
   */
  public function testFieldAccess(): void {
    $customer = $this->createUser();
    $customer->get('commerce_remote_id')->setByProvider('example|test', 'cus_123');
    $customer->save();
    $remote_id = $customer->get('commerce_remote_id');

    // The customer can edit their own account, but not their remote IDs.
    $this->assertTrue($customer->access('update', $customer));
    $this->assertFalse($remote_id->access('view', $customer));
    $this->assertFalse($remote_id->access('edit', $customer));

    // Users who can view other users' profiles can't see the remote IDs.
    $profile_viewer = $this->createUser(['access user profiles']);
    $this->assertTrue($customer->access('view', $profile_viewer));
    $this->assertFalse($remote_id->access('view', $profile_viewer));
    $this->assertFalse($remote_id->access('edit', $profile_viewer));

    $this->assertFalse($remote_id->access('view', new AnonymousUserSession()));
    $this->assertFalse($remote_id->access('edit', new AnonymousUserSession()));

    $admin = $this->createUser(['administer users']);
    $this->assertTrue($remote_id->access('view', $admin));
    $this->assertTrue($remote_id->access('edit', $admin));

    // Other fields of the account are unchanged.
    $this->assertTrue($customer->get('mail')->access('view', $customer));
    $this->assertTrue($customer->get('mail')->access('edit', $customer));
  }

}
