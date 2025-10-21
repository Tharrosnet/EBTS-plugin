<?php
namespace EBTS\CoreLD; if (!defined('ABSPATH')) exit;

class Helpers {
  public static function ld_enroll_user_to_course(int $user_id, int $course_id): void {
    if (function_exists('learndash_enroll_user')) { learndash_enroll_user($user_id, $course_id); }
    elseif (function_exists('ld_update_course_access')) { ld_update_course_access($user_id, $course_id); }
  }
  public static function ld_add_user_to_group(int $user_id, int $group_id): void {
    if (function_exists('ld_update_group_access')) { ld_update_group_access($user_id, $group_id); }
  }
  public static function get_admin_group_ids(int $user_id): array {
    if (function_exists('learndash_get_administrators_group_ids')) return (array) learndash_get_administrators_group_ids($user_id);
    if (function_exists('ld_get_administrators_group_ids')) return (array) ld_get_administrators_group_ids($user_id);
    return [];
  }
  public static function get_user_group_ids(int $user_id): array {
    if (function_exists('learndash_get_users_group_ids')) return (array) learndash_get_users_group_ids($user_id);
    if (function_exists('ld_get_mapped_user_groups')) return (array) ld_get_mapped_user_groups($user_id);
    return [];
  }
  public static function get_groups_user_ids(array $group_ids): array {
    $ids=[];
    foreach ($group_ids as $gid) {
      if (function_exists('learndash_get_groups_user_ids')) $ids = array_merge($ids, (array) learndash_get_groups_user_ids($gid));
    }
    return array_values(array_unique(array_map('intval',$ids)));
  }
  public static function get_course_user_ids(int $course_id, int $paged=1, int $per_page=50): array {
    if (!$course_id) return [];
    if (function_exists('learndash_get_users_for_course')) {
      $res = learndash_get_users_for_course($course_id, ['number'=>$per_page,'paged'=>$paged], true);
      if (is_a($res, 'WP_User_Query')) {
        $objs = (array) $res->get_results();
        $ids = []; foreach ($objs as $u) { $ids[] = is_object($u)? (int)$u->ID : (int)$u; }
        return array_values(array_unique($ids));
      } elseif (is_array($res)) {
        if (!empty($res) && is_object(reset($res))) {
          $ids = []; foreach ($res as $u) $ids[] = (int)$u->ID;
          return array_values(array_unique($ids));
        }
        return array_values(array_map('intval',$res));
      }
    }
    if (function_exists('ld_get_course_users')) {
      $res = ld_get_course_users($course_id, ['number'=>$per_page,'paged'=>$paged]);
      if (is_array($res)) {
        $ids = []; foreach ($res as $u) $ids[] = is_object($u)? (int)$u->ID : (int)$u;
        return array_values(array_unique($ids));
      }
    }
    $uq = new \WP_User_Query(['number'=>$per_page,'paged'=>$paged,'fields'=>'all']);
    $ids = [];
    foreach ((array)$uq->get_results() as $u) {
      $uid = is_object($u)? (int)$u->ID : (int)$u;
      $in = false;
      if (function_exists('learndash_is_user_enrolled_in_course')) $in = (bool) learndash_is_user_enrolled_in_course($uid, $course_id);
      elseif (function_exists('learndash_user_get_enrolled_courses')) $in = in_array($course_id, (array) learndash_user_get_enrolled_courses($uid), true);
      if ($in) $ids[] = $uid;
    }
    return array_values(array_unique($ids));
  }
  public static function find_group_by_slug(string $slug): int {
    $q=new \WP_Query(['post_type'=>'groups','name'=>sanitize_title($slug),'posts_per_page'=>1,'post_status'=>'any','fields'=>'ids']);
    return $q->have_posts()? (int)$q->posts[0]:0;
  }

