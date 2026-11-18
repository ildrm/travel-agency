<?php
// Export route metadata only, never tokens, user data or secret settings.
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Local WP-CLI required.');
}
$out = [];
foreach (rest_get_server()->get_routes() as $route => $endpoints) {
    if (!str_starts_with($route, '/agency/') && !str_starts_with($route, '/atlas-inventory/')) { continue; }
    foreach ($endpoints as $endpoint) {
        if (!is_array($endpoint) || !isset($endpoint['methods'])) { continue; }
        $out[] = [
            'route' => $route,
            'methods' => array_keys(array_filter($endpoint['methods'])),
            'permission_callback_present' => isset($endpoint['permission_callback']) && is_callable($endpoint['permission_callback']),
            'registered_argument_names' => array_keys($endpoint['args'] ?? []),
        ];
    }
}
$target = dirname(__DIR__) . '/implementation/API-CONTRACTS/registered-routes.json';
file_put_contents($target, wp_json_encode(['captured_at' => gmdate('c'), 'routes' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
WP_CLI::success('Exported actual route metadata: ' . count($out));
