<?php
namespace Atlas\Core;

if (!defined('ABSPATH')) { exit; }

/** Read-only deployment checks. Calls no provider and emits no configured secrets. */
final class Preflight {
    public static function checks(bool $production, bool $servicePayments): array {
        $checkout = function_exists('wc_get_page_id') ? get_post(wc_get_page_id('checkout')) : null;
        $worker = get_option('atlas_core_worker_heartbeat', []);
        $workerOk = is_array($worker) && !empty($worker['finished_at'])
            && ($worker['errors'] ?? null) === 0;
        $checks = [
            'atlas_theme_active' => get_template() === 'atlas-leisure',
            'core_schema' => DB::ready() === true,
            'operations_ready' => did_action('atlas_operations_ready') > 0,
            'inventory_schema' => class_exists(\Atlas\Inventory\Local::class)
                && get_option('atlas_local_inventory_schema') === '3',
            'woocommerce_active' => class_exists(\WooCommerce::class),
            'gateways_active' => class_exists(\Atlas_ZarinPal::class),
            'package_versions_aligned' => self::package_versions_aligned(),
            'checkout_blocks_published' => $checkout instanceof \WP_Post && $checkout->post_status === 'publish'
                && has_block('woocommerce/checkout', $checkout),
            'whole_irr' => function_exists('get_woocommerce_currency') && get_woocommerce_currency() === 'IRR'
                && wc_get_price_decimals() === 0,
        ];
        if ($production) {
            $checks['production_environment'] = wp_get_environment_type() === 'production';
            $checks['https_configured'] = wp_parse_url(home_url('/'), PHP_URL_SCHEME) === 'https';
            $checks['demo_mode_disabled'] = !defined('ATLAS_DEMO_MODE') || ATLAS_DEMO_MODE !== true;
            $checks['debug_display_disabled'] = !defined('WP_DEBUG') || !WP_DEBUG
                || (defined('WP_DEBUG_DISPLAY') && !WP_DEBUG_DISPLAY);
            $checks['worker_success_recorded'] = $workerOk;
        }
        if ($servicePayments) {
            $checks['zarinpal_ready_and_enabled'] = class_exists(\Atlas_ZarinPal::class)
                && \Atlas_ZarinPal::ready()
                && (get_option('woocommerce_atlas_zarinpal_settings', [])['enabled'] ?? '') === 'yes'
                && (!$production || (defined('ATLAS_ZARINPAL_MODE') && ATLAS_ZARINPAL_MODE === 'live'));
        }
        return $checks;
    }

    private static function package_versions_aligned(): bool {
        $theme = wp_get_theme(get_template());
        if ($theme->get('Version') !== ATLAS_CORE_VERSION) return false;
        $plugins = [
            \Atlas_Operations::class => 'atlas-operations.php',
            \Atlas_ZarinPal::class => 'atlas-gateways.php',
            \Atlas\Inventory\Local::class => 'atlas-inventory.php',
        ];
        foreach ($plugins as $class => $entry) {
            if (!class_exists($class)) return false;
            $source = (new \ReflectionClass($class))->getFileName();
            if (!$source) return false;
            $header = get_file_data(dirname($source).'/'.$entry, ['version'=>'Version']);
            if ($header['version'] !== ATLAS_CORE_VERSION) return false;
        }
        return true;
    }

}
