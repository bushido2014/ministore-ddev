<?php

namespace Drupal\commerce_product\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Ensures that a product only references its own variations.
 */
#[Constraint(
  id: "ProductVariations",
  label: new TranslatableMarkup("The variations of the product.", [], ["context" => "Validation"]),
)]
class ProductVariationsConstraint extends SymfonyConstraint {

  public $message = 'The variation %label belongs to another product.';

}
