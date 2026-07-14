<?php

namespace Drupal\Tests\entity_usage\Unit;

use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\Url;
use Drupal\entity_usage\UrlToEntity;
use Drupal\Tests\UnitTestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Tests \Drupal\entity_usage\UrlToEntity.
 *
 * @coversDefaultClass \Drupal\entity_usage\UrlToEntity
 * @group entity_usage
 */
class UrlToEntityTest extends UnitTestCase {

  /**
   * Tests \Drupal\entity_usage\UrlToEntity::findEntityIdByRoutedUrl()
   *
   * @testWith ["node"]
   *           ["my1entity"]
   */
  public function testFindEntityIdByRoutedUrl(string $entity_type_id): void {
    $url_to_entity = new UrlToEntity(
      $this->createMock(InboundPathProcessorInterface::class),
      $this->getConfigFactoryStub([
        'entity_usage.settings' => [
          'site_domains' => ['example.com'],
        ],
      ]),
      $this->createMock(EventDispatcherInterface::class),
    );

    $url = new Url('entity.' . $entity_type_id . '.canonical', [$entity_type_id => 1]);
    $this->assertSame(['type' => $entity_type_id, 'id' => 1], $url_to_entity->findEntityIdByRoutedUrl($url));
  }

}
