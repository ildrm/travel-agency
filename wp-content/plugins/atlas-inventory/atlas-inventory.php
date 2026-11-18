<?php
/**
 * Plugin Name: Atlas Local Inventory
 * Description: Authorized internal room-night and vehicle scheduling. No public sales or supplier connection.
 * Version: 0.2.0
 * Requires PHP: 8.2
 * Text Domain: atlas-inventory
 */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/local.php';
register_activation_hook(__FILE__, function () {
    if (!class_exists('Atlas\Core\DB')) { wp_die(esc_html__('Activate Atlas Core first.', 'atlas-inventory')); }
    $ready=\Atlas\Core\DB::ready(); if (is_wp_error($ready)) { wp_die(esc_html($ready->get_error_message())); }
    $result=\Atlas\Inventory\Local::migrate(); if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message())); }
});
add_action('rest_api_init', function () {
    if (class_exists('Atlas\Core\DB')) { \Atlas\Inventory\Routes::register(); }
});
