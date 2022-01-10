<?php

namespace Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway;

use CommerceGuys\AuthNet\DataTypes\CreditCard as AuthnetCreditCard;
use CommerceGuys\AuthNet\UpdateCustomerPaymentProfileRequest;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_price\Price;
use CommerceGuys\AuthNet\CreateCustomerPaymentProfileRequest;
use CommerceGuys\AuthNet\CreateCustomerProfileRequest;
use CommerceGuys\AuthNet\CreateTransactionRequest;
use CommerceGuys\AuthNet\UpdateHeldTransactionRequest;
use CommerceGuys\AuthNet\DataTypes\BillTo;
use CommerceGuys\AuthNet\DataTypes\CardholderAuthentication;
use CommerceGuys\AuthNet\DataTypes\CreditCard as CreditCardDataType;
use CommerceGuys\AuthNet\DataTypes\LineItem;
use CommerceGuys\AuthNet\DataTypes\Order as OrderDataType;
use CommerceGuys\AuthNet\DataTypes\OpaqueData;
use CommerceGuys\AuthNet\DataTypes\PaymentProfile;
use CommerceGuys\AuthNet\DataTypes\Profile;
use CommerceGuys\AuthNet\DataTypes\TransactionRequest;
use CommerceGuys\AuthNet\DataTypes\ShipTo;
use Drupal\Core\Form\FormStateInterface;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer\Hmac\Sha256;

/**
 * Provides the Accept.js payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "authorizenet_acceptjs",
 *   label = "Authorize.net (Accept.js)",
 *   display_label = "Authorize.net",
 *   forms = {
 *     "add-payment-method" = "Drupal\commerce_authnet\PluginForm\AcceptJs\PaymentMethodAddForm",
 *     "approve-payment" = "Drupal\commerce_authnet\PluginForm\AcceptJs\PaymentApproveForm",
 *     "decline-payment" = "Drupal\commerce_authnet\PluginForm\AcceptJs\PaymentDeclineForm",
 *   },
 *   payment_type = "acceptjs",
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "mastercard", "visa", "unionpay"
 *   },
 *   requires_billing_information = FALSE,
 * )
 */
