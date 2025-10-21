<?php
namespace EBTS\CoreLD; if (!defined('ABSPATH')) exit;

class AdminExport {
  public static function menu(){
    add_submenu_page('ebts_iscritti_edit','Export Iscritti','Export Iscritti','list_users','ebts_export',[__CLASS__,'render']);
  }
  public static function render(){
    if (isset($_GET['do']) && check_admin_referer('ebts_export')) {
      if ($_GET['do']==='csv') { self::download_csv(); return; }
      if ($_GET['do']==='xlsx') { self::download_xlsx(); return; }
    }

    $q = sanitize_text_field($_GET['q'] ?? '');
    $course_id = (int)($_GET['course_id'] ?? 0);
    $group_id  = (int)($_GET['group_id'] ?? 0);

    echo '<div class="wrap"><h1>Export Iscritti</h1><form method="get">';
    echo '<input type="hidden" name="page" value="ebts_export">';
    echo '<p><input type="search" name="q" value="'.esc_attr($q).'" placeholder="Cerca..."> ';

    $courses=get_posts(['post_type'=>'sfwd-courses','numberposts'=>-1,'post_status'=>'any','orderby'=>'title','order'=>'ASC']);
    echo '<select name="course_id"><option value="">— Corso —</option>';
    foreach($courses as $c) echo '<option value="'.$c->ID.'" '.selected($course_id,$c->ID,false).'>'.esc_html($c->post_title).'</option>';
    echo '</select> ';

    $groups=get_posts(['post_type'=>'groups','numberposts'=>-1,'post_status'=>'any','orderby'=>'title','order'=>'ASC']);
    echo '<select name="group_id"><option value="">— Azienda/Group —</option>';
    foreach($groups as $g) echo '<option value="'.$g->ID.'" '.selected($group_id,$g->ID,false).'>'.esc_html($g->post_title).'</option>';
    echo '</select> ';

    wp_nonce_field('ebts_export');
    echo '<button class="button button-primary" name="do" value="csv">Scarica CSV</button> ';
    echo '<button class="button" name="do" value="xlsx">Scarica XLSX</button>';
    echo '</p></form>';

    $args = ['number'=>20,'fields'=>'all'];
    if ($q) { $args['search']='*'.$q.'*'; $args['search_columns']=['user_login','user_email','user_nicename']; }
    if ($group_id) { $ids = Helpers::get_groups_user_ids([$group_id]); $args['include']=$ids?:[0]; }
    $users = get_users($args);
    echo '<table class="widefat striped"><thead><tr><th>Nome</th><th>Email</th><th>Gruppi</th></tr></thead><tbody>';
    foreach ($users as $u) {
      if ($course_id && function_exists('learndash_user_get_enrolled_courses')) {
        $enrolled = (array) learndash_user_get_enrolled_courses($u->ID);
        if (!in_array($course_id, $enrolled, true)) continue;
      }
      $gids = Helpers::get_user_group_ids($u->ID); $gnames=[]; foreach ($gids as $gid) $gnames[] = get_the_title($gid);
      echo '<tr><td>'.esc_html($u->first_name.' '.$u->last_name).'</td><td>'.esc_html($u->user_email).'</td><td>'.esc_html(implode(' | ',$gnames)).'</td></tr>';
    }
    echo '</tbody></table></div>';
  }

  private static function download_csv(): void {
    $course_id = (int)($_GET['course_id'] ?? 0);
    $group_id  = (int)($_GET['group_id'] ?? 0);
    $q = sanitize_text_field($_GET['q'] ?? '');

    $args = ['number'=>-1,'fields'=>'all'];
    if ($q) { $args['search']='*'.$q.'*'; $args['search_columns']=['user_login','user_email','user_nicename']; }
    if ($group_id) { $user_ids = Helpers::get_groups_user_ids([$group_id]); $args['include'] = $user_ids ?: [0]; }
    $users = get_users($args);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=iscritti-ebts.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Nome','Cognome','Email','Codice Fiscale','Telefono','Azienda (Gruppo)','Corsi','IP registrazione'], ';');

    foreach ($users as $u) {
      if ($course_id && function_exists('learndash_user_get_enrolled_courses')) {
        $enrolled = learndash_user_get_enrolled_courses($u->ID);
        if (!in_array($course_id, (array)$enrolled, true)) continue;
      }
      $nome=get_user_meta($u->ID,'first_name',true);
      $cogn=get_user_meta($u->ID,'last_name',true);
      $cf=get_user_meta($u->ID,'cfiscale',true);
      $tel=get_user_meta($u->ID,'telefono',true);
      $ip=get_user_meta($u->ID,'ip_registrazione',true);
      $gids=Helpers::get_user_group_ids($u->ID); $gnames=[]; foreach($gids as $gid) $gnames[]=get_the_title($gid);
      $ctitles=[]; if (function_exists('learndash_user_get_enrolled_courses')) { foreach ((array)learndash_user_get_enrolled_courses($u->ID) as $cid) $ctitles[] = get_the_title($cid); }
      fputcsv($out, [$nome,$cogn,$u->user_email,$cf,$tel,implode(' | ',$gnames),implode(' | ',$ctitles),$ip], ';');
    }
    fclose($out); exit;
  }

