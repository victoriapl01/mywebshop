<?php

namespace Drupal\commerce_paypal\Plugin\Commerce\CheckoutFlow;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowWithPanesBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a custom checkout flow for use by PayPal Checkout.
 *
 * @CommerceCheckoutFlow(
 *   id = "paypal_checkout",
 *   label = "PayPal Checkout",
 * )
 */
class PayPalCheckout extends CheckoutFlowWithPanesBase {

  /**
   * {@inheritdoc}
   */
  public function getSteps() {
    // Note that previous_label and next_label are not the labels
    // shown on the step itself. Instead, they are the labels shown
    // when going back to the step, or proceeding to the step.
    return [
      'order_information' => [
        'label' => $this->t('Order information'),
        'has_sidebar' => TRUE,
        'previous_label' => $this->t('Go back'),
      ],
      'review' => [
        'label' => $this->t('Review'),
        'next_label' => $this->t('Continue to review'),
        'previous_label' => $this->t('Go back'),
        'has_sidebar' => TRUE,
      ],
    ] + parent::getSteps();
  }

  /**
   * {@inheritdoc}
   */
  public function getPanes() {
    $panes = parent::getPanes();
    // Create a blacklist of panes we disallow adding to steps.
    $black_list = [
      'contact_information',
      'payment_information',
      'payment_process',
    ];
    return array_diff_key($panes, array_combine($black_list, $black_list));
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    $pane_values = $values['panes'];
    if (!isset($pane_values['paypal_checkout_payment_process']) ||
      $pane_values['paypal_checkout_payment_process']['step_id'] !== 'payment') {
      $pane = $this->getPane('paypal_checkout_payment_process');
      $form_state->setError($form['panes'], $this->t('The %title pane must be configured in the payment region.', ['%title' => $pane->getLabel()]));
    }
  }

}
