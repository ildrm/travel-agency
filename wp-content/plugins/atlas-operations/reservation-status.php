<?php
if (!defined('ABSPATH')) { exit; }
use Atlas\Core\Auth;
use Atlas\Core\Booking;
use Atlas\Core\DB;

/** Booking fulfillment notices, separate from Woo order/payment receipt semantics. */
final class Atlas_Reservation_Status {
    private static $rendered=array();
    private static function language() { return isset($_GET['lang']) && in_array($_GET['lang'],array('en','fa'),true)?$_GET['lang']:(str_starts_with(determine_locale(),'fa')?'fa':'en'); }
    private static function t($en,$fa) { return self::language()==='fa'?$fa:$en; }
    public static function register() {
        // Verified against installed Woo 11.3: the OrderConfirmation Status block
        // and classic thankyou template both invoke woocommerce_before_thankyou.
        add_action('woocommerce_before_thankyou',array(__CLASS__,'thankyou'),10,1);
        add_action('woocommerce_order_details_after_order_table',array(__CLASS__,'details'),10,1);
    }
    public static function label($state) {
        $labels=array('held'=>array('Reserved temporarily','رزرو موقت'),'pending_payment'=>array('Awaiting payment','در انتظار پرداخت'),'paid_pending_confirmation'=>array('Payment verified; awaiting agency confirmation','پرداخت تأیید شد؛ در انتظار تأیید آژانس'),'confirmed'=>array('Service reservation confirmed','رزرو خدمت تأیید شد'),'fulfilled'=>array('Service fulfilled','خدمت ارائه شد'),'expired'=>array('Reservation expired','مهلت رزرو پایان یافت'),'exception'=>array('Reservation requires agency review','رزرو نیازمند بررسی آژانس است'),'canceled'=>array('Reservation canceled','رزرو لغو شد'));
        return isset($labels[$state])?self::t($labels[$state][0],$labels[$state][1]):self::t('Reservation status requires review','وضعیت رزرو نیازمند بررسی است');
    }
    private static function text($state,$verified) {
        if ($state==='paid_pending_confirmation') return self::t('Payment verified; awaiting agency confirmation. No confirmed travel ticket has been issued by this receipt.','پرداخت تأیید شد؛ در انتظار تأیید آژانس است. این رسید به معنی صدور بلیت تأییدشده نیست.');
        if ($state==='confirmed') return self::t('The agency has confirmed the service reservation. Issued tickets and supplier documents, where applicable, are separate.','آژانس رزرو خدمت را تأیید کرده است. بلیت صادرشده و مدارک تأمین‌کننده، در صورت لزوم، جداگانه هستند.');
        if ($state==='fulfilled') return self::t('The service is recorded as fulfilled. This record is separate from any refund or supplier settlement.','ارائه خدمت ثبت شده است. این وضعیت از بازپرداخت و تسویه تأمین‌کننده جداست.');
        if ($state==='exception') return $verified?self::t('Payment received; the reservation requires review. No new seat or confirmed ticket is created. Contact support for the recorded resolution or refund review.','پرداخت دریافت شد؛ رزرو نیازمند بررسی است. صندلی یا بلیت تأییدشده جدیدی ایجاد نمی‌شود. برای بررسی راه‌حل یا بازپرداخت با پشتیبانی تماس بگیرید.'):self::t('The reservation requires review. Payment and service confirmation must be checked separately.','رزرو نیازمند بررسی است. پرداخت و تأیید خدمت باید جداگانه بررسی شوند.');
        if ($state==='expired') return self::t('The reservation deadline passed. A payment receipt does not restore a released place; support must review any payment.','مهلت رزرو پایان یافته است. رسید پرداخت جایگاه آزادشده را برنمی‌گرداند؛ هر پرداخت باید توسط پشتیبانی بررسی شود.');
        if ($state==='canceled') return self::t('The reservation is canceled. Any refund remains a separate process and requires its own verified outcome.','رزرو لغو شده است. بازپرداخت فرایندی جداست و نتیجه آن باید جداگانه تأیید شود.');
        return self::t('Payment verification and agency confirmation are pending. This is not a confirmed travel ticket.','بررسی پرداخت و تأیید آژانس در انتظار است. این رسید بلیت تأییدشده نیست.');
    }
    private static function verified($booking_id) { global $wpdb; return (bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.DB::table('payments').' WHERE booking_id=%s AND status=%s LIMIT 1',$booking_id,'verified')); }
    public static function notice($order_id) {
        if (!is_user_logged_in() || !function_exists('wc_get_order')) return '';
        $order=wc_get_order((int)$order_id);
        if (!$order || ((int)$order->get_customer_id()!==get_current_user_id() && !Auth::agency())) return '';
        $html='';
        foreach ($order->get_items('line_item') as $item) {
            $id=(string)$item->get_meta('_atlas_booking_id',true); if (!$id) continue;
            $booking=Booking::get($id,get_current_user_id());
            if (is_wp_error($booking) || (int)$booking['order_id']!==(int)$order->get_id()) continue;
            $html.='<aside class="atlas-notice atlas-reservation-status" data-atlas-confirmation-status="'.esc_attr($booking['status']).'" role="status"><h2>'.esc_html(self::label($booking['status'])).'</h2><p>'.esc_html(self::text($booking['status'],self::verified($id))).'</p><p>'.esc_html(self::t('Reservation reference','شناسه رزرو')).': <bdi dir="ltr">'.esc_html($id).'</bdi></p></aside>';
        }
        return $html;
    }
    public static function thankyou($id) { self::echo_once((int)$id); }
    public static function details($order) { if (is_object($order) && method_exists($order,'get_id')) self::echo_once((int)$order->get_id()); }
    private static function echo_once($id) { if (isset(self::$rendered[$id])) return; $html=self::notice($id); if ($html!=='') { self::$rendered[$id]=true; echo $html; } }
    public static function customer_panel() {
        global $wpdb; if (!is_user_logged_in()) return '';
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,snapshot FROM '.DB::table('bookings').' WHERE user_id=%d ORDER BY created_at DESC LIMIT 50',get_current_user_id()));
        $html='<section class="atlas-customer-bookings"><h2>'.esc_html(self::t('My reservations','رزروهای من')).'</h2>';
        if (!$rows) return $html.'<p>'.esc_html(self::t('No reservations yet.','هنوز رزروی وجود ندارد.')).'</p></section>';
        foreach ($rows as $row) {
            $booking=Booking::get($row->id,get_current_user_id()); if (is_wp_error($booking)) continue;
            $snapshot=json_decode($row->snapshot,true)?:array(); $title=self::language()==='fa' && !empty($snapshot['title_fa'])?$snapshot['title_fa']:($snapshot['title_en']??'');
            $amount=preg_replace('/\B(?=(\d{3})+(?!\d))/',',',(string)$booking['amount_irr']);
            $html.='<article data-atlas-booking-state="'.esc_attr($booking['status']).'"><h3>'.esc_html($title).'</h3><p><bdi dir="ltr">'.esc_html($amount.' IRR').'</bdi></p><p>'.esc_html(self::label($booking['status'])).'</p><p>'.esc_html(self::text($booking['status'],self::verified($row->id))).'</p><bdi dir="ltr">'.esc_html($row->id).'</bdi>';
            if (!empty($booking['order_id']) && function_exists('wc_get_order')) {
                $order=wc_get_order((int)$booking['order_id']);
                if ($order && (int)$order->get_customer_id()===get_current_user_id()) $html.='<p><a href="'.esc_url(add_query_arg('lang',self::language(),$order->get_checkout_order_received_url())).'">'.esc_html(self::t('View payment receipt','مشاهده رسید پرداخت')).'</a></p>';
            }
            $html.='</article>';
        }
        return $html.'</section>';
    }
}
