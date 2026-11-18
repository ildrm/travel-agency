<?php
if (!defined('ABSPATH')) { exit; }
use Atlas\Core\DB;
use Atlas\Core\Booking;
use Atlas\Core\VerifiedPayment;

/** Prepared ZarinPal V4 transport. All deployment gates default off. */
final class Atlas_ZarinPal {
    private static $schema=false;
    private static $testConfig=null;
    private static $testTransport=null;
    public static function table() { global $wpdb; return $wpdb->prefix.'atlas_zarinpal_attempts'; }
    public static function t($en,$fa) { return (isset($_GET['lang']) && $_GET['lang']==='fa') || str_starts_with(determine_locale(),'fa')?$fa:$en; }
    private static function error($code,$message,$status=409) { return new WP_Error($code,$message,array('status'=>$status)); }
    private static function constant($key,$default=null) { return defined($key)?constant($key):$default; }
    private static function config() {
        $mode=self::constant('ATLAS_ZARINPAL_MODE',''); $merchant=self::constant('ATLAS_ZARINPAL_MERCHANT_ID',''); $callback=function_exists('WC') && WC()?WC()->api_request_url('atlas_zarinpal'):add_query_arg('wc-api','atlas_zarinpal',home_url('/')); $test=false;
        if (self::$testConfig!==null) { $mode=self::$testConfig['mode']??''; $merchant=self::$testConfig['merchant_id']??''; $callback='https://atlas.example.invalid/?wc-api=atlas_zarinpal'; $test=true; }
        if (!is_string($mode) || !in_array($mode,array('sandbox','live'),true) || !is_string($merchant) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$merchant) || !str_starts_with($callback,'https://')) return null;
        $environment=wp_get_environment_type();
        if ($test) {
            if ($mode!=='sandbox' || $environment!=='local' || PHP_SAPI!=='cli' || !defined('ATLAS_TEST_GATEWAYS') || ATLAS_TEST_GATEWAYS!==true || !self::$testTransport || has_filter('pre_http_request',array(__CLASS__,'intercept_test_http'))===false) return null;
        } else {
            if (self::constant('ATLAS_ZARINPAL_MERCHANT_CONFIRMED')!==true) return null;
            if ($mode==='sandbox' && (!in_array($environment,array('local','development','staging'),true) || self::constant('ATLAS_ZARINPAL_SANDBOX_ENABLED')!==true)) return null;
            if ($mode==='live' && ($environment!=='production' || self::constant('ATLAS_ZARINPAL_LIVE_ENABLED')!==true || self::constant('ATLAS_ZARINPAL_LIVE_APPROVED')!==true || !is_string(self::constant('ATLAS_ZARINPAL_MERCHANT_EVIDENCE')) || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',self::constant('ATLAS_ZARINPAL_MERCHANT_EVIDENCE')))) return null;
        }
        $host=$mode==='sandbox'?'sandbox.zarinpal.com':'payment.zarinpal.com';
        return array('mode'=>$mode,'merchant_id'=>$merchant,'host'=>$host,'callback'=>$callback,'fingerprint'=>hash('sha256',wp_json_encode(array($mode,$merchant,$callback))),'test'=>$test);
    }
    public static function ready() { return self::$schema && self::config()!==null && class_exists(Booking::class) && DB::ready()===true; }
    /** Fixture-only configuration always installs a fail-closed WP HTTP interceptor. */
    public static function set_test_config(?array $config,?Closure $transport=null) {
        if (PHP_SAPI!=='cli' || wp_get_environment_type()!=='local' || !defined('ATLAS_TEST_GATEWAYS') || ATLAS_TEST_GATEWAYS!==true) throw new LogicException('Gateway fixture configuration is unavailable.');
        remove_filter('pre_http_request',array(__CLASS__,'intercept_test_http'),PHP_INT_MAX);
        self::$testConfig=$config; self::$testTransport=$transport;
        if ($config!==null) add_filter('pre_http_request',array(__CLASS__,'intercept_test_http'),PHP_INT_MAX,3);
    }
    public static function intercept_test_http($pre,$args,$url) {
        if (self::$testConfig===null) return $pre;
        if (!self::$testTransport) return new WP_Error('atlas_test_http_blocked','No gateway fixture transport.');
        $result=call_user_func(self::$testTransport,$url,$args);
        return $result===false || $result===null?new WP_Error('atlas_test_http_blocked','Fixture HTTP escape blocked.'):$result;
    }
    public static function install() {
        global $wpdb; $table=self::table(); require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $ddl="id char(36) NOT NULL, order_id bigint unsigned NOT NULL, customer_id bigint unsigned NOT NULL, booking_id char(36) NOT NULL DEFAULT '', amount_irr bigint unsigned NOT NULL, currency char(3) NOT NULL, environment varchar(8) NOT NULL, merchant_fingerprint char(64) NOT NULL, state_hash char(64) NOT NULL, authority varchar(64) NULL, provider_ref varchar(64) NULL, state varchar(24) NOT NULL, provider_verified tinyint unsigned NOT NULL DEFAULT 0, last_error varchar(40) NOT NULL DEFAULT '', created_at datetime(6) NOT NULL, updated_at datetime(6) NOT NULL, checked_at datetime(6) NULL, verified_at datetime(6) NULL, PRIMARY KEY  (id), UNIQUE KEY order_once (order_id), UNIQUE KEY authority_once (environment,authority), UNIQUE KEY proof_once (environment,merchant_fingerprint,provider_ref), UNIQUE KEY callback_once (state_hash)";
        if (get_option('atlas_zarinpal_schema')!=='1') dbDelta("CREATE TABLE $table (\n".preg_replace('/, (?![^()]*\))/',",\n",$ddl)."\n) ENGINE=InnoDB ".$wpdb->get_charset_collate().';');
        $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table)); $columns=$wpdb->get_results("SHOW COLUMNS FROM `$table`",ARRAY_A); $found=array();
        foreach ((array)$columns as $column) $found[$column['Field']]=strtolower(preg_replace('/\b(bigint|tinyint)\([0-9]+\)/','$1',$column['Type']));
        $types=array('id'=>'char(36)','order_id'=>'bigint unsigned','customer_id'=>'bigint unsigned','booking_id'=>'char(36)','amount_irr'=>'bigint unsigned','currency'=>'char(3)','environment'=>'varchar(8)','merchant_fingerprint'=>'char(64)','state_hash'=>'char(64)','authority'=>'varchar(64)','provider_ref'=>'varchar(64)','state'=>'varchar(24)','provider_verified'=>'tinyint unsigned','last_error'=>'varchar(40)','created_at'=>'datetime(6)','updated_at'=>'datetime(6)','checked_at'=>'datetime(6)','verified_at'=>'datetime(6)');
        $ok=strtoupper((string)$engine)==='INNODB'; foreach ($types as $name=>$type) if (($found[$name]??null)!==$type) $ok=false;
        $indexes=array(); foreach ((array)$wpdb->get_results("SHOW INDEX FROM `$table`",ARRAY_A) as $index) if ((int)$index['Non_unique']===0) $indexes[$index['Key_name']][(int)$index['Seq_in_index']]=$index['Column_name'];
        foreach (array('PRIMARY'=>array('id'),'order_once'=>array('order_id'),'authority_once'=>array('environment','authority'),'proof_once'=>array('environment','merchant_fingerprint','provider_ref'),'callback_once'=>array('state_hash')) as $name=>$shape) { $parts=$indexes[$name]??array(); ksort($parts); if (array_values($parts)!==$shape) $ok=false; }
        self::$schema=$ok; if ($ok) update_option('atlas_zarinpal_schema','1',false); else delete_option('atlas_zarinpal_schema');
        return $ok;
    }
    private static function attempt($id,$order=false) { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE '.($order?'order_id=%d':'id=%s'),$id)); }
    private static function lock($order) { global $wpdb; $key='atlas_zp_'.hash('sha256',$wpdb->prefix.':'.$order); $key=substr($key,0,64); return (string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$key))==='1'?$key:false; }
    private static function unlock($key) { global $wpdb; $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key)); }
    private static function save($id,array $changes,$action,$reason='') {
        global $wpdb; if ($wpdb->query('START TRANSACTION')===false) return false;
        try { $changes['updated_at']=DB::now(); if ($wpdb->update(self::table(),$changes,array('id'=>$id))===false || !DB::audit(0,0,'zarinpal.'.$action,$id,'recorded',$reason) || !DB::emit('zarinpal.'.$action,$id)) throw new RuntimeException('write'); if ($wpdb->query('COMMIT')===false) throw new RuntimeException('commit'); return true; }
        catch (Throwable $e) { $wpdb->query('ROLLBACK'); return false; }
    }
    private static function authority($value) { return is_string($value) && preg_match('/^[AS][0-9a-zA-Z]{35}$/D',$value); }
    private static function reference($value) { return (is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]{0,63}$/D',(string)$value)?(string)$value:null; }
    /** WP HTTP only, fixed HTTPS hosts/paths, no redirects, no raw provider fields persisted. */
    private static function http($method,array $body,array $config) {
        if (!in_array($method,array('request','verify','inquiry'),true) || !in_array($config['host'],array('payment.zarinpal.com','sandbox.zarinpal.com'),true) || ($config['test'] && has_filter('pre_http_request',array(__CLASS__,'intercept_test_http'))===false)) return self::error('atlas_zarinpal_transport','Payment transport unavailable.');
        $url='https://'.$config['host'].'/pg/v4/payment/'.$method.'.json';
        $response=wp_safe_remote_post($url,array('timeout'=>15,'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true,'limit_response_size'=>65536,'headers'=>array('Content-Type'=>'application/json','Accept'=>'application/json'),'body'=>wp_json_encode($body)));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200) return self::error('atlas_zarinpal_unknown','Payment response requires reconciliation.');
        $raw=wp_remote_retrieve_body($response); if (!is_string($raw) || strlen($raw)>65536) return self::error('atlas_zarinpal_unknown','Payment response requires reconciliation.');
        $json=json_decode($raw,true,32,JSON_BIGINT_AS_STRING); if (!is_array($json) || !empty($json['errors']) || !is_array($json['data']??null) || !is_int($json['data']['code']??null)) return self::error('atlas_zarinpal_unknown','Payment response requires reconciliation.');
        $data=$json['data'];
        if ((array_key_exists('authority',$data) && !self::authority($data['authority'])) || (array_key_exists('amount',$data) && !is_int($data['amount']))) return self::error('atlas_zarinpal_unknown','Payment response requires reconciliation.');
        return array('code'=>$data['code'],'authority'=>$data['authority']??null,'ref_id'=>self::reference($data['ref_id']??null),'status'=>is_string($data['status']??null)?substr($data['status'],0,32):null,'amount'=>$data['amount']??null);
    }
    private static function amount($order) {
        $value=(string)$order->get_total(); if ($order->get_currency()!=='IRR' || !preg_match('/^([1-9][0-9]{0,14})(?:\.0+)?$/D',$value,$m) || (int)$m[1]<10000) return null; return (int)$m[1];
    }
    private static function context($order) {
        global $wpdb; if (!$order instanceof WC_Order || (int)$order->get_customer_id()<1 || ($amount=self::amount($order))===null) return self::error('atlas_zarinpal_order','The order cannot be paid with this method.');
        $ids=array(); $physical=false;
        foreach ($order->get_items('line_item') as $line) { $id=$line->get_meta('_atlas_booking_id',true); $service=(int)get_post_meta((int)$line->get_product_id(),'_atlas_offer_id',true)>0;
            if ($service && (!is_string($id) || $id==='')) return self::error('atlas_zarinpal_order','A valid reservation is required.');
            if (is_string($id) && $id!=='') $ids[$id]=true; else $physical=true;
        }
        if (count($ids)>1 || ($ids && $physical)) return self::error('atlas_zarinpal_order','Separate service checkout is required.');
        $booking=$ids?(string)array_key_first($ids):'';
        if ($booking!=='') { $b=$wpdb->get_row($wpdb->prepare('SELECT user_id,order_id,amount_irr FROM '.DB::table('bookings').' WHERE id=%s',$booking)); if (!$b || (int)$b->user_id!==(int)$order->get_customer_id() || (int)$b->order_id!==(int)$order->get_id() || (int)$b->amount_irr!==$amount) return self::error('atlas_zarinpal_order','The order and reservation do not match.'); }
        return array('amount'=>$amount,'booking_id'=>$booking,'customer_id'=>(int)$order->get_customer_id());
    }
    private static function initiate_guard($order,$context) {
        if (!is_user_logged_in() || get_current_user_id()!==(int)$order->get_customer_id() || !$order->needs_payment() || $order->get_payment_method()!=='atlas_zarinpal') return self::error('atlas_zarinpal_owner','Sign in to your unpaid ZarinPal order before payment.');
        return $context['booking_id']!==''?Booking::can_initiate_payment($context['booking_id'],get_current_user_id(),(int)$order->get_id()):true;
    }
    private static function redirect($authority,$environment) {
        if (!self::authority($authority) || !in_array($environment,array('sandbox','live'),true)) return null;
        return 'https://'.($environment==='sandbox'?'sandbox.zarinpal.com':'payment.zarinpal.com').'/pg/StartPay/'.$authority;
    }
    private static function protects_order($order,$method) {
        if (!self::$schema || !$order instanceof WC_Order || $order->is_paid()) return false;
        $attempt=self::attempt((int)$order->get_id(),true);
        return $attempt && ($method!=='atlas_zarinpal' || $attempt->state!=='pending' || (int)$attempt->provider_verified);
    }
    public static function guarded_gateways($gateways) {
        global $wp; $id=absint($wp->query_vars['order-pay']??0); if (!$id || !self::$schema) return $gateways;
        $order=wc_get_order($id); if (!$order || $order->is_paid() || !self::attempt($id,true)) return $gateways;
        foreach ($gateways as $method=>$gateway) if (self::protects_order($order,(string)$method)) unset($gateways[$method]); return $gateways;
    }
    public static function guard_store_payment($context) {
        if (self::protects_order($context->order,(string)$context->payment_method)) throw new Exception(self::t('An existing ZarinPal attempt must be reconciled before another payment method is used.','پیش از استفاده از روش پرداخت دیگر، تلاش موجود زرین‌پال باید تطبیق داده شود.'));
    }
    public static function guard_classic_order($orderId,$data,$order) {
        if (self::protects_order($order,(string)$order->get_payment_method())) throw new Exception(self::t('The existing payment outcome needs agency reconciliation. Do not pay again.','نتیجه پرداخت موجود نیازمند تطبیق آژانس است. دوباره پرداخت نکنید.'));
    }
    public static function guard_order_pay($order) {
        $method=isset($_POST['payment_method']) && is_string($_POST['payment_method'])?sanitize_key(wp_unslash($_POST['payment_method'])):'';
        if (self::protects_order($order,$method)) wc_add_notice(self::t('The existing payment outcome needs agency reconciliation. Do not pay again.','نتیجه پرداخت موجود نیازمند تطبیق آژانس است. دوباره پرداخت نکنید.'),'error');
    }
    public static function initiate($orderId) {
        global $wpdb; $order=wc_get_order($orderId); if (!$order || !is_user_logged_in() || (int)$order->get_customer_id()!==get_current_user_id()) return self::error('atlas_zarinpal_owner',self::t('Sign in to the account that owns this order.','با حساب صاحب این سفارش وارد شوید.'));
        if ($order->is_paid()) return array('result'=>'success','redirect'=>$order->get_checkout_order_received_url());
        if (!self::ready() || !($config=self::config())) return self::error('atlas_zarinpal_disabled',self::t('ZarinPal is not enabled for this environment.','زرین‌پال برای این محیط فعال نشده است.'));
        $context=self::context($order); if (is_wp_error($context)) return $context; $guard=self::initiate_guard($order,$context); if (is_wp_error($guard)) return $guard;
        $lock=self::lock((int)$orderId); if (!$lock) return self::error('atlas_zarinpal_busy',self::t('A payment action is already in progress.','یک عملیات پرداخت در حال انجام است.'));
        try {
            $order=wc_get_order($orderId); if (!$order) return self::error('atlas_zarinpal_order','Order unavailable.'); if ($order->is_paid()) return array('result'=>'success','redirect'=>$order->get_checkout_order_received_url());
            $context=self::context($order); if (is_wp_error($context)) return $context; $guard=self::initiate_guard($order,$context); if (is_wp_error($guard)) return $guard;
            $prior=self::attempt((int)$orderId,true);
            if ($prior) {
                if ((int)$prior->amount_irr!==$context['amount'] || (int)$prior->customer_id!==$context['customer_id'] || $prior->booking_id!==$context['booking_id'] || $prior->currency!=='IRR' || !hash_equals($prior->merchant_fingerprint,$config['fingerprint'])) return self::error('atlas_zarinpal_review',self::t('Payment details changed. Contact the agency for reconciliation.','اطلاعات پرداخت تغییر کرده است. برای تطبیق با آژانس تماس بگیرید.'));
                if ($prior->state==='pending' && self::authority($prior->authority)) return array('result'=>'success','redirect'=>self::redirect($prior->authority,$prior->environment));
                return self::error('atlas_zarinpal_review',self::t('The prior payment outcome needs review. Do not start another payment.','نتیجه پرداخت قبلی نیازمند بررسی است. پرداخت دیگری آغاز نکنید.'));
            }
            $id=wp_generate_uuid4(); $state=bin2hex(random_bytes(32)); $now=DB::now();
            if ($wpdb->query('START TRANSACTION')===false) return self::error('atlas_zarinpal_storage','Payment could not start.');
            try { if (!$wpdb->insert(self::table(),array('id'=>$id,'order_id'=>(int)$orderId,'customer_id'=>$context['customer_id'],'booking_id'=>$context['booking_id'],'amount_irr'=>$context['amount'],'currency'=>'IRR','environment'=>$config['mode'],'merchant_fingerprint'=>$config['fingerprint'],'state_hash'=>hash('sha256',$state),'state'=>'requesting','created_at'=>$now,'updated_at'=>$now)) || !DB::audit(get_current_user_id(),0,'zarinpal.request_prepare',$id,'success') || !DB::emit('zarinpal.request_prepare',$id)) throw new RuntimeException('prepare'); if ($wpdb->query('COMMIT')===false) throw new RuntimeException('commit'); }
            catch (Throwable $e) { $wpdb->query('ROLLBACK'); return self::error('atlas_zarinpal_storage','Payment could not start.'); }
            // Durable requesting state precedes I/O; crash/timeout cannot initiate again.
            $guard=self::initiate_guard($order,$context); if (is_wp_error($guard)) { self::save($id,array('state'=>'request_unknown','last_error'=>'deadline_before_request'),'request_review'); return $guard; }
            $callback=add_query_arg(array('attempt'=>$id,'state'=>$state),$config['callback']);
            $response=self::http('request',array('merchant_id'=>$config['merchant_id'],'amount'=>$context['amount'],'currency'=>'IRR','callback_url'=>$callback,'description'=>'Atlas order '.(int)$orderId),$config);
            if (is_wp_error($response) || $response['code']!==100 || !$response['authority']) { self::save($id,array('state'=>'request_unknown','last_error'=>'request_response_unknown'),'request_review'); return self::error('atlas_zarinpal_unknown',self::t('Payment request outcome is uncertain. Agency reconciliation is required before retrying.','نتیجه درخواست پرداخت نامشخص است. پیش از تلاش دوباره تطبیق آژانس لازم است.')); }
            if (!self::save($id,array('authority'=>$response['authority'],'state'=>'pending','last_error'=>''),'authority_stored')) return self::error('atlas_zarinpal_storage','Payment reference could not be safely recorded.');
            $order=wc_get_order($orderId); $fresh=self::context($order); $guard=is_wp_error($fresh)?$fresh:self::initiate_guard($order,$fresh);
            if (is_wp_error($guard) || $fresh['amount']!==$context['amount'] || $fresh['booking_id']!==$context['booking_id']) return self::error('atlas_zarinpal_review',self::t('The reservation changed or expired before redirect. Contact the agency.','پیش از انتقال، رزرو تغییر کرده یا منقضی شده است. با آژانس تماس بگیرید.'));
            return array('result'=>'success','redirect'=>self::redirect($response['authority'],$config['mode']));
        } catch (Throwable $e) { if (isset($id)) self::save($id,array('state'=>'request_unknown','last_error'=>'request_interrupted'),'request_review'); return self::error('atlas_zarinpal_review',self::t('The payment outcome needs agency review. Do not pay again.','نتیجه پرداخت نیازمند بررسی آژانس است. دوباره پرداخت نکنید.')); }
        finally { self::unlock($lock); }
    }
    private static function apply_proof($attempt) {
        if (!(int)$attempt->provider_verified || !self::reference($attempt->provider_ref)) return self::error('atlas_zarinpal_review','No stored independent provider proof.');
        if ($attempt->booking_id!=='') {
            $reference=$attempt->merchant_fingerprint.':'.$attempt->provider_ref;
            $proof=VerifiedPayment::from_adapter('zarinpal_v4_'.$attempt->environment,$reference,(int)$attempt->amount_irr);
            $result=is_wp_error($proof)?$proof:Booking::verify($attempt->booking_id,$proof);
            if (is_wp_error($result) || ($result['payment_status']??'')!=='verified') { self::save($attempt->id,array('state'=>'core_pending','last_error'=>'domain_reconciliation_pending'),'domain_review'); return self::error('atlas_zarinpal_review','Verified money requires domain reconciliation.'); }
        }
        $order=wc_get_order((int)$attempt->order_id); $context=$order?self::context($order):self::error('atlas_zarinpal_order','Order unavailable.');
        if (is_wp_error($context) || (int)$context['amount']!==(int)$attempt->amount_irr || $context['customer_id']!==(int)$attempt->customer_id || $context['booking_id']!==$attempt->booking_id) { self::save($attempt->id,array('state'=>'core_pending','last_error'=>'order_binding_changed'),'order_review'); return self::error('atlas_zarinpal_review','Verified money requires order review.'); }
        $transaction='atlas-zarinpal-'.$attempt->environment.'-'.$attempt->provider_ref;
        if ($order->is_paid() && $order->get_transaction_id()!==$transaction) {
            self::save($attempt->id,array('state'=>'review','last_error'=>'other_payment_on_order'),'excess_payment_review');
            return self::error('atlas_zarinpal_review','Verified additional money requires manual review.');
        }
        if ($order->get_payment_method()!=='atlas_zarinpal') { self::save($attempt->id,array('state'=>'core_pending','last_error'=>'payment_method_changed'),'order_review'); return self::error('atlas_zarinpal_review','Verified money requires payment-method review.'); }
        if (!$order->is_paid() && !$order->needs_payment()) { self::save($attempt->id,array('state'=>'core_pending','last_error'=>'order_state_requires_review'),'order_review'); return self::error('atlas_zarinpal_review','Verified money requires order-state review.'); }
        try { if (!$order->is_paid()) $order->payment_complete($transaction); }
        catch (Throwable $e) { self::save($attempt->id,array('state'=>'core_pending','last_error'=>'woo_completion_pending'),'woo_review'); return self::error('atlas_zarinpal_review','Verified money requires order reconciliation.'); }
        $order=wc_get_order((int)$attempt->order_id); if (!$order || !$order->is_paid() || $order->get_transaction_id()!==$transaction) { self::save($attempt->id,array('state'=>'core_pending','last_error'=>'woo_completion_pending'),'woo_review'); return self::error('atlas_zarinpal_review','Verified money requires order reconciliation.'); }
        if ($attempt->state!=='applied' && !self::save($attempt->id,array('state'=>'applied','last_error'=>''),'applied')) return self::error('atlas_zarinpal_review','Payment was recorded; reconciliation needs retry.');
        return array('verified'=>true,'applied'=>true);
    }
    /** No provider I/O or public Core mutations execute inside an attempt transaction. */
    public static function reconcile($attemptId,$force=false) {
        if (!self::$schema) return self::error('atlas_zarinpal_unavailable','Payment storage is unavailable.');
        $attempt=self::attempt($attemptId); if (!$attempt) return self::error('atlas_zarinpal_unavailable','Payment attempt unavailable.');
        $lock=self::lock((int)$attempt->order_id); if (!$lock) return self::error('atlas_zarinpal_busy','Payment reconciliation is in progress.');
        try {
            $attempt=self::attempt($attemptId);
            if ((int)$attempt->provider_verified) return self::apply_proof($attempt);
            $config=self::config(); if (!self::ready() || !$config || !hash_equals($attempt->merchant_fingerprint,$config['fingerprint']) || $attempt->environment!==$config['mode']) return self::error('atlas_zarinpal_configuration','Original merchant/environment configuration is required for reconciliation.');
            if (!self::authority($attempt->authority)) return self::error('atlas_zarinpal_review','Request outcome is unknown; no stored authority is available.');
            if (!$force && $attempt->checked_at && (new DateTimeImmutable(DB::now(),new DateTimeZone('UTC')))->getTimestamp()-(new DateTimeImmutable($attempt->checked_at,new DateTimeZone('UTC')))->getTimestamp()<30) return self::error('atlas_zarinpal_review','Payment status is awaiting reconciliation.');
            if (!self::save($attempt->id,array('state'=>'verifying','checked_at'=>DB::now(),'last_error'=>''),'verify_started')) return self::error('atlas_zarinpal_storage','Reconciliation could not start.');
            $verified=self::http('verify',array('merchant_id'=>$config['merchant_id'],'amount'=>(int)$attempt->amount_irr,'authority'=>$attempt->authority),$config);
            if (!is_wp_error($verified) && $verified['code']===100 && $verified['ref_id']!==null && ($verified['authority']===null || hash_equals($attempt->authority,$verified['authority'])) && ($verified['amount']===null || $verified['amount']===(int)$attempt->amount_irr)) {
                if (!self::save($attempt->id,array('provider_verified'=>1,'provider_ref'=>$verified['ref_id'],'verified_at'=>DB::now(),'state'=>'verified','last_error'=>''),'provider_verified')) return self::error('atlas_zarinpal_storage','Provider verification needs manual reconciliation.');
                return self::apply_proof(self::attempt($attempt->id));
            }
            // Inquiry corroborates operational state; its documented response does
            // not establish a trustworthy ref_id/paid_at for a new Core proof.
            self::http('inquiry',array('merchant_id'=>$config['merchant_id'],'authority'=>$attempt->authority),$config);
            $reason=!is_wp_error($verified) && $verified['code']===101?'already_verified_without_proof':'verification_outcome_unknown';
            self::save($attempt->id,array('state'=>'verify_unknown','last_error'=>$reason),'verify_review');
            return self::error('atlas_zarinpal_review',self::t('Payment verification requires agency review. Do not pay again.','تأیید پرداخت نیازمند بررسی آژانس است. دوباره پرداخت نکنید.'));
        } catch (Throwable $e) { $fresh=self::attempt($attempt->id); self::save($attempt->id,array('state'=>$fresh && (int)$fresh->provider_verified?'core_pending':'verify_unknown','last_error'=>'verification_interrupted'),'verify_review'); return self::error('atlas_zarinpal_review','Verification needs agency reconciliation.'); }
        finally { self::unlock($lock); }
    }
    /** Public transport returns no attempt/order information to unrelated users. */
    public static function callback_result(array $params) {
        $generic=array('status'=>'received');
        if (!self::$schema || !is_string($params['attempt']??null) || !preg_match('/^[a-f0-9-]{36}$/D',$params['attempt']) || !is_string($params['state']??null) || !preg_match('/^[a-f0-9]{64}$/D',$params['state']) || !self::authority($params['Authority']??null)) return $generic;
        $attempt=self::attempt($params['attempt']);
        if (!$attempt || !hash_equals($attempt->state_hash,hash('sha256',$params['state'])) || !is_string($attempt->authority) || !hash_equals($attempt->authority,$params['Authority'])) return $generic;
        // Status, including NOK, is untrusted navigation data. It never mutates money.
        try { self::reconcile($attempt->id); } catch (Throwable $e) { return $generic; }
        $fresh=self::attempt($attempt->id); $order=wc_get_order((int)$attempt->order_id);
        if ($fresh && (int)$fresh->provider_verified && $order && $order->is_paid() && is_user_logged_in() && get_current_user_id()===(int)$fresh->customer_id && get_current_user_id()===(int)$order->get_customer_id()) $generic['redirect']=$order->get_checkout_order_received_url();
        return $generic;
    }
    public static function callback_http() {
        $params=array(); foreach (array('attempt','state','Authority','Status') as $name) if (isset($_GET[$name]) && is_string($_GET[$name])) $params[$name]=wp_unslash($_GET[$name]);
        $result=self::callback_result($params); nocache_headers();
        if (isset($result['redirect'])) { wp_safe_redirect($result['redirect'],303); exit; }
        wp_send_json(array('status'=>'received'),200);
    }
    public static function routes() {
        $permission=static function($r) { return is_user_logged_in() && current_user_can('manage_options') && current_user_can('atlas_manage_finance') && wp_verify_nonce((string)$r->get_header('X-WP-Nonce'),'wp_rest')?true:self::error('atlas_zarinpal_forbidden','Agency finance access and nonce required.',403); };
        register_rest_route('agency/v1','/zarinpal/attempts',array('methods'=>'GET','permission_callback'=>$permission,'callback'=>static function($r) {
            global $wpdb; $page=(int)($r->get_param('page')?:1); if (!self::$schema || $page<1 || $page>10000) return self::error('atlas_zarinpal_unavailable','Payment storage/filter unavailable.',503);
            $items=$wpdb->get_results($wpdb->prepare('SELECT id,order_id,amount_irr,currency,environment,state,provider_verified,last_error,created_at,verified_at FROM '.self::table().' ORDER BY created_at DESC,id LIMIT 25 OFFSET %d',($page-1)*25),ARRAY_A);
            if (!DB::audit(get_current_user_id(),0,'zarinpal.queue','attempts','read')) return self::error('atlas_zarinpal_audit','Read could not be recorded.',503); foreach ($items as &$item) $item['amount_irr']=(string)$item['amount_irr']; unset($item);
            $response=new WP_REST_Response(array('items'=>$items,'page'=>$page,'limit'=>25),200); $response->header('Cache-Control','private, no-store'); return $response;
        }));
        register_rest_route('agency/v1','/zarinpal/attempts/(?P<id>[a-f0-9-]{36})/reconcile',array('methods'=>'POST','permission_callback'=>$permission,'callback'=>static function($r) {
            $b=$r->get_json_params(); if (!is_array($b) || array_keys($b)!==array('reason') || !is_string($b['reason']) || trim($b['reason'])==='' || strlen($b['reason'])>190) return self::error('atlas_zarinpal_reason','A bounded review reason is required.',422);
            if (!DB::audit(get_current_user_id(),0,'zarinpal.reconcile_request',(string)$r['id'],'requested',$b['reason'])) return self::error('atlas_zarinpal_audit','Review could not be recorded.',503);
            $result=self::reconcile((string)$r['id'],true); if (is_wp_error($result)) return $result;
            $response=new WP_REST_Response(array('status'=>'reconciled'),200); $response->header('Cache-Control','private, no-store'); return $response;
        }));
    }
    public static function admin_menu() {
        add_submenu_page('woocommerce',self::t('ZarinPal reconciliation','تطبیق زرین‌پال'),self::t('ZarinPal reconciliation','تطبیق زرین‌پال'),'manage_options','atlas-zarinpal',array(__CLASS__,'admin_page'));
    }
    public static function admin_page() {
        global $wpdb; if (!current_user_can('manage_options') || !current_user_can('atlas_manage_finance')) wp_die(esc_html(self::t('Agency finance access required.','دسترسی مالی آژانس لازم است.')),'',array('response'=>403));
        echo '<div class="wrap"><h1>'.esc_html(self::t('ZarinPal payment reconciliation','تطبیق پرداخت زرین‌پال')).'</h1><p>'.esc_html(self::t('Unknown requests must not be paid again. Reconcile only with the original merchant/environment; this action may verify an existing attempt and never creates another payment request.','درخواست نامشخص را دوباره پرداخت نکنید. فقط با پذیرنده و محیط اصلی تطبیق دهید؛ این عملیات ممکن است تلاش موجود را تأیید کند و درخواست پرداخت جدید نمی‌سازد.')).'</p>';
        if (!self::$schema) { echo '<p>'.esc_html(self::t('Payment storage is unavailable.','نگهداری پرداخت در دسترس نیست.')).'</p></div>'; return; }
        $page=isset($_GET['atlas_zp_page']) && is_scalar($_GET['atlas_zp_page'])?max(1,min(10000,(int)$_GET['atlas_zp_page'])):1;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,order_id,amount_irr,environment,state,provider_verified,last_error FROM '.self::table().' ORDER BY created_at DESC,id LIMIT 25 OFFSET %d',($page-1)*25));
        if (!DB::audit(get_current_user_id(),0,'zarinpal.queue','attempts','read')) { echo '<p>'.esc_html(self::t('Read audit failed.','ثبت خواندن انجام نشد.')).'</p></div>'; return; }
        echo '<table class="widefat"><thead><tr><th>'.esc_html(self::t('Order / exact IRR / environment','سفارش / ریال دقیق / محیط')).'</th><th>'.esc_html(self::t('State / independent verification','وضعیت / تأیید مستقل')).'</th><th>'.esc_html(self::t('Review','بررسی')).'</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>'.esc_html($row->order_id.' / '.$row->amount_irr.' IRR / '.$row->environment).'</td><td>'.esc_html($row->state.' / '.((int)$row->provider_verified?'verified':'unverified').' / '.$row->last_error).'</td><td>';
            if ($row->state!=='applied') echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('atlas_zarinpal_reconcile','_wpnonce',true,false).'<input type="hidden" name="action" value="atlas_zarinpal_reconcile"><input type="hidden" name="attempt" value="'.esc_attr($row->id).'"><label>'.esc_html(self::t('Review reason','دلیل بررسی')).'<input name="reason" maxlength="190" required></label><button>'.esc_html(self::t('Reconcile existing attempt','تطبیق تلاش موجود')).'</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table><p>'; if ($page>1) echo '<a href="'.esc_url(add_query_arg(array('page'=>'atlas-zarinpal','atlas_zp_page'=>$page-1),admin_url('admin.php'))).'">'.esc_html(self::t('Previous','قبل')).'</a> '; if (count($rows)===25) echo '<a href="'.esc_url(add_query_arg(array('page'=>'atlas-zarinpal','atlas_zp_page'=>$page+1),admin_url('admin.php'))).'">'.esc_html(self::t('Next','بعد')).'</a>'; echo '</p></div>';
    }
    public static function admin_reconcile() {
        if (!current_user_can('manage_options') || !current_user_can('atlas_manage_finance')) wp_die('Access denied.','',array('response'=>403)); check_admin_referer('atlas_zarinpal_reconcile');
        $id=isset($_POST['attempt']) && is_string($_POST['attempt'])?wp_unslash($_POST['attempt']):''; $reason=isset($_POST['reason']) && is_string($_POST['reason'])?sanitize_text_field(wp_unslash($_POST['reason'])):'';
        if (!preg_match('/^[a-f0-9-]{36}$/D',$id) || $reason==='' || strlen($reason)>190 || !DB::audit(get_current_user_id(),0,'zarinpal.reconcile_request',$id,'requested',$reason)) wp_die(esc_html(self::t('A recorded review reason is required.','ثبت دلیل بررسی لازم است.')),'',array('response'=>422));
        self::reconcile($id,true); wp_safe_redirect(add_query_arg('page','atlas-zarinpal',admin_url('admin.php'))); exit;
    }
}

class Atlas_Gateway_ZarinPal extends WC_Payment_Gateway {
    public function __construct() {
        $this->id='atlas_zarinpal'; $this->method_title='ZarinPal V4';
        $this->title=Atlas_ZarinPal::t('ZarinPal secure payment','پرداخت امن زرین‌پال');
        $this->description=Atlas_ZarinPal::t('Pay the exact order amount in IRR through ZarinPal. Service confirmation is a separate agency step.','مبلغ دقیق سفارش را به ریال از طریق زرین‌پال پرداخت کنید. تأیید خدمت مرحله جداگانه آژانس است.');
        $this->method_description=Atlas_ZarinPal::t('Prepared V4 adapter. Sandbox/live requests require explicit server merchant configuration and approval.','درگاه آماده نسخه ۴. درخواست آزمایشی یا واقعی نیازمند تنظیم و تأیید صریح پذیرنده در سرور است.');
        $this->enabled=Atlas_ZarinPal::ready()?'yes':'no'; $this->supports=array('products'); $this->has_fields=false;
    }
    public function is_available() { $this->enabled=Atlas_ZarinPal::ready()?'yes':'no'; return $this->enabled==='yes' && get_woocommerce_currency()==='IRR' && parent::is_available(); }
    public function process_payment($order_id) {
        $result=Atlas_ZarinPal::initiate((int)$order_id); if (is_wp_error($result)) { wc_add_notice($result->get_error_message(),'error'); return array('result'=>'failure'); } return $result;
    }
}
