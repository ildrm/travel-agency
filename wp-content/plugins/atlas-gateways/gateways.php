<?php
if (!defined('ABSPATH')) { exit; }

/** Production adapters cannot be enabled without a real verified provider contract. */
abstract class Atlas_Disabled_Gateway extends WC_Payment_Gateway {
    protected function setup($id, $title) {
        $this->id = $id;
        $this->method_title = $title;
        $this->method_description = __('Unavailable: provider contract, security verification and merchant approval are required.', 'atlas-gateways');
        $this->title = $title;
        $this->enabled = 'no';
        $this->supports = array('products');
    }
    public function is_available() { return false; }
    public function process_payment($order_id) {
        wc_add_notice(__('This payment method is unavailable.', 'atlas-gateways'), 'error');
        return array('result' => 'failure');
    }
}
class Atlas_Gateway_SEP extends Atlas_Disabled_Gateway {
    public function __construct() { $this->setup('atlas_sep', 'SEP / Saman'); }
}
class Atlas_Gateway_Mellat extends Atlas_Disabled_Gateway {
    public function __construct() { $this->setup('atlas_mellat', 'Behpardakht Mellat'); }
}
class Atlas_Gateway_ZarinPal extends Atlas_Disabled_Gateway {
    public function __construct() { $this->setup('atlas_zarinpal', 'ZarinPal'); }
}

class Atlas_Gateway_Simulator extends WC_Payment_Gateway {
    public function __construct() {
        $this->id = 'atlas_simulator';
        $this->method_title = __('Atlas local simulator', 'atlas-gateways');
        $this->title = __('Simulated payment (local only)', 'atlas-gateways');
        $this->description = __('No funds are transferred. This method is available only in an explicitly enabled local environment.', 'atlas-gateways');
        $this->enabled = atlas_gateways_demo_allowed() ? 'yes' : 'no';
        $this->supports = array('products');
        $this->has_fields = false;
    }
    public function is_available() { return atlas_gateways_demo_allowed() && parent::is_available(); }
    public function process_payment($order_id) {
        if (!atlas_gateways_demo_allowed() || !is_user_logged_in()) {
            wc_add_notice(__('Simulated payment is unavailable.', 'atlas-gateways'), 'error');
            return array('result' => 'failure');
        }
        $order = wc_get_order($order_id);
        if (!$order || (int)$order->get_customer_id() !== get_current_user_id() || $order->get_currency() !== 'IRR') {
            wc_add_notice(__('The order cannot be paid with this method.', 'atlas-gateways'), 'error');
            return array('result' => 'failure');
        }
        $total = (string)$order->get_total();
        if (!preg_match('/^(0|[1-9][0-9]*)(?:\.0+)?$/', $total, $m) || strlen($m[1]) > 15) {
            wc_add_notice(__('The order amount is invalid.', 'atlas-gateways'), 'error');
            return array('result' => 'failure');
        }
        $amount = (int)$m[1];
        $bookings = array();
        foreach ($order->get_items() as $item) {
            $id = (string)$item->get_meta('_atlas_booking_id', true);
            $is_service = (int)get_post_meta((int)$item->get_product_id(), '_atlas_offer_id', true) > 0;
            if ($is_service && $id === '') {
                wc_add_notice(__('A valid reservation is required for this order.', 'atlas-gateways'), 'error');
                return array('result' => 'failure');
            }
            if ($id !== '') $bookings[$id] = true;
        }
        if (count($bookings) > 1) {
            wc_add_notice(__('This order needs separate payment.', 'atlas-gateways'), 'error');
            return array('result' => 'failure');
        }
        if ($bookings) {
            $booking_id = (string)array_key_first($bookings);
            if (!class_exists('Atlas\Core\Booking') || !class_exists('Atlas\Core\VerifiedPayment')) {
                wc_add_notice(__('Booking service is unavailable.', 'atlas-gateways'), 'error');
                return array('result' => 'failure');
            }
            $booking = \Atlas\Core\Booking::get($booking_id, get_current_user_id());
            if (is_wp_error($booking) || (int)$booking['amount_irr'] !== $amount || (int)$booking['order_id'] !== (int)$order->get_id()) {
                wc_add_notice(__('The order and reservation do not match.', 'atlas-gateways'), 'error');
                return array('result' => 'failure');
            }
            $proof = \Atlas\Core\VerifiedPayment::from_adapter('local_simulator', 'order-' . $order->get_id(), $amount);
            if (is_wp_error($proof)) { wc_add_notice(__('Simulation could not be verified.', 'atlas-gateways'), 'error'); return array('result'=>'failure'); }
            $result = \Atlas\Core\Booking::verify($booking_id, $proof);
            if (is_wp_error($result) || $result['status'] !== 'paid_pending_confirmation') {
                wc_add_notice(__('The reservation requires manual review.', 'atlas-gateways'), 'error');
                return array('result' => 'failure');
            }
        }
        if (!$order->is_paid()) $order->payment_complete('atlas-simulated-' . $order->get_id());
        if (function_exists('WC') && WC()->cart) WC()->cart->empty_cart();
        return array('result'=>'success','redirect'=>$this->get_return_url($order));
    }
}
