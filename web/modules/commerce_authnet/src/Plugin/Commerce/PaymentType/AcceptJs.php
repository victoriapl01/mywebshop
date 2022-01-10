<?php

namespace Drupal\commerce_authnet\Plugin\Commerce\PaymentType;

use Drupal\commerce_payment\Plugin\Commerce\PaymentType\PaymentTypeBase;

/**
 * Provides the payment type for Accept.js.
 *
 * @CommercePaymentType(
 *   id = "acceptjs",
 *   label = @Translation("Authorize.net (Accept.js)"),
 *   workflow = "payment_acceptjs"
 * )
 */
class AcceptJs extends PaymentTypeBase {

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions() {
    return [];
  }

}
