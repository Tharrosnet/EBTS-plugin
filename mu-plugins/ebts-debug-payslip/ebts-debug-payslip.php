<?php
/*
Plugin Name: EBTS – Debug Payslip Upload
Description: Diagnostica per il campo "Busta paga (PDF)" al checkout WooCommerce. Logga $_FILES, limiti PHP e gli errori WooCommerce.
Author: EBTS
Version: 1.0.0
*/

if (!defined('ABSPATH')) exit;

// 0) Assicura enctype multipart sul form del checkout (innocuo se già presente)
add_filter('woocommerce_checkout_form_tag', function($tag){
    if (strpos($tag, 'enctype=') === false) {
        $tag = str_replace('<form', '<form enctype="multipart/form-data"', $tag);
    }
    return $tag;
}, 10, 1);

// 1) Aggiungi qui tutti i possibili "name" del campo file usato dal tema/plugin.
//    Puoi aggiungerne altri via filtro nel theme functions.php se necessario.
add_filter('ebts_payslip_field_keys', function($keys){
    $prefer = array(
        'ebts_busta_paga',       // usato dal modulo integrato nel core
        'billing_ebts_payslip',  // variante storica
        'ebts_payslip',
        'billing_busta_paga',
        'busta_paga',
        'billing_payslip',
        'payslip',
    );
    $keys = is_array($keys) ? $keys : array();
    // Inserisce i preferiti in testa e rimuove duplicati preservando l'ordine
    $out = array();
    foreach (array_merge($prefer, $keys) as $k){
        if (!in_array($k, $out, true)) $out[] = $k;
    }
    return $out;
});

// 2) Log dettagliato dopo la validazione Woo (non aggiunge errori, solo log)
add_action('woocommerce_after_checkout_validation', function($data, $errors){
    if (!function_exists('error_log')) return;

    $keys = apply_filters('ebts_payslip_field_keys', array());
    $keys = is_array($keys) ? $keys : array();
    $file_keys = array_keys($_FILES);

    error_log('[EBTS_DEBUG] FILE KEYS: ' . implode(',', $file_keys));

    $logged_any = false;
    foreach ($keys as $k){
        if (!empty($_FILES[$k])){
            $f = $_FILES[$k];
            $name = isset($f['name']) ? (string)$f['name'] : '';
            $size = isset($f['size']) ? (int)$f['size'] : 0;
            $err  = isset($f['error']) ? (int)$f['error'] : -1;
            $mime = isset($f['type']) ? (string)$f['type'] : '';
            $det  = '';
            $sig  = '';

            if (is_uploaded_file($f['tmp_name'])){
                if (function_exists('finfo_open')){
                    $fi = finfo_open(FILEINFO_MIME_TYPE);
                    if ($fi){ $det = (string)finfo_file($fi, $f['tmp_name']); finfo_close($fi); }
                }
                // Magic bytes
                $h = @fopen($f['tmp_name'], 'rb');
                if ($h){ $sig = fread($h, 5); fclose($h); }
            }

            error_log(sprintf('[EBTS_DEBUG] FIELD=%s NAME=%s SIZE=%d ERROR=%d TYPE=%s FINFOMIME=%s MAGIC=%s',
                $k, $name, $size, $err, $mime, $det, $sig
            ));
            $logged_any = true;
            break;
        }
    }

    if (!$logged_any){
        error_log('[EBTS_DEBUG] Nessun file trovato nelle chiavi attese. Controlla il "name" dell\'input file.');
    }

    // Log limiti PHP correnti
    $umf = ini_get('upload_max_filesize');
    $pms = ini_get('post_max_size');
    $mfu = ini_get('max_file_uploads');
    error_log(sprintf('[EBTS_DEBUG] LIMITS upload_max_filesize=%s post_max_size=%s max_file_uploads=%s', $umf, $pms, $mfu));

    // Logga anche gli errori Woo attuali (notices) per capire cosa viene mostrato a schermo
    if (function_exists('wc_get_notices')){
        $notices = wc_get_notices();
        if (!empty($notices['error'])){
            foreach ($notices['error'] as $n){
                $txt = is_array($n) ? ($n['notice'] ?? '') : $n;
                $txt = trim(wp_strip_all_tags((string)$txt));
                if ($txt !== '') error_log('[EBTS_DEBUG] WC_ERROR: '.$txt);
            }
        }
    }
}, 1, 2);

// 3) (Opzionale) Evita che i notice di debug interrompano i redirect in produzione
//    Commenta se vuoi visualizzarli a schermo.
if (!defined('WP_DEBUG_DISPLAY')) {
    define('WP_DEBUG_DISPLAY', false);
}
@ini_set('display_errors', '0');
