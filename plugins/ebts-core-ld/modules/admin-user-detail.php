<?php
namespace EBTS\CoreLD; if (!defined('ABSPATH')) exit;

class AdminUserDetail {
  public static function menu(){
    add_submenu_page(null,'Dettagli utente (EBTS)','Dettagli utente (EBTS)','list_users','ebts_utente',[__CLASS__,'render']);
  }
  public static function render(){
    $uid = (int)($_GET['user_id'] ?? 0);
    if (!$uid) { echo '<div class="wrap"><h1>Utente non specificato</h1></div>'; return; }
    $u = get_user_by('id',$uid); if(!$u){ echo '<div class="wrap"><h1>Utente non trovato</h1></div>'; return; }

    $cf  = get_user_meta($uid,'cfiscale',true);
    $tel = get_user_meta($uid,'telefono',true);
    $ip  = get_user_meta($uid,'ip_registrazione',true);
    $bp_rel = get_user_meta($uid,'busta_paga_rel',true);
    $groups = Helpers::get_user_group_ids($uid);
    $courses = function_exists('learndash_user_get_enrolled_courses') ? (array) learndash_user_get_enrolled_courses($uid) : [];

    echo '<div class="wrap ebts-user-detail"><h1>Dettagli utente (EBTS)</h1>';
    echo '<h2 class="title">'.esc_html($u->first_name.' '.$u->last_name).' <small style="font-weight:normal;color:#666">('.esc_html($u->user_email).')</small></h2>';

    echo '<div class="ebts-grid">';
    echo '<div class="ebts-card"><h3>Anagrafica</h3><table class="form-table"><tbody>';
    echo '<tr><th>Nome</th><td>'.esc_html($u->first_name).'</td></tr>';
    echo '<tr><th>Cognome</th><td>'.esc_html($u->last_name).'</td></tr>';
    echo '<tr><th>Email</th><td>'.esc_html($u->user_email).'</td></tr>';
    echo '<tr><th>Telefono</th><td>'.esc_html($tel).'</td></tr>';
    echo '<tr><th>Codice Fiscale</th><td>'.esc_html($cf).'</td></tr>';
    echo '<tr><th>IP registrazione</th><td>'.esc_html($ip).'</td></tr>';
    echo '</tbody></table></div>';

    echo '<div class="ebts-card"><h3>Azienda / Gruppi</h3>';
    if ($groups) { echo '<ul class="ul-disc">'; foreach ($groups as $gid) echo '<li>'.esc_html(get_the_title($gid)).'</li>'; echo '</ul>'; } else { echo '<p><em>Nessun gruppo</em></p>'; }
    echo '</div>';

    echo '<div class="ebts-card"><h3>Corsi</h3>';
    if ($courses) { echo '<ul class="ul-disc">'; foreach ($courses as $cid) echo '<li>#'.$cid.' '.esc_html(get_the_title($cid)).'</li>'; echo '</ul>'; } else { echo '<p><em>Nessun corso</em></p>'; }
    echo '</div>';

    echo '<div class="ebts-card"><h3>Busta paga</h3>';
    if ($bp_rel) {
      $dl = wp_nonce_url(admin_url('admin-ajax.php?action='.C::DOWNLOAD_ACTION.'&kind=busta&user_id='.$uid),'ebts_dl_user');
      echo '<p><a class="button" href="'.esc_url($dl).'" target="_blank">Scarica busta paga</a></p>';
    } else {
      echo '<p><em>Non presente</em></p>';
    }
    echo '</div>';

    echo '<div class="ebts-card"><h3>Attestati</h3>';
    $certs = Helpers::get_user_certs($uid);
    if ($certs) {
      echo '<table class="widefat striped"><thead><tr><th>Titolo</th><th>Data</th><th>Corso</th><th>Azioni</th></tr></thead><tbody>';
      foreach ($certs as $c) {
        $dl = wp_nonce_url(admin_url('admin-ajax.php?action='.C::DOWNLOAD_ACTION.'&kind=attestato&user_id='.$uid.'&cert_id='.$c['id']), 'ebts_dl_user');
        echo '<tr><td>'.esc_html($c['title']).'</td><td>'.esc_html($c['date']).'</td><td>#'.intval($c['course_id']).' '.esc_html(get_the_title($c['course_id'])).'</td><td><a class="button button-small" target="_blank" href="'.esc_url($dl).'">Scarica</a></td></tr>';
      }
      echo '</tbody></table>';
    } else { echo '<p><em>Nessun attestato</em></p>'; }

    echo '</div>';
    echo '</div>';
  }
}
add_action('admin_menu', [AdminUserDetail::class, 'menu']);
