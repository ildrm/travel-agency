<?php
if (!defined('ABSPATH')) { exit; }
use Atlas\Core\Auth;
use Atlas\Core\DB;

/** Internal operational signals based on committed state. No customer tracking. */
final class Atlas_Operations_Health {
    private static function permitted(): bool { return Auth::agency() && current_user_can('atlas_manage_bookings'); }
    public static function register(): void {
        add_action('rest_api_init',static function() {
            register_rest_route('agency/v1','/operations-health',array(
                'methods'=>'GET','permission_callback'=>static function($request) {
                    $nonce=Atlas_Partner_Security::permission($request);
                    return is_wp_error($nonce)?$nonce:self::permitted();
                },'callback'=>static function() {
                    $data=self::snapshot();
                    return is_wp_error($data)?$data:Atlas_Partner_Security::response($data);
                },
            ));
        });
        add_filter('atlas_operations_dashboard',array(__CLASS__,'panel'),50,2);
    }
    public static function snapshot() {
        global $wpdb;
        if (!self::permitted()) return new WP_Error('atlas_forbidden','Agency operations authority required.',array('status'=>403));
        $now=DB::now();
        $holds=DB::table('holds'); $outbox=DB::table('outbox'); $bookings=DB::table('bookings'); $inventory=DB::table('inventory');
        $due=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS total, MIN(expires_at) AS oldest FROM $holds WHERE state=%s AND expires_at<=%s",'active',$now));
        $queue=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS total, MIN(COALESCE(available_at,created_at)) AS oldest FROM $outbox WHERE status=%s AND (available_at IS NULL OR available_at<=%s)",'pending',$now));
        $failed=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $outbox WHERE status=%s",'failed'));
        $exceptions=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS total, MIN(updated_at) AS oldest FROM $bookings WHERE status IN (%s,%s)",'exception','paid_pending_confirmation'));
        $invariants=$wpdb->get_var("SELECT COUNT(*) FROM $inventory WHERE allocated>capacity");
        if (!$due || !$queue || !$exceptions || $failed===null || $invariants===null) return new WP_Error('atlas_health_unavailable','Operations health could not be read.',array('status'=>503));
        $beat=get_option('atlas_core_worker_heartbeat',array());
        $heartbeat=is_array($beat)&&is_string($beat['finished_at']??null)?$beat['finished_at']:null;
        return array(
            'schema_version'=>1,'observed_at_utc'=>$now,'scheduler_heartbeat_utc'=>$heartbeat,
            'last_worker_errors'=>is_array($beat)&&is_int($beat['errors']??null)?$beat['errors']:null,
            'wp_cron_disabled'=>defined('DISABLE_WP_CRON') && DISABLE_WP_CRON===true,
            'due_holds'=>array('count'=>(int)$due->total,'oldest_utc'=>$due->oldest),
            'due_outbox'=>array('count'=>(int)$queue->total,'oldest_utc'=>$queue->oldest),
            'failed_outbox'=>(int)$failed,
            'bookings_for_review'=>array('count'=>(int)$exceptions->total,'oldest_utc'=>$exceptions->oldest),
            'inventory_over_capacity'=>(int)$invariants,
        );
    }
    public static function panel(string $html,bool $customer): string {
        if ($customer || !self::permitted()) return $html;
        $data=self::snapshot();
        if (is_wp_error($data)) {
            return $html.'<p role="status">'.esc_html(Atlas_Partner_Security::t(
                'Operations health is temporarily unavailable.', 'سلامت عملیات موقتاً در دسترس نیست.'
            )).'</p>';
        }
        $t=array('Atlas_Partner_Security','t');
        $html.='<details><summary>'.esc_html($t('Operations health','سلامت عملیات')).'</summary><dl>';
        $fields=array(
            array($t('Last worker completion (UTC)','آخرین پایان کار پردازش (UTC)'),$data['scheduler_heartbeat_utc']??$t('No worker run recorded','اجرای پردازش ثبت نشده است')),
            array($t('Last worker errors','خطاهای آخرین اجرای پردازش'),$data['last_worker_errors']??'—'),
            array($t('Holds awaiting release','نگهداشت‌های در انتظار آزادسازی'),$data['due_holds']['count']),
            array($t('Due outbox events','رویدادهای آماده پردازش'),$data['due_outbox']['count']),
            array($t('Failed outbox events','رویدادهای ناموفق'),$data['failed_outbox']),
            array($t('Bookings awaiting review','رزروهای در انتظار بررسی'),$data['bookings_for_review']['count']),
            array($t('Inventory above capacity','موجودی فراتر از ظرفیت'),$data['inventory_over_capacity']),
        );
        foreach ($fields as [$label,$value]) $html.='<dt>'.esc_html($label).'</dt><dd><bdi>'.esc_html((string)$value).'</bdi></dd>';
        if ($data['wp_cron_disabled']) $html.='<dt>'.esc_html($t('WordPress web cron','زمان‌بندی داخلی وردپرس')).'</dt><dd>'.esc_html($t('Disabled. Use the configured external worker or the local CLI runner.','غیرفعال. از پردازشگر خارجی پیکربندی‌شده یا ابزار خط فرمان محلی استفاده کنید.')).'</dd>';
        return $html.'</dl></details>';
    }
}
add_action('atlas_operations_ready',array('Atlas_Operations_Health','register'));
