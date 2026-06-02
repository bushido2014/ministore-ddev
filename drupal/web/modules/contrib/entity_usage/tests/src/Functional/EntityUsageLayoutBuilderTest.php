<?php

namespace Drupal\Tests\entity_usage\Functional;

use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;

/**
 * Tests layout builder usage through Inline Blocks displays in UI.
 *
 * @group entity_usage
 * @group layout_builder
 * @coversDefaultClass \Drupal\entity_usage\Plugin\EntityUsage\Track\LayoutBuilder
 */
class EntityUsageLayoutBuilderTest extends BrowserTestBase {

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
    'block_content',
    'block',
    'text',
    'user',
    'layout_builder',
    'layout_discovery',
    'field',
    'system',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'eu_test_ct',
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->enableLayoutBuilder()
      ->setOverridable()
      ->save();

    $this->config('entity_usage.settings')
      ->set('local_task_enabled_entity_types', ['entity_test'])
      ->set('track_enabled_source_entity_types', ['node', 'block_content'])
      ->set('track_enabled_target_entity_types', ['block_content', 'entity_test'])
      ->set('track_enabled_plugins', ['layout_builder', 'entity_reference'])
      ->save();

    /** @var \Drupal\Core\Routing\RouteBuilderInterface $routerBuilder */
    $routerBuilder = \Drupal::service('router.builder');
    $routerBuilder->rebuild();
  }

  /**
   * Test entities referenced by block content in LB are shown on usage page.
   *
   * E.g, if entityHost (with LB) -> Inline Block Content -> entityInner, when
   * navigating to entityInner, the source relationship is shown as ultimately
   * coming from entityHost (via Block Content).
   */
  public function testLayoutBuilderInlineAndReusableBlockUsage(): void {
    // Create a block content type with an entity reference field to our custom
    // entity type.
    $blockType = BlockContentType::create([
      'id' => 'foo',
      'label' => 'Foo',
    ]);
    $blockType->save();

    $fieldStorage = FieldStorageConfig::create([
      'field_name' => 'my_ref',
      'entity_type' => 'block_content',
      'type' => 'entity_reference',
      'settings' => [
        'target_type' => 'entity_test',
      ],
    ]);
    $fieldStorage->save();
    $field = FieldConfig::create([
      'field_storage' => $fieldStorage,
      'bundle' => $blockType->id(),
    ]);
    $field->save();

    // Create two entities of our custom type. These will be referenced by
    // a couple content blocks.
    $innerEntityReferencedByReusableBlock = EntityTest::create(['name' => $this->randomMachineName()]);
    $innerEntityReferencedByReusableBlock->save();
    $innerEntityReferencedByInlineBlock = EntityTest::create(['name' => $this->randomMachineName()]);
    $innerEntityReferencedByInlineBlock->save();

    // Create a node from a layout builder enabled content type, and add
    // an inline block and reusable block to its layout.
    $reusableBlock = BlockContent::create([
      'type' => $blockType->id(),
      'info' => $this->randomString(),
      'reusable' => 1,
      'status' => 1,
      'my_ref' => $innerEntityReferencedByReusableBlock,
    ]);
    $reusableBlock->save();
    $layoutBuilderNode = Node::create([
      'title' => $this->randomMachineName(),
      'type' => 'eu_test_ct',
    ]);
    $layoutBuilderNode->save();
    $sectionData = [
      new Section('layout_onecol', [], [
        'first-uuid' => new SectionComponent('first-uuid', 'content', [
          'id' => 'inline_block:' . $blockType->id(),
          'block_serialized' => serialize(BlockContent::create([
            'info' => $this->randomString(),
            'type' => $blockType->id(),
            'reusable' => 0,
            'my_ref' => [
              'target_id' => $innerEntityReferencedByInlineBlock->id(),
            ],
          ])),
        ]),
        'second-uuid' => new SectionComponent('second-uuid', 'content', [
          'id' => 'block_content:' . $reusableBlock->uuid(),
        ]),
      ]),
    ];
    $layoutBuilderNode->set(OverridesSectionStorage::FIELD_NAME, $sectionData);
    $layoutBuilderNode->save();

    $this->drupalLogin($this->drupalCreateUser([
      'access entity usage statistics',
      'view test entity',
      'bypass node access',
    ]));

    // Check the usage for our custom entities now.
    // The inline block should be displayed as a usage of its referenced entity,
    // but its name, link, and status should be that of its host entity (the
    // layout builder node) since inline blocks don't exist outside their
    // host entity (similar to paragraphs).
    $this->assertInnerEntityUsage($innerEntityReferencedByInlineBlock, $layoutBuilderNode->label(), $layoutBuilderNode->toUrl()->toString(), 'Published');

    // The reusable block should be displayed as a usage of its referenced
    // entity, but its name and status should be that of the block and NOT
    // the layout builder node that uses the block. We don't jump the reference
    // to layout builder node using the reusable block because this block can
    // be used by MANY layout builder nodes.
    $this->assertInnerEntityUsage($innerEntityReferencedByReusableBlock, $reusableBlock->label(), NULL, 'Published');

    // Unpublish the parent node and verify that the "Status" column for the
    // inline block usage updates accordingly.
    // The reusable block usage should remain unchanged.
    $layoutBuilderNode->setUnpublished();
    $layoutBuilderNode->save();

    $this->assertInnerEntityUsage($innerEntityReferencedByInlineBlock, $layoutBuilderNode->label(), $layoutBuilderNode->toUrl()->toString(), 'Unpublished');
    $this->assertInnerEntityUsage($innerEntityReferencedByReusableBlock, $reusableBlock->label(), NULL, 'Published');

    $layoutBuilderNode->delete();

    // With the layout builder node deleted, verify that the entity used by
    // the inline block no longer shows any usages.
    $this->drupalGet(Url::fromRoute('entity.entity_test.entity_usage', ['entity_test' => $innerEntityReferencedByInlineBlock->id()]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('There are no recorded usages');

    // The usage data for the entity referenced by the reusable block should
    // not show any differences than before.
    $this->assertInnerEntityUsage($innerEntityReferencedByReusableBlock, $reusableBlock->label(), NULL, 'Published');
  }

  /**
   * Asserts that a host entity is listed against the usage of an inner entity.
   */
  protected function assertInnerEntityUsage(EntityTest $inner, string $host_label, ?string $host_url, string $host_status): void {
    $this->drupalGet(Url::fromRoute('entity.entity_test.entity_usage', ['entity_test' => $inner->id()]));
    $this->assertSession()->statusCodeEquals(200);
    $row = $this->assertSession()->elementExists('css', 'table tbody tr:nth-child(1)');

    if ($host_url) {
      $link = $this->assertSession()->elementExists('css', 'td:nth-child(1) a', $row);
      $this->assertEquals($link->getText(), $host_label);
      $this->assertEquals($link->getAttribute('href'), $host_url);
    }
    else {
      $host_label_element = $this->assertSession()->elementExists('css', 'td:nth-child(1)', $row);
      $this->assertEquals($host_label_element->getText(), $host_label);
    }

    $status = $this->assertSession()->elementExists('css', 'td:nth-child(5)', $row);
    $this->assertEquals($status->getText(), $host_status);
  }

}
