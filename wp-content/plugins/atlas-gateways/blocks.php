<?php
if (!defined('ABSPATH')) { exit; }
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
class Atlas_Simulator_Blocks extends AbstractPaymentMethodType {
    protected $name = 'atlas_simulator';
    public function initialize() {}
    public function is_active() { return atlas_gateways_demo_allowed(); }
    public function get_payment_method_script_handles() {
        wp_register_script('atlas-simulator-blocks', plugins_url('assets/simulator.js', __FILE__), array('wc-blocks-registry','wc-settings','wp-element'), '0.1.0', true);
        return array('atlas-simulator-blocks');
    }
    public function get_payment_method_data() {
        return array('title' => __('Simulated payment (local only)', 'atlas-gateways'), 'description' => __('No funds are transferred.', 'atlas-gateways'), 'supports' => array('products'));
    }
}
class Atlas_ZarinPal_Blocks extends AbstractPaymentMethodType {
    protected $name='atlas_zarinpal';
    public function initialize() {}
    public function is_active() { return class_exists('Atlas_ZarinPal') && Atlas_ZarinPal::ready(); }
    public function get_payment_method_script_handles() {
        wp_register_script('atlas-zarinpal-blocks',plugins_url('assets/zarinpal.js',__FILE__),array('wc-blocks-registry','wc-settings','wp-element'),'0.1.0',true);
        return array('atlas-zarinpal-blocks');
    }
    public function get_payment_method_data() {
        return array('title'=>Atlas_ZarinPal::t('ZarinPal secure payment','پرداخت امن زرین‌پال'),'description'=>Atlas_ZarinPal::t('You will continue to ZarinPal. Amounts are in IRR; service confirmation remains separate.','برای ادامه به زرین‌پال منتقل می‌شوید. مبالغ به ریال هستند؛ تأیید خدمت جداگانه است.'),'supports'=>array('products'),'enabled'=>Atlas_ZarinPal::ready());
    }
}
