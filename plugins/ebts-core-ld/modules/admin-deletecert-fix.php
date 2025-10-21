<?php
namespace EBTS\CoreLD; if (!defined('ABSPATH')) exit;

/**
 * Fix eliminazione attestati (quick edit) integrata nel core.
 * - Endpoint AJAX: ebts_delete_attestato
 * - JS: intercetta i click su .ebts-del-cert o [data-ebts-action="del-cert"]
 */
class AdminDeleteCertFix {
  public static function boot(){
    add_action('wp_ajax_ebts_delete_attestato', [__CLASS__,'ajax_delete_attestato']);
    add_action('admin_enqueue_scripts', [__CLASS__,'enqueue']);
  }

  public static function enqueue($hook){
    // Script leggero, admin only
    wp_enqueue_script('ebts_delete_cert', plugins_url('assets/admin-delete-cert.js', dirname(__FILE__)), ['jquery'], '1.0.0', true);
    wp_localize_script('ebts_delete_cert', 'EBTS_DEL', [
      'ajax'  => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('ebts_admin_nonce'),
    ]);
  }

  public static function ajax_delete_attestato(){
    if (!is_user_logged_in()) wp_send_json_error(['message'=>'Non autenticato'], 403);
    if (!current_user_can('list_users') && !current_user_can('manage_options')) {
      wp_send_json_error(['message'=>'Permesso negato'], 403);
    }
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if ($nonce && !wp_verify_nonce($nonce, 'ebts_admin_nonce')) {
      wp_send_json_error(['message'=>'Nonce non valido'], 400);
    }

    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $cert_id = isset($_POST['cert_id']) ? sanitize_text_field($_POST['cert_id']) : '';
    if (!$user_id || !$cert_id) wp_send_json_error(['message'=>'Parametri mancanti'], 400);

    // Meta key
    $meta_key = 'ebts_user_certs';
    if (class_exists(__NAMESPACE__.'\\C') && defined(__NAMESPACE__.'\\C::META_CERTS')) {
      $meta_key = C::META_CERTS;
    }
    $certs = get_user_meta($user_id, $meta_key, true);
    if (!is_array($certs)) $certs = [];

    $found = null; $kept = [];
    foreach ($certs as $c){
      if (($c['id'] ?? '') === $cert_id) { $found = $c; continue; }
      $kept[] = $c;
    }
    if (!$found) wp_send_json_error(['message'=>'Attestato non trovato'], 404);

    // Elimina file se è un PDF di attestato (non busta-*)
    $rel = isset($found['rel']) ? basename($found['rel']) : '';
    if ($rel && stripos($rel, 'busta-') !== 0) {
      $priv_dir = '';
      if (class_exists(__NAMESPACE__.'\\Helpers') && method_exists(__NAMESPACE__.'\\Helpers','get_private_dir')) {
        $priv_dir = trailingslashit(Helpers::get_private_dir());
      } else {
        $priv_dir = trailingslashit(WP_CONTENT_DIR) . 'ebts-private/';
      }
      $path = $priv_dir . $rel;
      if (file_exists($path)) @unlink($path);
    }

    update_user_meta($user_id, $meta_key, $kept);
    wp_send_json_success(['message'=>'Attestato eliminato','user_id'=>$user_id,'cert_id'=>$cert_id]);
  }
}
AdminDeleteCertFix::boot();
