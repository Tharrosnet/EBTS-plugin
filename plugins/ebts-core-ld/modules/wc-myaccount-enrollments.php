<?php
namespace EBTS\CoreLD;
if (!defined('ABSPATH')) exit;

class WC_MyAccount_Enrollments {
  public static function init(){
    add_action('init', [__CLASS__, 'add_endpoints']);
    add_filter('woocommerce_get_query_vars', [__CLASS__, 'add_query_vars']);
    add_filter('woocommerce_account_menu_items', [__CLASS__, 'menu_items']);
    add_action('woocommerce_account_iscrizioni_endpoint', [__CLASS__, 'render_iscrizioni']);
    add_action('woocommerce_account_gl-iscrizioni_endpoint', [__CLASS__, 'render_gl_iscrizioni']);
    add_action('woocommerce_account_gl-iscrivi_endpoint', [__CLASS__, 'render_gl_iscrivi']);
    if (defined('EBTS_CORE_LD_FILE')) { register_activation_hook(EBTS_CORE_LD_FILE, [__CLASS__, 'activate']); }
  }

  public static function activate(){
    self::add_endpoints();
    flush_rewrite_rules();
  }

  public static function is_gl($user_id = 0){
    $user_id = $user_id ?: get_current_user_id();
    $u = get_userdata($user_id);
    if (!$u) return false;
    if (in_array('group_leader', (array)$u->roles, true)) return true;
    return user_can($user_id, 'group_leader');
  }

  public static function add_endpoints(){
    add_rewrite_endpoint('iscrizioni', EP_ROOT | EP_PAGES);
    add_rewrite_endpoint('gl-iscrizioni', EP_ROOT | EP_PAGES);
    add_rewrite_endpoint('gl-iscrivi', EP_ROOT | EP_PAGES);
  }

  public static function add_query_vars($vars){
    $vars['iscrizioni'] = 'iscrizioni';
    $vars['gl-iscrizioni'] = 'gl-iscrizioni';
    $vars['gl-iscrivi'] = 'gl-iscrivi';
    return $vars;
  }

  private static function insert_after($items, $after_key, $new_key, $new_label){
    $out = [];
    $inserted = false;
    foreach ($items as $k=>$v){
      $out[$k] = $v;
      if (!$inserted && $k === $after_key){
        $out[$new_key] = $new_label;
        $inserted = true;
      }
    }
    if (!$inserted) $out[$new_key] = $new_label;
    return $out;
  }

  public static function menu_items($items){
    // Aggiunge "Le mie iscrizioni" dopo "orders" (o in coda se non esiste)
    if (is_user_logged_in()){
      $items = self::insert_after($items, 'orders', 'iscrizioni', __('Le mie iscrizioni','ebts'));
      if (self::is_gl()){
        $items = self::insert_after($items, 'iscrizioni', 'gl-iscrizioni', __('Iscrizioni gruppo','ebts'));
        $items = self::insert_after($items, 'gl-iscrizioni', 'gl-iscrivi', __('Iscrivi in gruppo','ebts'));
      } else {
        // se non GL, rimuovi eventuali voci residue
        unset($items['gl-iscrizioni'], $items['gl-iscrivi']);
      }
    }
    return $items;
  }

  public static function render_iscrizioni(){
    if (!is_user_logged_in()){ echo '<p>'.esc_html__('Devi effettuare l\'accesso.','ebts').'</p>'; return; }
    echo do_shortcode('[ebts_mie_iscrizioni]');
  }

  public static function render_gl_iscrizioni(){
    if (!self::is_gl()){ echo '<p>'.esc_html__('Non autorizzato.','ebts').'</p>'; return; }
    echo do_shortcode('[ebts_gl_iscrizioni]');
  }

  public static function render_gl_iscrivi(){
    if (!self::is_gl()){ echo '<p>'.esc_html__('Non autorizzato.','ebts').'</p>'; return; }
    echo do_shortcode('[ebts_gl_bulk_enroll]');
  }
}
WC_MyAccount_Enrollments::init();