  public static function get_private_dir(): string { return trailingslashit(WP_CONTENT_DIR) . C::PRIVATE_DIR_NAME; }
  public static function ensure_private_dir(): void {
    $dir=self::get_private_dir(); wp_mkdir_p($dir);
    $ht = $dir.'/.htaccess';
    if(!file_exists($ht)) @file_put_contents($ht,"Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    $idx = $dir.'/index.html'; if(!file_exists($idx)) @file_put_contents($idx,'');
  }
  public static function store_private_pdf(array $file,string $dest, ?string &$rel): bool {
    self::ensure_private_dir();
    if(!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return false;
    $t=@mime_content_type($file['tmp_name']); if(stripos((string)$t,'pdf')===false) return false;
    $p=trailingslashit(self::get_private_dir()).sanitize_file_name($dest);
    if(!@move_uploaded_file($file['tmp_name'],$p)) return false;
    $rel=basename($p); return true;
  }
  public static function get_user_by_meta(string $k,string $v): ?\WP_User {
    $q=new \WP_User_Query(['meta_key'=>$k,'meta_value'=>$v,'number'=>1,'fields'=>'all']); $r=$q->get_results(); return $r? $r[0]:null;
  }

  /** Sanifica e deduplica l’array meta degli attestati. */
  public static function sanitize_user_certs_array($meta): array {
    if (!is_array($meta)) return [];
    $dir = trailingslashit(self::get_private_dir());
    $map = []; // key => item (mantiene il più recente)
    foreach ($meta as $c) {
      if (!is_array($c)) continue;
      $rel = basename($c['rel'] ?? '');
      $cid = intval($c['course_id'] ?? 0);
      $title = isset($c['title']) ? (string)$c['title'] : 'Attestato';
      $date  = isset($c['date']) ? (string)$c['date'] : '';
      $id    = isset($c['id']) ? (string)$c['id'] : '';

      if ($rel !== '') {
        $low = strtolower($rel);
        if (strpos($low,'busta') !== false || strpos($low,'payslip') !== false || substr($low,-4) !== '.pdf') continue;
        if (!file_exists($dir . $rel)) continue;
      }

      $key = $rel !== '' ? ('rel:' . $rel) : ($cid > 0 ? ('course:' . $cid) : ('id:' . ($id ?: md5($title.$date))));
      $ts = strtotime($date) ?: 0;
      $item = ['id' => ($id ?: 'file_' . md5(($rel?:$key))), 'course_id' => $cid, 'title' => $title, 'date' => $date, 'rel' => $rel, '_ts' => $ts];
      if (!isset($map[$key]) || $ts >= ($map[$key]['_ts'] ?? 0)) $map[$key] = $item;
    }
    $out = array_values(array_map(function($x){ unset($x['_ts']); return $x; }, $map));
    usort($out, function($a,$b){ $ta=strtotime($a['date']??'')?:0; $tb=strtotime($b['date']??'')?:0; return $tb <=> $ta; });
    return $out;
  }

  /** Ritorna gli attestati; se meta vuota prova a recuperarli dai file. */
  public static function get_user_certs(int $user_id): array {
    $meta = get_user_meta($user_id, C::META_CERTS, true);
    if (!is_array($meta)) $meta = [];
    $meta = self::sanitize_user_certs_array($meta);
    if (!empty($meta)) return $meta;
    $salvaged = self::parse_cert_files_for_user($user_id);
    if (!empty($salvaged)) { update_user_meta($user_id, C::META_CERTS, $salvaged); return $salvaged; }
    return [];
  }

  /** Scansiona la cartella privata e recupera PDF “attestati” per l’utente. */
  public static function parse_cert_files_for_user(int $user_id): array {
    $dir = trailingslashit(self::get_private_dir());
    if (!is_dir($dir)) return [];
    $files = glob($dir . '*.pdf'); if(!$files) return [];
    $out = [];
    foreach ($files as $file) {
      $base = basename($file);
      $low  = strtolower($base);
      if (strpos($low,'busta') !== false || strpos($low,'payslip') !== false) continue; // escludi buste
      $looks_cert = (strpos($low,'cert-') === 0) || (strpos($low,'attestato') !== false) || (strpos($low,'certificato') !== false) || (strpos($low,'certificate') !== false);
      if (!$looks_cert) continue;
      if (!preg_match('/(^|[^0-9])' . preg_quote((string)$user_id, '/') . '([^0-9]|$)/', $low)) continue; // file non dell’utente

      $course_id = 0;
      if (preg_match_all('/\d+/', $base, $nums) && !empty($nums[0])) {
        foreach ($nums[0] as $n) { $n=(int)$n; if ($n>0 && get_post_type($n)==='sfwd-courses') { $course_id=$n; break; } }
      }
      $title = 'Attestato'; if ($course_id) { $t=get_the_title($course_id); if ($t) $title = 'Attestato — ' . $t; }
      $out[] = ['id'=>'file_'.md5($base),'course_id'=>$course_id,'title'=>$title,'date'=>date('Y-m-d', @filemtime($file)?:time()),'rel'=>$base];
    }
    return $out;
  }

  /** Se esiste un file busta-<uid>-*.pdf e manca la meta, la collega. */
  public static function ensure_payslip_meta(int $user_id): void {
    $rel = get_user_meta($user_id, 'busta_paga_rel', true);
    if ($rel) return;
    $dir = trailingslashit(self::get_private_dir());
    if (!is_dir($dir)) return;
    foreach (glob($dir . 'busta-' . $user_id . '-*.pdf') as $file) {
      update_user_meta($user_id, 'busta_paga_rel', basename($file));
      break;
    }
  }

  public static function current_user_is_admin(): bool { return current_user_can('manage_options'); }
}

add_action('user_register', function($uid){
  if(!empty($_SERVER['REMOTE_ADDR'])) update_user_meta($uid,'ip_registrazione',sanitize_text_field($_SERVER['REMOTE_ADDR']));
});
