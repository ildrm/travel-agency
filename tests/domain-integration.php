<?php
/** Run on a disposable local WordPress via wp eval-file; this file creates/removes fictional fixtures. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local') { throw new RuntimeException('Local WP-CLI only.'); }
if (!class_exists('Atlas_Operations') || !class_exists('Atlas\\Core\\Records')) { WP_CLI::error('Activate Atlas Core and Atlas Operations first.'); }
require_once ABSPATH . 'wp-admin/includes/user.php';
$users = array(); $records = array(); $keys = array(); $offers = array(); $orgs = array(); $bookings = array(); $holds = array();
$check = function ($condition, $message) { if (!$condition) throw new RuntimeException($message); };
$call = function ($method, $route, $body = null, $key = '') {
    $r = new WP_REST_Request($method, $route);
    if ($body !== null) { $r->set_header('content-type','application/json'); $r->set_body(wp_json_encode($body)); }
    if ($key !== '') $r->set_header('Idempotency-Key',$key);
    return rest_do_request($r);
};
try {
    foreach (array('one','two') as $suffix) {
        $uid = wp_insert_user(array('user_login'=>'atlas-test-'.wp_generate_password(12,false).$suffix,'user_pass'=>wp_generate_password(30),'role'=>'subscriber'));
        if (is_wp_error($uid)) throw new RuntimeException('Fixture user creation failed.');
        $users[] = $uid;
    }
    wp_set_current_user($users[0]);
    $payload=array('category'=>'booking','subject'=>'Fictional support','message'=>'Please review my reservation.');
    $key='domain_test_'.str_replace('-','',wp_generate_uuid4()); $keys[]=$key;
    $first=$call('POST','/agency/v1/support-cases',$payload,$key); $check($first->get_status() < 300,'Support creation failed: HTTP '.$first->get_status().' '.wp_json_encode($first->get_data()));
    $item=$first->get_data(); $records[]=$item['id'];
    $again=$call('POST','/agency/v1/support-cases',$payload,$key);
    $check($again->get_status() < 300 && $again->get_data()['id']===$item['id'],'Same request must return same support record.');
    $changed=$payload; $changed['subject']='Changed';
    $check($call('POST','/agency/v1/support-cases',$changed,$key)->get_status()===409,'Conflicting retry must fail.');
    wp_set_current_user($users[1]);
    $check($call('GET','/agency/v1/operations/support_case/'.$item['id'])->get_status()===404,'Another customer must not read the case.');
    wp_set_current_user($users[0]);
    $transfer=array('pickup'=>'Fictional station','dropoff'=>'Fictional hotel','pickup_at'=>'2027-06-20T09:00:00Z','pickup_zone'=>'Asia/Tehran','dropoff_at'=>'2027-06-20T10:00:00Z','dropoff_zone'=>'Asia/Tehran','passengers'=>2,'luggage'=>1,'vehicle_class'=>'car','contact'=>'example@example.invalid');
    $key='domain_test_'.str_replace('-','',wp_generate_uuid4()); $keys[]=$key;
    $request=$call('POST','/agency/v1/transfer-requests',$transfer,$key);
    $check($request->get_status() < 300,'Transfer request should be saved.');
    $tr=$request->get_data(); $records[]=$tr['id'];
    $check($tr['type']==='transfer_request' && $tr['state']==='draft','Transfer request must not be a booking.');
    $bad=$transfer; $bad['dropoff_at']='2027-06-20T08:00:00Z';
    $key='domain_test_'.str_replace('-','',wp_generate_uuid4()); $keys[]=$key;
    $check($call('POST','/agency/v1/transfer-requests',$bad,$key)->get_status()===422,'Reversed transfer interval must fail.');
    $bad=$transfer; $bad['passengers']=0;
    $key='domain_test_'.str_replace('-','',wp_generate_uuid4()); $keys[]=$key;
    $check($call('POST','/agency/v1/transfer-requests',$bad,$key)->get_status()===422,'Zero passengers must fail.');
    wp_set_current_user(0);
    $check($call('POST','/agency/v1/transfer-requests',$transfer,'domain_test_anonymous_key')->get_status() >= 400,'Anonymous request must fail closed.');
    // A scoped leader proposes a structured route; an independent agency reviewer approves it.
    global $wpdb;
    $admin = wp_insert_user(array('user_login'=>'atlas-agency-'.wp_generate_password(12,false),'user_pass'=>wp_generate_password(30),'role'=>'administrator'));
    if (is_wp_error($admin)) throw new RuntimeException('Agency fixture creation failed.');
    $users[]=$admin;
    $wpdb->insert(Atlas\Core\DB::table('organizations'),array('name'=>'Fictional Guide Org','status'=>'active','created_at'=>current_time('mysql',true))); $org=(int)$wpdb->insert_id; $orgs[]=$org;
    $leader=get_userdata($users[0]); $leader->add_cap('atlas_manage_records'); $leader->add_cap('atlas_manage_offers');
    $wpdb->insert(Atlas\Core\DB::table('memberships'),array('org_id'=>$org,'user_id'=>$users[0],'role'=>'leader','status'=>'active','updated_at'=>current_time('mysql',true)));
    wp_set_current_user($users[0]);
    $offer=Atlas\Core\Offers::save(0,array('kind'=>'tour','org_id'=>$org,'title_en'=>'Fictional Route','title_fa'=>'مسیر فرضی','description_en'=>'Test route','description_fa'=>'مسیر آزمایشی','registration_open_at'=>'2027-06-01T00:00:00Z','registration_close_at'=>'2027-06-15T00:00:00Z','timezone'=>'UTC','capacity'=>5,'amount_irr'=>1000000,'mode'=>'local','status'=>'draft','product_id'=>0),$users[0]);
    $check(!is_wp_error($offer),'Leader should create draft offer.'); $offers[]=$offer;
    $version=array('org_id'=>$org,'offer_id'=>$offer,'title_en'=>'Fictional Route','stops'=>array(array('city'=>'London','country'=>'GB','timezone'=>'Europe/London','arrival'=>'2027-09-01T08:00:00Z','departure'=>'2027-09-01T14:00:00Z')),'days'=>array(array('date'=>'2027-09-01','items'=>array(array('type'=>'walk','description'=>'Fictional walk','start'=>'2027-09-01T09:00:00Z','end'=>'2027-09-01T10:00:00Z')))),'legs'=>array());
    $key='domain_test_'.str_replace('-','',wp_generate_uuid4()); $keys[]=$key;
    $tour=$call('POST','/agency/v1/operations/tour_version',$version,$key); $check($tour->get_status()<300,'Tour draft should be saved.'); $tv=$tour->get_data(); $records[]=$tv['id'];
    $submitted=$call('PATCH','/agency/v1/operations/tour_version/'.$tv['id'],array('version'=>$tv['version'],'changes'=>array('state'=>'submitted')));
    $check($submitted->get_status()<300,'Complete route should submit.');
    $rejected=$call('PATCH','/agency/v1/operations/tour_version/'.$tv['id'],array('version'=>2,'changes'=>array('state'=>'approved')));
    $check($rejected->get_status()>=400,'Partner must not approve its own tour.');
    wp_set_current_user($admin);
    $approved=$call('PATCH','/agency/v1/operations/tour_version/'.$tv['id'],array('version'=>2,'changes'=>array('state'=>'approved')));
    $check($approved->get_status()<300,'Independent agency reviewer should approve.');
    $publish=Atlas\Core\Offers::save($offer,array('status'=>'published','expected_version'=>1),$admin);
    $check(!is_wp_error($publish),'Approved linked structured route should permit publication.');
    // One attendee can be checked in only once, regardless of concurrent or repeat staff requests.
    $event=Atlas\Core\Offers::save(0,array('kind'=>'event','org_id'=>0,'title_en'=>'Fictional Event','description_en'=>'Test only','registration_open_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-300),'registration_close_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600),'timezone'=>'UTC','capacity'=>2,'amount_irr'=>1000000,'mode'=>'local','status'=>'published','product_id'=>0),$admin);
    $check(!is_wp_error($event),'Event fixture should be saved.'); $offers[]=$event;
    wp_set_current_user($users[0]);
    $key='domain_test_'.str_replace('-','',wp_generate_uuid4()); $keys[]=$key;
    $hold=Atlas\Core\Booking::hold($event,1,1,$users[0],$key,array());
    $check(!is_wp_error($hold),'Event hold should succeed.'); $bookings[]=$hold['booking_id']; $holds[]=$hold['hold_id'];
    $proof=Atlas\Core\VerifiedPayment::from_adapter('local_simulator','checkin-test-'.wp_generate_uuid4(),1000000);
    $verified=Atlas\Core\Booking::verify($hold['booking_id'],$proof); $check(!is_wp_error($verified) && $verified['status']==='paid_pending_confirmation','Event payment proof should verify.');
    wp_set_current_user($admin);
    $confirmation=Atlas\Core\Booking::confirm($hold['booking_id'],$admin,'Fictional fixture'); $check(!is_wp_error($confirmation),'Agency confirmation should succeed.');
    $checkin=array('booking_id'=>$hold['booking_id'],'attendee_index'=>1);
    $first_checkin=$call('POST','/agency/v1/events/'.$event.'/checkins',$checkin); $check($first_checkin->get_status()===201,'Check-in should succeed: '.wp_json_encode($first_checkin->get_data()));
    $check($call('POST','/agency/v1/events/'.$event.'/checkins',$checkin)->get_status()===409,'Duplicate check-in must fail.');
    $checkin['attendee_index']=2; $check($call('POST','/agency/v1/events/'.$event.'/checkins',$checkin)->get_status()===409,'Attendee number beyond quantity must fail.');
    wp_set_current_user($users[1]); $check($call('POST','/agency/v1/events/'.$event.'/checkins',$checkin)->get_status()>=400,'Unassigned customer may not check in.');
    // Approved course/cohort, purchaser-owned hold and no second allocation.
    wp_set_current_user($users[0]);
    $key='domain_test_'.str_replace('-','',wp_generate_uuid4()); $keys[]=$key;
    $course=$call('POST','/agency/v1/operations/course',array('org_id'=>$org,'title_en'=>'Fictional Course','materials'=>array(array('title'=>'Lesson 1','body'=>'Fictional learning material.'))),$key);
    $check($course->get_status()<300,'Course creation failed: '.wp_json_encode($course->get_data())); $c=$course->get_data(); $records[]=$c['id'];
    $csubmit=$call('PATCH','/agency/v1/operations/course/'.$c['id'],array('version'=>1,'changes'=>array('state'=>'submitted'))); $check($csubmit->get_status()<300,'Course submission failed.');
    wp_set_current_user($admin); $capprove=$call('PATCH','/agency/v1/operations/course/'.$c['id'],array('version'=>2,'changes'=>array('state'=>'approved'))); $check($capprove->get_status()<300,'Course approval failed.');
    wp_set_current_user($users[0]);
    $coffer=Atlas\Core\Offers::save(0,array('kind'=>'cohort','org_id'=>$org,'title_en'=>'Fictional cohort','registration_open_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-300),'registration_close_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600),'timezone'=>'UTC','capacity'=>2,'amount_irr'=>1000000,'mode'=>'local','status'=>'draft','product_id'=>0),$users[0]);
    $check(!is_wp_error($coffer),'Cohort offer fixture failed.'); $offers[]=$coffer;
    $key='domain_test_'.str_replace('-','',wp_generate_uuid4()); $keys[]=$key;
    $cohort=$call('POST','/agency/v1/operations/cohort',array('org_id'=>$org,'course_id'=>$c['id'],'offer_id'=>$coffer,'venue'=>'Fictional classroom','timezone'=>'UTC'),$key);
    $check($cohort->get_status()<300,'Cohort creation failed: '.wp_json_encode($cohort->get_data())); $ch=$cohort->get_data(); $records[]=$ch['id'];
    $submit=$call('PATCH','/agency/v1/operations/cohort/'.$ch['id'],array('version'=>1,'changes'=>array('state'=>'submitted'))); $check($submit->get_status()<300,'Cohort submit failed.');
    wp_set_current_user($admin); $approve=$call('PATCH','/agency/v1/operations/cohort/'.$ch['id'],array('version'=>2,'changes'=>array('state'=>'approved'))); $check($approve->get_status()<300,'Cohort approval failed.');
    $published=Atlas\Core\Offers::save($coffer,array('status'=>'published','expected_version'=>1),$admin); $check(!is_wp_error($published),'Reviewed cohort offer should publish.');
    $offer_data=Atlas\Core\Offers::get($coffer,true);
    wp_set_current_user($users[1]); $key='domain_test_'.str_replace('-','',wp_generate_uuid4()); $keys[]=$key;
    $seat=Atlas\Core\Booking::hold($coffer,(int)$offer_data['version'],1,$users[1],$key,array()); $check(!is_wp_error($seat),'Cohort hold failed.'); $bookings[]=$seat['booking_id'];$holds[]=$seat['hold_id']; $keys[]='enrollment_'.$seat['booking_id'];
    $payload=array('booking_id'=>$seat['booking_id'],'learner_id'=>$users[1]);
    $enroll=$call('POST','/agency/v1/cohorts/'.$ch['id'].'/enrollments',$payload); $check($enroll->get_status()<300,'Enrollment failed: '.wp_json_encode($enroll->get_data())); $en=$enroll->get_data(); $records[]=$en['id'];
    $enroll_again=$call('POST','/agency/v1/cohorts/'.$ch['id'].'/enrollments',$payload); $check($enroll_again->get_status()<300 && $enroll_again->get_data()['id']===$en['id'],'Enrollment retry must not allocate another record.');
    $allocated=(int)$wpdb->get_var($wpdb->prepare('SELECT allocated FROM '.Atlas\Core\DB::table('inventory').' WHERE offer_id=%d',$coffer)); $check($allocated===1,'Enrollment must not allocate a second seat.');
    $check($call('GET','/agency/v1/cohorts/'.$ch['id'].'/materials')->get_status()===404,'Unconfirmed learner must not access materials.');
    $payment=Atlas\Core\VerifiedPayment::from_adapter('local_simulator','cohort-test-'.wp_generate_uuid4(),1000000); $v=Atlas\Core\Booking::verify($seat['booking_id'],$payment); $check(!is_wp_error($v),'Cohort payment proof failed.');
    wp_set_current_user($admin); $confirmed=Atlas\Core\Booking::confirm($seat['booking_id'],$admin,'Fictional fixture'); $check(!is_wp_error($confirmed),'Cohort confirmation failed.');
    wp_set_current_user($users[1]); $materials=$call('GET','/agency/v1/cohorts/'.$ch['id'].'/materials'); $check($materials->get_status()===200 && count($materials->get_data()['materials'])===1,'Confirmed learner should access materials.');
    wp_set_current_user($users[0]); $check($call('GET','/agency/v1/cohorts/'.$ch['id'].'/materials')->get_status()===404,'Another user must not access materials.');
    WP_CLI::log('Domain integration checks passed: support, transfer, structured tour, check-in, cohort enrollment and authorization.');
} finally {
    global $wpdb;
    foreach ($bookings as $id) { $wpdb->delete($wpdb->prefix.'atlas_event_checkins',array('booking_id'=>$id)); $wpdb->delete(Atlas\Core\DB::table('payments'),array('booking_id'=>$id)); $wpdb->delete(Atlas\Core\DB::table('bookings'),array('id'=>$id)); }
    foreach ($holds as $id) $wpdb->delete(Atlas\Core\DB::table('holds'),array('id'=>$id));
    foreach ($offers as $id) { $wpdb->delete(Atlas\Core\DB::table('inventory'),array('offer_id'=>$id)); wp_delete_post($id,true); }
    foreach ($orgs as $id) { $wpdb->delete(Atlas\Core\DB::table('memberships'),array('org_id'=>$id)); $wpdb->delete(Atlas\Core\DB::table('organizations'),array('id'=>$id)); }
    foreach ($records as $id) $wpdb->delete(Atlas\Core\DB::table('records'),array('id'=>$id));
    foreach ($keys as $key) $wpdb->delete(Atlas\Core\DB::table('idempotency'),array('idem_key'=>$key));
    foreach ($users as $id) wp_delete_user($id);
    wp_set_current_user(0);
}
