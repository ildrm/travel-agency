<?php
/** FICTIONAL HTTP fixtures only. Every WP HTTP call is intercepted; never contact a PSP. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='local' || getenv('ATLAS_TEST_DB')!=='atlas_integration' || !defined('ATLAS_DEMO_MODE') || ATLAS_DEMO_MODE!==true) throw new RuntimeException('Isolated local demo CLI required.');
if (!defined('ATLAS_TEST_GATEWAYS')) define('ATLAS_TEST_GATEWAYS',true);
if (!defined('ATLAS_TEST_CLOCK')) define('ATLAS_TEST_CLOCK',true);
use Atlas\Core\DB;
use Atlas\Core\Offers;
use Atlas\Core\Booking;
if (!class_exists('Atlas_ZarinPal') || !class_exists('Atlas_Gateway_ZarinPal')) WP_CLI::error('Prepared gateway plugin required.');
$passed=0; $failed=0; $check=static function($ok,$name) use (&$passed,&$failed) { if ($ok) { $passed++; echo "PASS $name\n"; } else { $failed++; echo "FAIL $name\n"; } };
$check(!Atlas_ZarinPal::ready() && !(new Atlas_Gateway_ZarinPal())->is_available(),'real sandbox/live gateway remains off without explicit merchant configuration');
if (Atlas_ZarinPal::ready()) WP_CLI::error('Refuse to run fixture with an enabled real merchant configuration.');
global $wpdb; $suffix=wp_generate_password(10,false,false); $adminIds=get_users(array('role'=>'administrator','number'=>1,'fields'=>'ID')); $admin=(int)($adminIds[0]??0); if (!$admin) WP_CLI::error('Local administrator required.');
$user=wp_insert_user(array('user_login'=>'zp_owner_'.$suffix,'user_pass'=>wp_generate_password(32),'user_email'=>'owner_'.$suffix.'@example.invalid','role'=>'customer')); $other=wp_insert_user(array('user_login'=>'zp_other_'.$suffix,'user_pass'=>wp_generate_password(32),'user_email'=>'other_'.$suffix.'@example.invalid','role'=>'customer')); if (is_wp_error($user) || is_wp_error($other)) WP_CLI::error('Customer fixture failed.'); $user=(int)$user; $other=(int)$other;
if (!WC()->session) WC()->initialize_session(); WC()->initialize_cart(); WC()->cart->empty_cart();
$product=new WC_Product_Simple(); $product->set_name('Fictional gateway souvenir'); $product->set_status('publish'); $product->set_regular_price('1000000'); $product->set_price('1000000'); $product->set_virtual(true); $product->save();
$makeOrder=static function($amount='1000000') use ($user,$product) { $order=wc_create_order(array('customer_id'=>$user)); if (is_wp_error($order)) WP_CLI::error('Order fixture failed.'); $order->set_currency('IRR'); $order->set_payment_method('atlas_zarinpal'); $order->add_product($product,1); $order->set_total($amount); $order->save(); return $order; };
$requests=array(); $issued=array(); $modes=array(); $sequence=0; $escape=0; $requestMode='normal'; $advanceDeadline=null;
$authorityPrefix=str_pad((string)random_int(1,PHP_INT_MAX),31,'0',STR_PAD_LEFT);
$http=static function($url,$args) use (&$requests,&$issued,&$modes,&$sequence,&$escape,&$requestMode,&$advanceDeadline,$authorityPrefix) {
    $path=wp_parse_url($url,PHP_URL_PATH); $host=wp_parse_url($url,PHP_URL_HOST);
    if ($host!=='sandbox.zarinpal.com' || wp_parse_url($url,PHP_URL_SCHEME)!=='https' || !in_array($path,array('/pg/v4/payment/request.json','/pg/v4/payment/verify.json','/pg/v4/payment/inquiry.json'),true) || ($args['sslverify']??false)!==true || ($args['redirection']??null)!==0 || ($args['timeout']??99)>15) { $escape++; throw new RuntimeException('Unexpected HTTP request blocked by fixture.'); }
    $body=json_decode($args['body']??'',true); if (!is_array($body) || ($body['merchant_id']??'')!=='11111111-2222-3333-4444-555555555555') { $escape++; throw new RuntimeException('Unexpected merchant payload blocked.'); }
    $requests[]=array('path'=>$path,'body'=>$body); $response=static function($data) { return array('headers'=>array('content-type'=>'application/json'),'body'=>wp_json_encode(array('data'=>$data,'errors'=>array())),'response'=>array('code'=>200,'message'=>'OK'),'cookies'=>array()); };
    if ($path==='/pg/v4/payment/request.json') {
        if ($requestMode==='timeout') return new WP_Error('http_request_failed','Fictional interrupted request.');
        if ($requestMode==='bad_authority') return $response(array('code'=>100,'authority'=>'https://attacker.example.invalid/redirect'));
        $authority='S'.$authorityPrefix.str_pad((string)(++$sequence),4,'0',STR_PAD_LEFT); parse_str(wp_parse_url($body['callback_url'],PHP_URL_QUERY),$params); $params['Authority']=$authority; $params['Status']='OK'; $issued[$authority]=array('callback'=>$params,'amount'=>$body['amount']);
        if ($requestMode==='deadline' && $advanceDeadline) DB::set_test_now($advanceDeadline);
        return $response(array('code'=>100,'authority'=>$authority));
    }
    $authority=$body['authority']??''; if (!isset($issued[$authority])) { $escape++; throw new RuntimeException('Unissued authority verification blocked.'); }
    $mode=$modes[$authority]??'normal';
    if ($path==='/pg/v4/payment/inquiry.json') return $response(array('code'=>100,'status'=>'VERIFIED','amount'=>$issued[$authority]['amount']));
    if ($mode==='timeout') return new WP_Error('http_request_failed','Fictional verify timeout.');
    if ($mode==='101') return $response(array('code'=>101,'ref_id'=>'999999999','authority'=>$authority));
    if ($mode==='malformed') return array('headers'=>array(),'body'=>'not-json','response'=>array('code'=>200,'message'=>'OK'),'cookies'=>array());
    return $response(array('code'=>100,'authority'=>$authority,'ref_id'=>ltrim(substr($authority,1),'0'),'amount'=>$issued[$authority]['amount']+($mode==='amount_mismatch'?1:0),'paid_at'=>'2027-06-01T06:00:00Z','card_pan'=>'FICTIONAL_PAN_MUST_NOT_BE_STORED','card_hash'=>'FICTIONAL_CARD_HASH_MUST_NOT_BE_STORED'));
};
$config=array('mode'=>'sandbox','merchant_id'=>'11111111-2222-3333-4444-555555555555');
Atlas_ZarinPal::set_test_config($config); $check(!Atlas_ZarinPal::ready(),'fixture configuration cannot enable gateway without HTTP interception');
Atlas_ZarinPal::set_test_config(array('mode'=>'live','merchant_id'=>$config['merchant_id']),$http); $check(!Atlas_ZarinPal::ready(),'local fixture cannot enable live provider mode');
Atlas_ZarinPal::set_test_config($config,$http); $check(Atlas_ZarinPal::ready() && (new Atlas_Gateway_ZarinPal())->is_available(),'guarded sandbox fixture enables actual prepared gateway logic');
$check(apply_filters('atlas_core_verified_service_gateway',false,'atlas_zarinpal')===true,'Core service allowlist admits only ready server-gated provider');
DB::set_test_now('2027-06-01T06:00:00.000000Z');
try {
    $order=$makeOrder(); wp_set_current_user($other); $before=count($requests); $check(is_wp_error(Atlas_ZarinPal::initiate($order->get_id())) && count($requests)===$before,'another customer cannot initiate owner order payment');
    wp_set_current_user(0); $check(is_wp_error(Atlas_ZarinPal::initiate($order->get_id())) && count($requests)===$before,'anonymous initiation is denied before HTTP');
    wp_set_current_user($user); $started=Atlas_ZarinPal::initiate($order->get_id()); $check(is_array($started) && str_starts_with($started['redirect'],'https://sandbox.zarinpal.com/pg/StartPay/S'),'request returns only official HTTPS StartPay authority redirect');
    if (is_wp_error($started)) WP_CLI::error('Fixture could not create its first provider attempt: '.$started->get_error_code());
    $authority=basename($started['redirect']); $callback=$issued[$authority]['callback']; $attempt=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Atlas_ZarinPal::table().' WHERE order_id=%d',$order->get_id()));
    $check($attempt && $attempt->state_hash===hash('sha256',$callback['state']) && $attempt->state_hash!==$callback['state'] && $attempt->currency==='IRR' && (int)$attempt->amount_irr===1000000,'attempt durably binds order/amount/currency and stores callback hash only');
    $before=count($requests); $repeat=Atlas_ZarinPal::initiate($order->get_id()); $check(is_array($repeat) && $repeat['redirect']===$started['redirect'] && count($requests)===$before,'pending owner retry reuses authority without reinitiating');
    $tampered=$callback; $tampered['state']=str_repeat('0',64); $generic=Atlas_ZarinPal::callback_result($tampered);
    $wrongAuthority=$callback; $wrongAuthority['Authority']='S'.str_repeat('9',35); Atlas_ZarinPal::callback_result($wrongAuthority);
    $check($generic===array('status'=>'received') && count($requests)===$before,'tampered callback state or authority cannot trigger provider HTTP or expose order');
    wp_set_current_user(0); $callback['amount']=1; $callback['order_id']=999999; $public=Atlas_ZarinPal::callback_result($callback);
    $check($public===array('status'=>'received') && wc_get_order($order->get_id())->is_paid(),'unauthenticated callback independently verifies money while returning generic no-PII status');
    $before=count($requests); $callback['Status']='NOK'; $public=Atlas_ZarinPal::callback_result($callback);
    $check($public===array('status'=>'received') && count($requests)===$before && wc_get_order($order->get_id())->is_paid(),'NOK/replayed callback cannot erase verified money or repeat provider verification');
    wp_set_current_user($other); $check(Atlas_ZarinPal::callback_result($callback)===array('status'=>'received'),'logged-in unrelated callback user receives no receipt URL or order key');
    wp_set_current_user($user); $receipt=Atlas_ZarinPal::callback_result($callback); $paidReplay=Atlas_ZarinPal::initiate($order->get_id());
    $check(isset($receipt['redirect']) && is_array($paidReplay) && count($requests)===$before,'only owner receives paid receipt replay with no new request');
    $amountCheck=array_values(array_filter($requests,static fn($r)=>$r['path']==='/pg/v4/payment/verify.json'))[0];
    $check($amountCheck['body']['amount']===1000000 && !isset($amountCheck['body']['paid_at']) && count($amountCheck['body'])===3,'verification sends stored amount/authority/merchant only, never trusted client/provider paid_at');
    $timeoutOrder=$makeOrder(); $requestMode='timeout'; $unknown=Atlas_ZarinPal::initiate($timeoutOrder->get_id()); $before=count($requests); $retry=Atlas_ZarinPal::initiate($timeoutOrder->get_id());
    $check(is_wp_error($unknown) && is_wp_error($retry) && count($requests)===$before && (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Atlas_ZarinPal::table().' WHERE order_id=%d',$timeoutOrder->get_id()))===1,'interrupted request remains durable unknown and never blindly reinitiates');
    global $wp; $savedOrderPay=$wp->query_vars['order-pay']??null; $wp->query_vars['order-pay']=$timeoutOrder->get_id();
    $check(Atlas_ZarinPal::guarded_gateways(array('bacs'=>new stdClass(),'atlas_zarinpal'=>new stdClass()))===array(),'unknown ZarinPal order cannot silently switch to another gateway on order-pay');
    if ($savedOrderPay===null) unset($wp->query_vars['order-pay']); else $wp->query_vars['order-pay']=$savedOrderPay;
    $requestMode='bad_authority'; $badOrder=$makeOrder(); $bad=Atlas_ZarinPal::initiate($badOrder->get_id()); $check(is_wp_error($bad) && !$badOrder->is_paid(),'malicious provider authority cannot become an external redirect'); $requestMode='normal';
    $ownerChanged=$makeOrder(); $ownerStart=Atlas_ZarinPal::initiate($ownerChanged->get_id()); $ownerChanged->set_customer_id($other); $ownerChanged->save(); wp_set_current_user($other); $before=count($requests);
    $check(is_wp_error(Atlas_ZarinPal::initiate($ownerChanged->get_id())) && count($requests)===$before,'pending attempt cannot be rebound to a changed order owner'); wp_set_current_user($user);
    $unknownCases=array();
    foreach (array('101','timeout','amount_mismatch','malformed') as $mode) {
        $testOrder=$makeOrder(); $start=Atlas_ZarinPal::initiate($testOrder->get_id()); $auth=basename($start['redirect']); $modes[$auth]=$mode; $before=count($requests); wp_set_current_user(0); $result=Atlas_ZarinPal::callback_result($issued[$auth]['callback']);
        $row=$wpdb->get_row($wpdb->prepare('SELECT provider_verified,state,last_error FROM '.Atlas_ZarinPal::table().' WHERE order_id=%d',$testOrder->get_id()));
        $check($result===array('status'=>'received') && !$row->provider_verified && $row->state==='verify_unknown' && !wc_get_order($testOrder->get_id())->is_paid() && count($requests)===$before+2,'verify '.$mode.' plus inquiry never invents independently stored success'); $unknownCases[$mode]=array('authority'=>$auth,'order_id'=>$testOrder->get_id()); wp_set_current_user($user);
    }
    $recover=$unknownCases['timeout']; $modes[$recover['authority']]='normal'; $recoverAttempt=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Atlas_ZarinPal::table().' WHERE order_id=%d',$recover['order_id'])); wp_set_current_user($admin);
    $reviewRequest=new WP_REST_Request('POST','/agency/v1/zarinpal/attempts/'.$recoverAttempt.'/reconcile'); $reviewRequest->set_header('Content-Type','application/json'); $reviewRequest->set_header('X-WP-Nonce',wp_create_nonce('wp_rest')); $reviewRequest->set_body(wp_json_encode(array('reason'=>'Fictional independent reconciliation after interrupted verification'))); $reviewResponse=rest_get_server()->dispatch($reviewRequest);
    $check($reviewResponse->get_status()===200 && wc_get_order($recover['order_id'])->is_paid(),'authorized agency reconciliation can recover existing unknown attempt without another request');
    wp_set_current_user($user);
    $edited=$makeOrder(); $editStart=Atlas_ZarinPal::initiate($edited->get_id()); $edited->set_total('2000000'); $edited->save(); $editAuth=basename($editStart['redirect']); wp_set_current_user(0); Atlas_ZarinPal::callback_result($issued[$editAuth]['callback']);
    $editAttempt=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Atlas_ZarinPal::table().' WHERE order_id=%d',$edited->get_id()));
    $check((int)$editAttempt->provider_verified===1 && (int)$editAttempt->amount_irr===1000000 && $editAttempt->state==='core_pending' && !wc_get_order($edited->get_id())->is_paid(),'verified old amount is preserved while changed Woo total requires manual order review');
    wp_set_current_user($user); $small=$makeOrder('9999'); $before=count($requests); $check(is_wp_error(Atlas_ZarinPal::initiate($small->get_id())) && count($requests)===$before,'documented minimum10000 IRR is enforced before HTTP');

    // Real local Core/Woo lifecycle, but transport remains entirely fictional.
    wp_set_current_user($admin); $service=new WC_Product_Simple(); $service->set_name('Fictional PSP event'); $service->set_status('publish'); $service->set_virtual(true); $service->set_regular_price('1000000'); $service->set_price('1000000'); $service->save();
    $offer=Offers::save(0,array('kind'=>'event','org_id'=>0,'title_en'=>'Fictional PSP event','title_fa'=>'','description_en'=>'','description_fa'=>'','registration_open_at'=>'2027-06-01T05:30:00Z','registration_close_at'=>'2027-06-15T14:30:00Z','timezone'=>'UTC','capacity'=>10,'amount_irr'=>1000000,'mode'=>'local','status'=>'published','product_id'=>$service->get_id()),$admin); if (is_wp_error($offer)) WP_CLI::error('Offer fixture failed.'); update_post_meta($service->get_id(),'_atlas_offer_id',$offer);
    $makeService=static function() use ($offer,$user,$service,$suffix) {
        static $count=0; wp_set_current_user($user); $hold=Booking::hold($offer,1,1,$user,'zp_hold_'.$suffix.'_'.(++$count)); if (is_wp_error($hold)) WP_CLI::error('Hold fixture failed: '.$hold->get_error_message());
        WC()->cart->empty_cart(); WC()->cart->add_to_cart($service->get_id(),1,0,array(),array('atlas_booking_id'=>$hold['booking_id'])); WC()->cart->calculate_totals();
        $order=wc_create_order(array('customer_id'=>$user)); $order->set_currency('IRR'); $order->set_payment_method('atlas_zarinpal'); $order->add_product($service,1); $order->set_total('1000000'); $order->save(); \Atlas\Core\Woo::store_order($order); return array($order,$hold);
    };
    DB::set_test_now('2027-06-01T06:00:00.000000Z'); list($serviceOrder,$hold)=$makeService(); $serviceStart=Atlas_ZarinPal::initiate($serviceOrder->get_id()); $serviceAuth=basename($serviceStart['redirect']); wp_set_current_user(0); Atlas_ZarinPal::callback_result($issued[$serviceAuth]['callback']);
    wp_set_current_user($user); $booking=Booking::get($hold['booking_id'],$user); $proofs=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('payments').' WHERE booking_id=%s',$hold['booking_id']));
    $check($booking['status']==='paid_pending_confirmation' && $booking['payment_status']==='verified' && $proofs===1 && wc_get_order($serviceOrder->get_id())->is_paid(),'independent provider100 creates one Core payment/ledger and Woo paid state, without agency confirmation');
    $before=count($requests); Atlas_ZarinPal::callback_result($issued[$serviceAuth]['callback']); $check($proofs===(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('payments').' WHERE booking_id=%s',$hold['booking_id'])) && count($requests)===$before,'service proof replay has no duplicate Core money or HTTP side effect');
    list($deadlineOrder,$deadlineHold)=$makeService(); DB::set_test_now($deadlineHold['payment_deadline']); $before=count($requests); $check(is_wp_error(Atlas_ZarinPal::initiate($deadlineOrder->get_id())) && count($requests)===$before && !(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Atlas_ZarinPal::table().' WHERE order_id=%d',$deadlineOrder->get_id())),'expired hold cannot prepare or send a real-provider initiation');
    DB::set_test_now('2027-06-01T06:00:00.000000Z'); list($redirectOrder,$redirectHold)=$makeService(); $requestMode='deadline'; $advanceDeadline=$redirectHold['payment_deadline']; $before=count($requests); $check(is_wp_error(Atlas_ZarinPal::initiate($redirectOrder->get_id())) && count($requests)===$before+1,'deadline is checked again after request response before StartPay redirect'); $requestMode='normal';
    DB::set_test_now('2027-06-01T06:00:00.000000Z'); list($lateOrder,$lateHold)=$makeService(); $lateStart=Atlas_ZarinPal::initiate($lateOrder->get_id()); $lateAuth=basename($lateStart['redirect']); DB::set_test_now($lateHold['payment_deadline']); Booking::expire($lateHold['hold_id']); wp_set_current_user(0); Atlas_ZarinPal::callback_result($issued[$lateAuth]['callback']); wp_set_current_user($user); $late=Booking::get($lateHold['booking_id'],$user);
    $check($late['status']==='exception' && $late['payment_status']==='verified' && wc_get_order($lateOrder->get_id())->is_paid(),'late verification preserves real money as exception without trusting response paid_at or reacquiring capacity');
    DB::set_test_now('2027-06-01T06:00:00.000000Z'); list($unreleasedOrder,$unreleasedHold)=$makeService(); $unreleasedStart=Atlas_ZarinPal::initiate($unreleasedOrder->get_id()); $unreleasedAuth=basename($unreleasedStart['redirect']);
    DB::set_test_now((new DateTimeImmutable($unreleasedHold['payment_deadline']))->modify('+1 second')->format('Y-m-d\TH:i:s.u\Z')); wp_set_current_user(0); Atlas_ZarinPal::callback_result($issued[$unreleasedAuth]['callback']); wp_set_current_user($user); $unreleased=Booking::get($unreleasedHold['booking_id'],$user);
    $check($unreleased['status']==='exception' && $unreleased['payment_status']==='verified' && $wpdb->get_var($wpdb->prepare('SELECT state FROM '.DB::table('holds').' WHERE id=%s',$unreleasedHold['hold_id']))==='active','unreleased late hold still requires review because no trustworthy provider paid_at contract exists');
    $stored=wp_json_encode($wpdb->get_results('SELECT * FROM '.Atlas_ZarinPal::table(),ARRAY_A));
    $check(strpos($stored,'FICTIONAL_PAN')===false && strpos($stored,'FICTIONAL_CARD_HASH')===false && strpos($stored,$callback['state'])===false,'attempt storage contains no raw PAN/hash, callback secret or provider response body');
    wp_set_current_user($other); $r=new WP_REST_Request('GET','/agency/v1/zarinpal/attempts'); $r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest')); $check(rest_get_server()->dispatch($r)->get_status()===403,'another customer cannot read gateway reconciliation queue');
    $check($escape===0,'all HTTP stayed inside the strict fictional interceptor; zero provider network escape');
    $_GET['lang']='fa'; $localized=new Atlas_Gateway_ZarinPal(); $check(str_contains($localized->title,'زرین') && str_contains($localized->description,'ریال'),'prepared gateway exposes meaningful Persian title and IRR description'); unset($_GET['lang']);
} finally { Atlas_ZarinPal::set_test_config(null); DB::set_test_now(null); WC()->cart->empty_cart(); wp_set_current_user(0); }
$check(!Atlas_ZarinPal::ready(),'real external gateway remains disabled after fictional transport cleanup');
echo "Atlas ZarinPal fixture checks: $passed passed, $failed failed\n"; if ($failed) WP_CLI::error('ZarinPal fixture checks failed.'); WP_CLI::success('Prepared ZarinPal logic passed fictional HTTP fixtures; no PSP acceptance claimed.');
