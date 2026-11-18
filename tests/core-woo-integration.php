<?php
/** Local-only WP-CLI integration; uses fictional products, orders and simulated payment/refund proof. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='local' || !defined('ATLAS_DEMO_MODE') || ATLAS_DEMO_MODE!==true) { throw new RuntimeException('Isolated local WP-CLI with explicit simulator mode required.'); }
if (!defined('ATLAS_TEST_CLOCK')) { define('ATLAS_TEST_CLOCK',true); }
use Atlas\Core\DB;
use Atlas\Core\Offers;
use Atlas\Core\Finance;
use Atlas\Core\VerifiedRefund;
use Atlas\Core\Woo;
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
$offerFields=['kind'=>'event','org_id'=>0,'title_en'=>'Fictional Store API event','title_fa'=>'','description_en'=>'','description_fa'=>'','registration_open_at'=>'2027-06-01T05:30:00.000000Z','registration_close_at'=>'2027-06-15T14:30:00.000000Z','timezone'=>'Asia/Tehran','capacity'=>3,'amount_irr'=>1000000,'mode'=>'local','status'=>'published','product_id'=>$serviceId];
$offer=Offers::save(0,$offerFields,$admin);
if (is_wp_error($offer)) { WP_CLI::error('Could not create local offer: '.$offer->get_error_message()); }
$check((int)get_post_meta($serviceId,'_atlas_offer_id',true)===$offer,'normal offer save records the reciprocal Woo service product without fixture metadata');
$duplicate=Offers::save(0,array_replace($offerFields,array('title_en'=>'Fictional duplicate service link')),$admin);
$check(is_wp_error($duplicate) && $duplicate->get_error_code()==='atlas_product_in_use' && (int)get_post_meta($serviceId,'_atlas_offer_id',true)===$offer,'another offer cannot overwrite a product binding');
$invalid=Offers::save(0,array_replace($offerFields,array('product_id'=>$physicalId)),$admin);
$check(is_wp_error($invalid) && $invalid->get_error_code()==='atlas_invalid_service_product' && !get_post_meta($physicalId,'_atlas_offer_id',true),'physical merchandise cannot be silently converted into a service product');
$historicalProduct=new WC_Product_Simple();
$historicalProduct->set_name('Fictional downloadable merchandise '.$suffix); $historicalProduct->set_virtual(true); $historicalProduct->set_regular_price('250000'); $historicalId=$historicalProduct->save();
$historicalOrder=wc_create_order(array('customer_id'=>$user));
$historicalOrder->add_product($historicalProduct,1); $historicalOrder->set_payment_method('bacs'); $historicalOrder->save();
$retroactive=Offers::save(0,array_replace($offerFields,array('product_id'=>$historicalId)),$admin);
$check(is_wp_error($retroactive) && $retroactive->get_error_code()==='atlas_product_history' && !get_post_meta($historicalId,'_atlas_offer_id',true),'a historical ordinary Woo virtual order prevents retroactive service product binding');
$historicalOrder->delete(true); $historicalProduct->delete(true);
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
$savedPost=$_POST; $_POST['atlas_booking_id']=$hold['booking_id'];
$missingBooking=$api('POST','/wc/store/v1/cart/add-item',['id'=>$serviceId,'quantity'=>1]);
$check($missingBooking->get_status()===400 && WC()->cart->get_cart_contents_count()===0,'Store API requires its own booking token and cannot fall back to POST');
$arrayBooking=$api('POST','/wc/store/v1/cart/add-item',['id'=>$serviceId,'quantity'=>1,'atlas_booking_id'=>[$hold['booking_id']]]);
$malformedBooking=$api('POST','/wc/store/v1/cart/add-item',['id'=>$serviceId,'quantity'=>1,'atlas_booking_id'=>'<b>'.$hold['booking_id'].'</b>']);
$check($arrayBooking->get_status()===400 && $malformedBooking->get_status()===400 && WC()->cart->get_cart_contents_count()===0,'Store API rejects array or malformed reservation tokens before adding a service');
$_POST=$savedPost; wc_clear_notices();
$add=$api('POST','/wc/store/v1/cart/add-item',['id'=>$serviceId,'quantity'=>1,'atlas_booking_id'=>$hold['booking_id']]);
$check($add->get_status()>=200 && $add->get_status()<300 && WC()->cart->get_cart_contents_count()===1,'Store API adds held service to cart');
if ($add->get_status()>=400) { echo 'Store cart response: '.wp_json_encode($add->get_data())."\n"; }
$cartSnapshot=static function(): array {
    $snapshot=[];
    foreach (WC()->cart->get_cart() as $key=>$line) { $snapshot[$key]=['product_id'=>$line['product_id'],'variation_id'=>$line['variation_id'],'quantity'=>$line['quantity'],'atlas_booking_id'=>$line['atlas_booking_id'] ?? null]; }
    return $snapshot;
};
$originalCart=$cartSnapshot();
$otherHold=\Atlas\Core\Booking::hold($offer,1,1,$user,'cart-conflict-'.$suffix);
$check(is_array($otherHold) && $otherHold['booking_id']!==$hold['booking_id'],'same service conflict fixture has a separate valid reservation');
if (is_array($otherHold)) {
    $conflict=$api('POST','/wc/store/v1/cart/add-item',['id'=>$serviceId,'quantity'=>1,'atlas_booking_id'=>$otherHold['booking_id']]);
    $check($conflict->get_status()===400 && $cartSnapshot()===$originalCart,'Store API rejects a different booking for the same offer and preserves the existing cart');
    $_POST['atlas_booking_id']=$otherHold['booking_id']; wc_clear_notices();
    $classicConflict=apply_filters('woocommerce_add_to_cart_validation',true,$serviceId,1);
    $check($classicConflict===false && wc_notice_count('error')>0 && $cartSnapshot()===$originalCart,'classic three-argument validation rejects a different booking without changing the cart');
} else { $check(false,'Store API same offer booking conflict'); $check(false,'classic same offer booking conflict'); }
$_POST=$savedPost; wc_clear_notices();
$duplicate=$api('POST','/wc/store/v1/cart/add-item',['id'=>$serviceId,'quantity'=>1,'atlas_booking_id'=>$hold['booking_id']]);
$check($duplicate->get_status()===400 && $cartSnapshot()===$originalCart,'Store API rejects a repeated booking add before increasing its line quantity');
$_POST['atlas_booking_id']=$hold['booking_id']; wc_clear_notices();
$classicDuplicate=apply_filters('woocommerce_add_to_cart_validation',true,$serviceId,1,0,[]);
$check($classicDuplicate===false && wc_notice_count('error')>0 && $cartSnapshot()===$originalCart,'classic five-argument validation rejects repeated booking add before increasing quantity');
$_POST['atlas_booking_id']=[$hold['booking_id']]; wc_clear_notices();
$classicArray=apply_filters('woocommerce_add_to_cart_validation',true,$serviceId,1);
unset($_POST['atlas_booking_id']);
$classicMissing=apply_filters('woocommerce_add_to_cart_validation',true,$serviceId,1);
$check($classicArray===false && $classicMissing===false && $cartSnapshot()===$originalCart,'classic missing or array booking tokens are denied without cart changes');
$_POST=$savedPost; wc_clear_notices();
$mixed=$api('POST','/wc/store/v1/cart/add-item',['id'=>$physicalId,'quantity'=>1]);
$check($mixed->get_status()>=400 && WC()->cart->get_cart_contents_count()===1,'Store API rejects mixed service/physical cart without discarding it');
WC()->cart->calculate_totals();
$gateways=WC()->payment_gateways()->payment_gateways(); $bacs=$gateways['bacs']; $previousBacsEnabled=$bacs->enabled; $bacs->enabled='yes';
$available=WC()->payment_gateways()->get_available_payment_gateways();
$check(!isset($available['bacs']) && !isset($available['cod']) && isset($available['atlas_simulator']),'service cart excludes native unverified gateways and permits local simulator');
$billing=['first_name'=>'Fictional','last_name'=>'Customer','address_1'=>'123 Sample Road','city'=>'Tehran','state'=>'THR','postcode'=>'1234567890','country'=>'IR','email'=>'atlas_'.$suffix.'@example.invalid','phone'=>''];
$unsupported=$api('POST','/wc/store/v1/checkout',['billing_address'=>$billing,'payment_method'=>'bacs','payment_data'=>[]]);
global $wpdb;
$unverifiedCount=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('payments').' WHERE booking_id=%s',$hold['booking_id']));
$check($unsupported->get_status()>=400 && $unverifiedCount===0,'Store API rejects bank transfer for a service before creating payment evidence');
$checkout=$api('POST','/wc/store/v1/checkout',['billing_address'=>$billing,'payment_method'=>'atlas_simulator','payment_data'=>[]]);
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
// Existing-order payment must inspect order lines even with an empty cart. The
// sentinel aborts before every gateway adapter, so this test never takes money.
$policyOrder=wc_create_order(['customer_id'=>$user]); $policyOrder->set_currency('IRR'); $policyOrder->add_product(wc_get_product($serviceId),1); $policyOrder->set_total('1000000'); $policyOrder->save();
$physicalOrder=wc_create_order(['customer_id'=>$user]); $physicalOrder->add_product(wc_get_product($physicalId),1); $physicalOrder->calculate_totals(); $physicalOrder->save();
global $wp;
$savedOrderPay=$wp->query_vars['order-pay'] ?? null; $savedPost=$_POST; WC()->cart->empty_cart();
$wp->query_vars['order-pay']=$policyOrder->get_id();
$available=WC()->payment_gateways()->get_available_payment_gateways();
$check(!isset($available['bacs']) && isset($available['atlas_simulator']),'order-pay excludes bank transfer for service order when cart is empty');
$_POST['payment_method']='bacs'; wc_clear_notices(); do_action('woocommerce_before_pay_action',$policyOrder);
$check(wc_notice_count('error')>0,'classic order-pay refuses unverified service method before gateway dispatch');
do_action('woocommerce_after_pay_action',$policyOrder); wc_clear_notices(); $_POST=$savedPost;
$wp->query_vars['order-pay']=$physicalOrder->get_id();
$available=WC()->payment_gateways()->get_available_payment_gateways();
$check(isset($available['bacs']),'physical order-pay retains enabled native bank transfer');
if ($savedOrderPay===null) { unset($wp->query_vars['order-pay']); } else { $wp->query_vars['order-pay']=$savedOrderPay; }
$context=new \Automattic\WooCommerce\StoreApi\Payments\PaymentContext(); $context->set_order($policyOrder); $context->set_payment_method('bacs');
$paymentResult=new \Automattic\WooCommerce\StoreApi\Payments\PaymentResult(); $gatewayDispatch=false; $serviceRejected=false;
$sentinel=static function() use (&$gatewayDispatch): void { $gatewayDispatch=true; throw new RuntimeException('Test stopped before any payment adapter.'); };
add_action('woocommerce_rest_checkout_process_payment_with_context',$sentinel,1,0);
try { do_action_ref_array('woocommerce_rest_checkout_process_payment_with_context',[$context,&$paymentResult]); }
catch (Exception $e) { $serviceRejected=!$gatewayDispatch; }
finally { remove_action('woocommerce_rest_checkout_process_payment_with_context',$sentinel,1); }
$check($serviceRejected && !wc_get_order($policyOrder->get_id())->is_paid(),'Blocks existing-order payment rejects bank transfer before any payment adapter');
$context->set_order($physicalOrder); $physicalAllowed=true;
try { Woo::guard_store_payment($context); } catch (Exception $e) { $physicalAllowed=false; }
$check($physicalAllowed,'Blocks payment policy preserves physical merchandise methods');
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
// A pre-existing unpaid order must not initiate even a simulated charge once its
// hold deadline has elapsed. Keep callback reconciliation separate from initiation.
wp_set_current_user($user); DB::set_test_now('2027-06-01T06:00:00.000000Z');
WC()->cart->empty_cart();
$lateHold=\Atlas\Core\Booking::hold($offer,1,1,$user,'late-initiation-'.$suffix);
if (is_array($lateHold)) {
    $added=$api('POST','/wc/store/v1/cart/add-item',['id'=>$serviceId,'quantity'=>1,'atlas_booking_id'=>$lateHold['booking_id']]);
    WC()->cart->calculate_totals();
    $lateOrder=wc_create_order(['customer_id'=>$user]);
    if (!is_wp_error($lateOrder)) {
        $lateOrder->set_currency('IRR'); $lateOrder->set_payment_method('atlas_simulator'); $lateOrder->add_product(wc_get_product($serviceId),1); $lateOrder->set_total('1000000'); $lateOrder->save();
        \Atlas\Core\Woo::store_order($lateOrder);
        DB::set_test_now($lateHold['payment_deadline']);
        $gateway=new Atlas_Gateway_Simulator(); $denied=$gateway->process_payment($lateOrder->get_id());
        $attempts=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('payments').' WHERE booking_id=%s',$lateHold['booking_id']));
        $check(($denied['result'] ?? '')==='failure' && $attempts===0 && !wc_get_order($lateOrder->get_id())->is_paid(),'simulator rejects initiation at deadline before creating verified-money evidence');
        \Atlas\Core\Booking::expire($lateHold['hold_id']);
        $releasedDenied=$gateway->process_payment($lateOrder->get_id());
        $attempts=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('payments').' WHERE booking_id=%s',$lateHold['booking_id']));
        $check(($releasedDenied['result'] ?? '')==='failure' && $attempts===0,'released allocation cannot initiate a simulated payment through order retry');
    } else { $check(false,'late initiation order fixture'); }
} else { $check(false,'late initiation hold fixture'); }
if ($order && $payment) {
    wp_set_current_user($admin);
    $confirmed=\Atlas\Core\Booking::confirm($hold['booking_id'],$admin,'Fictional agency confirmation','woo-confirm-'.$suffix);
    wp_set_current_user($user);
    $beforePayments=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('payments').' WHERE booking_id=%s',$hold['booking_id']));
    $beforeLedger=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('ledger').' WHERE payment_id=%s',$payment->id));
    $gateway=new Atlas_Gateway_Simulator(); $receipt=$gateway->process_payment($order->get_id());
    $afterPayments=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('payments').' WHERE booking_id=%s',$hold['booking_id']));
    $afterLedger=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('ledger').' WHERE payment_id=%s',$payment->id));
    $preserved=\Atlas\Core\Booking::get($hold['booking_id'],$user);
    $check(is_array($confirmed) && ($receipt['result'] ?? '')==='success' && $beforePayments===$afterPayments && $beforeLedger===$afterLedger && is_array($preserved) && $preserved['status']==='confirmed','paid confirmed order replay returns its receipt without a new proof or regressed entitlement');
}
WC()->cart->empty_cart();
$classicPhysical=apply_filters('woocommerce_add_to_cart_validation',true,$physicalId,1);
$physicalAdd=$api('POST','/wc/store/v1/cart/add-item',['id'=>$physicalId,'quantity'=>1]);
$check($classicPhysical===true && $physicalAdd->get_status()>=200 && $physicalAdd->get_status()<300 && WC()->cart->get_cart_contents_count()===1,'classic and Store API physical merchandise remain eligible without reservation tokens');
$available=WC()->payment_gateways()->get_available_payment_gateways();
$check(isset($available['bacs']),'physical merchandise cart retains enabled native bank transfer');
$beforeNativeRefund=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DB::table('ledger'));
$physicalOrder->set_payment_method('bacs'); $physicalOrder->set_status('on-hold'); $physicalOrder->save();
$nativeRefund=wc_create_refund(array('order_id'=>$physicalOrder->get_id(),'amount'=>250000,'reason'=>'Fictional manual bookkeeping refund','refund_payment'=>false,'restock_items'=>false));
$reloaded=wc_get_order($physicalOrder->get_id());
$afterNativeRefund=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DB::table('ledger'));
$check($nativeRefund instanceof WC_Order_Refund && (int)$nativeRefund->get_amount()===250000 && $reloaded->get_status()==='refunded' && !$reloaded->get_meta('_atlas_booking_id') && $beforeNativeRefund===$afterNativeRefund,'native physical manual refund changes the Woo order without a provider transaction or Atlas service ledger entry');
WC()->cart->empty_cart(); $bacs->enabled=$previousBacsEnabled;
DB::set_test_now(null);
echo "Atlas Woo checks: $passed passed, $failed failed\n";
if ($failed) { WP_CLI::error('Atlas Woo integration checks failed.'); }
WP_CLI::success('Atlas Woo integration checks passed.');
