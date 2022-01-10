<?php

namespace Drupal\commerce_add_to_cart_confirmation\Plugin\views\area;

use Drupal\commerce_order\Plugin\views\area\OrderTotal;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\Plugin\views\argument\NumericArgument;

/**
 * Defines an order total area handler.
 *
 * Shows the order total field with its components listed in the footer of a
 * View.
 *
 * @ingroup views_area_handlers
 *
 * @ViewsArea("commerce_add_to_cart_confirmation_order_item_order_total")
 */
class OrderItemOrderTotal extends OrderTotal {

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
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager);

    $this->orderItemStorage = $entity_type_manager->getStorage('commerce_order_item');;
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
          return $order->get('total_price')->view(['label' => 'inline']);
        }
      }
    }

    return [];
  }

}
