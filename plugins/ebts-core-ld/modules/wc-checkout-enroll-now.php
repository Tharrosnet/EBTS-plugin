<?php
/**
 * EBTS: Enroll Now sul checkout
 */
if (!defined('ABSPATH')) exit;
if (!class_exists('EBTS_WC_Checkout_Enroll_Now')) {
class EBTS_WC_Checkout_Enroll_Now {
    const ACTION_KEY = 'ebts_enroll_now';
    public static function init() {
        add_action('woocommerce_checkout_before_order_review', [__CLASS__, 'render_button'], 5);
        add_action('template_redirect', [__CLASS__, 'maybe_handle_enroll'], 1);
    }
    public static function render_button() {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) return;
        $is_logged = is_user_logged_in();
        $checkout_url = wc_get_checkout_url();
        echo '<div class="ebts-enroll-now" style="margin:1rem 0;padding:1rem;border:1px solid #e5e5e5;border-radius:6px;">';
        echo '<h3 style="margin-top:0;">'.esc_html__('Iscrizione rapida ai corsi', 'ebts').'</h3>';
        if ($is_logged) {
            $url = add_query_arg([ self::ACTION_KEY => 1, '_wpnonce' => wp_create_nonce(self::ACTION_KEY.'-'.get_current_user_id()) ], $checkout_url);
            echo '<p>'.esc_html__('Se sei già registrato, puoi iscriverti direttamente ai corsi presenti nel carrello senza completare il pagamento.', 'ebts').'</p>';
            echo '<a class="button button-primary" href="'.esc_url($url).'" style="margin-right:8px;">'.esc_html__('Iscriviti ora', 'ebts').'</a>';
        } else {
            $reg_url = home_url('/registrazione-utente/');
            $reg_url = add_query_arg('redirect_to', rawurlencode($checkout_url), $reg_url);
            echo '<p>'.esc_html__('Per iscriverti ai corsi devi prima registrarti.', 'ebts').'</p>';
            echo '<a class="button" href="'.esc_url($reg_url).'">'.esc_html__('Vai alla registrazione', 'ebts').'</a>';
        }
        echo '</div>';
    }
    public static function maybe_handle_enroll() {
        if (empty($_GET[self::ACTION_KEY])) return;
        if (!is_user_logged_in()) return;
        $uid = get_current_user_id();
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, self::ACTION_KEY.'-'.$uid)) {
            if (function_exists('wc_add_notice')) wc_add_notice(__('Link non valido o scaduto.', 'ebts'), 'error');
            wp_safe_redirect(wc_get_checkout_url()); exit;
        }
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            if (function_exists('wc_add_notice')) wc_add_notice(__('Il carrello è vuoto.', 'ebts'), 'error');
            wp_safe_redirect(wc_get_checkout_url()); exit;
        }
        $course_titles = [];
        foreach (WC()->cart->get_cart() as $item) {
            $product = isset($item['data']) ? $item['data'] : null;
            if (!$product) continue;
            $pid = $product->get_id();
            $course_id = self::get_course_id_from_product($pid);
            if (!$course_id) $course_id = $pid;
            self::enroll_user_in_course($uid, $course_id);
            $course_titles[] = get_the_title($course_id);
        }
        WC()->cart->empty_cart();
        if (!empty($course_titles)) {
            $list = implode(', ', array_map('esc_html', $course_titles));
            if (function_exists('wc_add_notice')) wc_add_notice(sprintf(__('Iscrizione completata ai corsi: %s', 'ebts'), $list), 'success');
        } else {
            if (function_exists('wc_add_notice')) wc_add_notice(__('Iscrizione completata.', 'ebts'), 'success');
        }
        $redirect = wc_get_page_permalink('myaccount'); if (!$redirect) $redirect = home_url('/');
        wp_safe_redirect($redirect); exit;
    }
    protected static function get_course_id_from_product($product_id) {
        $keys = ['_ebts_ld_course_id','_ld_course_id','_related_course','_course_id','ld_course_id'];
        foreach ($keys as $k) {
            $val = get_post_meta($product_id, $k, true);
            if ($val && is_numeric($val)) return absint($val);
            if (is_array($val) && !empty($val)) { $first = reset($val); if (is_numeric($first)) return absint($first); }
        }
        return 0;
    }
    protected static function enroll_user_in_course($user_id, $course_id) {
        $user_id = absint($user_id); $course_id = absint($course_id);
        if (!$user_id || !$course_id) return;
        if (function_exists('ld_update_course_access')) { ld_update_course_access($user_id, $course_id, false); return; }
        if (function_exists('learndash_user_enrolled_in_course')) {
            if (!learndash_user_enrolled_in_course($user_id, $course_id)) {
                update_user_meta($user_id, 'course_' . $course_id . '_access_from', time());
            }
            return;
        }
        add_user_meta($user_id, 'ebts_course_enrolled', $course_id, false);
    }
}
EBTS_WC_Checkout_Enroll_Now::init();
}
