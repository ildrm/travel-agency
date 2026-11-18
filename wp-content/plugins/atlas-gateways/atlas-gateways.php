<?php
/**
 * Plugin Name: Atlas Gateways
 * Description: Prepared ZarinPal V4, disabled provider boundaries and local-only simulation.
 * Version: 0.2.0
 * WC tested up to: 11.3.0
 * Text Domain: atlas-gateways
 * Requires Plugins: woocommerce, atlas-core
 */
if (!defined('ABSPATH')) { exit; }
// Tested framework/local transport compatibility; live PSP acceptance is separate.
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});
add_action('init', static function () {
    load_plugin_textdomain('atlas-gateways', false, dirname(plugin_basename(__FILE__)) . '/languages');
}, 1);

/** A deployment setting cannot turn a non-local environment into a payment simulator. */
function atlas_gateways_demo_allowed() {
    return function_exists('wp_get_environment_type')
        && wp_get_environment_type() === 'local'
        && defined('ATLAS_DEMO_MODE') && ATLAS_DEMO_MODE === true;
}

add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) { return; }
    require_once __DIR__ . '/zarinpal.php';
    require_once __DIR__ . '/gateways.php';
    add_action('init', array('Atlas_ZarinPal','install'),5);
    add_action('rest_api_init', array('Atlas_ZarinPal','routes'));
    add_action('woocommerce_api_atlas_zarinpal',array('Atlas_ZarinPal','callback_http'));
    add_action('admin_menu',array('Atlas_ZarinPal','admin_menu'));
    add_action('admin_post_atlas_zarinpal_reconcile',array('Atlas_ZarinPal','admin_reconcile'));
    add_filter('atlas_core_verified_service_gateway',static function($allowed,$method) { return $method==='atlas_zarinpal'?Atlas_ZarinPal::ready():$allowed; },10,2);
    add_filter('woocommerce_available_payment_gateways',array('Atlas_ZarinPal','guarded_gateways'),PHP_INT_MAX);
    add_action('woocommerce_before_pay_action',array('Atlas_ZarinPal','guard_order_pay'),1);
    add_action('woocommerce_checkout_order_processed',array('Atlas_ZarinPal','guard_classic_order'),0,3);
    add_action('woocommerce_rest_checkout_process_payment_with_context',array('Atlas_ZarinPal','guard_store_payment'),-1,1);
    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'Atlas_Gateway_SEP';
        $gateways[] = 'Atlas_Gateway_Mellat';
        $gateways[] = 'Atlas_Gateway_ZarinPal';
        if (atlas_gateways_demo_allowed()) { $gateways[] = 'Atlas_Gateway_Simulator'; }
        return $gateways;
    });
}, 20);

add_action('woocommerce_blocks_loaded', function () {
    if (!class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')) { return; }
    require_once __DIR__ . '/blocks.php';
    add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
        if (atlas_gateways_demo_allowed()) { $registry->register(new Atlas_Simulator_Blocks()); }
        $registry->register(new Atlas_ZarinPal_Blocks());
    });
});
