<?php
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local') {
    throw new RuntimeException('This helper is restricted to the isolated local runtime.');
}
if (!class_exists('Atlas\Core\DB')) { WP_CLI::error('Activate Atlas Core first.'); }
$result = \Atlas\Core\DB::migrate();
if (is_wp_error($result)) { WP_CLI::error($result->get_error_message()); }
if (class_exists('Atlas\Inventory\Local')) {
    $result = \Atlas\Inventory\Local::migrate();
    if (is_wp_error($result)) { WP_CLI::error($result->get_error_message()); }
}
if (class_exists('Atlas_Event_Checkins')) { Atlas_Event_Checkins::install(); }
WP_CLI::success('Installed local companion schemas are current.');
