<?php
namespace EBTS\CoreLD; if (!defined('ABSPATH')) exit;

add_action('wp_ajax_' . C::DOWNLOAD_ACTION, __NAMESPACE__ . '\download_private');
function download_private(){
  if (!current_user_can('list_users') && !is_user_logged_in()) wp_die('Forbidden', 403);
  $kind = sanitize_text_field($_GET['kind'] ?? '');
  $user_id = (int)($_GET['user_id'] ?? 0);
  if (!$user_id) wp_die('Missing', 400);

  $file = '';
  if ($kind === 'busta') {
    $rel = get_user_meta($user_id, 'busta_paga_rel', true);
    if ($rel) $file = trailingslashit(Helpers::get_private_dir()) . basename($rel);
  } elseif ($kind === 'attestato') {
    $cert_id = sanitize_text_field($_GET['cert_id'] ?? '');
    $certs = get_user_meta($user_id, C::META_CERTS, true);
    if (is_array($certs)) {
      foreach ($certs as $c) {
        if (($c['id'] ?? '') === $cert_id) { $file = trailingslashit(Helpers::get_private_dir()) . basename($c['rel'] ?? ''); break; }
      }
    }
  }
  if (!$file || !file_exists($file)) wp_die('File non trovato', 404);
  header('Content-Type: application/pdf');
  header('Content-Disposition: attachment; filename="' . basename($file) . '"');
  header('Content-Length: '.filesize($file));
  readfile($file); exit;
}
