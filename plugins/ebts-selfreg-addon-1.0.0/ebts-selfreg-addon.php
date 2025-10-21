<?php
/**
 * Plugin Name: EBTS Self-Registration (add-on)
 * Description: Shortcode front-end per iscrizione singolo studente con upload busta paga (richiede EBTS Core (LD)).
 * Version: 1.0.0
 * Author: EBTS
 * License: GPLv2 or later
 */
if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', function () {
  if (!class_exists('EBTS\CoreLD\Helpers')) {
    add_action('admin_notices', function () {
      echo '<div class="notice notice-error"><p><strong>EBTS Self-Registration</strong>: richiede il plugin <em>EBTS Core (LD)</em> attivo.</p></div>';
    });
    return;
  }
  require_once __DIR__ . '/shortcode-iscrizione.php';
});