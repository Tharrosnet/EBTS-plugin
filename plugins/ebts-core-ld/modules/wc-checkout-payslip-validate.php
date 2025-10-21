<?php
namespace EBTS\CoreLD;
if (!defined('ABSPATH')) exit;

add_filter('woocommerce_checkout_form_tag', function($tag){
    if (strpos($tag, 'enctype=') === false) {
        $tag = str_replace('<form', '<form enctype="multipart/form-data"', $tag);
    }
    return $tag;
}, 10, 1);


add_action('woocommerce_after_checkout_validation', function($data, $errors){
        $msg_required = __('Carica la busta paga in PDF.', 'ebts');
        $has_same_notice = false;
        if (function_exists('wc_get_notices')){
            $all = wc_get_notices();
            if (!empty($all['error'])){
                foreach ($all['error'] as $n){
                    if (is_string($n) && trim(wp_strip_all_tags($n)) === $msg_required){ $has_same_notice = true; break; }
                    if (is_array($n) && !empty($n['notice']) && trim(wp_strip_all_tags($n['notice'])) === $msg_required){ $has_same_notice = true; break; }
                }
            }
        }

    // Avoid duplicate errors from other validators
    $keys = ['billing_ebts_payslip','ebts_payslip'];
    foreach ($keys as $k){
        if (method_exists($errors,'get_error_messages') && $errors->get_error_messages($k)){
            return; // another validator already added an error for this field
        }
    }
    $add_once = function($key,$msg) use ($errors){
        if (method_exists($errors,'get_error_messages')){
            foreach ($errors->get_error_messages($key) as $m){ if ($m === $msg) return; }
        }
        $errors->add($key, $msg);
    };

    $candidates = ['billing_ebts_payslip', 'ebts_payslip'];
    $f = null; $key_used = null;
    foreach ($candidates as $k) {
        if (!empty($_FILES[$k]) && !empty($_FILES[$k]['name'])) { $f = $_FILES[$k]; $key_used = $k; break; }
    }

    if (!$f) { if (!$has_same_notice) { $add_once('billing_ebts_payslip', $msg_required); } return; }

    if (!empty($f['error']) && $f['error'] !== UPLOAD_ERR_OK) { $add_once($key_used, __('Errore nel caricamento del file.', 'ebts')); return; }

    if (!empty($f['size']) && $f['size'] > 10 * 1024 * 1024) { $add_once($key_used, __('Il file è troppo grande (max 10MB).', 'ebts')); return; }

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') { $add_once($key_used, __('Il file deve avere estensione .pdf', 'ebts')); return; }

    $mime_ok  = in_array(strtolower($f['type'] ?? ''), ['application/pdf','application/x-pdf','application/octet-stream'], true);
    $magic_ok = false;

    if (is_uploaded_file($f['tmp_name'])) {
        $h = @fopen($f['tmp_name'], 'rb'); $sig = $h ? fread($h, 5) : ''; if ($h) fclose($h);
        $magic_ok = ($sig === '%PDF-');
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $det = finfo_file($fi, $f['tmp_name']);
                finfo_close($fi);
                if ($det === 'application/pdf') { $mime_ok = true; }
            }
        }
    }

    if (!($mime_ok && $magic_ok)) { $add_once($key_used, __('Il file deve essere un PDF valido.', 'ebts')); return; }
}, 12, 2);
