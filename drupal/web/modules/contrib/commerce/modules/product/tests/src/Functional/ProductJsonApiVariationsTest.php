<?php

namespace Drupal\Tests\commerce_product\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\user\UserInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests that products can't take over other products' variations via JSON:API.
 *
 * @group commerce
 */
class ProductJsonApiVariationsTest extends CommerceBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_product',
    'jsonapi',
    'basic_auth',
  ];

  /**
   * The variation of another user's product.
   *
   * @var \Drupal\commerce_product\Entity\ProductVariationInterface
   */
  protected $otherVariation;

  /**
   * The other user's product.
   *
   * @var \Drupal\commerce_product\Entity\ProductInterface
   */
  protected $otherProduct;

  /**
   * A product editor limited to their own products.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $editor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config('jsonapi.settings')->set('read_only', FALSE)->save(TRUE);

    $this->otherVariation = $this->createVariation();
    $this->otherProduct = Product::create([
      'type' => 'default',
      'title' => 'Other product',
      'uid' => $this->adminUser->id(),
      'stores' => [$this->store],
      'variations' => [$this->otherVariation],
    ]);
    $this->otherProduct->save();
    $this->editor = $this->drupalCreateUser([
      'view commerce_store',
      'view commerce_product',
      'create default commerce_product',
      'update own default commerce_product',
      'delete own default commerce_product',
    ]);
  }

  /**
   * Tests creating a product that lists another product's variation.
   */
  public function testCreate(): void {
    $response = $this->request('POST', '/jsonapi/commerce_product/default', [
      'data' => [
        'type' => 'commerce_product--default',
        'attributes' => ['title' => 'Own product'],
        'relationships' => [
          'stores' => [
            'data' => [
              [
                'type' => 'commerce_store--' . $this->store->bundle(),
                'id' => $this->store->uuid(),
              ],
            ],
          ],
          'variations' => [
            'data' => [$this->variationIdentifier($this->otherVariation)],
          ],
        ],
      ],
    ]);
    $this->assertSame(422, $response->getStatusCode());
    $this->assertStringContainsString('belongs to another product', (string) $response->getBody());
    $this->assertCount(1, $this->container->get('entity_type.manager')->getStorage('commerce_product')->getQuery()->accessCheck(FALSE)->execute());
  }

  /**
   * Tests listing another product's variation on an existing product.
   */
  public function testUpdate(): void {
    $own_variation = $this->createVariation();
    $own_product = Product::create([
      'type' => 'default',
      'title' => 'Own product',
      'uid' => $this->editor->id(),
      'stores' => [$this->store],
      'variations' => [$own_variation],
    ]);
    $own_product->save();

    $response = $this->request('PATCH', '/jsonapi/commerce_product/default/' . $own_product->uuid(), [
      'data' => [
        'type' => 'commerce_product--default',
        'id' => $own_product->uuid(),
        'relationships' => [
          'variations' => [
            'data' => [
              $this->variationIdentifier($own_variation),
              $this->variationIdentifier($this->otherVariation),
            ],
          ],
        ],
      ],
    ]);
    $this->assertSame(422, $response->getStatusCode());
    $this->assertStringContainsString('belongs to another product', (string) $response->getBody());
    $this->assertFalse($this->reloadEntity($own_product)->hasVariation($this->otherVariation));

    // Deleting the product doesn't delete the other product's variation.
    $response = $this->request('DELETE', '/jsonapi/commerce_product/default/' . $own_product->uuid());
    $this->assertSame(204, $response->getStatusCode());
    $this->assertNull($this->reloadEntity($own_variation));
    $this->assertNotNull($this->reloadEntity($this->otherVariation));
    $this->assertTrue($this->reloadEntity($this->otherProduct)->hasVariation($this->otherVariation));
  }

  /**
   * Tests moving a variation to another product.
   */
  public function testVariationProduct(): void {
    $own_product = Product::create([
      'type' => 'default',
      'title' => 'Own product',
      'stores' => [$this->store],
    ]);
    $own_product->save();
    $variation_manager = $this->drupalCreateUser([
      'view commerce_product',
      'manage default commerce_product_variation',
    ]);

    $response = $this->request('PATCH', '/jsonapi/commerce_product_variation/default/' . $this->otherVariation->uuid(), [
      'data' => [
        'type' => 'commerce_product_variation--default',
        'id' => $this->otherVariation->uuid(),
        'relationships' => [
          'product_id' => [
            'data' => [
              'type' => 'commerce_product--default',
              'id' => $own_product->uuid(),
            ],
          ],
        ],
      ],
    ], $variation_manager);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertEquals($this->otherProduct->id(), $this->reloadEntity($this->otherVariation)->getProductId());
  }

  /**
   * Creates a variation.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface
   *   The variation.
   */
  protected function createVariation(): ProductVariationInterface {
    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => $this->randomMachineName(),
      'price' => ['number' => '10.00', 'currency_code' => 'USD'],
      'status' => TRUE,
    ]);
    $variation->save();
    return $variation;
  }

  /**
   * Gets the JSON:API resource identifier of a variation.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The variation.
   *
   * @return array
   *   The resource identifier.
   */
  protected function variationIdentifier(ProductVariationInterface $variation): array {
    return [
      'type' => 'commerce_product_variation--default',
      'id' => $variation->uuid(),
    ];
  }

  /**
   * Makes a JSON:API request.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   The path.
   * @param array $document
   *   The request document, if any.
   * @param \Drupal\user\UserInterface|null $account
   *   The account making the request. Defaults to the product editor.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
   */
  protected function request(string $method, string $path, array $document = [], ?UserInterface $account = NULL): ResponseInterface {
    $account = $account ?: $this->editor;
    $options = [
      'auth' => [$account->getAccountName(), $account->passRaw],
      'headers' => [
        'Accept' => 'application/vnd.api+json',
        'Content-Type' => 'application/vnd.api+json',
      ],
      'http_errors' => FALSE,
    ];
    if ($document) {
      $options['body'] = Json::encode($document);
    }
    return $this->getHttpClient()->request($method, $this->buildUrl($path), $options);
  }

}
