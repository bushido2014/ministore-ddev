<?php

namespace Drupal\commerce_product\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ProductVariations constraint.
 */
class ProductVariationsConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($items, Constraint $constraint) {
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $items */
    $product_id = $items->getEntity()->id();
    foreach ($items as $delta => $item) {
      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation */
      $variation = $item->entity;
      // Variations without a product get their back-reference when the
      // product is saved (e.g. new variations).
      if (!$variation || !$variation->getProductId()) {
        continue;
      }
      if ($variation->getProductId() != $product_id) {
        $this->context->buildViolation($constraint->message)
          ->setParameter('%label', $variation->label())
          ->atPath((string) $delta . '.target_id')
          ->setInvalidValue($variation->id())
          ->addViolation();
      }
    }
  }

}
