<?php
namespace EBTS\CoreLD;
if (!defined('ABSPATH')) exit;

class Sessions_Admin_UI {
  public static function init(){ add_action('admin_menu', [__CLASS__, 'menu']); }

  public static function _ebts_hook_init(){ add_action('admin_post_ebts_delete_session', [__CLASS__, 'handle_delete_session']); }


  public static function menu(){
    add_submenu_page('ebts_iscritti_edit', __('Sessioni', 'ebts'), __('Sessioni', 'ebts'), 'list_users', 'ebts_sessions', [__CLASS__, 'render']);
  }

  public static function render(){
    if (!current_user_can('list_users')){ wp_die('Forbidden'); }
    global $wpdb; $t = Enrollments_Schema::tables();
    $sid = isset($_GET['session_id']) ? (int) $_GET['session_id'] : 0;
    $notice = '';
    // Export CSV/XLSX
    if (!empty($_POST['ebts_export_csv']) && check_admin_referer('ebts_sessions')){
      $sid = (int) ($_POST['session_id'] ?? 0);
      Sessions_Export::export_session($sid, 'csv');
      return;
    }
    if (!empty($_POST['ebts_export_xlsx']) && check_admin_referer('ebts_sessions')){
      $sid = (int) ($_POST['session_id'] ?? 0);
      Sessions_Export::export_session($sid, 'xlsx');
      return;
    }


    // Handle updates
    if (!empty($_POST['ebts_sess_update']) && check_admin_referer('ebts_sessions')){
      $sid = (int) ($_POST['session_id'] ?? 0);
      $capacity = isset($_POST['capacity']) && $_POST['capacity']!=='' ? (int)$_POST['capacity'] : null;
      $waitlist_enabled = isset($_POST['waitlist_enabled']) ? 1 : 0;
      $dates = array_map('sanitize_text_field', (array) ($_POST['dates'] ?? []));
      $dates = array_filter($dates);
      // save session basics
      $wpdb->update($t['sess'], ['capacity'=>$capacity, 'waitlist_enabled'=>$waitlist_enabled], ['id'=>$sid]);
      Enrollments_Logic::save_session_dates($sid, $dates);
      $notice = '<div class="updated"><p>Sessione aggiornata.</p></div>';
    }

    // Promote waitlist
    if (!empty($_POST['ebts_promote']) && check_admin_referer('ebts_sessions')){
      $sid = (int) ($_POST['session_id'] ?? 0);
      $slots = isset($_POST['slots']) ? (int) $_POST['slots'] : 1;
      $moved = Enrollments_Logic::promote_waitlist($sid, $slots);
      $notice = '<div class="updated"><p>Promossi ' . (int)$moved . ' dalla lista d\'attesa.</p></div>';
    }

    // Save attendance for selected enrollments
    if (!empty($_POST['ebts_save_attendance']) && check_admin_referer('ebts_sessions')){
      foreach ((array)($_POST['attendance'] ?? []) as $eid => $map){
        $eid = (int)$eid;
        $norm = [];
        foreach ($map as $date => $val){
          $norm[sanitize_text_field($date)] = $val ? 1 : 0;
        }
        Enrollments_Logic::save_attendance($eid, $norm);
      }
      $notice = '<div class="updated"><p>Presenze salvate.</p></div>';
    }

    // Mark completed bulk
    if (!empty($_POST['ebts_mark_completed']) && check_admin_referer('ebts_sessions')){
      $sid = (int) ($_POST['session_id'] ?? 0);
      $ids = array_map('intval', (array)($_POST['enrollment_id'] ?? []));
      if ($ids){
        foreach ($ids as $eid){
          $wpdb->update($t['enroll'], [
            'status'=>'completed',
            'completed_at'=>current_time('mysql'),
          ], ['id'=>$eid]);
        }
        $notice = '<div class="updated"><p>Iscrizioni segnate come completate.</p></div>';
      }
    }

    echo '<div class="wrap"><h1>Sessioni</h1>';
    // merge notices via GET
    if (isset($_GET['ebts_notice'])){
      $n = sanitize_text_field($_GET['ebts_notice']);
      if ($n === 'has_enrollments'){
        $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
        $sidn  = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
        $force = self::delete_url($sidn, true);
        $notice .= '<div class="notice notice-warning"><p>'
          . sprintf(esc_html__('La sessione ha %d iscrizioni collegate. Puoi usare Elimina (forza).', 'ebts'), $count)
          . ' <a class="button" href="'.esc_url($force).'">'.esc_html__('Elimina (forza)','ebts').'</a></p></div>';
      } elseif ($n === 'deleted'){
        $notice .= '<div class="updated"><p>'.esc_html__('Sessione eliminata.','ebts').'</p></div>';
      } elseif ($n === 'delete_failed'){
        $notice .= '<div class="error"><p>'.esc_html__('Errore durante l\'eliminazione della sessione.','ebts').'</p></div>';
      }
    }
    echo $notice;

    if (!$sid){
      // List sessions
      $rows = $wpdb->get_results("SELECT s.*, p.post_title AS product_title FROM {$t['sess']} s LEFT JOIN {$wpdb->posts} p ON p.ID=s.product_id ORDER BY s.start_date DESC, s.id DESC LIMIT 200");
      echo '<table class="widefat fixed striped"><thead><tr><th>ID</th><th>Corso (prodotto)</th><th>Data inizio</th><th>Sede</th><th>Capienza</th><th>Iscritti</th><th>Lista attesa</th><th>Azioni</th></tr></thead><tbody>';
      foreach ($rows as $r){
        $assigned = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['enroll']} WHERE session_id=%d AND status IN ('assigned','active','completed')", $r->id));
        $wait = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['enroll']} WHERE session_id=%d AND status='waitlist'", $r->id));
        printf('<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td><a class="button" href="%s">Gestisci</a> <a class="button button-link-delete" href="%s" onclick="return confirm(\'Eliminare questa sessione?\');">Elimina</a> <a class="button" style="color:#b32d2e;border-color:#b32d2e" href="%s" onclick="return confirm(\'Eliminare e scollegare tutte le iscrizioni da questa sessione?\');">Elimina (forza)</a></td></tr>',
          (int)$r->id,
          esc_html($r->product_title ?: ('Prodotto #'.$r->product_id)),
          esc_html($r->start_date ? mysql2date('Y-m-d', $r->start_date) : '—'),
          esc_html($r->sede ?: '—'),
          esc_html($r->capacity ? $r->capacity : 'Illimitata'),
          $assigned,
          $wait,
          esc_url(add_query_arg(['page'=>'ebts_sessions','session_id'=>$r->id], admin_url('users.php'))), esc_url(self::delete_url($r->id,false)), esc_url(self::delete_url($r->id,true))
        );
      }
      echo '</tbody></table>';
      echo '</div>';
      return;
    }

    // Single session roster
    $s = Enrollments_Logic::session_load($sid);
    if (!$s){ echo '<p>Sessione non trovata.</p></div>'; return; }
    $dates = Enrollments_Logic::get_session_dates($sid);

    $enrolls = $wpdb->get_results($wpdb->prepare(
      "SELECT e.*, u.display_name, u.user_email FROM {$t['enroll']} e LEFT JOIN {$wpdb->users} u ON u.ID=e.user_id WHERE e.session_id=%d ORDER BY e.status='waitlist' ASC, e.waitlist_position ASC, e.created_at ASC", $sid));

    echo '<h2>Roster Sessione #'.(int)$sid.'</h2>';
    echo '<form method="post">'; wp_nonce_field('ebts_sessions');
    printf('<input type="hidden" name="session_id" value="%d">', (int)$sid);
    echo '<h3>Impostazioni</h3>';
    echo '<p><label>Capienza: <input type="number" name="capacity" min="0" value="'.esc_attr($s->capacity ?: '').'" placeholder="Illimitata"></label> ';
    echo '<label><input type="checkbox" name="waitlist_enabled" '.checked((int)$s->waitlist_enabled,1,false).'> Abilita lista d\'attesa</label></p>';
    echo '<h3>Date presenze</h3>';
    echo '<div id="ebts-dates">';
    if (!$dates) $dates = [];
    foreach ($dates as $i=>$d){
      printf('<p><input type="date" name="dates[]" value="%s"> <button class="button remove-date" onclick="this.parentNode.remove();return false;">Rimuovi</button></p>', esc_attr($d));
    }
    echo '</div>';echo '<p><button type="button" class="button" id="ebts-add-date">Aggiungi data</button></p>';echo '<script>'."document.addEventListener('DOMContentLoaded',function(){var c=document.getElementById('ebts-dates');var b=document.getElementById('ebts-add-date');if(b&&c){b.addEventListener('click',function(e){e.preventDefault();var p=document.createElement('p');p.innerHTML=\'<input type=\"date\" name=\"dates[]\"> <button type=\"button\" class=\"button ebts-remove-date\">Rimuovi</button>\';c.appendChild(p);});c.addEventListener('click',function(e){if(e.target&&e.target.classList&&e.target.classList.contains('ebts-remove-date')){e.preventDefault();var p=e.target.closest('p');if(p){p.remove();}}});}});".'</script>';
    echo '<p><button class="button button-primary" name="ebts_sess_update" value="1">Salva impostazioni & date</button></p>';
    echo '</form>';

    echo '<h3>Roster</h3>';
    echo '<form method="post">'; wp_nonce_field('ebts_sessions');
    printf('<input type="hidden" name="session_id" value="%d">', (int)$sid);
    echo '<table class="widefat fixed striped"><thead><tr>';
    echo '<th class="check-column"><input type="checkbox" onclick="jQuery(\'.ebts-rowcb\').prop(\'checked\', this.checked)"></th>';
    echo '<th>Utente</th><th>Email</th><th>Stato</th>';
    foreach ($dates as $d){ echo '<th>'.esc_html(mysql2date('Y-m-d',$d)).'</th>'; }
    echo '</tr></thead><tbody>';
    if (!$enrolls){
      echo '<tr><td colspan="'.(4+count($dates)).'">Nessun iscritto.</td></tr>';
    } else {
      foreach ($enrolls as $e){
        $att = Enrollments_Logic::get_attendance($e->id);
        echo '<tr>';
        printf('<th class="check-column"><input type="checkbox" class="ebts-rowcb" name="enrollment_id[]" value="%d"></th>', (int)$e->id);
        printf('<td>%s</td><td>%s</td><td>%s%s</td>',
          esc_html($e->display_name ?: ('User #'.$e->user_id)),
          esc_html($e->user_email),
          esc_html($e->status),
          $e->status==='waitlist' && $e->waitlist_position ? ' (pos. '.$e->waitlist_position.')' : ''
        );
        foreach ($dates as $d){
          $checked = !empty($att[$d]) ? 'checked' : '';
          printf('<td><input type="checkbox" name="attendance[%d][%s]" value="1" %s></td>', (int)$e->id, esc_attr($d), $checked);
        }
        echo '</tr>';
      }
    }
    echo '</tbody></table>';
    echo '<p><button class="button" name="ebts_save_attendance" value="1">Salva presenze</button> ';
    echo '<button class="button" name="ebts_mark_completed" value="1">Segna completati (selezionati)</button></p>';
    echo '</form>';

    echo '<h3>Lista attesa</h3>';
    echo '<form method="post">'; wp_nonce_field('ebts_sessions');
    printf('<input type="hidden" name="session_id" value="%d">', (int)$sid);
    echo '<p><label>Posti da liberare: <input type="number" name="slots" value="1" min="1" style="width:80px"></label> ';
    echo '<button class="button" name="ebts_promote" value="1">Promuovi dalla lista d\'attesa</button></p>';
    echo '</form>';

    
    echo '<h3>Export</h3>';
    echo '<form method="post">'; wp_nonce_field('ebts_sessions');
    printf('<input type="hidden" name="session_id" value="%d">', (int)$sid);
    echo '<p><button class="button" name="ebts_export_csv" value="1">Esporta CSV</button> ';
    echo '<button class="button button-primary" name="ebts_export_xlsx" value="1">Esporta XLSX</button></p>';
    echo '</form>';
