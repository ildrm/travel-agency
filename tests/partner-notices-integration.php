<?php
/** Fictional persisted associations and actual in-app delivery, with outbound transports blocked. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='local' || getenv('ATLAS_TEST_DB')!=='atlas_integration') throw new RuntimeException('Isolated local CLI required.');
if (!defined('ATLAS_TEST_CLOCK')) define('ATLAS_TEST_CLOCK',true);
use Atlas\Core\DB;
if (!class_exists('Atlas_Partner_Notices') || !class_exists('Atlas_Notifications') || !Atlas_Procurement::ready() || !Atlas_Dispatch::ready()) WP_CLI::error('Partner notice hooks and protected domain storage required.');
global $wpdb; $passed=0; $failed=0; $suffix=strtolower(wp_generate_password(10,false,false));
$check=static function($ok,$name)use(&$passed,&$failed){if($ok){$passed++;echo "PASS $name\n";}else{$failed++;echo "FAIL $name\n";}};
$mailCount=0; $networkCount=0;
$mailBlock=static function($prior,$args)use(&$mailCount){$mailCount++;return false;};
$httpBlock=static function($prior,$args,$url)use(&$networkCount){$networkCount++;return new WP_Error('atlas_fixture_network_blocked','No network permitted.');};
add_filter('pre_wp_mail',$mailBlock,PHP_INT_MAX,2); add_filter('pre_http_request',$httpBlock,PHP_INT_MAX,3);
DB::set_test_now('2027-08-01T09:00:00Z');
$users=array();
foreach (array('agency','finance','manager','viewer','revoked','unknown','rival','driver','customer','invitee') as $label) {
    $id=wp_insert_user(array('user_login'=>'notice_'.$label.'_'.$suffix,'user_pass'=>wp_generate_password(32),'user_email'=>$label.'_'.$suffix.'@example.invalid','role'=>in_array($label,array('agency','finance'),true)?'administrator':'customer'));
    if (is_wp_error($id)) WP_CLI::error('Notice fixture user failed.'); $users[$label]=(int)$id;
    if (!in_array($label,array('customer','invitee'),true)) { $u=get_userdata($id); $u->add_cap('atlas_manage_records'); }
}
$agency=$users['agency']; $finance=$users['finance']; $manager=$users['manager']; $viewer=$users['viewer']; $revoked=$users['revoked']; $unknown=$users['unknown']; $rival=$users['rival']; $driver=$users['driver']; $customer=$users['customer']; $invitee=$users['invitee'];
get_userdata($agency)->add_cap('atlas_manage_finance',false); get_userdata($finance)->add_cap('atlas_manage_finance'); update_user_meta($manager,'locale','fa_IR');
$orgs=array(); foreach (array('A','B') as $label) { $wpdb->insert(DB::table('organizations'),array('name'=>'Fictional notices '.$label.' '.$suffix,'status'=>'approved','created_at'=>DB::now())); $orgs[]=(int)$wpdb->insert_id; }
list($orgA,$orgB)=$orgs;
foreach (array(array($manager,'manager','active',$orgA),array($viewer,'viewer','active',$orgA),array($revoked,'manager','suspended',$orgA),array($unknown,'invented_role','active',$orgA),array($rival,'manager','active',$orgB),array($driver,'contributor','active',$orgA)) as $m) $wpdb->insert(DB::table('memberships'),array('user_id'=>$m[0],'role'=>$m[1],'status'=>$m[2],'org_id'=>$m[3],'updated_at'=>DB::now()));
$request=static function($method,$path,$body=null){$r=new WP_REST_Request($method,'/agency/v1'.$path);$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));if($body!==null){$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));}return rest_get_server()->dispatch($r);};
$event=static function($type,$id,$payload=array()) { return array('id'=>wp_generate_uuid4(),'event_type'=>$type,'aggregate_id'=>$id,'payload'=>wp_json_encode($payload)); };
$resolve=static function($type,$id,$payload=array())use($event){return apply_filters('atlas_notification_recipients',array(),$event($type,$id,$payload));};
$ids=static function($recipients){return is_array($recipients)?array_map('intval',array_column($recipients,'user')):array();};
$insert=static function($table,$values)use($wpdb){if(!$wpdb->insert($table,$values))WP_CLI::error('Persisted notice association fixture failed.');};
$sensitive='RIVAL_SECRET_BUDGET_AND_BANK_EVIDENCE';
$rfq=wp_generate_uuid4(); $quote=wp_generate_uuid4(); $order=wp_generate_uuid4(); $now=DB::now();
foreach (array(array($rfq,'rfq',0),array($quote,'quote',$orgA),array($order,'order',$orgA)) as $r) $insert(Atlas_Procurement::table(),array('id'=>$r[0],'type'=>$r[1],'rfq_id'=>$rfq,'quote_id'=>null,'org_id'=>$r[2],'version'=>1,'state'=>$r[1]==='rfq'?'open':'submitted','quantity'=>1,'amount_irr'=>123456,'payload'=>wp_json_encode(array('recipients'=>array($orgA,$orgB),'terms'=>$sensitive,'bank_evidence'=>$sensitive)),'created_by'=>$agency,'created_at'=>$now,'updated_at'=>$now));
$registered=apply_filters('atlas_notification_events',array());
$check(in_array('partner.contract_accept',$registered,true) && in_array('procurement.artwork',$registered,true) && in_array('dispatch.schedule_conflict',$registered,true) && !in_array('dispatch.live_gps',$registered,true),'registry contains emitted domain events and no invented GPS event');
$unrelated=array(array('user'=>$rival,'reference'=>'existing')); $check(Atlas_Partner_Notices::recipients($unrelated,$event('booking.confirmed',$order))===$unrelated,'unrelated core notice routing is preserved');
$quoteAudience=$resolve('procurement.quote',$quote,array('recipient_id'=>$rival,'org_id'=>$orgB,'reference'=>$sensitive)); $quoteIds=$ids($quoteAudience);
$check(in_array($manager,$quoteIds,true) && in_array($viewer,$quoteIds,true) && !in_array($rival,$quoteIds,true) && !in_array($revoked,$quoteIds,true) && !in_array($unknown,$quoteIds,true),'quote notices use current selected organization read scope, excluding rivals, revoked and unknown roles');
$check(!array_filter($quoteAudience,static function($row)use($quote){return array_keys($row)!==array('user','reference') || !is_int($row['user']) || $row['reference']!==$quote;}),'recipient projection contains only integer user and authentic object UUID');
$check(in_array($rival,$ids($resolve('procurement.rfq',$rfq)),true),'RFQ creation may notify each invited organization without exposing budget or quotes');
$check($resolve('procurement.award',$quote)===false && $resolve('procurement.quote',wp_generate_uuid4())===false,'wrong aggregate type and missing object cannot fabricate a notification association');
$financeIds=$ids($resolve('procurement.settlement_evidence',$order));
$check(!in_array($agency,$financeIds,true) && in_array($finance,$financeIds,true) && in_array($manager,$financeIds,true) && !in_array($rival,$financeIds,true),'finance event agency audience also requires finance ceiling while supplier sees only own object reference');
$wpdb->update(DB::table('organizations'),array('status'=>'suspended'),array('id'=>$orgA));
$check(!in_array($manager,$ids($resolve('procurement.quote',$quote)),true),'organization suspension is rechecked at delivery time');
$wpdb->update(DB::table('organizations'),array('status'=>'approved'),array('id'=>$orgA));
$wpdb->update(DB::table('memberships'),array('status'=>'suspended'),array('user_id'=>$manager,'org_id'=>$orgA));
$check(!in_array($manager,$ids($resolve('procurement.quote',$quote)),true),'membership revoked after event emission loses notice eligibility');
$wpdb->update(DB::table('memberships'),array('status'=>'active'),array('user_id'=>$manager,'org_id'=>$orgA));
$contract=wp_generate_uuid4(); $insert(Atlas_Partner_Contracts::table(),array('id'=>$contract,'org_id'=>$orgA,'title'=>$sensitive,'current_revision'=>1,'version'=>1,'state'=>'accepted','created_by'=>$agency,'created_at'=>$now,'updated_at'=>$now));
$contractIds=$ids($resolve('partner.contract_accept',$contract));
$check(in_array($manager,$contractIds,true) && !in_array($viewer,$contractIds,true) && !in_array($driver,$contractIds,true) && !in_array($rival,$contractIds,true),'confidential contract notices go only to current partner managers and agency reviewers');
$application=wp_generate_uuid4(); $insert(DB::table('records'),array('id'=>$application,'type'=>'partner_application','org_id'=>0,'owner_id'=>$customer,'state'=>'approved','payload'=>wp_json_encode(array('secret'=>$sensitive)),'version'=>1,'created_at'=>$now,'updated_at'=>$now));
$check(in_array($customer,$ids($resolve('partner.reviewed',$application)),true) && !in_array($rival,$ids($resolve('partner.reviewed',$application)),true),'application outcome resolves the stored applicant owner');
$invite=wp_generate_uuid4(); $inviteeUser=get_userdata($invitee);
$insert(Atlas_Partner_Invitations::table(),array('id'=>$invite,'org_id'=>$orgA,'email'=>$inviteeUser->user_email,'role'=>'viewer','capability_ceiling'=>'[]','token_hash'=>hash('sha256',random_bytes(32)),'state'=>'pending','created_by'=>$manager,'accepted_by'=>0,'request_key'=>'notice_'.$suffix,'request_hash'=>hash('sha256',$suffix),'created_at'=>$now,'expires_at'=>'2027-08-02 09:00:00.000000','accepted_at'=>null,'revoke_reason'=>''));
$invitationIds=$ids($resolve('partner.invitation_created',$invite));
$check(in_array($manager,$invitationIds,true) && in_array($invitee,$invitationIds,true) && !in_array($rival,$invitationIds,true),'pending invitation notice matches existing recipient email without a token or outbound message');
$wpdb->update(Atlas_Partner_Invitations::table(),array('state'=>'revoked'),array('id'=>$invite));
$check(!in_array($invitee,$ids($resolve('partner.invitation_created',$invite)),true),'revoked invitation cannot produce a new recipient notice');
$wpdb->update(Atlas_Partner_Invitations::table(),array('state'=>'accepted','accepted_by'=>$manager,'accepted_at'=>$now),array('id'=>$invite));
$check(in_array($manager,$ids($resolve('partner.member_accepted',$invite)),true),'membership acceptance notices use the persisted active accepted member');
$dispatch=wp_generate_uuid4(); $insert(Atlas_Dispatch::table(),array('id'=>$dispatch,'request_id'=>wp_generate_uuid4(),'customer_id'=>$customer,'org_id'=>$orgA,'resource_id'=>wp_generate_uuid4(),'allocation_id'=>wp_generate_uuid4(),'driver_id'=>$driver,'version'=>1,'state'=>'confirmed','start_at'=>'2027-08-03T09:00:00Z','end_at'=>'2027-08-03T10:00:00Z','effective_end'=>'2027-08-03T10:30:00Z','payload'=>wp_json_encode(array('contact'=>$sensitive,'gps'=>$sensitive)),'created_at'=>$now,'updated_at'=>$now));
$dispatchIds=$ids($resolve('dispatch.arrived',$dispatch,array('driver_id'=>$rival,'customer_id'=>$rival)));
$check(in_array($customer,$dispatchIds,true) && in_array($driver,$dispatchIds,true) && in_array($manager,$dispatchIds,true) && !in_array($viewer,$dispatchIds,true) && !in_array($rival,$dispatchIds,true),'dispatch stages notify stored customer, current assigned driver and current operator managers');
$check(!in_array($customer,$ids($resolve('dispatch.contact_attempt',$dispatch)),true),'internal contact attempt evidence does not notify customer');
$wpdb->update(DB::table('memberships'),array('status'=>'suspended'),array('user_id'=>$driver,'org_id'=>$orgA));
$check(!in_array($driver,$ids($resolve('dispatch.arrived',$dispatch)),true),'assigned driver must retain active scoped membership at delivery time');
$wpdb->update(DB::table('memberships'),array('status'=>'active'),array('user_id'=>$driver,'org_id'=>$orgA));
// Actual persisted outbox -> reviewed bilingual template -> private in-app rows.
wp_set_current_user($agency);
$approvedBefore=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Atlas_Notifications::table('templates').' WHERE event_type=%s AND state=%s','dispatch.no_show','approved'));
$draft=$request('POST','/notification-operations/templates',array('event_type'=>'dispatch.no_show','locale'=>'en','body'=>'Fictional draft notice {reference}.','reason'=>'Draft requires later explicit review','approve'=>false));
$approvedAfter=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Atlas_Notifications::table('templates').' WHERE event_type=%s AND state=%s','dispatch.no_show','approved'));
$check($draft->get_status()===200 && ($draft->get_data()['state']??'')==='draft' && $approvedBefore===$approvedAfter,'new domain template stays a draft until explicit agency approval');
foreach (array('en'=>'Fictional local quote notice {reference}.','fa'=>'اعلان محلی ساختگی پیشنهاد {reference}.') as $locale=>$text) {
    $template=$request('POST','/notification-operations/templates',array('event_type'=>'procurement.quote','locale'=>$locale,'body'=>$text,'reason'=>'Explicit fictional local notice review','approve'=>true));
    $check($template->get_status()===200,'explicit reviewed '.$locale.' in-app template accepted for emitted event');
}
DB::emit('procurement.quote',$quote); $persisted=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('outbox').' WHERE event_type=%s AND aggregate_id=%s ORDER BY created_at DESC,id DESC LIMIT 1','procurement.quote',$quote),ARRAY_A);
if (!$persisted) WP_CLI::error('Committed notice outbox fixture failed.');
$delivered=apply_filters('atlas_core_deliver_outbox',false,$persisted); $firstCount=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Atlas_Notifications::table().' WHERE event_id=%s',$persisted['id']));
$replayed=apply_filters('atlas_core_deliver_outbox',false,$persisted); $secondCount=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Atlas_Notifications::table().' WHERE event_id=%s',$persisted['id']));
$check($delivered===true && $replayed===true && $firstCount===count($quoteAudience) && $secondCount===$firstCount,'actual in-app delivery retries create one notice per eligible recipient');
$messages=$wpdb->get_results($wpdb->prepare('SELECT recipient_id,reference_id,body,locale FROM '.Atlas_Notifications::table().' WHERE event_id=%s',$persisted['id']),ARRAY_A);
$leaked=array_filter($messages,static function($row)use($sensitive,$quote,$rival){return str_contains($row['body'],$sensitive) || $row['reference_id']!==$quote || (int)$row['recipient_id']===$rival;});
$fa=array_filter($messages,static function($row)use($manager){return (int)$row['recipient_id']===$manager && $row['locale']==='fa' && str_contains($row['body'],'اعلان');});
$check(!$leaked && count($fa)===1,'bilingual message bodies use UUID substitution and never source financial/contact payload or rival recipients');
wp_set_current_user($manager); $mine=$request('GET','/notifications'); $headers=$mine->get_headers();
$check($mine->get_status()===200 && ($headers['Cache-Control']??$headers['cache-control']??'')==='private, no-store' && in_array($quote,array_column($mine->get_data()['items'],'reference_id'),true),'eligible member can read its private no-store in-app notice');
wp_set_current_user($rival); $other=$request('GET','/notifications')->get_data();
$check(!in_array($quote,array_column($other['items'],'reference_id'),true),'rival notification account has no selected-supplier quote reference');
$check($mailCount===0 && $networkCount===0,'domain notices perform no email, SMS or external HTTP transport');
remove_filter('pre_wp_mail',$mailBlock,PHP_INT_MAX); remove_filter('pre_http_request',$httpBlock,PHP_INT_MAX); DB::set_test_now(null);
echo "Atlas partner notices checks: $passed passed, $failed failed\n"; if($failed)WP_CLI::error('Partner notice checks failed.'); WP_CLI::success('Private in-app domain notice checks passed; no external delivery evidence.');
