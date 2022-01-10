<?php

namespace Drupal\commerce_authnet\PluginForm;

use Drupal\commerce_payment\PluginForm\PaymentMethodAddForm as BasePaymentMethodAddForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_authnet\Plugin\Commerce\PaymentMethodType\AuthorizeNetEcheck;

class EcheckAddForm extends BasePaymentMethodAddForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['payment_details'] = $this->buildEcheckForm($form['payment_details'], $form_state);
    return $form;
  }

  /**
   * Builds the eCheck form.
   */
  public function buildEcheckForm(array $element, FormStateInterface $form_state) {
    // Alter the form with AuthorizeNet Accept JS specific needs.
    $element['#attributes']['class'][] = 'authorize-net-accept-js-form';
    /** @var \Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway\AuthorizeNetInterface $plugin */
    $plugin = $this->plugin;

    if ($plugin->getMode() == 'test') {
      $element['#attached']['library'][] = 'commerce_authnet/accept-js-sandbox';
    }
    else {
      $element['#attached']['library'][] = 'commerce_authnet/accept-js-production';
    }
    $element['#attached']['library'][] = 'commerce_authnet/form-echeck';
    $element['#attached']['drupalSettings']['commerceAuthorizeNet'] = [
      'clientKey' => $plugin->getConfiguration()['client_key'],
      'apiLoginID' => $plugin->getConfiguration()['api_login'],
      'paymentMethodType' => 'authnet_echeck',
    ];

    // To display validation errors.
    $element['payment_errors'] = [
      '#type' => 'markup',
      '#markup' => '<div id="payment-errors"></div>',
      '#weight' => -200,
    ];

    $element['routing_number'] = [
      '#type' => 'textfield',
      '#title' => t('Routing number'),
      '#description' => t("The bank's routing number."),
      '#attributes' => [
        'class' => ['authnet-echeck-routing-number'],
      ],
    ];
    $element['account_number'] = [
      '#type' => 'textfield',
      '#title' => t('Bank account'),
      '#description' => t('The bank account number.'),
      '#attributes' => [
        'class' => ['authnet-echeck-account-number'],
      ],
    ];
    $element['name_on_account'] = [
      '#type' => 'textfield',
      '#title' => t('Name on account'),
      '#description' => t('The name of the person who holds the bank account.'),
      '#attributes' => [
        'class' => ['authnet-echeck-name-on-account'],
      ],
    ];
    $element['account_type'] = [
      '#type' => 'select',
      '#title' => t('Account type'),
      '#description' => t('The type of bank account. Currently only WEB eCheck ACH transactions are supported.'),
      '#options' => AuthorizeNetEcheck::getAccountTypes(),
      '#attributes' => [
        'class' => ['authnet-echeck-account-type'],
      ],
    ];

    // Populated by the JS library after receiving a response from AuthorizeNet.
    $element['data_descriptor'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'class' => ['accept-js-data-descriptor'],
      ],
    ];
    $element['data_value'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'class' => ['accept-js-data-value'],
      ],
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateEcheckForm(array &$element, FormStateInterface $form_state) {
    // The JS library performs its own validation.
  }

  /**
   * {@inheritdoc}
   */
  public function submitEcheckForm(array $element, FormStateInterface $form_state) {
    // The payment gateway plugin will process the submitted payment details.
  }

}
