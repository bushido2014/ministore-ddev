<?php

namespace Drupal\commerce_promotion;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the list builder for coupons.
 */
class CouponListBuilder extends EntityListBuilder {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The usage.
   *
   * @var \Drupal\commerce_promotion\PromotionUsageInterface
   */
  protected $usage;

  /**
   * The usage counts.
   *
   * @var array
   */
  protected $usageCounts;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    $instance->routeMatch = $container->get('current_route_match');
    $instance->usage = $container->get('commerce_promotion.usage');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function load() {
    $promotion = $this->routeMatch->getParameter('commerce_promotion');
    $coupons = $this->getStorage()->loadMultipleByPromotion($promotion);
    // Load the usage counts for each coupon.
    $this->usageCounts = $this->usage->loadMultipleByCoupon($coupons);

    return $coupons;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['code'] = $this->t('Code');
    $header['usage'] = $this->t('Usage');
    $header['customer_limit'] = $this->t('Per-customer limit');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\commerce_promotion\Entity\CouponInterface $entity */
    $current_usage = $this->usageCounts[$entity->id()];
    $usage_limit = $entity->getUsageLimit();
    $usage_limit = $usage_limit ?: $this->t('Unlimited');
    $customer_limit = $entity->getCustomerUsageLimit();
    $customer_limit = $customer_limit ?: $this->t('Unlimited');
    $row['code'] = $entity->label();
    if (!$entity->isEnabled()) {
      $row['code'] .= ' (' . $this->t('Disabled') . ')';
    }
    $row['usage'] = $current_usage . ' / ' . $usage_limit;
    $row['customer_limit'] = $customer_limit;

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity/* , ?CacheableMetadata $cacheability = NULL */) {
    $cacheability = func_num_args() > 1 ? func_get_arg(1) : NULL;
    $operations = parent::getDefaultOperations($entity, $cacheability);

    $access = $entity->access('update', NULL, TRUE);
    if ($cacheability instanceof CacheableMetadata) {
      $cacheability->addCacheableDependency($access);
    }
    if ($access->isAllowed()) {
      if (!$entity->isEnabled() && $entity->hasLinkTemplate('enable-form')) {
        $operations['enable'] = [
          'title' => $this->t('Enable'),
          'weight' => -10,
          'url' => $this->ensureDestination($entity->toUrl('enable-form')),
        ];
      }
      elseif ($entity->hasLinkTemplate('disable-form')) {
        $operations['disable'] = [
          'title' => $this->t('Disable'),
          'weight' => 40,
          'url' => $this->ensureDestination($entity->toUrl('disable-form')),
        ];
      }
    }

    return $operations;
  }

}
