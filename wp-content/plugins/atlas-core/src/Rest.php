<?php
namespace Atlas\Core;
if (!defined('ABSPATH')) { exit; }
final class Rest {
    public static function register(): void { add_action('rest_api_init',[self::class,'routes']); }
    public static function routes(): void {
        register_rest_route('agency/v1','/catalog',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>static function(\WP_REST_Request $r) { return self::catalog($r,true); }]);
        register_rest_route('agency/v1','/catalog/search',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>static function(\WP_REST_Request $r) { return self::catalog($r,false); }]);
        register_rest_route('agency/v1','/catalog/compare',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>static function(\WP_REST_Request $r) {
            $locale=$r->get_param('locale')??'en';if (!is_string($locale)) return new \WP_Error('atlas_invalid_catalog','Invalid locale.',['status'=>422]);
            $result=Catalog::compare($r->get_param('ids'),$locale);if (is_wp_error($result)) return $result;$response=new \WP_REST_Response($result);$response->header('Cache-Control','no-store');return $response;
        }]);
        register_rest_route('agency/v1','/holds',['methods'=>'POST','permission_callback'=>static function() { return is_user_logged_in() ? true : new \WP_Error('atlas_auth_required',__('Sign in to reserve.','atlas-core'),['status'=>401]); },'callback'=>static function(\WP_REST_Request $r) {
            $data=$r->get_json_params(); if (!is_array($data)) { return new \WP_Error('atlas_invalid_request','JSON object required.',['status'=>400]); }
            foreach (['offer_id','offer_version','quantity'] as $k) { if (!isset($data[$k]) || !is_int($data[$k])) { return new \WP_Error('atlas_invalid_request','Offer, version and quantity must be integers.',['status'=>422]); } }
            $refs=$data['participant_refs'] ?? []; if (!is_array($refs)) { return new \WP_Error('atlas_invalid_participant','Participant references must be a list.', ['status'=>422]); }
            $options=$data['options'] ?? []; if (!is_array($options) || ($options && array_is_list($options))) { return new \WP_Error('atlas_invalid_options','Booking options must be an object.',['status'=>422]); }
            $result=Booking::hold($data['offer_id'],$data['offer_version'],$data['quantity'],get_current_user_id(),(string)$r->get_header('Idempotency-Key'),$refs,$options);
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
    private static function catalog(\WP_REST_Request $request,bool $legacy): \WP_REST_Response|\WP_Error {
        $result=Catalog::search($request->get_query_params());if (is_wp_error($result)) return $result;
        $response=new \WP_REST_Response($legacy?$result['items']:$result);$response->header('X-WP-Total',(string)$result['total']);$response->header('X-WP-TotalPages',(string)$result['total_pages']);$response->header('Cache-Control','no-store');return $response;
    }
}
final class CLI {
    public function migrate(): void { $r=DB::migrate(); if (is_wp_error($r)) { \WP_CLI::error($r->get_error_message()); } \WP_CLI::success('Atlas schema is current.'); }
    public function expire(array $args): void {
        if (empty($args[0])) { \WP_CLI::error('Hold ID required.'); }
        $r=Booking::expire((string)$args[0]); if (is_wp_error($r)) { \WP_CLI::error($r->get_error_message()); }
        \WP_CLI::success('Hold state: '.$r['state']);
    }
    /**
     * Verify the installed Atlas stack and optionally production configuration.
     *
     * ## OPTIONS
     *
     * [--production]
     * : Require production environment, HTTPS and a successful worker run.
     *
     * [--service-payments]
     * : Require the configured ZarinPal gateway to pass its local readiness checks.
     */
    public function preflight(array $args, array $options): void {
        $checks=Preflight::checks(isset($options['production']),isset($options['service-payments']));
        \WP_CLI::line(wp_json_encode($checks,JSON_PRETTY_PRINT));
        if (in_array(false,$checks,true)) \WP_CLI::halt(1);
    }
    public function run_due(): void {
        $result=Jobs::run();
        if (is_wp_error($result)) \WP_CLI::error($result->get_error_message());
        if ($result['errors']>0) \WP_CLI::error('Atlas worker finished with '.$result['errors'].' errors. Inspect Operations health.');
        \WP_CLI::success('Processed '.$result['due_holds'].' due holds and '.$result['delivered_events'].' events.');
    }
}
final class Jobs {
    private const BATCH_SIZE = 25;

    public static function run(): array|\WP_Error {
        global $wpdb;
        $ready=DB::ready();
        if (is_wp_error($ready)) return $ready;
        $key='atlas_worker_'.md5(DB_NAME.':'.$wpdb->prefix);
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$key))!=='1') {
            return new \WP_Error('atlas_worker_unavailable','An Atlas worker is active or the worker lock is unavailable.');
        }
        try {
            $started=DB::now();
            $holds=self::expire_due();
            $events=self::dispatch_outbox();
            $heartbeat=[
                'started_at'=>$started,
                'finished_at'=>DB::now(),
                'due_holds'=>$holds['released'],
                'delivered_events'=>is_wp_error($events)?0:$events,
                'errors'=>$holds['errors']+(is_wp_error($events)?1:0),
            ];
            if (!update_option('atlas_core_worker_heartbeat',$heartbeat,false)
                && get_option('atlas_core_worker_heartbeat')!==$heartbeat) {
                return new \WP_Error('atlas_worker_heartbeat','Atlas could not record worker completion.');
            }
            return $heartbeat;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key));
        }
    }

    public static function dispatch_outbox(): int|\WP_Error {
        global $wpdb;
        if (!has_filter('atlas_core_deliver_outbox')) { return 0; }
        $table=DB::table('outbox');
        $now=DB::now();
        $error=new \WP_Error('atlas_worker_storage','Atlas could not read or update the outbox.');
        $stale=(new \DateTimeImmutable($now,new \DateTimeZone('UTC')))->modify('-5 minutes')->format('Y-m-d H:i:s.u');
        $reset=$wpdb->query($wpdb->prepare("UPDATE $table SET status=%s,claimed_at=NULL WHERE status=%s AND claimed_at<%s",'pending','processing',$stale));
        if ($reset===false) return $error;
        $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE status=%s AND (available_at IS NULL OR available_at<=%s) ORDER BY created_at,id LIMIT %d",'pending',$now,self::BATCH_SIZE));
        if ($wpdb->last_error!=='') return $error;
        $delivered=0;
        foreach ($ids as $id) {
            $claimTime=DB::now();
            $claimed=$wpdb->query($wpdb->prepare("UPDATE $table SET status=%s,claimed_at=%s,attempts=attempts+1 WHERE id=%s AND status=%s AND (available_at IS NULL OR available_at<=%s)",'processing',$claimTime,$id,'pending',$claimTime));
            if ($claimed===false) return $error;
            if ($claimed!==1) continue;
            $claim=['id'=>$id,'status'=>'processing','claimed_at'=>$claimTime];
            $event=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%s AND status=%s AND claimed_at=%s",$id,'processing',$claimTime),ARRAY_A);
            if (!$event) return $error;
            try {
                $ok=apply_filters('atlas_core_deliver_outbox',null,$event)===true;
            } catch (\Throwable $failure) {
                $ok=false;
            }
            $claim['attempts']=(int)$event['attempts'];
            if ($ok) {
                $change=['status'=>'delivered','claimed_at'=>null];
            } else {
                $attempt=$claim['attempts'];
                $delay=min(3600,30*(2**min($attempt,7)));
                $next=(new \DateTimeImmutable(DB::now(),new \DateTimeZone('UTC')))->modify('+'.$delay.' seconds')->format('Y-m-d H:i:s.u');
                $change=['status'=>$attempt>=5?'failed':'pending','claimed_at'=>null,'available_at'=>$next];
            }
            $saved=$wpdb->update($table,$change,$claim);
            if ($saved!==1) return $error;
            if ($ok) ++$delivered;
        }
        return $delivered;
    }

    private static function expire_due(): array {
        global $wpdb;
        $table=DB::table('holds');
        $now=DB::now();
        $cursor=get_option('atlas_core_expiry_resume',[]);
        $query="SELECT id,expires_at FROM $table WHERE state=%s AND expires_at<=%s";
        $params=['active',$now];
        if (is_array($cursor) && isset($cursor['expires_at'],$cursor['id'])) {
            $query.=' AND (expires_at>%s OR (expires_at=%s AND id>%s))';
            array_push($params,$cursor['expires_at'],$cursor['expires_at'],$cursor['id']);
        }
        $rows=$wpdb->get_results($wpdb->prepare($query.' ORDER BY expires_at,id LIMIT %d',array_merge($params,[self::BATCH_SIZE])),ARRAY_A);
        if ($wpdb->last_error!=='') return ['released'=>0,'errors'=>1];
        $released=0;
        $errors=0;
        foreach ($rows as $row) {
            $result=Booking::expire($row['id']);
            if (is_wp_error($result)) {
                ++$errors;
            } elseif ($result['state']==='released') {
                ++$released;
            }
        }
        // Rotate past a full page even when a bad hold keeps failing to release.
        // When the tail is reached, retry older failures and new arrivals.
        if (count($rows)===self::BATCH_SIZE && ($errors>0 || !empty($cursor))) {
            update_option('atlas_core_expiry_resume',end($rows),false);
        } else {
            delete_option('atlas_core_expiry_resume');
        }
        return ['released'=>$released,'errors'=>$errors];
    }
}
