<?php

namespace Drupal\commerce_authnet\PluginForm\AcceptJs;

use Drupal\commerce\InlineFormManager;
use Drupal\commerce_payment\PluginForm\PaymentMethodAddForm as BasePaymentMethodAddForm;
use Drupal\commerce_store\CurrentStoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\Calculator;
use Lcobucci\JWT\Signer\Key;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class PaymentMethodAddForm extends BasePaymentMethodAddForm {

  /**
   * The route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs a new PaymentMethodFormBase.
   *
   * @param \Drupal\commerce_store\CurrentStoreInterface $current_store
   *   The current store.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce\InlineFormManager $inline_form_manager
   *   The inline form manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   */
  public function __construct(CurrentStoreInterface $current_store, EntityTypeManagerInterface $entity_type_manager, InlineFormManager $inline_form_manager, LoggerInterface $logger, RouteMatchInterface $route_match) {
    parent::__construct($current_store, $entity_type_manager, $inline_form_manager, $logger);
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('commerce_store.current_store'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_inline_form'),
      $container->get('logger.channel.commerce_payment'),
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildCreditCardForm(array $element, FormStateInterface $form_state) {
    // Alter the form with AuthorizeNet Accept JS specific needs.
    $element['#attributes']['class'][] = 'authorize-net-accept-js-form';
    /** @var \Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway\AcceptJs $plugin */
    $plugin = $this->plugin;

    if ($plugin->getMode() == 'test') {
      $element['#attached']['library'][] = 'commerce_authnet/accept-js-sandbox';
    }
    else {
      $element['#attached']['library'][] = 'commerce_authnet/accept-js-production';
    }
    // @todo Remove this line when
    // https://www.drupal.org/project/commerce/issues/2986599 gets fixed.
    $element['#attached']['library'][] = 'commerce_authnet/form-accept';
    $element['#attached']['drupalSettings']['commerceAuthorizeNet'] = [
      'clientKey' => $plugin->getConfiguration()['client_key'],
      'apiLoginID' => $plugin->getConfiguration()['api_login'],
      'paymentMethodType' => 'credit_card',
      'ccaStatus' => 0,
      'mode' => $plugin->getMode(),
      'gatewayId' => $this->getEntity()->getPaymentGatewayId(),
    ];

    // Fields placeholder to be built by the JS.
    $element['number'] = [
      '#type' => 'textfield',
      '#title' => t('Card number'),
      '#attributes' => [
        'placeholder' => '•••• •••• •••• ••••',
        'autocomplete' => 'off',
        'autocorrect' => 'off',
        'autocapitalize' => 'none',
        'id' => 'credit-card-number',
        'required' => 'required',
      ],
      '#label_attributes' => [
        'class' => [
          'js-form-required',
          'form-required',
        ],
      ],
      '#maxlength' => 20,
      '#size' => 20,
    ];

    $element['expiration'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['credit-card-form__expiration'],
      ],
    ];
    $element['expiration']['month'] = [
      '#type' => 'textfield',
      '#title' => t('Month'),
      '#attributes' => [
        'placeholder' => 'MM',
        'autocomplete' => 'off',
        'autocorrect' => 'off',
        'autocapitalize' => 'none',
        'id' => 'expiration-month',
        'required' => 'required',
      ],
      '#label_attributes' => [
        'class' => [
          'js-form-required',
          'form-required',
        ],
      ],
      '#maxlength' => 2,
      '#size' => 3,
    ];

    $element['expiration']['divider'] = [
      '#type' => 'item',
      '#title' => '',
      '#markup' => '<span class="credit-card-form__divider">/</span>',
    ];
    $element['expiration']['year'] = [
      '#type' => 'textfield',
      '#title' => t('Year'),
      '#attributes' => [
        'placeholder' => 'YY',
        'autocomplete' => 'off',
        'autocorrect' => 'off',
        'autocapitalize' => 'none',
        'id' => 'expiration-year',
        'required' => 'required',
      ],
      '#label_attributes' => [
        'class' => [
          'js-form-required',
          'form-required',
        ],
      ],
      '#maxlength' => 2,
      '#size' => 3,
    ];

    $element['security_code'] = [
      '#type' => 'textfield',
      '#title' => t('CVV'),
      '#attributes' => [
        'placeholder' => '•••',
        'autocomplete' => 'off',
        'autocorrect' => 'off',
        'autocapitalize' => 'none',
        'id' => 'cvv',
        'required' => 'required',
      ],
      '#label_attributes' => [
        'class' => [
          'js-form-required',
          'form-required',
        ],
      ],
      '#maxlength' => 4,
      '#size' => 4,
    ];

    // To display validation errors.
    $element['payment_errors'] = [
      '#type' => 'markup',
      '#markup' => '<div id="payment-errors"></div>',
      '#weight' => -200,
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
    $element['last4'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'class' => ['accept-js-data-last4'],
      ],
    ];
    $element['expiration_month'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'class' => ['accept-js-data-month'],
      ],
    ];
    $element['expiration_year'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'class' => ['accept-js-data-year'],
      ],
    ];
    /** @var \Drupal\commerce_order\Entity\Order $order */
    if ($order = $this->routeMatch->getParameter('commerce_order')) {
      if ($plugin->getConfiguration()['cca_status']) {
        $element['cca_jwt_token'] = [
          '#type' => 'hidden',
          '#attributes' => [
            'class' => ['accept-js-data-cca-jwt-token'],
          ],
          '#value' => (string) $this->generateJwt(),
        ];
        $element['cca_jwt_response_token'] = [
          '#type' => 'hidden',
          '#attributes' => [
            'class' => ['accept-js-data-cca-jwt-response-token'],
          ],
        ];
        $element['#attached']['drupalSettings']['commerceAuthorizeNet']['orderId'] = $order->id();
        $element['#attached']['drupalSettings']['commerceAuthorizeNet']['orderAmount'] = $this->toMinorUnits($order->getTotalPrice());
        $element['#attached']['drupalSettings']['commerceAuthorizeNet']['orderCurrency'] = $order->getTotalPrice()->getCurrencyCode();
        $element['#attached']['drupalSettings']['commerceAuthorizeNet']['ccaStatus'] = 1;
      }
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateCreditCardForm(array &$element, FormStateInterface $form_state) {
    // The JS library performs its own validation.
  }

  /**
   * {@inheritdoc}
   */
  public function submitCreditCardForm(array $element, FormStateInterface $form_state) {
    // The payment gateway plugin will process the submitted payment details.
    $values = $form_state->getValues();
    if (!empty($values['contact_information']['email'])) {
      // Then we are dealing with anonymous user. Adding a customer email.
      $payment_details = $values['payment_information']['add_payment_method']['payment_details'];
      $payment_details['customer_email'] = $values['contact_information']['email'];
      $form_state->setValue(['payment_information', 'add_payment_method', 'payment_details'], $payment_details);
    }
  }

  /**
   * Create JWT token for CCA.
   *
   * @return \Lcobucci\JWT\Token
   *   The JWT token.
   */
  protected function generateJwt() {
    $current_time = time();
    $expire_time = 3600;
    /** @var \Drupal\commerce_order\Entity\Order $order */
    if ($order = $this->routeMatch->getParameter('commerce_order')) {
      $order_details = [
        'OrderDetails' => [
          'OrderNumber' => $order->getOrderNumber(),
        ],
      ];
    }

    /** @var \Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway\AcceptJs $plugin */
    $plugin = $this->plugin;

    $token = (new Builder())->issuedBy($plugin->getCcaApiId())
      ->identifiedBy(uniqid(), TRUE)
      ->issuedAt($current_time)
      ->expiresAt($current_time + $expire_time)
      ->withClaim('OrgUnitId', $plugin->getCcaOrgUnitId())
      ->withClaim('Payload', $order_details)
      ->withClaim('ObjectifyPayload', TRUE)
      ->getToken(new Sha256(), new Key($plugin->getCcaApiKey()));

    return $token;
  }

  /**
   * Converts the given amount to its minor units.
   *
   * This is a copypaste of PaymentGatewayBase::toMinorUnits() until that
   * method is made public.
   *
   * @todo Remove when https://www.drupal.org/project/commerce/issues/2944281
   * gets fixed.
   *
   * @param \Drupal\commerce_price\Price $amount
   *   The amount.
   *
   * @return int
   *   The amount in minor units, as an integer.
   */
  protected function toMinorUnits(Price $amount) {
    $entity_type_manager = \Drupal::service('entity_type.manager');
    $currency_storage = $entity_type_manager->getStorage('commerce_currency');
    /** @var \Drupal\commerce_price\Entity\CurrencyInterface $currency */
    $currency = $currency_storage->load($amount->getCurrencyCode());
    $fraction_digits = $currency->getFractionDigits();
    $number = $amount->getNumber();
    if ($fraction_digits > 0) {
      $number = Calculator::multiply($number, pow(10, $fraction_digits));
    }

    return round($number, 0);
  }

}
