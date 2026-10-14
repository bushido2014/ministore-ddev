<?php

namespace Drupal\commerce_product\EventSubscriber;

use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\DefaultContent\PreExportEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class DefaultContentEventSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [PreExportEvent::class => 'preExport'];
  }

  /**
   * Reacts before an entity is exported.
   *
   * @param \Drupal\Core\DefaultContent\PreExportEvent $event
   *   The event object.
   */
  public function preExport(PreExportEvent $event): void {
    // The product references its variations, and each variation references
    // the product back via product_id. Exporting both sides would create a
    // circular dependency that the importer cannot resolve, so the
    // backreference is skipped. It is restored when the product is saved.
    if ($event->entity instanceof ProductVariationInterface) {
      $event->setExportable('product_id', FALSE);
    }
  }

}
