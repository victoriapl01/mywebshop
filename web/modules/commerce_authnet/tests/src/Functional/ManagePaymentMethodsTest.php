<?php

namespace Drupal\Tests\commerce_authnet\Functional;

use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_store\StoreCreationTrait;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Url;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;

/**
 * Tests the managing Authorize.net payment methods.
 *
 * @group commerce_authnet
 */
class ManagePaymentMethodsTest extends CommerceBrowserTestBase {

  use StoreCreationTrait;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * {@inheritdoc}
   */
  public static $modules = ['commerce_authnet'];

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return array_merge([
      'administer commerce_payment_method',
    ], parent::getAdministratorPermissions());
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->markTestIncomplete();
    parent::setUp();

    /** @var \Drupal\commerce_payment\Entity\PaymentGateway $gateway */
    $gateway = PaymentGateway::create([
      'id' => 'authorize_net_us',
      'label' => 'Authorize.net US',
      'plugin' => 'authorizenet',
    ]);
    $gateway->getPlugin()->setConfiguration([
      'api_login' => '5KP3u95bQpv',
      'transaction_key' => '346HZ32z3fP4hTG2',
      'mode' => 'test',
      'payment_method_types' => ['credit_card'],
    ]);
    $gateway->save();

    // Cheat so we don't need JS to interact w/ Address field widget.
    /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $customer_form_display */
    $customer_form_display = EntityFormDisplay::load('profile.customer.default');
    $address_component = $customer_form_display->getComponent('address');
    $address_component['settings']['default_country'] = 'US';
    $customer_form_display->setComponent('address', $address_component);
    $customer_form_display->save();
  }

  /**
   * Tests than an order can go through checkout steps.
   *
   * @group registered
   */
  public function testAddingPaymentMethod() {
    $this->drupalGet(Url::fromRoute('entity.commerce_payment_method.add_form', [
      'user' => $this->loggedInUser->id(),
    ])->toString());
    $this->assertSession()->pageTextContains('Add payment method');

    $this->submitForm([
      'payment_method[payment_details][number]' => '4111111111111111',
      'payment_method[payment_details][expiration][month]' => '2',
      'payment_method[payment_details][expiration][year]' => '2027',
      'payment_method[payment_details][security_code]' => '123',
      'payment_method[billing_information][address][0][address][given_name]' => 'Johnny',
      'payment_method[billing_information][address][0][address][family_name]' => 'Appleseed',
      'payment_method[billing_information][address][0][address][address_line1]' => '123 New York Drive',
      'payment_method[billing_information][address][0][address][locality]' => 'New York City',
      'payment_method[billing_information][address][0][address][administrative_area]' => 'NY',
      'payment_method[billing_information][address][0][address][postal_code]' => '10001',
    ], 'Save');
    $this->assertSession()->pageTextNotContains('We encountered an error processing your payment method. Please verify your details and try again.');
    $this->assertSession()->pageTextNotContains('We encountered an unexpected error processing your payment method. Please try again later.');

    $html_output = 'GET request to: ' . $this->getSession()->getCurrentUrl() .
      '<hr />Ending URL: ' . $this->getSession()->getCurrentUrl();
    $html_output .= '<hr />' . $this->getSession()->getPage()->getContent();
    $html_output .= $this->getHtmlOutputHeaders();
    $this->htmlOutput($html_output);
  }

}
