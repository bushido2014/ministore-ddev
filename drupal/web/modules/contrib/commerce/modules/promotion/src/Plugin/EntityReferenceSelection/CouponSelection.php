<?php

namespace Drupal\commerce_promotion\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Restricts listing coupons to users who can manage promotions.
 *
 * Coupons have no query access handler, so the default selection would list
 * every coupon code (e.g. through the entity autocomplete route). Only the
 * listing is restricted: validating references (e.g. the order "coupons"
 * field when a customer redeems a coupon) must keep working for customers.
 *
 * @see \Drupal\commerce_promotion\CouponAccessControlHandler::checkCreateAccess()
 */
#[EntityReferenceSelection(
  id: 'default:commerce_promotion_coupon',
  label: new TranslatableMarkup('Coupon selection'),
  group: 'default',
  weight: 1,
  entity_types: ['commerce_promotion_coupon'],
)]
class CouponSelection extends DefaultSelection {

  /**
   * {@inheritdoc}
   */
  public function getReferenceableEntities($match = NULL, $match_operator = 'CONTAINS', $limit = 0) {
    if (!static::canListCoupons($this->currentUser)) {
      return [];
    }
    return parent::getReferenceableEntities($match, $match_operator, $limit);
  }

  /**
   * Checks whether the given account can list coupon codes.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return bool
   *   TRUE if the account can list coupon codes, FALSE otherwise.
   */
  public static function canListCoupons(AccountInterface $account): bool {
    return $account->hasPermission('administer commerce_promotion') ||
      $account->hasPermission('update any commerce_promotion');
  }

}
