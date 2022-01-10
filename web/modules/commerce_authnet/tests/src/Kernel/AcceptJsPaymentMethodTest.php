<?php

namespace Drupal\Tests\commerce_authnet\Kernel;

use CommerceGuys\AuthNet\Configuration;
use CommerceGuys\AuthNet\DataTypes\MerchantAuthentication;
use CommerceGuys\AuthNet\GetCustomerPaymentProfileRequest;
use CommerceGuys\AuthNet\Request\JsonRequest;
use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Drupal\profile\Entity\Profile;
use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use Drupal\user\Entity\User;

/**
 * Tests creating a payment method with AcceptJS.
 *
 * @group commerce_authnet
 */
class AcceptJsPaymentMethodTest extends OrderKernelTestBase implements ServiceModifierInterface {

  /**
   * The payment gateway.
   *
   * @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface
   */
  protected $gateway;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'commerce_payment',
    'commerce_authnet',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_payment');
    $this->installEntitySchema('commerce_payment_method');
    $this->installConfig('commerce_payment');

    /** @var \Drupal\commerce_payment\Entity\PaymentGateway $gateway */
    $gateway = PaymentGateway::create([
      'id' => 'authorizenet_acceptjs',
      'label' => 'Authorize.net AcceptJS',
      'plugin' => 'authorizenet_acceptjs',
    ]);
    $gateway->getPlugin()->setConfiguration([
      'api_login' => '64EZ77a2w8',
      'transaction_key' => '2rrbVvBR6949En2d',
      'client_key' => '2fejMFQEzA2cg6C5wV3Kz398S94XkPbS56RU2Zq2tfjcmDhDVp8h8XmZ49JQLbY6',
      'mode' => 'test',
      'payment_method_types' => ['credit_card'],
    ]);
    $gateway->save();
    $this->gateway = $gateway;
  }

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $container->removeDefinition('test.http_client.middleware');
  }

  /**
   * Creates an API configuration.
   *
   * @return \CommerceGuys\AuthNet\Configuration
   *   The API configuration.
   */
  protected function createApiConfiguration() {
    $configuration = $this->gateway->getPlugin()->getConfiguration();
    return new Configuration([
      'sandbox' => ($this->gateway->getPlugin()->getMode() == 'test'),
      'api_login' => $configuration['api_login'],
      'transaction_key' => $configuration['transaction_key'],
      'client_key' => $configuration['client_key'],
      'request_mode' => 'json',
    ]);
  }

  /**
   * Creates data descriptor information.
   *
   * Replicates the JS calls.
   *
   * @link https://community.developer.authorize.net/t5/Integration-and-Testing/Accept-JS-and-Integration-Testing/td-p/57232
   *
   * @return object
   *   The response.
   *
   * @throws \CommerceGuys\AuthNet\Exception\AuthNetException
   */
  protected function createDataDescriptor() {
    $configuration = $this->createApiConfiguration();
    $request = new JsonRequest(
      $configuration,
      $this->container->get('http_client'),
      'securePaymentContainerRequest'
    );
    $request->addDataType(new MerchantAuthentication([
      'name' => $configuration->getApiLogin(),
      'transactionKey' => $configuration->getTransactionKey(),
    ]));
    $request->addData('refId', '12345');
    $request->addData('data', [
      'type' => 'TOKEN',
      'id' => $this->randomString(),
      'token' => [
        'cardNumber' => '5424000000000015',
        'expirationDate' => '122027',
        'cardCode' => '900',
        'fullName' => 'Testy McTesterson',
      ],
    ]);

    $response = $request->sendRequest();
    $this->assertTrue($response->getResultCode() == 'Ok');
    $opaque_data = $response->opaqueData;
    $this->assertNotEmpty($opaque_data);
    return $opaque_data;
  }

  /**
   * Tests creating the payment method for an authenticated users.
   */
  public function testCreatePaymentMethodForAuthenticated() {
    $user = $this->createUser(['mail' => $this->randomString() . '@example.com']);
    $profile = Profile::create([
      'type' => 'customer',
      'address' => [
        'country_code' => 'US',
        'postal_code' => '53177',
        'locality' => 'Milwaukee',
        'address_line1' => 'Pabst Blue Ribbon Dr',
        'administrative_area' => 'WI',
        'given_name' => 'Frederick',
        'family_name' => 'Pabst',
      ],
      'uid' => $user->id(),
    ]);
    $profile->save();

    /** @var \Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway\AcceptJs $plugin */
    $plugin = $this->gateway->getPlugin();
    $opaque_data = $this->createDataDescriptor();

    /** @var \Drupal\commerce_payment\PaymentMethodStorageInterface $payment_method_storage */
    $payment_method_storage = $this->container->get('entity_type.manager')->getStorage('commerce_payment_method');
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $payment_method = $payment_method_storage->create([
      'type' => 'credit_card',
      'payment_gateway' => $this->gateway->id(),
      'uid' => $user->id(),
      'billing_profile' => $profile,
    ]);

    $plugin->createPaymentMethod(
      $payment_method,
      [
        'data_descriptor' => $opaque_data->dataDescriptor,
        'data_value' => $opaque_data->dataValue,
        'last4' => '0015',
        'expiration_month' => '12',
        'expiration_year' => '2027',
      ]
    );
    $this->assertNotEmpty($payment_method->id());
    $this->assertEquals('mastercard', $payment_method->card_type->value);
  }

  /**
   * Tests creating the payment method for an anonymous users.
   */
  public function testCreatePaymentMethodForAnonymous() {
    $user = User::getAnonymousUser();
    $profile = Profile::create([
      'type' => 'customer',
      'address' => [
        'country_code' => 'US',
        'postal_code' => '53177',
        'locality' => 'Milwaukee',
        'address_line1' => 'Pabst Blue Ribbon Dr',
        'administrative_area' => 'WI',
        'given_name' => 'Frederick',
        'family_name' => 'Pabst',
      ],
      'uid' => $user,
    ]);
    $profile->save();

    /** @var \Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway\AcceptJs $plugin */
    $plugin = $this->gateway->getPlugin();
    $opaque_data = $this->createDataDescriptor();

    /** @var \Drupal\commerce_payment\PaymentMethodStorageInterface $payment_method_storage */
    $payment_method_storage = $this->container->get('entity_type.manager')->getStorage('commerce_payment_method');
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $payment_method = $payment_method_storage->create([
      'type' => 'credit_card',
      'payment_gateway' => $this->gateway->id(),
      'uid' => $user,
      'billing_profile' => $profile,
    ]);

    $plugin->createPaymentMethod(
      $payment_method,
      [
        'data_descriptor' => $opaque_data->dataDescriptor,
        'data_value' => $opaque_data->dataValue,
        'customer_email' => $this->randomString() . '@example.com',
        'last4' => '0015',
        'expiration_month' => '12',
        'expiration_year' => '2027',
      ]
    );
    $this->assertNotEmpty($payment_method->id());
    $this->assertEquals('mastercard', $payment_method->card_type->value);
  }

  /**
   * Tests creating a payment method which has the delimeter in the address.
   */
  public function testWithDataWhichHasDelimeter() {
    $user = User::getAnonymousUser();
    $profile = Profile::create([
      'type' => 'customer',
      'address' => [
        'country_code' => 'US',
        'postal_code' => '53177',
        'locality' => 'Milwaukee',
        'address_line1' => 'Pabst, Blue Ribbon Dr',
        'administrative_area' => 'WI',
        'given_name' => 'Frederick',
        'family_name' => 'Pabst',
      ],
      'uid' => $user,
    ]);
    $profile->save();

    /** @var \Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway\AcceptJs $plugin */
    $plugin = $this->gateway->getPlugin();
    $opaque_data = $this->createDataDescriptor();

    /** @var \Drupal\commerce_payment\PaymentMethodStorageInterface $payment_method_storage */
    $payment_method_storage = $this->container->get('entity_type.manager')->getStorage('commerce_payment_method');
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $payment_method = $payment_method_storage->create([
      'type' => 'credit_card',
      'payment_gateway' => $this->gateway->id(),
      'uid' => $user,
      'billing_profile' => $profile,
    ]);

    $plugin->createPaymentMethod(
      $payment_method,
      [
        'data_descriptor' => $opaque_data->dataDescriptor,
        'data_value' => $opaque_data->dataValue,
        'customer_email' => $this->randomString() . '@example.com',
        'last4' => '0015',
        'expiration_month' => '12',
        'expiration_year' => '2027',
      ]
    );
    $this->assertNotEmpty($payment_method->id());
    $this->assertEquals('mastercard', $payment_method->card_type->value);
  }

  /**
   * Tests creating a duplicate profile.
   *
   * @link Here is a list of what deems things as a duplicate: https://developer.authorize.net/api/reference/features/customer_profiles.html#Duplicate_Profile_Verification
   */
  public function testDuplicateProfiles() {
    // Force the uid and email to match so the customer profile is flagged
    // as being a duplicate.
    $user = $this->createUser([
      'uid' => 10,
      'mail' => 'fred.pabst@example.com',
    ]);
    $profile = Profile::create([
      'type' => 'customer',
      'address' => [
        'country_code' => 'US',
        'postal_code' => '53177',
        'locality' => 'Milwaukee',
        'address_line1' => 'Pabst Blue Ribbon Dr',
        'administrative_area' => 'WI',
        'given_name' => 'Frederick',
        'family_name' => 'Pabst',
      ],
      'uid' => $user->id(),
    ]);
    $profile->save();

    /** @var \Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway\AcceptJs $plugin */
    $plugin = $this->gateway->getPlugin();
    $opaque_data = $this->createDataDescriptor();

    /** @var \Drupal\commerce_payment\PaymentMethodStorageInterface $payment_method_storage */
    $payment_method_storage = $this->container->get('entity_type.manager')->getStorage('commerce_payment_method');
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $payment_method = $payment_method_storage->create([
      'type' => 'credit_card',
      'payment_gateway' => $this->gateway->id(),
      'uid' => $user,
      'billing_profile' => $profile,
    ]);

    $plugin->createPaymentMethod(
      $payment_method,
      [
        'data_descriptor' => $opaque_data->dataDescriptor,
        'data_value' => $opaque_data->dataValue,
        'last4' => '0015',
        'expiration_month' => '12',
        'expiration_year' => '2027',
      ]
    );
    $this->assertNotEmpty($payment_method->id());
    $this->assertEquals('mastercard', $payment_method->card_type->value);
  }

  /**
   * Tests creating the payment method for an authenticated users.
   */
  public function testUpdatePaymentMethod() {
    $user = $this->createUser(['mail' => $this->randomString() . '@example.com']);
    $profile = Profile::create([
      'type' => 'customer',
      'address' => [
        'country_code' => 'US',
        'postal_code' => '53177',
        'locality' => 'Milwaukee',
        'address_line1' => 'Pabst Blue Ribbon Dr',
        'administrative_area' => 'WI',
        'given_name' => 'Frederick',
        'family_name' => 'Pabst',
      ],
      'uid' => $user->id(),
    ]);
    $profile->save();

    /** @var \Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway\AcceptJs $plugin */
    $plugin = $this->gateway->getPlugin();
    $opaque_data = $this->createDataDescriptor();

    /** @var \Drupal\commerce_payment\PaymentMethodStorageInterface $payment_method_storage */
    $payment_method_storage = $this->container->get('entity_type.manager')->getStorage('commerce_payment_method');
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $payment_method = $payment_method_storage->create([
      'type' => 'credit_card',
      'payment_gateway' => $this->gateway->id(),
      'uid' => $user->id(),
      'billing_profile' => $profile,
    ]);

    $plugin->createPaymentMethod(
      $payment_method,
      [
        'data_descriptor' => $opaque_data->dataDescriptor,
        'data_value' => $opaque_data->dataValue,
        'last4' => '0015',
        'expiration_month' => '12',
        'expiration_year' => '2027',
      ]
    );
    $this->assertNotEmpty($payment_method->id());
    $this->assertEquals('mastercard', $payment_method->card_type->value);

    // Test the update.
    $payment_method->get('card_exp_month')->setValue('02');
    $payment_method->get('card_exp_year')->setValue('2028');
    $expires = CreditCard::calculateExpirationTimestamp('02', '2028');
    $payment_method->setExpiresTime($expires);

    $plugin->updatePaymentMethod($payment_method);

    // Manually get the customer payment profile and verify it was updated.
    $user = $this->reloadEntity($user);
    /** @var \Drupal\commerce\Plugin\Field\FieldType\RemoteIdFieldItemListInterface $remote_ids */
    $remote_ids = $user->get('commerce_remote_id');
    $customer_remote_id = $remote_ids->getByProvider($this->gateway->id() . '|' . $plugin->getMode());
    $configuration = $this->createApiConfiguration();
    $request = new GetCustomerPaymentProfileRequest($configuration, $this->container->get('http_client'));
    $request->setCustomerProfileId($customer_remote_id);
    $request->setCustomerPaymentProfileId($plugin->getRemoteProfileId($payment_method));
    $request->setUnmaskExpirationDate(TRUE);

    $response = $request->execute();
    $this->assertEquals('XXXX0015', $response->paymentProfile->payment->creditCard->cardNumber);
    $this->assertEquals('2028-02', $response->paymentProfile->payment->creditCard->expirationDate);

    // Assert the billing information was not lost.
    $this->assertEquals((object) [
      'firstName' => 'Frederick',
      'lastName' => 'Pabst',
      'address' => 'Pabst Blue Ribbon Dr',
      'city' => 'Milwaukee',
      'state' => 'WI',
      'zip' => '53177',
      'country' => 'US',
    ], $response->paymentProfile->billTo);
  }

}
