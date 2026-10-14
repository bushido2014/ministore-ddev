<?php

namespace Drupal\Tests\commerce_promotion\Kernel;

use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use Drupal\views\Views;

/**
 * Tests the promotion and coupon listings.
 *
 * @group commerce
 */
class PromotionViewsTest extends OrderKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_promotion',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('commerce_promotion');
    $this->installEntitySchema('commerce_promotion_coupon');
    $this->installSchema('commerce_promotion', ['commerce_promotion_usage']);
    $this->installConfig(['filter', 'commerce_promotion']);
    $this->setCurrentUser($this->createUser([
      'access commerce_promotion overview',
      'administer commerce_promotion',
    ]));
  }

  /**
   * Tests that the empty listings show their empty text.
   *
   * The kernel test site has no basic_html text format, like any site not
   * built on the Standard profile.
   */
  public function testEmptyText(): void {
    $this->assertStringContainsString('There are no promotions yet.', $this->renderView('commerce_promotions', 'admin'));
    $this->assertStringContainsString('There are no coupons yet.', $this->renderView('commerce_promotion_coupons', 'page_1', ['1']));
  }

  /**
   * Renders a view display.
   *
   * @param string $view_id
   *   The view ID.
   * @param string $display_id
   *   The display ID.
   * @param array $args
   *   The view arguments.
   *
   * @return string
   *   The rendered markup.
   */
  protected function renderView(string $view_id, string $display_id, array $args = []): string {
    $output = Views::getView($view_id)->preview($display_id, $args);
    return (string) $this->container->get('renderer')->renderInIsolation($output);
  }

}
