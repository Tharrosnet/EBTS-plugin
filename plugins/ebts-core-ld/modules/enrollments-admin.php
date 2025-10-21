<?php
namespace EBTS\CoreLD;
if (!defined('ABSPATH')) exit;

class Enrollments_Admin_UI {
  public static function init(){ add_action('admin_menu', [__CLASS__,'menu']); add_action('admin_enqueue_scripts',[__CLASS__,'assets']); }

  public static function assets($hook){
    $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
    if ($page !== 'ebts_enrollments') return;
    wp_enqueue_style('ebts-enrollments-admin', plugins_url('assets/admin-enrollments.css', EBTS_CORE_LD_FILE), [], EBTS_CORE_LD_VER);
  }

  public static function menu(){
    add_submenu_page('ebts_iscritti_edit', __('Iscrizioni','ebts'), __('Iscrizioni','ebts'), 'list_users', 'ebts_enrollments', [__CLASS__, 'render']);
  }
  public static function render(){
    if (!current_user_can('list_users')){ wp_die('Forbidden'); }
    global $wpdb; $t = Enrollments_Schema::tables();
    $notice='';
    // Handle certificate upload per row
    if (!empty($_POST['ebts_upload_cert']) && check_admin_referer('ebts_enrollments_upload') && !empty($_FILES['cert_pdf'])){
      $eid = (int) ($_POST['single_enrollment_id'] ?? 0);
      $file = $_FILES['cert_pdf'] ?? null;
      if ($eid && $file && is_uploaded_file($file['tmp_name'])){
        // Validate PDF
        $ok = false;
        $fh = fopen($file['tmp_name'], 'rb'); $head = $fh ? fread($fh, 5) : ''; if ($fh) fclose($fh);
        if (strpos($head, '%PDF-') === 0) $ok = true;
        $ft = wp_check_filetype($file['name'], ['pdf'=>'application/pdf']);
        if ($ft && $ft['ext']==='pdf') $ok = $ok || true;
        if ($ok){
          $uploads = wp_upload_dir();
          $dir = trailingslashit($uploads['basedir']).'ebts/certificati/'.$eid;
          if (!wp_mkdir_p($dir)) $dir = $uploads['basedir'];
          $dest = trailingslashit($dir) . 'certificato-'.$eid.'-'.time().'.pdf';
          if (move_uploaded_file($file['tmp_name'], $dest)){
            $rel = ltrim(str_replace($uploads['basedir'], '', $dest), '/');
            $token = Certificates_Service::attach_token($eid, $rel);
            $notice = '<div class="updated"><p>Attestato caricato. Token: ' . esc_html($token) . '</p></div>';
          } else {
            $notice = '<div class="error"><p>Impossibile salvare il file.</p></div>';
          }
        } else {
          $notice = '<div class="error"><p>File non valido: caricare un PDF.</p></div>';
        }
      }
    }

    if (!empty($_POST['ebts_action']) && check_admin_referer('ebts_enrollments')){
      $ids = array_map('intval', (array)($_POST['enrollment_id'] ?? [])); $ids = array_filter($ids);
      if ($ids){
        if ($_POST['ebts_action']==='create_and_assign'){
          $product_id=(int)($_POST['product_id']??0);
          $base_course_id=(int)($_POST['base_course_id']??0);
          $start_date=sanitize_text_field($_POST['start_date']??'');
          $sede=sanitize_text_field($_POST['sede']??'');
          $session_id = Enrollments_Logic::create_session(['product_id'=>$product_id,'base_course_id'=>$base_course_id,'start_date'=>$start_date,'sede'=>$sede]);
          if ($session_id){ $count = Enrollments_Logic::assign_enrollments_to_session($ids, $session_id); $notice = '<div class="updated"><p>Assegnate ' . (int)$count . ' iscrizioni alla nuova sessione.</p></div>'; }
          else { $notice = '<div class="error"><p>Impossibile creare la sessione.</p></div>'; }
        } elseif ($_POST['ebts_action']==='assign_existing'){
          $session_id=(int)($_POST['session_id']??0);
          if ($session_id){ $count = Enrollments_Logic::assign_enrollments_to_session($ids, $session_id); $notice = '<div class="updated"><p>Assegnate ' . (int)$count . ' iscrizioni alla sessione.</p></div>'; }
        }
      }
    }
    $filter_course = isset($_GET['filter_course']) ? (int) $_GET['filter_course'] : 0;
    $filter_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '';
    $filter_assigned = isset($_GET['filter_assigned']) ? sanitize_text_field($_GET['filter_assigned']) : '';
    $where=['1=1']; $params=[];
    if ($filter_course){ $where[]='e.product_id=%d'; $params[]=$filter_course; }
    if ($filter_status){ $where[]='e.status=%s'; $params[]=$filter_status; }
    if ($filter_assigned==='yes'){ $where[]='e.session_id IS NOT NULL'; }
    if ($filter_assigned==='no'){ $where[]='e.session_id IS NULL'; }
    $where_sql=implode(' AND ', $where);
    $sql = "SELECT e.*, u.user_email, u.display_name, u.ID as uID, p.post_title AS product_title, s.start_date, s.sede
            FROM {$t['enroll']} e
            LEFT JOIN {$wpdb->users} u ON u.ID = e.user_id
            LEFT JOIN {$wpdb->posts} p ON p.ID = e.product_id
            LEFT JOIN {$t['sess']} s ON s.id = e.session_id
            WHERE $where_sql
            ORDER BY e.created_at DESC
            LIMIT 200";
    $prepared = $params ? $wpdb->prepare($sql, $params) : $sql;
    $rows = $wpdb->get_results($prepared);
    $products = get_posts(['post_type'=>'product','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC','fields'=>'ids']);
    $sessions = $wpdb->get_results("SELECT id, lms_course_id, start_date, sede FROM {$t['sess']} ORDER BY start_date DESC");
    echo '<div class="wrap"><h1>Iscrizioni</h1>'; echo $notice;
    echo '<form method="get"><input type="hidden" name="page" value="ebts_enrollments">';
    echo '<div class="ebts-enrollments-bar ebts-card">';
    echo '<label>Corso (prodotto): <select name="filter_course"><option value="">— Tutti —</option>';
    foreach ($products as $pid){ printf('<option value="%d"%s>%s</option>', $pid, selected($filter_course,$pid,false), esc_html(get_the_title($pid))); }
    echo '</select></label>';
    echo '<label>Stato: <select name="filter_status"><option value="">— Tutti —</option>';
    foreach (['pending','assigned','active','completed','cancelled','refunded'] as $st){ printf('<option value="%s"%s>%s</option>', esc_attr($st), selected($filter_status,$st,false), esc_html($st)); }
    echo '</select></label>';
    echo '<label>Assegnazione: <select name="filter_assigned"><option value="">— Tutte —</option>';
    foreach (['yes'=>'Assegnate','no'=>'Non assegnate'] as $k=>$lbl){ printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($filter_assigned,$k,false), esc_html($lbl)); }
    echo '</select></label>'; submit_button('Filtra', 'secondary', '', false); echo '</div></form>';
    echo '<form id="ebts-assign-form" method="post">'; wp_nonce_field('ebts_enrollments'); echo '<div id="ebts-selected" style="display:none"></div>'; 
    echo '<div class="ebts-actions-split">';
    echo '<div class="ebts-card"><label><strong>Crea sessione & assegna</strong></label><div class="ebts-fields-row">';
    echo '<label>Corso (prodotto): <select name="product_id">';
    foreach ($products as $pid){ printf('<option value="%d">%s</option>', $pid, esc_html(get_the_title($pid))); }
    echo '</select></label>';
    echo '<label>Base course ID (LD): <input type="number" name="base_course_id" min="1" class="small-text" placeholder="ID"></label>';
    echo '<label>Data inizio: <input type="date" name="start_date" placeholder="yyyy-mm-dd"></label>';
    echo '<label>Sede: <input type="text" name="sede" class="regular-text" placeholder="Sede"></label>';
    echo '<p><button class="button button-primary" name="ebts_action" value="create_and_assign">Crea & assegna</button></p></div>';
    echo '<div class="ebts-card"><label><strong>oppure Assegna a sessione esistente</strong></label><div class="ebts-fields-row">';
    echo '<label>Sessione: <select name="session_id"><option value="">— Seleziona —</option>';
    foreach ($sessions as $s){ $label = trim(($s->start_date ? mysql2date('Y-m-d', $s->start_date) : '') . ' ' . ($s->sede ?: '')); if (!$label) $label = 'Sessione #'.$s->id; printf('<option value="%d">%s</option>', (int)$s->id, esc_html($label)); }
    echo '</select></label>'; echo '<button class="button" name="ebts_action" value="assign_existing">Assegna</button></div>';
    echo '</div>'; echo '</form>'; echo '<script>document.addEventListener("DOMContentLoaded",function(){var f=document.getElementById("ebts-assign-form"); if(!f) return;function injectSelected(){var box=document.getElementById("ebts-selected"); if(!box) return; box.innerHTML="";var cbs=document.querySelectorAll(".ebts-rowcb:checked"); cbs.forEach(function(cb){var i=document.createElement("input"); i.type="hidden"; i.name="enrollment_id[]"; i.value=cb.value; box.appendChild(i);});}f.addEventListener("submit",function(){injectSelected();});var btns=f.querySelectorAll("button[name=ebts_action]"); btns.forEach(function(b){b.addEventListener("click",function(){injectSelected();});});});</script>';
    echo '<table class="widefat fixed striped">';
    echo '<thead><tr>';
    echo '<td class="manage-column column-cb check-column"><input type="checkbox" onclick="jQuery(\'.ebts-rowcb\').prop(\'checked\', this.checked)"></td>';
    echo '<th>Data iscrizione</th><th>Utente</th><th>Email</th><th>Corso (prodotto)</th><th>Sessione</th><th>Stato</th><th>Sorgente</th><th>Attestato</th>';
    echo '</tr></thead><tbody>';
    if (!$rows){ echo '<tr><td colspan="8">Nessuna iscrizione trovata.</td></tr>'; } else {
      foreach ($rows as $r){
        $uname = $r->display_name ?: ('User #'.$r->uID);
        $sess_label = $r->start_date ? (mysql2date('Y-m-d', $r->start_date) . ($r->sede ? ' • '.$r->sede : '')) : '—';
        printf('<tr><th class="check-column"><input type="checkbox" class="ebts-rowcb" name="enrollment_id[]" value="%d"></th><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>'.'<form method="post" enctype="multipart/form-data" style="display:inline">'.wp_nonce_field('ebts_enrollments_upload','_wpnonce',true,false).'<input type="hidden" name="single_enrollment_id" value="%d">'.'<input type="file" name="cert_pdf" accept="application/pdf" style="width:180px"> '.'<button class="button" name="ebts_upload_cert" value="1">Carica</button>'.'</form>'.'</td></tr>',
          (int)$r->id,
          esc_html(mysql2date('Y-m-d H:i', $r->created_at)),
          esc_html($uname),
          esc_html($r->user_email),
          esc_html($r->product_title ?: ('Prodotto #'.$r->product_id)),
          esc_html($sess_label),
          esc_html($r->status),
          esc_html($r->source),
          (int)$r->id
        );
      }
    }
    echo '</tbody></table>'; echo '</div>';
  }
}
Enrollments_Admin_UI::init();
