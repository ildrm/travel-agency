<?php
/**
 * Plugin Name: Atlas Core
 * Description: Transactional booking, organization permissions, records, and WooCommerce bridge for Atlas.
 * Version: 0.2.0
 * Requires PHP: 8.2
 * WC tested up to: 11.3.0
 * Text Domain: atlas-core
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) { exit; }
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});
define('ATLAS_CORE_VERSION', '0.2.0');
define('ATLAS_CORE_PATH', __DIR__);
foreach (['DB', 'Auth', 'Records', 'Eligibility', 'Catalog', 'Offers', 'Money', 'Booking', 'Finance', 'Woo', 'Rest', 'Privacy'] as $file) {
    if (!is_readable(__DIR__ . '/src/' . $file . '.php')) {
        add_action('admin_notices', static function (): void { echo '<div class="notice notice-error"><p>Atlas Core is incomplete and has not started.</p></div>'; });
        return;
    }
}
foreach (['DB', 'Auth', 'Records', 'Eligibility', 'Catalog', 'Offers', 'Money', 'Booking', 'Finance', 'Woo', 'Rest', 'Privacy'] as $file) { require_once __DIR__ . '/src/' . $file . '.php'; }
register_activation_hook(__FILE__, ['Atlas\Core\DB', 'activate']);
add_action('init', static function (): void {
    \Atlas\Core\Offers::register_types();
    $ready = \Atlas\Core\DB::ready();
    if (is_wp_error($ready)) {
        add_action('admin_notices', static function () use ($ready): void {
            echo '<div class="notice notice-error"><p>' . esc_html($ready->get_error_message()) . '</p></div>';
        });
        return;
    }
    \Atlas\Core\Eligibility::register();
    \Atlas\Core\Woo::register();
    \Atlas\Core\Rest::register();
    \Atlas\Core\Privacy::register();
    do_action('atlas_core_ready');
}, 20);
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('atlas', \Atlas\Core\CLI::class);
}
