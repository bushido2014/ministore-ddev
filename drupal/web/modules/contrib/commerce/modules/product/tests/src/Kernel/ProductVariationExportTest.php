<?php

namespace Drupal\Tests\commerce_product\Kernel;

use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\DefaultContent\Exporter;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;

class ProductVariationExportTest extends CommerceKernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'commerce_product',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('commerce_product');
  }

  /**
   * Tests that the exported variation does not reference its product.
   */
  public function testVariationExportOmitsProduct(): void {
    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => strtolower($this->randomMachineName()),
      'title' => $this->randomString(),
      'status' => 0,
    ]);
    $variation->save();

    $product = Product::create([
      'type' => 'default',
      'variations' => [$variation],
    ]);
    $product->save();

    // Confirm the backreference exists, so the assertions below are not
    // passing just because there was nothing to export.
    $variation = $this->reloadEntity($variation);
    $this->assertEquals($product->id(), $variation->getProductId());

    $data = (string) \Drupal::service(Exporter::class)->export($variation);
    $data = Yaml::decode($data);

    $this->assertSame($variation->uuid(), $data['_meta']['uuid']);
    $this->assertArrayHasKey('sku', $data['default']);
    $this->assertArrayNotHasKey('product_id', $data['default']);
    $this->assertArrayNotHasKey($product->uuid(), $data['_meta']['depends'] ?? []);
    $this->assertNotContains('commerce_product', $data['_meta']['depends'] ?? []);

    // The product side of the relationship must still be exported.
    $data = (string) \Drupal::service(Exporter::class)->export($product);
    $data = Yaml::decode($data);

    $this->assertSame($product->uuid(), $data['_meta']['uuid']);
    $this->assertSame([['entity' => $variation->uuid()]], $data['default']['variations']);
    $this->assertSame([$variation->uuid() => 'commerce_product_variation'], $data['_meta']['depends']);
  }

}
