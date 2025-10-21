<?php
/**
 * EBTS — Cart page (classic + Divi + Woo Blocks)
 * v4: forza URL del bottone via filtro Woo + override del testo via gettext.
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('EBTS_WC_Cart_Enroll_Now')) {
class EBTS_WC_Cart_Enroll_Now {

    public static function init() {
        // Classic hooks (se disponibili)
        add_action('template_redirect', [__CLASS__, 'setup_classic_cart_hooks']);

        // Server-side per Blocks (render_block) — mantiene compat Divi
        add_filter('render_block', [__CLASS__, 'filter_blocks_cart_cta'], 20, 2);

        // Filtro checkout URL: funziona anche quando i temi stampano il bottone direttamente
        add_filter('woocommerce_get_checkout_url', [__CLASS__, 'filter_checkout_url'], 20);

        // Cambia label del bottone via gettext (classico): copre vari testi/lingue
        add_filter('gettext', [__CLASS__, 'filter_cart_button_text'], 99, 3);

        // Fallback JS universale
        add_action('wp_enqueue_scripts', [__CLASS__, 'inject_universal_js'], 99);
    }

    /** CLASSIC */
    public static function setup_classic_cart_hooks() {
        remove_action('woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20);
        add_action('woocommerce_proceed_to_checkout', [__CLASS__, 'render_classic_cta'], 20);
        add_action('wp_head', function (){
            echo '<style id="ebts-hide-proceed">a.checkout-button, .wc-proceed-to-checkout .button.checkout{display:none!important;}</style>';
        });
    }

    public static function render_classic_cta() {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) return;
        $checkout_url = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : wc_get_page_permalink('checkout');
        echo '<div class="ebts-cart-actions" style="margin-top:1rem;">';
        if (is_user_logged_in()) {
            $url = add_query_arg([
                'ebts_enroll_now' => 1,
                '_wpnonce'        => wp_create_nonce('ebts_enroll_now-'.get_current_user_id()),
            ], $checkout_url);
            echo '<a class="button button-primary" href="'.esc_url($url).'">'.esc_html__('Iscriviti ora','ebts').'</a>';
        } else {
            $reg = home_url('/registrazione-utente/');
            $reg = add_query_arg('redirect_to', rawurlencode($checkout_url), $reg);
            echo '<a class="button" href="'.esc_url($reg).'">'.esc_html__('Vai alla registrazione','ebts').'</a>';
        }
        echo '</div>';
    }

    /** Blocks/Divi: riscrivi anchor nel markup del blocco */
    public static function filter_blocks_cart_cta($block_content, $block) {
        if (empty($block_content)) return $block_content;
        if (is_admin()) return $block_content;
        if (strpos($block_content, 'wc-block-cart__submit-button') === false) return $block_content;

        $checkout = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/checkout/');
        $is_logged = is_user_logged_in();
        $nonce = $is_logged ? wp_create_nonce('ebts_enroll_now-'.get_current_user_id()) : '';
        $reg = add_query_arg('redirect_to', rawurlencode($checkout), home_url('/registrazione-utente/'));

        $label = $is_logged ? esc_html__('Iscriviti ora','ebts') : esc_html__('Vai alla registrazione','ebts');
        $target = $is_logged ? esc_url($checkout.'?ebts_enroll_now=1&_wpnonce='.$nonce) : esc_url($reg);

        $block_content = preg_replace_callback(
            '#<a([^>]*class="[^"]*wc-block-cart__submit-button[^"]*"[^>]*)>(.*?)</a>#is',
            function($m) use ($label, $target){
                $attrs = $m[1];
                if (preg_match('#href="[^"]*"#i', $attrs)) {
                    $attrs = preg_replace('#href="[^"]*"#i', 'href="'.esc_url($target).'"', $attrs);
                } else {
                    $attrs .= ' href="'.esc_url($target).'"';
                }
                $inner = $m[2];
                $inner = preg_replace('#<span[^>]*class="[^"]*wc-block-components-button__text[^"]*"[^>]*>.*?</span>#is', '<span class="wc-block-components-button__text">'.esc_html($label).'</span>', $inner, 1);
                if ($inner === $m[2]) {
                    $inner = '<span class="wc-block-components-button__text">'.esc_html($label).'</span>';
                }
                return '<a'.$attrs.'>'.$inner.'</a>';
            },
            $block_content,
            1
        );
        return $block_content;
    }

    /** FORCE URL anche sul bottone classico */
    public static function filter_checkout_url($url) {
        // Solo in carrello
        if (!function_exists('is_cart') || !is_cart()) return $url;

        $checkout = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : $url;
        if (is_user_logged_in()) {
            return add_query_arg([
                'ebts_enroll_now' => 1,
                '_wpnonce'        => wp_create_nonce('ebts_enroll_now-'.get_current_user_id()),
            ], $checkout);
        } else {
            return add_query_arg('redirect_to', rawurlencode($checkout), home_url('/registrazione-utente/'));
        }
    }

    /** Cambia il testo del bottone classico nel carrello */
    public static function filter_cart_button_text($translated, $text, $domain) {
        if (!function_exists('is_cart') || !is_cart()) return $translated;

        // Copriamo testi comuni in IT/EN (Divi/Woo)
        $targets = [
            "Proceed to checkout",
            "Proceed to Checkout",
            "Concludi il pagamento",
            "Procedi con l'ordine",
            "Procedi con l’ordine",
        ];
        if (in_array($text, $targets, true) || in_array($translated, $targets, true)) {
            return is_user_logged_in() ? __('Iscriviti ora','ebts') : __('Vai alla registrazione','ebts');
        }
        return $translated;
    }

    /** Fallback JS */
    public static function inject_universal_js() {
        $checkout = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/checkout/');
        $is_logged = is_user_logged_in();
        $nonce = $is_logged ? wp_create_nonce('ebts_enroll_now-'.get_current_user_id()) : '';
        $reg = add_query_arg('redirect_to', rawurlencode($checkout), home_url('/registrazione-utente/'));

        $label = $is_logged ? esc_js(__('Iscriviti ora','ebts')) : esc_js(__('Vai alla registrazione','ebts'));
        $target = $is_logged ? esc_url($checkout.'?ebts_enroll_now=1&_wpnonce='.$nonce) : esc_url($reg);

        $selectors = implode(', ', [
            '.cart_totals a.button',
            '.cart_totals button.button',
            '.wc-proceed-to-checkout .button',
            'a.checkout-button',
            'button.checkout',
            '[data-testid="checkout-button"]',
            '.wc-block-cart__submit-button',
            '.wp-block-woocommerce-cart .components-button',
            '.wc-block-components-button',
            'a.button.alt.wc-forward'
        ]);

        $js = "(function(){function enhance(){var btn=null;var sels='%s'.split(',');for(var i=0;i<sels.length;i++){var q=sels[i].trim();var el=document.querySelector(q);if(el){btn=el;break;}}if(!btn||btn.dataset.ebtsHandled==='1')return;btn.dataset.ebtsHandled='1';var span=btn.querySelector('.wc-block-components-button__text');if(span){span.textContent='%s';}else{btn.textContent='%s';}btn.addEventListener('click',function(e){e.preventDefault();window.location='%s';});}if(document.readyState!=='loading'){enhance();}else{document.addEventListener('DOMContentLoaded',enhance);}var obs=new MutationObserver(function(){enhance();});obs.observe(document.body,{subtree:true,childList:true});})();" % (
            selectors, label, label, target
        );

        wp_register_script('ebts-cart-inline', false, array(), null, true);
        wp_enqueue_script('ebts-cart-inline');
        wp_add_inline_script('ebts-cart-inline', $js);
    }
}
EBTS_WC_Cart_Enroll_Now::init();
}
