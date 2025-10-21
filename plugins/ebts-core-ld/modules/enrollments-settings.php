<?php
namespace EBTS\CoreLD;
if (!defined('ABSPATH')) exit;

class Enrollments_Settings {
  public static function init(){
    add_action('admin_menu', [__CLASS__,'menu']);
    add_action('admin_init', [__CLASS__,'register']);
  }
  public static function menu(){
    add_submenu_page('ebts_iscritti_edit', __('Impostazioni EBTS', 'ebts'), __('Impostazioni', 'ebts'), 'manage_options', 'ebts_settings', [__CLASS__, 'render']);
  }
  public static function register(){
    register_setting('ebts_settings', 'ebts_course_category_slug', ['type'=>'string','sanitize_callback'=>'sanitize_title']);
    add_settings_section('ebts_sec_courses', __('Corsi generici', 'ebts'), function(){
      echo '<p>Imposta la categoria prodotti che identifica i <strong>corsi generici</strong> (fallback se mancano i meta sul prodotto).</p>';
    }, 'ebts_settings');
    add_settings_field('ebts_course_category_slug', __('Categoria prodotti (slug)', 'ebts'), function(){
      $val = get_option('ebts_course_category_slug', 'corsi');
      echo '<input type="text" name="ebts_course_category_slug" value="'.esc_attr($val).'" class="regular-text">';
      echo '<p class="description">Esempio: <code>corsi</code>. Lasciare <code>corsi</code> se non si usa una categoria dedicata.</p>';
    }, 'ebts_settings', 'ebts_sec_courses');
  }
  public static function render(){
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    echo '<div class="wrap"><h1>Impostazioni EBTS</h1>';
    echo '<form method="post" action="options.php">';
    settings_fields('ebts_settings');
    do_settings_sections('ebts_settings');
    submit_button();
    echo '</form></div>';
  }
}
Enrollments_Settings::init();
