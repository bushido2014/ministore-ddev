<?php

namespace Drupal\Tests\commerce_order\Kernel;

use Drupal\views\Views;

/**
 * Tests the customer's order history view.
 *
 * @group commerce
 */
class UserOrdersViewTest extends OrderKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'views',
  ];

  /**
   * Tests that a customer without orders sees the empty text.
   *
   * The kernel test site has no basic_html text format, like any site not
   * built on the Standard profile.
   */
  public function testEmptyText(): void {
    $this->installConfig(['filter']);
    $customer = $this->createUser(['view own commerce_order']);
    $this->setCurrentUser($customer);

    $view = Views::getView('commerce_user_orders');
    $output = $view->preview('order_page', [$customer->id()]);
    $html = (string) $this->container->get('renderer')->renderInIsolation($output);

    $this->assertStringContainsString('You have not placed any orders with us yet.', $html);
  }

}
