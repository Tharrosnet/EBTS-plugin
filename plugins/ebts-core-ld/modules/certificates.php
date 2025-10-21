<?php
namespace EBTS\CoreLD;
if (!defined('ABSPATH')) exit;

class Certificates_Service {
  public static function issue_token(): string {
    return bin2hex(random_bytes(16));
  }
  public static function attach_token($enrollment_id, $rel_path){
    global $wpdb; $t = Enrollments_Schema::tables();
    $token = self::issue_token();
    $wpdb->insert($t['certs'], [
      'enrollment_id' => (int)$enrollment_id,
      'title' => 'Attestato',
      'rel_path' => ltrim($rel_path,'/'),
      'issued_at' => current_time('mysql'),
      'issued_by' => get_current_user_id(),
      'verify_token' => $token,
    ]);
    return $token;
  }
  public static function find_by_token($token){
    global $wpdb; $t = Enrollments_Schema::tables();
    return $wpdb->get_row($wpdb->prepare("SELECT c.*, e.user_id, e.product_id, e.session_id FROM {$t['certs']} c LEFT JOIN {$t['enroll']} e ON e.id=c.enrollment_id WHERE c.verify_token=%s", $token));
  }

  // Minimal PDF generator (single-page text)
  public static function generate_pdf($dest_path, array $rows): bool {
    $lines = [];
    foreach ($rows as $row){ $lines[] = preg_replace('/[\r\n]+/', ' ', (string)$row); }
    $content = self::build_simple_pdf($lines);
    return (bool) file_put_contents($dest_path, $content);
  }
  private static function build_simple_pdf(array $lines): string {
    // Build a tiny PDF with Helvetica text, A4 page
    $objs = [];
    $add = function($s) use (&$objs){ $objs[] = $s; return count($objs); };
    $font = $add("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>");
    // Content stream
    $y = 800; $content = "BT /F1 18 Tf 50 {$y} Td (Attestato di partecipazione) Tj ET\n"; $y -= 30;
    foreach ($lines as $ln){
      $txt = self::pdf_escape($ln);
      $content .= "BT /F1 12 Tf 50 {$y} Td ({$txt}) Tj ET\n"; $y -= 18;
    }
    $stream = "<< /Length ".strlen($content)." >>\nstream\n".$content."endstream";
    $contents = $add($stream);
    $page = $add("<< /Type /Page /Parent 0 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 ".($font)." 0 R >> >> /Contents ".($contents)." 0 R >>");
    $pages = $add("<< /Type /Pages /Kids [".($page)." 0 R] /Count 1 >>");
    // Fix parent reference
    $objs[$page-1] = $objs[$page-1]."";
    $catalog = $add("<< /Type /Catalog /Pages ".($pages)." 0 R >>");
    // xref
    $buf = "%PDF-1.4\n"; $ofs=[0]; 
    for ($i=0; $i<count($objs); $i++){
      $ofs[] = strlen($buf);
      $buf .= ($i+1)." 0 obj\n".$objs[$i]."\nendobj\n";
    }
    // Fix the /Parent for page to point to Pages object
    // Rebuild page object with correct parent
    $buf = "%PDF-1.4\n"; $ofs=[0]; $objs[$page-1] = "<< /Type /Page /Parent ".($pages)." 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 ".($font)." 0 R >> >> /Contents ".($contents)." 0 R >>";
    for ($i=0; $i<count($objs); $i++){
      $ofs[] = strlen($buf);
      $buf .= ($i+1)." 0 obj\n".$objs[$i]."\nendobj\n";
    }
    $xref_pos = strlen($buf);
    $buf .= "xref\n0 ".(count($objs)+1)."\n0000000000 65535 f \n";
    for ($i=1; $i<=count($objs); $i++){
      $buf .= sprintf("%010d 00000 n \n", $ofs[$i]);
    }
    $buf .= "trailer << /Size ".(count($objs)+1)." /Root ".($catalog)." 0 R >>\nstartxref\n".$xref_pos."\n%%EOF";
    return $buf;
  }
  private static function pdf_escape($s){ return str_replace(['\\','(',')',"\r","\n"], ['\\\\','\\(','\\)', ' ', ' '], $s); }
}

class Certificates_Shortcode {
  public static function init(){
    add_shortcode('ebts_verifica_attestato', [__CLASS__, 'render']);
  }
  public static function render(){
    $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
    $out = '<form method="get"><input type="hidden" name="page_id" value="'.esc_attr(get_queried_object_id()).'">';
    $out .= '<p><label>Token attestato: <input type="text" name="token" value="'.esc_attr($token).'" class="regular-text" required></label> ';
    $out .= '<button class="button">Verifica</button></p></form>';
    if ($token){
      $row = Certificates_Service::find_by_token($token);
      if ($row){
        $course = get_the_title((int)$row->product_id);
        $user = get_userdata((int)$row->user_id);
        $session = Enrollments_Logic::session_load((int)$row->session_id);
        $date = $session && $session->start_date ? mysql2date('Y-m-d', $session->start_date) : '—';
        $out .= '<div class="notice notice-success"><p>Attestato valido per <strong>'.esc_html($user ? $user->display_name : ('User #'.$row->user_id)).'</strong> — Corso <strong>'.esc_html($course).'</strong> — Sessione: '.esc_html($date).'</p></div>';
      } else {
        $out .= '<div class="notice notice-error"><p>Token non valido.</p></div>';
      }
    }
    return $out;
  }
}
Certificates_Shortcode::init();
