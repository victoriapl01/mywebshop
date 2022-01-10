<?php

namespace Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway;

use CommerceGuys\AuthNet\DataTypes\Shipping;
use Drupal\commerce_order\AdjustmentTransformerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\MinorUnitsConverterInterface;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;
use CommerceGuys\AuthNet\Configuration;
use CommerceGuys\AuthNet\CreateTransactionRequest;
use CommerceGuys\AuthNet\DataTypes\LineItem;
use CommerceGuys\AuthNet\DataTypes\MerchantAuthentication;
use CommerceGuys\AuthNet\DataTypes\TransactionRequest;
use CommerceGuys\AuthNet\DeleteCustomerPaymentProfileRequest;
use CommerceGuys\AuthNet\Request\XmlRequest;
use CommerceGuys\AuthNet\Response\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use CommerceGuys\AuthNet\DataTypes\Tax;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Provides the Authorize.net payment gateway base class.
 */
abstract class OnsiteBase extends OnsitePaymentGatewayBase implements OnsitePaymentGatewayInterface, SupportsAuthorizationsInterface {

  /**
   * The adjustment transformer.
   *
   * @var \Drupal\commerce_order\AdjustmentTransformerInterface
   */
  protected $adjustmentTransformer;

  /**
   * The Authorize.net API configuration.
   *
   * @var \CommerceGuys\AuthNet\Configuration
   */
  protected $authnetConfiguration;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The private temp store factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $privateTempStore;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, MinorUnitsConverterInterface $minor_units_converter, ClientInterface $client, LoggerInterface $logger, PrivateTempStoreFactory $private_tempstore, AdjustmentTransformerInterface $adjustment_transformer, MessengerInterface $messenger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time, $minor_units_converter);

    $this->httpClient = $client;
    $this->logger = $logger;
    $this->authnetConfiguration = new Configuration([
      'sandbox' => ($this->getMode() == 'test'),
      'api_login' => $this->configuration['api_login'],
      'transaction_key' => $this->configuration['transaction_key'],
      'client_key' => $this->configuration['client_key'],
    ]);
    $this->privateTempStore = $private_tempstore;
    $this->adjustmentTransformer = $adjustment_transformer;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('commerce_price.minor_units_converter'),
      $container->get('http_client'),
      $container->get('commerce_authnet.logger'),
      $container->get('tempstore.private'),
      $container->get('commerce_order.adjustment_transformer'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'api_login' => '',
      'transaction_key' => '',
      'client_key' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['api_login'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Login ID'),
      '#default_value' => $this->configuration['api_login'],
      '#required' => TRUE,
    ];

    $form['transaction_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Transaction Key'),
      '#default_value' => $this->configuration['transaction_key'],
      '#required' => TRUE,
    ];

    $form['client_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client Key'),
      '#description' => $this->t('Follow the instructions <a href="https://developer.authorize.net/api/reference/features/acceptjs.html#Obtaining_a_Public_Client_Key">here</a> to get a client key.'),
      '#default_value' => $this->configuration['client_key'],
      '#required' => TRUE,
    ];

    try {
      $url = Url::fromRoute('entity.commerce_checkout_flow.collection');
      $form['transaction_type'] = [
        '#markup' => $this->t('<p>To configure the transaction settings, modify the <em>Payment process</em> pane in your checkout flow. From there you can choose authorization only or authorization and capture. You can manage your checkout flows here: <a href=":url">:url</a></p>', [
          ':url' => $url->toString(),
        ]) . $this->t('<p>For Echeck to work Transaction Details API needs to be enabled in your merchant account ("Account" => "Transaction Details API").</p>'),
      ];
    }
    catch (\Exception $e) {
      // Route was malformed, such as checkout not being enabled. So do nothing.
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);

    if (!empty($values['api_login']) && !empty($values['transaction_key'])) {
      $request = new XmlRequest(new Configuration([
        'sandbox' => ($values['mode'] == 'test'),
        'api_login' => $values['api_login'],
        'transaction_key' => $values['transaction_key'],
      ]), $this->httpClient, 'authenticateTestRequest');
      $request->addDataType(new MerchantAuthentication([
        'name' => $values['api_login'],
        'transactionKey' => $values['transaction_key'],
      ]));
      $response = $request->sendRequest();

      if ($response->getResultCode() != 'Ok') {
        $this->logResponse($response);
        $this->messenger->addError($this->describeResponse($response));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['api_login'] = $values['api_login'];
      $this->configuration['transaction_key'] = $values['transaction_key'];
      $this->configuration['client_key'] = $values['client_key'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['authorization']);
    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();

    $request = new CreateTransactionRequest($this->authnetConfiguration, $this->httpClient);
    $request->setTransactionRequest(new TransactionRequest([
      'transactionType' => TransactionRequest::PRIOR_AUTH_CAPTURE,
      'amount' => $amount->getNumber(),
      'refTransId' => $payment->getRemoteId(),
    ]));
    $response = $request->execute();

    if ($response->getResultCode() != 'Ok') {
      $this->logResponse($response);
      $message = $response->getMessages()[0];
      throw new PaymentGatewayException($message->getText());
    }

    $payment->setState('completed');
    $payment->setAmount($amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    $this->assertPaymentState($payment, ['authorization']);

    $request = new CreateTransactionRequest($this->authnetConfiguration, $this->httpClient);
    $request->setTransactionRequest(new TransactionRequest([
      'transactionType' => TransactionRequest::VOID,
      'amount' => $payment->getAmount()->getNumber(),
      'refTransId' => $payment->getRemoteId(),
    ]));
    $response = $request->execute();

    if ($response->getResultCode() != 'Ok') {
      $this->logResponse($response);
      $message = $response->getMessages()[0];
      throw new PaymentGatewayException($message->getText());
    }

    $payment->setState('authorization_voided');
    $payment->save();
  }

  /**
   * {@inheritdoc}
   *
   * @todo Needs kernel test
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    $owner = $payment_method->getOwner();
    $customer_id = $this->getRemoteCustomerId($owner);
    if (empty($customer_id)) {
      $customer_id = $this->getPaymentMethodCustomerId($payment_method);
    }

    $request = new DeleteCustomerPaymentProfileRequest($this->authnetConfiguration, $this->httpClient);
    $request->setCustomerProfileId($customer_id);
    $request->setCustomerPaymentProfileId($this->getRemoteProfileId($payment_method));
    $response = $request->execute();

    if ($response->getResultCode() != 'Ok') {
      $this->logResponse($response);
      $message = $response->getMessages()[0];
      // If the error is not "record not found" throw an error.
      if ($message->getCode() != 'E00040') {
        throw new InvalidResponseException("Unable to delete payment method");
      }
    }

    $payment_method->delete();
  }

  /**
   * Writes an API response to the log for debugging.
   *
   * @param \CommerceGuys\AuthNet\Response\ResponseInterface $response
   *   The API response object.
   */
  protected function logResponse(ResponseInterface $response) {
    $message = $this->describeResponse($response);
    $level = $response->getResultCode() === 'Error' ? 'error' : 'info';
    $this->logger->log($level, $message);
  }

  /**
   * Returns the customer identifier from a payment method's remote id.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method.
   * @return mixed
   *   The remote customer id or FALSE if it cannot be resolved.
   */
  public function getPaymentMethodCustomerId(PaymentMethodInterface $payment_method) {
    $remote_id = $payment_method->getRemoteId();
    if (strstr($remote_id, '|')) {
      $ids = explode('|', $remote_id);
      return reset($ids);
    }
    return FALSE;
  }

  /**
   * Returns the payment method remote identifier ensuring customer identifier is removed.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method.
   * @return string
   *   The remote id.
   */
  public function getRemoteProfileId(PaymentMethodInterface $payment_method) {
    $remote_id = $payment_method->getRemoteId();
    $ids = explode('|', $remote_id);
    return end($ids);
  }

  /**
   * Formats an API response as a string.
   *
   * @param \CommerceGuys\AuthNet\Response\ResponseInterface $response
   *   The API response object.
   *
   * @return string
   *   The message.
   */
  protected function describeResponse(ResponseInterface $response) {
    $messages = [];
    foreach ($response->getMessages() as $message) {
      $messages[] = $message->getCode() . ': ' . $message->getText();
    }

    return $this->t('Received response with code %code from Authorize.net: @messages', [
      '%code' => $response->getResultCode(),
      '@messages' => implode("\n", $messages),
    ]);
  }

  /**
   * Gets the line items from order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \CommerceGuys\AuthNet\DataTypes\LineItem[]
   *   An array of line items.
   */
  protected function getLineItems(OrderInterface $order) {
    $line_items = [];
    foreach ($order->getItems() as $order_item) {
      $name = $order_item->label();
      $name = (strlen($name) > 31) ? substr($name, 0, 28) . '...' : $name;

      $line_items[] = new LineItem([
        'itemId' => $order_item->id(),
        'name' => $name,
        'quantity' => $order_item->getQuantity(),
        'unitPrice' => $order_item->getUnitPrice()->getNumber(),
      ]);
    }

    return $line_items;
  }

  /**
   * Gets the tax from order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \CommerceGuys\AuthNet\DataTypes\Tax
   *   The total tax.
   */
  protected function getTax(OrderInterface $order) {
    $amount = '0';
    $labels = [];

    $adjustments = $order->collectAdjustments();
    if ($adjustments) {
      $adjustments = $this->adjustmentTransformer->combineAdjustments($adjustments);
      $adjustments = $this->adjustmentTransformer->roundAdjustments($adjustments);
      foreach ($adjustments as $adjustment) {
        if ($adjustment->getType() !== 'tax') {
          continue;
        }
        $amount = Calculator::add($amount, $adjustment->getAmount()->getNumber());
        $labels[] = $adjustment->getLabel();
      }
    }

    // Determine whether multiple tax types are present.
    $labels = array_unique($labels);
    if (empty($labels)) {
      $name = '';
      $description = '';
    }
    elseif (count($labels) > 1) {
      $name = 'Multiple Tax Types';
      $description = implode(', ', $labels);
    }
    else {
      $name = $labels[0];
      $description = $labels[0];
    }

    // Limit name, description fields to 32, 255 characters.
    $name = (strlen($name) > 31) ? substr($name, 0, 28) . '...' : $name;
    $description = (strlen($description) > 255) ? substr($description, 0, 252) . '...' : $description;

    // If amount is negative, do not transmit any information.
    if ($amount < 0) {
      $amount = 0;
      $name = '';
      $description = '';
    }

    return new Tax([
      'amount' => $amount,
      'name' => $name,
      'description' => $description,
    ]);
  }

  /**
   * Gets the shipping from order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \CommerceGuys\AuthNet\DataTypes\Shipping
   *   The total shipping.
   */
  protected function getShipping(OrderInterface $order) {
    // Return empty if there is no shipments field.
    if (!$order->hasField('shipments')) {
      return new Shipping([
        'amount' => 0,
        'name' => '',
        'description' => '',
      ]);
    }

    $amount = '0';
    $labels = [];

    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface[] $shipments */
    $shipments = $order->get('shipments')->referencedEntities();
    if ($shipments) {
      foreach ($shipments as $shipment) {
        // Shipments without an amount are incomplete / unrated.
        if ($shipment_amount = $shipment->getAmount()) {
          $amount = Calculator::add($amount, $shipment_amount->getNumber());
          $labels[] = $shipment->label();
        }
      }
    }

    // Determine whether multiple tax types are present.
    $labels = array_unique($labels);
    if (empty($labels)) {
      $name = '';
      $description = '';
    }
    elseif (count($labels) > 1) {
      $name = 'Multiple shipments';
      $description = implode(', ', $labels);
    }
    else {
      $name = $labels[0];
      $description = $labels[0];
    }

    // Limit name, description fields to 32, 255 characters.
    $name = (strlen($name) > 31) ? substr($name, 0, 28) . '...' : $name;
    $description = (strlen($description) > 255) ? substr($description, 0, 252) . '...' : $description;
    return new Shipping([
      'amount' => $amount,
      'name' => $name,
      'description' => $description,
    ]);
  }

}
