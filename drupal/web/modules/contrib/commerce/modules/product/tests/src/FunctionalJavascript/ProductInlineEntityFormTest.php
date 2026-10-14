<?php

namespace Drupal\Tests\commerce_product\FunctionalJavascript;

use Drupal\Tests\commerce\FunctionalJavascript\CommerceWebDriverTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Tests creating products through Inline Entity Form on a parent entity.
 *
 * @group commerce
 */
class ProductInlineEntityFormTest extends CommerceWebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_product',
    'inline_entity_form',
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Page']);
    FieldStorageConfig::create([
      'field_name' => 'field_products',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => [
        'target_type' => 'commerce_product',
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_products',
      'entity_type' => 'node',
      'bundle' => 'page',
      'label' => 'Products',
      'settings' => [
        'handler' => 'default:commerce_product',
        'handler_settings' => [
          'target_bundles' => ['default' => 'default'],
        ],
      ],
    ])->save();

    /** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $display_repository */
    $display_repository = $this->container->get('entity_display.repository');
    $display_repository->getFormDisplay('node', 'page')
      ->setComponent('field_products', [
        'type' => 'inline_entity_form_complex',
        'settings' => [
          'allow_new' => TRUE,
          'allow_existing' => FALSE,
        ],
      ])
      ->save();
    $display_repository->getFormDisplay('commerce_product', 'default')
      ->setComponent('variations', [
        'type' => 'inline_entity_form_complex',
        'settings' => [
          'allow_new' => TRUE,
          'allow_existing' => FALSE,
        ],
      ])
      ->save();

    $this->adminUser = $this->drupalCreateUser(array_merge([
      'administer commerce_product',
      'access content',
      'create page content',
      'edit any page content',
    ], $this->getAdministratorPermissions()));
    $this->drupalLogin($this->adminUser);
  }

  /**
   * Tests saving a new product after re-opening its inline form.
   *
   * The inline product form is re-opened and submitted after the product has
   * been added to the parent form, but before the parent entity is saved.
   */
  public function testReopenNewInlineProduct() {
    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    $this->drupalGet('node/add/page');
    $page->fillField('title[0][value]', 'Parent node');
    $page->pressButton('Add new product');
    $this->assertNotEmpty($assert_session->waitForButton('Create product'));
    $assert_session->elementExists('css', 'input[name^="field_products"][name$="[title][0][value]"]')->setValue('Inline product');

    // The variations field is required, so its inline form is already open.
    $assert_session->buttonExists('Create variation');
    $assert_session->elementExists('css', 'input[name$="[sku][0][value]"]')->setValue('INLINE-SKU');
    $assert_session->elementExists('css', 'input[name$="[price][0][number]"]')->setValue('9.99');
    $page->pressButton('Create variation');
    $assert_session->assertNoElementAfterWait('named', ['button', 'Create variation']);
    $assert_session->elementsCount('css', 'tr.ief-row-entity', 1);

    $page->pressButton('Create product');
    $assert_session->assertNoElementAfterWait('named', ['button', 'Create product']);
    $assert_session->elementsCount('css', 'tr.ief-row-entity', 1);
    $assert_session->pageTextContains('Inline product');

    // Re-open the inline product form and submit it without any change.
    $page->pressButton('Edit');
    $this->assertNotEmpty($assert_session->waitForButton('Update product'));
    $page->pressButton('Update product');
    $assert_session->assertNoElementAfterWait('named', ['button', 'Update product']);
    $assert_session->pageTextContains('Inline product');

    $page->pressButton('Save');
    $assert_session->pageTextContains('Page Parent node has been created.');

    $product_storage = $this->container->get('entity_type.manager')->getStorage('commerce_product');
    $variation_storage = $this->container->get('entity_type.manager')->getStorage('commerce_product_variation');
    $products = $product_storage->loadByProperties(['title' => 'Inline product']);
    $this->assertCount(1, $products);
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = reset($products);
    $variations = $variation_storage->loadByProperties(['sku' => 'INLINE-SKU']);
    $this->assertCount(1, $variations);
    /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation */
    $variation = reset($variations);
    $this->assertEquals([$variation->id()], $product->getVariationIds());
    $this->assertEquals($product->id(), $variation->getProductId());

    $node = $this->drupalGetNodeByTitle('Parent node');
    $this->assertEquals($product->id(), $node->get('field_products')->target_id);
  }

}
