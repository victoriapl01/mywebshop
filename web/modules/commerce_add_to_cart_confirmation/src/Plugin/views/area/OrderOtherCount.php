<?php

namespace Drupal\commerce_add_to_cart_confirmation\Plugin\views\area;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\area\AreaPluginBase;
use Drupal\views\Plugin\views\argument\NumericArgument;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines an order total area handler.
 *
 * Shows the order total field with its components listed in the footer of a
 * View.
 *
 * @ingroup views_area_handlers
 *
 * @ViewsArea("commerce_add_to_cart_confirmation_order_other_count")
 */
class OrderOtherCount extends AreaPluginBase {

  /**
   * The order storage.
   *
   * @var \Drupal\Core\Entity\Sql\SqlContentEntityStorage
   */
  protected $orderStorage;

  /**
   * The order item storage.
   *
   * @var \Drupal\Core\Entity\Sql\SqlContentEntityStorage
   */
  protected $orderItemStorage;

  /**
   * Constructs a new OrderTotal instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->orderStorage = $entity_type_manager->getStorage('commerce_order');;
    $this->orderItemStorage = $entity_type_manager->getStorage('commerce_order_item');;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $form['empty']['#description'] = $this->t("Even if selected, this area handler will never render if a valid order cannot be found in the View's arguments.");
  }

  /**
   * {@inheritdoc}
   */
  public function render($empty = FALSE) {
    if (!$empty || !empty($this->options['empty'])) {
      foreach ($this->view->argument as $argument) {
        // First look for an order_id argument.
        if (!$argument instanceof NumericArgument) {
          continue;
        }
        if ($argument->getField() !== 'commerce_order_item.order_item_id') {
          continue;
        }
        /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
        $order_item = $this->orderItemStorage->load($argument->getValue());
        if (!$order_item) {
          continue;
        }
        if ($order = $order_item->getOrder()) {
          $current_order_item_quantity = $order_item->getQuantity();
          $total_order_quantity = 0;
          $other_order_price = $order->getTotalPrice()->subtract($order_item->getTotalPrice());
          foreach ($order->getItems() as $item) {
            $total_order_quantity += $item->getQuantity();
          }
          $other_order_quantity = $total_order_quantity - $current_order_item_quantity;
          if (!$other_order_quantity) {
            return [];
          }
          $other_title = \Drupal::translation()->formatPlural($other_order_quantity, '1 other item in your Cart', '@count other items in your Cart');

          return [
            '#type' => 'inline_template',
            '#template' => '<div class="order-other"> {{ title }} <div class="price"> {{ price|commerce_price_format }}</div></div>',
            '#context' => [
              'title' => $other_title,
              'price' => $other_order_price,
            ],
          ];
        }
      }
    }

    return [];
  }

}
