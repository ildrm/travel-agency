<?php
/** Disposable local fixtures for the public dated-resource bridge, not provider transactions. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='local' || !class_exists('Atlas_Resource_Booking')) throw new RuntimeException('Local activated resource bridge only.');
if (!defined('ATLAS_TEST_CLOCK')) define('ATLAS_TEST_CLOCK',true);
require_once ABSPATH.'wp-admin/includes/user.php';
$users=array(); $orgs=array(); $records=array(); $resources=array(); $offers=array(); $products=array(); $bookings=array(); $holds=array(); $keys=array(); $checks=0;
$check=static function($condition,$message) use(&$checks) { $checks++; if (!$condition) throw new RuntimeException($message); };
$call=static function($method,$path,$body=null,$query=array()) { $r=new WP_REST_Request($method,'/agency/v1/'.$path); if ($body!==null) { $r->set_header('content-type','application/json'); $r->set_body(wp_json_encode($body)); } $r->set_query_params($query); return rest_do_request($r); };
$user=static function($role,$caps=array()) use(&$users) { $id=wp_insert_user(array('user_login'=>'resource-fixture-'.wp_generate_password(14,false),'user_pass'=>wp_generate_password(30),'role'=>$role)); if (is_wp_error($id)) throw new RuntimeException('User fixture'); $users[]=$id; foreach ($caps as $cap) get_userdata($id)->add_cap($cap); return $id; };
$record=static function($type,$org,$owner,$fields) use(&$records) { global $wpdb; $id=wp_generate_uuid4(); if (!$wpdb->insert(Atlas\Core\DB::table('records'),array('id'=>$id,'type'=>$type,'org_id'=>$org,'owner_id'=>$owner,'state'=>'approved','payload'=>wp_json_encode($fields),'version'=>1,'created_at'=>Atlas\Core\DB::now(),'updated_at'=>Atlas\Core\DB::now()))) throw new RuntimeException('Record fixture'); $records[]=$id; return $id; };
$hold=static function($offer,$actor,$quantity,$options,$key=null) use(&$keys,&$bookings,&$holds) { $key=$key?:'resource_'.wp_generate_uuid4(); $keys[]=$key; $data=Atlas\Core\Offers::get($offer,true); $result=Atlas\Core\Booking::hold($offer,(int)$data['version'],$quantity,$actor,$key,array(),$options); if (!is_wp_error($result)) { $bookings[]=$result['booking_id']; $holds[]=$result['hold_id']; } return $result; };
$mapping=static function($id) { global $wpdb; $r=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Atlas\Core\DB::table('records').' WHERE type=%s AND id=%s','resource_reservation',$id)); return $r ? array('version'=>(int)$r->version,'state'=>$r->state,'fields'=>json_decode($r->payload,true)) : null; };
try {
    global $wpdb;
    $admin=$user('administrator',array('atlas_manage_bookings','atlas_manage_records','atlas_manage_offers'));
    $supplier=$user('subscriber',array('atlas_manage_records','atlas_manage_offers')); $outsider=$user('subscriber',array('atlas_manage_records'));
    $customer=$user('subscriber'); $second=$user('subscriber');
    foreach (array('Fictional reviewed property operator','Fictional unrelated supplier') as $name) { $wpdb->insert(Atlas\Core\DB::table('organizations'),array('name'=>$name,'status'=>'active','created_at'=>Atlas\Core\DB::now())); $orgs[]=(int)$wpdb->insert_id; }
    $org=$orgs[0];
    foreach (array(array($org,$supplier),array($orgs[1],$outsider)) as $m) $wpdb->insert(Atlas\Core\DB::table('memberships'),array('org_id'=>$m[0],'user_id'=>$m[1],'role'=>'contributor','status'=>'active','updated_at'=>Atlas\Core\DB::now()));
    Atlas\Core\DB::set_test_now('2032-05-01T08:00:00Z');
    $property=$record('property',$org,$supplier,array('name_en'=>'Fictional hotel','timezone'=>'Asia/Tehran'));
    $unit=$record('unit_type',$org,$supplier,array('property_id'=>$property,'name'=>'Fictional standard','capacity'=>2,'units'=>2,'whole_property'=>false));
    $villa_property=$record('property',$org,$supplier,array('name_en'=>'Fictional whole villa','timezone'=>'Asia/Tehran'));
    $villa=$record('unit_type',$org,$supplier,array('property_id'=>$villa_property,'name'=>'Fictional entire villa','capacity'=>6,'units'=>1,'whole_property'=>true));
    $vehicle=$record('vehicle',$org,$supplier,array('category'=>'car','label'=>'Fictional reviewed car','capacity'=>4,'license_class'=>'B','blocked_intervals'=>array()));
    wp_set_current_user($supplier);
    foreach (array(array('unit_type',$unit),array('unit_type',$villa),array('vehicle',$vehicle)) as $item) { $r=Atlas\Inventory\Local::register_resource($item[0],$item[1],1,60); $check(!is_wp_error($r),'Resource fixture should register.'); $resources[]=$r['id']; }
    wp_set_current_user($admin); $check(!is_wp_error(Atlas\Inventory\Local::configure_rental_policy($resources[2],array('policy_reference'=>'fictional-rental-policy-v1','minimum_age'=>25,'coverage_reference'=>'fictional-coverage-v1','valid_until'=>'2033-01-01T00:00:00Z'))),'A fictional explicit age/coverage policy should be reviewed; no legal threshold is inferred.');
    $token='resourcefixture'.str_replace('-','',wp_generate_uuid4());
    foreach (array('stay','stay','rental') as $index=>$kind) {
        wp_set_current_user($supplier);
        $id=Atlas\Core\Offers::save(0,array('kind'=>$kind,'org_id'=>$org,'title_en'=>$token.' '.$kind.' '.$index,'title_fa'=>'پیشنهاد فرضی','registration_open_at'=>'2032-05-01T00:00:00Z','registration_close_at'=>'2032-06-01T00:00:00Z','timezone'=>'Asia/Tehran','capacity'=>0,'amount_irr'=>0,'mode'=>'request_only','status'=>'draft','product_id'=>0),$supplier);
        $check(!is_wp_error($id),'Request-only offer should exist before reviewed inventory is linked.'); $offers[]=$id;
        wp_set_current_user($admin);
        $unreviewed=Atlas\Core\Offers::save($id,array('expected_version'=>1,'mode'=>'local','status'=>'published','catalog'=>array('price_unit'=>$kind==='stay'?'per_night':'per_item')),$admin);
        $check(is_wp_error($unreviewed),'Unreviewed resource offers cannot enter paid booking mode.');
        $rate=array('version'=>0,'resource_id'=>$resources[$index],'start_date'=>'2032-05-01','end_date'=>'2032-06-01','timezone'=>'Asia/Tehran','valid_until'=>'2032-06-30T00:00:00Z','unit_amount_irr'=>$kind==='stay'?8000000:5000000,'tax_per_unit_irr'=>100000,'protection_irr'=>$kind==='rental'?2000000:0,'min_duration'=>1,'max_duration'=>30,'terms_en'=>'Fictional reviewed terms. Supplier acceptance is separate. No secured deposit.','terms_fa'=>'شرایط فرضی بازبینی‌شده. پذیرش تأمین‌کننده جداست. ودیعه تضمین نشده است.','policy_reference'=>'fictional-reviewed-terms-v1','supplier_reference'=>'fictional-private-allotment-v1','supplier_users'=>array($supplier),'published'=>true,'reason'=>'Fictional agency allotment and policy review.');
        $saved=$call('POST','resource-offers/'.$id,$rate); $check($saved->get_status()===200,'Reviewed rate should save: '.wp_json_encode($saved->get_data())); $records[]=$saved->get_data()['id'];
        $product=new WC_Product_Simple(); $product->set_name('Fictional resource checkout '.$index); $product->set_virtual(true); $product->set_regular_price('1'); $product->set_status('publish'); $product_id=$product->save(); $products[]=$product_id;
        $enabled=$call('POST','resource-offers/'.$id.'/activate',array('version'=>1,'product_id'=>$product_id)); $check($enabled->get_status()===200,'Independent review should enable the linked public resource offer: '.wp_json_encode($enabled->get_data()));
    }
    wp_set_current_user(0);
    $public=$call('GET','resource-offers/'.$offers[0]); $check($public->get_status()===200 && !isset($public->get_data()['supplier_reference'],$public->get_data()['supplier_users'],$public->get_data()['resource_id']),'Public rate contains reviewed customer terms, not private supplier evidence or assignments.');
    $dates=array('check_in'=>'2032-05-10','check_out'=>'2032-05-13');
    $missing=$call('GET','resource-offers/'.$offers[0].'/availability'); $check($missing->get_status()===200 && $missing->get_data()['status']==='dates_required' && $missing->get_data()['remaining']===null,'Undated resources never claim availability from a global seat count.');
    $available=$call('GET','resource-offers/'.$offers[0].'/availability',null,$dates); $check($available->get_status()===200 && $available->get_data()['remaining']===2 && $available->get_data()['amount_per_unit_irr']===24100000,'Three local nights produce the reviewed base plus explicit tax.');
    $catalog=Atlas\Core\Catalog::search(array('kind'=>'stay','q'=>$token,'availability'=>'open','check_in'=>$dates['check_in'],'check_out'=>$dates['check_out'],'limit'=>1));
    $check(!is_wp_error($catalog) && $catalog['total']===2 && count($catalog['items'])===1,'Dated availability filtering counts before pagination, including zero-global-seat offers.');
    wp_set_current_user($customer); $options=$dates+array('rate_version'=>'1','guests_per_unit'=>'2','terms_accepted'=>true); $key='resource_'.wp_generate_uuid4();
    $bad=$options; $bad['terms_accepted']=false; $check(is_wp_error($hold($offers[0],$customer,1,$bad)),'Unchecked terms cannot create a paid resource hold.');
    $bad=$options; $bad['guests_per_unit']='3'; $check(is_wp_error($hold($offers[0],$customer,1,$bad)),'Occupancy above the reviewed room capacity is rejected.');
    $bad=$options; $bad['rate_version']='0'; $check(is_wp_error($hold($offers[0],$customer,1,$bad)),'A stale dated-rate version cannot be accepted.');
    $audit_dates=$options; $audit_dates['check_in']='2032-05-18'; $audit_dates['check_out']='2032-05-19'; Atlas\Core\DB::set_test_audit_failure(true);
    $check(is_wp_error($hold($offers[0],$customer,1,$audit_dates)),'Audit failure rolls back the Core booking and all additional resource allocation writes.'); Atlas\Core\DB::set_test_audit_failure(false);
    $check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->prefix.'atlas_local_nights WHERE resource_id=%s AND local_date=%s',$resources[0],'2032-05-18'))===0,'Failed audited hold does not leave a phantom room-night allocation.');
    $first=$hold($offers[0],$customer,1,$options,$key); $check(!is_wp_error($first) && $first['quote']['amount']===24100000,'Public stay atomically holds every night at the reviewed exact total.');
    $first_map=$mapping($first['booking_id']);
    $check((int)$wpdb->get_var($wpdb->prepare('SELECT primary_quantity FROM '.Atlas\Core\DB::table('holds').' WHERE id=%s',$first['hold_id']))===0 && (int)$wpdb->get_var($wpdb->prepare('SELECT allocated FROM '.Atlas\Core\DB::table('inventory').' WHERE offer_id=%d',$offers[0]))===0,'Dated capacity is authoritative; accepted stays do not permanently consume a global counter.');
    $retry=$hold($offers[0],$customer,1,$options,$key); $check(!is_wp_error($retry) && $retry['booking_id']===$first['booking_id'],'Identical hold retry returns the same booking and room-night allocation.');
    $check(is_wp_error($hold($offers[0],$customer,2,$options,$key)),'A conflicting idempotency retry cannot change the room count.');
    $check(is_wp_error($hold($offers[0],$customer,2,$options)),'Insufficient room capacity rejects the whole requested quantity.');
    $check(array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT allocated FROM '.$wpdb->prefix.'atlas_local_nights WHERE resource_id=%s AND local_date>=%s AND local_date<%s ORDER BY local_date',$resources[0],$dates['check_in'],$dates['check_out'])))===array(1,1,1),'Rejected over-capacity hold leaves all original nights unchanged.');
    wp_set_current_user($second); $contender=$hold($offers[0],$second,1,$options); $check(!is_wp_error($contender),'A second customer can hold the last remaining room.');
    $check(is_wp_error($hold($offers[0],$second,1,$options)),'The next contender cannot oversell the last room.');
    $catalog=Atlas\Core\Catalog::search(array('kind'=>'stay','q'=>$token,'availability'=>'open','check_in'=>$dates['check_in'],'check_out'=>$dates['check_out'],'limit'=>1)); $check(!is_wp_error($catalog) && $catalog['total']===1,'A sold-out room type is excluded before available-only result counts are calculated.');
    $villa_options=$options; $villa_options['guests_per_unit']='6'; $villa_hold=$hold($offers[1],$second,1,$villa_options); $check(!is_wp_error($villa_hold),'A whole villa can be held once as one resource.');
    $check(is_wp_error($hold($offers[1],$second,1,$villa_options)),'A whole villa cannot overlap another customer allocation.');
    $wpdb->insert($wpdb->prefix.'atlas_local_nights',array('resource_id'=>$resources[0],'local_date'=>'2032-05-16','allocated'=>0,'blocked'=>1));
    $blocked=$options; $blocked['check_in']='2032-05-14'; $blocked['check_out']='2032-05-17';
    $check(is_wp_error($hold($offers[0],$second,1,$blocked)),'One unavailable night rolls back the entire public multi-night hold.');
    $check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->prefix.'atlas_local_nights WHERE resource_id=%s AND local_date IN (%s,%s)',$resources[0],'2032-05-14','2032-05-15'))===0,'Earlier newly-created night rows roll back when a later night is blocked.');
    wp_set_current_user($admin); $check(is_wp_error(Atlas\Inventory\Local::release($first_map['fields']['allocation_id'])),'Generic inventory release cannot invalidate a booking-linked allocation.');
    wp_set_current_user($customer); $adjacent=$options; $adjacent['check_in']='2032-05-13'; $adjacent['check_out']='2032-05-14'; $next=$hold($offers[0],$customer,1,$adjacent); $check(!is_wp_error($next),'Checkout date is exclusive and permits an adjacent stay.');
    $check(!is_wp_error(Atlas\Core\Booking::verify($first['booking_id'],Atlas\Core\VerifiedPayment::from_adapter('local_simulator','resource-proof-'.wp_generate_uuid4(),24100000))),'Fictional independent payment consumes the public stay allocation.');
    wp_set_current_user($admin); $check(is_wp_error(Atlas\Core\Booking::confirm($first['booking_id'],$admin,'Fictional premature confirmation')),'Payment alone cannot bypass separate supplier acceptance.');
    wp_set_current_user($outsider); $check(!$call('GET','resource-bookings')->get_data()['items'],'Unrelated supplier has no customer reservation access.');
    $decision=array('version'=>2,'accepted'=>true,'evidence_reference'=>'fictional-supplier-confirm-01','reason'=>'Fictional supplier allotment accepted.');
    $check($call('POST','resource-bookings/'.$first['booking_id'].'/supplier-review',$decision)->get_status()===404,'An unrelated supplier cannot accept another organization booking.');
    wp_set_current_user($customer); $own_queue=$call('GET','resource-bookings')->get_data()['items']; $own=null; foreach ($own_queue as $item) if ($item['id']===$first['booking_id']) $own=$item;
    $check($own && !isset($own['fields']['supplier_users'],$own['fields']['quote']['policy_reference']),'Customer reservation projection omits internal supplier assignments and review references.');
    $check($call('POST','resource-bookings/'.$first['booking_id'].'/supplier-review',$decision)->get_status()===404,'Customer cannot record their own supplier acceptance.');
    // The configured writer may fail before or after an operation's work.
    // None of the review paths can acknowledge changes if its transaction fails.
    $transaction_fault=static function($statement,$path,$body) use($call) {
        $filter=static fn($sql)=>$sql===$statement?'SELECT id FROM atlas_failed_resource_transaction_fixture':$sql;
        add_filter('query',$filter);
        try { return $call('POST',$path,$body); }
        finally { remove_filter('query',$filter); }
    };
    wp_set_current_user($supplier);
    $failure=$transaction_fault('START TRANSACTION','resource-bookings/'.$first['booking_id'].'/supplier-review',$decision);
    $check($failure->get_status()===503 && $mapping($first['booking_id'])['fields']['supplier_status']==='pending',
        'Supplier review cannot change availability when its transaction cannot begin.');
    wp_set_current_user($supplier); $check($call('POST','resource-bookings/'.$first['booking_id'].'/supplier-review',$decision)->get_status()===200,'Only explicitly assigned active supplier can accept the held local allotment.');
    wp_set_current_user($admin); $confirmation=Atlas\Core\Booking::confirm($first['booking_id'],$admin,'Fictional supplier and agency confirmation'); $check(!is_wp_error($confirmation),'Agency may confirm after verified payment and recorded supplier acceptance: '.wp_json_encode($confirmation));
    $check(!is_wp_error(Atlas\Core\Booking::verify($contender['booking_id'],Atlas\Core\VerifiedPayment::from_adapter('local_simulator','resource-proof-'.wp_generate_uuid4(),24100000))),'Second fictional payment can be verified independently.');
    $reject=array('version'=>2,'accepted'=>false,'evidence_reference'=>'fictional-supplier-rejection-01','reason'=>'Fictional supplier cannot honor the requested stay.');
    $check($call('POST','resource-bookings/'.$contender['booking_id'].'/supplier-review',$reject)->get_status()===200,'Supplier rejection is recorded and routes paid booking to manual exception review.');
    $check($wpdb->get_var($wpdb->prepare('SELECT status FROM '.Atlas\Core\DB::table('bookings').' WHERE id=%s',$contender['booking_id']))==='exception' && $mapping($contender['booking_id'])['state']==='consumed','Supplier failure preserves verified money and retained capacity; no false refund or silent release.');
    // Customer consent and agency review release only this reservation's exact dated allocation.
    wp_set_current_user($outsider); $check($call('POST','resource-bookings/'.$first['booking_id'].'/cancel-request',array('version'=>0,'reason'=>'Fictional unauthorized cancellation.'))->get_status()===404,'Another customer or supplier cannot request cancellation of this booking.');
    wp_set_current_user($customer); $request_body=array('version'=>0,'reason'=>'Private fictional customer cancellation reason.');
    $failure=$transaction_fault('START TRANSACTION','resource-bookings/'.$first['booking_id'].'/cancel-request',$request_body);
    $check($failure->get_status()===503 && $mapping($first['booking_id'])['state']==='consumed',
        'A failed cancellation-request transaction preserves the paid room allocation.');
    $requested=$call('POST','resource-bookings/'.$first['booking_id'].'/cancel-request',$request_body); $check($requested->get_status()===200 && $requested->get_data()['state']==='requested','Customer can request cancellation without immediately releasing or reversing payment.');
    $check($call('POST','resource-bookings/'.$first['booking_id'].'/cancel-request',$request_body)->get_data()['version']===1,'An identical cancellation request retry does not create another version or record.');
    wp_set_current_user($supplier); $supplier_queue=$call('GET','resource-bookings')->get_data()['items']; $supplier_item=null; foreach ($supplier_queue as $item) if ($item['id']===$first['booking_id']) $supplier_item=$item;
    $check($supplier_item && !isset($supplier_item['cancellation']['fields']),'Assigned supplier sees cancellation status but not the customer private reason or agency evidence.');
    $cancel_review=array('version'=>1,'approved'=>true,'terms_reviewed'=>true,'policy_reference'=>'fictional-reviewed-terms-v1','evidence_reference'=>str_repeat('e',127),'reason'=>str_repeat('ب',95));
    $check($call('POST','resource-bookings/'.$first['booking_id'].'/cancel-review',$cancel_review)->get_status()===403,'Supplier assignment does not confer agency cancellation or refund-review powers.');
    wp_set_current_user($admin); $unchecked=$cancel_review; $unchecked['terms_reviewed']=false;
    $check($call('POST','resource-bookings/'.$first['booking_id'].'/cancel-review',$unchecked)->get_status()===422,'Agency cannot bypass explicit review of the accepted cancellation terms.');
    $failure=$transaction_fault('START TRANSACTION','resource-bookings/'.$first['booking_id'].'/cancel-review',$cancel_review);
    $check($failure->get_status()===503 && $mapping($first['booking_id'])['state']==='consumed',
        'A failed cancellation-review transaction preserves the paid room allocation.');
    Atlas\Core\DB::set_test_audit_failure(true); $cancel_failed=$call('POST','resource-bookings/'.$first['booking_id'].'/cancel-review',$cancel_review); Atlas\Core\DB::set_test_audit_failure(false);
    $check($cancel_failed->get_status()===503 && $mapping($first['booking_id'])['state']==='consumed' && $wpdb->get_var($wpdb->prepare('SELECT status FROM '.Atlas\Core\DB::table('bookings').' WHERE id=%s',$first['booking_id']))==='confirmed','Cancellation audit failure rolls back Core status and additional allocation changes together.');
    $check(array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT allocated FROM '.$wpdb->prefix.'atlas_local_nights WHERE resource_id=%s AND local_date>=%s AND local_date<%s ORDER BY local_date',$resources[0],$dates['check_in'],$dates['check_out'])))===array(2,2,2),'Failed cancellation restores every room night, including quantities shared with another customer.');
    $canceled=$call('POST','resource-bookings/'.$first['booking_id'].'/cancel-review',$cancel_review); $check($canceled->get_status()===200 && $canceled->get_data()['state']==='approved' && $canceled->get_data()['fields']['refund_review_required']===true,'Approved paid cancellation explicitly requires manual refund review rather than asserting a refund.');
    $summary=$wpdb->get_var($wpdb->prepare('SELECT reason FROM '.Atlas\Core\DB::table('audit').' WHERE action=%s AND object_id=%s ORDER BY id DESC LIMIT 1','booking.resource_cancel',$first['booking_id']));
    $check(is_string($summary) && strlen($summary)<=190 && preg_match('//u',$summary)===1 && str_starts_with($summary,$cancel_review['evidence_reference']),'A bounded audit summary preserves a long Persian review and evidence reference without splitting characters.');
    $check($wpdb->get_var($wpdb->prepare('SELECT status FROM '.Atlas\Core\DB::table('bookings').' WHERE id=%s',$first['booking_id']))==='canceled' && $mapping($first['booking_id'])['state']==='released','Core cancellation and dated resource release commit together.');
    $check(array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT allocated FROM '.$wpdb->prefix.'atlas_local_nights WHERE resource_id=%s AND local_date>=%s AND local_date<%s ORDER BY local_date',$resources[0],$dates['check_in'],$dates['check_out'])))===array(1,1,1),'Cancellation releases exactly this room, preserving the other customer allocation on all nights.');
    $check($transaction_fault('COMMIT','resource-bookings/'.$first['booking_id'].'/cancel-review',$cancel_review)->get_status()===503,
        'A cancellation replay reports a failed transaction completion.');
    $check($call('POST','resource-bookings/'.$first['booking_id'].'/cancel-review',$cancel_review)->get_data()===$canceled->get_data(),'An exact agency decision retry is idempotent after Core booking cancellation.');
    $check($call('POST','resource-bookings/'.$first['booking_id'].'/cancel-review',array_reverse($cancel_review,true))->get_data()===$canceled->get_data(),'Semantically identical review retries remain idempotent when JSON field order changes.');
    $changed=$cancel_review; $changed['reason']='Fictional changed review.'; $check($call('POST','resource-bookings/'.$first['booking_id'].'/cancel-review',$changed)->get_status()===409,'A changed decision cannot replay an already approved cancellation.');
    $check(is_wp_error(Atlas\Core\Booking::confirm($first['booking_id'],$admin,'Fictional supplier and agency confirmation')),'An old confirmation replay cannot report a canceled resource booking as confirmed.');
    Atlas\Core\Booking::expire($first['hold_id']);
    $check((int)$wpdb->get_var($wpdb->prepare('SELECT allocated FROM '.$wpdb->prefix.'atlas_local_nights WHERE resource_id=%s AND local_date=%s',$resources[0],$dates['check_in']))===1,'Expiry after reviewed cancellation cannot release the same room twice.');
    $check($wpdb->get_var($wpdb->prepare('SELECT status FROM '.Atlas\Core\DB::table('payments').' WHERE booking_id=%s',$first['booking_id']))==='verified' && (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Atlas\Core\DB::table('refunds').' WHERE booking_id=%s',$first['booking_id']))===0,'Cancellation preserves the verified payment and creates no unapproved refund/provider action.');
    $check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Atlas\Core\DB::table('outbox').' WHERE aggregate_id=%s AND event_type=%s',$first['booking_id'],'booking.refund_review_required'))===1,'Paid cancellation queues one explicit refund-review event.');
    wp_set_current_user($second); $failure_request=$call('POST','resource-bookings/'.$contender['booking_id'].'/cancel-request',array('version'=>0,'reason'=>'Fictional cancellation after supplier rejection.')); $check($failure_request->get_status()===200,'Customer can request cancellation of a paid supplier-failure exception.');
    wp_set_current_user($admin); $failure_review=$cancel_review; $failure_review['evidence_reference']='fictional-supplier-failure-cancel';
    $check($call('POST','resource-bookings/'.$contender['booking_id'].'/cancel-review',$failure_review)->get_status()===200,'Agency can release the failed supplier commitment while money remains in manual review.');
    $check(array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT allocated FROM '.$wpdb->prefix.'atlas_local_nights WHERE resource_id=%s AND local_date>=%s AND local_date<%s ORDER BY local_date',$resources[0],$dates['check_in'],$dates['check_out'])))===array(0,0,0),'Both individually reviewed cancellations restore the actual three-night room availability.');
    wp_set_current_user($second); $villa_request=$call('POST','resource-bookings/'.$villa_hold['booking_id'].'/cancel-request',array('version'=>0,'reason'=>'Fictional villa cancellation request.')); $check($villa_request->get_status()===200,'An unpaid villa hold can request cancellation.');
    $withdrawn=$call('POST','resource-bookings/'.$villa_hold['booking_id'].'/cancel-request',array('version'=>1,'reason'=>'Fictional customer changes their mind.','withdraw'=>true)); $check($withdrawn->get_status()===200 && $withdrawn->get_data()['state']==='withdrawn','Customer may withdraw an unreviewed request without changing allocation.');
    wp_set_current_user($admin); $check($call('POST','resource-bookings/'.$villa_hold['booking_id'].'/cancel-review',$cancel_review)->get_status()===409,'A stale agency decision cannot cancel a withdrawn customer request.');
    wp_set_current_user($second); $check($call('POST','resource-bookings/'.$villa_hold['booking_id'].'/cancel-request',array('version'=>2,'reason'=>'Fictional renewed villa cancellation request.'))->get_status()===200,'Customer can submit a new version after withdrawal.');
    wp_set_current_user($admin); $deny=$cancel_review; $deny['version']=3; $deny['approved']=false; $check($call('POST','resource-bookings/'.$villa_hold['booking_id'].'/cancel-review',$deny)->get_status()===200 && $mapping($villa_hold['booking_id'])['state']==='held','Agency rejection of cancellation preserves the whole-villa allocation.');
    wp_set_current_user($second); $check($call('POST','resource-bookings/'.$villa_hold['booking_id'].'/cancel-request',array('version'=>4,'reason'=>'Fictional revised customer request under agreed terms.'))->get_status()===200,'A rejected request can be resubmitted explicitly under the current version.');
    wp_set_current_user($admin); $villa_review=$cancel_review; $villa_review['version']=5; $villa_review['evidence_reference']='fictional-villa-cancel-review'; $villa_canceled=$call('POST','resource-bookings/'.$villa_hold['booking_id'].'/cancel-review',$villa_review);
    $check($villa_canceled->get_status()===200 && $villa_canceled->get_data()['fields']['refund_review_required']===false && $mapping($villa_hold['booking_id'])['state']==='released','An approved unpaid cancellation releases the villa without inventing a verified payment or refund.');
    $check((int)$wpdb->get_var($wpdb->prepare('SELECT MAX(allocated) FROM '.$wpdb->prefix.'atlas_local_nights WHERE resource_id=%s',$resources[1]))===0,'Whole-property night counts are actually restored after review.');
    Atlas\Core\DB::set_test_now((new DateTimeImmutable($next['expires_at']))->modify('+1 second')->format('Y-m-d\TH:i:s\Z'));
    $check(!is_wp_error(Atlas\Core\Booking::expire($next['hold_id'])) && $mapping($next['booking_id'])['state']==='released','Expiry atomically releases the additional room-night allocation.');
    $check((int)$wpdb->get_var($wpdb->prepare('SELECT allocated FROM '.$wpdb->prefix.'atlas_local_nights WHERE resource_id=%s AND local_date=%s',$resources[0],'2032-05-13'))===0,'Only the expired stay dates are released.');
    $late=Atlas\Core\Booking::verify($next['booking_id'],Atlas\Core\VerifiedPayment::from_adapter('local_simulator','resource-proof-'.wp_generate_uuid4(),8100000)); $check(!is_wp_error($late) && $mapping($next['booking_id'])['state']==='released','Money proof after released hold never reclaims dated resource allocation.');
    wp_set_current_user($customer); $occupied_options=$options; $occupied_options['check_in']='2032-05-20'; $occupied_options['check_out']='2032-05-22'; $occupied=$hold($offers[0],$customer,1,$occupied_options); $check(!is_wp_error($occupied),'Fictional check-in-day stay fixture can reserve its own actual nights.');
    $check(!is_wp_error(Atlas\Core\Booking::verify($occupied['booking_id'],Atlas\Core\VerifiedPayment::from_adapter('local_simulator','resource-proof-'.wp_generate_uuid4(),16100000))),'Fictional occupied-stay payment can verify.');
    wp_set_current_user($supplier); $check($call('POST','resource-bookings/'.$occupied['booking_id'].'/supplier-review',$decision)->get_status()===200,'Supplier separately accepts the stay used for occupancy protection.');
    wp_set_current_user($admin); $check(!is_wp_error(Atlas\Core\Booking::confirm($occupied['booking_id'],$admin,'Fictional occupancy fixture confirmation')),'Agency separately confirms the future occupancy fixture.');
    wp_set_current_user($admin); $maintenance=Atlas\Inventory\Local::allocate_vehicle($resources[2],'2032-05-20T06:30:00Z','2032-05-21T06:30:00Z','maintenance','fictional-maintenance-'.wp_generate_uuid4()); $check(!is_wp_error($maintenance),'Manual maintenance shares the same vehicle calendar.');
    $rental=array('rate_version'=>'1','pickup_local'=>'2032-05-20T10:00','return_local'=>'2032-05-24T10:00','protection'=>true,'terms_accepted'=>true);
    wp_set_current_user($customer); $check(is_wp_error($hold($offers[2],$customer,1,$rental)),'Public rental cannot overlap existing manual maintenance.');
    wp_set_current_user($admin); $check(!is_wp_error(Atlas\Inventory\Local::release($maintenance['id'])),'Manual maintenance release remains available.');
    wp_set_current_user($customer); $check(is_wp_error($hold($offers[2],$customer,2,$rental)),'One vehicle cannot be represented as a multiple-quantity rental.');
    $bad=$rental; $bad['unit_amount_irr']=1; $check(is_wp_error($hold($offers[2],$customer,1,$bad)),'Client-supplied price options are rejected.');
    $car=$hold($offers[2],$customer,1,$rental); $check(!is_wp_error($car) && $car['quote']['amount']===22100000,'Four started24-hour units, explicit tax and opted-in protection produce the persisted exact total.'); $car_map=$mapping($car['booking_id']);
    wp_set_current_user($admin); $check(is_wp_error(Atlas\Inventory\Local::allocate_vehicle($resources[2],'2032-05-20T07:00:00Z','2032-05-20T08:00:00Z','transfer','fictional-transfer-'.wp_generate_uuid4())),'A public rental blocks the manual chauffeured-transfer calendar too.');
    $check(is_wp_error(Atlas\Inventory\Local::start_vehicle($car_map['fields']['allocation_id'])),'Unpaid/unconfirmed public rental cannot be picked up through an internal endpoint.');
    wp_set_current_user($second); $buffer=$rental; $buffer['pickup_local']='2032-05-24T10:30'; $buffer['return_local']='2032-05-25T10:30';
    $check(is_wp_error($hold($offers[2],$second,1,$buffer)),'Turnaround buffer prevents a new public rental before vehicle inspection time ends.');
    $check(Atlas_Resource_Booking::local_instant('2032-03-28T02:30','Europe/Berlin')===false,'Nonexistent daylight-saving local times are rejected.');
    $check(Atlas_Resource_Booking::local_instant('2032-10-31T02:30','Europe/Berlin')===false && Atlas_Resource_Booking::local_instant('2032-10-31T02:30','Europe/Berlin','+01:00') instanceof DateTimeImmutable,'Ambiguous local times require an explicit matching offset.');
    wp_set_current_user($customer); $check(!is_wp_error(Atlas\Core\Booking::verify($car['booking_id'],Atlas\Core\VerifiedPayment::from_adapter('local_simulator','resource-proof-'.wp_generate_uuid4(),22100000))),'Fictional rental payment is independently verified.');
    wp_set_current_user($supplier); $check($call('POST','resource-bookings/'.$car['booking_id'].'/supplier-review',$decision)->get_status()===200,'Assigned rental supplier separately accepts availability.');
    wp_set_current_user($admin); $check(!is_wp_error(Atlas\Core\Booking::confirm($car['booking_id'],$admin,'Fictional rental supplier and agency confirmation')),'Agency confirms the accepted paid rental.');
    Atlas\Core\DB::set_test_now('2032-05-20T06:30:00Z');
    $check(is_wp_error(Atlas\Inventory\Local::start_vehicle($car_map['fields']['allocation_id'])),'A paid/confirmed rental still requires manual license, age and coverage evidence at pickup.');
    $eligibility=array('renter_reference'=>'fictional-renter-001','age_years'=>30,'age_evidence_reference'=>'fictional-age-check-001','coverage_evidence_reference'=>'fictional-coverage-check-001','review_reference'=>'fictional-pickup-review-001','license_class'=>'B','license_expires_at'=>'2033-01-01T00:00:00Z','license_evidence_reference'=>'fictional-license-check-001');
    $check(!is_wp_error(Atlas\Inventory\Local::review_eligibility($car_map['fields']['allocation_id'],$eligibility)) && !is_wp_error(Atlas\Inventory\Local::start_vehicle($car_map['fields']['allocation_id'])),'Existing manual-reviewed eligibility gates actual public-rental pickup.');
    wp_set_current_user($customer); $check($call('POST','resource-bookings/'.$occupied['booking_id'].'/cancel-request',array('version'=>0,'reason'=>'Fictional same-day stay cancellation inquiry.'))->get_status()===200,'Customer may request manual review on the local check-in day.');
    wp_set_current_user($admin); $check($call('POST','resource-bookings/'.$occupied['booking_id'].'/cancel-review',$cancel_review)->get_status()===409,'Agency cannot release a stay on its local check-in day without separate occupancy reconciliation.');
    $check((int)$wpdb->get_var($wpdb->prepare('SELECT allocated FROM '.$wpdb->prefix.'atlas_local_nights WHERE resource_id=%s AND local_date=%s',$resources[0],'2032-05-20'))===1,'Check-in-day refusal preserves the actual occupied room-night count.');
    wp_set_current_user($customer); $check($call('POST','resource-bookings/'.$car['booking_id'].'/cancel-request',array('version'=>0,'reason'=>'Fictional post-pickup cancellation inquiry.'))->get_status()===200,'Customer can ask for review of a rental after pickup without automatically changing state.');
    wp_set_current_user($admin); $check($call('POST','resource-bookings/'.$car['booking_id'].'/cancel-review',$cancel_review)->get_status()===409 && $wpdb->get_var($wpdb->prepare('SELECT state FROM '.$wpdb->prefix.'atlas_local_allocations WHERE id=%s',$car_map['fields']['allocation_id']))==='in_use','A picked-up vehicle cannot be released by cancellation review instead of return inspection.');
    Atlas\Core\DB::set_test_now('2032-05-24T07:30:00Z');
    $returned=Atlas\Inventory\Local::return_vehicle($car_map['fields']['allocation_id'],'2032-05-24T07:30:00Z','Fictional parties disagree on damage; preserve both accounts.',true);
    $check(!is_wp_error($returned) && $wpdb->get_var($wpdb->prepare('SELECT state FROM '.$wpdb->prefix.'atlas_local_allocations WHERE id=%s',$car_map['fields']['allocation_id']))==='disputed','Return dispute blocks further assignments without an automatic charge.');
    $check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Atlas\Core\DB::table('refunds').' WHERE booking_id=%s',$car['booking_id']))===0 && $wpdb->get_var($wpdb->prepare('SELECT amount_irr FROM '.Atlas\Core\DB::table('bookings').' WHERE id=%s',$car['booking_id']))==='22100000','Damage/deposit handling creates no unapproved provider charge or refund.');
    WP_CLI::log('Atlas resource booking checks: '.$checks.' passed, 0 failed. Dated quotes, atomic allocation/release, supplier separation, ACL, shared calendars, DST and manual pickup covered.');
} finally {
    global $wpdb; Atlas\Core\DB::set_test_now(null); Atlas\Core\DB::set_test_audit_failure(false);
    foreach (array_unique($bookings) as $id) { foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM '.Atlas\Core\DB::table('payments').' WHERE booking_id=%s',$id)) as $payment) { $wpdb->delete(Atlas\Core\DB::table('ledger'),array('payment_id'=>$payment)); $wpdb->delete(Atlas\Core\DB::table('refunds'),array('payment_id'=>$payment)); } $wpdb->delete(Atlas\Core\DB::table('payments'),array('booking_id'=>$id)); $wpdb->delete(Atlas\Core\DB::table('bookings'),array('id'=>$id)); $wpdb->delete(Atlas\Core\DB::table('idempotency'),array('idem_key'=>'confirm-direct:'.$id)); $records[]=$id; }
    foreach (array_unique($holds) as $id) $wpdb->delete(Atlas\Core\DB::table('holds'),array('id'=>$id));
    foreach ($resources as $id) { $allocations=$wpdb->get_col($wpdb->prepare('SELECT id FROM '.$wpdb->prefix.'atlas_local_allocations WHERE resource_id=%s',$id)); foreach ($allocations as $allocation) $wpdb->delete(Atlas\Core\DB::table('audit'),array('object_id'=>$allocation)); $wpdb->delete($wpdb->prefix.'atlas_local_allocations',array('resource_id'=>$id)); $wpdb->delete($wpdb->prefix.'atlas_local_nights',array('resource_id'=>$id)); $wpdb->delete($wpdb->prefix.'atlas_local_resources',array('id'=>$id)); }
    foreach ($orgs as $org) foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM '.Atlas\Core\DB::table('records').' WHERE org_id=%d',$org)) as $id) $records[]=$id;
    foreach (array_unique($records) as $id) { $wpdb->delete(Atlas\Core\DB::table('outbox'),array('aggregate_id'=>$id)); $wpdb->delete(Atlas\Core\DB::table('audit'),array('object_id'=>$id)); $wpdb->delete(Atlas\Core\DB::table('records'),array('id'=>$id)); }
    foreach ($offers as $id) { $wpdb->delete(Atlas\Core\DB::table('inventory'),array('offer_id'=>$id)); wp_delete_post($id,true); }
    foreach ($products as $id) wp_delete_post($id,true);
    foreach ($orgs as $id) { $wpdb->delete(Atlas\Core\DB::table('memberships'),array('org_id'=>$id)); $wpdb->delete(Atlas\Core\DB::table('organizations'),array('id'=>$id)); }
    foreach ($keys as $key) $wpdb->delete(Atlas\Core\DB::table('idempotency'),array('idem_key'=>$key));
    foreach ($users as $id) wp_delete_user($id);
    wp_set_current_user(0);
}
