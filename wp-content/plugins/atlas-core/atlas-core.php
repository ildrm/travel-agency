<?php
/**
 * Plugin Name: Atlas Core
 * Description: Transactional booking, organization permissions, records, and WooCommerce bridge for Atlas.
 * Version: 0.1.0
 * Requires PHP: 8.2
 * Text Domain: atlas-core
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) { exit; }
define('ATLAS_CORE_VERSION', '0.1.0');
define('ATLAS_CORE_PATH', __DIR__);
foreach (['DB', 'Auth', 'Records', 'Eligibility', 'Offers', 'Money', 'Booking', 'Finance', 'Woo', 'Rest', 'Privacy'] as $file) {
    if (!is_readable(__DIR__ . '/src/' . $file . '.php')) {
        add_action('admin_notices', static function (): void { echo '<div class="notice notice-error"><p>Atlas Core is incomplete and has not started.</p></div>'; });
        return;
    }
}
foreach (['DB', 'Auth', 'Records', 'Eligibility', 'Offers', 'Money', 'Booking', 'Finance', 'Woo', 'Rest', 'Privacy'] as $file) { require_once __DIR__ . '/src/' . $file . '.php'; }
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
