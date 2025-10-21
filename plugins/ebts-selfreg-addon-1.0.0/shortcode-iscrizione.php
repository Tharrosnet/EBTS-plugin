<?php
namespace EBTS\SelfReg; if (!defined('ABSPATH')) exit;
use EBTS\CoreLD\Helpers;

class Shortcode {
  public static function render($atts = []): string {
    $atts = shortcode_atts([
      'require_azienda' => '1',
      'success_message' => 'Iscrizione inviata! Controlla la tua email per completare l\'accesso.',
      'show_privacy'    => '1',
    ], $atts);
    $require_az = $atts['require_azienda'] === '1';
    $notice = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ebts_selfreg']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'ebts_self_reg')) {
      // Honeypot anti-spam
      if (!empty($_POST['website'])) {
        return '<div class="notice notice-error"><p>Errore di validazione.</p></div>';
      }

      $nome   = sanitize_text_field($_POST['nome'] ?? '');
      $cogn   = sanitize_text_field($_POST['cognome'] ?? '');
      $cf     = strtoupper(sanitize_text_field($_POST['codice_fiscale'] ?? ''));
      $email  = sanitize_email($_POST['email'] ?? '');
      $tel    = sanitize_text_field($_POST['telefono'] ?? '');
      $course = isset($_POST['corso_id']) ? (int) $_POST['corso_id'] : 0;
      $azid   = isset($_POST['azienda_id']) ? (int) $_POST['azienda_id'] : 0;

      $errors = [];
      if ($nome === '' || $cogn === '' || $cf === '' || $email === '' || $tel === '' || !$course || ($require_az && !$azid)) $errors[] = 'Tutti i campi sono obbligatori.';
      if (!preg_match('/^[A-Z0-9]{16}$/', $cf)) $errors[] = 'Codice Fiscale non valido (deve essere 16 caratteri alfanumerici).';
      if (!is_email($email)) $errors[] = 'Email non valida.';
      if (empty($_FILES['busta_paga']['name'])) $errors[] = 'Carica la busta paga in PDF.';

      // Duplicati
      if (get_user_by('email', $email)) $errors[] = 'Esiste già un utente con questa email.';
      $dup_cf = Helpers::get_user_by_meta('cfiscale', $cf); if ($dup_cf) $errors[] = 'Esiste già un utente con questo Codice Fiscale.';

      if (empty($errors)) {
        // Crea utente
        $login = sanitize_user( explode('@',$email)[0] . '_' . wp_generate_password(4, false, false), true );
        $pass  = wp_generate_password(12, true);
        $uid = wp_insert_user([
          'user_login' => $login,
          'user_pass'  => $pass,
          'user_email' => $email,
          'first_name' => $nome,
          'last_name'  => $cogn,
          'role'       => 'subscriber',
        ]);
        if (is_wp_error($uid)) {
          $errors[] = 'Creazione utente fallita: ' . $uid->get_error_message();
        } else {
          // Meta
          update_user_meta($uid, 'cfiscale', $cf);
          update_user_meta($uid, 'telefono', $tel);
          if (!empty($_SERVER['REMOTE_ADDR'])) update_user_meta($uid, 'ip_registrazione', sanitize_text_field($_SERVER['REMOTE_ADDR']));

          // Gruppo (azienda)
          if ($azid) { Helpers::ld_add_user_to_group($uid, $azid); }

          // Corso
          Helpers::ld_enroll_user_to_course($uid, $course);

          // Busta paga
          $rel = null;
          if (!Helpers::store_private_pdf($_FILES['busta_paga'], 'busta-' . $uid . '-self-' . time() . '.pdf', $rel)) {
            // Rollback utente se il PDF non è valido
            wp_delete_user($uid);
            $errors[] = 'Il file busta paga non sembra un PDF valido.';
          } else {
            update_user_meta($uid, 'busta_paga_rel', $rel);
            // Notifica email di benvenuto (utente)
            wp_new_user_notification($uid, null, 'user');
          }
        }
      }

      if (!empty($errors)) {
        $notice = '<div class="notice notice-error"><ul><li>' . implode('</li><li>', array_map('esc_html', $errors)) . '</li></ul></div>';
      } else {
        return '<div class="notice notice-success"><p>' . esc_html($atts['success_message']) . '</p></div>';
      }
    }

    // Build form
    $courses = get_posts(['post_type'=>'sfwd-courses','numberposts'=>-1,'post_status'=>'publish','orderby'=>'title','order'=>'ASC']);
    $groups  = get_posts(['post_type'=>'groups','numberposts'=>-1,'post_status'=>'publish','orderby'=>'title','order'=>'ASC']);

    ob_start();
    echo $notice;
    echo '<form method="post" enctype="multipart/form-data" class="ebts-selfreg">';
    wp_nonce_field('ebts_self_reg');
    echo '<input type="hidden" name="ebts_selfreg" value="1">';
    // Honeypot
    echo '<div style="position:absolute;left:-9999px;top:-9999px;"><label>Website <input type="text" name="website" value=""></label></div>';

    echo '<p><label>Nome<br><input class="regular-text" type="text" name="nome" required></label></p>';
    echo '<p><label>Cognome<br><input class="regular-text" type="text" name="cognome" required></label></p>';
    echo '<p><label>Codice Fiscale<br><input class="regular-text" type="text" name="codice_fiscale" maxlength="16" pattern="[A-Za-z0-9]{16}" required></label></p>';
    echo '<p><label>Email<br><input class="regular-text" type="email" name="email" required></label></p>';
    echo '<p><label>Telefono<br><input class="regular-text" type="tel" name="telefono" required></label></p>';

    echo '<p><label>Corso<br><select name="corso_id" required><option value="">— seleziona —</option>';
    foreach ($courses as $c) echo '<option value="'.$c->ID.'">'.esc_html($c->post_title).'</option>';
    echo '</select></label></p>';

    echo '<p><label>Azienda (Gruppo)<br><select name="azienda_id" '.($require_az?'required':'').'><option value="">— seleziona —</option>';
    foreach ($groups as $g) echo '<option value="'.$g->ID.'">'.esc_html($g->post_title).'</option>';
    echo '</select></label></p>';

    echo '<p><label>Busta paga (PDF)<br><input type="file" name="busta_paga" accept="application/pdf" required></label></p>';

    if ($atts['show_privacy'] === '1') {
      echo '<p><label><input type="checkbox" name="privacy" value="1" required> Dichiaro di aver letto l\'informativa privacy.</label></p>';
    }

    echo '<p><button class="button button-primary" type="submit">Invia iscrizione</button></p>';
    echo '</form>';
    return ob_get_clean();
  }
}
add_shortcode('ebts_iscrizione_studente', [Shortcode::class, 'render']);