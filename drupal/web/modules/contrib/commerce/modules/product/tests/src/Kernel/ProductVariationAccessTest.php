<?php

namespace Drupal\Tests\commerce_product\Kernel;

use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;

/**
 * Tests the product variation access control.
 *
 * @coversDefaultClass \Drupal\commerce_product\ProductVariationAccessControlHandler
 * @group commerce
 */
class ProductVariationAccessTest extends CommerceKernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'path',
    'commerce_product',
    'commerce_product_test',
  ];

  /**
   * A sample user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('commerce_product');
    $this->installConfig(['commerce_product']);

    // Create uid: 1 here so that it's skipped in test cases.
    $admin_user = $this->createUser();
    $regular_user = $this->createUser([], NULL, FALSE, ['uid' => 2]);
    \Drupal::currentUser()->setAccount($regular_user);
  }

  /**
   * @covers ::checkAccess
   */
  public function testAccess() {
    /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation */
    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => $this->randomMachineName(),
      'title' => $this->randomString(),
      'status' => 1,
    ]);
    $variation->save();
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = Product::create([
      'type' => 'default',
      'title' => 'My Product Title',
      'variations' => [$variation],
    ]);
    $product->save();
    $variation = $this->reloadEntity($variation);

    $account = $this->createUser(['access administration pages']);
    $this->assertFalse($variation->access('view', $account));
    $this->assertFalse($variation->access('update', $account));
    $this->assertFalse($variation->access('delete', $account));

    $account = $this->createUser(['view commerce_product']);
    $this->assertTrue($variation->access('view', $account));
    $this->assertFalse($variation->access('update', $account));
    $this->assertFalse($variation->access('delete', $account));

    $account = $this->createUser(['update any default commerce_product']);
    $this->assertFalse($variation->access('view', $account));
    $this->assertFalse($variation->access('update', $account));
    $this->assertFalse($variation->access('delete', $account));

    $account = $this->createUser([
      'manage default commerce_product_variation',
    ]);
    $this->assertFalse($variation->access('view', $account));
    $this->assertTrue($variation->access('update', $account));
    $this->assertTrue($variation->access('delete', $account));

    $account = $this->createUser(['administer commerce_product']);
    $this->assertTrue($variation->access('view', $account));
    $this->assertTrue($variation->access('update', $account));
    $this->assertTrue($variation->access('delete', $account));

    // Unpublished variation.
    $variation->setUnpublished();

    $account = $this->createUser(['view commerce_product']);
    $this->assertFalse($variation->access('view', $account));
    $this->assertFalse($variation->access('update', $account));
    $this->assertFalse($variation->access('delete', $account));

    $account = $this->createUser([
      'view commerce_product',
      'manage default commerce_product_variation',
    ]);
    $this->assertTrue($variation->access('view', $account));
    $this->assertTrue($variation->access('update', $account));
    $this->assertTrue($variation->access('delete', $account));

    $account = $this->createUser(['administer commerce_product']);
    $this->assertTrue($variation->access('view', $account));
    $this->assertTrue($variation->access('update', $account));
    $this->assertTrue($variation->access('delete', $account));

    $variation->setPublished();

    // Broken product reference.
    $variation->set('product_id', '999');
    $account = $this->createUser([
      'manage default commerce_product_variation',
    ]);
    $this->assertFalse($variation->access('view', $account));
    $this->assertFalse($variation->access('update', $account));
    $this->assertFalse($variation->access('delete', $account));
  }

  /**
   * @covers ::checkCreateAccess
   */
  public function testCreateAccess() {
    $access_control_handler = \Drupal::entityTypeManager()->getAccessControlHandler('commerce_product_variation');

    $account = $this->createUser(['access content']);
    $this->assertFalse($access_control_handler->createAccess('test', $account));

    $account = $this->createUser(['administer commerce_product']);
    $this->assertTrue($access_control_handler->createAccess('default', $account));

    $account = $this->createUser(['manage default commerce_product_variation']);
    $this->assertTrue($access_control_handler->createAccess('default', $account));
  }

  /**
   * Tests that variations without access are not available on the frontend.
   */
  public function testFrontendFiltering() {
    /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation */
    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => $this->randomMachineName(),
      'title' => $this->randomString(),
      'status' => 1,
    ]);
    $variation->save();
    /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation_denied */
    $variation_denied = ProductVariation::create([
      'type' => 'default',
      'sku' => 'DENY_' . $this->randomMachineName(),
      'title' => $this->randomString(),
      'status' => 1,
    ]);
    $variation_denied->save();
    /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation_unpublished */
    $variation_unpublished = ProductVariation::create([
      'type' => 'default',
      'sku' => $this->randomMachineName(),
      'title' => $this->randomString(),
      'status' => 0,
    ]);
    $variation_unpublished->save();
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = Product::create([
      'type' => 'default',
      'title' => 'My Product Title',
      'variations' => [$variation, $variation_denied, $variation_unpublished],
    ]);
    $product->save();
    $product = $this->reloadEntity($product);

    $user = $this->createUser(['view commerce_product']);
    $this->container->get('current_user')->setAccount($user);

    /** @var \Drupal\commerce_product\ProductVariationStorageInterface $variation_storage */
    $variation_storage = $this->container->get('entity_type.manager')->getStorage('commerce_product_variation');
    $this->container->get('request_stack')->getCurrentRequest()->query->set('v', $variation_denied->id());
    $context = $variation_storage->loadFromContext($product);
    $this->assertNotEquals($variation_denied->id(), $context->id());
    $this->assertEquals($variation->id(), $context->id());

    $this->container->get('request_stack')->getCurrentRequest()->query->set('v', $variation_unpublished->id());
    $context = $variation_storage->loadFromContext($product);
    $this->assertNotEquals($variation_unpublished->id(), $context->id());
    $this->assertEquals($variation->id(), $context->id());

    $enabled = $variation_storage->loadEnabled($product);
    $this->assertEquals(1, count($enabled));
  }

  /**
   * Tests route access for variations.
   */
  public function testRouteAccess() {
    /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation */
    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => $this->randomMachineName(),
      'title' => $this->randomString(),
      'status' => 1,
    ]);
    $variation->save();
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = Product::create([
      'type' => 'default',
      'title' => 'My Product Title',
      'variations' => [$variation],
    ]);
    $product->save();
    $variation = $this->reloadEntity($variation);

    $account = $this->createUser(['administer commerce_product']);
    $this->assertTrue($variation->toUrl('collection')->access($account));
    $this->assertTrue($variation->toUrl('add-form')->access($account));
    $this->assertTrue($variation->toUrl('edit-form')->access($account));
    $this->assertTrue($variation->toUrl('delete-form')->access($account));

    $account = $this->createUser(['manage default commerce_product_variation']);
    $this->assertTrue($variation->toUrl('collection')->access($account));
    $this->assertTrue($variation->toUrl('add-form')->access($account));
    $this->assertTrue($variation->toUrl('edit-form')->access($account));
    $this->assertTrue($variation->toUrl('delete-form')->access($account));

    $account = $this->createUser(['access commerce_product overview']);
    $this->assertTrue($variation->toUrl('collection')->access($account));
    $this->assertFalse($variation->toUrl('add-form')->access($account));
    $this->assertFalse($variation->toUrl('edit-form')->access($account));
    $this->assertFalse($variation->toUrl('delete-form')->access($account));
  }

  /**
   * @covers ::checkFieldAccess
   */
  public function testFieldAccess() {
    $owner = $this->createUser();
    $product = Product::create([
      'type' => 'default',
      'title' => 'Own product',
      'uid' => $owner->id(),
    ]);
    $product->save();
    $other_product = Product::create([
      'type' => 'default',
      'title' => 'Other product',
    ]);
    $other_product->save();
    /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation */
    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => $this->randomMachineName(),
      'product_id' => $product->id(),
    ]);
    $variation->save();

    $variation_manager = $this->createUser([
      'manage default commerce_product_variation',
      'update own default commerce_product',
    ]);
    $product->setOwnerId($variation_manager->id())->save();
    // Variation managers can update every variation of the type, but can't
    // move a variation to another product.
    $this->assertTrue($variation->access('update', $variation_manager));
    $this->assertTrue($variation->get('sku')->access('edit', $variation_manager));
    $this->assertFalse($variation->get('product_id')->access('edit', $variation_manager));
    $this->assertTrue($variation->get('product_id')->access('view', $variation_manager));

    // New variations can be added to any product, as on the variation add
    // form.
    $new_variation = ProductVariation::create([
      'type' => 'default',
      'sku' => $this->randomMachineName(),
      'product_id' => $other_product->id(),
    ]);
    $this->assertTrue($new_variation->get('product_id')->access('edit', $variation_manager));
    $this->assertFalse($new_variation->get('product_id')->access('edit', $this->createUser()));

    $admin = $this->createUser(['administer commerce_product']);
    $this->assertTrue($variation->get('product_id')->access('edit', $admin));
    $this->assertTrue($new_variation->get('product_id')->access('edit', $admin));
  }

  /**
   * Tests that entity queries only return the variations the user can view.
   *
   * @covers \Drupal\commerce_product\Hook\CommerceProductHooks::queryCommerceProductVariationAccessAlter
   */
  public function testQueryAccess() {
    $variations = [];
    foreach (['published', 'unpublished_product', 'unpublished'] as $key) {
      $variations[$key] = ProductVariation::create([
        'type' => 'default',
        'sku' => $key,
        'title' => $key,
        'status' => $key !== 'unpublished',
      ]);
      $variations[$key]->save();
    }
    Product::create([
      'type' => 'default',
      'title' => 'Published',
      'variations' => [$variations['published'], $variations['unpublished']],
    ])->save();
    Product::create([
      'type' => 'default',
      'title' => 'Unpublished',
      'status' => 0,
      'variations' => [$variations['unpublished_product']],
    ])->save();
    // A variation without a product.
    ProductVariation::create([
      'type' => 'default',
      'sku' => 'orphan',
      'title' => 'orphan',
      'status' => 1,
    ])->save();

    $variation_storage = $this->container->get('entity_type.manager')->getStorage('commerce_product_variation');
    $query_skus = function (array $permissions) use ($variation_storage) {
      $this->container->get('current_user')->setAccount($this->createUser($permissions));
      $query = $variation_storage->getQuery()->accessCheck(TRUE)->sort('variation_id');
      $count = (clone $query)->count()->execute();
      $skus = array_map(function ($variation) {
        return $variation->getSku();
      }, $variation_storage->loadMultiple($query->execute()));
      $this->assertCount($count, $skus);
      return array_values($skus);
    };

    $this->assertEquals([], $query_skus([]));
    $this->assertEquals(['published'], $query_skus(['view commerce_product']));
    $this->assertEquals(['unpublished'], $query_skus(['manage default commerce_product_variation']));
    $this->assertEquals(['published', 'unpublished'], $query_skus([
      'view commerce_product',
      'manage default commerce_product_variation',
    ]));
    $this->assertEquals(['published', 'unpublished_product', 'unpublished', 'orphan'], $query_skus(['administer commerce_product']));
    // Queries without access checks are unchanged.
    $this->assertCount(4, $variation_storage->getQuery()->accessCheck(FALSE)->execute());

    // Validating references isn't restricted, only listing them is.
    $this->container->get('current_user')->setAccount($this->createUser());
    $handler = $this->container->get('plugin.manager.entity_reference_selection')->getInstance([
      'target_type' => 'commerce_product_variation',
      'handler' => 'default',
    ]);
    $this->assertEquals([], $handler->getReferenceableEntities());
    $variation_ids = array_keys($variation_storage->loadMultiple());
    $this->assertEquals($variation_ids, array_values($handler->validateReferenceableEntities($variation_ids)));
  }

}
