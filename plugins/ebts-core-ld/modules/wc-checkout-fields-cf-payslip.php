<?php
// NIENTE namespace qui, per evitare conflitti con namespace annidati
if (!defined('ABSPATH')) exit;

/**
 * EBTS Checkout Fields: Codice Fiscale + Busta paga (PDF)
 * - Forza enctype e id="checkout" sul form
 * - Campo file con form="checkout"
 * - Fallback JS che sposta il campo DENTRO il form prima dell'invio
 * - Validazione robusta e deduplica notice
 * - Salvataggio sicuro su user meta via Helpers::store_private_pdf
 */

// 0) Assicura enctype multiparte e id="checkout" sul form
add_filter('woocommerce_checkout_form_tag', function($tag){
    if (strpos($tag, 'enctype=') === false) {
        $tag = str_replace('<form', '<form enctype="multipart/form-data"', $tag);
    }
    // assicura id="checkout" (Woo di solito lo mette già)
    if (!preg_match('/\sid=(["\'])checkout\1/i', $tag)) {
        $tag = str_replace('<form', '<form id="checkout"', $tag);
    }
    return $tag;
}, 10, 1);

// 1) Campo Codice Fiscale
add_filter('woocommerce_checkout_fields', function($fields){
    if (!isset($fields['billing']['billing_codice_fiscale'])){
        $fields['billing']['billing_codice_fiscale'] = [
            'type'        => 'text',
            'label'       => __('Codice Fiscale','ebts'),
            'required'    => true,
            'class'       => ['form-row-wide'],
            'priority'    => 110,
            'maxlength'   => 16,
            'placeholder' => 'XXXXXXXXXXXXXXX',
        ];
    }
    return $fields;
});

add_action('woocommerce_checkout_process', function(){
    $cf = isset($_POST['billing_codice_fiscale']) ? strtoupper(trim(wp_unslash($_POST['billing_codice_fiscale']))) : '';
    if ($cf === '' || !preg_match('/^[A-Z0-9]{16}$/', $cf)) {
        wc_add_notice(__('Inserisci un Codice Fiscale valido (16 caratteri).','ebts'), 'error');
    }
});

add_action('woocommerce_checkout_update_order_meta', function($order_id){
    if (!empty($_POST['billing_codice_fiscale'])) {
        update_post_meta($order_id, '_billing_codice_fiscale', strtoupper(sanitize_text_field(wp_unslash($_POST['billing_codice_fiscale']))));
    }
});

add_action('woocommerce_checkout_create_order', function($order, $data){
    $cf = isset($_POST['billing_codice_fiscale']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['billing_codice_fiscale']))) : '';
    if ($cf){
        $uid = $order->get_user_id();
        if (!$uid && is_user_logged_in()) $uid = get_current_user_id();
        if ($uid) update_user_meta($uid, 'cfiscale', $cf);
    }
}, 10, 2);

// 2) Campo upload busta paga – ASSOCIAZIONE ESPLICITA al form tramite form="checkout"
add_action('woocommerce_after_checkout_billing_form', function(){
    ?>
    <div class="woocommerce-additional-fields__field-wrapper" id="ebts-payslip-wrapper">
      <p class="form-row form-row-wide" id="ebts_busta_paga_field">
        <label for="ebts_busta_paga">
          <?php echo esc_html__('Busta paga (PDF)','ebts'); ?> <abbr class="required" title="obbligatorio">*</abbr>
        </label>
        <input type="file"
               name="ebts_busta_paga"
               id="ebts_busta_paga"
               accept="application/pdf"
               form="checkout"
               required />
      </p>
    </div>
    <?php
});

