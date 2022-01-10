<?php

namespace Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use CommerceGuys\AuthNet\DataTypes\TransactionRequest;
use CommerceGuys\AuthNet\DataTypes\BillTo;
use Drupal\commerce_payment\Entity\PaymentInterface;
use CommerceGuys\AuthNet\DataTypes\ShipTo;
use CommerceGuys\AuthNet\DataTypes\Order as OrderDataType;
use CommerceGuys\AuthNet\CreateTransactionRequest;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_price\Price;
use CommerceGuys\AuthNet\GetSettledBatchListRequest;
use CommerceGuys\AuthNet\GetTransactionListRequest;

/**
 * Provides the Authorize.net echeck payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "authorizenet_echeck",
 *   label = "Authorize.net (Echeck)",
 *   display_label = "Authorize.net Echeck",
 *   forms = {
 *     "add-payment-method" = "Drupal\commerce_authnet\PluginForm\EcheckAddForm",
 *   },
 *   payment_type = "payment_manual",
 *   payment_method_types = {"authnet_echeck"},
 *   requires_billing_information = FALSE,
 * )
 */
class Echeck extends OnsiteBase implements EcheckInterface {

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    $this->assertPaymentState($payment, ['new']);
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentMethod($payment_method);

    $order = $payment->getOrder();

    // Transaction request.
    // eChecks have a pseudo "authorized" state, so just do AUTH_CAPTURE.
    $transaction_request = new TransactionRequest([
      'transactionType' => TransactionRequest::AUTH_CAPTURE,
      'amount' => $payment->getAmount()->getNumber(),
    ]);

    list($data_descriptor, $data_value) = explode('|', $payment_method->getRemoteId());
    $payment_data = [
      'opaqueData' => [
        'dataDescriptor' => $data_descriptor,
        'dataValue' => $data_value,
      ],
    ];
    $transaction_request->addData('payment', $payment_data);

    if ($billing_profile = $payment_method->getBillingProfile()) {
      /** @var \Drupal\address\AddressInterface $address */
      $address = $billing_profile->get('address')->first();
      $bill_to = [
        // @todo how to allow customizing this.
        'firstName' => $address->getGivenName(),
        'lastName' => $address->getFamilyName(),
        'company' => $address->getOrganization(),
        'address' => substr($address->getAddressLine1() . ' ' . $address->getAddressLine2(), 0, 60),
        'country' => $address->getCountryCode(),
        'city' => $address->getLocality(),
        'state' => $address->getAdministrativeArea(),
        'zip' => $address->getPostalCode(),
        // @todo support adding phone and fax
      ];
      $transaction_request->addDataType(new BillTo(array_filter($bill_to)));
    }

    $profiles = $order->collectProfiles();
    if (isset($profiles['shipping']) && !$profiles['shipping']->get('address')->isEmpty()) {
      /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $shipping_address */
      $shipping_address = $profiles['shipping']->get('address')->first();
      $ship_data = [
        // @todo how to allow customizing this.
        'firstName' => $shipping_address->getGivenName(),
        'lastName' => $shipping_address->getFamilyName(),
        'address' => substr($shipping_address->getAddressLine1() . ' ' . $shipping_address->getAddressLine2(), 0, 60),
        'country' => $shipping_address->getCountryCode(),
        'company' => $shipping_address->getOrganization(),
        'city' => $shipping_address->getLocality(),
        'state' => $shipping_address->getAdministrativeArea(),
        'zip' => $shipping_address->getPostalCode(),
      ];
      $transaction_request->addDataType(new ShipTo(array_filter($ship_data)));
    }

    // Adding order information to the transaction.
    $transaction_request->addOrder(new OrderDataType([
      'invoiceNumber' => $order->getOrderNumber() ?: $order->id(),
    ]));
    $transaction_request->addData('customerIP', $order->getIpAddress());

    // Adding line items.
    $line_items = $this->getLineItems($order);
    foreach ($line_items as $line_item) {
      $transaction_request->addLineItem($line_item);
    }

