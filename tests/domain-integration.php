<?php
/** Run on a disposable local WordPress via wp eval-file; this file creates/removes fictional fixtures. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local') { throw new RuntimeException('Local WP-CLI only.'); }
if (!class_exists('Atlas_Operations') || !class_exists('Atlas\\Core\\Records')) { WP_CLI::error('Activate Atlas Core and Atlas Operations first.'); }
if (!defined('ATLAS_TEST_CLOCK')) define('ATLAS_TEST_CLOCK',true);
require_once ABSPATH . 'wp-admin/includes/user.php';
$users = array(); $records = array(); $keys = array(); $offers = array(); $orgs = array(); $bookings = array(); $holds = array(); $orders=array();
$checks=0;
$check = function ($condition, $message) use (&$checks) { $checks++; if (!$condition) throw new RuntimeException($message); };
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
    $check($item['state']==='new','New support cases must use the service state, not editorial approval.');
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
    // Shared support replies, internal notes and triage have distinct authorization.
    wp_set_current_user($users[0]);
    $reply_key='reply_'.str_replace('-','',wp_generate_uuid4());
    $reply=$call('POST','/agency/v1/support-cases/'.$item['id'].'/messages',array('message'=>'Fictional follow-up.'),$reply_key);
    $check($reply->get_status()===201,'Customer reply should be recorded.');
    $reply_again=$call('POST','/agency/v1/support-cases/'.$item['id'].'/messages',array('message'=>'Fictional follow-up.'),$reply_key);
    $check($reply_again->get_status()===200 && $reply_again->get_data()['id']===$reply->get_data()['id'],'Reply retry must have one effect.');
    $check($call('POST','/agency/v1/support-cases/'.$item['id'].'/messages',array('message'=>'Changed.'),$reply_key)->get_status()===409,'Changed reply retry must conflict.');
    $check($call('POST','/agency/v1/support-cases/'.$item['id'].'/messages',array('message'=>'Not internal.','internal_note'=>true),'reply_'.wp_generate_uuid4())->get_status()===403,'Customer cannot create internal agency notes.');
    $check($call('POST','/agency/v1/support-cases/'.$item['id'].'/triage',array('version'=>1,'state'=>'resolved','resolution_code'=>'answered'))->get_status()>=400,'Customer cannot triage own case.');
    wp_set_current_user($users[1]);
    $check($call('GET','/agency/v1/support-cases/'.$item['id'].'/messages')->get_status()===404,'Other customer cannot read shared thread.');
    wp_set_current_user($admin);
    $note=$call('POST','/agency/v1/support-cases/'.$item['id'].'/messages',array('message'=>'Fictional internal agency note.','internal_note'=>true),'note_'.wp_generate_uuid4());
    $check($note->get_status()===201,'Agency internal note should be recorded.');
    $staff_reply=$call('POST','/agency/v1/support-cases/'.$item['id'].'/messages',array('message'=>'Fictional shared agency answer.'),'staff_'.wp_generate_uuid4());
    $check($staff_reply->get_status()===201,'Agency shared answer should be recorded.');
    $triage=$call('POST','/agency/v1/support-cases/'.$item['id'].'/triage',array('version'=>1,'state'=>'in_progress','next_action'=>'Fictional supplier review.','assigned_user'=>$admin));
    $check($triage->get_status()===200 && $triage->get_data()['state']==='in_progress','Agency can assign and triage case.');
    $check($call('POST','/agency/v1/support-cases/'.$item['id'].'/triage',array('version'=>1,'state'=>'resolved','resolution_code'=>'answered'))->get_status()===409,'Stale triage must conflict.');
    $resolved=$call('POST','/agency/v1/support-cases/'.$item['id'].'/triage',array('version'=>2,'state'=>'resolved','resolution_code'=>'answered'));
    $check($resolved->get_status()===200,'Agency can resolve with a nonfinancial outcome code.');
    wp_set_current_user($users[0]);
    $thread=$call('GET','/agency/v1/support-cases/'.$item['id'].'/messages');
    $check($thread->get_status()===200 && count($thread->get_data()['messages'])===2 && !str_contains(wp_json_encode($thread->get_data()),'Fictional internal agency note.'),'Customer projection excludes internal notes.');
    $reopen=$call('POST','/agency/v1/support-cases/'.$item['id'].'/messages',array('message'=>'Fictional clarification after resolution.'),'reopen_'.wp_generate_uuid4());
    $reopened=$call('GET','/agency/v1/operations/support_case/'.$item['id']);
    $check($reopen->get_status()===201 && $reopened->get_data()['state']==='new','Customer clarification reopens resolved case.');
    $before=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Atlas_Support_Workflow::table().' WHERE case_id=%s',$item['id']));
    Atlas\Core\DB::set_test_audit_failure(true);
    try { $audit_failed=$call('POST','/agency/v1/support-cases/'.$item['id'].'/messages',array('message'=>'Must roll back.'),'fault_'.wp_generate_uuid4()); }
    finally { Atlas\Core\DB::set_test_audit_failure(false); }
    $after=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Atlas_Support_Workflow::table().' WHERE case_id=%s',$item['id']));
    $check($audit_failed->get_status()===503 && $before===$after,'Reply and audit must roll back atomically.');
    wp_set_current_user($admin);
    $wpdb->insert(Atlas\Core\DB::table('organizations'),array('name'=>'Fictional Guide Org','status'=>'active','created_at'=>current_time('mysql',true))); $org=(int)$wpdb->insert_id; $orgs[]=$org;
    $leader=get_userdata($users[0]); $leader->add_cap('atlas_manage_records'); $leader->add_cap('atlas_manage_offers');
    $wpdb->insert(Atlas\Core\DB::table('memberships'),array('org_id'=>$org,'user_id'=>$users[0],'role'=>'leader','status'=>'active','updated_at'=>current_time('mysql',true)));
    wp_set_current_user($users[0]);
    $offer=Atlas\Core\Offers::save(0,array('kind'=>'tour','org_id'=>$org,'title_en'=>'Fictional Route','title_fa'=>'مسیر فرضی','description_en'=>'Test route','description_fa'=>'مسیر آزمایشی','registration_open_at'=>'2027-06-01T00:00:00Z','registration_close_at'=>'2027-06-15T00:00:00Z','timezone'=>'UTC','capacity'=>5,'amount_irr'=>1000000,'mode'=>'local','status'=>'draft','product_id'=>0),$users[0]);
    $check(!is_wp_error($offer),'Leader should create draft offer.'); $offers[]=$offer;
    $version=array('org_id'=>$org,'offer_id'=>$offer,'title_en'=>'Fictional Route','stops'=>array(array('city'=>'London','country'=>'GB','timezone'=>'Europe/London','arrival'=>'2027-09-01T08:00:00Z','departure'=>'2027-09-01T14:00:00Z')),'days'=>array(array('date'=>'2027-09-01','items'=>array(array('type'=>'walk','description'=>'Fictional walk','start'=>'2027-09-01T09:00:00Z','end'=>'2027-09-01T10:00:00Z')))),'legs'=>array());
    $key='domain_test_'.str_replace('-','',wp_generate_uuid4()); $keys[]=$key;
    $tour=$call('POST','/agency/v1/operations/tour_version',$version,$key); $check($tour->get_status()<300,'Tour draft should be saved.'); $tv=$tour->get_data(); $records[]=$tv['id'];
    $bad_days=array(array('date'=>'2027-09-01','items'=>array(array('type'=>'walk','description'=>'Invalid replacement','start'=>'2027-09-01T11:00:00Z','end'=>'2027-09-01T10:00:00Z'))));
    $invalid_submit=$call('PATCH','/agency/v1/operations/tour_version/'.$tv['id'],array('version'=>1,'changes'=>array('days'=>$bad_days,'state'=>'submitted')));
    $check($invalid_submit->get_status()===422,'Merged edits must be validated before submitting an itinerary.');
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
    $order=wc_create_order(array('customer_id'=>$users[0])); $check(!is_wp_error($order),'Receipt order fixture should be created through Woo CRUD.'); $orders[]=$order->get_id();
    $line=new WC_Order_Item_Product();$line->set_name('Fictional event receipt');$line->set_quantity(1);$line->set_total('1000000');$line->add_meta_data('_atlas_booking_id',$hold['booking_id'],true);$order->add_item($line);$order->set_currency('IRR');$order->set_total('1000000');$order->set_status('processing');$order->save();
    $wpdb->update(Atlas\Core\DB::table('bookings'),array('order_id'=>$order->get_id()),array('id'=>$hold['booking_id']));
    $receipt=Atlas_Reservation_Status::notice($order->get_id());
    $check(str_contains($receipt,'data-atlas-confirmation-status="paid_pending_confirmation"') && str_contains($receipt,'awaiting agency confirmation'),'Woo paid receipt must explicitly preserve pending agency confirmation.');
    $_GET['lang']='fa';$fa_receipt=Atlas_Reservation_Status::notice($order->get_id());$check(str_contains($fa_receipt,'در انتظار تأیید آژانس'),'Receipt status has Persian copy.');unset($_GET['lang']);
    wp_set_current_user($users[1]);$check(Atlas_Reservation_Status::notice($order->get_id())==='','Another customer cannot inspect reservation receipt status.');
    wp_set_current_user($admin);
    $confirmation=Atlas\Core\Booking::confirm($hold['booking_id'],$admin,'Fictional fixture'); $check(!is_wp_error($confirmation),'Agency confirmation should succeed.');
    $check(str_contains(Atlas_Reservation_Status::notice($order->get_id()),'data-atlas-confirmation-status="confirmed"'),'Receipt uses actual independent agency confirmation state.');
    $check(has_action('woocommerce_before_thankyou',array('Atlas_Reservation_Status','thankyou'))!==false,'Receipt notice is hooked into installed Woo Blocks/classic shared hook.');
    // Finance exceptions remain verified payments, while manual review never claims a transfer.
    $finance_call=function($method,$route,$body=null,$key='', $nonce=true) {
        $r=new WP_REST_Request($method,$route);if ($nonce) $r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
        if ($body!==null) { $r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body)); }
        if ($key!=='') $r->set_header('Idempotency-Key',$key);return rest_do_request($r);
    };
    $excess=Atlas\Core\Booking::verify($hold['booking_id'],Atlas\Core\VerifiedPayment::from_adapter('local_simulator','finance-excess-test-'.wp_generate_uuid4(),1000000));
    $check(!is_wp_error($excess) && $excess['payment_exception']==='excess_payment' && $excess['status']==='confirmed','Distinct excess payment must remain visible without removing confirmed entitlement.');
    $payment_id=$excess['payment_id'];
    wp_set_current_user($users[0]);wp_get_current_user()->add_cap('atlas_manage_finance');
    $check($finance_call('GET','/agency/v1/finance/payment-exceptions')->get_status()===403 && Atlas_Finance_UI::panel()==='','Customer finance capability alone cannot access the agency queue or panel.');
    $refund_body=array('payment_id'=>$payment_id,'amount_irr'=>'400000','reason'=>'Fictional excess payment review.');
    $check($finance_call('POST','/agency/v1/finance/refunds',$refund_body,'forbidden_'.wp_generate_uuid4())->get_status()===403,'Customer cannot request a refund through agency finance controls.');
    wp_get_current_user()->remove_cap('atlas_manage_finance');wp_set_current_user($admin);
    $check($finance_call('GET','/agency/v1/finance/payment-exceptions',null,'',false)->get_status()===403,'Finance routes require an explicit valid REST nonce.');
    // Set query parameters directly because REST route dispatch does not parse a query in its route string.
    $queue_request=new WP_REST_Request('GET','/agency/v1/finance/payment-exceptions');$queue_request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$queue_request->set_query_params(array('limit'=>50));$queue=rest_do_request($queue_request);
    $queue_items=$queue->get_data()['items']??array();$found=array_values(array_filter($queue_items,fn($entry)=>$entry['payment_id']===$payment_id));
    $check($queue->get_status()===200 && count($found)===1 && $found[0]['amount_irr']==='1000000' && $found[0]['status']==='verified','Agency exception queue exposes exact string IRR and independent verified state.');
    $invalid_amount=$refund_body;$invalid_amount['amount_irr']=0.5;
    $check($finance_call('POST','/agency/v1/finance/refunds',$invalid_amount,'float_'.wp_generate_uuid4())->get_status()===422,'Fractional JSON refund amounts must be rejected.');
    $refund_key='refund_'.wp_generate_uuid4();$keys[]=$refund_key;
    $refund=$finance_call('POST','/agency/v1/finance/refunds',$refund_body,$refund_key);$refund_data=$refund->get_data();
    $check($refund->get_status()===200 && $refund_data['status']==='requested' && $refund_data['amount_irr']==='400000','Agency can record an exact partial refund request without transferring funds.');
    $refund_again=$finance_call('POST','/agency/v1/finance/refunds',$refund_body,$refund_key);
    $check($refund_again->get_status()===200 && $refund_again->get_data()['refund_id']===$refund_data['refund_id'],'Refund request retry has one effect.');
    $changed_refund=$refund_body;$changed_refund['amount_irr']='400001';
    $check($finance_call('POST','/agency/v1/finance/refunds',$changed_refund,$refund_key)->get_status()===409,'Changed refund payload cannot reuse request key.');
    $too_much=$refund_body;$too_much['amount_irr']='600001';
    $check($finance_call('POST','/agency/v1/finance/refunds',$too_much,'over_'.wp_generate_uuid4())->get_status()===409,'Reserved requests prevent refunds above the verified payment.');
    wp_set_current_user($users[0]);
    $check($finance_call('POST','/agency/v1/finance/refunds/'.$refund_data['refund_id'].'/approve',array('reason'=>'Forbidden.'),'forbidden_'.wp_generate_uuid4())->get_status()===403,'Customer cannot approve a recorded refund.');
    wp_set_current_user($admin);$approve_key='approve_'.wp_generate_uuid4();$keys[]=$approve_key;$approve_body=array('reason'=>'Fictional independent finance review.');
    $approval=$finance_call('POST','/agency/v1/finance/refunds/'.$refund_data['refund_id'].'/approve',$approve_body,$approve_key);
    $check($approval->get_status()===200 && $approval->get_data()['status']==='approved','Agency approval records approved, never provider completion.');
    $approval_again=$finance_call('POST','/agency/v1/finance/refunds/'.$refund_data['refund_id'].'/approve',$approve_body,$approve_key);
    $check($approval_again->get_status()===200 && $approval_again->get_data()===$approval->get_data(),'Approval retry returns its original result.');
    $check($finance_call('POST','/agency/v1/finance/refunds/'.$refund_data['refund_id'].'/approve',array('reason'=>'Changed review.'),$approve_key)->get_status()===409,'Approval key is bound to the exact review.');
    $second_refund=$refund_body;$second_refund['amount_irr']='300000';$second_key='refund_'.wp_generate_uuid4();$keys[]=$second_key;
    $second=$finance_call('POST','/agency/v1/finance/refunds',$second_refund,$second_key);$check($second->get_status()===200,'A separate partial refund can reserve the remaining payment.');$second_id=$second->get_data()['refund_id'];
    $check($finance_call('POST','/agency/v1/finance/refunds/'.$second_id.'/approve',$approve_body,$approve_key)->get_status()===409 && $wpdb->get_var($wpdb->prepare('SELECT status FROM '.Atlas\Core\DB::table('refunds').' WHERE id=%s',$second_id))==='requested','Reused approval key must not approve another refund object.');
    $ledger=$finance_call('GET','/agency/v1/finance/payments/'.$payment_id.'/ledger');$ledger_items=$ledger->get_data()['items']??array();
    $check($ledger->get_status()===200 && count($ledger_items)===1 && $ledger_items[0]['event_type']==='payment_verified','Manual request and approval must not create a verified refund debit.');
    $check($finance_call('POST','/agency/v1/finance/refunds/'.$refund_data['refund_id'].'/verify',array('reference'=>'fictional'))->get_status()===404,'There is no public refund-proof route.');
    $approval_events=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Atlas\Core\DB::table('outbox').' WHERE event_type=%s AND aggregate_id=%s','refund.approved',$refund_data['refund_id']));
    $check($approval_events===1,'Approval replay emits exactly one approval event.');
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
    // Deadline checks apply to a NEW enrollment link, but not to an existing paid learner's material access.
    $key='domain_test_'.str_replace('-','',wp_generate_uuid4()); $keys[]=$key;
    $late_seat=Atlas\Core\Booking::hold($coffer,(int)$offer_data['version'],1,$users[0],$key,array()); $check(!is_wp_error($late_seat),'Second cohort fixture hold should succeed.'); $bookings[]=$late_seat['booking_id']; $holds[]=$late_seat['hold_id'];
    Atlas\Core\DB::set_test_now((new DateTimeImmutable($late_seat['expires_at']))->modify('+1 second')->format('Y-m-d\TH:i:s.u\Z'));
    $late_enroll=$call('POST','/agency/v1/cohorts/'.$ch['id'].'/enrollments',array('booking_id'=>$late_seat['booking_id'],'learner_id'=>$users[0]));
    $check($late_enroll->get_status()===409,'An elapsed but unreleased unpaid hold cannot create a new enrollment.');
    $released=Atlas\Core\Booking::expire($late_seat['hold_id']); $check(!is_wp_error($released),'Elapsed second fixture hold should release.');
    $check($call('POST','/agency/v1/cohorts/'.$ch['id'].'/enrollments',array('booking_id'=>$late_seat['booking_id'],'learner_id'=>$users[0]))->get_status()===409,'Released allocation cannot create enrollment.');
    wp_set_current_user($users[1]);
    $late_retry=$call('POST','/agency/v1/cohorts/'.$ch['id'].'/enrollments',array('booking_id'=>$seat['booking_id'],'learner_id'=>$users[1]));
    $check($late_retry->get_status()===200 && $late_retry->get_data()['id']===$en['id'],'Existing confirmed enrollment retry remains idempotent after another hold expires.');
    Atlas\Core\DB::set_test_now((new DateTimeImmutable($offer_data['registration_close_at']))->modify('+1 day')->format('Y-m-d\TH:i:s.u\Z'));
    $check($call('GET','/agency/v1/cohorts/'.$ch['id'].'/materials')->get_status()===200,'Registration close must not remove confirmed learner material access.');
    Atlas\Core\DB::set_test_now(null);
    // An application approval creates exactly one active organization and membership.
    $key='domain_test_'.str_replace('-','',wp_generate_uuid4());$keys[]=$key;
    $application=$call('POST','/agency/v1/operations/partner_application',array('organization_name'=>'Fictional property applicant','contact'=>'partner@example.invalid','partner_types'=>array('accommodation')),$key);
    $check($application->get_status()===200,'Partner application should save.');$app=$application->get_data();$records[]=$app['id'];
    $submitted=$call('PATCH','/agency/v1/operations/partner_application/'.$app['id'],array('version'=>1,'changes'=>array('state'=>'submitted')));$check($submitted->get_status()===200,'Applicant should submit its own application.');
    $review_payload=array('version'=>2,'decision'=>'approved','evidence_reference'=>'fictional-review-001','initial_role'=>'contributor','reason'=>'Fictional reviewed fixture only.');
    $check($call('POST','/agency/v1/partner-applications/'.$app['id'].'/review',$review_payload)->get_status()>=400,'Applicant cannot approve onboarding.');
    wp_set_current_user($admin);$review=$call('POST','/agency/v1/partner-applications/'.$app['id'].'/review',$review_payload);
    $check($review->get_status()===200,'Independent agency review should activate approved organization.');$approved_app=$review->get_data();$new_org=(int)$approved_app['fields']['organization_id'];$orgs[]=$new_org;
    $repeat=$call('POST','/agency/v1/partner-applications/'.$app['id'].'/review',$review_payload);
    $check($repeat->get_status()===200 && (int)$repeat->get_data()['fields']['organization_id']===$new_org,'Review retry must not create another organization.');
    $member_count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Atlas\Core\DB::table('memberships').' WHERE org_id=%d AND user_id=%d',$new_org,$users[1]));$check($member_count===1,'Approval creates one scoped applicant membership.');
    $membership_key='membership_'.wp_generate_uuid4();$keys[]=$membership_key;
    $member_payload=array('user_id'=>$users[0],'role'=>'viewer','status'=>'active','expected_updated_at'=>'','reason'=>'Fictional staff invitation accepted.');
    $add_member=$call('POST','/agency/v1/organizations/'.$new_org.'/members',$member_payload,$membership_key);
    $check($add_member->get_status()===200,'Agency can add a known viewer without admin powers.');
    wp_set_current_user($users[0]);$check(Atlas\Core\Auth::can('atlas_manage_records',$new_org,null,'read') && !Atlas\Core\Auth::can('atlas_manage_records',$new_org,null,'write'),'Viewer role cannot inherit write rights from a different organization.');
    $check($call('POST','/agency/v1/organizations/'.$new_org.'/members',$member_payload,'unauthorized_'.wp_generate_uuid4())->get_status()>=400,'Partner cannot grant membership through agency service.');
    wp_set_current_user($admin);$revoke=$member_payload;$revoke['expected_updated_at']=$add_member->get_data()['updated_at'];$revoke['status']='revoked';$revoke['reason']='Fictional access termination.';$rev_key='revoke_'.wp_generate_uuid4();$keys[]=$rev_key;
    $check($call('POST','/agency/v1/organizations/'.$new_org.'/members',$revoke,$rev_key)->get_status()===200,'Agency can revoke membership with the current revision.');
    wp_set_current_user($users[0]);$check(!Atlas\Core\Auth::can('atlas_manage_records',$new_org,null,'read'),'Revocation immediately removes viewer access.');
    // Three invited suppliers submit confidential bids; closed deadlines/expired quotes fail closed.
    $third_user=wp_insert_user(array('user_login'=>'atlas-maker-'.wp_generate_password(12,false),'user_pass'=>wp_generate_password(30),'role'=>'subscriber'));$check(!is_wp_error($third_user),'Third supplier fixture should exist.');$users[]=$third_user;
    $maker=get_userdata($third_user);$maker->add_cap('atlas_manage_records');
    $wpdb->insert(Atlas\Core\DB::table('organizations'),array('name'=>'Fictional third manufacturer','status'=>'active','created_at'=>Atlas\Core\DB::now()));$third_org=(int)$wpdb->insert_id;$orgs[]=$third_org;
    $wpdb->insert(Atlas\Core\DB::table('memberships'),array('org_id'=>$third_org,'user_id'=>$third_user,'role'=>'contributor','status'=>'active','updated_at'=>Atlas\Core\DB::now()));
    wp_set_current_user($admin);$rfq_key='rfq_'.wp_generate_uuid4();$keys[]=$rfq_key;
    $rfq=$call('POST','/agency/v1/rfqs',array('title'=>'Fictional 500 conference gifts','requirements'=>'Fictional art v3 and sample inspection.','quantity'=>500,'deadline'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600),'recipients'=>array($org,$new_org,$third_org)),$rfq_key);
    $check($rfq->get_status()===200,'Agency should create RFQ with explicit recipients and deadline.');$rf=$rfq->get_data();$records[]=$rf['id'];
    $check($call('PATCH','/agency/v1/operations/rfq/'.$rf['id'],array('version'=>1,'changes'=>array('state'=>'submitted')))->get_status()===200,'Complete RFQ should open for bids.');
    $bids=array();foreach (array(array($users[0],$org,310000000),array($users[1],$new_org,325000000),array($third_user,$third_org,320000000)) as $supplier) {
        wp_set_current_user($supplier[0]);$bid_key='bid_'.wp_generate_uuid4();$keys[]=$bid_key;
        $bid=$call('POST','/agency/v1/operations/supplier_quote',array('org_id'=>$supplier[1],'rfq_id'=>$rf['id'],'amount_irr'=>$supplier[2],'expiry'=>gmdate('Y-m-d\TH:i:s\Z',time()+7200),'terms'=>'Fictional sample and shipping terms.'),$bid_key);
        $check($bid->get_status()===200,'Invited supplier should save private bid.');$b=$bid->get_data();$records[]=$b['id'];$bids[]=$b;
        $check($call('PATCH','/agency/v1/operations/supplier_quote/'.$b['id'],array('version'=>1,'changes'=>array('state'=>'submitted')))->get_status()===200,'Timely bid should submit.');
    }
    wp_set_current_user($users[0]);$check($call('GET','/agency/v1/operations/supplier_quote/'.$bids[1]['id'])->get_status()===404,'Manufacturer must not read competing bid.');
    $expired_key='expired_'.wp_generate_uuid4();$keys[]=$expired_key;
    $expired=$call('POST','/agency/v1/operations/supplier_quote',array('org_id'=>$org,'rfq_id'=>$rf['id'],'amount_irr'=>1,'expiry'=>'2020-01-01T00:00:00Z'),$expired_key);$check($expired->get_status()===422,'Expired quote must not be accepted.');
    Atlas\Core\DB::set_test_now(gmdate('Y-m-d\TH:i:s\Z',time()+3601));$late_key='latebid_'.wp_generate_uuid4();$keys[]=$late_key;
    $late_bid=$call('POST','/agency/v1/operations/supplier_quote',array('org_id'=>$org,'rfq_id'=>$rf['id'],'amount_irr'=>1,'expiry'=>gmdate('Y-m-d\TH:i:s\Z',time()+7200)),$late_key);$check($late_bid->get_status()===422,'Closed RFQ deadline must prevent a fresh bid.');
    wp_set_current_user($admin);$award=$call('PATCH','/agency/v1/operations/supplier_quote/'.$bids[0]['id'],array('version'=>2,'changes'=>array('state'=>'approved')));$check($award->get_status()===200,'Agency may award a still-valid submitted quote after bid deadline.');
    Atlas\Core\DB::set_test_now(gmdate('Y-m-d\TH:i:s\Z',time()+7201));$expired_award=$call('PATCH','/agency/v1/operations/supplier_quote/'.$bids[1]['id'],array('version'=>2,'changes'=>array('state'=>'approved')));$check($expired_award->get_status()===422,'Expired quote cannot create a fresh award.');Atlas\Core\DB::set_test_now(null);
    wp_set_current_user(0);$guest_account=Atlas_Operations::account();$check(str_contains($guest_account,'woocommerce-login-nonce') && str_contains($guest_account,'name="password"'),'Guest account uses native Woo login, not a custom password handler.');
    $_REQUEST['atlas_return']='https://not-the-local-site.example.invalid/?offer_id='.$event;
    $safe_redirect=Atlas_Operations::auth_redirect('https://not-the-local-site.example.invalid/');
    $check(wp_parse_url($safe_redirect,PHP_URL_HOST)===wp_parse_url(home_url('/'),PHP_URL_HOST),'Native authentication return rejects external origins.');
    $_REQUEST['atlas_return']=add_query_arg('offer_id',$event,home_url('/'));$return=Atlas_Operations::auth_redirect(home_url('/'));parse_str(wp_parse_url($return,PHP_URL_QUERY),$return_query);$check((int)($return_query['offer_id']??0)===$event,'Native authentication preserves the selected published offer intent.');unset($_REQUEST['atlas_return']);
    WP_CLI::log('Atlas domain checks: '.$checks.' passed, 0 failed. Support, transfer, itinerary, check-in, enrollment, partner, finance and ACL workflows covered.');
} finally {
    global $wpdb;
    Atlas\Core\DB::set_test_now(null); Atlas\Core\DB::set_test_audit_failure(false);
    foreach ($orders as $id) { $order=wc_get_order($id); if ($order) $order->delete(true); }
    foreach ($bookings as $id) { $wpdb->delete($wpdb->prefix.'atlas_event_checkins',array('booking_id'=>$id)); $payments=$wpdb->get_col($wpdb->prepare('SELECT id FROM '.Atlas\Core\DB::table('payments').' WHERE booking_id=%s',$id));foreach ($payments as $payment_id) { $wpdb->delete(Atlas\Core\DB::table('refunds'),array('payment_id'=>$payment_id));$wpdb->delete(Atlas\Core\DB::table('ledger'),array('payment_id'=>$payment_id)); }$wpdb->delete(Atlas\Core\DB::table('payments'),array('booking_id'=>$id)); $wpdb->delete(Atlas\Core\DB::table('bookings'),array('id'=>$id)); }
    foreach ($holds as $id) $wpdb->delete(Atlas\Core\DB::table('holds'),array('id'=>$id));
    foreach ($offers as $id) { $wpdb->delete(Atlas\Core\DB::table('inventory'),array('offer_id'=>$id)); wp_delete_post($id,true); }
    foreach ($orgs as $id) { $wpdb->delete(Atlas\Core\DB::table('memberships'),array('org_id'=>$id)); $wpdb->delete(Atlas\Core\DB::table('organizations'),array('id'=>$id)); }
    foreach ($records as $id) { $wpdb->delete(Atlas_Support_Workflow::table(),array('case_id'=>$id)); $wpdb->delete(Atlas\Core\DB::table('records'),array('id'=>$id)); }
    foreach ($keys as $key) $wpdb->delete(Atlas\Core\DB::table('idempotency'),array('idem_key'=>$key));
    foreach ($users as $id) wp_delete_user($id);
    wp_set_current_user(0);
}
