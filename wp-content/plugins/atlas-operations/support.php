<?php
if (!defined('ABSPATH')) { exit; }
use Atlas\Core\Auth;
use Atlas\Core\DB;
use Atlas\Core\Records;

/** Owner-scoped conversations; internal notes never use a customer-visible projection. */
final class Atlas_Support_Workflow {
    private static $payment_cases_ready=false;
    public static function table() { global $wpdb; return $wpdb->prefix.'atlas_support_messages'; }
    public static function install() {
        global $wpdb;
        if (get_option('atlas_support_schema')==='1') return;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $table=self::table(); $charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (id char(36) NOT NULL, case_id char(36) NOT NULL, author_id bigint unsigned NOT NULL, internal_note tinyint unsigned NOT NULL DEFAULT 0, body text NOT NULL, idem_key varchar(128) NOT NULL, request_hash char(64) NOT NULL, created_at datetime(6) NOT NULL, PRIMARY KEY  (id), UNIQUE KEY request_once (case_id,author_id,idem_key), KEY conversation (case_id,created_at)) ENGINE=InnoDB $charset;");
        $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
        if (strtoupper((string)$engine)==='INNODB') update_option('atlas_support_schema','1',false);
    }
    public static function register() {
        self::install();
        self::$payment_cases_ready=Atlas_Partner_Security::install('atlas_support_payment_cases','payment_id char(36) NOT NULL, case_id char(36) NOT NULL, created_at datetime(6) NOT NULL, PRIMARY KEY  (payment_id), UNIQUE KEY case_once (case_id)',array('payment_id'=>'char(36)','case_id'=>'char(36)','created_at'=>'datetime(6)'),array('PRIMARY'=>array('payment_id'),'case_once'=>array('case_id')));
        add_filter('atlas_core_deliver_outbox',array(__CLASS__,'exception_case'),5,2);
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_action('admin_post_atlas_support_reply',array(__CLASS__,'form_reply'));
        add_action('admin_post_atlas_support_triage',array(__CLASS__,'form_triage'));
        add_filter('wp_privacy_personal_data_exporters',array(__CLASS__,'exporter'));
    }
    /** Build an idempotent review case from verified, committed money facts only. */
    public static function exception_case($prior,$event) {
        global $wpdb;
        if (!is_array($event) || ($event['event_type']??'')!=='payment.exception' || !is_string($event['aggregate_id']??null)) return $prior;
        $id=$event['aggregate_id'];
        if (!self::$payment_cases_ready) return self::error('atlas_support_unavailable','Payment review storage is unavailable.',503);
        $table=$wpdb->prefix.'atlas_support_payment_cases';
        try {
            if ($wpdb->query('START TRANSACTION')===false) throw new RuntimeException('transaction');
            $payment=$wpdb->get_row($wpdb->prepare('SELECT p.id,p.booking_id,p.status,p.exception_code,b.user_id FROM '.DB::table('payments').' p INNER JOIN '.DB::table('bookings').' b ON b.id=p.booking_id WHERE p.id=%s FOR UPDATE',$id));
            if (!$payment || $payment->status!=='verified' || $payment->exception_code==='') { $wpdb->query('ROLLBACK'); return self::error('atlas_payment_review_invalid','Verified payment exception unavailable.',409); }
            if ($wpdb->get_var($wpdb->prepare("SELECT case_id FROM $table WHERE payment_id=%s",$id))) { $wpdb->query('ROLLBACK'); return $prior; }
            $user=get_user_by('id',(int)$payment->user_id); if (!$user) { $wpdb->query('ROLLBACK'); return self::error('atlas_payment_review_invalid','Payment case owner unavailable.',409); }
            $fa=str_starts_with(get_user_locale($user),'fa'); $case=wp_generate_uuid4(); $now=DB::now();
            $fields=array('state'=>'new','category'=>'payment_review','subject'=>$fa?'بررسی پرداخت دریافت‌شده':'Received payment review','message'=>$fa?'پرداخت شما دریافت شد و رزرو در حال بررسی است. این درخواست تأیید ورود یا تکمیل بازپرداخت نیست.':'We received your payment and are reviewing the reservation. This case does not confirm admission or a completed refund.','booking_id'=>$payment->booking_id,'priority'=>'urgent','next_action'=>$fa?'در انتظار بررسی عملیات و مالی.':'Awaiting operations and finance review.');
            if (!$wpdb->insert(DB::table('records'),array('id'=>$case,'type'=>'support_case','org_id'=>0,'owner_id'=>(int)$payment->user_id,'state'=>'new','payload'=>wp_json_encode($fields),'version'=>1,'created_at'=>$now,'updated_at'=>$now)) || !$wpdb->insert($table,array('payment_id'=>$id,'case_id'=>$case,'created_at'=>$now)) || !DB::audit(0,0,'support.payment_exception',$case,'success',$id)) throw new RuntimeException('case');
            if ($wpdb->query('COMMIT')===false) throw new RuntimeException('commit');
            return $prior;
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); return self::error('atlas_payment_review_failed','Payment review case could not be saved.',503); }
    }
    public static function routes() {
        register_rest_route('agency/v1','/support-cases',array('methods'=>'GET','permission_callback'=>array('Atlas_Partner_Security','permission'),'callback'=>array(__CLASS__,'listing')));
        register_rest_route('agency/v1','/support-cases/(?P<id>[a-f0-9-]{36})/messages',array(
            array('methods'=>'GET','permission_callback'=>static function(){return is_user_logged_in();},'callback'=>array(__CLASS__,'thread')),
            array('methods'=>'POST','permission_callback'=>static function(){return is_user_logged_in();},'callback'=>array(__CLASS__,'reply')),
        ));
        register_rest_route('agency/v1','/support-cases/(?P<id>[a-f0-9-]{36})/triage',array('methods'=>'POST','permission_callback'=>static function(){return Auth::agency();},'callback'=>array(__CLASS__,'triage')));
    }
    private static function error($code,$message,$status=422) { return new WP_Error($code,__($message,'atlas-operations'),array('status'=>$status)); }
    private static function case_record($id) {
        if (!is_user_logged_in() || !is_string($id) || !preg_match('/^[a-f0-9-]{36}$/',$id)) return self::error('atlas_not_found','Case unavailable.',404);
        return Records::get('support_case',$id,get_current_user_id());
    }
    public static function cases(int $page,bool $queue=false) {
        global $wpdb;
        if (!is_user_logged_in() || ($queue && !Auth::agency())) return self::error('atlas_forbidden','Sign in with case access.',403);
        if ($page<1 || $page>10000) return self::error('atlas_invalid_page','Invalid case page.');
        $limit=20; $offset=($page-1)*$limit;
        $table=DB::table('records');
        if ($queue) {
            $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE type=%s ORDER BY (state IN ('resolved','closed')), CASE JSON_UNQUOTE(JSON_EXTRACT(payload,'$.priority')) WHEN 'urgent' THEN 0 WHEN 'low' THEN 2 ELSE 1 END, created_at,id LIMIT %d OFFSET %d",'support_case',$limit+1,$offset));
        } else {
            $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE type=%s AND org_id=0 AND owner_id=%d ORDER BY created_at DESC,id DESC LIMIT %d OFFSET %d",'support_case',get_current_user_id(),$limit+1,$offset));
        }
        if ($wpdb->last_error!=='') return self::error('atlas_support_unavailable','Support storage is unavailable.',503);
        $cases=array();
        foreach (array_slice($ids,0,$limit) as $id) { $case=self::case_record($id); if (!is_wp_error($case)) $cases[]=$case; }
        return array('items'=>$cases,'page'=>$page,'has_more'=>count($ids)>$limit);
    }
    public static function listing($request) {
        $page=$request->get_param('page')??1; $scope=$request->get_param('scope')??'mine';
        if ((!is_int($page) && !(is_string($page) && ctype_digit($page))) || !in_array($scope,array('mine','queue'),true)) return self::error('atlas_invalid_page','Invalid case scope or page.');
        $result=self::cases((int)$page,$scope==='queue');
        return is_wp_error($result)?$result:Atlas_Partner_Security::response($result);
    }
    public static function case_links($data,$parameter) {
        $target=$parameter==='support_queue_page'?'atlas-support-queue':'atlas-task-support_case';
        $html='<nav aria-label="'.esc_attr(self::text('Support case pages','صفحه‌های درخواست پشتیبانی')).'">';
        if ($data['page']>1) $html.='<a href="'.esc_url(add_query_arg($parameter,$data['page']-1).'#'.$target).'">'.esc_html(self::text('Previous cases','درخواست‌های قبل')).'</a> ';
        if ($data['has_more']) $html.='<a href="'.esc_url(add_query_arg($parameter,$data['page']+1).'#'.$target).'">'.esc_html(self::text('More cases','درخواست‌های بیشتر')).'</a>';
        return $html.'</nav>';
    }
    public static function thread($request) {
        global $wpdb;
        $case=self::case_record((string)$request['id']); if (is_wp_error($case)) return $case;
        if (get_option('atlas_support_schema')!=='1') return self::error('atlas_support_unavailable','Support storage is unavailable.',503);
        $table=self::table(); $internal=Auth::agency() ? '' : ' AND internal_note=0';
        $page=$request->get_param('page') ?? 1; $limit=$request->get_param('limit') ?? 50;
        foreach (array($page,$limit) as $number) if ((!is_int($number) && !(is_string($number) && ctype_digit($number))) || (int)$number<1) return self::error('atlas_invalid_page','Invalid conversation page.');
        $page=(int)$page; $limit=(int)$limit;
        if ($page>10000 || $limit>100) return self::error('atlas_invalid_page','Invalid conversation page.');
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,author_id,internal_note,body,created_at FROM $table WHERE case_id=%s$internal ORDER BY created_at,id LIMIT %d OFFSET %d",$case['id'],$limit+1,($page-1)*$limit),ARRAY_A);
        if ($wpdb->last_error!=='') return self::error('atlas_support_unavailable','Support storage is unavailable.',503);
        $hasMore=count($rows)>$limit; $rows=array_slice($rows,0,$limit);
        $messages=array(); foreach ($rows as $row) $messages[]=array('id'=>$row['id'],'author'=>(int)$row['author_id']===(int)$case['owner_id']?'customer':'agency','internal_note'=>(bool)$row['internal_note'],'body'=>$row['body'],'created_at'=>$row['created_at']);
        $response=new WP_REST_Response(array('case_id'=>$case['id'],'state'=>$case['state'],'priority'=>$case['fields']['priority']??'normal','next_action'=>$case['fields']['next_action']??'','messages'=>$messages,'pagination'=>array('page'=>$page,'limit'=>$limit,'has_more'=>$hasMore)),200);
        $response->header('Cache-Control','private, no-store'); return $response;
    }
    public static function reply($request) {
        global $wpdb;
        $case=self::case_record((string)$request['id']); if (is_wp_error($case)) return $case;
        if (get_option('atlas_support_schema')!=='1') return self::error('atlas_support_unavailable','Support storage is unavailable.',503);
        $body=$request->get_json_params(); $key=(string)$request->get_header('Idempotency-Key');
        if (!is_array($body) || array_diff(array_keys($body),array('message','internal_note')) || !isset($body['message']) || !is_string($body['message']) || trim($body['message'])==='' || strlen($body['message'])>4000 || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$key) || (isset($body['internal_note']) && !is_bool($body['internal_note']))) return self::error('atlas_invalid_message','A bounded message and request key are required.');
        $internal=!empty($body['internal_note']); if ($internal && !Auth::agency()) return self::error('atlas_forbidden','Internal notes require agency access.',403);
        $text=sanitize_textarea_field($body['message']); $hash=hash('sha256',wp_json_encode(array($text,$internal))); $actor=get_current_user_id(); $table=self::table();
        try {
            if ($wpdb->query('START TRANSACTION')===false) throw new RuntimeException('transaction');
            $locked=$wpdb->get_row($wpdb->prepare('SELECT owner_id,org_id,state,payload,version FROM '.DB::table('records').' WHERE id=%s AND type=%s FOR UPDATE',$case['id'],'support_case'));
            if (!$locked || (!Auth::agency() && ((int)$locked->owner_id!==$actor || (int)$locked->org_id!==0))) { $wpdb->query('ROLLBACK'); return self::error('atlas_not_found','Case unavailable.',404); }
            $prior=$wpdb->get_row($wpdb->prepare("SELECT id,request_hash FROM $table WHERE case_id=%s AND author_id=%d AND idem_key=%s",$case['id'],$actor,$key));
            if ($prior) { $wpdb->query('ROLLBACK'); return hash_equals($prior->request_hash,$hash) ? new WP_REST_Response(array('id'=>$prior->id,'case_id'=>$case['id'],'status'=>'recorded'),200) : self::error('atlas_idempotency_conflict','The request key was reused.',409); }
            $id=wp_generate_uuid4();
            if (!$wpdb->insert($table,array('id'=>$id,'case_id'=>$case['id'],'author_id'=>$actor,'internal_note'=>$internal?1:0,'body'=>$text,'idem_key'=>$key,'request_hash'=>$hash,'created_at'=>DB::now()))) throw new RuntimeException('message write');
            if (!Auth::agency() && in_array($locked->state,array('resolved','closed'),true)) {
                $fields=json_decode($locked->payload,true)?:array(); $fields['state']='new'; unset($fields['resolution_code']);
                if ($wpdb->update(DB::table('records'),array('state'=>'new','payload'=>wp_json_encode($fields),'version'=>(int)$locked->version+1,'updated_at'=>DB::now()),array('id'=>$case['id'],'version'=>(int)$locked->version))!==1) throw new RuntimeException('case reopen');
            }
            if (!DB::audit($actor,(int)$locked->org_id,'support.reply',$case['id'],'success',$internal?'internal_note':'shared_reply') || !DB::emit($internal?'support.internal_note':'support.shared_reply',$case['id'])) throw new RuntimeException('audit or event');
            if ($wpdb->query('COMMIT')===false) throw new RuntimeException('commit');
            return new WP_REST_Response(array('id'=>$id,'case_id'=>$case['id'],'status'=>'recorded'),201);
        } catch (Throwable $error) { $wpdb->query('ROLLBACK'); return self::error('atlas_support_failed','Message could not be recorded.',503); }
    }
    public static function triage($request) {
        global $wpdb;
        if (!Auth::agency()) return self::error('atlas_forbidden','Agency access required.',403);
        $case=self::case_record((string)$request['id']); if (is_wp_error($case)) return $case;
        $body=$request->get_json_params(); $states=array('new','triaged','waiting_customer','waiting_supplier','in_progress','resolved','closed');
        if (!is_array($body) || array_diff(array_keys($body),array('version','state','priority','next_action','assigned_user','resolution_code')) || !isset($body['version'],$body['state']) || !is_int($body['version']) || !is_string($body['state']) || !in_array($body['state'],$states,true)) return self::error('atlas_invalid_triage','A current version and permitted case state are required.');
        if (isset($body['priority']) && !in_array($body['priority'],array('low','normal','urgent'),true)) return self::error('atlas_invalid_triage','Invalid priority.');
        if (isset($body['next_action']) && (!is_string($body['next_action']) || strlen($body['next_action'])>1000)) return self::error('atlas_invalid_triage','Invalid next action.');
        if (isset($body['resolution_code']) && (!is_string($body['resolution_code']) || ($body['resolution_code']!=='' && !in_array($body['resolution_code'],array('answered','duplicate','withdrawn','other'),true)))) return self::error('atlas_invalid_triage','Invalid resolution code.');
        if (isset($body['assigned_user']) && (!is_int($body['assigned_user']) || $body['assigned_user']<0 || ($body['assigned_user'] && !user_can($body['assigned_user'],'manage_options')))) return self::error('atlas_invalid_triage','Assign an authorized agency operator.');
        if (in_array($body['state'],array('resolved','closed'),true) && !in_array($body['resolution_code']??'',array('answered','duplicate','withdrawn','other'),true)) return self::error('atlas_invalid_triage','A resolution code is required.');
        try {
            if ($wpdb->query('START TRANSACTION')===false) throw new RuntimeException('transaction');
            $locked=$wpdb->get_row($wpdb->prepare('SELECT state,payload,version,org_id FROM '.DB::table('records').' WHERE id=%s AND type=%s FOR UPDATE',$case['id'],'support_case'));
            if (!$locked || (int)$locked->version!==$body['version']) { $wpdb->query('ROLLBACK'); return self::error('atlas_conflict','Case changed. Refresh before updating.',409); }
            $next=array('new'=>array('triaged','in_progress','waiting_customer','waiting_supplier','resolved','closed'),'triaged'=>array('in_progress','waiting_customer','waiting_supplier','resolved','closed'),'in_progress'=>array('waiting_customer','waiting_supplier','resolved','closed'),'waiting_customer'=>array('in_progress','resolved','closed'),'waiting_supplier'=>array('in_progress','resolved','closed'),'resolved'=>array('new','closed'),'closed'=>array('new'),'draft'=>array('new','triaged','in_progress'),'submitted'=>array('triaged','in_progress'),'approved'=>array('resolved','closed'),'rejected'=>array('new','closed'));
            if ($body['state']!==$locked->state && !in_array($body['state'],$next[$locked->state]??array(),true)) { $wpdb->query('ROLLBACK'); return self::error('atlas_invalid_transition','This case transition is not permitted.',409); }
            $fields=json_decode($locked->payload,true)?:array();
            foreach (array('state','priority','next_action','assigned_user','resolution_code') as $field) if (array_key_exists($field,$body)) $fields[$field]=is_string($body[$field])?sanitize_textarea_field($body[$field]):$body[$field];
            if ($body['state']==='new') unset($fields['resolution_code']);
            if ($wpdb->update(DB::table('records'),array('state'=>$body['state'],'payload'=>wp_json_encode($fields),'version'=>$body['version']+1,'updated_at'=>DB::now()),array('id'=>$case['id'],'version'=>$body['version']))!==1 || !DB::audit(get_current_user_id(),(int)$locked->org_id,'support.triage',$case['id'],'success',$body['state']) || !DB::emit('support.triaged',$case['id'])) throw new RuntimeException('triage write');
            if ($wpdb->query('COMMIT')===false) throw new RuntimeException('commit');
            return Records::get('support_case',$case['id'],get_current_user_id());
        } catch (Throwable $error) { $wpdb->query('ROLLBACK'); return self::error('atlas_support_failed','Case could not be updated.',503); }
    }
    private static function text($en,$fa) { return isset($_GET['lang']) && $_GET['lang']==='fa' ? $fa : $en; }
    public static function panel($case) {
        $request=new WP_REST_Request('GET','/'); $request['id']=$case['id'];
        $pageKey='support_page_'.$case['id']; $request->set_param('page',isset($_GET[$pageKey]) && is_scalar($_GET[$pageKey])?max(1,absint($_GET[$pageKey])):1);
        $thread=self::thread($request);
        if (is_wp_error($thread)) return '<p role="status">'.esc_html(self::text(
            'The conversation is temporarily unavailable.', 'گفتگو موقتاً در دسترس نیست.'
        )).'</p>';
        $data=$thread->get_data(); $html='<section class="atlas-support-thread"><p>'.esc_html($data['next_action']).'</p><ol>';
        foreach ($data['messages'] as $message) $html.='<li><strong>'.esc_html($message['internal_note']?self::text('Internal agency note','یادداشت داخلی آژانس'):($message['author']==='agency'?self::text('Agency','آژانس'):self::text('Customer','مشتری'))).'</strong><p>'.nl2br(esc_html($message['body'])).'</p></li>';
        $html.='</ol><nav aria-label="'.esc_attr(self::text('Conversation pages','صفحه‌های گفتگو')).'">';
        if ($data['pagination']['page']>1) $html.='<a href="'.esc_url(add_query_arg($pageKey,$data['pagination']['page']-1)).'">'.esc_html(self::text('Earlier page','صفحه قبل')).'</a> ';
        if ($data['pagination']['has_more']) $html.='<a href="'.esc_url(add_query_arg($pageKey,$data['pagination']['page']+1)).'">'.esc_html(self::text('Next page','صفحه بعد')).'</a>';
        $html.='</nav><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('atlas_support_reply','_wpnonce',true,false).'<input type="hidden" name="action" value="atlas_support_reply"><input type="hidden" name="case_id" value="'.esc_attr($case['id']).'"><input type="hidden" name="idempotency_key" value="'.esc_attr(wp_generate_uuid4()).'"><label>'.esc_html(self::text('Reply','پاسخ')).'<textarea name="message" required maxlength="4000"></textarea></label>';
        if (Auth::agency()) $html.='<label><input type="checkbox" name="internal_note" value="1">'.esc_html(self::text('Internal note — hidden from customer','یادداشت داخلی — پنهان از مشتری')).'</label>';
        $html.='<button>'.esc_html(self::text('Record reply','ثبت پاسخ')).'</button></form>';
        if (Auth::agency()) {
            $html.='<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('atlas_support_triage','_wpnonce',true,false).'<input type="hidden" name="action" value="atlas_support_triage"><input type="hidden" name="case_id" value="'.esc_attr($case['id']).'"><input type="hidden" name="version" value="'.esc_attr($case['version']).'"><label>'.esc_html(self::text('Case state','وضعیت درخواست')).'<select name="state">';
            foreach (array('new','triaged','waiting_customer','waiting_supplier','in_progress','resolved','closed') as $state) $html.='<option value="'.esc_attr($state).'"'.selected($case['state'],$state,false).'>'.esc_html($state).'</option>';
            $html.='</select></label><label>'.esc_html(self::text('Next action visible to customer','اقدام بعدی قابل نمایش به مشتری')).'<input name="next_action" maxlength="1000" value="'.esc_attr($data['next_action']).'"></label><label>'.esc_html(self::text('Resolution','نتیجه')).'<select name="resolution_code"><option value="">—</option><option value="answered">Answered / پاسخ داده شد</option><option value="duplicate">Duplicate / تکراری</option><option value="withdrawn">Withdrawn / انصراف</option><option value="other">Other / سایر</option></select></label><button>'.esc_html(self::text('Update case','به‌روزرسانی درخواست')).'</button></form>';
        }
        return $html.'</section>';
    }
    public static function queue() {
        if (!Auth::agency()) return '';
        $page=isset($_GET['support_queue_page']) && is_scalar($_GET['support_queue_page'])?max(1,absint($_GET['support_queue_page'])):1;
        $cases=self::cases($page,true); if (is_wp_error($cases)) return '';
        $html='<details id="atlas-support-queue"'.(isset($_GET['support_queue_page'])?' open':'').'><summary>'.esc_html(self::text('Customer support queue','صف پشتیبانی مشتریان')).'</summary>';
        foreach ($cases['items'] as $case) $html.='<details><summary>'.esc_html($case['fields']['subject'].' — '.$case['state']).'</summary><p>'.nl2br(esc_html($case['fields']['message'])).'</p>'.self::panel($case).'</details>';
        $html.=self::case_links($cases,'support_queue_page');
        return $html.'</details>';
    }
    public static function form_reply() {
        if (!is_user_logged_in()) wp_die(esc_html__('Sign in required.','atlas-operations'),'',array('response'=>403));
        check_admin_referer('atlas_support_reply');
        $r=new WP_REST_Request('POST','/'); $r->set_url_params(array('id'=>sanitize_text_field(wp_unslash($_POST['case_id']??'')))); $r->set_header('content-type','application/json'); $r->set_header('Idempotency-Key',sanitize_text_field(wp_unslash($_POST['idempotency_key']??''))); $r->set_body(wp_json_encode(array('message'=>wp_unslash($_POST['message']??''),'internal_note'=>isset($_POST['internal_note']))));
        $result=self::reply($r); if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()),'',array('response'=>422));
        wp_safe_redirect(wp_get_referer()?:home_url('/')); exit;
    }
    public static function form_triage() {
        if (!Auth::agency()) wp_die(esc_html__('Agency access required.','atlas-operations'),'',array('response'=>403));
        check_admin_referer('atlas_support_triage');
        $r=new WP_REST_Request('POST','/'); $r->set_url_params(array('id'=>sanitize_text_field(wp_unslash($_POST['case_id']??'')))); $r->set_header('content-type','application/json'); $r->set_body(wp_json_encode(array('version'=>absint($_POST['version']??0),'state'=>sanitize_key(wp_unslash($_POST['state']??'')),'next_action'=>wp_unslash($_POST['next_action']??''),'resolution_code'=>sanitize_key(wp_unslash($_POST['resolution_code']??'')))));
        $result=self::triage($r); if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()),'',array('response'=>422));
        wp_safe_redirect(wp_get_referer()?:home_url('/')); exit;
    }
    public static function exporter($exporters) { $exporters['atlas-support']=array('exporter_friendly_name'=>__('Atlas support conversations','atlas-operations'),'callback'=>array(__CLASS__,'export_data')); return $exporters; }
    public static function export_data($email,$page=1) {
        global $wpdb; $user=get_user_by('email',$email); if (!$user) return array('data'=>array(),'done'=>true);
        $offset=(max(1,(int)$page)-1)*100; $messages=self::table(); $records=DB::table('records');
        $rows=$wpdb->get_results($wpdb->prepare("SELECT m.id,m.case_id,m.body,m.created_at FROM $messages m INNER JOIN $records r ON r.id=m.case_id AND r.type='support_case' WHERE r.owner_id=%d AND r.org_id=0 AND m.internal_note=0 ORDER BY m.id LIMIT 100 OFFSET %d",$user->ID,$offset));
        $data=array(); foreach ($rows as $row) $data[]=array('group_id'=>'atlas-support','group_label'=>__('Support conversation','atlas-operations'),'item_id'=>'support-'.$row->id,'data'=>array(array('name'=>'Case reference','value'=>$row->case_id),array('name'=>'Shared message','value'=>$row->body),array('name'=>'Recorded at UTC','value'=>$row->created_at)));
        return array('data'=>$data,'done'=>count($rows)<100);
    }
}