// 2a) Validazione robusta + deduplica
add_action('woocommerce_checkout_process', function(){
    $keys = apply_filters('ebts_payslip_field_keys', [
        'ebts_busta_paga',
        'billing_ebts_payslip',
        'ebts_payslip',
        'billing_busta_paga',
        'busta_paga',
        'billing_payslip',
        'payslip',
    ]);

    $msg_required = __('Carica la busta paga in PDF.','ebts');
    if (function_exists('wc_get_notices')){
        foreach ((array) (wc_get_notices()['error'] ?? []) as $n){
            $txt = is_array($n) ? ($n['notice'] ?? '') : $n;
            if (trim(wp_strip_all_tags((string)$txt)) === $msg_required){ return; }
        }
    }

    $f = null; $key_used = null;
    foreach ($keys as $k){
        if (!empty($_FILES[$k]) && !empty($_FILES[$k]['name'])) { $f = $_FILES[$k]; $key_used = $k; break; }
    }

    if (!$f){
        wc_add_notice($msg_required, 'error');
        return;
    }

    if (!empty($f['error']) && $f['error'] !== UPLOAD_ERR_OK) {
        wc_add_notice(__('Errore nel caricamento del file.','ebts'), 'error');
        return;
    }

    // Estensione
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        wc_add_notice(__('Il file deve avere estensione .pdf','ebts'), 'error');
        return;
    }

    // Magic bytes + MIME reale (tollerante per octet-stream)
    $magic_ok = false; $mime_ok = true;
    if (is_uploaded_file($f['tmp_name'])){
        $h = @fopen($f['tmp_name'],'rb'); $sig = $h ? fread($h,5) : ''; if ($h) fclose($h);
        $magic_ok = ($sig === '%PDF-');
        if (function_exists('finfo_open')){
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi){ $det = finfo_file($fi, $f['tmp_name']); finfo_close($fi); $mime_ok = ($det === 'application/pdf'); }
        }
    }
    if (!($magic_ok && $mime_ok)){
        wc_add_notice(__('Il file deve essere un PDF valido.','ebts'), 'error');
        return;
    }
});

// 2b) Salvataggio sicuro su user meta (usa Helpers::store_private_pdf)
add_action('woocommerce_checkout_create_order', function($order, $data){
    $keys = apply_filters('ebts_payslip_field_keys', [
        'ebts_busta_paga',
        'billing_ebts_payslip',
        'ebts_payslip',
        'billing_busta_paga',
        'busta_paga',
        'billing_payslip',
        'payslip',
    ]);

    $f = null;
    foreach ($keys as $k) {
        if (!empty($_FILES[$k]) && !empty($_FILES[$k]['name'])) { $f = $_FILES[$k]; break; }
    }
    if (!$f) return;

    $uid = $order->get_user_id();
    if (!$uid && is_user_logged_in()) $uid = get_current_user_id();
    if (!$uid) return;

    if (class_exists('\\EBTS\\CoreLD\\Helpers') && method_exists('\\EBTS\\CoreLD\\Helpers','store_private_pdf')){
        $rel = null;
        $ok = \EBTS\CoreLD\Helpers::store_private_pdf($f, 'busta-'.$uid.'-checkout-'.time().'.pdf', $rel);
        if ($ok && $rel){
            update_user_meta($uid, 'busta_paga_rel', $rel);
        } else {
            wc_add_notice(__('Errore salvataggio busta paga.','ebts'), 'error');
        }
    }
}, 10, 2);

// 3) JS fallback: se per qualche motivo l'input non è dentro il form, spostalo dentro prima dell'invio
add_action('wp_footer', function(){
    if (!function_exists('is_checkout') || !is_checkout()) return;
    ?>
    <script>
    (function(){
      function ensureFileInsideForm(){
        var form = document.getElementById('checkout') || document.querySelector('form.checkout, form.woocommerce-checkout');
        var input = document.getElementById('ebts_busta_paga');
        if (!form || !input) return;
        if (!form.contains(input)) {
          var wrap = document.getElementById('ebts-payslip-wrapper') || input;
          try { form.appendChild(wrap); } catch(e) {}
        }
        if (input.form !== form) { try { input.setAttribute('form', form.id || 'checkout'); } catch(e) {} }
        if (!form.enctype) form.enctype = 'multipart/form-data';
      }
      document.addEventListener('DOMContentLoaded', ensureFileInsideForm);
      document.addEventListener('click', function(e){
        var t = e.target;
        if (!t) return;
        if (t.id === 'place_order' || t.name === 'woocommerce_checkout_place_order') {
          ensureFileInsideForm();
        }
      }, true);
    })();
    </script>
    <?php
}, 99);
