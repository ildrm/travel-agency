<?php
/** Disposable local fixtures: learner-consented payer relationships, private eligibility and shared multi-seat holds. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='local' || !class_exists('Atlas_Academy')) throw new RuntimeException('Local activated academy only.');
if (!defined('ATLAS_TEST_CLOCK')) define('ATLAS_TEST_CLOCK',true);
require_once ABSPATH.'wp-admin/includes/user.php';
$users=array(); $records=array(); $orgs=array(); $offers=array(); $holds=array(); $bookings=array(); $keys=array(); $orders=array(); $checks=0;
$check=static function($ok,$message) use(&$checks) { $checks++; if (!$ok) throw new RuntimeException($message); };
$call=static function($method,$path,$body=null,$query=array()) { $r=new WP_REST_Request($method,'/agency/v1/'.$path); if ($body!==null) { $r->set_header('content-type','application/json'); $r->set_body(wp_json_encode($body)); } $r->set_query_params($query); return rest_do_request($r); };
$user=static function($role,$caps=array()) use(&$users) { $id=wp_insert_user(array('user_login'=>'learner-fixture-'.wp_generate_password(14,false),'user_pass'=>wp_generate_password(32),'role'=>$role)); if (is_wp_error($id)) throw new RuntimeException('User fixture'); $users[]=$id; foreach ($caps as $cap) get_userdata($id)->add_cap($cap); return $id; };
$record=static function($type,$org,$owner,$fields) use(&$records) { global $wpdb; $id=wp_generate_uuid4(); if ($wpdb->insert(Atlas\Core\DB::table('records'),array('id'=>$id,'type'=>$type,'org_id'=>$org,'owner_id'=>$owner,'state'=>'approved','payload'=>wp_json_encode($fields),'version'=>1,'created_at'=>Atlas\Core\DB::now(),'updated_at'=>Atlas\Core\DB::now()))!==1) throw new RuntimeException('Record fixture'); $records[]=$id; return $id; };
$hold=static function($offer,$payer,$quantity,$options=array(),$refs=array(),$key='') use(&$keys,&$bookings,&$holds) { $key=$key?:'learner_'.wp_generate_uuid4(); $keys[]=$key; $o=Atlas\Core\Offers::get($offer,true); $result=Atlas\Core\Booking::hold($offer,(int)$o['version'],$quantity,$payer,$key,$refs,$options); if (!is_wp_error($result)) { $bookings[]=$result['booking_id']; $holds[]=$result['hold_id']; } return $result; };
$grant=static function($cohort,$learner,$payer,$evidence,$expires='2035-05-25T00:00:00Z') use($call,&$records) { wp_set_current_user($learner); $result=$call('POST','academy/authorizations',array('cohort_id'=>$cohort,'payer_id'=>$payer,'eligibility_reference'=>$evidence,'expires_at'=>$expires,'learner_attested'=>true,'reason'=>'Fictional learner consents to this identified payer and cohort only.')); if ($result->get_status()===200) $records[]=$result->get_data()['id']; return $result; };
$selection=static function($response) { $g=$response->get_data(); return array('learner_id'=>(int)$g['fields']['learner_id'],'authorization_id'=>$g['id'],'authorization_version'=>(int)$g['version'],'eligibility_reference'=>$g['fields']['eligibility_reference']); };
try {
    global $wpdb; Atlas\Core\DB::set_test_now('2035-05-15T08:00:00Z');
    $admin=$user('administrator',array('atlas_academy_schedule','atlas_academy_review','atlas_academy_issue','atlas_manage_bookings','atlas_manage_records','atlas_manage_offers'));
    $supplier=$user('subscriber',array('atlas_manage_records','atlas_manage_offers')); $instructor=$user('subscriber',array('atlas_academy_instruct'));
    $payer=$user('subscriber'); $wrong_payer=$user('subscriber'); $adult=$user('subscriber'); $second=$user('subscriber'); $minor=$user('subscriber'); $too_young=$user('subscriber');
    $wpdb->insert(Atlas\Core\DB::table('organizations'),array('name'=>'Fictional learner authorization academy','status'=>'active','created_at'=>Atlas\Core\DB::now())); $org=(int)$wpdb->insert_id; $orgs[]=$org;
    foreach (array($supplier,$instructor) as $id) $wpdb->insert(Atlas\Core\DB::table('memberships'),array('org_id'=>$org,'user_id'=>$id,'role'=>'contributor','status'=>'active','updated_at'=>Atlas\Core\DB::now()));
    $course=$record('course',$org,$supplier,array('title_en'=>'Fictional reviewed learner course','title_fa'=>'دوره فرضی بازبینی‌شده فراگیر','syllabus'=>'Fictional practice syllabus.','prerequisites'=>'Fictional introductory guiding experience must be reviewed.','materials'=>array(array('title'=>'Fictional lesson','body'=>'Private fictional learner material.'))));
    $cohorts=array();
    foreach (array('A','B') as $i=>$label) {
        wp_set_current_user($supplier);
        $offer=Atlas\Core\Offers::save(0,array('kind'=>'cohort','org_id'=>$org,'title_en'=>'Fictional learner cohort '.$label,'registration_open_at'=>'2035-05-01T00:00:00Z','registration_close_at'=>'2035-06-01T00:00:00Z','timezone'=>'UTC','capacity'=>8,'amount_irr'=>1000000,'mode'=>'local','status'=>'draft','product_id'=>0,'eligibility'=>array('min_age'=>16,'max_age'=>65,'guardian_below'=>18,'age_on'=>'2035-06-01')),$supplier);
        $check(!is_wp_error($offer),'Reviewed cohort offer fixture should save.'); $offers[]=$offer;
        $cohort=$record('cohort',$org,$supplier,array('course_id'=>$course,'offer_id'=>$offer,'venue'=>'Fictional learning room '.$label,'timezone'=>'UTC','prerequisites'=>'Fictional practical equipment declaration.')); $cohorts[]=$cohort;
        wp_set_current_user($admin);
        $start=$i?'2035-06-10T11:00:00Z':'2035-06-10T09:00:00Z'; $end=$i?'2035-06-10T12:00:00Z':'2035-06-10T10:00:00Z';
        $plan=$call('POST','academy/cohorts/'.$cohort.'/plan',array('version'=>0,'sessions'=>array(array('id'=>'practical','start'=>$start,'end'=>$end,'timezone'=>'UTC','room'=>'fictional-room-'.wp_generate_uuid4(),'instructors'=>array($instructor))),'syllabus_version'=>'fictional-syllabus-v1','policy_reference'=>'fictional-director-reviewed-prerequisite-policy','required_sessions'=>array('practical'),'assessment_required'=>true,'rubric_version'=>'fictional-rubric-v1','publish'=>true,'reason'=>'Fictional director policy review without accreditation claim.'));
        $check($plan->get_status()===200,'A reviewed plan fixes the prerequisite and syllabus snapshot.'); $records[]=$plan->get_data()['id'];
        $published=Atlas\Core\Offers::save($offer,array('status'=>'published','expected_version'=>1),$admin); $check(!is_wp_error($published),'A policy-reviewed cohort can publish while individual prerequisites remain fail-closed.');
    }
    $people=array();
    foreach (array(array($adult,'1990-01-02',false),array($second,'2000-02-03',false),array($minor,'2018-01-01',false),array($too_young,'2025-01-01',true),array($payer,'1992-01-01',false)) as $person) {
        wp_set_current_user($person[0]); $p=Atlas\Core\Records::create('traveler',array('name'=>'Fictional self-declared learner','birth_date'=>$person[1],'guardian_name'=>$person[2]?'Fictional declared guardian':'','guardian_attested'=>$person[2]),$person[0]); $check(!is_wp_error($p),'Learner-owned eligibility profile should save.'); $people[$person[0]]=$p; $records[]=$p['id'];
    }
    wp_set_current_user($payer);
    $check(is_wp_error(Atlas\Core\Records::get('traveler',$people[$adult]['id'],$payer)),'Payer cannot bypass owner-only traveler Records access.');
    $check(is_wp_error($hold($offers[0],$payer,2)),'Legacy group booking remains denied without learner authorizations.');
    $check(is_wp_error($hold($offers[0],$payer,1,array(),array($people[$payer]['id']))),'Legacy self admission cannot bypass the applicable prerequisite review.');
    $forged=$call('POST','academy/authorizations',array('cohort_id'=>$cohorts[0],'payer_id'=>$wrong_payer,'eligibility_reference'=>$people[$adult]['id'],'expires_at'=>'2035-05-25T00:00:00Z','learner_attested'=>true,'reason'=>'Fictional attempted cross-owner evidence grant.'));
    $check($forged->get_status()===404,'A buyer cannot authorize another learner evidence by claiming to be its owner.');
    $child=$grant($cohorts[0],$too_young,$payer,$people[$too_young]['id']); $check($child->get_status()===422,'The declared minimum age policy is evaluated rather than waived for a payer grant.');
    $minor_without_guardian=$grant($cohorts[0],$minor,$payer,$people[$minor]['id']); $check($minor_without_guardian->get_status()===422,'A minor who meets minimum age still requires the declared guardian evidence.');
    wp_set_current_user($minor); $updated=Atlas\Core\Records::update('traveler',$people[$minor]['id'],array('guardian_name'=>'Fictional declared guardian','guardian_attested'=>true),$minor,1); $check(!is_wp_error($updated),'Learner can update their own declared guardian fields.'); $people[$minor]=$updated;
    $grants=array(); foreach (array($adult,$second,$minor) as $learner) { $g=$grant($cohorts[0],$learner,$payer,$people[$learner]['id']); $check($g->get_status()===200,'Each learner may explicitly authorize this payer for one cohort.'); $grants[$learner]=$g; }
    $too_long=$grant($cohorts[0],$adult,$payer,$people[$adult]['id'],'2035-09-01T00:00:00Z'); $check($too_long->get_status()===422,'Authorizations cannot grant indefinite eligibility access.');
    wp_set_current_user($wrong_payer); $check(!$call('GET','academy/cohorts/'.$cohorts[0].'/payer-authorizations')->get_data()['items'],'A different payer cannot list grants addressed to the purchaser.');
    wp_set_current_user($payer); $addressed=$call('GET','academy/cohorts/'.$cohorts[0].'/payer-authorizations'); $check($addressed->get_status()===200 && count($addressed->get_data()['items'])===3,'Purchaser lists only the learner-accepted grants addressed to them.');
    $json=wp_json_encode($addressed->get_data()); $check(!str_contains($json,'birth_date') && !str_contains($json,'1990-01-02') && !str_contains($json,'guardian_name'),'Grant metadata never discloses raw birth date or guardian details to payer.');
    $selected=array_map($selection,array_values($grants)); $options=array('academy_learners'=>$selected);
    $check(is_wp_error($hold($offers[0],$payer,3,$options)),'Age/guardian evidence does not replace protected agency prerequisite review.');
    $review_body=array('version'=>0,'learner_id'=>$adult,'approved'=>true,'valid_until'=>'2035-06-20T00:00:00Z','evidence_reference'=>'fictional-private-prerequisite-review','reason'=>'Fictional independent prerequisite review.');
    $check($call('POST','academy/cohorts/'.$cohorts[0].'/prerequisite-review',$review_body)->get_status()===403,'Payer cannot approve their learners prerequisite evidence.');
    wp_set_current_user($admin);
    foreach (array($adult,$second,$minor,$payer) as $learner) { $review_body['learner_id']=$learner; $review=$call('POST','academy/cohorts/'.$cohorts[0].'/prerequisite-review',$review_body); $check($review->get_status()===200,'Agency reviewer can record a bounded independent prerequisite decision.'); $records[]=$review->get_data()['id']; }
    $check(is_wp_error(Atlas\Core\Records::get('traveler',$people[$adult]['id'],$admin)),'Agency override does not grant raw access to learner-owned traveler data.');
    wp_set_current_user($payer);
    $duplicate=array('academy_learners'=>array($selected[0],$selected[0])); $check(is_wp_error($hold($offers[0],$payer,2,$duplicate)),'One learner cannot fill two seats in an accepted group.');
    $bad=$options; $bad['academy_learners'][0]['eligibility_reference']=$people[$second]['id']; $check(is_wp_error($hold($offers[0],$payer,3,$bad)),'A grant cannot be swapped to different learner evidence.');
    wp_set_current_user($wrong_payer); $check(is_wp_error($hold($offers[0],$wrong_payer,3,$options)),'Valid authorizations cannot be borrowed by another buyer.');
    wp_set_current_user($payer); $check(is_wp_error($hold($offers[1],$payer,3,$options)),'Payer authority is scoped to one cohort and cannot be reused for another.');
    $short=$grant($cohorts[0],$adult,$payer,$people[$adult]['id'],'2035-05-15T08:01:00Z'); $check($short->get_status()===200,'A short-lived learner permission can be created.');
    Atlas\Core\DB::set_test_now('2035-05-15T08:02:00Z'); wp_set_current_user($payer); $check(is_wp_error($hold($offers[0],$payer,1,array('academy_learners'=>array($selection($short))))),'An expired grant cannot create a new commitment.'); Atlas\Core\DB::set_test_now('2035-05-15T08:00:00Z');
    $accepted=$hold($offers[0],$payer,3,$options); $check(!is_wp_error($accepted),'One shared hold may atomically accept three independently authorized learners: '.wp_json_encode($accepted)); $keys[]='enrollment_'.$accepted['booking_id'];
    $stored=Atlas\Core\Booking::get($accepted['booking_id'],$payer); $snapshot=json_decode($wpdb->get_var($wpdb->prepare('SELECT snapshot FROM '.Atlas\Core\DB::table('bookings').' WHERE id=%s',$accepted['booking_id'])),true);
    $check(count($snapshot['booking_options']['academy_learners'])===3 && !str_contains(wp_json_encode($snapshot),'1990-01-02') && !str_contains(wp_json_encode($snapshot),'birth_date'),'Accepted booking stores exact learner references and prerequisite review versions, not raw DOB.');
    $check(!isset($stored['snapshot'],$stored['participants']),'Payer booking/account projection does not expose private eligibility snapshots.');
    $allocated_before=(int)$wpdb->get_var($wpdb->prepare('SELECT allocated FROM '.Atlas\Core\DB::table('inventory').' WHERE offer_id=%d',$offers[0])); $check($allocated_before===3,'The shared hold allocates exactly the three requested places.');
    $order=wc_create_order(array('customer_id'=>$payer)); $check(!is_wp_error($order),'Fictional payment initiation order fixture should exist.'); $orders[]=$order->get_id(); $wpdb->update(Atlas\Core\DB::table('bookings'),array('order_id'=>$order->get_id()),array('id'=>$accepted['booking_id']));
    $check(is_wp_error(Atlas\Core\Booking::can_initiate_payment($accepted['booking_id'],$payer,$order->get_id())),'Payment initiation is blocked until every accepted learner is linked.');
    $enrollment_path='cohorts/'.$cohorts[0].'/enrollments';
    $partial=$call('POST',$enrollment_path,array('booking_id'=>$accepted['booking_id'],'learner_id'=>$adult)); $check($partial->get_status()===422,'A group cannot be linked as an unapproved single-learner subset.');
    Atlas\Core\DB::set_test_audit_failure(true); $failed=$call('POST',$enrollment_path,array('booking_id'=>$accepted['booking_id'])); Atlas\Core\DB::set_test_audit_failure(false);
    $check($failed->get_status()===503 && (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Atlas\Core\DB::table('records')." WHERE type=%s AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.booking_id'))=%s",'enrollment',$accepted['booking_id']))===0,'Audit failure rolls back every learner link rather than committing a partial group.');
    wp_set_current_user($adult); $revoke=$call('POST','academy/authorizations/'.$grants[$adult]->get_data()['id'].'/revoke',array('version'=>1,'reason'=>'Fictional learner withdraws permission for future purchases.')); $check($revoke->get_status()===200,'Learner may revoke their payer authorization.');
    wp_set_current_user($payer); $check(is_wp_error($hold($offers[0],$payer,3,$options)),'Revocation blocks a new group commitment.');
    $linked=$call('POST',$enrollment_path,array('booking_id'=>$accepted['booking_id'])); $check($linked->get_status()===200 && $linked->get_data()['enrolled_count']===3,'Revocation does not silently destroy the learner binding already accepted in a hold.'); $enrollments=array();
    foreach ($linked->get_data()['items'] as $item) { $records[]=$item['id']; $enrollments[$item['owner_id']]=$item; $check($item['owner_id']===$item['fields']['learner_id'] && $item['fields']['hold_id']===$accepted['hold_id'],'Each enrollment is owned by its learner and references the same accepted hold.'); }
    $again=$call('POST',$enrollment_path,array('booking_id'=>$accepted['booking_id'])); $check($again->get_status()===200 && $again->get_data()===$linked->get_data(),'Group enrollment retry is idempotent for the exact complete learner set.');
    $check((int)$wpdb->get_var($wpdb->prepare('SELECT allocated FROM '.Atlas\Core\DB::table('inventory').' WHERE offer_id=%d',$offers[0]))===$allocated_before,'Linking a group never allocates another seat.');
    $check(Atlas\Core\Booking::can_initiate_payment($accepted['booking_id'],$payer,$order->get_id())===true,'The exact complete enrollment links satisfy the real Core payment gate.');
    $check(is_wp_error(Atlas\Core\Records::get('enrollment',$enrollments[$adult]['id'],$payer)),'Paying does not confer Records ownership of another learner enrollment.');
    $proof=Atlas\Core\VerifiedPayment::from_adapter('local_simulator','learner-proof-'.wp_generate_uuid4(),3000000); $check(!is_wp_error(Atlas\Core\Booking::verify($accepted['booking_id'],$proof)),'Fictional payment verifies once for the shared three-place booking.');
    wp_set_current_user($admin); $check(!is_wp_error(Atlas\Core\Booking::confirm($accepted['booking_id'],$admin,'Fictional academy confirms the complete authorized group.')),'Manual agency confirmation remains separate from payment and learner linkage.');
    // A separate paid proof cannot create enrollment or bypass the confirmation guard.
    wp_set_current_user($payer); $proof_only=$hold($offers[0],$payer,1,array('academy_learners'=>array($selection($grants[$second])))); $check(!is_wp_error($proof_only),'Independent proof-only fixture hold should exist.');
    $check(!is_wp_error(Atlas\Core\Booking::verify($proof_only['booking_id'],Atlas\Core\VerifiedPayment::from_adapter('local_simulator','learner-proof-'.wp_generate_uuid4(),1000000))),'Independent payment proof may arrive before enrollment is linked.');
    wp_set_current_user($admin); $check(is_wp_error(Atlas\Core\Booking::confirm($proof_only['booking_id'],$admin,'Fictional missing-link confirmation attempt')),'Money alone never confirms an enrollment with missing learner links.');
    Atlas\Core\DB::set_test_now('2035-06-11T12:00:00Z');
    $assessment=$call('POST','academy/enrollments/'.$enrollments[$adult]['id'].'/assessment',array('version'=>0,'rubric_version'=>'fictional-rubric-v1','passed'=>false,'reason'=>'Confidential fictional practical assessment note.')); $check($assessment->get_status()===200,'An authorized reviewer can assess the exact learner even when a different payer owns the booking.');
    wp_set_current_user($adult); $own=$call('GET','academy/enrollments/'.$enrollments[$adult]['id']); $check($own->get_status()===200 && $own->get_data()['fields']['assessments'][0]['passed']===false,'Learner can privately see their own result.'); $records[]=$own->get_data()['id'];
    foreach (array($adult,$second,$minor) as $learner) { wp_set_current_user($learner); $check($call('GET','cohorts/'.$cohorts[0].'/materials')->get_status()===200,'An exact accepted learner retains materials after admission closes, including revoked future payer consent.'); }
    wp_set_current_user($payer); $check($call('GET','academy/enrollments/'.$enrollments[$adult]['id'])->get_status()===404 && $call('GET','cohorts/'.$cohorts[0].'/materials')->get_status()===404,'Payer booking/account visibility cannot reveal another learner assessment or materials.');
    wp_set_current_user($second); $check($call('GET','academy/enrollments/'.$enrollments[$adult]['id'])->get_status()===404,'A fellow learner cannot read another learner assessment.');
    $recipient_event=array('event_type'=>'academy.assessment','aggregate_id'=>$own->get_data()['id']); $recipients=Atlas_Academy::notification_recipients(array(),$recipient_event); $check(array_column($recipients,'user')===array($adult),'Assessment notices route to the learner, never the payer.');
    $grant_recipients=Atlas_Academy::notification_recipients(array(),array('event_type'=>'academy.payer_authorization_revoked','aggregate_id'=>$grants[$adult]->get_data()['id'])); $ids=array_column($grant_recipients,'user'); sort($ids,SORT_NUMERIC); $expected=array($adult,$payer); sort($expected,SORT_NUMERIC); $check($ids===$expected,'Scoped consent revocation notifies the affected learner and payer without disclosing evidence.');
    WP_CLI::log('Atlas learner booking checks: '.$checks.' passed, 0 failed. Scoped learner consent, private age/guardian evaluation, prerequisite review, atomic group links, payment gates, ownership and notification routing covered.');
} finally {
    global $wpdb; Atlas\Core\DB::set_test_now(null); Atlas\Core\DB::set_test_audit_failure(false);
    foreach ($orders as $id) { $o=wc_get_order($id); if ($o) $o->delete(true); }
    foreach (array_unique($bookings) as $id) { foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM '.Atlas\Core\DB::table('payments').' WHERE booking_id=%s',$id)) as $payment) $wpdb->delete(Atlas\Core\DB::table('ledger'),array('payment_id'=>$payment)); $wpdb->delete(Atlas\Core\DB::table('payments'),array('booking_id'=>$id)); $wpdb->delete(Atlas\Core\DB::table('bookings'),array('id'=>$id)); $wpdb->delete(Atlas\Core\DB::table('outbox'),array('aggregate_id'=>$id)); $wpdb->delete(Atlas\Core\DB::table('audit'),array('object_id'=>$id)); }
    foreach (array_unique($holds) as $id) $wpdb->delete(Atlas\Core\DB::table('holds'),array('id'=>$id));
    foreach ($orgs as $org) foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM '.Atlas\Core\DB::table('records').' WHERE org_id=%d',$org)) as $id) $records[]=$id;
    foreach ($users as $user_id) foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM '.Atlas\Core\DB::table('records').' WHERE owner_id=%d AND type IN (%s,%s,%s,%s)',$user_id,'academy_authorization','enrollment','academy_progress','academy_prerequisite')) as $id) $records[]=$id;
    foreach (array_unique($records) as $id) { $wpdb->delete(Atlas\Core\DB::table('records'),array('id'=>$id)); $wpdb->delete(Atlas\Core\DB::table('outbox'),array('aggregate_id'=>$id)); $wpdb->delete(Atlas\Core\DB::table('audit'),array('object_id'=>$id)); }
    foreach ($offers as $id) { $wpdb->delete(Atlas\Core\DB::table('inventory'),array('offer_id'=>$id)); wp_delete_post($id,true); }
    foreach ($orgs as $id) { $wpdb->delete(Atlas\Core\DB::table('memberships'),array('org_id'=>$id)); $wpdb->delete(Atlas\Core\DB::table('organizations'),array('id'=>$id)); }
    foreach ($keys as $key) $wpdb->delete(Atlas\Core\DB::table('idempotency'),array('idem_key'=>$key));
    foreach ($users as $id) wp_delete_user($id); wp_set_current_user(0);
}
