<?php
namespace Atlas\Core;
if (!defined('ABSPATH')) { exit; }
final class Woo {
    private static int $payOrderId=0;
    public static function register(): void {
        if (!class_exists('WooCommerce')) { return; }
        add_filter('woocommerce_add_to_cart_validation',[self::class,'add_validation'],10,6);
        add_filter('woocommerce_product_title',[self::class,'localized_service_title'],10,2);
        add_filter('woocommerce_get_item_data',[self::class,'reservation_deadline'],10,2);
        add_filter('woocommerce_add_cart_item_data',[self::class,'cart_item_data'],10,3);
        add_filter('woocommerce_store_api_add_to_cart_data',[self::class,'store_cart_data'],10,2);
        add_action('woocommerce_check_cart_items',[self::class,'classic_cart_validation']);
        add_action('woocommerce_store_api_cart_errors',[self::class,'store_cart_validation'],10,2);
        add_action('woocommerce_checkout_process',[self::class,'checkout_auth']);
        add_action('woocommerce_before_calculate_totals',[self::class,'quote_prices'],20,1);
        add_action('woocommerce_checkout_create_order_line_item',[self::class,'line_item'],10,4);
        add_action('woocommerce_checkout_order_processed',[self::class,'classic_order'],10,3);
        add_action('woocommerce_store_api_checkout_order_processed',[self::class,'store_order'],10,1);
        add_filter('woocommerce_available_payment_gateways',[self::class,'available_gateways'],PHP_INT_MAX);
        add_action('woocommerce_before_pay_action',[self::class,'before_pay'],0);
        add_action('woocommerce_after_pay_action',[self::class,'after_pay'],PHP_INT_MAX,0);
        // Checkout Blocks and the Store API existing-order endpoint both dispatch here.
        // Run before Woo's legacy gateway adapter (priority 999) can initiate payment.
        add_action('woocommerce_rest_checkout_process_payment_with_context',[self::class,'guard_store_payment'],0,1);
    }
    public static function localized_service_title(string $title, $product): string {
        if (!str_starts_with(determine_locale(), 'fa')) return $title;
        $id = self::offer_id($product->get_id());
        if (!$id) return $title;
        $offer = Offers::get($id, true);
        if (is_wp_error($offer) || (int)$offer['product_id'] !== $product->get_id()) return $title;
        return $offer['title_fa'] ?: $title;
    }
    /** Expose the owner's hold deadline in native cart and Checkout Blocks summaries. */
    public static function reservation_deadline(array $rows, array $item): array {
        global $wpdb;
        $booking = $item['atlas_booking_id'] ?? '';
        if (!is_string($booking) || $booking === '' || !is_user_logged_in()) {
            return $rows;
        }
        $reservation = $wpdb->get_row($wpdb->prepare(
            'SELECT h.expires_at,b.amount_irr FROM '.DB::table('holds').' h JOIN '.DB::table('bookings').' b ON b.hold_id=h.id WHERE b.id=%s AND b.user_id=%d AND h.state=%s',
            $booking, get_current_user_id(), 'active'
        ));
        if ($reservation) {
            $utc = new \DateTimeImmutable($reservation->expires_at, new \DateTimeZone('UTC'));
            $zone = wp_timezone();
            $fa = str_starts_with(determine_locale(), 'fa');
            $deadline = wp_date('Y-m-d H:i:s', $utc->getTimestamp(), $zone).' ('.$zone->getName().')';
            $zero = (string)$reservation->amount_irr === '0';
            $label = $zero ? ($fa ? 'مهلت رزرو' : 'Reservation deadline') : ($fa ? 'مهلت پرداخت' : 'Payment deadline');
            $rows[] = [
                'key' => $label,
                'value' => $fa ? "\u{2066}".$deadline."\u{2069}" : $deadline,
            ];
        }
        return $rows;
    }
    private static function offer_id(int $product): int { return (int)get_post_meta($product,'_atlas_offer_id',true); }
    private static function service_cart(): bool {
        if (!function_exists('WC') || !WC()->cart) { return false; }
        foreach (WC()->cart->get_cart() as $item) {
            if (!empty($item['atlas_booking_id']) || self::offer_id((int)($item['product_id'] ?? 0))>0) { return true; }
        }
        return false;
    }
    private static function service_order($order): bool {
        if (!$order instanceof \WC_Order) { return false; }
        foreach ($order->get_items('line_item') as $item) {
            if ($item->get_meta('_atlas_booking_id',true)!=='' || self::offer_id((int)$item->get_product_id())>0) { return true; }
        }
        return false;
    }
    private static function service_gateway_allowed(string $method): bool {
        // No live provider has a reviewed domain-verification bridge yet. Merely
        // enabling a Woo gateway must never authorize it to collect service money.
        $allowed=$method==='atlas_simulator' && wp_get_environment_type()==='local'
            && defined('ATLAS_DEMO_MODE') && ATLAS_DEMO_MODE===true;
        return apply_filters('atlas_core_verified_service_gateway',$allowed,$method)===true;
    }
    public static function available_gateways(array $gateways): array {
        global $wp;
        $orderId=self::$payOrderId ?: absint($wp->query_vars['order-pay'] ?? 0);
        // The existing order governs order-pay, even when an unrelated cart exists.
        $service=$orderId ? self::service_order(wc_get_order($orderId)) : self::service_cart();
        if (!$service) { return $gateways; }
        foreach ($gateways as $id=>$gateway) { if (!self::service_gateway_allowed((string)$id)) { unset($gateways[$id]); } }
        return $gateways;
    }
    public static function before_pay($order): void {
        self::$payOrderId=$order instanceof \WC_Order ? (int)$order->get_id() : 0;
        $method=isset($_POST['payment_method']) && is_string($_POST['payment_method']) ? sanitize_key(wp_unslash($_POST['payment_method'])) : '';
        // This Woo hook is outside its exception handler; an error notice safely
        // prevents dispatch, while the availability filter rejects the method too.
        if (self::service_order($order)) {
            if (!self::service_gateway_allowed($method)) { wc_add_notice(__('This payment method is not available for service bookings.','atlas-core'),'error'); return; }
            $booking=self::order_booking($order);
            $ready=is_wp_error($booking)?$booking:Booking::can_initiate_payment($booking['id'],get_current_user_id(),(int)$order->get_id());
            if (is_wp_error($ready)) { wc_add_notice(__('The order reservation expired or changed. Review it before payment.','atlas-core'),'error'); }
        }
    }
    public static function after_pay(): void { self::$payOrderId=0; }
    public static function guard_store_payment($context): void {
        if (!self::service_order($context->order)) return;
        if (!self::service_gateway_allowed((string)$context->payment_method)) { throw new \Exception(__('This payment method is not available for service bookings.','atlas-core')); }
        $booking=self::order_booking($context->order);
        $ready=is_wp_error($booking)?$booking:Booking::can_initiate_payment($booking['id'],get_current_user_id(),(int)$context->order->get_id());
        if (is_wp_error($ready)) { throw new \Exception(__('The order reservation expired or changed. Review it before payment.','atlas-core')); }
    }
    private static function guard_order_payment($order): void {
        // Native free checkout has no gateway. bind_order still proves the exact
        // zero order/line/reservation amounts before recording zero-due settlement.
        if ($order instanceof \WC_Order && self::integer_amount((string)$order->get_total())===0) return;
        if (self::service_order($order) && !self::service_gateway_allowed((string)$order->get_payment_method())) { throw new \Exception(__('This payment method is not available for service bookings.','atlas-core')); }
    }
    /** Existing-order payment is governed by its own persisted reservation, never a different cart. */
    private static function order_booking($order,bool $allowUnbound=false): array|\WP_Error {
        $error=new \WP_Error('atlas_order_reservation_invalid',__('The service order and reservation require review.','atlas-core'));
        if (!$order instanceof \WC_Order || (int)$order->get_id()<1 || !is_user_logged_in() || (int)$order->get_customer_id()!==get_current_user_id() || $order->get_currency()!=='IRR') return $error;
        $items=$order->get_items('line_item'); if (count($items)!==1) return $error;
        $item=reset($items); $id=$item->get_meta('_atlas_booking_id',true);
        if (!is_string($id) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$id)) return $error;
        $booking=Booking::get($id,get_current_user_id());
        if (is_wp_error($booking) || $booking['user_id']!==get_current_user_id() || ($booking['order_id']!==(int)$order->get_id() && !($allowUnbound && $booking['order_id']===0))) return $error;
        $offer=Offers::get($booking['offer_id'],true);
        if (is_wp_error($offer) || self::offer_id((int)$item->get_product_id())!==$booking['offer_id'] || (int)$item->get_product_id()!==(int)$offer['product_id'] || (int)$item->get_variation_id()!==0 || (int)$item->get_quantity()!==$booking['quantity'] || self::integer_amount((string)$item->get_total())!==$booking['amount_irr'] || self::integer_amount((string)$order->get_total())!==$booking['amount_irr']) return $error;
        return $booking;
    }
    public static function add_validation(bool $passed,int $productId,int $quantity,int $variationId=0,array $variation=[],?array $cartItemData=null): bool {
        if (!$passed || !function_exists('WC') || !WC()->cart) { return $passed; }
        $incoming=self::offer_id($productId);
        // Classic handlers pass three/five arguments; Store API supplies the final
        // cart-item data as argument six. An empty API payload must never read POST.
        $booking=$cartItemData===null ? ($_POST['atlas_booking_id'] ?? null) : ($cartItemData['atlas_booking_id'] ?? null);
        if ($cartItemData===null && is_string($booking)) { $booking=wp_unslash($booking); }
        if ($incoming>0 && (!is_string($booking) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$booking))) {
            wc_add_notice(__('A valid reservation reference is required before adding this service.','atlas-core'),'error'); return false;
        }
        foreach (WC()->cart->get_cart() as $item) {
            $existing=self::offer_id((int)$item['product_id']);
            if (($incoming>0) !== ($existing>0) || ($incoming>0 && $incoming!==$existing)) { wc_add_notice(__('Service and merchandise or different services require separate checkouts.','atlas-core'),'error'); return false; }
            if ($incoming>0) {
                $same=is_string($item['atlas_booking_id'] ?? null) && $item['atlas_booking_id']===$booking;
                wc_add_notice($same ? __('This reservation is already in your cart. Open the cart to continue.','atlas-core') : __('Complete or remove the service already in your cart before adding another reservation.','atlas-core'),'error'); return false;
            }
        }
        return true;
    }
    public static function cart_item_data(array $data,int $productId,int $variationId): array {
        if (self::offer_id($productId)>0 && !array_key_exists('atlas_booking_id',$data) && isset($_POST['atlas_booking_id']) && is_string($_POST['atlas_booking_id'])) { $data['atlas_booking_id']=sanitize_text_field(wp_unslash($_POST['atlas_booking_id'])); }
        return $data;
    }
    public static function store_cart_data(array $data,\WP_REST_Request $request): array {
        $productId=(int)($data['id'] ?? 0);
        if (get_post_type($productId)==='product_variation') { $productId=(int)wp_get_post_parent_id($productId); }
        if (self::offer_id($productId)>0) {
            $id=$request->get_param('atlas_booking_id');
            // Preserve a missing/malformed marker so the classic data filter cannot
            // inject unrelated POST state into a Store API request before validation.
            $data['cart_item_data']['atlas_booking_id']=is_string($id) ? $id : '';
        }
        return $data;
    }
    private static function integer_amount(string $amount): ?int {
        if (!preg_match('/^([0-9]+)(?:\.0+)?$/',$amount,$m) || strlen($m[1])>18 || (float)$m[1]>PHP_INT_MAX) { return null; }
        return (int)$m[1];
    }
    /** Use only the owned, persisted booking quote; caller prices are ignored. */
    public static function quote_prices($cart): void {
        if (!is_user_logged_in()) return;
        foreach ($cart->get_cart() as $item) {
            $id=$item['atlas_booking_id'] ?? null;
            if (!is_string($id) || !self::offer_id((int)($item['product_id'] ?? 0))) continue;
            $booking=Booking::get($id,get_current_user_id());
            if (is_wp_error($booking) || $booking['user_id']!==get_current_user_id() || $booking['quantity']<1 || $booking['amount_irr']%$booking['quantity']!==0) continue;
            $offer=Offers::get($booking['offer_id'],true);
            if (is_wp_error($offer) || (int)$offer['product_id']!==(int)$item['product_id']) continue;
            $item['data']->set_price((string)intdiv($booking['amount_irr'],$booking['quantity']));
        }
    }
    private static function cart_error($cart): ?string {
        global $wpdb;
        $type=null; $service=null; $bookingId=null; $expected=0;
        foreach ($cart->get_cart() as $item) {
            $offerId=self::offer_id((int)$item['product_id']); $thisType=$offerId ? 'service' : 'physical';
            if ($type!==null && $type!==$thisType) { return __('Use separate checkouts for services and merchandise.','atlas-core'); }
            $type=$thisType;
            if (!$offerId) { continue; }
            if ($service!==null && $service!==$offerId) { return __('Use separate checkouts for different services.','atlas-core'); }
            $service=$offerId; $id=(string)($item['atlas_booking_id'] ?? '');
            if (!$id || ($bookingId!==null && $bookingId!==$id) || !is_user_logged_in()) { return __('A signed-in account and valid reservation are required.','atlas-core'); }
            $bookingId=$id;
            $b=$wpdb->get_row($wpdb->prepare('SELECT b.*,h.state AS hold_state,h.expires_at FROM '.DB::table('bookings').' b INNER JOIN '.DB::table('holds').' h ON h.id=b.hold_id WHERE b.id=%s',$id));
            $offer=Offers::get($offerId,true);
            if (!$b || is_wp_error($offer) || (int)$b->user_id!==get_current_user_id() || (int)$b->offer_id!==$offerId || $b->hold_state!=='active' || DB::now()>=$b->expires_at || !in_array($b->status,['held','pending_payment'],true) || (int)$b->offer_version!==$offer['version'] || (int)$item['quantity']!==(int)$b->quantity || (int)$offer['product_id']!==(int)$item['product_id']) { return __('Check the reservation and quantity. If a retry added the item twice, remove the cart line and add it once using the same unexpired reservation.','atlas-core'); }
            $price=self::integer_amount((string)$item['data']->get_price());
            if ($price===null || $price*(int)$b->quantity!==(int)$b->amount_irr || get_woocommerce_currency()!=='IRR') { return __('Price or currency requires review before checkout.','atlas-core'); }
            $expected+=(int)$b->amount_irr;
        }
        if ($type==='service' && self::integer_amount((string)$cart->get_total('edit'))!==$expected) { return __('The final total changed. Please request a new quote.','atlas-core'); }
        return null;
    }
    public static function classic_cart_validation(): void { if (WC()->cart && ($error=self::cart_error(WC()->cart))) { wc_add_notice($error,'error'); } }
    public static function store_cart_validation(\WP_Error $errors,$cart): void { if ($error=self::cart_error($cart)) { $errors->add('atlas_cart_invalid',$error); } }
    public static function checkout_auth(): void { if (!is_user_logged_in()) { wc_add_notice(__('Sign in before checkout.','atlas-core'),'error'); } }
    public static function line_item($item,string $cartItemKey,array $values,$order): void {
        if (!empty($values['atlas_booking_id'])) { $item->add_meta_data('_atlas_booking_id',sanitize_text_field((string)$values['atlas_booking_id']),true); }
    }
    public static function classic_order(int $orderId,array $data,$order): void { self::guard_order_payment($order); self::bind_order($order); }
    public static function store_order($order): void { self::guard_order_payment($order); self::bind_order($order); }
    private static function bind_order($order): void {
        global $wpdb;
        if (!is_user_logged_in() || (int)$order->get_customer_id()!==get_current_user_id()) { throw new \Exception(__('Sign in before checkout.','atlas-core')); }
        if (!self::service_order($order)) return;
        $orderLines=$order->get_items('line_item'); $persisted=null;
        foreach ($orderLines as $line) {
            if ($line->get_meta('_atlas_booking_id',true)==='') continue;
            $persisted=self::order_booking($order,true);
            if (is_wp_error($persisted)) throw new \Exception($persisted->get_error_message());
            if ($persisted['order_id']===(int)$order->get_id()) {
                if ($persisted['amount_irr']===0) {
                    $settled=Booking::complete_zero_due($persisted['id'],get_current_user_id(),(int)$order->get_id());
                    if (is_wp_error($settled)) throw new \Exception($settled->get_error_message());
                    return;
                }
                $ready=Booking::can_initiate_payment($persisted['id'],get_current_user_id(),(int)$order->get_id());
                $receipt=$order->is_paid() && $persisted['payment_status']==='verified' && in_array($persisted['status'],['paid_pending_confirmation','confirmed','fulfilled'],true);
                if (is_wp_error($ready) && !$receipt) throw new \Exception(__('The order reservation expired or changed. Review it before payment.','atlas-core'));
                return;
            }
        }
        if (!WC()->cart || ($error=self::cart_error(WC()->cart))) { throw new \Exception($error ?: __('Cart unavailable.','atlas-core')); }
        $serviceItems=[]; foreach (WC()->cart->get_cart() as $item) { if (self::offer_id((int)$item['product_id'])) { $serviceItems[]=$item; } }
        if (!$serviceItems) { return; }
        if (count($serviceItems)!==1) { throw new \Exception(__('Separate service checkouts are required.','atlas-core')); }
        $cartItem=$serviceItems[0]; $bookingId=(string)$cartItem['atlas_booking_id']; $booking=Booking::get($bookingId,get_current_user_id());
        if (is_wp_error($booking) || ($persisted && $persisted['id']!==$bookingId) || self::integer_amount((string)$order->get_total())!==$booking['amount_irr'] || $order->get_currency()!=='IRR') { throw new \Exception(__('Order total requires review.','atlas-core')); }
        $matches=[]; foreach ($order->get_items('line_item') as $item) { if ((int)$item->get_product_id()===(int)$cartItem['product_id']) { $matches[]=$item; } }
        if (count($orderLines)!==1 || count($matches)!==1 || (int)$matches[0]->get_variation_id()!==0 || (int)$matches[0]->get_quantity()!==$booking['quantity'] || self::integer_amount((string)$matches[0]->get_total())!==$booking['amount_irr']) { throw new \Exception(__('Order line requires review.','atlas-core')); }
        $item=$matches[0]; $item->update_meta_data('_atlas_booking_id',$bookingId); $item->save();
        if ($wpdb->query('START TRANSACTION')===false) throw new \Exception(__('Reservation cannot be used for this order.','atlas-core'));
        try {
            $inventory=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('inventory').' WHERE offer_id=%d FOR UPDATE',$booking['offer_id']));
            $hold=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('holds').' WHERE id=%s FOR UPDATE',$booking['hold_id']));
            $locked=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('bookings').' WHERE id=%s FOR UPDATE',$bookingId));
            $offer=$inventory?json_decode($inventory->offer_snapshot,true):null;
            if (!$inventory || !$hold || !$locked || !is_array($offer) || $inventory->status!=='published' || $offer['status']!=='published' || (int)$inventory->version!==(int)$locked->offer_version || (int)$locked->user_id!==get_current_user_id() || (int)$locked->quantity!==(int)$item->get_quantity() || (int)$locked->amount_irr!==self::integer_amount((string)$order->get_total()) || (int)$offer['product_id']!==(int)$item->get_product_id() || $hold->booking_id!==$bookingId || (int)$hold->offer_id!==(int)$locked->offer_id || $hold->state!=='active' || DB::now()>=$hold->expires_at || ((int)$locked->order_id!==0 && (int)$locked->order_id!==(int)$order->get_id()) || !in_array($locked->status,['held','pending_payment'],true)) { throw new \RuntimeException('hold unavailable'); }
            if ($wpdb->update(DB::table('bookings'),['order_id'=>(int)$order->get_id(),'status'=>'pending_payment','updated_at'=>DB::now()],['id'=>$bookingId])===false || !DB::audit(get_current_user_id(),(int)($offer['org_id']??0),'booking.order_link',$bookingId,'success')) { throw new \RuntimeException('link failed'); }
            if ($wpdb->query('COMMIT')===false) throw new \RuntimeException('commit');
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); throw new \Exception(__('Reservation cannot be used for this order.','atlas-core')); }
        if ($booking['amount_irr']===0) {
            $settled=Booking::complete_zero_due($bookingId,get_current_user_id(),(int)$order->get_id());
            if (is_wp_error($settled)) throw new \Exception($settled->get_error_message());
        }
    }
}
