<?php
namespace Atlas\Core;
if (!defined('ABSPATH')) { exit; }
final class Rest {
    public static function register(): void { add_action('rest_api_init',[self::class,'routes']); }
    public static function routes(): void {
        register_rest_route('agency/v1','/catalog',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>static function(\WP_REST_Request $r) { return rest_ensure_response(Offers::listing(['kind'=>sanitize_key((string)$r->get_param('kind')),'page'=>(int)($r->get_param('page') ?: 1)])); }]);
        register_rest_route('agency/v1','/holds',['methods'=>'POST','permission_callback'=>static function() { return is_user_logged_in() ? true : new \WP_Error('atlas_auth_required',__('Sign in to reserve.','atlas-core'),['status'=>401]); },'callback'=>static function(\WP_REST_Request $r) {
            $data=$r->get_json_params(); if (!is_array($data)) { return new \WP_Error('atlas_invalid_request','JSON object required.',['status'=>400]); }
            foreach (['offer_id','offer_version','quantity'] as $k) { if (!isset($data[$k]) || !is_int($data[$k])) { return new \WP_Error('atlas_invalid_request','Offer, version and quantity must be integers.',['status'=>422]); } }
            $refs=$data['participant_refs'] ?? []; if (!is_array($refs)) { return new \WP_Error('atlas_invalid_participant','Participant references must be a list.', ['status'=>422]); }
            $result=Booking::hold($data['offer_id'],$data['offer_version'],$data['quantity'],get_current_user_id(),(string)$r->get_header('Idempotency-Key'),$refs);
            return is_wp_error($result) ? $result : new \WP_REST_Response($result,201);
        }]);
        register_rest_route('agency/v1','/travelers',[
            ['methods'=>'GET','permission_callback'=>static function() { return is_user_logged_in(); },'callback'=>static function(\WP_REST_Request $r) { $result=Records::query('traveler',['limit'=>min(100,max(1,(int)($r->get_param('limit') ?: 25)))],get_current_user_id()); if (is_wp_error($result)) { return $result; } $response=new \WP_REST_Response($result,200); $response->header('Cache-Control','private, no-store'); return $response; }],
            ['methods'=>'POST','permission_callback'=>static function() { return is_user_logged_in(); },'callback'=>static function(\WP_REST_Request $r) {
                $data=$r->get_json_params();
                if (is_array($data) && array_diff(array_keys($data),['name','birth_date','guardian_name','guardian_attested'])) { return new \WP_Error('atlas_invalid_traveler','Unknown traveler field.', ['status'=>422]); }
                if (!is_array($data) || !isset($data['name'],$data['birth_date']) || !is_string($data['name']) || trim($data['name'])==='' || !is_string($data['birth_date']) || !Eligibility::date($data['birth_date'])) { return new \WP_Error('atlas_invalid_traveler','A name and valid birth date are required.', ['status'=>422]); }
                $result=Records::create_idempotent('traveler',$data,get_current_user_id(),(string)$r->get_header('Idempotency-Key'));
                if (is_wp_error($result)) { return $result; } $response=new \WP_REST_Response($result,201); $response->header('Cache-Control','private, no-store'); return $response;
            }]
        ]);
        register_rest_route('agency/v1','/bookings/(?P<id>[a-f0-9-]{36})',['methods'=>'GET','permission_callback'=>static function() { return is_user_logged_in(); },'callback'=>static function(\WP_REST_Request $r) {
            $result=Booking::get((string)$r['id'],get_current_user_id());
            if (is_wp_error($result)) { return $result; }
            $response=rest_ensure_response($result); $response->header('Cache-Control','private, no-store'); return $response;
        }]);
        register_rest_route('agency/v1','/booking-queue',[
            'methods'=>'GET','permission_callback'=>static function() { return Auth::agency() && Auth::can('atlas_manage_bookings',null,null,'read'); },
            'args'=>['page'=>['type'=>'integer','default'=>1,'minimum'=>1,'maximum'=>100000],'limit'=>['type'=>'integer','default'=>25,'minimum'=>1,'maximum'=>50],'status'=>['type'=>'string','default'=>'paid_pending_confirmation','enum'=>['paid_pending_confirmation','exception','confirmed']]],
            'callback'=>static function(\WP_REST_Request $r) { $result=Booking::queue(get_current_user_id(),(int)$r['page'],(int)$r['limit'],(string)$r['status']); if (is_wp_error($result)) { return $result; } $response=new \WP_REST_Response($result,200); $response->header('Cache-Control','private, no-store'); return $response; }
        ]);
        register_rest_route('agency/v1','/bookings/(?P<id>[a-f0-9-]{36})/confirm',[
            'methods'=>'POST','permission_callback'=>static function() { return Auth::agency() && Auth::can('atlas_manage_bookings'); },
            'callback'=>static function(\WP_REST_Request $r) {
                $data=$r->get_json_params(); $key=(string)$r->get_header('Idempotency-Key');
                if (!is_array($data) || array_diff(array_keys($data),['reason']) || !isset($data['reason']) || !is_string($data['reason']) || trim($data['reason'])==='' || strlen($data['reason'])>190 || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$key)) { return new \WP_Error('atlas_invalid_request','A bounded reason and idempotency key are required.',['status'=>422]); }
                $result=Booking::confirm((string)$r['id'],get_current_user_id(),$data['reason'],$key);
                if (is_wp_error($result)) { return $result; } $response=new \WP_REST_Response($result,200); $response->header('Cache-Control','private, no-store'); return $response;
            }
        ]);
    }
}
final class CLI {
    public function migrate(): void { $r=DB::migrate(); if (is_wp_error($r)) { \WP_CLI::error($r->get_error_message()); } \WP_CLI::success('Atlas schema is current.'); }
    public function expire(array $args): void {
        if (empty($args[0])) { \WP_CLI::error('Hold ID required.'); }
        $r=Booking::expire((string)$args[0]); if (is_wp_error($r)) { \WP_CLI::error($r->get_error_message()); }
        \WP_CLI::success('Hold state: '.$r['state']);
    }
    public function run_due(): void { $n=Jobs::expire_due(); $sent=Jobs::dispatch_outbox(); \WP_CLI::success('Processed '.$n.' due holds and '.$sent.' events.'); }
}
final class Jobs {
    public static function dispatch_outbox(): int {
        global $wpdb;
        if (!has_filter('atlas_core_deliver_outbox')) { return 0; }
        $table=DB::table('outbox'); $now=DB::now();
        $stale=(new \DateTimeImmutable($now,new \DateTimeZone('UTC')))->modify('-5 minutes')->format('Y-m-d H:i:s.u');
        $wpdb->query($wpdb->prepare("UPDATE $table SET status=%s,claimed_at=NULL WHERE status=%s AND claimed_at<%s",'pending','processing',$stale));
        $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE status=%s AND (available_at IS NULL OR available_at<=%s) ORDER BY created_at LIMIT 25",'pending',$now));
        $delivered=0;
        foreach ($ids as $id) {
            $claimed=$wpdb->query($wpdb->prepare("UPDATE $table SET status=%s,claimed_at=%s,attempts=attempts+1 WHERE id=%s AND status=%s",'processing',DB::now(),$id,'pending'));
            if ($claimed!==1) { continue; }
            $event=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%s",$id),ARRAY_A);
            try { $ok=apply_filters('atlas_core_deliver_outbox',null,$event)===true; } catch (\Throwable $e) { $ok=false; }
            if ($ok) { $wpdb->update($table,['status'=>'delivered','claimed_at'=>null],['id'=>$id,'status'=>'processing']); $delivered++; }
            else {
                $attempt=(int)$event['attempts']; $status=$attempt>=5 ? 'failed' : 'pending';
                $next=(new \DateTimeImmutable(DB::now(),new \DateTimeZone('UTC')))->modify('+'.min(3600,30*(2**min($attempt,7))).' seconds')->format('Y-m-d H:i:s.u');
                $wpdb->update($table,['status'=>$status,'claimed_at'=>null,'available_at'=>$next],['id'=>$id,'status'=>'processing']);
            }
        }
        return $delivered;
    }
    public static function expire_due(): int {
        global $wpdb;
        $ids=$wpdb->get_col($wpdb->prepare('SELECT id FROM '.DB::table('holds').' WHERE state=%s AND expires_at<=%s ORDER BY expires_at LIMIT 25','active',DB::now()));
        $n=0; foreach ($ids as $id) { if (!is_wp_error(Booking::expire($id))) { $n++; } }
        return $n;
    }
}