echo '</div>';
  }
}

class Sessions_Export {
  private static function clean_output_buffers(){
    while (ob_get_level()) { ob_end_clean(); }
  }
  private static function to_csv($headers, $rows){
    $sep = ';';
    $out = "\xEF\xBB\xBF";
    $escape = function($v){
      $v = (string)$v;
      $v = str_replace('"','""',$v);
      return '"' . $v . '"';
    };
    $out .= implode($sep, array_map($escape, $headers)) . "\r\n";
    foreach ($rows as $r){
      $out .= implode($sep, array_map($escape, $r)) . "\r\n";
    }
    return $out;
  }
  private static function xml_escape($s){
    return htmlspecialchars((string)$s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
  }
  private static function to_xlsx($headers, $rows, $filename='export.xlsx'){
    if (!class_exists('\ZipArchive')){ return [false, 'ZipArchive non disponibile']; }
    $zip = new \ZipArchive();
    $tmp = tempnam(sys_get_temp_dir(), 'ebtsxlsx');
    $zip->open($tmp, \ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>\
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">\
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>\
<Default Extension="xml" ContentType="application/xml"/>\
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>\
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>\
<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>\
<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>\
</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>\
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">\
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>\
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>\
<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>\
</Relationships>');
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8"?>\
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" \
xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" \
xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">\
<dc:title>Export</dc:title><dc:creator>EBTS</dc:creator><cp:lastModifiedBy>EBTS</cp:lastModifiedBy>\
</cp:coreProperties>');
    $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8"?>\
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" \
xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">\
<Application>EBTS</Application>\
</Properties>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>\
<Relationships xmlns="http://schemas.openxmlformats.org/officeDocument/2006/relationships">\
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>\
</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>\
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" \
xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">\
<sheets><sheet name="Sessione" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $rows_xml = [];
    $row_idx = 1;
    $make_row = function($cells) use (&$row_idx){
      $cells_xml = '';
      foreach ($cells as $v){
        $v = (string)$v;
        $cells_xml .= '<c r="" t="inlineStr"><is><t>'.Sessions_Export::xml_escape($v).'</t></is></c>';
      }
      $xml = '<row r="'.$row_idx.'">'.$cells_xml.'</row>';
      $row_idx++;
      return $xml;
    };
    $rows_xml[] = $make_row($headers);
    foreach ($rows as $r){ $rows_xml[] = $make_row($r); }
    $sheet = '<?xml version="1.0" encoding="UTF-8"?>\
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">\
<sheetData>'.implode('', $rows_xml).'</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();
    Sessions_Export::clean_output_buffers();
    nocache_headers();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.basename($filename).'"');
    header('Content-Length: '.filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
  }
  public static function export_session($sid, $format='csv'){
    if (!$sid){ wp_die('Sessione non valida'); }
    global $wpdb; $t = Enrollments_Schema::tables();
    $s = Enrollments_Logic::session_load($sid); if (!$s) wp_die('Sessione non trovata');
    $dates = Enrollments_Logic::get_session_dates($sid);
    $headers = ['Sessione ID','Corso (prodotto)','Corso LD','Data inizio','Sede','Capienza','Enrollment ID','User ID','Nome','Email','Stato','Waitlist pos.'];
    foreach ($dates as $d){ $headers[] = 'Presenza ' . mysql2date('Y-m-d', $d); }
    $sql = $wpdb->prepare(
      "SELECT e.*, u.display_name, u.user_email, p.post_title AS product_title FROM {$t['enroll']} e
       LEFT JOIN {$wpdb->users} u ON u.ID=e.user_id
       LEFT JOIN {$wpdb->posts} p ON p.ID=e.product_id
       WHERE e.session_id=%d
       ORDER BY e.status='waitlist' ASC, e.waitlist_position ASC, e.created_at ASC", $sid);
    $rows_db = $wpdb->get_results($sql);
    $rows = [];
    foreach ($rows_db as $r){
      $att = Enrollments_Logic::get_attendance($r->id);
      $row = [
        (int)$sid,
        (string)($r->product_title ?: ('Prodotto #'.$r->product_id)),
        (string)$s->lms_course_id,
        (string)($s->start_date ? mysql2date('Y-m-d', $s->start_date) : ''),
        (string)($s->sede ?: ''),
        (string)($s->capacity ?: ''),
        (int)$r->id,
        (int)$r->user_id,
        (string)($r->display_name ?: ('User #'.$r->user_id)),
        (string)$r->user_email,
        (string)$r->status,
        (string)($r->waitlist_position ?: ''),
      ];
      foreach ($dates as $d){ $row.append(!empty($att[$d]) ? '1' : '0'); }
      $rows[] = $row;
    }
    $fname_csv = 'sessione-'.$sid.'-'.date('Ymd-His').'.csv';
    $fname_xlsx = 'sessione-'.$sid.'-'.date('Ymd-His').'.xlsx';
    if ($format==='csv'){
      $buf = Sessions_Export::to_csv($headers, $rows);
      Sessions_Export::clean_output_buffers();
      nocache_headers();
      header('Content-Type: text/csv; charset=UTF-8');
      header('Content-Disposition: attachment; filename="'.$fname_csv.'"');
      echo $buf; exit;
    } else {
      Sessions_Export::to_xlsx($headers, $rows, $fname_xlsx);
    }
  }

  public static function delete_url($session_id, $force = false){
    $url = add_query_arg([
      'action'     => 'ebts_delete_session',
      'session_id' => absint($session_id),
    ], admin_url('admin-post.php'));
    if ($force) { $url = add_query_arg('force', 1, $url); }
    return wp_nonce_url($url, 'ebts_delete_session_' . absint($session_id));
  }
}
Sessions_Admin_UI::init();
