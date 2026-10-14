<?php

namespace Drupal\commerce_product\Plugin\Field;

use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;
use Drupal\commerce_product\Entity\ProductInterface;

/**
 * Computed field item list for the default_variation field.
 */
class ComputedDefaultVariation extends EntityReferenceFieldItemList {
  use ComputedItemListTrait;

  /**
   * {@inheritdoc}
   */
  protected function computeValue() {
    $product = $this->getEntity();
    assert($product instanceof ProductInterface);
    $this->list[0] = $this->createItem(0, $product->getDefaultVariation());
  }

  /**
   * {@inheritdoc}
   */
  public function preSave() {
    // This field is computed from the "variations" field, which is responsible
    // for saving new variations. The referenced variation must never be saved
    // from here: it can be a stale copy of a variation that has already been
    // saved through another object (e.g. after an Inline Entity Form cache
    // round-trip), which would result in a duplicate insert.
  }

}
