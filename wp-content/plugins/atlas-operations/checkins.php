<?php
if (!defined('ABSPATH')) { exit; }
use Atlas\Core\Auth;
use Atlas\Core\DB;
use Atlas\Core\Offers;
/** Agency-only check-in; uniqueness is enforced by an InnoDB primary key. */
final class Atlas_Event_Checkins {
    private static function table() { global $wpdb; return $wpdb->prefix.'atlas_event_checkins'; }
    public static function install() {
        global $wpdb;
        if (get_option('atlas_operations_checkins_schema')==='1') return;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $table=self::table(); $charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (booking_id char(36) NOT NULL, attendee_index int unsigned NOT NULL, offer_id bigint unsigned NOT NULL, checked_by bigint unsigned NOT NULL, checked_at datetime(6) NOT NULL, PRIMARY KEY  (booking_id,attendee_index), KEY offer_id (offer_id)) ENGINE=InnoDB $charset;");
        $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
        $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
        if ($exists===$table && strtoupper((string)$engine)==='INNODB') update_option('atlas_operations_checkins_schema','1',false);
    }
    public static function routes() {
        register_rest_route('agency/v1','/events/(?P<id>\d+)/checkins',array('methods'=>'POST','permission_callback'=>static function(){ return Auth::agency(); },'callback'=>array(__CLASS__,'checkin')));
    }
    public static function checkin($request) {
        global $wpdb;
        if (!Auth::agency()) return new WP_Error('atlas_forbidden',__('Agency access required.','atlas-operations'),array('status'=>403));
        if (get_option('atlas_operations_checkins_schema')!=='1') return new WP_Error('atlas_checkin_unavailable',__('Check-in storage is unavailable.','atlas-operations'),array('status'=>503));
        $body=$request->get_json_params(); $offer_id=(int)$request['id'];
        if (!is_array($body) || array_diff(array_keys($body),array('booking_id','attendee_index')) || !isset($body['booking_id'],$body['attendee_index']) || !is_string($body['booking_id']) || !preg_match('/^[a-f0-9-]{36}$/',$body['booking_id']) || !is_int($body['attendee_index']) || $body['attendee_index']<1) return new WP_Error('atlas_invalid_checkin',__('Booking and attendee number are required.','atlas-operations'),array('status'=>422));
        $offer=Offers::get($offer_id,true);
        if (is_wp_error($offer) || $offer['kind']!=='event') return new WP_Error('atlas_not_found',__('Event unavailable.','atlas-operations'),array('status'=>404));
        $wpdb->query('START TRANSACTION');
        try {
            $booking=$wpdb->get_row($wpdb->prepare('SELECT id,offer_id,status,quantity FROM '.DB::table('bookings').' WHERE id=%s FOR UPDATE',$body['booking_id']));
            if (!$booking || (int)$booking->offer_id!==$offer_id || $booking->status!=='confirmed' || $body['attendee_index']>(int)$booking->quantity) { $wpdb->query('ROLLBACK'); return new WP_Error('atlas_invalid_checkin',__('A confirmed booking and valid attendee number are required.','atlas-operations'),array('status'=>409)); }
            $allowed=apply_filters('atlas_operations_validate_checkin',true,$booking,$offer_id,$body);
            if ($allowed!==true) { $wpdb->query('ROLLBACK'); return is_wp_error($allowed)?$allowed:new WP_Error('atlas_checkin_window',__('Check-in is not available.','atlas-operations'),array('status'=>409)); }
            $table=self::table();
            $inserted=$wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table (booking_id,attendee_index,offer_id,checked_by,checked_at) VALUES (%s,%d,%d,%d,%s)",$body['booking_id'],$body['attendee_index'],$offer_id,get_current_user_id(),DB::now()));
            if ($inserted!==1) { $wpdb->query('ROLLBACK'); return new WP_Error('atlas_already_checked_in',__('This attendee is already checked in.','atlas-operations'),array('status'=>409)); }
            if (!DB::audit(get_current_user_id(),(int)$offer['org_id'],'event.checkin',$body['booking_id'],'success')) throw new RuntimeException('audit');
            $wpdb->query('COMMIT'); return new WP_REST_Response(array('booking_id'=>$body['booking_id'],'attendee_index'=>$body['attendee_index'],'status'=>'checked_in'),201);
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); return new WP_Error('atlas_checkin_failed',__('Check-in could not be recorded.','atlas-operations'),array('status'=>503)); }
    }
}
