<?php

namespace Drupal\commerce_order\Form;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides the order item duplicate form.
 */
class OrderItemDuplicateForm extends OrderItemEditForm {

  /**
   * {@inheritdoc}
   */
  public function setEntity(EntityInterface $entity): self {
    $entity = $entity->createDuplicate();
    return parent::setEntity($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function saveOrder(OrderInterface $order): void {
    // The duplicated order item is new, so attach it to the order before
    // the order is saved. This keeps it to a single order save.
    $order->addItem($this->entity);
    parent::saveOrder($order);
  }

  /**
   * Provides the title for the order item duplicate form.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $commerce_order_item
   *   The order item.
   */
  public function duplicateTitle(OrderItemInterface $commerce_order_item): TranslatableMarkup {
    return $this->t('Duplicate %label', ['%label' => $commerce_order_item->label()]);
  }

}
