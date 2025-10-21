<?php
namespace EBTS\CoreLD; if (!defined('ABSPATH')) exit;

add_action('wp_ajax_ebts_user_get', __NAMESPACE__.'\ajax_get');
add_action('wp_ajax_ebts_user_save', __NAMESPACE__.'\ajax_save');
add_action('wp_ajax_ebts_user_upload_payslip', __NAMESPACE__.'\ajax_upload_payslip');
add_action('wp_ajax_ebts_user_list_certs', __NAMESPACE__.'\ajax_list_certs');
add_action('wp_ajax_ebts_user_upload_cert', __NAMESPACE__.'\ajax_upload_cert');
add_action('wp_ajax_ebts_user_delete_cert', __NAMESPACE__.'\ajax_delete_cert');

function check_nonce(){ if (!isset($_REQUEST['_nonce']) || !wp_verify_nonce($_REQUEST['_nonce'], 'ebts_admin_nonce')) wp_send_json_error(['msg'=>'bad nonce'], 403); }
function can_manage_user($user_id): bool { return current_user_can('list_users'); }

function ajax_get(){
  check_nonce(); $uid=(int)($_POST['user_id']??0); if(!$uid || !can_manage_user($uid)) wp_send_json_error(['msg'=>'forbidden'],403);
  $u=get_user_by('id',$uid); if(!$u) wp_send_json_error(['msg'=>'notfound'],404);
  $data=[ 'first_name'=>$u->first_name,'last_name'=>$u->last_name,'email'=>$u->user_email,'telefono'=>get_user_meta($uid,'telefono',true),'cfiscale'=>get_user_meta($uid,'cfiscale',true),'ip'=>get_user_meta($uid,'ip_registrazione',true),'has_payslip'=>!!get_user_meta($uid,'busta_paga_rel',true) ];
  wp_send_json_success($data);
}
function ajax_save(){
  check_nonce(); $uid=(int)($_POST['user_id']??0); if(!$uid || !can_manage_user($uid)) wp_send_json_error(['msg'=>'forbidden'],403);
  $uarr=['ID'=>$uid]; if(isset($_POST['email'])) $uarr['user_email']=sanitize_text_field(wp_unslash($_POST['email']));
  if(isset($_POST['first_name'])) update_user_meta($uid,'first_name',sanitize_text_field(wp_unslash($_POST['first_name'])));
  if(isset($_POST['last_name']))  update_user_meta($uid,'last_name',sanitize_text_field(wp_unslash($_POST['last_name'])));
  if(isset($_POST['telefono']))   update_user_meta($uid,'telefono',sanitize_text_field(wp_unslash($_POST['telefono'])));
  if(isset($_POST['cfiscale']))   update_user_meta($uid,'cfiscale',strtoupper(sanitize_text_field(wp_unslash($_POST['cfiscale']))));
  if(count($uarr)>1){ $r=wp_update_user($uarr); if(is_wp_error($r)) wp_send_json_error(['msg'=>$r->get_error_message()],400); }
  wp_send_json_success(['ok'=>true]);
}
function ajax_upload_payslip(){
  check_nonce(); $uid=(int)($_POST['user_id']??0); if(!$uid || !can_manage_user($uid)) wp_send_json_error(['msg'=>'forbidden'],403);
  if(empty($_FILES['busta_paga']['name'])) wp_send_json_error(['msg'=>'missing'],400);
  $rel=null; if(!\EBTS\CoreLD\Helpers::store_private_pdf($_FILES['busta_paga'],'busta-'.$uid.'-admin-'.time().'.pdf',$rel)) wp_send_json_error(['msg'=>'invalid pdf'],400);
  update_user_meta($uid,'busta_paga_rel',$rel); wp_send_json_success(['ok'=>true]);
}
function ajax_list_certs(){
  check_nonce(); $uid=(int)($_POST['user_id']??0); if(!$uid || !can_manage_user($uid)) wp_send_json_error(['msg'=>'forbidden'],403);
  $certs=\EBTS\CoreLD\Helpers::get_user_certs($uid);
  $items=[]; foreach($certs as $c){ $items[]=['id'=>$c['id']??'','title'=>$c['title']??'','date'=>$c['date']??'','course_id'=>(int)($c['course_id']??0)]; }
  wp_send_json_success(['items'=>$items]);
}
function ajax_upload_cert(){
  check_nonce(); $uid=(int)($_POST['user_id']??0); $cid=(int)($_POST['course_id']??0); $title=sanitize_text_field($_POST['title']??'Attestato'); $date=sanitize_text_field($_POST['date']??current_time('Y-m-d'));
  if(!$uid || !$cid || !can_manage_user($uid)) wp_send_json_error(['msg'=>'forbidden'],403);
  if(empty($_FILES['cert_pdf']['name'])) wp_send_json_error(['msg'=>'missing'],400);
  $rel=null; if(!\EBTS\CoreLD\Helpers::store_private_pdf($_FILES['cert_pdf'],'cert-'.$uid.'-'.$cid.'-'.time().'.pdf',$rel)) wp_send_json_error(['msg'=>'invalid pdf'],400);
  $certs=get_user_meta($uid,\EBTS\CoreLD\C::META_CERTS,true); if(!is_array($certs)) $certs=[];
  // replace previous for this course
  $new=[]; foreach($certs as $c){ if((int)($c['course_id']??0)!==$cid) $new[]=$c; }
  $id=uniqid('cert_',true); $new[]=['id'=>$id,'course_id'=>$cid,'title'=>$title,'date'=>$date,'rel'=>$rel]; update_user_meta($uid,\EBTS\CoreLD\C::META_CERTS,$new);
  wp_send_json_success(['ok'=>true,'id'=>$id]);
}
function ajax_delete_cert(){
  check_nonce(); $uid=(int)($_POST['user_id']??0); $cid=sanitize_text_field($_POST['cert_id']??''); if(!$uid || !can_manage_user($uid) || !$cid) wp_send_json_error(['msg'=>'forbidden'],403);
  $certs=get_user_meta($uid,\EBTS\CoreLD\C::META_CERTS,true); if(!is_array($certs)) $certs=[]; $new=[]; foreach($certs as $c){ if(($c['id']??'')!==$cid) $new[]=$c; } update_user_meta($uid,\EBTS\CoreLD\C::META_CERTS,$new);
  wp_send_json_success(['ok'=>true]);
}
