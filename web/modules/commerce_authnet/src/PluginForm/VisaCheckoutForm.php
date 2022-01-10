<?php

namespace Drupal\commerce_authnet\PluginForm;

use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;

class VisaCheckoutForm extends PaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;

    $form['authnet_visa']['#type'] = 'container';
    $form['authnet_visa']['#attributes']['class'][] = 'authorize-net-accept-js-form';
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayBase $plugin */
    $plugin = $this->plugin;

    $form['authnet_visa']['#attached']['library'][] = 'commerce_authnet/form-visa-checkout';
    if ($plugin->getMode() == 'test') {
      $form['authnet_visa']['#attached']['library'][] = 'commerce_authnet/form-visa-checkout-sandbox';
      $image_src = 'https://sandbox.secure.checkout.visa.com/wallet-services-web/xo/button.png';
    }
    else {
      $form['authnet_visa']['#attached']['library'][] = 'commerce_authnet/form-visa-checkout-production';
      $image_src = 'https://secure.checkout.visa.com/wallet-services-web/xo/button.png';
    }
    $order = $payment->getOrder();
    $current_checkout_step = $order->get('checkout_step')->getValue()[0]['value'];
    /** @var \Drupal\commerce_checkout\Entity\CheckoutFlowInterface $checkout_flow */
    $checkout_flow = $order->get('checkout_flow')->entity;
    $next_step = $checkout_flow->getPlugin()->getNextStepId($current_checkout_step);

    $form['authnet_visa']['#attached']['drupalSettings']['commerceAuthorizeNet'] = [
      'visaApiKey' => $plugin->getConfiguration()['visa_checkout_api_key'],
      'paymentMethodType' => 'authnet_visa',
      'currencyCode' => $payment->getAmount()->getCurrencyCode(),
      'number' => $payment->getAmount()->getNumber(),
      'successUrl' => $form['#return_url'],
      'errorUrl' => $form['#return_url'],
      'cancelUrl' => $form['#cancel_url'],
      'nextCheckoutStepUrl' => '/checkout/' . $order->id() . '/' . $next_step,
    ];

    $form['authnet_visa']['visa_button'] = [
      '#type' => 'html_tag',
      '#tag' => 'img',
      '#attributes' => [
        'alt' => 'Visa Checkout',
        'role' => 'button',
        'class' => ['v-button'],
        'src' => $image_src,
      ],
    ];
    return $form;
  }

}
