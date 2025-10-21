<?php
namespace EBTS\CoreLD;
if (!defined('ABSPATH')) exit;

class Enrollments_Shortcodes {
  public static function init(){
    add_shortcode('ebts_mie_iscrizioni', [__CLASS__, 'sc_my_enrollments']);
    add_shortcode('ebts_gl_iscrizioni', [__CLASS__, 'sc_gl_enrollments']);
    add_shortcode('ebts_gl_bulk_enroll', [__CLASS__, 'sc_gl_bulk_enroll']);
    add_action('init', [__CLASS__,'handle_download']); // download attestato
  }

  private static function is_gl($user_id=null): bool {
    $u = get_userdata($user_id ?: get_current_user_id());
    if (!$u) return false;
    return in_array('group_leader', (array)$u->roles, true) || user_can($u, 'group_leader');
  }

  public static function handle_download(){
    if (!isset($_GET['ebts_download_cert'])) return;
    $enrollment_id = isset($_GET['enrollment']) ? (int) $_GET['enrollment'] : 0;
    $nonce = $_GET['_wpnonce'] ?? '';
    if (!$enrollment_id || !wp_verify_nonce($nonce, 'ebts_dl_cert_'.$enrollment_id)) wp_die('Link non valido');
    global $wpdb; $t = Enrollments_Schema::tables();
    $row = $wpdb->get_row($wpdb->prepare("SELECT e.user_id, c.rel_path FROM {$t['enroll']} e JOIN {$t['certs']} c ON c.enrollment_id=e.id WHERE e.id=%d ORDER BY c.issued_at DESC LIMIT 1", $enrollment_id));
    if (!$row) wp_die('Attestato non disponibile.');
    $uid = get_current_user_id();
    $allowed = (current_user_can('manage_options') || (int)$row->user_id === $uid);
    if (!$allowed && self::is_gl($uid)){
      // GL can download solo se l'utente è nel suo gruppo
      $gids = Helpers::get_user_group_ids($uid);
      $uids = Helpers::get_groups_user_ids($gids);
      $allowed = in_array((int)$row->user_id, array_map('intval', (array)$uids), true);
    }
    if (!$allowed) wp_die('Non autorizzato.');
    $uploads = wp_upload_dir();
    $path = trailingslashit($uploads['basedir']) . ltrim($row->rel_path, '/');
    if (!file_exists($path)) wp_die('File non trovato.');
    nocache_headers();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="attestato-'.$enrollment_id.'.pdf"');
    readfile($path);
    exit;
  }

