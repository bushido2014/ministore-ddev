<?php

namespace Drupal\Tests\entity_usage\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\TestFileCreationTrait;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;

/**
 * Tests file tracking via the link plugin.
 *
 * Needs to be a browser test as we need a real file system.
 *
 * @group entity_usage
 */
class EntityUsageTrackFileViaLinkTest extends BrowserTestBase {

  use TestFileCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_test',
    'entity_usage',
    'field',
    'file',
    // For some reason the image module is needed for this test to pass.
    'image',
    'link',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Add a link field.
    FieldStorageConfig::create([
      'field_name' => 'link',
      'entity_type' => 'entity_test',
      'type' => 'link',
    ])->save();

    FieldConfig::create([
      'field_name' => 'link',
      'label' => 'Link',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ])->save();

    $current_request = \Drupal::request();
    $this->config('entity_usage.settings')
      ->set('site_domains', [$current_request->getHttpHost() . $current_request->getBasePath()])
      ->set('track_enabled_source_entity_types', ['entity_test'])
      ->set('track_enabled_target_entity_types', ['file'])
      ->set('track_enabled_plugins', ['link'])
      ->save();
  }

  /**
   * Tests tracking files via the link plugin.
   */
  public function testFileViaLink(): void {
    $files = $this->getTestFiles('text');

    $text_file = File::create(['uri' => $files[0]->uri]);
    $text_file->save();

    $entity = EntityTest::create([
      'type' => 'entity_test',
      'name' => $this->randomString(),
      'link' => [
        'title' => 'Test link',
        'uri' => 'base:/' . $this->publicFilesDirectory . '/' . $files[0]->filename,
      ],
    ]);
    $entity->save();

    $this->assertSame([
      'file' => [
        $text_file->id() => [
          [
            'method' => 'link',
            'field_name' => 'link',
            'count' => '1',
          ],
        ],
      ],
    ], $this->container->get('entity_usage.usage')->listTargets($entity));
  }

  /**
   * Tests tracking files linked via an absolute URL.
   *
   * When the link URI is an absolute external URL (e.g. http://localhost/…),
   * the link plugin calls findEntityIdByUrl() which strips the host via
   * site_domains, then PublicFileIntegration matches the remaining path
   * against a regex built from the public files base path.
   *
   * The public stream wrapper service returns a trailing slash in that base
   * path (e.g. http://localhost/.../files/). Without the rtrim() fix the
   * regex would contain a double slash and no files would be tracked.
   *
   * @see \Drupal\entity_usage\UrlToEntityIntegrations\PublicFileIntegration
   */
  public function testFileViaAbsoluteLink(): void {
    $files = $this->getTestFiles('text');

    $text_file = File::create(['uri' => $files[0]->uri]);
    $text_file->save();

    $stream_wrapper = $this->container->get('stream_wrapper.public');
    $stream_wrapper->setUri($files[0]->uri);

    $entity = EntityTest::create([
      'type' => 'entity_test',
      'name' => $this->randomString(),
      'link' => [
        'title' => 'Test link',
        'uri' => $stream_wrapper->getExternalUrl(),
      ],
    ]);
    $entity->save();

    $this->assertSame([
      'file' => [
        $text_file->id() => [
          [
            'method' => 'link',
            'field_name' => 'link',
            'count' => '1',
          ],
        ],
      ],
    ], $this->container->get('entity_usage.usage')->listTargets($entity));
  }

}
