<?php
namespace EBTS\CoreLD; if (!defined('ABSPATH')) exit;

class AdminAttestati {
  public static function menu(){
    add_submenu_page('ebts_iscritti_edit','Attestati (upload)','Attestati (upload)','list_users','ebts_attestati_upload',[__CLASS__,'render']);
  }
  public static function render(){
    $view = sanitize_text_field($_GET['view'] ?? 'by_user');
    echo '<div class="wrap"><h1>Attestati (upload)</h1>';
    echo '<h2 class="nav-tab-wrapper">';
    echo '<a class="nav-tab '.($view==='by_user'?'nav-tab-active':'').'" href="'.esc_url(add_query_arg(['page'=>'ebts_attestati_upload','view'=>'by_user'])).'">Per utente</a>';
    echo '<a class="nav-tab '.($view==='by_course'?'nav-tab-active':'').'" href="'.esc_url(add_query_arg(['page'=>'ebts_attestati_upload','view'=>'by_course'])).'">Per corso</a>';
    echo '</h2>';
    if ($view==='by_course') self::view_by_course(); else self::view_by_user();
    echo '</div>';
  }

  private static function view_by_user(): void {
    $uid = (int)($_GET['user_id'] ?? 0);
    echo '<form method="get"><input type="hidden" name="page" value="ebts_attestati_upload"><input type="hidden" name="view" value="by_user">';
    echo '<p><label>Seleziona utente</label> ';
    wp_dropdown_users(['name'=>'user_id','show_option_none'=>'— seleziona —','selected'=>$uid]);
    echo ' <button class="button">Apri</button></p></form>';
    if (!$uid) return;

    $u = get_user_by('id',$uid); 
    if(!$u){ echo '<p>Utente non trovato.</p>'; return; }

    echo '<h3>'.esc_html($u->first_name.' '.$u->last_name).' ('.esc_html($u->user_email).')</h3>';
    $certs = Helpers::get_user_certs($uid); 
    if ($certs && is_array($certs)) {
      echo '<table class="widefat striped"><thead><tr><th>Titolo</th><th>Data</th><th>Corso</th><th>Azioni</th></tr></thead><tbody>';
      foreach ($certs as $c) {
        $cid = isset($c['course_id']) ? (int)$c['course_id'] : 0;
        $title = isset($c['title']) ? (string)$c['title'] : 'Attestato';
        $date  = isset($c['date']) ? (string)$c['date'] : '';
        $cert_id = isset($c['id']) ? (string)$c['id'] : '';
        $dl = $cert_id ? wp_nonce_url(admin_url('admin-ajax.php?action=' . C::DOWNLOAD_ACTION . '&kind=attestato&user_id=' . $uid . '&cert_id=' . $cert_id), 'ebts_dl_user') : '';
        echo '<tr><td>'.esc_html($title).'</td><td>'.esc_html($date).'</td><td>#'.($cid?:0).' '.esc_html($cid?get_the_title($cid):'').'</td><td>'.($dl?'<a class="button button-small" href="'.esc_url($dl).'">Scarica</a>':'').'</td></tr>';
      }
      echo '</tbody></table>';
    } else {
      echo '<p><em>Nessun attestato.</em></p>';
    }

    $courses=get_posts(['post_type'=>'sfwd-courses','numberposts'=>-1,'post_status'=>'publish','orderby'=>'title','order'=>'ASC']);
    echo '<h4>Carica nuovo attestato</h4><form method="post" enctype="multipart/form-data">';
    wp_nonce_field('ebts_att_op');
    echo '<input type="hidden" name="user_id" value="'.$uid.'">';
    echo '<p><label>Corso<br><select name="course" required><option value="">— seleziona —</option>';
    foreach($courses as $c) echo '<option value="'.$c->ID.'">'.esc_html($c->post_title).'</option>';
    echo '</select></label></p>';
    echo '<p><label>Titolo<br><input type="text" name="title" value="Attestato" class="regular-text" required></label></p>';
    echo '<p><label>Data<br><input type="date" name="date" value="'.esc_attr(current_time('Y-m-d')).'" required></label></p>';
    echo '<p><label>PDF<br><input type="file" name="cert_pdf" accept="application/pdf" required></label></p>';
    echo '<p><button class="button button-primary" name="ebts_upload_cert" value="1">Carica</button></p>';
    echo '</form>';

    self::handle_upload();
  }