    // Adding tax information to the transaction.
    $transaction_request->addData('tax', $this->getTax($order)->toArray());
    $transaction_request->addData('shipping', $this->getShipping($order)->toArray());

    $request = new CreateTransactionRequest($this->authnetConfiguration, $this->httpClient);
    $request->setTransactionRequest($transaction_request);
    $response = $request->execute();

    if ($response->getResultCode() != 'Ok') {
      $this->logResponse($response);
      $message = $response->getMessages()[0];
      switch ($message->getCode()) {
        case 'E00040':
          $payment_method->delete();
          throw new PaymentGatewayException('The provided payment method is no longer valid');

        default:
          throw new PaymentGatewayException($message->getText());
      }
    }

    if (!empty($response->getErrors())) {
      $message = $response->getErrors()[0];
      throw new HardDeclineException($message->getText());
    }

    // Mark the payment as pending as we await for transaction details from
    // Authorize.net.
    $payment->setState('pending');
    $payment->setRemoteId($response->transactionResponse->transId);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();

    $this->assertPaymentState($payment, ['pending']);
    $payment->setState('completed');
    $payment->setAmount($amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    $this->assertPaymentState($payment, ['pending']);
    $payment->setState('voided');
    $payment->save();
  }

  /**
   * {@inheritdoc}
   *
   * @todo Needs kernel test
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    $required_keys = [
      'data_descriptor', 'data_value',
    ];
    foreach ($required_keys as $required_key) {
      if (empty($payment_details[$required_key])) {
        throw new \InvalidArgumentException(sprintf('$payment_details must contain the %s key.', $required_key));
      }
    }

    // Reusing echecks is not supported at the moment.
    // @see https://community.developer.authorize.net/t5/Integration-and-Testing/Accept-JS-and-ACH/td-p/55874
    $payment_method->setReusable(FALSE);
    $payment_method->setRemoteId($payment_details['data_descriptor'] . '|' . $payment_details['data_value']);
    // OpaqueData expire after 15min. We reduce that time by 5s to account for
    // the time it took to do the server request after the JS tokenization.
    $expires = $this->time->getRequestTime() + (15 * 60) - 5;
    $payment_method->setExpiresTime($expires);

    $payment_method->save();
  }

  /**
   * {@inheritdoc}
   */
  public function getSettledTransactions($from_date, $to_date) {
    $request = new GetSettledBatchListRequest($this->authnetConfiguration, $this->httpClient, FALSE, $from_date, $to_date);
    $batch_response = $request->execute();
    $batch_ids = [];
    if ($batch_response->getResultCode() === 'Ok') {
      if (is_object($batch_response->contents()->batchList->batch)) {
        if ($batch_response->contents()->batchList->batch->paymentMethod === 'eCheck') {
          if ($batch_response->contents()->batchList->batch->settlementState === 'settledSuccessfully') {
            $batch_ids[] = $batch_response->contents()->batchList->batch->batchId;
          }
        }
      }
      else {
        foreach ($batch_response->contents()->batchList->batch as $batch) {
          if ($batch->paymentMethod === 'eCheck') {
            if ($batch->settlementState === 'settledSuccessfully') {
              $batch_ids[] = $batch->batchId;
            }
          }
        }
      }
    }
    $remote_ids = [];
    foreach ($batch_ids as $batch_id) {
      $request = new GetTransactionListRequest($this->authnetConfiguration, $this->httpClient, $batch_id);
      $transaction_list_response = $request->execute();
      if ($transaction_list_response->contents()->totalNumInResultSet == 1) {
        $remote_ids[] = $transaction_list_response->contents()->transactions->transaction->transId;
      }
      else {
        foreach ($transaction_list_response->contents()->transactions->transaction as $transaction) {
          $remote_ids[] = $transaction->transId;
        }
      }
    }

    $payments = [];
    if ($remote_ids) {
      $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
      $payment_ids = $payment_storage->getQuery()
        ->condition('state', 'pending')
        ->condition('remote_id', $remote_ids, 'IN')
        ->execute();
      if ($payment_ids) {
        $payments = $payment_storage->loadMultiple($payment_ids);
      }
    }

    return $payments;
  }

}
