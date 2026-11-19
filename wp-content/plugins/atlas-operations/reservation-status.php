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
        add_action('admin_post_atlas_booking_voucher',array(__CLASS__,'voucher_page'));
    }
    public static function label($state,$payment='unverified') {
        if ($state==='paid_pending_confirmation' && $payment==='not_required') {
            return self::t('Awaiting agency confirmation','در انتظار تأیید آژانس');
        }
        $labels=array('held'=>array('Reserved temporarily','رزرو موقت'),'pending_payment'=>array('Awaiting payment','در انتظار پرداخت'),'paid_pending_confirmation'=>array('Payment verified; awaiting agency confirmation','پرداخت تأیید شد؛ در انتظار تأیید آژانس'),'confirmed'=>array('Service reservation confirmed','رزرو خدمت تأیید شد'),'fulfilled'=>array('Service fulfilled','خدمت ارائه شد'),'expired'=>array('Reservation expired','مهلت رزرو پایان یافت'),'exception'=>array('Reservation requires agency review','رزرو نیازمند بررسی آژانس است'),'canceled'=>array('Reservation canceled','رزرو لغو شد'));
        return isset($labels[$state])?self::t($labels[$state][0],$labels[$state][1]):self::t('Reservation status requires review','وضعیت رزرو نیازمند بررسی است');
    }
    private static function text($state,$payment) {
        if ($state==='paid_pending_confirmation' && $payment==='not_required') {
            return self::t('This reservation requires no payment. The agency will review the service before confirming it.','این رزرو نیازی به پرداخت ندارد. آژانس پیش از تأیید، خدمت را بررسی می‌کند.');
        }
        if ($state==='paid_pending_confirmation') return self::t('Payment verified; awaiting agency confirmation. No confirmed travel ticket has been issued by this receipt.','پرداخت تأیید شد؛ در انتظار تأیید آژانس است. این رسید به معنی صدور بلیت تأییدشده نیست.');
        if ($state==='confirmed') return self::t('The agency has confirmed the service reservation. Issued tickets and supplier documents, where applicable, are separate.','آژانس رزرو خدمت را تأیید کرده است. بلیت صادرشده و مدارک تأمین‌کننده، در صورت لزوم، جداگانه هستند.');
        if ($state==='fulfilled') return self::t('The service is recorded as fulfilled. This record is separate from any refund or supplier settlement.','ارائه خدمت ثبت شده است. این وضعیت از بازپرداخت و تسویه تأمین‌کننده جداست.');
        if ($state==='exception') return $payment==='verified'?self::t('Payment received; the reservation requires review. No new seat or confirmed ticket is created. Contact support for the recorded resolution or refund review.','پرداخت دریافت شد؛ رزرو نیازمند بررسی است. صندلی یا بلیت تأییدشده جدیدی ایجاد نمی‌شود. برای بررسی راه‌حل یا بازپرداخت با پشتیبانی تماس بگیرید.'):self::t('The reservation requires review. Payment and service confirmation must be checked separately.','رزرو نیازمند بررسی است. پرداخت و تأیید خدمت باید جداگانه بررسی شوند.');
        if ($state==='expired') return self::t('The reservation deadline passed. A payment receipt does not restore a released place; support must review any payment.','مهلت رزرو پایان یافته است. رسید پرداخت جایگاه آزادشده را برنمی‌گرداند؛ هر پرداخت باید توسط پشتیبانی بررسی شود.');
        if ($state==='canceled') return self::t('The reservation is canceled. Any refund remains a separate process and requires its own verified outcome.','رزرو لغو شده است. بازپرداخت فرایندی جداست و نتیجه آن باید جداگانه تأیید شود.');
        return self::t('Payment verification and agency confirmation are pending. This is not a confirmed travel ticket.','بررسی پرداخت و تأیید آژانس در انتظار است. این رسید بلیت تأییدشده نیست.');
    }
    private static function voucher_snapshot($id) {
        global $wpdb;
        if (!is_user_logged_in() || !is_string($id) || !preg_match('/^[a-f0-9-]{36}$/D',$id)) return null;
        $table=DB::table('bookings'); $inventory=DB::table('inventory');
        $row=$wpdb->get_row($wpdb->prepare("SELECT b.id,b.offer_id,b.status,b.quantity,b.offer_version,b.snapshot,i.status AS offer_status FROM $table b INNER JOIN $inventory i ON i.offer_id=b.offer_id WHERE b.id=%s AND b.user_id=%d",$id,get_current_user_id()));
        if (!$row || !in_array($row->status,array('confirmed','fulfilled'),true)) return null;
        $booking=Booking::get($id,get_current_user_id());
        if (is_wp_error($booking) || !in_array($booking['payment_status'],['verified','not_required'],true)) return null;
        $snapshot=json_decode($row->snapshot,true);
        if (!is_array($snapshot)) return null;
        // Operations can suspend the offer after a material change. A pending review
        // must not produce a fresh admission document based on superseded details.
        if ($row->status==='confirmed' && $row->offer_status!=='published') return null;
        $event=$snapshot['booking_options']['event']??null;
        if ($row->status==='confirmed' && is_array($event) && class_exists('Atlas_Events')) {
            $occurrence=Atlas_Events::occurrence($event['occurrence_id']??'');
            if (!$occurrence || $occurrence->state!=='scheduled' || (int)$occurrence->version!==(int)($event['occurrence_version']??0)) return null;
        }
        if (apply_filters('atlas_booking_voucher_allowed',true,$row,$snapshot)!==true) return null;
        return array($row,$snapshot);
    }
    private static function voucher_link($id) {
        if (!self::voucher_snapshot($id)) return '';
        $url=add_query_arg(array('action'=>'atlas_booking_voucher','booking_id'=>$id,'lang'=>self::language(),'_wpnonce'=>wp_create_nonce('atlas_booking_voucher_'.$id)),admin_url('admin-post.php'));
        return '<p><a data-atlas-voucher href="'.esc_url($url).'">'.esc_html(self::t('Open printable service voucher','باز کردن واچر قابل چاپ خدمت')).'</a></p>';
    }
    public static function voucher_page() {
        $id=isset($_GET['booking_id']) && is_string($_GET['booking_id'])?wp_unslash($_GET['booking_id']):'';
        $nonce=isset($_GET['_wpnonce']) && is_string($_GET['_wpnonce'])?wp_unslash($_GET['_wpnonce']):'';
        $record=wp_verify_nonce($nonce,'atlas_booking_voucher_'.$id)?self::voucher_snapshot($id):null;
        if (!$record) wp_die(esc_html(self::t('Service voucher unavailable. Review the booking or contact support.','واچر خدمت در دسترس نیست. رزرو را بررسی کنید یا با پشتیبانی تماس بگیرید.')),'',array('response'=>404));
        [$row,$snapshot]=$record;
        if (!DB::audit(get_current_user_id(),0,'booking.voucher_view',$row->id,'success')) wp_die(esc_html(self::t('Service voucher is temporarily unavailable.','واچر خدمت موقتاً در دسترس نیست.')),'',array('response'=>503));
        $locale=self::language(); $catalog=$snapshot['catalog']??array();
        $resource=$snapshot['booking_options']['resource_booking']??array();
        $title=($snapshot['title_'.$locale]??'')?:(($snapshot['title_en']??'')?:($snapshot['title_fa']??''));
        $terms=$resource['terms_'.$locale]??($catalog['terms_'.$locale]??'');
        $facts=array(
            self::t('Reservation reference','شناسه رزرو')=>$row->id,
            self::t('Service status','وضعیت خدمت')=>self::label($row->status),
            self::t('Quantity','تعداد')=>(string)$row->quantity,
            self::t('Accepted offer revision','نسخه پذیرفته‌شده پیشنهاد')=>(string)$row->offer_version,
            self::t('Time zone','منطقه زمانی')=>$resource['timezone']??($snapshot['timezone']??'UTC'),
        );
        $start=$resource['start']??($catalog['starts_at']??''); $end=$resource['end']??($catalog['ends_at']??'');
        if ($start!=='') $facts[self::t('Accepted start','شروع پذیرفته‌شده')]=$start;
        if ($end!=='') $facts[self::t('Accepted end','پایان پذیرفته‌شده')]=$end;
        nocache_headers(); header('Cache-Control: private, no-store, max-age=0'); header('Content-Type: text/html; charset=UTF-8'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer'); header('X-Robots-Tag: noindex');
        $heading=self::t('Service voucher','واچر خدمت');
        echo '<!doctype html><html lang="'.esc_attr($locale).'" dir="'.($locale==='fa'?'rtl':'ltr').'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.esc_html($heading).'</title><style>body{font:1rem/1.65 system-ui,sans-serif;margin:3rem auto;padding:0 1rem;max-width:42rem;color:#172b38;background:#fff}dt{font-weight:700;margin-top:1rem}dd{margin-inline-start:0}bdi{overflow-wrap:anywhere}@media print{.print-help{display:none}}</style></head><body><main><h1>'.esc_html($heading).'</h1><h2>'.esc_html($title).'</h2><p>'.esc_html(self::t('Keep this confirmation with your service details. Supplier-issued tickets, tax invoices and payment receipts are separate documents.','این تأییدیه را همراه جزئیات خدمت نگه دارید. بلیت تأمین‌کننده، صورتحساب مالیاتی و رسید پرداخت اسناد جداگانه هستند.')).'</p><dl>';
        foreach ($facts as $label=>$value) echo '<dt>'.esc_html($label).'</dt><dd><bdi>'.esc_html($value).'</bdi></dd>';
        echo '</dl>';
        if ($terms!=='') echo '<h2>'.esc_html(self::t('Accepted service terms','شرایط پذیرفته‌شده خدمت')).'</h2><p>'.nl2br(esc_html($terms)).'</p>';
        echo '<p class="print-help">'.esc_html(self::t('Use your browser’s Print command to print or save this voucher.','برای چاپ یا ذخیره واچر، از فرمان چاپ مرورگر استفاده کنید.')).'</p></main></body></html>'; exit;
    }
    public static function notice($order_id) {
        if (!is_user_logged_in() || !function_exists('wc_get_order')) return '';
        $order=wc_get_order((int)$order_id);
        if (!$order || ((int)$order->get_customer_id()!==get_current_user_id() && !Auth::agency())) return '';
        $html='';
        foreach ($order->get_items('line_item') as $item) {
            $id=(string)$item->get_meta('_atlas_booking_id',true); if (!$id) continue;
            $booking=Booking::get($id,get_current_user_id());
            if (is_wp_error($booking) || (int)$booking['order_id']!==(int)$order->get_id()) continue;
            $html.='<aside class="atlas-notice atlas-reservation-status" data-atlas-confirmation-status="'.esc_attr($booking['status']).'" role="status"><h2>'.esc_html(self::label($booking['status'],$booking['payment_status'])).'</h2><p>'.esc_html(self::text($booking['status'],$booking['payment_status'])).'</p><p>'.esc_html(self::t('Reservation reference','شناسه رزرو')).': <bdi dir="ltr">'.esc_html($id).'</bdi></p>'.(in_array($booking['status'],array('confirmed','fulfilled'),true)?self::voucher_link($id):'').'</aside>';
        }
        return $html;
    }
    public static function thankyou($id) { self::echo_once((int)$id); }
    public static function details($order) { if (is_object($order) && method_exists($order,'get_id')) self::echo_once((int)$order->get_id()); }
    private static function echo_once($id) { if (isset(self::$rendered[$id])) return; $html=self::notice($id); if ($html!=='') { self::$rendered[$id]=true; echo $html; } }
    public static function customer_panel() {
        global $wpdb; if (!is_user_logged_in()) return '';
        $requested=$_GET['reservation_page']??'1';
        $page=is_string($requested) && ctype_digit($requested)?min(10000,max(1,(int)$requested)):1;
        $size=20;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,snapshot FROM '.DB::table('bookings')
            .' WHERE user_id=%d ORDER BY created_at DESC,id DESC LIMIT %d OFFSET %d',get_current_user_id(),$size+1,($page-1)*$size));
        $html='<section class="atlas-customer-bookings" id="atlas-reservations"><h2>'.esc_html(self::t('My reservations','رزروهای من')).'</h2>';
        if ($wpdb->last_error!=='') return $html.'<p role="status">'.esc_html(self::t(
            'Reservations are temporarily unavailable.','رزروها موقتاً در دسترس نیستند.'
        )).'</p></section>';
        if (!$rows) $html.='<p>'.esc_html(self::t('No reservations on this page.','رزروی در این صفحه نیست.')).'</p>';
        foreach (array_slice($rows,0,$size) as $row) {
            $booking=Booking::get($row->id,get_current_user_id()); if (is_wp_error($booking)) continue;
            $snapshot=json_decode($row->snapshot,true)?:array(); $title=self::language()==='fa' && !empty($snapshot['title_fa'])?$snapshot['title_fa']:($snapshot['title_en']??'');
            $amount=preg_replace('/\B(?=(\d{3})+(?!\d))/',',',(string)$booking['amount_irr']);
            $html.='<article data-atlas-booking-state="'.esc_attr($booking['status']).'"><h3>'.esc_html($title).'</h3><p><bdi dir="ltr">'.esc_html($amount.' IRR').'</bdi></p><p>'.esc_html(self::label($booking['status'],$booking['payment_status'])).'</p><p>'.esc_html(self::text($booking['status'],$booking['payment_status'])).'</p><bdi dir="ltr">'.esc_html($row->id).'</bdi>';
            if (!empty($booking['order_id']) && function_exists('wc_get_order')) {
                $order=wc_get_order((int)$booking['order_id']);
                if ($order && (int)$order->get_customer_id()===get_current_user_id()) $html.='<p><a href="'.esc_url(add_query_arg('lang',self::language(),$order->get_checkout_order_received_url())).'">'.esc_html(($booking['payment_status']==='not_required'?self::t('View order details','مشاهده جزئیات سفارش'):self::t('View payment receipt','مشاهده رسید پرداخت'))).'</a></p>';
            }
            $html.=(in_array($booking['status'],array('confirmed','fulfilled'),true)?self::voucher_link($row->id):'').'</article>';
        }
        if ($page>1 || count($rows)>$size) {
            $html.='<nav aria-label="'.esc_attr(self::t('Reservation pages','صفحه‌های رزرو')).'">';
            if ($page>1) $html.='<a href="'.esc_url(add_query_arg('reservation_page',$page-1).'#atlas-reservations').'">'
                .esc_html(self::t('Previous reservations','رزروهای قبلی')).'</a> ';
            if (count($rows)>$size && $page<10000) $html.='<a href="'.esc_url(add_query_arg('reservation_page',$page+1).'#atlas-reservations').'">'
                .esc_html(self::t('More reservations','رزروهای بیشتر')).'</a>';
            $html.='</nav>';
        }
        return $html.'</section>';
    }
}
