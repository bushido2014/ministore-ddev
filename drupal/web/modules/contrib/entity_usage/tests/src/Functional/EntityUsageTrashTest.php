<?php

namespace Drupal\Tests\entity_usage\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Tests the Trash module's interaction with Entity Usage.
 *
 * @group entity_usage
 * @group trash
 */
class EntityUsageTrashTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_usage',
    'entity_test',
    'entity_usage_test',
    'media',
    'node',
    'path',
    'text',
    'user',
    'field',
    'system',
    'trash',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config('entity_usage.settings')
      ->set('local_task_enabled_entity_types', ['entity_test'])
      ->set('track_enabled_source_entity_types', ['node', 'block_content'])
      ->set('track_enabled_target_entity_types', ['block_content', 'entity_test'])
      ->set('track_enabled_plugins', ['entity_reference'])
      ->save();

    /** @var \Drupal\Core\Routing\RouteBuilderInterface $routerBuilder */
    $routerBuilder = \Drupal::service('router.builder');
    $routerBuilder->rebuild();
  }

  /**
   * Test that trashed entities are still shown as sources.
   */
  public function testTrashedEntitiesShowAsSources(): void {
    // Create a content type with an entity reference field to act as our
    // source.
    $contentType = $this->drupalCreateContentType([
      'type' => 'foo',
      'name' => 'Foo',
    ]);

    $fieldStorage = FieldStorageConfig::create([
      'field_name' => 'my_ref',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => [
        'target_type' => 'entity_test',
      ],
    ]);
    $fieldStorage->save();
    $field = FieldConfig::create([
      'field_storage' => $fieldStorage,
      'bundle' => $contentType->id(),
    ]);
    $field->save();

    $targetEntity = EntityTest::create(['name' => $this->randomMachineName()]);
    $targetEntity->save();

    $node = $this->drupalCreateNode([
      'type' => 'foo',
      'title' => 'Foo',
      'my_ref' => [
        'target_id' => $targetEntity->id(),
      ],
    ]);

    $this->drupalLogin($this->drupalCreateUser([
      'access entity usage statistics',
      'view test entity',
      'bypass node access',
      'view deleted entities',
    ]));

    // Verify that our node shows up as a usage source for the target entity.
    $this->drupalGet(Url::fromRoute('entity.entity_test.entity_usage', ['entity_test' => $targetEntity->id()]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('There are no recorded usages');
    $usageFirstRow = $this->assertSession()->elementExists('css', 'table tbody tr:nth-child(1)');
    $link = $this->assertSession()->elementExists('css', 'td:nth-child(1) a', $usageFirstRow);
    $this->assertEquals($link->getText(), 'Foo');
    $this->assertEquals($link->getAttribute('href'), $node->toUrl()->toString());

    // Now delete the node, which moves it to the trash.
    $this->drupalGet("/node/{$node->id()}/delete");
    $this->assertSession()->pageTextContains('Deleting this content item will move it to the trash.');
    $this->submitForm([], 'Delete');

    // Verify that the source list still shows the node, but that there's an
    // indicator it's in the trash.
    $this->drupalGet(Url::fromRoute('entity.entity_test.entity_usage', ['entity_test' => $targetEntity->id()]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('There are no recorded usages');
    $usageFirstRow = $this->assertSession()->elementExists('css', 'table tbody tr:nth-child(1)');
    $link = $this->assertSession()->elementExists('css', 'td:nth-child(1) a', $usageFirstRow);
    $this->assertEquals($link->getText(), 'Foo (in trash)');
    $this->assertEquals($link->getAttribute('href'), $node->toUrl('canonical', ['query' => ['in_trash' => TRUE]])->toString());

    // Verify that a user that isn't allowed to view trashed entities can still
    // see the source listed, but they don't see the label to the entity or
    // the link.
    $this->drupalLogin($this->drupalCreateUser([
      'access entity usage statistics',
      'view test entity',
      'bypass node access',
    ]));
    $this->drupalGet(Url::fromRoute('entity.entity_test.entity_usage', ['entity_test' => $targetEntity->id()]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('There are no recorded usages');
    $usageFirstRow = $this->assertSession()->elementExists('css', 'table tbody tr:nth-child(1)');
    $firstColumn = $this->assertSession()->elementExists('css', 'td:nth-child(1)', $usageFirstRow);
    $this->assertSession()->elementNotExists('css', 'a', $firstColumn);
    $this->assertEquals($firstColumn->getText(), '- Restricted access - (in trash)');
  }

}
