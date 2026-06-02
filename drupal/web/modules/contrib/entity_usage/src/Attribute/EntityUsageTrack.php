<?php

declare(strict_types=1);

namespace Drupal\entity_usage\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines an entity_usage track attribute object.
 *
 * Plugin namespace: EntityUsage\Track.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class EntityUsageTrack extends Plugin {

  /**
   * Constructs an EntityUsageTrack attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The plugin label.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   The plugin description.
   * @param array $field_types
   *   The field types that this plugin is able to track.
   * @param class-string $source_entity_class
   *   Determines what source entities the plugins support.
   * @param class-string|null $deriver
   *   (optional) The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly array $field_types = [],
    public readonly string $source_entity_class = FieldableEntityInterface::class,
    public readonly ?string $deriver = NULL,
  ) {
  }

}
