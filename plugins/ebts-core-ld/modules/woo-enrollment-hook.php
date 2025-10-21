<?php
namespace EBTS\CoreLD;
if (!defined('ABSPATH')) exit;

class Woo_Enrollment_Hook {
  public static function init(){
    add_action('woocommerce_order_status_processing', [__CLASS__,'from_order'], 10, 1);
    add_action('woocommerce_order_status_completed', [__CLASS__,'from_order'], 10, 1);
  }
  public static function from_order($order_id){
    if (!$order_id) return;
    $order = wc_get_order($order_id);
    if (!$order) return;
    $user_id = (int) $order->get_user_id();
    $created = $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : current_time('mysql');
    $group_id = 0;
    foreach ($order->get_items('line_item') as $item_id => $item){
      $product = $item->get_product(); if (!$product) continue;
      $product_id = (int) $product->get_id();
      if (!Enrollments_Logic::is_course_product($product_id)) continue;
      if (!Enrollments_Logic::ensure_unique_from_order_item($item_id)) continue;
      $base_course_id = Enrollments_Logic::product_base_course($product_id);
      Enrollments_Logic::create_enrollment([
        'user_id' => $user_id,
        'product_id' => $product_id,
        'base_course_id' => $base_course_id ?: null,
        'order_id' => $order_id,
        'order_item_id' => $item_id,
        'group_id' => $group_id ?: null,
        'status' => 'pending',
        'source' => 'checkout',
        'created_at' => $created,
      ]);
    }
  }
}
Woo_Enrollment_Hook::init();
