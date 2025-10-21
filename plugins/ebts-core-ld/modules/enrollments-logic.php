<?php
namespace EBTS\CoreLD;
if (!defined('ABSPATH')) exit;

class Enrollments_Logic {
  public static function get_course_category_slug(): string {
    $slug = get_option('ebts_course_category_slug');
    return is_string($slug) && $slug !== '' ? $slug : 'corsi';
  }

  public static function product_is_in_course_category(int $product_id): bool {
    $slug = self::get_course_category_slug();
    if (!$slug) return false;
    $terms = get_the_terms($product_id, 'product_cat');
    if (is_wp_error($terms) || !$terms) return false;
    foreach ($terms as $t){ if ($t->slug === $slug) return true; }
    return false;
  }

  public static function resolve_product_ids($product_id): array {
    // If variation, include parent
    $parent_id = 0;
    if (function_exists('wc_get_product')){
      $p = wc_get_product($product_id);
      if ($p && $p->is_type('variation')){
        $parent_id = (int) $p->get_parent_id();
      }
    }
    return [$product_id, $parent_id];
  }

  public static function is_course_product(int $product_id): bool {
    // Check meta on product or its parent (for variations)
    list($pid, $parent_id) = self::resolve_product_ids($product_id);
    $base = (int) get_post_meta($pid, '_ebts_ld_course_id', true);
    if (!$base && $parent_id) $base = (int) get_post_meta($parent_id, '_ebts_ld_course_id', true);
    if ($base > 0) return true;
    $flag = get_post_meta($pid, '_ebts_is_course_generic', true);
    if (!$flag && $parent_id) $flag = get_post_meta($parent_id, '_ebts_is_course_generic', true);
    if ($flag === 'yes' || $flag === '1') return true;
    // Category fallback
    if (self::product_is_in_course_category($pid)) return true;
    if ($parent_id && self::product_is_in_course_category($parent_id)) return true;
    return false;
  }

  public static function product_base_course(int $product_id): int {
    list($pid, $parent_id) = self::resolve_product_ids($product_id);
    $base = (int) get_post_meta($pid, '_ebts_ld_course_id', true);
    if (!$base && $parent_id) $base = (int) get_post_meta($parent_id, '_ebts_ld_course_id', true);
    return (int) $base;
  }

