<?php
/**
 * Endpoint "Busta paga" (layout PIATTO in ebts-private/)
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('EBTS_WC_MyAccount_Payslip')) {
class EBTS_WC_MyAccount_Payslip {

    const EP       = 'busta-paga';
    const META_KEY = 'busta_paga_rel';     // memorizza SOLO il filename in flat storage
    const NONCE_UP = 'ebts_payslip_update';
    const DL_QUERY = 'ebts_payslip_dl';

    public static function init() {
        add_action('init', [__CLASS__, 'add_endpoint']);
        add_filter('query_vars', [__CLASS__, 'add_query_var']);
        add_filter('woocommerce_account_menu_items', [__CLASS__, 'add_menu_item']);
        add_action('woocommerce_account_' . self::EP . '_endpoint', [__CLASS__, 'render_endpoint']);

        add_action('template_redirect', [__CLASS__, 'maybe_handle_download'], 1);
        add_action('template_redirect', [__CLASS__, 'maybe_handle_upload'], 5);
    }

    public static function add_endpoint() { add_rewrite_endpoint(self::EP, EP_ROOT | EP_PAGES); }
    public static function add_query_var($vars) { $vars[] = self::EP; return $vars; }

    public static function add_menu_item($items) {
        $new = [];
        foreach ($items as $key => $label) {
            $new[$key] = $label;
            if ($key === 'downloads') $new[self::EP] = __('Busta paga', 'ebts');
        }
        if (!isset($new[self::EP])) $new[self::EP] = __('Busta paga', 'ebts');
        return $new;
    }

    /** Helpers */

    protected static function uploads_base() {
        $uploads = wp_upload_dir();
        return trailingslashit(wp_normalize_path($uploads['basedir'])) . 'ebts-private/';
    }

    protected static function signed_download_url($rel_or_name, $uid) {
        $base = function_exists('wc_get_account_endpoint_url')
            ? wc_get_account_endpoint_url(self::EP)
            : (function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/'));
        return add_query_arg([
            self::DL_QUERY => rawurlencode((string) $rel_or_name),
            '_wpnonce'     => wp_create_nonce('ebts_pslip_dl_' . (int)$uid),
        ], $base);
    }

    // Risolve il percorso in layout PIATTO:
    // - se nel meta è salvato solo il filename → ebts-private/{filename}
    // - accetta legacy path e prova comunque ebts-private/{basename}
    // - impone che il filename inizi con 'busta-{uid}-'
    protected static function resolve_private_path_flat($meta_value, $uid) {
        $uid = (int) $uid;
        $base = self::uploads_base();

        $name = basename(wp_normalize_path((string)$meta_value));
        if ($name === '' || strpos($name, 'busta-' . $uid . '-') !== 0) {
            return false;
        }
        $abs = $base . $name;
        return file_exists($abs) ? $abs : false;
    }

    /** Render endpoint */
    public static function render_endpoint() {
        if (!is_user_logged_in()) {
            echo '<p>'.esc_html__('Devi effettuare l’accesso per vedere questa pagina.', 'ebts').'</p>';
            return;
        }
        $uid = get_current_user_id();
        $rel = (string) get_user_meta($uid, self::META_KEY, true);

        echo '<div class="woocommerce">';
        echo '<h3>'.esc_html__('La tua busta paga', 'ebts').'</h3>';

        if ($rel !== '') {
            // 1) Prova helper (se disponibile lato frontend)
            $download_url = '';
            if (class_exists('\EBTS\CoreLD\Helpers') && method_exists('\EBTS\CoreLD\Helpers', 'private_download_url')) {
                try { $download_url = \EBTS\CoreLD\Helpers::private_download_url($rel); } catch (\Throwable $e) { $download_url = ''; }
            }
            // 2) Fallback firmato
            if (!$download_url) $download_url = self::signed_download_url($rel, $uid);

            echo '<p><a class="button" href="'.esc_url($download_url).'">'.esc_html__('Scarica la busta paga', 'ebts').'</a></p>';
        } else {
            echo '<p>'.esc_html__('Nessuna busta paga caricata.', 'ebts').'</p>';
        }

        echo '<hr/><h4>'.esc_html__('Aggiorna busta paga (PDF)', 'ebts').'</h4>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field(self::NONCE_UP, self::NONCE_UP);
        echo '<p><input type="file" name="ebts_payslip" accept="application/pdf" required></p>';
        echo '<p><button class="button" type="submit" name="ebts_payslip_submit" value="1">'.esc_html__('Carica', 'ebts').'</button></p>';
        echo '</form>';

        echo '</div>';
    }

    /** Download */
    public static function maybe_handle_download() {
        if (!is_user_logged_in()) return;
        if (empty($_GET[self::DL_QUERY])) return;

        $uid   = get_current_user_id();
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'ebts_pslip_dl_' . (int)$uid)) {
            wp_die(__('Link non valido o scaduto.', 'ebts'), 403);
        }

        $requested = rawurldecode((string) $_GET[self::DL_QUERY]);
        $saved     = (string) get_user_meta($uid, self::META_KEY, true);
        if ($saved === '' || basename($requested) !== basename($saved)) {
            wp_die(__('Non sei autorizzato a scaricare questo file.', 'ebts'), 403);
        }

        // Prova helper → redirect
        if (class_exists('\EBTS\CoreLD\Helpers') && method_exists('\EBTS\CoreLD\Helpers', 'private_download_url')) {
            try {
                $secure = \EBTS\CoreLD\Helpers::private_download_url($saved);
                if ($secure) { wp_safe_redirect($secure); exit; }
            } catch (\Throwable $e) { /* fallback */ }
        }

        // Fallback flat
        $abs = self::resolve_private_path_flat($saved, $uid);
        if (!$abs) {
            wp_die(__('File non trovato.', 'ebts'), 404);
        }

        if (function_exists('wc_nocache_headers')) wc_nocache_headers();
        @ini_set('zlib.output_compression', 'Off');
        while (ob_get_level()) { @ob_end_clean(); }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.basename($abs).'"');
        header('Content-Length: ' . filesize($abs));
        header('X-Content-Type-Options: nosniff');

        $fp = fopen($abs, 'rb');
        if ($fp) {
            while (!feof($fp)) { echo fread($fp, 8192); flush(); }
            fclose($fp);
            exit;
        }
        readfile($abs);
        exit;
    }

    /** Upload */
    public static function maybe_handle_upload() {
        if (!is_user_logged_in()) return;
        if (empty($_POST['ebts_payslip_submit'])) return;
        if (!isset($_POST[self::NONCE_UP]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_UP])), self::NONCE_UP)) {
            if (function_exists('wc_add_notice')) wc_add_notice(__('Token non valido. Riprova.', 'ebts'), 'error');
            return;
        }
        if (empty($_FILES['ebts_payslip']['name'])) {
            if (function_exists('wc_add_notice')) wc_add_notice(__('Seleziona un PDF.', 'ebts'), 'error');
            return;
        }

        $f   = $_FILES['ebts_payslip'];
        $uid = get_current_user_id();

        if (!empty($f['error']) && $f['error'] !== UPLOAD_ERR_OK) { if (function_exists('wc_add_notice')) wc_add_notice(__('Errore nel caricamento del file.', 'ebts'), 'error'); return; }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf') { if (function_exists('wc_add_notice')) wc_add_notice(__('Il file deve avere estensione .pdf', 'ebts'), 'error'); return; }

        // Firma/mime minimi
        $magic_ok = false; $mime_ok = true;
        if (is_uploaded_file($f['tmp_name'])) {
            $h = @fopen($f['tmp_name'], 'rb'); $sig = $h ? fread($h, 5) : ''; if ($h) fclose($h);
            $magic_ok = ($sig === '%PDF-');
            if (function_exists('finfo_open')) {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                if ($fi) { $det = finfo_file($fi, $f['tmp_name']); finfo_close($fi); $mime_ok = ($det === 'application/pdf'); }
            }
        }
        if (!($magic_ok && $mime_ok)) { if (function_exists('wc_add_notice')) wc_add_notice(__('Il file deve essere un PDF valido.', 'ebts'), 'error'); return; }

        // 1) Tenta helper ufficiale (potrebbe restituire solo filename)
        $stored = false; $meta_value = null;
        if (class_exists('\EBTS\CoreLD\Helpers') && method_exists('\EBTS\CoreLD\Helpers','store_private_pdf')) {
            try {
                $stored = \EBTS\CoreLD\Helpers::store_private_pdf($f, 'busta-'.$uid.'-profile-'.time().'.pdf', $meta_value);
            } catch (\Throwable $e) { $stored = false; $meta_value = null; }
        }

        // 2) Fallback PIATTO: ebts-private/{filename}
        if (!$stored || empty($meta_value)) {
            $base = self::uploads_base();
            if (!is_dir($base)) { wp_mkdir_p($base); @chmod($base, 0755); }

            $filename  = 'busta-'.$uid.'-profile-'.time().'.pdf';
            $dest_abs  = wp_normalize_path($base . $filename);
            $moved = @move_uploaded_file($f['tmp_name'], $dest_abs);
            if (!$moved) { $moved = @copy($f['tmp_name'], $dest_abs); if ($moved) @unlink($f['tmp_name']); }
            if ($moved && file_exists($dest_abs)) {
                $stored = true;
                $meta_value = $filename; // memorizziamo SOLO il filename
            }
        }

        if ($stored && $meta_value) {
            update_user_meta($uid, self::META_KEY, $meta_value);
            if (function_exists('wc_add_notice')) wc_add_notice(__('Busta paga aggiornata con successo.', 'ebts'), 'success');
        } else {
            if (function_exists('wc_add_notice')) wc_add_notice(__('Errore nel salvataggio della busta paga. Riprovare.', 'ebts'), 'error');
        }

        $url = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url(self::EP) : (function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/'));
        wp_safe_redirect($url);
        exit;
    }
}
EBTS_WC_MyAccount_Payslip::init();
}
