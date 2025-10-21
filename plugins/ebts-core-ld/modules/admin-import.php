<?php
namespace EBTS\CoreLD; if (!defined('ABSPATH')) exit;

/**
 * Import di massa (CSV + ZIP buste) per EBTS Core 0.7.8.2
 * - Fase 1: Carica file -> ANTEPRIMA (dry-run)
 * - Fase 2: Conferma import -> Esecuzione
 *
 * Modulo auto‑contenuto: non richiede nuovi metodi in Helpers.
 */
class AdminImport0782 {

  public static function menu(){
    add_submenu_page('ebts_iscritti_edit','Import di massa','Import di massa','list_users','ebts_import',[__CLASS__,'render']);
  }

  public static function render(){
    if (!current_user_can('list_users')) wp_die('Permesso negato');
    echo '<div class="wrap"><h1>Import di massa (CSV + ZIP buste)</h1>';

    // === Fase 2: CONFERMA ===
    if (!empty($_POST['ebts_confirm_import']) && check_admin_referer('ebts_import_confirm')) {
      $token = sanitize_text_field($_POST['import_token'] ?? '');
      $data  = get_transient('ebts_imp_' . $token);
      if (!$data || (int)($data['user'] ?? 0) !== get_current_user_id()) {
        echo '<div class="notice notice-error"><p>Sessione di import non trovata o scaduta. Riesegui l\'upload.</p></div></div>';
        return;
      }
      self::process($data['csv'], $data['zip'], false, $data['opts']);
      self::cleanup_temp($data, $token);
      echo '<p><strong>Import completato.</strong></p>';
      echo '</div>'; return;
    }

    // === Fase 1: ANTEPRIMA ===
    if (!empty($_POST['ebts_do_import']) && check_admin_referer('ebts_import')) {
      $opts = [
        'delim'        => sanitize_text_field($_POST['delim'] ?? 'auto'),
        'create_group' => !empty($_POST['create_group']),
        'notify'       => !empty($_POST['notify']),
      ];
      $persist = self::persist_temp_files($_FILES, $opts);
      if (is_wp_error($persist)) {
        echo '<div class="notice notice-error"><p>'.esc_html($persist->get_error_message()).'</p></div>';
        echo '</div>'; return;
      }
      self::process($persist['csv'], $persist['zip'], true, $opts);
      echo '<form method="post" style="margin-top:16px">';
      wp_nonce_field('ebts_import_confirm');
      echo '<input type="hidden" name="import_token" value="'.esc_attr($persist['token']).'">';
      echo '<p class="submit">';
      echo '<button class="button button-primary" name="ebts_confirm_import" value="1">Conferma import</button> ';
      echo '<a href="'.esc_url(admin_url('admin.php?page=ebts_import')).'" class="button">Annulla</a>';
      echo '</p></form>';
      echo '</div>'; return;
    }

    // === Form iniziale ===
    echo '<p>Carica un <strong>CSV</strong> con intestazione e (opzionale) un <strong>ZIP</strong> con le buste paga PDF.</p>';
    echo '<p><strong>Intestazioni supportate:</strong> nome, cognome, email, cfiscale, telefono, corso|corso_id|corso_slug, azienda|azienda_slug, ip, busta.</p>';
    echo '<form method="post" enctype="multipart/form-data">';
    wp_nonce_field('ebts_import');
    echo '<table class="form-table"><tbody>';
    echo '<tr><th>CSV</th><td><input type="file" name="csv" accept=".csv,text/csv" required></td></tr>';
    echo '<tr><th>ZIP buste (opz.)</th><td><input type="file" name="zip" accept=".zip,application/zip"></td></tr>';
    echo '<tr><th>Delimitatore</th><td><select name="delim"><option value="auto">Auto</option><option value=";">;</option><option value=",">,</option></select></td></tr>';
    echo '<tr><th>Opzioni</th><td>';
    echo '<label><input type="checkbox" name="create_group" value="1"> Crea gruppo (Azienda) se mancante</label><br>';
    echo '<label><input type="checkbox" name="notify" value="1"> Invia email agli utenti nuovi</label>';
    echo '</td></tr>';
    echo '</tbody></table>';
    echo '<p><button class="button button-primary" name="ebts_do_import" value="1">Carica e anteprima</button></p>';
    echo '</form></div>';
  }

