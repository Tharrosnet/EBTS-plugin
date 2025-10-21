<?php
/**
 * Plugin Name: EBTS Core (LD)
 * Description: Gestione iscritti, attestati, busta paga e integrazione LearnDash/WooCommerce per EBTS.
 * \g<1>8.4.4
 * Author: EBTS
 * License: GPLv2 or later
 */
if (!defined('ABSPATH')) exit;

define('EBTS_CORE_LD_VER', '8.6.23');
define('EBTS_CORE_LD_DIR', plugin_dir_path(__FILE__));

if (!defined('EBTS_CORE_LD_FILE')) define('EBTS_CORE_LD_FILE', __FILE__);
define('EBTS_CORE_LD_URL', plugin_dir_url(__FILE__));

require_once EBTS_CORE_LD_DIR . 'includes/constants.php';
require_once EBTS_CORE_LD_DIR . 'includes/helpers.php';
require_once EBTS_CORE_LD_DIR . 'includes/downloads.php';

require_once EBTS_CORE_LD_DIR . 'modules/admin-ajax.php';
require_once EBTS_CORE_LD_DIR . 'modules/admin-iscritti.php';
require_once EBTS_CORE_LD_DIR . 'modules/admin-attestati.php';
require_once EBTS_CORE_LD_DIR . 'modules/admin-export.php';
require_once EBTS_CORE_LD_DIR . 'modules/admin-user-detail.php';
require_once EBTS_CORE_LD_DIR . 'modules/shortcodes.php';
require_once EBTS_CORE_LD_DIR . 'modules/certificates.php';
require_once EBTS_CORE_LD_DIR . 'modules/sessions-admin.php';
require_once EBTS_CORE_LD_DIR . 'modules/wc-myaccount-enrollments.php';
require_once EBTS_CORE_LD_DIR . 'modules/enrollments-shortcodes.php';
require_once EBTS_CORE_LD_DIR . 'modules/enrollments-settings.php';
require_once EBTS_CORE_LD_DIR . 'modules/enrollments-admin.php';
require_once EBTS_CORE_LD_DIR . 'modules/woo-enrollment-hook.php';
require_once EBTS_CORE_LD_DIR . 'modules/enrollments-logic.php';
require_once EBTS_CORE_LD_DIR . 'modules/enrollments-schema.php';
require_once EBTS_CORE_LD_DIR . 'modules/admin-import.php' ;
require_once EBTS_CORE_LD_DIR . 'modules/admin-deletecert-fix.php' ;

add_action('admin_enqueue_scripts', function($hook){
  if (isset($_GET['page']) && strpos(sanitize_text_field($_GET['page']), 'ebts_') === 0) {
    wp_enqueue_style('dashicons');
    wp_enqueue_style('ebts_admin_css', EBTS_CORE_LD_URL . 'assets/admin.css', [], EBTS_CORE_LD_VER);
    wp_enqueue_script('ebts_admin_js', EBTS_CORE_LD_URL . 'assets/admin.js', ['jquery'], EBTS_CORE_LD_VER, true);
    wp_localize_script('ebts_admin_js','EBTSAJ', [
      'ajax' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('ebts_admin_nonce'),
      'download_action' => \EBTS\CoreLD\C::DOWNLOAD_ACTION,
      'detail_url' => admin_url('admin.php?page=ebts_utente&user_id='),
    ]);
  }
});


require_once EBTS_CORE_LD_DIR . 'modules/wc-checkout-fields-cf-payslip.php';

require_once EBTS_CORE_LD_DIR . 'modules/shortcode-registration.php';

require_once EBTS_CORE_LD_DIR . 'modules/wc-myaccount-payslip.php';
require_once EBTS_CORE_LD_DIR . 'modules/shortcode-cart-enroll.php';

require_once EBTS_CORE_LD_DIR . 'modules/activity-log.php';
