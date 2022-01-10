<?php

namespace Drupal\commerce_authnet\Plugin\Commerce\PaymentMethodType;

use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentMethodType\PaymentMethodTypeBase;

/**
 * Provides the Authorize.net eCheck payment method type.
 *
 * @CommercePaymentMethodType(
 *   id = "authnet_echeck",
 *   label = @Translation("eCheck"),
 *   create_label = @Translation("eCheck"),
 * )
 */
class AuthorizeNetEcheck extends PaymentMethodTypeBase {

  /**
   * {@inheritdoc}
   */
  public function buildLabel(PaymentMethodInterface $payment_method) {
    // eChecks are not reused, so use a generic label.
    return $this->t('eCheck');
  }

  /**
   * The account types.
   */
  public static function getAccountTypes() {
    return [
      'checking' => t('Checking'),
      'saving' => t('Savings'),
      'business_checking' => t('Business checking'),
    ];
  }

}
