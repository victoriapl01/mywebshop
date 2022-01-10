<?php

namespace Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;

/**
 * Provides the Authorize.net echeck payment gateway interface.
 */
interface EcheckInterface extends OnsitePaymentGatewayInterface, SupportsAuthorizationsInterface {

  /**
   * Get settled transactions from authorize.net.
   *
   * @param string $from_date
   *   The settlement starting date in Y-m-d\TH:i:s format.
   * @param string $to_date
   *   The settlement end date in Y-m-d\TH:i:s format.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface[]
   *   An array of payments keyed by the entity id.
   */
  public function getSettledTransactions($from_date, $to_date);

}
