<?php

declare(strict_types=1);

namespace Drupal\Tests\entity_usage\Kernel;

use Drupal\Core\Access\AccessResult;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\entity_usage\Controller\ListUsageController;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the cacheability of ListUsageController::checkAccess().
 *
 * @coversDefaultClass \Drupal\entity_usage\Controller\ListUsageController
 *
 * @group entity_usage
 */
#[Group('entity_usage')]
#[RunTestsInSeparateProcesses]
class ListUsageControllerCheckAccessTest extends KernelTestBase {

  use NodeCreationTrait {
    createNode as drupalCreateNode;
  }
  use UserCreationTrait {
    createUser as drupalCreateUser;
  }
  use ContentTypeCreationTrait {
    createContentType as drupalCreateContentType;
  }

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'datetime',
    'user',
    'system',
    'filter',
    'field',
    'text',
    'entity_usage',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['filter', 'node', 'user']);
    $this->installSchema('entity_usage', ['entity_usage']);
    $this->installConfig('entity_usage');

    // Clear permissions for authenticated users so access is only granted
    // through what we explicitly assign to the test user below.
    $this->config('user.role.' . RoleInterface::AUTHENTICATED_ID)
      ->set('permissions', [])
      ->save();

    // Create and discard a first user so it consumes uid 1, which Drupal
    // always treats as a superuser bypassing every permission check. Without
    // this, the accounts created in the tests below would themselves become
    // uid 1 and no access denial could ever be exercised.
    $this->drupalCreateUser();

    $this->drupalCreateContentType(['type' => 'page']);
  }

  /**
   * Ensures checkAccess() returns the entity's real cacheability metadata.
   *
   * @covers ::checkAccess
   */
  public function testCheckAccessCacheability(): void {
    $account = $this->drupalCreateUser(['access content']);
    $this->setCurrentUser($account);

    $node = $this->drupalCreateNode(['type' => 'page', 'status' => 1]);

    /** @var \Drupal\entity_usage\Controller\ListUsageController $controller */
    $controller = ListUsageController::create($this->container);
    $access_result = $controller->checkAccess('node', (int) $node->id());

    $this->assertInstanceOf(AccessResult::class, $access_result);
    $this->assertTrue($access_result->isAllowed());

    // The node's own cacheability must survive: if the node is unpublished
    // or its permissions change, the decision needs to be invalidated. This
    // is exactly the metadata that was being dropped before the fix, since
    // ->access('view') without $return_as_object discards it entirely.
    $this->assertContains('node:' . $node->id(), $access_result->getCacheTags());
    $this->assertContains('user.permissions', $access_result->getCacheContexts());
  }

  /**
   * Ensures checkAccess() denies access, with cacheability, when forbidden.
   *
   * @covers ::checkAccess
   */
  public function testCheckAccessCacheabilityForbidden(): void {
    $account = $this->drupalCreateUser(['access content']);
    $this->setCurrentUser($account);

    // Unpublished, and the account lacks 'view own unpublished content', so
    // 'view' is denied even though the account owns the node.
    $node = $this->drupalCreateNode(['type' => 'page', 'status' => 0]);

    $controller = ListUsageController::create($this->container);
    $access_result = $controller->checkAccess('node', (int) $node->id());

    $this->assertInstanceOf(AccessResult::class, $access_result);
    $this->assertFalse($access_result->isAllowed());
    $this->assertContains('node:' . $node->id(), $access_result->getCacheTags());
  }

}
