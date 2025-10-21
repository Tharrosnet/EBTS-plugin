<?php
/**
 * Shortcode: [ebts_cart_enroll ...]
 * - Mostra elenco carrello
 * - Se utente loggato, bottone "Iscriviti ora" che ENROLLA DIRETTAMENTE ai corsi (no checkout)
 * - Se ospite, bottone "Vai alla registrazione"
 */
if (!defined('ABSPATH')) exit;

if (!function_exists('ebts_register_cart_enroll_shortcode')) {

/** Trova ID corso LearnDash legato a un prodotto Woo (prova varie chiavi comuni) */
function ebts_map_product_to_course_id($product_id){
    $keys = ['_ebts_ld_course_id', '_related_course', 'ld_course_id', 'course_id'];
    foreach ($keys as $k){
        $v = get_post_meta($product_id, $k, true);
        if ($v && intval($v)) return intval($v);
    }
    // ultimo tentativo: cerca tra metadati noti di Woo for LearnDash
    $rel = get_post_meta($product_id, '_ld_course_id', true);
    if ($rel && intval($rel)) return intval($rel);
    return 0;
}

/** Enrolla l'utente corrente a tutti i corsi mappati nel carrello */
function ebts_enroll_current_user_from_cart(&$enrolled_course_ids = [], &$created_enrollments = []){
    $created_enrollments = array();
    if (!is_user_logged_in() || !function_exists('WC') || !WC()->cart) return false;
    $uid = get_current_user_id();
    $cart = WC()->cart;
    $ok_any = false;
    $enrolled_course_ids = [];

    foreach ($cart->get_cart() as $cart_item) {
        $prod = isset($cart_item['data']) ? $cart_item['data'] : null;
        if (!$prod) continue;
        $pid = $prod->get_id();
        // gestisci variazioni
        if ($prod->is_type('variation')) {
            $pid = $prod->get_parent_id() ?: $pid;
        }
        $cid = ebts_map_product_to_course_id($pid);
        if ($cid > 0 && function_exists('ld_update_course_access')) {
            ld_update_course_access($uid, $cid, true); // enroll
            $ok_any = true;
            $enrolled_course_ids[] = $cid;
            $pid = isset($cart_item['product_id']) ? intval($cart_item['product_id']) : (isset($prod) ? intval($prod->get_id()) : 0);
            $eid = ebts_record_enrollment($uid, $pid, $cid, 'shortcode', 'pending');
            if ($eid) { $created_enrollments[] = $eid; }
        }
    }
    if ($ok_any) {
        $cart->empty_cart();
    }
    return $ok_any;
}

function ebts_register_cart_enroll_shortcode(){
    // Handle POST enroll action
    add_action('template_redirect', function(){
        if (isset($_POST['ebts_enroll_now']) && $_POST['ebts_enroll_now'] == '1') {
            if (!is_user_logged_in()) {
                // Se è ospite reindirizza a registrazione
                $checkout_url = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/checkout/');
                $reg = add_query_arg('redirect_to', rawurlencode($checkout_url), home_url('/registrazione-utente/'));
                wp_safe_redirect($reg);
                exit;
            }
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'ebts_enroll_now-'.get_current_user_id())) {
                wc_add_notice(__('Sicurezza non valida. Riprova.','ebts'), 'error');
                return;
            }
            $enrolled = []; $created = [];
            $ok = ebts_enroll_current_user_from_cart($enrolled, $created);
            if ($ok) {
                wc_add_notice(sprintf(__('Iscrizione completata (%d corsi).','ebts'), count($enrolled)), 'success');
                // Redirect a "I miei corsi" LearnDash o My Account
                $dest = home_url('/mio-account/');
                wp_safe_redirect($dest);
                exit;
            } else {
                wc_add_notice(__('Nessun corso associato ai prodotti nel carrello.','ebts'), 'error');
            }
        }
    });

    add_shortcode('ebts_cart_enroll', function($atts = []){
        if ( ! function_exists('WC') || ! WC()->cart ) {
            return '<div class="ebts-cart-enroll"><p>WooCommerce non è disponibile.</p></div>';
        }

        $a = shortcode_atts([
            'registration_slug' => '/registrazione-utente',
            'label_logged'      => __('Iscriviti ora','ebts'),
            'label_guest'       => __('Vai alla registrazione','ebts'),
            'empty_notice'      => __('Il carrello è vuoto.','ebts'),
        ], $atts, 'ebts_cart_enroll');

        $cart = WC()->cart;
        ob_start();
        echo '<div class="ebts-cart-enroll">';

        if ( $cart->is_empty() ) {
            echo '<p>'.esc_html($a['empty_notice']).'</p>';
            $shop = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/');
            echo '<p><a class="button" href="'.esc_url($shop).'">'.esc_html__('Vai allo shop','ebts').'</a></p>';
            echo '</div>';
            return ob_get_clean();
        }

        echo '<table class="shop_table shop_table_responsive ebts-cart-table"><thead><tr>';
        echo '<th>'.esc_html__('Prodotto','ebts').'</th>';
        echo '<th style="text-align:center;">'.esc_html__('Quantità','ebts').'</th>';
        echo '</tr></thead><tbody>';

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product = apply_filters('woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key);
            if ( ! $product || ! $product->exists() ) continue;
            $name = $product->get_name();
            $qty  = $cart_item['quantity'];
            echo '<tr class="cart_item">';
            echo '<td class="product-name">'.esc_html($name).'</td>';
            echo '<td class="product-quantity" style="text-align:center;">'.intval($qty).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        if ( is_user_logged_in() ) {
            echo '<form method="post" class="ebts-enroll-form" style="margin-top:1rem;">';
            echo '<input type="hidden" name="ebts_enroll_now" value="1" />';
            echo wp_nonce_field('ebts_enroll_now-'.get_current_user_id(), '_wpnonce', true, false);
            echo '<button type="submit" class="button button-primary">'.esc_html($a['label_logged']).'</button>';
            echo '</form>';
        } else {
            $checkout_url = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : wc_get_page_permalink('checkout');
            $reg_base = (stripos($a['registration_slug'], 'http') === 0)
                ? $a['registration_slug']
                : home_url($a['registration_slug']);
            $reg = add_query_arg('redirect_to', rawurlencode($checkout_url), $reg_base);
            echo '<div class="ebts-cart-actions" style="margin-top:1rem;">';
            echo '<a class="button" href="'.esc_url($reg).'">'.esc_html($a['label_guest']).'</a>';
            echo '</div>';
        }

        echo '<style>
            .ebts-cart-table{width:100%; border-collapse:collapse; margin:12px 0;}
            .ebts-cart-table th,.ebts-cart-table td{border:1px solid #eee; padding:10px;}
            .ebts-enroll-form .button{display:inline-block; padding:.7em 1.2em; font-weight:600;}
            .ebts-enroll-form .button-primary{background:#2a7cff; color:#fff; border:none;}
        </style>';

        echo '</div>';
        return ob_get_clean();
    });
}
add_action('init','ebts_register_cart_enroll_shortcode');
}

/** Registra una "Iscrizione EBTS" (CPT o API Core se presente) */
function ebts_record_enrollment($user_id, $product_id, $course_id, $source='shortcode', $status='pending'){
    if (class_exists('\EBTS\CoreLD\Enrollments')) {
        try {
            if (method_exists('\EBTS\CoreLD\Enrollments','create')) {
                $eid = \EBTS\CoreLD\Enrollments::create([
                    'user_id'    => intval($user_id),
                    'course_id'  => intval($course_id),
                    'product_id' => intval($product_id),
                    'status'     => $status,
                    'source'     => $source,
                ]);
                if ($eid) return $eid;
            }
        } catch (\Throwable $e) { /* fallback */ }
    }
    $title = sprintf('Iscrizione utente %d al corso %d', $user_id, $course_id);
    $eid = wp_insert_post([
        'post_type'   => 'ebts_enrollment',
        'post_status' => 'publish',
        'post_title'  => $title,
        'post_author' => $user_id,
    ], true);
    if (!is_wp_error($eid) && $eid) {
        update_post_meta($eid, 'user_id', intval($user_id));
        update_post_meta($eid, 'course_id', intval($course_id));
        update_post_meta($eid, 'product_id', intval($product_id));
        update_post_meta($eid, 'status', $status);
        update_post_meta($eid, 'source', $source);
        do_action('ebts_enrollment_created', $eid, [
            'user_id'    => intval($user_id),
            'course_id'  => intval($course_id),
            'product_id' => intval($product_id),
            'status'     => $status,
            'source'     => $source,
        ]);
        return $eid;
    }
    return 0;
}
