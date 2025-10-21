<?php
/**
 * EBTS: nascondi riepilogo costi e bottone "Effettua ordine" nel checkout WooCommerce
 */
if (!defined('ABSPATH')) exit;
if (!class_exists('EBTS_WC_Checkout_Hide_Order_Review')) {
class EBTS_WC_Checkout_Hide_Order_Review {
    public static function init() {
        add_action('init', [__CLASS__, 'unhook_checkout_parts'], 20);
        add_filter('woocommerce_order_button_html', [__CLASS__, 'blank_order_button'], 9999);
        add_filter('woocommerce_pay_order_button_html', [__CLASS__, 'blank_order_button'], 9999);
    }
    public static function unhook_checkout_parts() {
        remove_action('woocommerce_checkout_order_review', 'woocommerce_order_review', 10);
        remove_action('woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20);
        add_action('wp_head', function (){
            if (!function_exists('is_checkout') || !is_checkout()) return;
            echo '<style id="ebts-hide-order-review">.woocommerce-checkout-review-order-table, #order_review, #payment, .place-order, button#place_order{display:none!important;}</style>';
        });
    }
    public static function blank_order_button($html) { return ''; }
}
EBTS_WC_Checkout_Hide_Order_Review::init();
}
