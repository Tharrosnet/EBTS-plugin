<?php
namespace EBTS\CoreLD;
if (!defined('ABSPATH')) exit;

class Activity_Log {
    public static function init(){
        add_action('init', [__CLASS__, 'register_cpt']);
        add_action('admin_menu', [__CLASS__, 'register_menu'], 50);
    }
    public static function register_cpt(){
        $labels = [
            'name'          => __('Log attività','ebts'),
            'singular_name' => __('Voce log','ebts'),
            'menu_name'     => __('Log attività','ebts'),
        ];
        register_post_type('ebts_activity', [
            'labels'       => $labels,
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => false,
            'supports'     => ['title','editor','author','custom-fields'],
        ]);
    }
    public static function register_menu(){
        add_submenu_page(
            'ebts-core-ld',
            __('Log attività','ebts'),
            __('Log attività','ebts'),
            current_user_can('manage_ebts_sessions') ? 'manage_ebts_sessions' : 'manage_options',
            'edit.php?post_type=ebts_activity'
        );
    }
    public static function add($action, $context = [], $user_id = 0){
        if (!$user_id && is_user_logged_in()) $user_id = get_current_user_id();
        $title = sprintf('[%s] %s', current_time('mysql'), $action);
        $content = !empty($context) ? wp_json_encode($context, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : '';
        $post_id = wp_insert_post([
            'post_type'   => 'ebts_activity',
            'post_status' => 'publish',
            'post_title'  => $title,
            'post_content'=> $content,
            'post_author' => $user_id,
        ], true);
        if (!is_wp_error($post_id) && $post_id){
            update_post_meta($post_id, 'action', sanitize_text_field($action));
            update_post_meta($post_id, 'context', $context);
            update_post_meta($post_id, 'ip', self::client_ip());
            do_action('ebts_activity_logged', $post_id, $action, $context, $user_id);
        }
        return $post_id;
    }
    protected static function client_ip(){
        foreach (['HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k){
            if (!empty($_SERVER[$k])) {
                $ip = is_array($_SERVER[$k]) ? $_SERVER[$k][0] : $_SERVER[$k];
                $ip = explode(',', $ip)[0];
                return sanitize_text_field($ip);
            }
        }
        return '';
    }
}
Activity_Log::init();
