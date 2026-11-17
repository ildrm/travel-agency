<?php
/**
 * Plugin Name: Atlas Gateways
 * Description: Disabled provider boundaries and a local-only simulated payment method.
 * Version: 0.1.0
 * Text Domain: atlas-gateways
 * Requires Plugins: woocommerce, atlas-core
 */
if (!defined('ABSPATH')) { exit; }

/** A deployment setting cannot turn a non-local environment into a payment simulator. */
function atlas_gateways_demo_allowed() {
    return function_exists('wp_get_environment_type')
        && wp_get_environment_type() === 'local'
        && defined('ATLAS_DEMO_MODE') && ATLAS_DEMO_MODE === true;
}

add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) { return; }
    require_once __DIR__ . '/gateways.php';
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
    });
});