  private static function download_xlsx(): void {
    $course_id = (int)($_GET['course_id'] ?? 0);
    $group_id  = (int)($_GET['group_id'] ?? 0);
    $q = sanitize_text_field($_GET['q'] ?? '');

    $args = ['number'=>-1,'fields'=>'all'];
    if ($q) { $args['search']='*' . $q . '*'; $args['search_columns']=['user_login','user_email','user_nicename']; }
    if ($group_id) { $user_ids = Helpers::get_groups_user_ids([$group_id]); $args['include'] = $user_ids ?: [0]; }
    $users = get_users($args);

    $rows = [];
    $rows[] = ['Nome','Cognome','Email','Codice Fiscale','Telefono','Azienda (Gruppo)','Corsi','IP registrazione'];
    foreach ($users as $u) {
      if ($course_id && function_exists('learndash_user_get_enrolled_courses')) {
        $enrolled = learndash_user_get_enrolled_courses($u->ID);
        if (!in_array($course_id, (array)$enrolled, true)) continue;
      }
      $nome=get_user_meta($u->ID,'first_name',true);
      $cogn=get_user_meta($u->ID,'last_name',true);
      $cf=get_user_meta($u->ID,'cfiscale',true);
      $tel=get_user_meta($u->ID,'telefono',true);
      $ip=get_user_meta($u->ID,'ip_registrazione',true);
      $gids=Helpers::get_user_group_ids($u->ID); $gnames=[]; foreach($gids as $gid) $gnames[]=get_the_title($gid);
      $ctitles=[]; if (function_exists('learndash_user_get_enrolled_courses')) { foreach ((array)learndash_user_get_enrolled_courses($u->ID) as $cid) $ctitles[] = get_the_title($cid); }
      $rows[] = [$nome,$cogn,$u->user_email,$cf,$tel,implode(' | ',$gnames),implode(' | ',$ctitles),$ip];
    }

    // Helpers for XLSX
    $xml = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES | (defined('ENT_SUBSTITUTE') ? ENT_SUBSTITUTE : 0), 'UTF-8'); };
    $col = function($i){ $s=''; while($i>0){ $m=($i-1)%26; $s=chr(65+$m).$s; $i=intval(($i-1)/26);} return $s; };

    $sheetData=''; $r=1;
    foreach($rows as $row){
      $sheetData.='<row r="'.$r.'">'; $c=1;
      foreach($row as $val){
        if (is_numeric($val)) $sheetData.='<c r="'.$col($c).$r.'" t="n"><v>'.$val.'</v></c>';
        else $sheetData.='<c r="'.$col($c).$r.'" t="inlineStr"><is><t xml:space="preserve">'.$xml($val).'</t></is></c>';
        $c++;
      }
      $sheetData.='</row>'; $r++;
    }
    $content_types = '<?xml version="1.0" encoding="UTF-8"?>'
      .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
      .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
      .'<Default Extension="xml" ContentType="application/xml"/>'
      .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
      .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
      .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
      .'</Types>';
    $rels_root = '<?xml version="1.0" encoding="UTF-8"?>'
      .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
      .'</Relationships>';
    $workbook = '<?xml version="1.0" encoding="UTF-8"?>'
      .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheet/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
      .'<sheets><sheet name="'.$xml('Iscritti').'" sheetId="1" r:id="rId1"/></sheets>'
      .'</workbook>';
    $workbook_rels = '<?xml version="1.0" encoding="UTF-8"?>'
      .'<Relationships xmlns="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
      .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
      .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
      .'</Relationships>';
    $styles = '<?xml version="1.0" encoding="UTF-8"?>'
      .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      .'<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
      .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
      .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
      .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
      .'<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
      .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
      .'</styleSheet>';
    $sheet_xml = '<?xml version="1.0" encoding="UTF-8"?>'
      .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheet/2006/main">'
      .'<sheetData>'.$sheetData.'</sheetData>'
      .'</worksheet>';

    $tmp = wp_tempnam('ebts-xlsx');
    $zip = new \ZipArchive();
    if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) return;
    $zip->addFromString('[Content_Types].xml', $content_types);
    $zip->addFromString('_rels/.rels', $rels_root);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbook_rels);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet_xml);
    $zip->close();
    $data = @file_get_contents($tmp); @unlink($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="iscritti-ebts.xlsx"');
    header('Content-Length: '.strlen($data));
    echo $data; exit;
  }
}
add_action('admin_menu', [AdminExport::class, 'menu']);
