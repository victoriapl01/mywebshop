<?php

/**
 * @file
 * Provide views data for the Order module.
 */

/**
 * Implements hook_views_data().
 */
function commerce_add_to_cart_confirmation_views_data() {
  $data['views']['commerce_add_to_cart_confirmation_order_other_count'] = [
    'title' => t('Order other total count'),
    'help' => t('Displays the order other total field, requires an Order ID argument.'),
    'area' => [
      'id' => 'commerce_add_to_cart_confirmation_order_other_count',
    ],
  ];
  $data['views']['commerce_add_to_cart_confirmation_order_item_order_total'] = [
    'title' => t('Order total for views with order item id argument'),
    'help' => t('Displays the order other total field, requires an Order Item ID argument.'),
    'area' => [
      'id' => 'commerce_add_to_cart_confirmation_order_item_order_total',
    ],
  ];
  return $data;
}