  public static function ensure_unique_from_order_item(int $order_item_id): bool {
    global $wpdb; $t = Enrollments_Schema::tables();
    $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['enroll']} WHERE order_item_id=%d", $order_item_id));
    return $exists === 0;
  }

  public static function create_enrollment(array $args): int {
    global $wpdb;
    $t = Enrollments_Schema::tables();
    $now = current_time('mysql');
    $row = [
      'user_id'       => (int) ($args['user_id'] ?? 0),
      'product_id'    => (int) ($args['product_id'] ?? 0),
      'base_course_id'=> isset($args['base_course_id']) ? (int)$args['base_course_id'] : null,
      'session_id'    => isset($args['session_id']) ? (int)$args['session_id'] : null,
      'order_id'      => isset($args['order_id']) ? (int)$args['order_id'] : null,
      'order_item_id' => isset($args['order_item_id']) ? (int)$args['order_item_id'] : null,
      'group_id'      => isset($args['group_id']) ? (int)$args['group_id'] : null,
      'status'        => sanitize_text_field($args['status'] ?? 'pending'),
      'source'        => sanitize_text_field($args['source'] ?? 'checkout'),
      'created_at'    => $args['created_at'] ?? $now,
      'assigned_at'   => $args['assigned_at'] ?? null,
      'completed_at'  => $args['completed_at'] ?? null,
      'meta_json'     => !empty($args['meta']) ? wp_json_encode($args['meta']) : null,
    ];
    $wpdb->insert($t['enroll'], $row);
    return (int) $wpdb->insert_id;
  }

  public static function clone_ld_course(int $base_course_id, string $session_label): int {
    $base = get_post($base_course_id);
    if (!$base || $base->post_type !== 'sfwd-courses') return 0;
    $new_id = wp_insert_post([
      'post_type'   => 'sfwd-courses',
      'post_title'  => $base->post_title . ' — ' . $session_label,
      'post_status' => 'publish',
      'post_content'=> $base->post_content,
      'post_excerpt'=> $base->post_excerpt,
      'post_author' => get_current_user_id() ?: $base->post_author,
    ]);
    if (is_wp_error($new_id) || !$new_id) return 0;
    $meta = get_post_meta($base_course_id);
    foreach ($meta as $k=>$vals){ foreach ($vals as $v){ add_post_meta($new_id, $k, maybe_unserialize($v)); } }
    $taxes = get_object_taxonomies('sfwd-courses');
    foreach ($taxes as $tax){
      $terms = wp_get_object_terms($base_course_id, $tax, ['fields'=>'ids']);
      if (!is_wp_error($terms) && $terms){ wp_set_object_terms($new_id, $terms, $tax, false); }
    }
    return (int) $new_id;
  }

  public static function create_session(array $args): int {
    global $wpdb; $t = Enrollments_Schema::tables(); $now = current_time('mysql');
    $product_id = (int) ($args['product_id'] ?? 0);
    $base_course_id = (int) ($args['base_course_id'] ?? 0);
    $start_date = !empty($args['start_date']) ? date('Y-m-d H:i:s', strtotime($args['start_date'])) : null;
    $end_date   = !empty($args['end_date']) ? date('Y-m-d H:i:s', strtotime($args['end_date'])) : null;
    $sede = sanitize_text_field($args['sede'] ?? '');
    $capacity = isset($args['capacity']) ? (int)$args['capacity'] : null;
    $note = !empty($args['note']) ? wp_kses_post($args['note']) : null;
    $label_bits = []; if ($start_date) $label_bits[] = date_i18n('Y-m-d', strtotime($start_date)); if ($sede) $label_bits[] = $sede;
    $session_label = implode(' • ', $label_bits);
    $lms_course_id = 0;
    if ($base_course_id) { $lms_course_id = self::clone_ld_course($base_course_id, $session_label ?: 'Sessione'); }

    $row = [
      'product_id'    => $product_id,
      'base_course_id'=> $base_course_id ?: null,
      'lms_course_id' => $lms_course_id ?: null,
      'sede'          => $sede ?: null,
      'start_date'    => $start_date,
      'end_date'      => $end_date,
      'capacity'      => $capacity,
      'status'        => 'scheduled',
      'created_by'    => get_current_user_id(),
      'created_at'    => $now,
      'note'          => $note,
    ];
    $wpdb->insert($t['sess'], $row);
    return (int) $wpdb->insert_id;
  }

  public static function assign_enrollments_to_session(array $enrollment_ids, int $session_id): int {
    global $wpdb; $t = Enrollments_Schema::tables();
    $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['sess']} WHERE id=%d", $session_id));
    if (!$session) return 0;
    $lms_course_id = (int) $session->lms_course_id;
    $count=0;
    $cap_left = self::session_capacity_left($session_id);
    $wait_on = (int)$session->waitlist_enabled === 1;
    // find max waitlist_position
    $maxpos = (int)$wpdb->get_var($wpdb->prepare("SELECT MAX(waitlist_position) FROM {$t['enroll']} WHERE session_id=%d", $session_id));
    foreach ($enrollment_ids as $eid){
      $eid = (int)$eid; if (!$eid) continue;
      $en = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['enroll']} WHERE id=%d", $eid));
      if (!$en) continue;
      if ($cap_left > 0){
        $wpdb->update($t['enroll'], [
          'session_id' => $session_id,
          'status'     => 'assigned',
          'assigned_at'=> current_time('mysql'),
          'waitlist_position' => null,
        ], ['id'=>$eid]);
        if ($lms_course_id && function_exists('ld_update_course_access')){
          ld_update_course_access((int)$en->user_id, $lms_course_id, false);
        }
        $cap_left--;
        $count++;
      } else {
        if ($wait_on){
          $maxpos += 1;
          $wpdb->update($t['enroll'], [
            'session_id'=>$session_id,
            'status'=>'waitlist',
            'waitlist_position'=>$maxpos,
          ], ['id'=>$eid]);
          $count++;
        } else {
          // leave pending without session if no capacity and no waitlist
        }
      }
    }
    return $count;
  }

  public static function session_load($session_id){
    global $wpdb; $t = Enrollments_Schema::tables();
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['sess']} WHERE id=%d", $session_id));
  }

  public static function session_capacity_left($session_id): int {
    global $wpdb; $t = Enrollments_Schema::tables();
    $s = self::session_load($session_id); if (!$s) return 0;
    $cap = (int) $s->capacity; if ($cap <= 0) return PHP_INT_MAX; // unlimited
    $used = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$t['enroll']} WHERE session_id=%d AND status IN ('assigned','active','completed')", $session_id));
    $left = max(0, $cap - $used);
    return $left;
  }

  public static function promote_waitlist($session_id, $slots = 1): int {
    global $wpdb; $t = Enrollments_Schema::tables();
    $slots = (int)$slots; if ($slots<=0) return 0;
    $left = self::session_capacity_left($session_id);
    $take = min($left, $slots); if ($take<=0) return 0;
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT id, user_id FROM {$t['enroll']} WHERE session_id=%d AND status='waitlist' ORDER BY waitlist_position ASC, created_at ASC LIMIT %d",
      $session_id, $take));
    $session = self::session_load($session_id);
    $lms_course_id = (int)($session ? $session->lms_course_id : 0);
    $count=0;
    foreach ($rows as $r){
      $wpdb->update($t['enroll'], [
        'status'=>'assigned','assigned_at'=>current_time('mysql'),'waitlist_position'=>null
      ], ['id'=>(int)$r->id]);
      if ($lms_course_id && function_exists('ld_update_course_access')){
        ld_update_course_access((int)$r->user_id, $lms_course_id, false);
      }
      $count++;
    }
    return $count;
  }

  public static function save_session_dates($session_id, array $dates): bool {
    global $wpdb; $t = Enrollments_Schema::tables();
    $dates = array_values(array_unique(array_filter(array_map('sanitize_text_field',$dates))));
    $json = wp_json_encode($dates);
    return false !== $wpdb->update($t['sess'], ['attendance_dates_json'=>$json], ['id'=>$session_id]);
  }

  public static function get_session_dates($session_id): array {
    $s = self::session_load($session_id); if (!$s) return [];
    $arr = json_decode((string)$s->attendance_dates_json, true);
    return is_array($arr) ? $arr : [];
  }

  public static function save_attendance($enrollment_id, array $map): bool {
    global $wpdb; $t = Enrollments_Schema::tables();
    $json = wp_json_encode($map);
    return false !== $wpdb->update($t['enroll'], ['attendance_json'=>$json], ['id'=>$enrollment_id]);
  }

  public static function get_attendance($enrollment_id): array {
    global $wpdb; $t = Enrollments_Schema::tables();
    $json = (string) $wpdb->get_var($wpdb->prepare("SELECT attendance_json FROM {$t['enroll']} WHERE id=%d", $enrollment_id));
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : [];
  }

}
