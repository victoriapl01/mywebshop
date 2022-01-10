<?php

namespace Drupal\commerce_add_to_cart_confirmation\EventSubscriber;

use Drupal\commerce_cart\Event\CartEntityAddEvent;
use Drupal\commerce_cart\Event\CartEvents;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\views\Views;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event Subscriber ConfirmationMessageSubscriber.
 */
class ConfirmationMessageSubscriber implements EventSubscriberInterface {

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new ConfirmationMessageSubscriber instance.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(RendererInterface $renderer, MessengerInterface $messenger) {
    $this->renderer = $renderer;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[CartEvents::CART_ENTITY_ADD][] = ['onAddToCart'];
    return $events;
  }

  /**
   * Handles the add to cart event.
   *
   * @param \Drupal\commerce_cart\Event\CartEntityAddEvent $event
   */
  public function onAddToCart(CartEntityAddEvent $event) {
    $view = Views::getView('confirm_message_product_display');
    $view->setDisplay('default');
    $view->setArguments([$event->getOrderItem()->id()]);
    $confirmation_message = [
      '#theme' => 'commerce_add_to_cart_confirmation',
      '#message' => $view->render(),
    ];
    $this->messenger->addMessage($this->renderer->render($confirmation_message), 'commerce-add-to-cart-confirmation');
  }

}
