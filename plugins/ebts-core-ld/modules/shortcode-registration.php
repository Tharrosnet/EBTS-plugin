<?php
/**
 * Shortcode [ebts_registrazione]
 * - Render modulo registrazione (campi principali richiesti)
 * - Upload busta paga in wp-uploads/ebts-private/ (layout piatto)
 * - Salvataggio esteso dei meta utente (billing_* + custom) per compatibilità con EBTS > Iscritti
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('EBTS_Shortcode_Registration')) {
class EBTS_Shortcode_Registration {

    const SHORTCODE = 'ebts_registrazione';
    const NONCE     = 'ebts_reg_nonce';
    const META_PAYSLIP = 'busta_paga_rel'; // filename in ebts-private

    public static function init() {
        add_shortcode(self::SHORTCODE, [__CLASS__, 'render']);
        add_action('template_redirect', [__CLASS__, 'maybe_handle_submission']);
    }

    /** UI */
    public static function render($atts = [], $content = '') {
        if (is_user_logged_in()) {
            return '<div class="woocommerce"><p>'.esc_html__('Sei già registrato.', 'ebts').'</p></div>';
        }

        ob_start();
        echo '<form method="post" enctype="multipart/form-data" class="woocommerce">';
        wp_nonce_field(self::NONCE, self::NONCE);

        echo '<h3>'.esc_html__('Dati iscritto', 'ebts').'</h3>';
        self::field_text('billing_first_name', __('Nome', 'ebts'), true);
        self::field_text('billing_last_name',  __('Cognome', 'ebts'), true);
        self::field_text('billing_luogo_nascita', __('Luogo di nascita', 'ebts'), true);
        self::field_text('billing_data_di_nascita', __('Data di nascita', 'ebts'), true);
        self::field_text('billing_codicefiscale', __('Codice fiscale', 'ebts'), true);
        self::field_text('billing_city', __('Residente a', 'ebts'), true);
        self::field_text('billing_postcode', __('Cap', 'ebts'), true);
        self::field_text('billing_address_1', __('Via e numero', 'ebts'), true);
        self::field_text('billing_phone', __('Cellulare', 'ebts'), true);
        self::field_email('billing_email', __('Indirizzo email', 'ebts'), true);
        self::field_text('billing_mansione_iscritto', __('Mansione ricoperta', 'ebts'), true);

        echo '<p class="form-row form-row-wide"><label for="iscritto_occupazione">'.esc_html__('Occupazione', 'ebts').' <abbr class="required">*</abbr></label>';
        echo '<select name="iscritto_occupazione" id="iscritto_occupazione" required>';
        echo '<option value="dipendente_fisso">'.esc_html__('Dipendente fisso','ebts').'</option>';
        echo '<option value="dipendente_stagionale">'.esc_html__('Dipendente stagionale','ebts').'</option>';
        echo '<option value="dipendente_titolare">'.esc_html__('Titolare','ebts').'</option>';
        echo '</select></p>';

        self::field_text('dipendente_anno_occupa', __('Anno ultima occupazione per i dip. stagionali', 'ebts'), false);
        self::field_text('dati_azienda', __('Dati Azienda', 'ebts'), false);
        self::field_text('billing_azienda_iscritto', __('Azienda', 'ebts'), true);
        self::field_text('billing_ragione_sociale', __('Ragione sociale', 'ebts'), false);

        echo '<p class="form-row form-row-wide"><label for="billing_tipologia_attivita">'.esc_html__('Tipologia attività', 'ebts').' <abbr class="required">*</abbr></label>';
        echo '<select name="billing_tipologia_attivita" id="billing_tipologia_attivita" required>';
        foreach ([
            'bar'=>'Bar','ristorante'=>'Ristorante','pizzeria'=>'Pizzeria','albergo'=>'Albergo','campeggio'=>'Campeggio',
            'agenzia_viaggi'=>'Agenzia di viaggi','porto_approdo'=>'Porto e approdo turistico','stabilimento_balneare'=>'Stabilimento balneare',
            'gelateria'=>'Gelateria','mensa_catering'=>'Mensa e catering','affitacamere'=>'Affittacamere','residence_villaggi'=>'Residence e villaggi',
            'servizi_turistici'=>'Servizi turistici','altro'=>'Altro'
        ] as $v=>$l) {
            echo '<option value="'.esc_attr($v).'">'.esc_html($l).'</option>';
        }
        echo '</select></p>';

        self::field_text('billing_piva', __('Partita IVA', 'ebts'), false);
        self::field_text('billing_indirizzo', __('Indirizzo', 'ebts'), true);
        self::field_text('billing_cap', __('Cap', 'ebts'), false);
        self::field_text('billing_citta', __('Città', 'ebts'), true);
        self::field_text('billing_provincia', __('Provincia', 'ebts'), false);
        self::field_text('billing_telefono', __('Telefono', 'ebts'), false);
        self::field_email('billing_email_azienda', __('Email azienda', 'ebts'), true);

        echo '<p class="form-row form-row-wide"><label for="ebts_payslip">'.esc_html__('Busta paga (PDF)', 'ebts').' <abbr class="required">*</abbr></label>';
        echo '<input type="file" name="ebts_payslip" id="ebts_payslip" accept="application/pdf" required /></p>';

        echo '<p><button type="submit" class="button" name="ebts_reg_submit" value="1">'.esc_html__('Effettua l’iscrizione','ebts').'</button></p>';
        echo '</form>';

        return ob_get_clean();
    }

    protected static function field_text($name, $label, $required=false) {
        echo '<p class="form-row form-row-wide"><label for="'.esc_attr($name).'">'.esc_html($label);
        if ($required) echo ' <abbr class="required">*</abbr>';
        echo '</label><input type="text" class="input-text" name="'.esc_attr($name).'" id="'.esc_attr($name).'" '.($required?'required':'').' /></p>';
    }
    protected static function field_email($name, $label, $required=false) {
        echo '<p class="form-row form-row-wide"><label for="'.esc_attr($name).'">'.esc_html($label);
        if ($required) echo ' <abbr class="required">*</abbr>';
        echo '</label><input type="email" class="input-text" name="'.esc_attr($name).'" id="'.esc_attr($name).'" '.($required?'required':'').' /></p>';
    }

    /** Submit */
    public static function maybe_handle_submission() {
        if (is_user_logged_in()) return;
        if (empty($_POST['ebts_reg_submit'])) return;

        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE])), self::NONCE)) {
            if (function_exists('wc_add_notice')) wc_add_notice(__('Token non valido. Riprova.','ebts'), 'error');
            return;
        }

        $required = ['billing_first_name','billing_last_name','billing_email','billing_codicefiscale','billing_phone','billing_address_1','billing_postcode','billing_city','billing_mansione_iscritto','billing_azienda_iscritto','billing_tipologia_attivita'];
        foreach ($required as $key) {
            if (empty($_POST[$key])) {
                if (function_exists('wc_add_notice')) wc_add_notice(__('Compila tutti i campi obbligatori.','ebts'), 'error');
                return;
            }
        }
        if (empty($_FILES['ebts_payslip']['name'])) {
            if (function_exists('wc_add_notice')) wc_add_notice(__('Carica la busta paga in PDF.','ebts'), 'error');
            return;
        }

        $email = sanitize_email(wp_unslash($_POST['billing_email']));
        if (email_exists($email)) {
            if (function_exists('wc_add_notice')) wc_add_notice(__('Email già registrata. Effettua il login.','ebts'), 'error');
            return;
        }
        $pass  = wp_generate_password(12, true);
        $uid   = wp_create_user($email, $pass, $email);
        if (is_wp_error($uid)) {
            if (function_exists('wc_add_notice')) wc_add_notice(__('Errore creazione utente.','ebts'), 'error');
            return;
        }

        // ---- META UTENTE / BILLING ----
        $fields = [
          'billing_first_name'        => sanitize_text_field($_POST['billing_first_name'] ?? ''),
          'billing_last_name'         => sanitize_text_field($_POST['billing_last_name'] ?? ''),
          'billing_luogo_nascita'     => sanitize_text_field($_POST['billing_luogo_nascita'] ?? ''),
          'billing_data_di_nascita'   => sanitize_text_field($_POST['billing_data_di_nascita'] ?? ''),

          'billing_phone'             => preg_replace('/[^0-9+]/','', $_POST['billing_phone'] ?? ''),
          'billing_email'             => sanitize_email($_POST['billing_email'] ?? ''),

          'billing_address_1'         => sanitize_text_field($_POST['billing_address_1'] ?? ($_POST['billing_indirizzo'] ?? '')),
          'billing_postcode'          => sanitize_text_field($_POST['billing_postcode'] ?? ($_POST['billing_cap'] ?? '')),
          'billing_city'              => sanitize_text_field($_POST['billing_city'] ?? ($_POST['billing_citta'] ?? '')),
          'billing_state'             => sanitize_text_field($_POST['billing_prov_residenza'] ?? ($_POST['billing_provincia'] ?? '')),

          'billing_company'           => sanitize_text_field($_POST['billing_azienda_iscritto'] ?? ''),
          'billing_vat'               => sanitize_text_field($_POST['billing_piva'] ?? ''),
          'billing_email_azienda'     => sanitize_text_field($_POST['billing_email_azienda'] ?? ''),
          'billing_ragione_sociale'   => sanitize_text_field($_POST['billing_ragione_sociale'] ?? ''),
          'billing_tipologia_attivita'=> sanitize_text_field($_POST['billing_tipologia_attivita'] ?? ''),

          'billing_mansione_iscritto' => sanitize_text_field($_POST['billing_mansione_iscritto'] ?? ''),
          'iscritto_occupazione'      => sanitize_text_field($_POST['iscritto_occupazione'] ?? ''),
          'dipendente_anno_occupa'    => sanitize_text_field($_POST['dipendente_anno_occupa'] ?? ''),
          'dati_azienda'              => sanitize_text_field($_POST['dati_azienda'] ?? ''),
        ];

        update_user_meta($uid, 'first_name', $fields['billing_first_name']);
        update_user_meta($uid, 'last_name',  $fields['billing_last_name']);

        foreach ($fields as $k => $v) {
          if ($v === '') continue;
          update_user_meta($uid, $k, $v);
        }

        $cf = strtoupper(sanitize_text_field($_POST['billing_codicefiscale'] ?? ''));
        if ($cf !== '') {
          update_user_meta($uid, 'cfiscale', $cf);
          update_user_meta($uid, 'billing_codicefiscale', $cf);
        }

        if (!empty($_POST['billing_indirizzo'])) update_user_meta($uid, 'billing_indirizzo', sanitize_text_field($_POST['billing_indirizzo']));
        if (!empty($_POST['billing_cap']))       update_user_meta($uid, 'billing_cap',       sanitize_text_field($_POST['billing_cap']));
        if (!empty($_POST['billing_citta']))     update_user_meta($uid, 'billing_citta',     sanitize_text_field($_POST['billing_citta']));
        if (!empty($_POST['billing_provincia'])) update_user_meta($uid, 'billing_provincia', sanitize_text_field($_POST['billing_provincia']));
        if (!empty($_POST['billing_telefono']))  update_user_meta($uid, 'billing_telefono',  preg_replace('/[^0-9+]/','', $_POST['billing_telefono']));

        if (class_exists('WC_Customer')) {
          try {
            $customer = new WC_Customer($uid);
            foreach ($fields as $k => $v) { if ($v !== '') $customer->update_meta_data($k, $v); }
            if ($cf !== '') $customer->update_meta_data('billing_codicefiscale', $cf);
            $customer->save();
          } catch (\Throwable $e) {}
        }

        // Upload busta paga
        $rel = null; $stored = false;
        if (class_exists('\EBTS\CoreLD\Helpers') && method_exists('\EBTS\CoreLD\Helpers','store_private_pdf')) {
            try {
                $stored = \EBTS\CoreLD\Helpers::store_private_pdf($_FILES['ebts_payslip'], 'busta-'.$uid.'-registration-'.time().'.pdf', $rel);
            } catch (\Throwable $e) { $stored = false; $rel = null; }
        }
        if (!$stored || empty($rel)) {
            $uploads = wp_upload_dir();
            $base = trailingslashit($uploads['basedir']).'ebts-private';
            if (!is_dir($base)) { wp_mkdir_p($base); @chmod($base,0755); }
            $dest_filename = 'busta-'.$uid.'-registration-'.time().'.pdf';
            $dest_abs = wp_normalize_path(trailingslashit($base).$dest_filename);
            $moved = @move_uploaded_file($_FILES['ebts_payslip']['tmp_name'], $dest_abs);
            if (!$moved) { $moved = @copy($_FILES['ebts_payslip']['tmp_name'], $dest_abs); if ($moved) @unlink($_FILES['ebts_payslip']['tmp_name']); }
            if ($moved && file_exists($dest_abs)) { $rel = $dest_filename; $stored = true; }
        }
        if ($stored && $rel) {
            update_user_meta($uid, self::META_PAYSLIP, $rel);
        }

        // Autologin e redirect
        wp_set_current_user($uid);
        wp_set_auth_cookie($uid, true);

        $dest = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/');
        wp_safe_redirect($dest);
        exit;
    }
}
EBTS_Shortcode_Registration::init();
}