class AcceptJs extends OnsiteBase implements AcceptJsInterface {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'cca_status' => FALSE,
      'cca_api_id' => '',
      'cca_org_unit_id' => '',
      'cca_api_key' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['cca_status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Cardinal Cruise Authentication'),
      '#default_value' => $this->configuration['cca_status'],
    ];
    $form['cca'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Cardinal Cruise Authentication'),
      '#states' => [
        'visible' => [
          'input[name="configuration[authorizenet_acceptjs][cca_status]"]' => [
            'checked' => TRUE,
          ],
        ],
      ],
      '#description' => $this->t('In test mode the credentials provided here are not used (but the fields are still required).'),
    ];

    $form['cca']['cca_api_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Identifier'),
      '#default_value' => $this->configuration['cca_api_id'],
      '#states' => [
        'required' => [
          'input[name="configuration[authorizenet_acceptjs][cca_status]"]' => [
            'checked' => TRUE,
          ],
        ],
      ],
    ];

    $form['cca']['cca_org_unit_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Org Unit ID'),
      '#default_value' => $this->configuration['cca_org_unit_id'],
      '#states' => [
        'required' => [
          'input[name="configuration[authorizenet_acceptjs][cca_status]"]' => [
            'checked' => TRUE,
          ],
        ],
      ],
    ];

    $form['cca']['cca_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key'),
      '#default_value' => $this->configuration['cca_api_key'],
      '#states' => [
        'required' => [
          'input[name="configuration[authorizenet_acceptjs][cca_status]"]' => [
            'checked' => TRUE,
          ],
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['cca_status'] = $values['cca_status'];
      $this->configuration['cca_api_id'] = $values['cca']['cca_api_id'];
      $this->configuration['cca_org_unit_id'] = $values['cca']['cca_org_unit_id'];
      $this->configuration['cca_api_key'] = $values['cca']['cca_api_key'];
    }
  }

  /**
   * Get the CCA API Identifier.
   *
   * @return string
   *   The CCA API Identifier.
   */
  public function getCcaApiId() {
    if ($this->configuration['cca_status']) {
      // Test API Id.
      // @see https://developer.cardinalcommerce.com/try-it-now.shtml
      if ($this->configuration['mode'] == 'test') {
        return '582e0a2033fadd1260f990f6';
      }
      else {
        return $this->configuration['cca_api_id'];
      }
    }
  }

  /**
   * Get the CCA API Identifier.
   *
   * @return string
   *   The CCA API Identifier.
   */
  public function getCcaOrgUnitId() {
    if ($this->configuration['cca_status']) {
      // Test Org Unit ID.
      // @see https://developer.cardinalcommerce.com/try-it-now.shtml
      if ($this->configuration['mode'] == 'test') {
        return '582be9deda52932a946c45c4';
      }
      else {
        return $this->configuration['cca_org_unit_id'];
      }
    }
  }

  /**
   * Get the CCA API Key.
   *
   * @return string
   *   The CCA API Key.
   */
  public function getCcaApiKey() {
    if ($this->configuration['cca_status']) {
      // Test API Key.
      // @see https://developer.cardinalcommerce.com/try-it-now.shtml
      if ($this->configuration['mode'] == 'test') {
        return '754be3dc-10b7-471f-af31-f20ce12b9ec1';
      }
      else {
        return $this->configuration['cca_api_key'];
      }
    }
  }

  /**
   * {@inheritDoc}
   */
  public function getJsLibrary() {
    if ($this->configuration['cca_status']) {
      if ($this->getMode() === 'test') {
        return 'commerce_authnet/cardinalcruise-dev';
      }
      return 'commerce_authnet/cardinalcruise';
    }
    return 'commerce_authnet/form-accept';
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    $this->assertPaymentState($payment, ['new']);
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentMethod($payment_method);

    $order = $payment->getOrder();
    $owner = $payment_method->getOwner();

    // Transaction request.
    $transaction_request = new TransactionRequest([
      'transactionType' => ($capture) ? TransactionRequest::AUTH_CAPTURE : TransactionRequest::AUTH_ONLY,
      'amount' => $payment->getAmount()->getNumber(),
    ]);

    $tempstore_3ds = $this->privateTempStore->get('commerce_authnet')->get($payment_method->id());
    if (!empty($tempstore_3ds)) {
      // Do not send ECI and CAVV values when reusing a payment method.
      $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
      $payment_method_has_been_used = $payment_storage->getQuery()
        ->condition('payment_method', $payment_method->id())
        ->range(0, 1)
        ->execute();
      if (!$payment_method_has_been_used) {
        $cardholder_authentication = new CardholderAuthentication();
        $cardholder_authentication_empty = TRUE;
        if (!empty($tempstore_3ds['eci']) && $tempstore_3ds['eci'] != '07') {
          $cardholder_authentication->authenticationIndicator = $tempstore_3ds['eci'];
          $cardholder_authentication_empty = FALSE;
        }
        if (!empty($tempstore_3ds['cavv'])) {
          // This is quite undocumented, but seems that cavv needs to be
          // urlencoded.
          // @see https://community.developer.authorize.net/t5/Integration-and-Testing/Cardholder-Authentication-extraOptions-invalid-error/td-p/57955
          $cardholder_authentication->cardholderAuthenticationValue = urlencode($tempstore_3ds['cavv']);
          $cardholder_authentication_empty = FALSE;
        }
        if (!$cardholder_authentication_empty) {
          $transaction_request->addDataType($cardholder_authentication);
        }
      }
      else {
        $this->privateTempStore->get('commerce_authnet')->delete($payment_method->id());
      }
    }

    // @todo update SDK to support data type like this.
    // Initializing the profile to charge and adding it to the transaction.
    $customer_profile_id = $this->getRemoteCustomerId($owner);
    if (empty($customer_profile_id)) {
      $customer_profile_id = $this->getPaymentMethodCustomerId($payment_method);
    }
    $payment_profile_id = $this->getRemoteProfileId($payment_method);
    $profile_to_charge = new Profile(['customerProfileId' => $customer_profile_id]);
    $profile_to_charge->addData('paymentProfile', ['paymentProfileId' => $payment_profile_id]);
    $transaction_request->addData('profile', $profile_to_charge->toArray());
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

        case 'E00042':
          $payment_method->delete();
          throw new PaymentGatewayException('You cannot add more than 10 payment methods.');

        default:
          throw new PaymentGatewayException($message->getText());
      }
    }

    if (!empty($response->getErrors())) {
      $message = $response->getErrors()[0];
      throw new HardDeclineException($message->getText());
    }

    // Select the next state based on fraud detection results.
    $code = $response->getMessageCode();
    $expires = 0;
    $next_state = 'authorization';
    if ($code == 1 && $capture) {
      $next_state = 'completed';
    }
    // Do not authorize, but hold for review.
    elseif ($code == 252) {
      $next_state = 'unauthorized_review';
      $expires = strtotime('+5 days');
    }
    // Authorized, but hold for review.
    elseif ($code == 253) {
      $next_state = 'authorization_review';
      $expires = strtotime('+5 days');
    }
    $payment->setExpiresTime($expires);
    $payment->setState($next_state);
    $payment->setRemoteId($response->transactionResponse->transId);
    $payment->setAvsResponseCode($response->transactionResponse->avsResultCode);
    // @todo Find out how long an authorization is valid, set its expiration.
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function approvePayment(PaymentInterface $payment) {
    /** @var \Drupal\commerce_payment\Entity\PaymentMethod $payment_method */
    $this->assertPaymentState($payment, ['unauthorized_review', 'authorization_review']);
    if ($payment->isExpired()) {
      throw new HardDeclineException('This payment has expired.');
    }

    $request = new UpdateHeldTransactionRequest($this->authnetConfiguration, $this->httpClient);
    $request->setAction(UpdateHeldTransactionRequest::APPROVE);
    $request->setRefTransId($payment->getRemoteId());
    $response = $request->execute();

    if ($response->getResultCode() != 'Ok') {
      $this->logResponse($response);
      $message = $response->getMessages()[0];
      throw new PaymentGatewayException($message->getText());
    }

    $new_state = $payment->getState()->getId() == 'unauthorized_review' ? 'authorization' : 'completed';
    $payment->setState($new_state);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function declinePayment(PaymentInterface $payment) {
    /** @var \Drupal\commerce_payment\Entity\PaymentMethod $payment_method */
    $this->assertPaymentState($payment, ['unauthorized_review', 'authorization_review']);
    if ($payment->isExpired()) {
      throw new HardDeclineException('This payment has expired.');
    }

    $request = new UpdateHeldTransactionRequest($this->authnetConfiguration, $this->httpClient);
    $request->setAction(UpdateHeldTransactionRequest::DECLINE);
    $request->setRefTransId($payment->getRemoteId());
    $response = $request->execute();

    if ($response->getResultCode() != 'Ok') {
      $this->logResponse($response);
      $message = $response->getMessages()[0];
      throw new PaymentGatewayException($message->getText());
    }

    $new_state = $payment->getState()->getId() == 'unauthorized_review' ? 'unauthorized_declined' : 'authorization_declined';
    $payment->setState($new_state);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    /** @var \Drupal\commerce_payment\Entity\PaymentMethod $payment_method */
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $this->assertRefundAmount($payment, $amount);

    $request = new CreateTransactionRequest($this->authnetConfiguration, $this->httpClient);
    $transaction_request = new TransactionRequest([
      'transactionType' => TransactionRequest::REFUND,
      'amount' => $amount->getNumber(),
      'refTransId' => $payment->getRemoteId(),
    ]);

    // Add billing information when available, to satisfy AVS.
    if ($billing_profile = $payment_method->getBillingProfile()) {
      /** @var \Drupal\address\AddressInterface $address */
      $address = $billing_profile->get('address')->first();
      $bill_to = array_filter([
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
      ]);
      $transaction_request->addDataType(new BillTo($bill_to));
    }

    // Adding order information to the transaction.
    $order = $payment->getOrder();
    $transaction_request->addOrder(new OrderDataType([
      'invoiceNumber' => $order->getOrderNumber() ?: $order->id(),
    ]));
    $transaction_request->addPayment(new CreditCardDataType([
      'cardNumber' => $payment_method->card_number->value,
      'expirationDate' => str_pad($payment_method->card_exp_month->value, 2, '0', STR_PAD_LEFT) . str_pad($payment_method->card_exp_year->value, 2, '0', STR_PAD_LEFT),
    ]));
    $request->setTransactionRequest($transaction_request);
    $response = $request->execute();

    if ($response->getResultCode() != 'Ok') {
      $this->logResponse($response);
      $message = $response->getMessages()[0];
      throw new PaymentGatewayException($message->getText());
    }

    $old_refunded_amount = $payment->getRefundedAmount();
    $new_refunded_amount = $old_refunded_amount->add($amount);
    if ($new_refunded_amount->lessThan($payment->getAmount())) {
      $payment->setState('partially_refunded');
    }
    else {
      $payment->setState('refunded');
    }

    $payment->setRefundedAmount($new_refunded_amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   *
   * @todo Needs kernel test
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    // We don't want 3DS on the user payment method form.
    if (!empty($this->getConfiguration()['cca_status']) && !empty($payment_details['cca_jwt_token'])) {
      if (empty($payment_details['cca_jwt_response_token'])) {
        throw new PaymentGatewayException('Cannot continue when CCA is enabled but not used.');
      }

      /** @var \Lcobucci\JWT\Token $token */
      $token = (new Parser())->parse($payment_details['cca_jwt_response_token']);
      $signer = new Sha256();

      if (!$token->verify($signer, $this->getCcaApiKey())) {
        throw new PaymentGatewayException('Response CCA JWT is not valid.');
      }
      $claims = $token->getClaims();
      /** @var \Lcobucci\JWT\Claim $payload */
      $payload = $claims['Payload'];
      if (isset($payload->getValue()->Payment->ExtendedData->SignatureVerification) && $payload->getValue()->Payment->ExtendedData->SignatureVerification === 'N') {
        throw new PaymentGatewayException('Unsuccessful signature verification.');
      }
    }

    $required_keys = [
      'data_descriptor', 'data_value',
    ];
    foreach ($required_keys as $required_key) {
      if (empty($payment_details[$required_key])) {
        throw new \InvalidArgumentException(sprintf('$payment_details must contain the %s key.', $required_key));
      }
    }
    $remote_payment_method = $this->doCreatePaymentMethod($payment_method, $payment_details);
    $payment_method->card_type = $this->mapCreditCardType($remote_payment_method['card_type']);
    $payment_method->card_number = $remote_payment_method['last4'];
    $payment_method->card_exp_month = $remote_payment_method['expiration_month'];
    $payment_method->card_exp_year = $remote_payment_method['expiration_year'];
    $payment_method->setRemoteId($remote_payment_method['remote_id']);
    $expires = CreditCard::calculateExpirationTimestamp($remote_payment_method['expiration_month'], $remote_payment_method['expiration_year']);
    $payment_method->setExpiresTime($expires);

    $payment_method->save();
    if (!empty($this->getConfiguration()['cca_status']) && !empty($payment_details['cca_jwt_token'])) {
      $value = [];
      if (isset($payload->getValue()->Payment->ExtendedData->CAVV)) {
        $value['cavv'] = $payload->getValue()->Payment->ExtendedData->CAVV;
        $this->privateTempStore->get('commerce_authnet')->set($payment_method->id(), $value);
      }
      if (isset($payload->getValue()->Payment->ExtendedData->ECIFlag)) {
        $value['eci'] = $payload->getValue()->Payment->ExtendedData->ECIFlag;
        $this->privateTempStore->get('commerce_authnet')->set($payment_method->id(), $value);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updatePaymentMethod(PaymentMethodInterface $payment_method) {
    $request = new UpdateCustomerPaymentProfileRequest($this->authnetConfiguration, $this->httpClient);
    $customer_profile_id = $this->getRemoteCustomerId($payment_method->getOwner());
    if (empty($customer_profile_id)) {
      $customer_profile_id = $this->getPaymentMethodCustomerId($payment_method);
    }
    $request->setCustomerProfileId($customer_profile_id);
    $payment_profile = new PaymentProfile([
      'customerType' => 'individual',
    ]);
    $payment_profile->addCustomerPaymentProfileId($this->getRemoteProfileId($payment_method));
    if ($billing_profile = $payment_method->getBillingProfile()) {
      /** @var \Drupal\address\AddressInterface $address */
      $address = $billing_profile->get('address')->first();
      $bill_to = array_filter([
        'firstName' => $address->getGivenName(),
        'lastName' => $address->getFamilyName(),
        'company' => $address->getOrganization(),
        'address' => substr($address->getAddressLine1() . ' ' . $address->getAddressLine2(), 0, 60),
        'country' => $address->getCountryCode(),
        'city' => $address->getLocality(),
        'state' => $address->getAdministrativeArea(),
        'zip' => $address->getPostalCode(),
      ]);
      $payment_profile->addBillTo(new BillTo($bill_to));
    }
    $request->setPaymentProfile($payment_profile);
    $payment_profile->addPayment(new AuthnetCreditCard([
      'cardNumber' => 'XXXX' . $payment_method->get('card_number')->value,
      'expirationDate' => sprintf('%s-%s', $payment_method->get('card_exp_month')->value, $payment_method->get('card_exp_year')->value),
    ]));
    $response = $request->execute();
    if ($response->getResultCode() != 'Ok') {
      $this->logResponse($response);
      $error = $response->getMessages()[0];
      throw new DeclineException('Unable to update the payment method.');
    }
  }

  /**
   * Creates the payment method on the gateway.
   *
   * This handles customer and payment profile creation, along with logic to
   * handle Authorize.net's duplicate profile detection.
   *
   * @link https://developer.authorize.net/api/reference/features/customer_profiles.html#Duplicate_Profile_Verification
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method.
   * @param array $payment_details
   *   The gateway-specific payment details.
   *
   * @return array
   *   The payment method information returned by the gateway. Notable keys:
   *   - token: The remote ID.
   *   Credit card specific keys:
   *   - card_type: The card type.
   *   - last4: The last 4 digits of the credit card number.
   *   - expiration_month: The expiration month.
   *   - expiration_year: The expiration year.
   *
   * @todo Rename to customer profile
   * @todo Make a method for just creating payment profile on existing profile.
   */
  protected function doCreatePaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    $owner = $payment_method->getOwner();
    $customer_profile_id = NULL;
    $customer_data = [];
    if ($owner && !$owner->isAnonymous()) {
      $customer_profile_id = $this->getRemoteCustomerId($owner);
      if (empty($customer_profile_id)) {
        $customer_profile_id = $this->getPaymentMethodCustomerId($payment_method);
      }
      $customer_data['email'] = $owner->getEmail();
    }

    if ($customer_profile_id) {
      $payment_profile = $this->buildCustomerPaymentProfile($payment_method, $payment_details, $customer_profile_id);
      $request = new CreateCustomerPaymentProfileRequest($this->authnetConfiguration, $this->httpClient);
      $request->setCustomerProfileId($customer_profile_id);
      $request->setPaymentProfile($payment_profile);
      $response = $request->execute();

      if ($response->getResultCode() != 'Ok') {
        $this->logResponse($response);
        $error = $response->getMessages()[0];
        switch ($error->getCode()) {
          case 'E00039':
            if (!isset($response->customerPaymentProfileId)) {
              throw new InvalidResponseException('Duplicate payment profile ID, however could not get existing ID.');
            }
            break;

          case 'E00040':
            // The customer record ID is invalid, remove it.
            // @note this should only happen in development scenarios.
            $this->setRemoteCustomerId($owner, NULL);
            $owner->save();
            throw new InvalidResponseException('The customer record could not be found');

          default:
            throw new InvalidResponseException($error->getText());
        }
      }

      $payment_profile_id = $response->customerPaymentProfileId;
      $validation_direct_response = explode(',', $response->validationDirectResponse);
    }
    else {
      $request = new CreateCustomerProfileRequest($this->authnetConfiguration, $this->httpClient);
      if ($owner->isAuthenticated()) {
        $profile = new Profile([
          // @todo how to allow altering.
          'merchantCustomerId' => $owner->id(),
          'email' => $owner->getEmail(),
        ]);
      }
      else {
        $profile = new Profile([
          // @todo how to allow altering.
          'merchantCustomerId' => $owner->id() . '_' . $this->time->getRequestTime(),
          'email' => $payment_details['customer_email'],
        ]);
      }
      $request->setProfile($profile);
      // Due to their being a possible duplicate record for the customer
      // profile, we cannot attach the payment profile in the initial request.
      // If we did, it would invalidate the token generated by Accept.js and
      // not allow us to reconcile duplicate payment methods.
      //
      // So we do not attach a payment profile and run two requests, and make
      // sure no validation mode is executed.
      $request->setValidationMode(NULL);
      $response = $request->execute();

      if ($response->getResultCode() == 'Ok') {
        $customer_profile_id = $response->customerProfileId;
      }
      else {
        // Handle duplicate.
        if ($response->getMessages()[0]->getCode() == 'E00039') {
          $result = array_filter(explode(' ', $response->getMessages()[0]->getText()), 'is_numeric');
          $customer_profile_id = reset($result);
        }
        else {
          $this->logResponse($response);
          throw new InvalidResponseException("Unable to create customer profile.");
        }
      }

      $payment_profile = $this->buildCustomerPaymentProfile($payment_method, $payment_details, $customer_profile_id);
      $request = new CreateCustomerPaymentProfileRequest($this->authnetConfiguration, $this->httpClient);
      $request->setCustomerProfileId($customer_profile_id);
      $request->setPaymentProfile($payment_profile);
      $response = $request->execute();

      if ($response->getResultCode() != 'Ok') {
        // If the error is not due to duplicates, log and error.
        if ($response->getMessages()[0]->getCode() != 'E00039') {
          $this->logResponse($response);
          throw new InvalidResponseException("Unable to create payment profile for existing customer");
        }
      }

      $payment_profile_id = $response->customerPaymentProfileId;
      $validation_direct_response = explode(',', $response->validationDirectResponse);

      if ($owner->isAuthenticated()) {
        $this->setRemoteCustomerId($owner, $customer_profile_id);
        $owner->save();
      }
    }

    // The result in validationDirectResponse does not properly escape any
    // delimiter characters in the response data. So if the address, name, or
    // email have "," the key for the card type is offset.
    //
    // We know the card type is after the XXXX#### masked card number.
    $card_type_key = 51;
    foreach ($validation_direct_response as $key => $value) {
      if (!empty($value) && strpos($value, 'XXXX') !== FALSE) {
        $card_type_key = ($key + 1);
      }
    }

    $remote_id = ($owner->isAuthenticated()) ? $payment_profile_id : $customer_profile_id . '|' . $payment_profile_id;
    return [
      'remote_id' => $remote_id,
      'card_type' => $validation_direct_response[$card_type_key],
      'last4' => $payment_details['last4'],
      'expiration_month' => $payment_details['expiration_month'],
      'expiration_year' => $payment_details['expiration_year'],
    ];
  }

  /**
   * Creates a new customer payment profile in Authorize.net CIM.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method.
   * @param array $payment_details
   *   The gateway-specific payment details.
   * @param string $customer_id
   *   The remote customer ID, if available.
   *
   * @return \CommerceGuys\AuthNet\DataTypes\PaymentProfile
   *   The payment profile data type.
   */
  protected function buildCustomerPaymentProfile(PaymentMethodInterface $payment_method, array $payment_details, $customer_id = NULL) {
    $payment = new OpaqueData([
      'dataDescriptor' => $payment_details['data_descriptor'],
      'dataValue' => $payment_details['data_value'],
    ]);

    $payment_profile = new PaymentProfile([
      // @todo how to allow customizing this.
      'customerType' => 'individual',
    ]);

    if ($billing_profile = $payment_method->getBillingProfile()) {
      /** @var \Drupal\address\AddressInterface $address */
      $address = $billing_profile->get('address')->first();
      $bill_to = array_filter([
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
      ]);
      $payment_profile->addBillTo(new BillTo($bill_to));
    }
    $payment_profile->addPayment($payment);

    return $payment_profile;
  }

  /**
   * Maps the Authorize.Net credit card type to a Commerce credit card type.
   *
   * @param string $card_type
   *   The Authorize.Net credit card type.
   *
   * @return string
   *   The Commerce credit card type.
   */
  protected function mapCreditCardType($card_type) {
    $map = [
      'American Express' => 'amex',
      'Diners Club' => 'dinersclub',
      'Discover' => 'discover',
      'JCB' => 'jcb',
      'MasterCard' => 'mastercard',
      'Visa' => 'visa',
      'China UnionPay' => 'unionpay',
    ];
    if (!isset($map[$card_type])) {
      throw new HardDeclineException(sprintf('Unsupported credit card type "%s".', $card_type));
    }

    return $map[$card_type];
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
   * {@inheritdoc}
   */
  public function buildPaymentOperations(PaymentInterface $payment) {
    $payment_state = $payment->getState()->getId();
    $operations = parent::buildPaymentOperations($payment);
    $operations['approve'] = [
      'title' => $this->t('Approve'),
      'page_title' => $this->t('Approve payment'),
      'plugin_form' => 'approve-payment',
      'access' => in_array($payment_state, ['unauthorized_review', 'authorization_review']),
    ];
    $operations['decline'] = [
      'title' => $this->t('Decline'),
      'page_title' => $this->t('Decline payment'),
      'plugin_form' => 'decline-payment',
      'access' => in_array($payment_state, ['unauthorized_review', 'authorization_review']),
    ];

    return $operations;
  }

}
