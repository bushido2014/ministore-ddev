<?php

namespace Drupal\Tests\entity_usage\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\Entity\EntityTestMul;
use Drupal\entity_test\Entity\EntityTestMulRevPub;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;

/**
 * Tests entity usage API methods with multiple source langcodes.
 *
 * @group entity_usage
 */
class EntityUsageMultilingualTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_test',
    'entity_usage',
    'field',
    'language',
    'system',
    'user',
  ];

  /**
   * The name of the entity usage table.
   */
  protected string $tableName = 'entity_usage';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('entity_test_mul');
    $this->installEntitySchema('entity_test_mulrevpub');
    $this->installSchema('entity_usage', ['entity_usage']);
    $this->installConfig(['system', 'entity_usage']);

    // Add French as a site language so translations can be added.
    ConfigurableLanguage::createFromLangcode('fr')->save();

    // Add an entity_reference field on entity_test_mul pointing to entity_test.
    FieldStorageConfig::create([
      'field_name' => 'field_reference',
      'entity_type' => 'entity_test_mul',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'entity_test'],
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_reference',
      'entity_type' => 'entity_test_mul',
      'bundle' => 'entity_test_mul',
      'label' => 'Reference',
      'settings' => [
        'handler' => 'default:entity_test',
        'handler_settings' => [],
      ],
    ])->save();

    // Add the same field on entity_test_mulrevpub for revision/translation
    // tests.
    FieldStorageConfig::create([
      'field_name' => 'field_reference',
      'entity_type' => 'entity_test_mulrevpub',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'entity_test'],
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_reference',
      'entity_type' => 'entity_test_mulrevpub',
      'bundle' => 'entity_test_mulrevpub',
      'label' => 'Reference',
      'settings' => [
        'handler' => 'default:entity_test',
        'handler_settings' => [],
      ],
    ])->save();
  }

  /**
   * Tests that listTargetEntitiesByFieldAndMethod() filters by source_langcode.
   *
   * Regression test: before the fix the langcode condition was missing, so the
   * method returned targets for all translations of a source entity.
   *
   * @covers \Drupal\entity_usage\EntityUsage::listTargetEntitiesByFieldAndMethod
   */
  public function testListTargetEntitiesByFieldAndMethodFiltersByLangcode(): void {
    $target_en = EntityTest::create(['name' => 'target_en']);
    $target_en->save();
    $target_fr = EntityTest::create(['name' => 'target_fr']);
    $target_fr->save();

    // Create source entity in English referencing the English target.
    $source = EntityTestMul::create([
      'name' => 'source',
      'langcode' => 'en',
      'field_reference' => ['target_id' => $target_en->id()],
    ]);
    // Add a French translation of the source referencing the French target.
    $source->addTranslation('fr', [
      'name' => 'source_fr',
      'field_reference' => ['target_id' => $target_fr->id()],
    ]);
    // Saving triggers entity_insert → trackUpdateOnCreation, which iterates
    // over all translations and records usage for each.
    $source->save();
    $source_vid = $source->getRevisionId() ?: 0;

    /** @var \Drupal\entity_usage\EntityUsageInterface $entity_usage */
    $entity_usage = $this->container->get('entity_usage.usage');

    // The EN query must only return the EN target, not the FR one.
    $result_en = $entity_usage->listTargetEntitiesByFieldAndMethod(
      $source->id(),
      $source->getEntityTypeId(),
      'en',
      $source_vid,
      'entity_reference',
      'field_reference',
    );
    $this->assertCount(1, $result_en, 'EN query returns exactly one target.');
    $this->assertContains($target_en->getEntityTypeId() . '|' . $target_en->id(), $result_en);
    $this->assertNotContains($target_fr->getEntityTypeId() . '|' . $target_fr->id(), $result_en);

    // The FR query must only return the FR target, not the EN one.
    $result_fr = $entity_usage->listTargetEntitiesByFieldAndMethod(
      $source->id(),
      $source->getEntityTypeId(),
      'fr',
      $source_vid,
      'entity_reference',
      'field_reference',
    );
    $this->assertCount(1, $result_fr, 'FR query returns exactly one target.');
    $this->assertContains($target_fr->getEntityTypeId() . '|' . $target_fr->id(), $result_fr);
    $this->assertNotContains($target_en->getEntityTypeId() . '|' . $target_en->id(), $result_fr);
  }

  /**
   * Tests that listSources() lists each translation as a separate usage row.
   *
   * @covers \Drupal\entity_usage\EntityUsage::listSources
   */
  public function testListSourcesWithMultipleLangcodes(): void {
    $target = EntityTest::create(['name' => 'target']);
    $target->save();

    // Create a source entity in English and French, both referencing the same
    // target.
    $source = EntityTestMul::create([
      'name' => 'source',
      'langcode' => 'en',
      'field_reference' => ['target_id' => $target->id()],
    ]);
    $source->addTranslation('fr', [
      'name' => 'source_fr',
      'field_reference' => ['target_id' => $target->id()],
    ]);
    $source->save();

    /** @var \Drupal\entity_usage\EntityUsageInterface $entity_usage */
    $entity_usage = $this->container->get('entity_usage.usage');

    $sources = $entity_usage->listSources($target);
    $rows = $sources[$source->getEntityTypeId()][(string) $source->id()];

    // Both translations should be present as separate rows.
    $this->assertCount(2, $rows);
    $recorded_langcodes = array_column($rows, 'source_langcode');
    $this->assertEqualsCanonicalizing(['en', 'fr'], $recorded_langcodes);
  }

  /**
   * Tests tracking with revisions and translations on EntityTestMulRevPub.
   *
   * @covers \Drupal\entity_usage\EntityUsage::listTargetEntitiesByFieldAndMethod
   */
  public function testListTargetEntitiesByFieldAndMethodWithRevisions(): void {
    $target_en = EntityTest::create(['name' => 'target_en']);
    $target_en->save();
    $target_fr = EntityTest::create(['name' => 'target_fr']);
    $target_fr->save();

    $source = EntityTestMulRevPub::create([
      'name' => 'source',
      'langcode' => 'en',
      'field_reference' => ['target_id' => $target_en->id()],
    ]);
    $source->addTranslation('fr', [
      'name' => 'source_fr',
      'field_reference' => ['target_id' => $target_fr->id()],
    ]);
    $source->save();
    $vid = $source->getRevisionId();

    /** @var \Drupal\entity_usage\EntityUsageInterface $entity_usage */
    $entity_usage = $this->container->get('entity_usage.usage');

    $result_en = $entity_usage->listTargetEntitiesByFieldAndMethod(
      $source->id(),
      $source->getEntityTypeId(),
      'en',
      $vid,
      'entity_reference',
      'field_reference',
    );
    $this->assertCount(1, $result_en);
    $this->assertContains($target_en->getEntityTypeId() . '|' . $target_en->id(), $result_en);

    $result_fr = $entity_usage->listTargetEntitiesByFieldAndMethod(
      $source->id(),
      $source->getEntityTypeId(),
      'fr',
      $vid,
      'entity_reference',
      'field_reference',
    );
    $this->assertCount(1, $result_fr);
    $this->assertContains($target_fr->getEntityTypeId() . '|' . $target_fr->id(), $result_fr);
  }

}
