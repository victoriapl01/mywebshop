<?php

namespace Drupal\commerce_authnet\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Verifies JWT in the CCA process.
 *
 * @see https://developer.cardinalcommerce.com/cardinal-cruise-activation.shtml#generatingServerJWTphp
 */
class CcaValidation implements ContainerInjectionInterface {

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private $requestStack;

  /**
   * Constructs a new CcaValidation object.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(RequestStack $request_stack) {
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack')
    );
  }

  /**
   * Validates the JWT.
   */
  public function validateJwt() {
    $response_jwt = $this->requestStack->getCurrentRequest()->request->get('responseJwt');

    /** @var \Lcobucci\JWT\Token $token */
    $token = (new Parser())->parse($response_jwt);
    $signer = new Sha256();

    $gateway_id = $this->requestStack->getCurrentRequest()->request->get('gatewayId');
    /** @var \Drupal\commerce_payment\Entity\PaymentGateway $gateway */
    $gateway = PaymentGateway::load($gateway_id);
    $api_key = $gateway->getPlugin()->getCcaApiKey();
    $claims = $token->getClaims();
    $response = [
      'verified' => $token->verify($signer, $api_key),
      'payload' => $claims,
    ];
    return new JsonResponse($response);
  }

}
