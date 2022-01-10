<?php

namespace Drupal\commerce_authnet;

use Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway\Echeck;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Verify echeck transaction states.
 */
class EcheckTransactionVerifier implements PaymentProcessorInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Constructs a new EcheckTransactionVerifier object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger, TimeInterface $time) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public function getPayments() {
    $payment_gateway_storage = $this->entityTypeManager->getStorage('commerce_payment_gateway');
    $payment_gateway_ids = $payment_gateway_storage->getQuery()
      ->condition('plugin', 'authorizenet_echeck')
      ->execute();
    if (empty($payment_gateway_ids)) {
      return [];
    }

    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface[] $payment_gateways */
    $payment_gateways = $payment_gateway_storage->loadMultiple($payment_gateway_ids);
    $payment_gateway_plugins = [];
    foreach ($payment_gateways as $payment_gateway) {
      $payment_gateway_plugins[$payment_gateway->getPluginId()] = $payment_gateway->getPlugin();
    }

    // Get settled transactions.
    $payments = [];
    $now = date('Y-m-d\TH:i:s', $this->time->getCurrentTime());
    $two_days_ago = date('Y-m-d\TH:i:s', $this->time->getCurrentTime() - 480 * 3600);
    /** @var \Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway\EcheckInterface $plugin */
    foreach ($payment_gateway_plugins as $plugin) {
      $payments += $plugin->getSettledTransactions($two_days_ago, $now);
    }

    return $payments;
  }

  /**
   * {@inheritdoc}
   */
  public function processPayment(PaymentInterface $payment) {
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    if (!$payment_gateway_plugin instanceof Echeck) {
      return NULL;
    }

    if ($payment->getState() !== 'completed') {
      $payment_gateway_plugin->capturePayment($payment);
    }
  }

}
