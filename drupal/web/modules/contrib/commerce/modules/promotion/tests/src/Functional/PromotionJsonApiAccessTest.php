<?php

namespace Drupal\Tests\commerce_promotion\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;
use Drupal\commerce_promotion\Entity\Coupon;
use Drupal\commerce_promotion\Entity\CouponInterface;
use Drupal\commerce_promotion\Entity\Promotion;
use Drupal\commerce_promotion\Entity\PromotionInterface;
use Drupal\user\UserInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests that coupons can't be linked to other promotions through JSON:API.
 *
 * @group commerce
 */
class PromotionJsonApiAccessTest extends CommerceBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_promotion',
    'jsonapi',
    'basic_auth',
  ];

  /**
   * The promotion of another user.
   *
   * @var \Drupal\commerce_promotion\Entity\PromotionInterface
   */
  protected $otherPromotion;

  /**
   * The coupon of the other promotion.
   *
   * @var \Drupal\commerce_promotion\Entity\CouponInterface
   */
  protected $otherCoupon;

  /**
   * A user who can only update their own promotions.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $promotionOwner;

  /**
   * The promotion of the promotion owner.
   *
   * @var \Drupal\commerce_promotion\Entity\PromotionInterface
   */
  protected $ownPromotion;

  /**
   * The coupon of the promotion owner's promotion.
   *
   * @var \Drupal\commerce_promotion\Entity\CouponInterface
   */
  protected $ownCoupon;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config('jsonapi.settings')->set('read_only', FALSE)->save(TRUE);

    $this->otherPromotion = $this->createPromotion($this->adminUser);
    $this->otherCoupon = $this->createCoupon($this->otherPromotion);
    $this->promotionOwner = $this->drupalCreateUser([
      'view commerce_promotion',
      'create commerce_promotion',
      'update own commerce_promotion',
      'delete own commerce_promotion',
    ]);
    $this->ownPromotion = $this->createPromotion($this->promotionOwner);
    $this->ownCoupon = $this->createCoupon($this->ownPromotion);
  }

  /**
   * Tests moving a coupon to another promotion.
   */
  public function testCouponPromotion(): void {
    $response = $this->patchRelationship($this->promotionOwner, $this->ownCoupon, 'promotion_id', [
      'type' => 'commerce_promotion--commerce_promotion',
      'id' => $this->otherPromotion->uuid(),
    ]);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertEquals($this->ownPromotion->id(), $this->reloadEntity($this->ownCoupon)->getPromotionId());
    $this->assertFalse($this->reloadEntity($this->otherPromotion)->hasCoupon($this->ownCoupon));

    // Users who can update any promotion can move coupons.
    $account = $this->drupalCreateUser(['update any commerce_promotion']);
    $response = $this->patchRelationship($account, $this->ownCoupon, 'promotion_id', [
      'type' => 'commerce_promotion--commerce_promotion',
      'id' => $this->otherPromotion->uuid(),
    ]);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertEquals($this->otherPromotion->id(), $this->reloadEntity($this->ownCoupon)->getPromotionId());
  }

  /**
   * Tests referencing the coupon of another promotion.
   */
  public function testPromotionCoupons(): void {
    $response = $this->patchRelationship($this->promotionOwner, $this->ownPromotion, 'coupons', [
      [
        'type' => 'commerce_promotion_coupon--commerce_promotion_coupon',
        'id' => $this->ownCoupon->uuid(),
      ],
      [
        'type' => 'commerce_promotion_coupon--commerce_promotion_coupon',
        'id' => $this->otherCoupon->uuid(),
      ],
    ]);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertFalse($this->reloadEntity($this->ownPromotion)->hasCoupon($this->otherCoupon));

    // Changing other fields is still allowed.
    $response = $this->request('PATCH', $this->promotionOwner, $this->ownPromotion, [
      'attributes' => ['name' => 'Renamed'],
    ]);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertEquals('Renamed', $this->reloadEntity($this->ownPromotion)->getName());

    // Deleting the promotion doesn't delete the other promotion's coupon.
    $response = $this->request('DELETE', $this->promotionOwner, $this->ownPromotion);
    $this->assertSame(204, $response->getStatusCode());
    $this->assertNull($this->reloadEntity($this->ownCoupon));
    $this->assertNotNull($this->reloadEntity($this->otherCoupon));
  }

  /**
   * Creates a promotion.
   *
   * @param \Drupal\user\UserInterface $owner
   *   The owner.
   *
   * @return \Drupal\commerce_promotion\Entity\PromotionInterface
   *   The promotion.
   */
  protected function createPromotion(UserInterface $owner): PromotionInterface {
    $promotion = Promotion::create([
      'name' => $this->randomMachineName(),
      'uid' => $owner->id(),
      'order_types' => ['default'],
      'stores' => [$this->store->id()],
      'offer' => [
        'target_plugin_id' => 'order_percentage_off',
        'target_plugin_configuration' => [
          'percentage' => '0.10',
        ],
      ],
      'status' => TRUE,
    ]);
    $promotion->save();
    return $promotion;
  }

  /**
   * Creates a coupon.
   *
   * @param \Drupal\commerce_promotion\Entity\PromotionInterface $promotion
   *   The promotion.
   *
   * @return \Drupal\commerce_promotion\Entity\CouponInterface
   *   The coupon.
   */
  protected function createCoupon(PromotionInterface $promotion): CouponInterface {
    $coupon = Coupon::create([
      'promotion_id' => $promotion->id(),
      'code' => $this->randomMachineName(),
      'status' => TRUE,
    ]);
    $coupon->save();
    return $coupon;
  }

  /**
   * Changes a relationship of an entity through JSON:API.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account making the request.
   * @param \Drupal\commerce_promotion\Entity\PromotionInterface|\Drupal\commerce_promotion\Entity\CouponInterface $entity
   *   The entity.
   * @param string $field_name
   *   The relationship field name.
   * @param array $data
   *   The relationship data.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
   */
  protected function patchRelationship(UserInterface $account, PromotionInterface|CouponInterface $entity, string $field_name, array $data): ResponseInterface {
    return $this->request('PATCH', $account, $entity, [
      'relationships' => [
        $field_name => ['data' => $data],
      ],
    ]);
  }

  /**
   * Makes a JSON:API request for an entity.
   *
   * @param string $method
   *   The HTTP method.
   * @param \Drupal\user\UserInterface $account
   *   The account making the request.
   * @param \Drupal\commerce_promotion\Entity\PromotionInterface|\Drupal\commerce_promotion\Entity\CouponInterface $entity
   *   The entity.
   * @param array $document
   *   The resource object data, without the type and ID.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
   */
  protected function request(string $method, UserInterface $account, PromotionInterface|CouponInterface $entity, array $document = []): ResponseInterface {
    $entity_type_id = $entity->getEntityTypeId();
    $options = [
      'auth' => [$account->getAccountName(), $account->passRaw],
      'headers' => [
        'Accept' => 'application/vnd.api+json',
        'Content-Type' => 'application/vnd.api+json',
      ],
      'http_errors' => FALSE,
    ];
    if ($document) {
      $options['body'] = Json::encode([
        'data' => [
          'type' => "$entity_type_id--$entity_type_id",
          'id' => $entity->uuid(),
        ] + $document,
      ]);
    }
    $url = $this->buildUrl("/jsonapi/$entity_type_id/$entity_type_id/" . $entity->uuid());
    return $this->getHttpClient()->request($method, $url, $options);
  }

}
