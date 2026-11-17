<?php
/** Run only with a disposable local WP/Woo installation; no real transactions. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local') { throw new RuntimeException('Local WP-CLI only.'); }
if (!function_exists('atlas_gateways_demo_allowed') || !class_exists('Atlas_Gateway_SEP')) WP_CLI::error('Activate WooCommerce, Atlas Core and Atlas Gateways.');
if (function_exists('WC') && !WC()->session) WC()->initialize_session();
$check=function ($value,$message) { if (!$value) throw new RuntimeException($message); };
foreach (array('Atlas_Gateway_SEP','Atlas_Gateway_Mellat','Atlas_Gateway_ZarinPal') as $type) {
    $gateway=new $type();
    $check(!$gateway->is_available(),$type.' must be disabled.');
    $check($gateway->process_payment(0)['result']==='failure',$type.' must reject forced use.');
}
$check(atlas_gateways_demo_allowed() === (defined('ATLAS_DEMO_MODE') && ATLAS_DEMO_MODE === true),'Simulator gate must require explicit demo mode and local.');
if (atlas_gateways_demo_allowed()) {
    $sim=new Atlas_Gateway_Simulator();
    wp_set_current_user(0);
    $check($sim->process_payment(0)['result']==='failure','Anonymous simulator use must fail.');
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $user=wp_insert_user(array('user_login'=>'atlas-gateway-'.wp_generate_password(12,false),'user_pass'=>wp_generate_password(30),'role'=>'subscriber'));
    if (is_wp_error($user)) throw new RuntimeException('Fixture customer could not be created.');
    $product=null; $order=null;
    try {
        wp_set_current_user($user);
        $product=new WC_Product_Simple(); $product->set_name('Fictional local-only souvenir'); $product->set_regular_price('1000000'); $product->set_price('1000000'); $product->set_status('publish'); $product->save();
        $order=wc_create_order(array('customer_id'=>$user)); if (is_wp_error($order)) throw new RuntimeException('Fixture order failed.');
        $order->set_currency('IRR'); $order->add_product($product,1); $order->set_total('1000000'); $order->save();
        $result=$sim->process_payment($order->get_id());
        $check($result['result']==='success','Local simulated physical order should succeed.');
        $check(wc_get_order($order->get_id())->is_paid(),'Simulated order should be marked paid in this local test only.');
    } finally {
        if ($order && !is_wp_error($order)) $order->delete(true);
        if ($product) $product->delete(true);
        wp_delete_user($user); wp_set_current_user(0);
    }
}
WP_CLI::log('Gateway disabled and local gate checks passed; no external requests were made.');
