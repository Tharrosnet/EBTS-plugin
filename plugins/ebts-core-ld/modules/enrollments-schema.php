<?php
namespace EBTS\CoreLD;
if (!defined('ABSPATH')) exit;

class Enrollments_Schema {
  const OPT_VER = 'ebts_schema_version';
  const VER = '1.1.0';

  public static function init(){ add_action('plugins_loaded', [__CLASS__, 'maybe_install']); }

  public static function maybe_install(){
    $v = get_option(self::OPT_VER);
    if ($v === self::VER) return;
    self::install(); update_option(self::OPT_VER, self::VER);
  }

  public static function tables(){
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $enroll = $wpdb->prefix . 'ebts_enrollments';
    $sess   = $wpdb->prefix . 'ebts_sessions';
    $certs  = $wpdb->prefix . 'ebts_enrollment_certs';
    return compact('enroll','sess','certs','charset');
  }

  public static function install(){
    global $wpdb; require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    extract(self::tables());

    $sql1 = "CREATE TABLE $enroll (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      product_id BIGINT UNSIGNED NOT NULL,
      base_course_id BIGINT UNSIGNED NULL,
      session_id BIGINT UNSIGNED NULL,
      order_id BIGINT UNSIGNED NULL,
      order_item_id BIGINT UNSIGNED NULL,
      group_id BIGINT UNSIGNED NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'pending',
      source VARCHAR(20) NOT NULL DEFAULT 'checkout',
      created_at DATETIME NOT NULL,
      assigned_at DATETIME NULL,
      completed_at DATETIME NULL,
      meta_json LONGTEXT NULL,
      PRIMARY KEY (id),
      KEY idx_user (user_id),
      KEY idx_product (product_id),
      KEY idx_basecourse (base_course_id),
      KEY idx_session (session_id),
      KEY idx_group (group_id),
      KEY idx_status (status),
      KEY idx_created (created_at),
      KEY idx_orderitem (order_item_id)
          KEY idx_orderitem (order_item_id)
    ) $charset;";

    $sql_alter1 = "ALTER TABLE $enroll 
      ADD COLUMN attendance_json LONGTEXT NULL AFTER completed_at,
      ADD COLUMN renewal_from_enrollment_id BIGINT UNSIGNED NULL AFTER attendance_json,
      ADD COLUMN waitlist_position INT NULL AFTER renewal_from_enrollment_id
    ";

    $sql2 = "CREATE TABLE $sess (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      product_id BIGINT UNSIGNED NOT NULL,
      base_course_id BIGINT UNSIGNED NULL,
      lms_course_id BIGINT UNSIGNED NULL,
      sede VARCHAR(191) NULL,
      start_date DATETIME NULL,
      end_date DATETIME NULL,
      capacity INT NULL,
      waitlist_enabled TINYINT(1) NOT NULL DEFAULT 1,
      status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
      created_by BIGINT UNSIGNED NULL,
      created_at DATETIME NOT NULL,
      note TEXT NULL,
      attendance_dates_json LONGTEXT NULL,
      PRIMARY KEY (id),
      KEY idx_product (product_id),
      KEY idx_lms (lms_course_id),
      KEY idx_start (start_date),
      KEY idx_status (status)
    ) $charset;";

    $sql3 = "CREATE TABLE $certs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      enrollment_id BIGINT UNSIGNED NOT NULL,
      title VARCHAR(191) NULL,
      rel_path VARCHAR(255) NOT NULL,
      issued_at DATETIME NOT NULL,
      issued_by BIGINT UNSIGNED NULL,
      verify_token VARCHAR(64) NULL,
      PRIMARY KEY (id),
      KEY idx_enroll (enrollment_id),
      KEY idx_issued (issued_at)
    ) $charset;";

    dbDelta($sql1); dbDelta($sql2); dbDelta($sql3);
    // best-effort alters for new columns (ignore errors)
    if (method_exists($wpdb, 'query')) { @ $wpdb->query($sql_alter1); }

  }
}
Enrollments_Schema::init();
