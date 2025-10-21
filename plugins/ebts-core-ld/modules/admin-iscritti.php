<?php
namespace EBTS\CoreLD; if (!defined('ABSPATH')) exit;

class AdminIscritti {
  public static function menu(){
    add_menu_page('EBTS','EBTS','list_users','ebts_iscritti_edit',[__CLASS__,'render'],'dashicons-welcome-learn-more',58);
  }
  public static function render(){
    $q = sanitize_text_field($_GET['q'] ?? '');
    $course_id = (int)($_GET['course_id'] ?? 0);
    $group_id  = (int)($_GET['group_id'] ?? 0);
    $paged     = max(1,(int)($_GET['paged'] ?? 1));
    $per_page  = max(1,(int)($_GET['per_page'] ?? 25));

    echo '<div class="wrap"><h1>Iscritti (modifica)</h1>';
    echo '<form method="get" class="ebts-filter"><input type="hidden" name="page" value="ebts_iscritti_edit">';
    echo '<p><input type="search" name="q" value="'.esc_attr($q).'" placeholder="Nome, Cognome, Email, CF"> ';

    $courses=get_posts(['post_type'=>'sfwd-courses','numberposts'=>-1,'post_status'=>'any','orderby'=>'title','order'=>'ASC']);
    echo '<select name="course_id"><option value="">— Corso —</option>';
    foreach($courses as $c) echo '<option value="'.$c->ID.'" '.selected($course_id,$c->ID,false).'>'.esc_html($c->post_title).'</option>';
    echo '</select> ';
    $groups=get_posts(['post_type'=>'groups','numberposts'=>-1,'post_status'=>'any','orderby'=>'title','order'=>'ASC']);
    echo '<select name="group_id"><option value="">— Azienda/Group —</option>';
    foreach($groups as $g) echo '<option value="'.$g->ID.'" '.selected($group_id,$g->ID,false).'>'.esc_html($g->post_title).'</option>';
    echo '</select> ';
    echo '<label>Per pagina <input type="number" name="per_page" value="'.esc_attr($per_page).'" style="width:80px"></label> ';
    echo '<button class="button">Filtra</button></p></form>';

    $args = ['number'=>$per_page,'paged'=>$paged,'fields'=>'all'];
    if ($q) { $args['search']='*'.$q.'*'; $args['search_columns']=['user_login','user_email','user_nicename']; }
    if ($group_id) {
      $ids = Helpers::get_groups_user_ids([$group_id]);
      $args['include'] = $ids ?: [0];
    }
    $users = get_users($args);

    echo '<table class="widefat striped"><thead><tr>
      <th>Nome</th><th>Cognome</th><th>Email</th><th>CF</th><th>Telefono</th><th>Corso</th><th>Aziende</th><th>Busta</th><th>Azioni</th>
    </tr></thead><tbody>';
    foreach ($users as $u) {
      if ($course_id && function_exists('learndash_user_get_enrolled_courses')) {
        $enrolled = (array) learndash_user_get_enrolled_courses($u->ID);
        if (!in_array($course_id, $enrolled, true)) continue;
      }
      $cf = get_user_meta($u->ID,'cfiscale',true);
      $tel= get_user_meta($u->ID,'telefono',true);
      $gids = Helpers::get_user_group_ids($u->ID); $gnames=[]; foreach ($gids as $gid) $gnames[] = get_the_title($gid);
      $ctitles = []; if (function_exists('learndash_user_get_enrolled_courses')) { foreach ((array)learndash_user_get_enrolled_courses($u->ID) as $cid) $ctitles[] = get_the_title($cid); }
      $bp_rel = get_user_meta($u->ID, 'busta_paga_rel', true);
      $busta_html = $bp_rel ? '<a class="button button-small" target="_blank" href="'.esc_url( wp_nonce_url(admin_url('admin-ajax.php?action='.C::DOWNLOAD_ACTION.'&kind=busta&user_id='.$u->ID),'ebts_dl_user') ).'">Scarica</a>' : '<span class="dashicons dashicons-dismiss" title="Assente"></span>';

      $detail_url = admin_url('admin.php?page=ebts_utente&user_id='.$u->ID);
      $profile_url = admin_url('user-edit.php?user_id='.$u->ID);

      echo '<tr>
        <td>'.esc_html($u->first_name).'</td>
        <td>'.esc_html($u->last_name).'</td>
        <td>'.esc_html($u->user_email).'</td>
        <td>'.esc_html($cf).'</td>
        <td>'.esc_html($tel).'</td>
        <td>'.esc_html( implode(' | ', $ctitles) ).'</td>
        <td>'.esc_html( implode(' | ', $gnames) ).'</td>
        <td>'.$busta_html.'</td>
        <td class="ebts-inline-actions">
          <a class="button button-small" target="_blank" href="'.esc_url($detail_url).'">Dettagli (EBTS)</a>
          <a href="#" class="button button-small ebts-quick" data-user="'.$u->ID.'">Modifica rapida</a>
          <a class="button-link" target="_blank" href="'.esc_url($profile_url).'">Profilo WP</a>
        </td>
      </tr>';
      echo '<tr class="ebts-quick-row" data-user="'.$u->ID.'" style="display:none"><td colspan="9"><div class="ebts-quick-wrap"><div class="ebts-quick-columns"><div class="card a">Caricamento…</div><div class="card b"></div><div class="card c"></div></div></div></td></tr>';
    }
    echo '</tbody></table>';

    $next_url=add_query_arg(['page'=>'ebts_iscritti_edit','q'=>$q,'course_id'=>$course_id,'group_id'=>$group_id,'per_page'=>$per_page,'paged'=>$paged+1]);
    $prev_url=add_query_arg(['page'=>'ebts_iscritti_edit','q'=>$q,'course_id'=>$course_id,'group_id'=>$group_id,'per_page'=>$per_page,'paged'=>max(1,$paged-1)]);
    echo '<p class="tablenav"><a class="button'.($paged<=1?' disabled':'').'" href="'.esc_url($prev_url).'">« Precedente</a>
          <a class="button" href="'.esc_url($next_url).'">Successiva »</a></p>';

    echo '</div>';
  }
}
add_action('admin_menu', [AdminIscritti::class, 'menu']);
