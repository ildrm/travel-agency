<?php
namespace Atlas\Core;
if (!defined('ABSPATH')) { exit; }
final class Woo {
    public static function register(): void {
        if (!class_exists('WooCommerce')) { return; }
        add_filter('woocommerce_add_to_cart_validation',[self::class,'add_validation'],10,3);
        add_filter('woocommerce_add_cart_item_data',[self::class,'cart_item_data'],10,3);
        add_filter('woocommerce_store_api_add_to_cart_data',[self::class,'store_cart_data'],10,2);
        add_action('woocommerce_check_cart_items',[self::class,'classic_cart_validation']);
        add_action('woocommerce_store_api_cart_errors',[self::class,'store_cart_validation'],10,2);
        add_action('woocommerce_checkout_process',[self::class,'checkout_auth']);
        add_action('woocommerce_checkout_create_order_line_item',[self::class,'line_item'],10,4);
        add_action('woocommerce_checkout_order_processed',[self::class,'classic_order'],10,3);
        add_action('woocommerce_store_api_checkout_order_processed',[self::class,'store_order'],10,1);
    }
    private static function offer_id(int $product): int { return (int)get_post_meta($product,'_atlas_offer_id',true); }
    public static function add_validation(bool $passed,int $productId,int $quantity): bool {
        if (!$passed || !function_exists('WC') || !WC()->cart) { return $passed; }
        $incoming=self::offer_id($productId);
        foreach (WC()->cart->get_cart() as $item) {
            $existing=self::offer_id((int)$item['product_id']);
            if (($incoming>0) !== ($existing>0) || ($incoming>0 && $incoming!==$existing)) { wc_add_notice(__('Service and merchandise or different services require separate checkouts.','atlas-core'),'error'); return false; }
        }
        return true;
    }
    public static function cart_item_data(array $data,int $productId,int $variationId): array {
        if (self::offer_id($productId)>0 && isset($_POST['atlas_booking_id'])) { $data['atlas_booking_id']=sanitize_text_field(wp_unslash($_POST['atlas_booking_id'])); }
        return $data;
    }
    public static function store_cart_data(array $data,\WP_REST_Request $request): array {
        $id=$request->get_param('atlas_booking_id'); if (is_string($id)) { $data['cart_item_data']['atlas_booking_id']=sanitize_text_field($id); } return $data;
    }
    private static function integer_amount(string $amount): ?int {
        if (!preg_match('/^([0-9]+)(?:\.0+)?$/',$amount,$m) || strlen($m[1])>18 || (float)$m[1]>PHP_INT_MAX) { return null; }
        return (int)$m[1];
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
    public static function classic_order(int $orderId,array $data,$order): void { self::bind_order($order); }
    public static function store_order($order): void { self::bind_order($order); }
    private static function bind_order($order): void {
        global $wpdb;
        if (!is_user_logged_in() || (int)$order->get_customer_id()!==get_current_user_id()) { throw new \Exception(__('Sign in before checkout.','atlas-core')); }
        if (!WC()->cart || ($error=self::cart_error(WC()->cart))) { throw new \Exception($error ?: __('Cart unavailable.','atlas-core')); }
        $serviceItems=[]; foreach (WC()->cart->get_cart() as $item) { if (self::offer_id((int)$item['product_id'])) { $serviceItems[]=$item; } }
        if (!$serviceItems) { return; }
        if (count($serviceItems)!==1) { throw new \Exception(__('Separate service checkouts are required.','atlas-core')); }
        $cartItem=$serviceItems[0]; $bookingId=(string)$cartItem['atlas_booking_id']; $booking=Booking::get($bookingId,get_current_user_id());
        if (is_wp_error($booking) || self::integer_amount((string)$order->get_total())!==$booking['amount_irr'] || $order->get_currency()!=='IRR') { throw new \Exception(__('Order total requires review.','atlas-core')); }
        $matches=[]; foreach ($order->get_items('line_item') as $item) { if ((int)$item->get_product_id()===(int)$cartItem['product_id']) { $matches[]=$item; } }
        if (count($matches)!==1) { throw new \Exception(__('Order line requires review.','atlas-core')); }
        $item=$matches[0]; $item->update_meta_data('_atlas_booking_id',$bookingId); $item->save();
        $wpdb->query('START TRANSACTION');
        try {
            $inventory=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('inventory').' WHERE offer_id=%d FOR UPDATE',$booking['offer_id']));
            $hold=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('holds').' WHERE id=%s FOR UPDATE',$booking['hold_id']));
            $locked=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('bookings').' WHERE id=%s FOR UPDATE',$bookingId));
            if (!$inventory || !$hold || !$locked || $hold->state!=='active' || DB::now()>=$hold->expires_at || ((int)$locked->order_id!==0 && (int)$locked->order_id!==(int)$order->get_id()) || !in_array($locked->status,['held','pending_payment'],true)) { throw new \RuntimeException('hold unavailable'); }
            if ($wpdb->update(DB::table('bookings'),['order_id'=>(int)$order->get_id(),'status'=>'pending_payment','updated_at'=>DB::now()],['id'=>$bookingId])===false || !DB::audit(get_current_user_id(),0,'booking.order_link',$bookingId,'success')) { throw new \RuntimeException('link failed'); }
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); throw new \Exception(__('Reservation cannot be used for this order.','atlas-core')); }
    }
}