  private static function view_by_course(): void {
    $courses=get_posts(['post_type'=>'sfwd-courses','numberposts'=>-1,'post_status'=>'any']);
    $course_id=(int)($_GET['course_id']??0);
    $per_page=max(1,(int)($_GET['per_page']??25));
    $paged=max(1,(int)($_GET['paged']??1));

    echo '<form method="get"><input type="hidden" name="page" value="ebts_attestati_upload"><input type="hidden" name="view" value="by_course">';
    echo '<p><label>Seleziona corso</label><br><select name="course_id" onchange="this.form.submit()"><option value="">— seleziona —</option>';
    foreach($courses as $c) echo '<option value="'.$c->ID.'" '.selected($course_id,$c->ID,false).'>'.esc_html($c->post_title).' (#'.$c->ID.')</option>';
    echo '</select> <label style="margin-left:10px;">Per pagina</label> <input type="number" min="1" name="per_page" value="'.esc_attr($per_page).'" style="width:80px">';
    if($course_id) echo ' <button class="button">Applica</button>';
    echo '</p></form>';

    if(!$course_id) return;

    echo '<style>
      .ebts-cert-ok{color:#46b450;font-size:18px;vertical-align:middle;}
      .ebts-cert-missing{color:#d63638;font-size:18px;vertical-align:middle;}
      .ebts-cell-actions form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    </style>';

    $user_ids=Helpers::get_course_user_ids($course_id,$paged,$per_page);
    if(!$user_ids){ echo '<p>Nessun iscritto trovato per questo corso (pagina '.intval($paged).').</p>'; return; }

    echo '<h2>Iscritti al corso</h2>
    <table class="widefat striped">
      <thead><tr>
        <th>Utente</th>
        <th>Email</th>
        <th>Stato</th>
        <th>Carica attestato</th>
      </tr></thead><tbody>';

    foreach($user_ids as $uid){
      $u=get_user_by('id',$uid); if(!$u) continue;

      $certs = Helpers::get_user_certs($uid);
      $match = null;
      foreach ($certs as $c) { if ((int)($c['course_id'] ?? 0) === $course_id) { $match = $c; break; } }

      if ($match) {
        $icon = '<span class="dashicons dashicons-yes ebts-cert-ok" title="Attestato già caricato per questo corso"></span> ';
        $dl = wp_nonce_url(
          admin_url('admin-ajax.php?action=' . C::DOWNLOAD_ACTION . '&kind=attestato&user_id=' . $uid . '&cert_id=' . $match['id']),
          'ebts_dl_user'
        );
        $stato = $icon.'<a href="'.esc_url($dl).'" class="button button-small">Scarica</a>';
      } else {
        $stato = '<span class="dashicons dashicons-no-alt ebts-cert-missing" title="Nessun attestato per questo corso"></span> Manca';
      }

      $default_title = 'Attestato — '.get_the_title($course_id);

      echo '<tr>
        <td>'.esc_html($u->first_name.' '.$u->last_name).'</td>
        <td>'.esc_html($u->user_email).'</td>
        <td>'.$stato.'</td>
        <td class="ebts-cell-actions">
          <form method="post" enctype="multipart/form-data">'.wp_nonce_field('ebts_att_op','_wpnonce',true,false).'
            <input type="hidden" name="user_id" value="'.intval($uid).'">
            <input type="hidden" name="course" value="'.intval($course_id).'">
            <input type="text"   name="title" value="'.esc_attr($default_title).'" style="width:220px">
            <input type="date"   name="date"  value="'.esc_attr(current_time('Y-m-d')).'">
            <input type="file"   name="cert_pdf" accept="application/pdf" required>
            <button class="button button-primary" name="ebts_upload_cert" value="1">'.($match?'Carica / Sostituisci':'Carica').'</button>
          </form>
        </td>
      </tr>';
    }

    echo '</tbody></table>';

    $next_url=add_query_arg(['view'=>'by_course','course_id'=>$course_id,'per_page'=>$per_page,'paged'=>$paged+1]);
    $prev_url=add_query_arg(['view'=>'by_course','course_id'=>$course_id,'per_page'=>$per_page,'paged'=>max(1,$paged-1)]);
    echo '<p class="tablenav"><a class="button'.($paged<=1?' disabled':'').'" href="'.esc_url($prev_url).'">« Precedente</a>
          <a class="button" href="'.esc_url($next_url).'">Successiva »</a></p>';

    self::handle_upload();
  }

  private static function handle_upload(): void {
    if (empty($_POST['ebts_upload_cert'])) return;
    check_admin_referer('ebts_att_op');
    $uid = (int)($_POST['user_id'] ?? 0);
    $cid = (int)($_POST['course'] ?? 0);
    $title = sanitize_text_field($_POST['title'] ?? 'Attestato');
    $date  = sanitize_text_field($_POST['date'] ?? current_time('Y-m-d'));
    if (!$uid || !$cid || empty($_FILES['cert_pdf']['name'])) { echo '<div class="notice notice-error"><p>Dati mancanti.</p></div>'; return; }

    $rel = null;
    if (!Helpers::store_private_pdf($_FILES['cert_pdf'], 'cert-'.$uid.'-'.$cid.'-'.time().'.pdf', $rel)) {
      echo '<div class="notice notice-error"><p>PDF non valido.</p></div>'; return;
    }
    $certs = get_user_meta($uid, C::META_CERTS, true); if (!is_array($certs)) $certs=[];
    $new = [];
    foreach ($certs as $c) { if ((int)($c['course_id'] ?? 0) !== $cid) $new[] = $c; }
    $new[] = ['id'=>uniqid('cert_',true),'course_id'=>$cid,'title'=>$title,'date'=>$date,'rel'=>$rel];
    update_user_meta($uid, C::META_CERTS, $new);
    echo '<div class="notice notice-success"><p>Attestato caricato.</p></div>';
  }
}
add_action('admin_menu', [AdminAttestati::class, 'menu']);
