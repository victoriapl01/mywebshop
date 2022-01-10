<?php

namespace Drupal\commerce_authnet;

use Drupal\commerce_payment\Entity\PaymentInterface;

/**
 * Interface to process payments.
 */
interface PaymentProcessorInterface {

  /**
   * Gets payments to process.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface[]
   *   An array of payments keyed by the entity id.
   */
  public function getPayments();

  /**
   * Processes the payment.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment.
   */
  public function processPayment(PaymentInterface $payment);

}