  public static function sc_my_enrollments(){
    if (!is_user_logged_in()) return '<p>Devi effettuare l\'accesso.</p>';
    global $wpdb; $t = Enrollments_Schema::tables();
    $uid = get_current_user_id();
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT e.*, p.post_title AS product_title, s.start_date, s.sede,
        (SELECT rel_path FROM {$t['certs']} c WHERE c.enrollment_id=e.id ORDER BY c.issued_at DESC LIMIT 1) AS cert_path
       FROM {$t['enroll']} e
       LEFT JOIN {$wpdb->posts} p ON p.ID=e.product_id
       LEFT JOIN {$t['sess']} s ON s.id=e.session_id
       WHERE e.user_id=%d
       ORDER BY e.created_at DESC", $uid));
    ob_start();
    echo '<table class="ebts-table"><thead><tr><th>Data iscrizione</th><th>Corso</th><th>Sessione</th><th>Stato</th><th>Attestato</th></tr></thead><tbody>';
    if (!$rows){ echo '<tr><td colspan="5">Nessuna iscrizione trovata.</td></tr>'; }
    foreach ($rows as $r){
      $sess = $r->start_date ? (mysql2date('Y-m-d', $r->start_date) . ($r->sede ? ' • '.$r->sede : '')) : '—';
      $dl = $r->cert_path ? '<a class="button" href="'.esc_url(add_query_arg(['ebts_download_cert'=>1,'enrollment'=>$r->id,'_wpnonce'=>wp_create_nonce('ebts_dl_cert_'.$r->id)], home_url('/'))).'">Scarica</a>' : '—';
      printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
        esc_html(mysql2date('Y-m-d H:i', $r->created_at)),
        esc_html($r->product_title ?: ('Prodotto #'.$r->product_id)),
        esc_html($sess),
        esc_html($r->status),
        $dl
      );
    }
    echo '</tbody></table>';
    return ob_get_clean();
  }

  public static function sc_gl_enrollments(){
    if (!is_user_logged_in() || !self::is_gl()) return '';
    global $wpdb; $t = Enrollments_Schema::tables();
    $gids = Helpers::get_user_group_ids(get_current_user_id());
    if (!$gids) return '<p>Nessun gruppo assegnato.</p>';
    $uids = Helpers::get_groups_user_ids($gids);
    if (!$uids) return '<p>Nessuna iscrizione.</p>';
    $in = implode(',', array_map('intval', (array)$uids));
    $rows = $wpdb->get_results(
      "SELECT e.*, u.display_name, u.user_email, p.post_title AS product_title, s.start_date, s.sede
       FROM {$t['enroll']} e
       LEFT JOIN {$wpdb->users} u ON u.ID=e.user_id
       LEFT JOIN {$wpdb->posts} p ON p.ID=e.product_id
       LEFT JOIN {$t['sess']} s ON s.id=e.session_id
       WHERE e.user_id IN ($in)
       ORDER BY e.created_at DESC LIMIT 500");
    ob_start();
    echo '<table class="ebts-table"><thead><tr><th>Data iscrizione</th><th>Utente</th><th>Email</th><th>Corso</th><th>Sessione</th><th>Stato</th></tr></thead><tbody>';
    if (!$rows){ echo '<tr><td colspan="6">Nessuna iscrizione.</td></tr>'; }
    foreach ($rows as $r){
      $sess = $r->start_date ? (mysql2date('Y-m-d', $r->start_date) . ($r->sede ? ' • '.$r->sede : '')) : '—';
      printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
        esc_html(mysql2date('Y-m-d H:i', $r->created_at)),
        esc_html($r->display_name ?: ('User #'.$r->user_id)),
        esc_html($r->user_email),
        esc_html($r->product_title ?: ('Prodotto #'.$r->product_id)),
        esc_html($sess),
        esc_html($r->status)
      );
    }
    echo '</tbody></table>';
    return ob_get_clean();
  }

  public static function sc_gl_bulk_enroll(){
    if (!is_user_logged_in() || !self::is_gl()) return '';
    $uid = get_current_user_id();
    $gids = Helpers::get_user_group_ids($uid);
    if (!$gids) return '<p>Nessun gruppo assegnato.</p>';
    $users = Helpers::get_groups_user_ids($gids);
    if (!$users) return '<p>Nessun dipendente nel gruppo.</p>';
    // Handle POST
    if (!empty($_POST['ebts_gl_bulk_enroll']) && check_admin_referer('ebts_gl_bulk_enroll')){
      $user_ids = array_map('intval', (array)($_POST['user_ids'] ?? []));
      $product_id = (int) ($_POST['product_id'] ?? 0);
      if ($user_ids && $product_id && Enrollments_Logic::is_course_product($product_id)){
        foreach ($user_ids as $u){
          Enrollments_Logic::create_enrollment([
            'user_id' => $u,
            'product_id' => $product_id,
            'base_course_id' => Enrollments_Logic::product_base_course($product_id) ?: null,
            'group_id' => (int) ($gids[0] ?? 0),
            'status' => 'pending',
            'source' => 'groupleader',
          ]);
        }
        echo '<div class="ebts-notice success">Iscrizioni create.</div>';
      } else {
        echo '<div class="ebts-notice error">Seleziona utenti e un corso valido.</div>';
      }
    }
    // Build UI
    $product_ids = get_posts(['post_type'=>'product','posts_per_page'=>-1,'fields'=>'ids','orderby'=>'title','order'=>'ASC']);
    $course_products = [];
    foreach ($product_ids as $pid){ if (Enrollments_Logic::is_course_product((int)$pid)) $course_products[] = (int)$pid; }
    ob_start();
    echo '<form method="post">'; wp_nonce_field('ebts_gl_bulk_enroll');
    echo '<h3>Iscrivi dipendenti a corso generico</h3>';
    echo '<p><label>Seleziona corso: <select name="product_id"><option value="">— Seleziona —</option>';
    foreach ($course_products as $pid){ printf('<option value="%d">%s</option>', $pid, esc_html(get_the_title($pid))); }
    echo '</select></label></p>';
    echo '<div style="max-height:300px;overflow:auto;border:1px solid #ddd;padding:10px">';
    foreach ($users as $u){ $uobj = get_userdata($u); if (!$uobj) continue;
      printf('<label style="display:block"><input type="checkbox" name="user_ids[]" value="%d"> %s &lt;%s&gt;</label>', (int)$u, esc_html($uobj->display_name), esc_html($uobj->user_email));
    }
    echo '</div><p><button class="button button-primary" name="ebts_gl_bulk_enroll" value="1">Crea iscrizioni</button></p></form>';
    return ob_get_clean();
  }
}
Enrollments_Shortcodes::init();
