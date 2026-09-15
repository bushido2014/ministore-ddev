<?php

declare(strict_types=1);

namespace Drupal\entity_usage_test;

use Drupal\entity_usage\EntityUsageBatchManager;

/**
 * Batch manager with a tiny chunk size so tests can run several chunks.
 */
class TestEntityUsageBatchManager extends EntityUsageBatchManager {

  /**
   * The number of revisions or entities to load when in bulk mode.
   */
  const BULK_BATCH_SIZE = 2;

}
