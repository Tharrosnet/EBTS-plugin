<?php
namespace EBTS\CoreLD; if (!defined('ABSPATH')) exit;

/**
 * [ebts_miei_documenti] - area personale studente
 */
add_shortcode('ebts_miei_documenti', __NAMESPACE__ . '\shortcode_doc');
function shortcode_doc($atts=[]){
  if (!is_user_logged_in()) {
    return '<div class="ebts-docs"><p>Accedi per vedere i tuoi documenti.</p></div>';
  }
  $uid = get_current_user_id();
  Helpers::ensure_private_dir();
  Helpers::ensure_payslip_meta($uid);

  $out = '<div class="ebts-docs">';
  if (!empty($_POST['ebts_front_payslip_upload']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'],'ebts_front_docs')) {
    if (!empty($_FILES['busta_paga']['name'])) {
      $rel = null;
      if (Helpers::store_private_pdf($_FILES['busta_paga'], 'busta-'.$uid.'-front-'.time().'.pdf', $rel)) {
        update_user_meta($uid, 'busta_paga_rel', $rel);
        $out .= '<div class="ebts-notice ok">Busta paga aggiornata.</div>';
      } else {
        $out .= '<div class="ebts-notice err">Caricamento non valido (solo PDF).</div>';
      }
    }
  }

  $bp_rel = get_user_meta($uid, 'busta_paga_rel', true);
  $out .= '<h3>Busta paga</h3>';
  if ($bp_rel) {
    $dl = wp_nonce_url(admin_url('admin-ajax.php?action='.C::DOWNLOAD_ACTION.'&kind=busta&user_id='.$uid), 'ebts_dl_user');
    $out .= '<p><a class="button" href="'.esc_url($dl).'">Scarica busta paga</a></p>';
  } else {
    $out .= '<p><em>Nessun file presente.</em></p>';
  }
  $out .= '<form method="post" enctype="multipart/form-data"><input type="hidden" name="_wpnonce" value="'.esc_attr(wp_create_nonce('ebts_front_docs')).'"><input type="file" name="busta_paga" accept="application/pdf" required> <button class="button" name="ebts_front_payslip_upload" value="1">Carica / Sostituisci</button></form>';

  $out .= '<h3>Attestati</h3>';
  $certs = Helpers::get_user_certs($uid);
  if ($certs) {
    $out .= '<table class="ebts-table"><thead><tr><th>Titolo</th><th>Data</th><th>Corso</th><th>Azioni</th></tr></thead><tbody>';
    foreach ($certs as $c) {
      $title = esc_html($c['title'] ?? 'Attestato');
      $date  = esc_html($c['date'] ?? '');
      $cid   = intval($c['course_id'] ?? 0);
      $ctitle= $cid ? get_the_title($cid) : '';
      $dl = wp_nonce_url(admin_url('admin-ajax.php?action='.C::DOWNLOAD_ACTION.'&kind=attestato&user_id='.$uid.'&cert_id='.($c['id'] ?? '')), 'ebts_dl_user');
      $out .= '<tr><td>'.$title.'</td><td>'.$date.'</td><td>'.($cid?('#'.$cid.' '.esc_html($ctitle)):'—').'</td><td><a class="button button-small" href="'.esc_url($dl).'">Scarica</a></td></tr>';
    }
    $out .= '</tbody></table>';
  } else {
    $out .= '<p><em>Nessun attestato disponibile.</em></p>';
  }
  $out .= '</div>';
  return $out;
}
