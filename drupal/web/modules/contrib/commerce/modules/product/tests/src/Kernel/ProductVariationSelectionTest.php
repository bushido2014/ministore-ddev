<?php

namespace Drupal\Tests\commerce_product\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductType;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\user\RoleInterface;

/**
 * Tests that the variation selection only lists viewable variations.
 *
 * @coversDefaultClass \Drupal\commerce_product\Plugin\EntityReferenceSelection\ProductVariationSelection
 * @group commerce
 */
class ProductVariationSelectionTest extends CommerceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'path',
    'commerce_product',
  ];

  /**
   * The test variations, keyed by SKU.
   *
   * @var \Drupal\commerce_product\Entity\ProductVariationInterface[]
   */
  protected array $variations = [];

  /**
   * The owner of the unpublished product.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $owner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('commerce_product');
    // The anonymous and authenticated roles.
    $this->installConfig(['user', 'commerce_product']);
    // Create uid: 1 here so that it's skipped in test cases.
    $this->createUser();

    ProductVariationType::create([
      'id' => 'other',
      'label' => 'Other',
      'orderItemType' => 'default',
    ])->save();
    ProductType::create([
      'id' => 'other',
      'label' => 'Other',
      'variationTypes' => ['other'],
    ])->save();

    $this->owner = $this->createUser();
    $this->createVariation('TEST-PUBLISHED', 'default', TRUE, TRUE);
    $this->createVariation('TEST-UNPUBLISHED-VARIATION', 'default', FALSE, TRUE);
    $this->createVariation('TEST-UNPUBLISHED-PRODUCT', 'default', TRUE, FALSE);
    $this->createVariation('TEST-OTHER-UNPUBLISHED', 'other', FALSE, TRUE);
  }

  /**
   * @covers ::getReferenceableEntities
   */
  public function testReferenceableEntities(): void {
    $users = [
      'administrator' => [
        $this->createUser(['administer commerce_product']),
        ['TEST-PUBLISHED', 'TEST-UNPUBLISHED-VARIATION', 'TEST-UNPUBLISHED-PRODUCT', 'TEST-OTHER-UNPUBLISHED'],
      ],
      // Manages one variation type, but not the other.
      'variation manager' => [
        $this->createUser(['view commerce_product', 'manage default commerce_product_variation']),
        ['TEST-PUBLISHED', 'TEST-UNPUBLISHED-VARIATION'],
      ],
      'product viewer' => [
        $this->createUser(['view commerce_product']),
        ['TEST-PUBLISHED'],
      ],
      'unpublished product owner' => [
        $this->owner,
        ['TEST-PUBLISHED', 'TEST-UNPUBLISHED-PRODUCT'],
      ],
      'no permissions' => [
        $this->createUser(),
        [],
      ],
    ];
    $this->owner->addRole($this->createRole(['view commerce_product', 'view own unpublished commerce_product']));
    $this->owner->save();

    foreach ($users as $label => [$account, $expected]) {
      $this->assertReferenceableVariations($expected, $account, $label);
    }
  }

  /**
   * Tests anonymous users, e.g. requesting a leaked autocomplete URL.
   *
   * @covers ::getReferenceableEntities
   */
  public function testAnonymous(): void {
    $this->assertReferenceableVariations([], new AnonymousUserSession(), 'anonymous');
  }

  /**
   * Tests anonymous users who can view products, as on most storefronts.
   *
   * @covers ::getReferenceableEntities
   */
  public function testAnonymousWithViewPermission(): void {
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['view commerce_product']);
    $this->assertReferenceableVariations(['TEST-PUBLISHED'], new AnonymousUserSession(), 'anonymous with "view commerce_product"');
  }

  /**
   * Tests that validating references isn't restricted.
   *
   * @covers ::validateReferenceableEntities
   */
  public function testValidateReferenceableEntities(): void {
    $this->setCurrentUser(new AnonymousUserSession());
    $handler = $this->container->get('plugin.manager.entity_reference_selection')->getInstance([
      'target_type' => 'commerce_product_variation',
      'handler' => 'default',
    ]);
    $variation_ids = array_map(function ($variation) {
      return $variation->id();
    }, array_values($this->variations));
    $this->assertEquals($variation_ids, array_values($handler->validateReferenceableEntities($variation_ids)));
  }

  /**
   * Asserts the variations listed for the given account.
   *
   * Also asserts that they match the variations the account can view.
   *
   * @param string[] $expected_skus
   *   The expected SKUs.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param string $label
   *   The label of the test case.
   */
  protected function assertReferenceableVariations(array $expected_skus, AccountInterface $account, string $label): void {
    $this->setCurrentUser($account);
    $this->container->get('entity_type.manager')->getAccessControlHandler('commerce_product_variation')->resetCache();
    $this->container->get('entity_type.manager')->getAccessControlHandler('commerce_product')->resetCache();
    $handler = $this->container->get('plugin.manager.entity_reference_selection')->getInstance([
      'target_type' => 'commerce_product_variation',
      'handler' => 'default',
    ]);
    $skus = [];
    foreach ($handler->getReferenceableEntities('TEST') as $options) {
      foreach (array_keys($options) as $variation_id) {
        $skus[] = ProductVariation::load($variation_id)->getSku();
      }
    }
    sort($skus);
    sort($expected_skus);
    $this->assertSame($expected_skus, $skus, $label);

    $viewable_skus = array_keys(array_filter($this->variations, function ($variation) use ($account) {
      return $variation->access('view', $account);
    }));
    sort($viewable_skus);
    $this->assertSame($viewable_skus, $skus, "$label: listed variations match the viewable ones");
  }

  /**
   * Creates a variation and its product.
   *
   * @param string $sku
   *   The SKU.
   * @param string $type
   *   The variation and product type.
   * @param bool $published
   *   Whether the variation is published.
   * @param bool $product_published
   *   Whether the product is published.
   */
  protected function createVariation(string $sku, string $type, bool $published, bool $product_published): void {
    $variation = ProductVariation::create([
      'type' => $type,
      'sku' => $sku,
      'title' => $sku,
      'status' => $published,
      'price' => ['number' => '10', 'currency_code' => 'USD'],
    ]);
    $variation->save();
    Product::create([
      'type' => $type,
      'title' => $sku,
      'status' => $product_published,
      'uid' => $this->owner->id(),
      'stores' => [$this->store->id()],
      'variations' => [$variation],
    ])->save();
    $this->variations[$sku] = $this->reloadEntity($variation);
  }

}