  private static function temp_dir(): string {
    $up = wp_get_upload_dir();
    $dir = trailingslashit($up['basedir']) . 'ebts-import-temp';
    wp_mkdir_p($dir); return $dir;
  }
  private static function persist_temp_files(array $files, array $opts){
    if (empty($files['csv']['tmp_name']) || !is_uploaded_file($files['csv']['tmp_name'])) {
      return new \WP_Error('csv_missing','CSV mancante.');
    }
    $dir = self::temp_dir();
    $token = 'u'.get_current_user_id().'_'.wp_generate_password(8,false,false);
    $csv_path = trailingslashit($dir).'import-'.$token.'.csv';
    if (!@move_uploaded_file($files['csv']['tmp_name'], $csv_path)) {
      if (!@copy($files['csv']['tmp_name'], $csv_path)) {
        return new \WP_Error('csv_write','Impossibile salvare il CSV temporaneo.');
      }
    }
    $zip_path = '';
    if (!empty($files['zip']['tmp_name']) && is_uploaded_file($files['zip']['tmp_name'])) {
      $zip_path = trailingslashit($dir).'buste-'.$token.'.zip';
      if (!@move_uploaded_file($files['zip']['tmp_name'], $zip_path)) {
        @copy($files['zip']['tmp_name'], $zip_path);
      }
    }
    set_transient('ebts_imp_'.$token, ['csv'=>$csv_path,'zip'=>$zip_path,'opts'=>$opts,'user'=>get_current_user_id()], HOUR_IN_SECONDS);
    return ['csv'=>$csv_path,'zip'=>$zip_path,'token'=>$token];
  }
  private static function cleanup_temp(array $data, string $token): void {
    if (!empty($data['csv']) && file_exists($data['csv'])) @unlink($data['csv']);
    if (!empty($data['zip']) && file_exists($data['zip'])) @unlink($data['zip']);
    delete_transient('ebts_imp_'.$token);
  }
  private static function private_dir(): string {
    $dir = trailingslashit(WP_CONTENT_DIR) . 'ebts-private';
    if (!is_dir($dir)) {
      wp_mkdir_p($dir);
      if (!file_exists($dir.'/.htaccess')) @file_put_contents($dir+'/.htaccess',"Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
      if (!file_exists($dir.'/index.html')) @file_put_contents($dir+'/index.html','');
    }
    return $dir;
  }
  private static function store_pdf_bytes(string $bytes, string $basename): ?string {
    $dir = self::private_dir();
    $dest = trailingslashit($dir) . sanitize_file_name($basename);
    if (@file_put_contents($dest, $bytes) === false) return null;
    return basename($dest);
  }

  private static function process(string $csv_file, string $zip_file='', bool $dry=true, array $opts=[]): void {
    $create_group = !empty($opts['create_group']);
    $notify       = !empty($opts['notify']);
    $delim        = $opts['delim'] ?? 'auto';

    $zip_files = [];
    if ($zip_file && file_exists($zip_file)) {
      $za = new \ZipArchive();
      if ($za->open($zip_file) === true) {
        for ($i=0; $i<$za->numFiles; $i++){
          $st = $za->statIndex($i); $name = $st['name'];
          if (substr($name,-1)==='/') continue;
          $base = strtolower(basename($name));
          $data = $za->getFromIndex($i);
          if ($data !== false) $zip_files[$base] = $data;
        }
        $za->close();
      }
    }

    $raw = @file_get_contents($csv_file);
    if ($raw === false) { echo '<div class="notice notice-error"><p>Impossibile leggere il CSV.</p></div>'; return; }
    if ($delim === 'auto') $delim = (substr_count($raw, ';') > substr_count($raw, ',')) ? ';' : ',';
    $fh = fopen($csv_file, 'r'); if (!$fh) { echo '<div class="notice notice-error"><p>CSV non apribile.</p></div>'; return; }
    $headers = fgetcsv($fh, 0, $delim);
    if (!$headers) { echo '<div class="notice notice-error"><p>CSV senza intestazioni.</p></div>'; fclose($fh); return; }
    $map=[]; foreach ($headers as $i=>$h) { $map[$i]=strtolower(trim($h)); }

    echo '<h2>'.($dry? 'Anteprima':'Esecuzione import').'</h2>';
    echo '<table class="widefat striped"><thead><tr><th>#</th><th>Utente</th><th>Azione</th><th>Gruppo</th><th>Corso</th><th>Busta</th><th>Note</th></tr></thead><tbody>';

    $admin_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $rownum=0;

    while (($r=fgetcsv($fh,0,$delim))!==false){
      if (count($r)==1 && trim(implode('', $r))==='') continue;
      $row=[]; foreach ($r as $i=>$v){ $row[$map[$i] ?? ('col'.$i)] = trim($v); }
      $rownum++;

      $nome=$row['nome'] ?? ($row['first_name'] ?? '');
      $cogn=$row['cognome'] ?? ($row['last_name'] ?? '');
      $email=$row['email'] ?? '';
      $tel=$row['telefono'] ?? ($row['phone'] ?? '');
      $cf=strtoupper($row['cfiscale'] ?? ($row['codice_fiscale'] ?? ''));
      $ip=$row['ip'] ?? $admin_ip;

      $course_id=0; $corso=$row['corso'] ?? ($row['course'] ?? ''); $corso_id=intval($row['corso_id'] ?? ($row['course_id'] ?? 0)); $corso_slug=$row['corso_slug'] ?? ($row['course_slug'] ?? '');
      if ($corso_id>0 && get_post_type($corso_id)==='sfwd-courses') $course_id=$corso_id;
      if (!$course_id && $corso_slug){ $q=new \WP_Query(['post_type'=>'sfwd-courses','name'=>sanitize_title($corso_slug),'posts_per_page'=>1,'fields'=>'ids']); if ($q->have_posts()) $course_id=(int)$q->posts[0]; }
      if (!$course_id && $corso){ $p=get_page_by_title($corso, OBJECT, 'sfwd-courses'); if ($p) $course_id=(int)$p->ID; }

      $group_id=0; $azienda=$row['azienda'] ?? ($row['group'] ?? ''); $azienda_slug=$row['azienda_slug'] ?? ($row['group_slug'] ?? '');
      if ($azienda_slug){ $q=new \WP_Query(['post_type'=>'groups','name'=>sanitize_title($azienda_slug),'posts_per_page'=>1,'fields'=>'ids']); if ($q->have_posts()) $group_id=(int)$q->posts[0]; }
      if (!$group_id && $azienda){ $p=get_page_by_title($azienda, OBJECT, 'groups'); if ($p) $group_id=(int)$p->ID; }
      if (!$group_id && $azienda && $create_group){ $group_id = wp_insert_post(['post_type'=>'groups','post_status'=>'publish','post_title'=>wp_strip_all_tags($azienda)]); }

      if (!$nome or !$cogn or !$email){
        echo '<tr><td>'.$rownum.'</td><td>'.esc_html($email ?: $cf).'</td><td colspan="5"><span style="color:#d63638">Dati minimi mancanti (nome/cognome/email)</span></td></tr>'; 
        continue;
      }

      $user = get_user_by('email',$email);
      if (!$user && $cf) {
        $uq = new \WP_User_Query(['meta_key'=>'cfiscale','meta_value'=>$cf,'number'=>1,'fields'=>'all']);
        $res = $uq->get_results(); $user = $res? $res[0] : null;
      }
      $uid = $user? (int)$user->ID:0;
      $action = $uid? 'Aggiorna':'Crea';

      if (!$dry){
        if (!$uid){
          $login = sanitize_user(strtolower(preg_replace('/[^a-z0-9]+/','.', $nome.'.'.$cogn)));
          if (username_exists($login)) $login .= '.' . wp_generate_password(4, false, false);
          $pwd = wp_generate_password(12, true, false);
          $uid = wp_insert_user(['user_login'=>$login,'user_pass'=>$pwd,'user_email'=>$email,'first_name'=>$nome,'last_name'=>$cogn,'role'=>'subscriber']);
          if (!is_wp_error($uid) && $notify) wp_new_user_notification($uid, null, 'both');
        } else {
          wp_update_user(['ID'=>$uid,'user_email'=>$email]);
          update_user_meta($uid,'first_name',$nome);
          update_user_meta($uid,'last_name',$cogn);
        }
        if (!is_wp_error($uid) && $uid){
          update_user_meta($uid,'telefono',$tel);
          if ($cf) update_user_meta($uid,'cfiscale', $cf);
          if ($ip) update_user_meta($uid,'ip_registrazione', sanitize_text_field($ip));
        }
        if ($uid && $group_id && function_exists('ld_update_group_access')) { ld_update_group_access($uid, $group_id); }
        if ($uid && $course_id) {
          if (function_exists('learndash_enroll_user')) { learndash_enroll_user($uid, $course_id); }
          elseif (function_exists('ld_update_course_access')) { ld_update_course_access($uid, $course_id); }
        }
      }

      $busta_info='—';
      $bcol = $row['busta'] ?? ($row['payslip'] ?? ($row['busta_paga'] ?? ''));
      if ($bcol && $zip_files){
        $key = strtolower(basename($bcol));
        if (isset($zip_files[$key])){
          if (!$dry && $uid){
            $rel = self::store_pdf_bytes($zip_files[$key], 'busta-'.intval($uid).'-bulk-'.time().'.pdf');
            if ($rel) update_user_meta($uid,'busta_paga_rel',$rel);
          }
          $busta_info = 'Collegata: '.$key;
        } else {
          $busta_info = '<span style="color:#d63638">File non trovato nello ZIP: '.esc_html($key).'</span>';
        }
      }

      echo '<tr>';
      echo '<td>'.$rownum.'</td>';
      echo '<td>'.esc_html($nome.' '.$cogn).' <small>&lt;'.esc_html($email).'&gt;</small></td>';
      echo '<td>'.esc_html($action).'</td>';
      echo '<td>'.($group_id?('#'.$group_id.' '.esc_html(get_the_title($group_id))):'—').'</td>';
      echo '<td>'.($course_id?('#'.$course_id.' '.esc_html(get_the_title($course_id))):'—').'</td>';
      echo '<td>'.$busta_info.'</td>';
      echo '<td></td>';
      echo '</tr>';
    }
    fclose($fh);

    echo '</tbody></table>';
    if ($dry) {
      echo '<p><em>Questa è un\'<strong>anteprima</strong>. Premi "Conferma import" per applicare le modifiche.</em></p>';
    }
  }
}
add_action('admin_menu', [AdminImport0782::class, 'menu']);
