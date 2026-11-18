<?php
/** Confirmed-request state is fictional; local encrypted bytes are real. No confirmation mail is sent. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='local' || getenv('ATLAS_TEST_DB')!=='atlas_integration') throw new RuntimeException('Isolated local CLI required.');
if (!defined('ATLAS_TEST_CLOCK')) define('ATLAS_TEST_CLOCK',true);
use Atlas\Core\DB;
use Atlas\Core\Records;
if (!class_exists('Atlas_Private_File_Privacy') || !class_exists('Atlas_Private_Files')) WP_CLI::error('Private-file privacy module required.');
global $wpdb; $passed=0; $failed=0; $suffix=strtolower(wp_generate_password(10,false,false));
$check=static function($ok,$label)use(&$passed,&$failed){if($ok){$passed++;echo "PASS $label\n";}else{$failed++;echo "FAIL $label\n";}};
$mailCount=0; $networkCount=0; $mailBlock=static function($prior,$args)use(&$mailCount){$mailCount++;return false;}; $httpBlock=static function($prior,$args,$url)use(&$networkCount){$networkCount++;return new WP_Error('atlas_fixture_network_blocked','External requests blocked.');};
add_filter('pre_wp_mail',$mailBlock,PHP_INT_MAX,2); add_filter('pre_http_request',$httpBlock,PHP_INT_MAX,3);
$users=array(); foreach(array('owner','other','operator','reviewer')as$label){$id=wp_insert_user(array('user_login'=>'file_privacy_'.$label.'_'.$suffix,'user_pass'=>wp_generate_password(32),'user_email'=>$label.'_'.$suffix.'@example.invalid','role'=>in_array($label,array('operator','reviewer'),true)?'administrator':'customer'));if(is_wp_error($id))WP_CLI::error('Privacy fixture user failed.');$users[$label]=(int)$id;}
$owner=$users['owner']; $other=$users['other']; $operator=$users['operator']; $reviewer=$users['reviewer']; $ownerEmail=get_userdata($owner)->user_email; $otherEmail=get_userdata($other)->user_email;
foreach(array($operator,$reviewer)as$id){$u=get_userdata($id);foreach(array('atlas_manage_records','export_others_personal_data','erase_others_personal_data','delete_users')as$cap)$u->add_cap($cap);}
get_userdata($owner)->add_cap('atlas_manage_records'); update_user_meta($owner,'locale','fa_IR');
DB::set_test_now('2027-09-01T09:00:00Z');
$wpdb->insert(DB::table('organizations'),array('name'=>'Fictional privacy organization '.$suffix,'status'=>'approved','created_at'=>DB::now()));$org=(int)$wpdb->insert_id;
foreach(array($owner,$other)as$id)$wpdb->insert(DB::table('memberships'),array('org_id'=>$org,'user_id'=>$id,'role'=>'manager','status'=>'active','updated_at'=>DB::now()));
$rest=static function($path,$body){$r=new WP_REST_Request('POST','/agency/v1'.$path);$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));return rest_get_server()->dispatch($r);};
if(defined('ATLAS_PRIVATE_FILE_ROOT')||defined('ATLAS_PRIVATE_FILE_KEY'))WP_CLI::error('Fresh isolated private storage configuration required.');
$root=sys_get_temp_dir().'/atlas-private-portability-'.$suffix;define('ATLAS_PRIVATE_FILE_ROOT',$root);define('ATLAS_PRIVATE_FILE_KEY',base64_encode(random_bytes(32)));
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jGZkAAAAASUVORK5CYII=');
wp_set_current_user($operator);$policy=$rest('/private-file-policies',array('purpose'=>'fictional_privacy_portability','context_types'=>array('record'),'notice_en'=>'Fictional portability fixture, one-day retention.','notice_fa'=>'آزمایش ساختگی انتقال‌پذیری؛ نگهداری یک‌روزه.','retention_days'=>1,'expires_at'=>'2028-01-01 00:00:00.000000','review_reference'=>'fixture:portability:privacy','sensitive'=>false,'legal_reference'=>'fixture:portability:legal'))->get_data();
if(empty($policy['id']))WP_CLI::error('Private-file policy fixture failed.');$policyId=$policy['id'];wp_set_current_user($reviewer);if($rest('/private-file-policies/'.$policyId.'/approve',array('reason'=>'Fictional independent purpose review'))->get_status()!==200)WP_CLI::error('Policy fixture approval failed.');
wp_set_current_user($owner);$case=Records::create('support_case',array('state'=>'new','category'=>'general','subject'=>'Fictional portability','message'=>'No actual personal documents'),$owner);if(is_wp_error($case))WP_CLI::error('Private context fixture failed.');
$file=Atlas_Private_Files::store('record',$case['id'],$policyId,$png,true);if(is_wp_error($file))WP_CLI::error('Encrypted byte fixture failed.');$actualId=$file['id'];$actualPath=$root.'/'.$actualId.'.blob';$cipherHash=hash_file('sha256',$actualPath);
$mockScan=static function($prior,$bytes,$metadata)use($png){return array('state'=>$bytes===$png?'clean':'infected','engine'=>'MOCK_KNOWN_BYTES_ONLY','reference'=>'fixture:portability:png','scanned_digest'=>hash('sha256',$bytes));};add_filter('atlas_private_file_scan',$mockScan,10,3);
wp_set_current_user($operator);if($rest('/private-files/'.$actualId.'/scan',array('version'=>1,'reason'=>'MOCK local known-byte scan'))->get_status()!==200)WP_CLI::error('Private fixture scan failed.');remove_filter('atlas_private_file_scan',$mockScan,10);
$ownIds=array($actualId);$foreignIds=array();$secretDigest=str_repeat('b',64);$secretPath='/not-a-real-private-root/secret-document';
for($i=0;$i<54;$i++){
    $mine=$i<52;$id=wp_generate_uuid4();if($mine)$ownIds[]=$id;else$foreignIds[]=$id;
    $row=array('id'=>$id,'owner_id'=>$mine?$owner:$other,'org_id'=>$org,'uploaded_by'=>$owner,'context_type'=>'record','context_ref'=>$case['id'],'policy_id'=>$policyId,'mime'=>'application/pdf','size_bytes'=>123,'digest'=>$secretDigest,'state'=>$i===2?'purged':'clean','version'=>1,'scan_evidence'=>wp_json_encode(array('path'=>$secretPath,'secret'=>ATLAS_PRIVATE_FILE_KEY)),'legal_hold'=>$i===0?1:0,'hold_reason'=>$secretPath,'erasure_requested'=>0,'expires_at'=>$i===1||$i===2?'2027-08-31 09:00:00.000000':'2027-10-01 09:00:00.000000','created_at'=>DB::now(),'updated_at'=>DB::now());
    if(!$wpdb->insert(Atlas_Private_Files::table(),$row))WP_CLI::error('Metadata pagination fixture failed.');
}
$exporters=apply_filters('wp_privacy_personal_data_exporters',array());$erasers=apply_filters('wp_privacy_personal_data_erasers',array());
$export=$exporters['atlas-private-files']['callback']??null;$erase=$erasers['atlas-private-files']['callback']??null;
$check(is_callable($export)&&is_callable($erase),'minimal private-file exporter and erasure-review callbacks register with WordPress');if(!is_callable($export)||!is_callable($erase))WP_CLI::error('Privacy callbacks unavailable.');
$savedPost=$_POST;$_POST=array();wp_set_current_user($owner);
$check(is_wp_error(call_user_func($export,$otherEmail,1))&&is_wp_error(call_user_func($erase,$otherEmail,1)),'ordinary organization manager cannot invoke another email privacy callback');
wp_set_current_user($operator);$check(is_wp_error(call_user_func($export,$ownerEmail,1)),'privacy capability alone cannot replace matching request and nonce');
$context=static function($requestId,$action)use($operator){wp_set_current_user($operator);$_POST=array('id'=>(string)$requestId,'security'=>wp_create_nonce(($action==='export_personal_data'?'wp-privacy-export-personal-data-':'wp-privacy-erase-personal-data-').$requestId));};
$exportId=wp_create_user_request($ownerEmail,'export_personal_data');if(is_wp_error($exportId))WP_CLI::error('Privacy request fixture failed.');$context($exportId,'export_personal_data');
$check(is_wp_error(call_user_func($export,$ownerEmail,1)),'pending privacy request cannot disclose private-document metadata');
wp_update_post(array('ID'=>$exportId,'post_status'=>'request-confirmed'));$context($exportId,'export_personal_data');$_POST['security']='invalid';
$check(is_wp_error(call_user_func($export,$ownerEmail,1)),'per-request WordPress privacy nonce is required');
$context($exportId,'export_personal_data');$check(is_wp_error(call_user_func($export,$otherEmail,1))&&is_wp_error(call_user_func($erase,$ownerEmail,1)),'email mismatch and export-versus-erasure action mismatch fail closed');
wp_update_post(array('ID'=>$exportId,'post_author'=>$other));$context($exportId,'export_personal_data');
$check(is_wp_error(call_user_func($export,$ownerEmail,1)),'original request account identity cannot silently switch to another user');
wp_update_post(array('ID'=>$exportId,'post_author'=>$owner));$context($exportId,'export_personal_data');
$check(is_wp_error(call_user_func($export,$ownerEmail,array(1)))&&is_wp_error(call_user_func($export,$ownerEmail,0)),'malformed or zero export page is rejected cleanly');
$_POST['id']=array($exportId);$check(is_wp_error(call_user_func($export,$ownerEmail,1)),'array request identifier is rejected without coercion');$context($exportId,'export_personal_data');
$page1=call_user_func($export,$ownerEmail,1);$page2=call_user_func($export,$ownerEmail,2);$page3=call_user_func($export,$ownerEmail,3);
$check(!is_wp_error($page1)&&!is_wp_error($page2)&&count($page1['data'])===50&&!$page1['done']&&count($page2['data'])===3&&$page2['done']&&$page3['data']===array()&&$page3['done'],'owner metadata export paginates deterministically with correct completion');
$all=array_merge($page1['data']??array(),$page2['data']??array());$exportedIds=array_map(static function($row){return substr($row['item_id'],strlen('private-document-'));},$all);sort($exportedIds);$expected=$ownIds;sort($expected);
$check($exportedIds===$expected&&!array_intersect($foreignIds,$exportedIds),'export selects exact owner metadata only, excluding other organization documents uploaded by requester');
$serialized=wp_json_encode($all);
$check(!str_contains($serialized,$secretDigest)&&!str_contains($serialized,hash('sha256',$png))&&!str_contains($serialized,$secretPath)&&!str_contains($serialized,$root)&&!str_contains($serialized,ATLAS_PRIVATE_FILE_KEY)&&!str_contains($serialized,base64_encode($png))&&!str_contains($serialized,$case['id']),'WordPress archive omits every digest, raw byte encoding, private path, key and attachment context reference');
DB::set_test_audit_failure(true);try{$blocked=call_user_func($export,$ownerEmail,1);}finally{DB::set_test_audit_failure(false);}
$check(is_wp_error($blocked),'metadata export fails closed if mandatory privacy audit fails');
wp_update_post(array('ID'=>$exportId,'post_status'=>'request-completed'));$context($exportId,'export_personal_data');
$check(!is_wp_error(call_user_func($export,$ownerEmail,1)),'completed matching export request permits authorized review retry');
$eraseId=wp_create_user_request($ownerEmail,'remove_personal_data',array(),'confirmed');if(is_wp_error($eraseId))WP_CLI::error('Erasure request fixture failed.');$context($eraseId,'remove_personal_data');
$table=Atlas_Private_Files::table();$before=$wpdb->get_results($wpdb->prepare("SELECT id,version,state,legal_hold,expires_at FROM $table WHERE owner_id=%d ORDER BY id",$owner),ARRAY_A);
DB::set_test_audit_failure(true);try{$rolledBack=call_user_func($erase,$ownerEmail,1);}finally{DB::set_test_audit_failure(false);}
$check(is_wp_error($rolledBack)&&(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE owner_id=%d AND erasure_requested=1",$owner))===0&&$before===$wpdb->get_results($wpdb->prepare("SELECT id,version,state,legal_hold,expires_at FROM $table WHERE owner_id=%d ORDER BY id",$owner),ARRAY_A),'erasure review flag and version roll back atomically on audit failure');
$erased1=call_user_func($erase,$ownerEmail,1);$erased2=call_user_func($erase,$ownerEmail,2);
$check(!is_wp_error($erased1)&&!is_wp_error($erased2)&&!$erased1['items_removed']&&$erased1['items_retained']&&!$erased1['done']&&$erased2['done'],'WordPress eraser records paged review and explicitly reports retained data without deletion');
$check((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE owner_id=%d AND state<>'purged' AND erasure_requested=1",$owner))===52&&(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE owner_id=%d AND erasure_requested=1",$other))===0,'only requesting owner nonpurged files receive review flags');
$audits=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('audit').' WHERE actor_id=%d AND action=%s',$operator,'private.privacy_erasure_request'));
$versionRows=$wpdb->get_results($wpdb->prepare("SELECT id,version FROM $table WHERE owner_id=%d ORDER BY id",$owner),ARRAY_A);
wp_update_post(array('ID'=>$eraseId,'post_status'=>'request-completed'));$context($eraseId,'remove_personal_data');$again1=call_user_func($erase,$ownerEmail,1);$again2=call_user_func($erase,$ownerEmail,2);
$check(!is_wp_error($again1)&&!is_wp_error($again2)&&$audits===52&&$audits===(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('audit').' WHERE actor_id=%d AND action=%s',$operator,'private.privacy_erasure_request'))&&$versionRows===$wpdb->get_results($wpdb->prepare("SELECT id,version FROM $table WHERE owner_id=%d ORDER BY id",$owner),ARRAY_A),'repeat completed erasure review creates no duplicate flag, version bump or audit fact');
$after=$wpdb->get_results($wpdb->prepare("SELECT id,state,legal_hold,expires_at FROM $table WHERE owner_id=%d ORDER BY id",$owner),ARRAY_A);$preserved=array_map(static function($row){unset($row['version']);return $row;},$before);
$check($after===$preserved&&is_file($actualPath)&&hash_file('sha256',$actualPath)===$cipherHash,'erasure review preserves holds, retention, storage states and actual encrypted bytes');
$messages=implode(' ',array_merge($erased1['messages']??array(),$erased2['messages']??array()));
$check(str_contains($messages,'بررسی حذف')&&str_contains($messages,'منع قانونی')&&str_contains($messages,'منقضی'),'owner-locale Persian response explains manual review, legal hold and expired records');
wp_set_current_user($owner);$download=Atlas_Private_Files::content($actualId);
$check(!is_wp_error($download)&&$download['bytes']===$png,'normal fresh scoped private download remains the byte access path during manual erasure review');
wp_set_current_user($other);$check(is_wp_error(Atlas_Private_Files::content($actualId)),'other organization manager cannot use archive reference to decrypt owner attachment');
wp_set_current_user($operator);$guestEmail='no_account_'.$suffix.'@example.invalid';$guestRequest=wp_create_user_request($guestEmail,'export_personal_data',array(),'confirmed');$context($guestRequest,'export_personal_data');$guest=call_user_func($export,$guestEmail,1);
$check(!is_wp_error($guest)&&$guest['data']===array()&&$guest['done'],'confirmed guest request without account cannot inherit another owner data');
$check($mailCount===0&&$networkCount===0,'privacy callback fixture sends no confirmation mail and performs no external transport');
$_POST=$savedPost;remove_filter('pre_wp_mail',$mailBlock,PHP_INT_MAX);remove_filter('pre_http_request',$httpBlock,PHP_INT_MAX);if(is_file($actualPath))unlink($actualPath);if(is_dir($root))rmdir($root);DB::set_test_now(null);
echo "Atlas private-file privacy checks: $passed passed, $failed failed\n";if($failed)WP_CLI::error('Private-file privacy checks failed.');WP_CLI::success('Owner portability and retained erasure-review checks passed; identity confirmation is a fictional fixture.');
