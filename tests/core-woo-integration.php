<?php
/** Local-only WP-CLI integration; uses fictional products, orders and simulated payment/refund proof. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='local' || !defined('ATLAS_DEMO_MODE') || ATLAS_DEMO_MODE!==true) { throw new RuntimeException('Isolated local WP-CLI with explicit simulator mode required.'); }
if (!defined('ATLAS_TEST_CLOCK')) { define('ATLAS_TEST_CLOCK',true); }
use Atlas\Core\DB;
use Atlas\Core\Offers;
use Atlas\Core\Finance;
use Atlas\Core\VerifiedRefund;
$passed=0; $failed=0;
$check=static function(bool $ok,string $name) use (&$passed,&$failed): void { if ($ok) { $passed++; echo "PASS $name\n"; } else { $failed++; echo "FAIL $name\n"; } };
if (!class_exists('WooCommerce') || !class_exists('Atlas_Gateway_Simulator')) { WP_CLI::error('WooCommerce and local simulator must be active.'); }
$check(class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),'HPOS authoritative order storage enabled');
$check(get_woocommerce_currency()==='IRR','Woo currency configured in IRR');
$users=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']); if (!$users) { WP_CLI::error('Local admin required.'); }
$admin=(int)$users[0]; wp_set_current_user($admin); $suffix=substr(wp_generate_password(12,false,false),0,12);
$user=wp_insert_user(['user_login'=>'atlas_woo_'.$suffix,'user_pass'=>wp_generate_password(32),'user_email'=>'atlas_woo_'.$suffix.'@example.invalid','role'=>'customer']);
if (is_wp_error($user)) { WP_CLI::error('Could not create local fictional customer.'); } $user=(int)$user;
$service=new WC_Product_Simple(); $service->set_name('Fictional event '.$suffix); $service->set_status('publish'); $service->set_virtual(true); $service->set_regular_price('1000000'); $serviceId=$service->save();
$physical=new WC_Product_Simple(); $physical->set_name('Fictional souvenir '.$suffix); $physical->set_status('publish'); $physical->set_virtual(false); $physical->set_regular_price('250000'); $physicalId=$physical->save();
$check($serviceId>0 && $physicalId>0 && wc_get_product($serviceId) instanceof WC_Product,'Woo product create/read via CRUD');
$offer=Offers::save(0,['kind'=>'event','org_id'=>0,'title_en'=>'Fictional Store API event','title_fa'=>'','description_en'=>'','description_fa'=>'','registration_open_at'=>'2027-06-01T05:30:00.000000Z','registration_close_at'=>'2027-06-15T14:30:00.000000Z','timezone'=>'Asia/Tehran','capacity'=>3,'amount_irr'=>1000000,'mode'=>'local','status'=>'published','product_id'=>$serviceId],$admin);
if (is_wp_error($offer)) { WP_CLI::error('Could not create local offer: '.$offer->get_error_message()); }
update_post_meta($serviceId,'_atlas_offer_id',$offer);
wp_set_current_user($user); DB::set_test_now('2027-06-01T06:00:00.000000Z');
if (!WC()->session) { WC()->initialize_session(); } WC()->initialize_cart(); WC()->cart->empty_cart();
$api=static function(string $method,string $route,array $payload=[]): WP_REST_Response {
    $request=new WP_REST_Request($method,$route); $request->set_header('Content-Type','application/json'); $request->set_header('Nonce',wp_create_nonce('wc_store_api')); $request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest')); $request->set_body(wp_json_encode($payload));
    return rest_get_server()->dispatch($request);
};
$holdReq=new WP_REST_Request('POST','/agency/v1/holds'); $holdReq->set_header('Content-Type','application/json'); $holdReq->set_header('X-WP-Nonce',wp_create_nonce('wp_rest')); $holdReq->set_header('Idempotency-Key','woo-hold-'.$suffix); $holdReq->set_body(wp_json_encode(['offer_id'=>$offer,'offer_version'=>1,'quantity'=>1,'participant_refs'=>[]]));
$holdResponse=rest_get_server()->dispatch($holdReq); $hold=$holdResponse->get_data();
$check($holdResponse->get_status()===201 && is_array($hold) && !empty($hold['booking_id']),'API-BOOK-001 authenticated REST hold');
if (!is_array($hold) || empty($hold['booking_id'])) { echo 'Hold response: '.wp_json_encode($hold)."\n"; WP_CLI::error('Cannot test Woo checkout without a hold.'); }
$add=$api('POST','/wc/store/v1/cart/add-item',['id'=>$serviceId,'quantity'=>1,'atlas_booking_id'=>$hold['booking_id']]);
$check($add->get_status()>=200 && $add->get_status()<300 && WC()->cart->get_cart_contents_count()===1,'Store API adds held service to cart');
if ($add->get_status()>=400) { echo 'Store cart response: '.wp_json_encode($add->get_data())."\n"; }
$mixed=$api('POST','/wc/store/v1/cart/add-item',['id'=>$physicalId,'quantity'=>1]);
$check($mixed->get_status()>=400 && WC()->cart->get_cart_contents_count()===1,'Store API rejects mixed service/physical cart without discarding it');
WC()->cart->calculate_totals();
$checkout=$api('POST','/wc/store/v1/checkout',['billing_address'=>['first_name'=>'Fictional','last_name'=>'Customer','address_1'=>'123 Sample Road','city'=>'Tehran','state'=>'THR','postcode'=>'1234567890','country'=>'IR','email'=>'atlas_'.$suffix.'@example.invalid','phone'=>''], 'payment_method'=>'atlas_simulator','payment_data'=>[]]);
$checkoutData=$checkout->get_data(); $orderId=(int)($checkoutData['order_id'] ?? 0);
$check($checkout->get_status()>=200 && $checkout->get_status()<300 && $orderId>0,'Store API checkout invokes local-only payment simulator');
if ($checkout->get_status()>=400 || !$orderId) { echo 'Store checkout response: '.wp_json_encode($checkoutData)."\n"; }
$order=$orderId ? wc_get_order($orderId) : false; $booking=\Atlas\Core\Booking::get($hold['booking_id'],$user); $lineFound=false;
if ($order) { foreach ($order->get_items('line_item') as $item) { if ($item->get_meta('_atlas_booking_id',true)===$hold['booking_id']) { $lineFound=true; } } }
$check($order instanceof WC_Order && $lineFound && is_array($booking) && (int)$booking['order_id']===$orderId && $booking['status']==='paid_pending_confirmation','Woo order CRUD stores booking linkage; payment and confirmation stay separate');
$check($order instanceof WC_Order && $order->get_currency()==='IRR' && (string)$order->get_total()==='1000000' && $order->is_paid(),'simulated payment matches integer IRR and marks Woo order paid');
if ($order) {
    $bad=wc_create_order(['customer_id'=>$user]); $bad->set_currency('IRR'); $item=new WC_Order_Item_Product(); $item->set_product(wc_get_product($serviceId)); $item->set_quantity(1); $item->set_subtotal('999999'); $item->set_total('999999'); $item->add_meta_data('_atlas_booking_id',$hold['booking_id'],true); $bad->add_item($item); $bad->set_total('999999'); $bad->save();
    $gateway=new Atlas_Gateway_Simulator(); $badResult=$gateway->process_payment($bad->get_id());
    $check(($badResult['result'] ?? '')==='failure' && !$bad->is_paid(),'simulator rejects mismatched amount/order linkage');
} else { $check(false,'simulator rejects mismatched amount/order linkage'); }
global $wpdb;
$payment=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('payments').' WHERE booking_id=%s AND status=%s',$hold['booking_id'],'verified'));
wp_set_current_user($admin);
if ($payment) {
    $request=Finance::request_refund($payment->id,400000,$admin,'refund-'.$suffix,'Fictional manual review');
    $repeat=Finance::request_refund($payment->id,400000,$admin,'refund-'.$suffix,'Fictional manual review');
    $changed=Finance::request_refund($payment->id,300000,$admin,'refund-'.$suffix,'Fictional manual review');
    $check(is_array($request) && is_array($repeat) && $request['refund_id']===$repeat['refund_id'] && is_wp_error($changed),'refund intent replay is idempotent; changed payload conflicts');
    if (is_array($request)) {
        $approval=Finance::approve($request['refund_id'],$admin,'Fictional authorized approval');
        $excess=Finance::request_refund($payment->id,700000,$admin,'refund-excess-'.$suffix,'Fictional excess test');
        $proof=VerifiedRefund::from_adapter('local_simulator','refund-sim-'.$suffix,400000);
        $refund=$proof instanceof VerifiedRefund ? Finance::verify_success($request['refund_id'],$proof) : $proof;
        $again=$proof instanceof VerifiedRefund ? Finance::verify_success($request['refund_id'],$proof) : $proof;
        $ledger=Finance::ledger($payment->id,$admin); $sum=0; if (is_array($ledger)) { foreach ($ledger as $entry) { $sum+=(int)$entry['amount_irr']; } }
        $check(is_array($approval) && $approval['status']==='approved' && is_wp_error($excess),'refund approval is separate and over-refund is denied');
        $check(is_array($refund) && $refund['status']==='succeeded' && is_array($again) && is_array($ledger) && count($ledger)===2 && $sum===600000,'simulated refund proof replay creates one append-only debit');
        $check($order->is_paid(),'domain refund state does not silently rewrite Woo order');
    } else { $check(false,'refund approval is separate and over-refund is denied'); $check(false,'simulated refund proof replay creates one append-only debit'); $check(false,'domain refund state does not silently rewrite Woo order'); }
} else { $check(false,'refund intent replay is idempotent; changed payload conflicts'); $check(false,'refund approval is separate and over-refund is denied'); $check(false,'simulated refund proof replay creates one append-only debit'); $check(false,'domain refund state does not silently rewrite Woo order'); }
DB::set_test_now(null);
echo "Atlas Woo checks: $passed passed, $failed failed\n";
if ($failed) { WP_CLI::error('Atlas Woo integration checks failed.'); }
WP_CLI::success('Atlas Woo integration checks passed.');
