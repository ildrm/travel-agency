<?php
if (!defined('ABSPATH')) { exit; }
use Atlas\Core\Auth;
use Atlas\Core\DB;

/** Committed in-app notices. No email/SMS is sent or represented as delivered. */
final class Atlas_Notifications {
    private const EVENTS=array('booking.held','booking.paid_pending_confirmation','booking.confirmed','booking.exception','booking.review_required','booking.canceled','booking.refund_review_required','hold.released','payment.exception','refund.requested','refund.approved','refund.succeeded','support.shared_reply','support.triaged','support.internal_note','support.reply');
    private static function events() {
        $values=apply_filters('atlas_notification_events',self::EVENTS);
        return is_array($values)?array_values(array_unique(array_filter($values,static function($v){return is_string($v) && preg_match('/^[a-z][a-z0-9_.]{2,99}$/D',$v);}))) : self::EVENTS;
    }
    public static function table($kind='messages') { global $wpdb; return $wpdb->prefix.'atlas_notice_'.$kind; }
    private static function t($en,$fa) { return (($_GET['lang']??'')==='fa' || str_starts_with(determine_locale(),'fa'))?$fa:$en; }
    private static function error($message,$status=422) { return new WP_Error('atlas_notice_error',$message,array('status'=>$status)); }
    public static function register() {
        self::install();
        add_filter('atlas_core_deliver_outbox',array(__CLASS__,'deliver'),20,2);
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_action('admin_post_atlas_notice_action',array(__CLASS__,'form'));
        add_filter('atlas_operations_dashboard',static function($html,$customer){ return $html.self::panel(); },30,2);
    }
    public static function install() {
        global $wpdb;
        if (get_option('atlas_notices_schema')==='1') return;
        require_once ABSPATH.'wp-admin/includes/upgrade.php'; $charset=$wpdb->get_charset_collate();
        $messages=self::table(); $templates=self::table('templates');
        dbDelta("CREATE TABLE $messages (id char(36) NOT NULL, event_id char(36) NOT NULL, recipient_id bigint unsigned NOT NULL, event_type varchar(100) NOT NULL, template_id char(36) NOT NULL, locale varchar(5) NOT NULL, body text NOT NULL, reference_id varchar(100) NOT NULL, read_at datetime(6) NULL, created_at datetime(6) NOT NULL, PRIMARY KEY  (id), UNIQUE KEY event_recipient (event_id,recipient_id), KEY recipient_time (recipient_id,created_at)) ENGINE=InnoDB $charset;");
        dbDelta("CREATE TABLE $templates (id char(36) NOT NULL, event_type varchar(100) NOT NULL, locale varchar(5) NOT NULL, version int unsigned NOT NULL, state varchar(16) NOT NULL, body text NOT NULL, reviewed_by bigint unsigned NOT NULL, reason varchar(190) NOT NULL, created_at datetime(6) NOT NULL, PRIMARY KEY  (id), UNIQUE KEY template_version (event_type,locale,version)) ENGINE=InnoDB $charset;");
        foreach (array($messages,$templates) as $table) {
            if (strtoupper((string)$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table)))!=='INNODB') return;
            $fields=$wpdb->get_col('SHOW COLUMNS FROM '.$table);
            $required=$table===$messages?array('id','event_id','recipient_id','event_type','template_id','locale','body','reference_id','read_at','created_at'):array('id','event_type','locale','version','state','body','reviewed_by','reason','created_at');
            if (array_diff($required,$fields)) return;
            $expected=$table===$messages?array('event_id','recipient_id'):array('event_type','locale','version'); $index=$table===$messages?'event_recipient':'template_version';
            $indexes=$wpdb->get_results('SHOW INDEX FROM '.$table,ARRAY_A); $actual=array();
            foreach ($indexes as $row) if ($row['Key_name']===$index && (int)$row['Non_unique']===0) $actual[(int)$row['Seq_in_index']]=$row['Column_name'];
            ksort($actual); if (array_values($actual)!==$expected) return;
        }
        update_option('atlas_notices_schema','1',false);
    }
    public static function permission($r) { return is_user_logged_in() && wp_verify_nonce((string)$r->get_header('X-WP-Nonce'),'wp_rest'); }
    public static function routes() {
        register_rest_route('agency/v1','/notifications',array('methods'=>'GET','permission_callback'=>array(__CLASS__,'permission'),'callback'=>array(__CLASS__,'listing')));
        register_rest_route('agency/v1','/notifications/(?P<id>[a-f0-9-]{36})/read',array('methods'=>'POST','permission_callback'=>array(__CLASS__,'permission'),'callback'=>array(__CLASS__,'mark_read')));
        register_rest_route('agency/v1','/notification-operations',array('methods'=>'GET','permission_callback'=>static function($r){ return self::permission($r) && Auth::agency(); },'callback'=>array(__CLASS__,'operations')));
        register_rest_route('agency/v1','/notification-operations/templates',array('methods'=>'POST','permission_callback'=>static function($r){ return self::permission($r) && Auth::agency(); },'callback'=>array(__CLASS__,'template')));
        register_rest_route('agency/v1','/notification-operations/retry',array('methods'=>'POST','permission_callback'=>static function($r){ return self::permission($r) && Auth::agency(); },'callback'=>array(__CLASS__,'retry')));
    }
    private static function response($data) { return new WP_REST_Response($data,200,array('Cache-Control'=>'private, no-store')); }
    private static function page($r) {
        $page=$r->get_param('page')??1;
        if ((!is_int($page) && !(is_string($page) && ctype_digit($page))) || (int)$page<1 || (int)$page>10000) return self::error('Invalid page.');
        return (int)$page;
    }
    public static function listing($r) {
        global $wpdb; $page=self::page($r); if (is_wp_error($page)) return $page;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,event_type,locale,body,reference_id,read_at,created_at FROM '.self::table().' WHERE recipient_id=%d ORDER BY created_at DESC,id DESC LIMIT 26 OFFSET %d',get_current_user_id(),($page-1)*25),ARRAY_A);
        return self::response(array('items'=>array_slice($rows,0,25),'page'=>$page,'has_more'=>count($rows)>25,'channel'=>'in_app'));
    }
    public static function mark_read($r) {
        global $wpdb;
        $table=self::table(); $id=(string)$r['id'];
        $wpdb->query('START TRANSACTION');
        try {
            $row=$wpdb->get_row($wpdb->prepare("SELECT id,read_at FROM $table WHERE id=%s AND recipient_id=%d FOR UPDATE",$id,get_current_user_id()));
            if (!$row) { $wpdb->query('ROLLBACK'); return self::error('Notification unavailable.',404); }
            if (!$row->read_at && ($wpdb->update($table,array('read_at'=>DB::now()),array('id'=>$id))===false || !DB::audit(get_current_user_id(),0,'notice.read',$id,'success'))) throw new RuntimeException('read');
            $wpdb->query('COMMIT'); return self::response(array('id'=>$id,'state'=>'read'));
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); return self::error('Could not record notification state.',503); }
    }
    /** Resolve only trusted persisted associations; never render raw event payload. */
    private static function recipient($event) {
        global $wpdb; $type=$event['event_type']; $id=$event['aggregate_id'];
        if (str_starts_with($type,'booking.')) return $wpdb->get_row($wpdb->prepare('SELECT user_id AS user, id AS reference FROM '.DB::table('bookings').' WHERE id=%s',$id),ARRAY_A);
        if ($type==='hold.released') return $wpdb->get_row($wpdb->prepare('SELECT user_id AS user,booking_id AS reference FROM '.DB::table('holds').' WHERE id=%s',$id),ARRAY_A);
        if ($type==='payment.exception') return $wpdb->get_row($wpdb->prepare('SELECT b.user_id AS user,b.id AS reference FROM '.DB::table('payments').' p INNER JOIN '.DB::table('bookings').' b ON b.id=p.booking_id WHERE p.id=%s',$id),ARRAY_A);
        if (str_starts_with($type,'refund.')) return $wpdb->get_row($wpdb->prepare('SELECT b.user_id AS user,b.id AS reference FROM '.DB::table('refunds').' r INNER JOIN '.DB::table('bookings').' b ON b.id=r.booking_id WHERE r.id=%s',$id),ARRAY_A);
        if (str_starts_with($type,'support.')) {
            $row=$wpdb->get_row($wpdb->prepare('SELECT id,owner_id,payload FROM '.DB::table('records').' WHERE id=%s AND type=%s',$id,'support_case'));
            if (!$row) return null;
            $fields=json_decode($row->payload,true)?:array();
            // Ambiguous historical reply events and internal notes never notify a customer.
            $user=in_array($type,array('support.internal_note','support.reply'),true)?(int)($fields['assigned_user']??0):(int)$row->owner_id;
            return array('user'=>$user,'reference'=>$row->id);
        }
        return null;
    }
    public static function deliver($previous,$event) {
        global $wpdb;
        if (is_wp_error($previous)) return $previous;
        if ($previous===true) return true;
        if (!is_array($event) || !in_array($event['event_type']??'',self::events(),true)) return $previous;
        if (!is_string($event['id']??null) || !preg_match('/^[a-f0-9-]{36}$/D',$event['id']) || !is_string($event['aggregate_id']??null)) return false;
        if (get_option('atlas_notices_schema')!=='1') return false;
        $recipient=self::recipient($event);
        $recipients=apply_filters('atlas_notification_recipients',$recipient?array($recipient):array(),$event);
        if (!is_array($recipients) || count($recipients)>1000 || (!$recipients && !$recipient)) return false;
        foreach ($recipients as $recipient) {
            if (!is_array($recipient) || !isset($recipient['user'],$recipient['reference']) || !is_scalar($recipient['user']) || !ctype_digit((string)$recipient['user']) || !is_string($recipient['reference']) || strlen($recipient['reference'])>100) return false;
            if (!(int)$recipient['user']) continue; // Unassigned internal event: no customer disclosure.
            if (!self::record_recipient($event,$recipient)) return false;
        }
        return true;
    }
    private static function record_recipient($event,$recipient) {
        global $wpdb; $user=get_user_by('id',(int)$recipient['user']); if (!$user) return false;
        $locale=str_starts_with(get_user_locale($user),'fa')?'fa':'en'; $table=self::table();
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE event_id=%s AND recipient_id=%d",$event['id'],$user->ID))) return true;
        $template=$wpdb->get_row($wpdb->prepare('SELECT id,body FROM '.self::table('templates').' WHERE event_type=%s AND locale=%s AND state=%s ORDER BY version DESC LIMIT 1',$event['event_type'],$locale,'approved'));
        if (!$template) return false; // Missing review is an observable queue failure, never a delivery claim.
        $body=strtr($template->body,array('{reference}'=>(string)$recipient['reference']));
        $wpdb->query('START TRANSACTION');
        try {
            $id=wp_generate_uuid4();
            $sql=$wpdb->prepare("INSERT IGNORE INTO $table (id,event_id,recipient_id,event_type,template_id,locale,body,reference_id,created_at) VALUES (%s,%s,%d,%s,%s,%s,%s,%s,%s)",$id,$event['id'],$user->ID,$event['event_type'],$template->id,$locale,$body,$recipient['reference'],DB::now());
            $inserted=$wpdb->query($sql); if ($inserted===false || ($inserted===1 && !DB::audit(0,0,'notice.record',$id,'success',$event['id']))) throw new RuntimeException('notice');
            $wpdb->query('COMMIT'); return true;
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); return false; }
    }
    public static function template($r) {
        global $wpdb;
        if (!Auth::agency()) return self::error('Agency authority required.',403);
        $b=$r->get_json_params();
        if (!is_array($b) || array_diff(array_keys($b),array('event_type','locale','body','reason','approve')) || !in_array($b['event_type']??'',self::events(),true) || !in_array($b['locale']??'',array('en','fa'),true)
            || !is_string($b['body']??null) || trim($b['body'])==='' || strlen($b['body'])>2000 || !is_bool($b['approve']??null) || !is_string($b['reason']??null) || trim($b['reason'])==='' || strlen($b['reason'])>190
            || preg_match('/\{(?!reference\})[^}]*\}/',$b['body'])) return self::error('A supported event, language, bounded text and review reason are required.');
        $table=self::table('templates'); $lock='atlas_notice_template_'.md5($wpdb->prefix.$b['event_type'].$b['locale']);
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1') return self::error('Template is being reviewed.',409);
        $wpdb->query('START TRANSACTION');
        try {
            $version=1+(int)$wpdb->get_var($wpdb->prepare("SELECT MAX(version) FROM $table WHERE event_type=%s AND locale=%s",$b['event_type'],$b['locale'])); $id=wp_generate_uuid4();
            if (!$wpdb->insert($table,array('id'=>$id,'event_type'=>$b['event_type'],'locale'=>$b['locale'],'version'=>$version,'state'=>$b['approve']?'approved':'draft','body'=>sanitize_textarea_field($b['body']),'reviewed_by'=>get_current_user_id(),'reason'=>sanitize_text_field($b['reason']),'created_at'=>DB::now())) || !DB::audit(get_current_user_id(),0,'notice.template',$id,'success',$b['reason'])) throw new RuntimeException('template');
            $wpdb->query('COMMIT'); return self::response(array('id'=>$id,'version'=>$version,'state'=>$b['approve']?'approved':'draft'));
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); return self::error('Template could not be recorded.',503); }
        finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    public static function operations($r) {
        global $wpdb; if (!Auth::agency()) return self::error('Agency authority required.',403); $page=self::page($r); if (is_wp_error($page)) return $page;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,event_type,status,attempts,available_at,created_at FROM '.DB::table('outbox').' WHERE status IN (%s,%s,%s) ORDER BY created_at,id LIMIT 26 OFFSET %d','pending','processing','failed',($page-1)*25),ARRAY_A);
        return self::response(array('items'=>array_slice($rows,0,25),'page'=>$page,'has_more'=>count($rows)>25));
    }
    public static function retry($r) {
        global $wpdb; if (!Auth::agency()) return self::error('Agency authority required.',403); $b=$r->get_json_params();
        if (!is_array($b) || array_diff(array_keys($b),array('id','reason')) || !is_string($b['id']??null) || !preg_match('/^[a-f0-9-]{36}$/D',$b['id']) || !is_string($b['reason']??null) || trim($b['reason'])==='' || strlen($b['reason'])>190) return self::error('Event reference and retry reason required.');
        $wpdb->query('START TRANSACTION');
        try {
            $ok=$wpdb->update(DB::table('outbox'),array('status'=>'pending','attempts'=>0,'claimed_at'=>null,'available_at'=>DB::now()),array('id'=>$b['id'],'status'=>'failed'));
            if ($ok!==1) { $wpdb->query('ROLLBACK'); return self::error('Failed event unavailable.',409); }
            if (!DB::audit(get_current_user_id(),0,'notice.retry',$b['id'],'success',$b['reason'])) throw new RuntimeException('audit');
            $wpdb->query('COMMIT'); return self::response(array('id'=>$b['id'],'state'=>'pending'));
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); return self::error('Retry could not be recorded.',503); }
    }
    public static function panel() {
        if (!is_user_logged_in()) return ''; $r=new WP_REST_Request('GET','/');
        $r->set_param('page',isset($_GET['notice_page']) && is_scalar($_GET['notice_page'])?max(1,absint($_GET['notice_page'])):1);
        $result=self::listing($r); if (is_wp_error($result)) return ''; $data=$result->get_data();
        $html='<details><summary>'.esc_html(self::t('Account notifications','اعلان‌های حساب')).'</summary><p>'.esc_html(self::t('These notices are recorded in your account. Email or SMS delivery is separate.','این اعلان‌ها در حساب ثبت می‌شوند. تحویل ایمیل یا پیامک جداست.')).'</p><ul>';
        foreach ($data['items'] as $row) {
            $html.='<li><p>'.nl2br(esc_html($row['body'])).'</p><bdi dir="ltr">'.esc_html($row['created_at']).'</bdi>';
            if (!$row['read_at']) $html.=self::form_start('read').'<input type="hidden" name="id" value="'.esc_attr($row['id']).'"><button>'.esc_html(self::t('Mark read','خوانده شد')).'</button></form>';
            $html.='</li>';
        }
        $html.='</ul>'; if ($data['page']>1) $html.='<a href="'.esc_url(add_query_arg('notice_page',$data['page']-1)).'">'.esc_html(self::t('Previous','قبل')).'</a> '; if ($data['has_more']) $html.='<a href="'.esc_url(add_query_arg('notice_page',$data['page']+1)).'">'.esc_html(self::t('Next','بعد')).'</a>';
        $html.='</details>'; if (!Auth::agency()) return $html;
        $html.='<details><summary>'.esc_html(self::t('Notification operations and template review','عملیات اعلان و بازبینی قالب')).'</summary>'.self::form_start('template').'<label>'.esc_html(self::t('Event','رویداد')).'<select name="event_type">';
        foreach (self::events() as $event) $html.='<option>'.esc_html($event).'</option>';
        $html.='</select></label><label>'.esc_html(self::t('Language','زبان')).'<select name="locale"><option value="en">English</option><option value="fa">فارسی</option></select></label><label>'.esc_html(self::t('Notice text; {reference} inserts the private account reference','متن اعلان؛ {reference} شناسه خصوصی حساب را درج می‌کند')).'<textarea name="body" required maxlength="2000"></textarea></label><label>'.esc_html(self::t('Review reason','دلیل بازبینی')).'<input name="reason" required maxlength="190"></label><label><input type="checkbox" name="approve" value="1">'.esc_html(self::t('Approve this version for account notices','تأیید این نسخه برای اعلان‌های حساب')).'</label><button>'.esc_html(self::t('Save new version','ذخیره نسخه جدید')).'</button></form>';
        $r->set_param('page',isset($_GET['notice_queue_page']) && is_scalar($_GET['notice_queue_page'])?max(1,absint($_GET['notice_queue_page'])):1); $queue=self::operations($r);
        if (!is_wp_error($queue)) { $q=$queue->get_data(); $html.='<ul>'; foreach ($q['items'] as $row) { $html.='<li><bdi dir="ltr">'.esc_html($row['id'].' '.$row['event_type'].' '.$row['status'].' '.$row['attempts']).'</bdi>'; if ($row['status']==='failed') $html.=self::form_start('retry').'<input type="hidden" name="id" value="'.esc_attr($row['id']).'"><label>'.esc_html(self::t('Retry reason','دلیل تلاش مجدد')).'<input name="reason" required maxlength="190"></label><button>'.esc_html(self::t('Retry','تلاش مجدد')).'</button></form>'; $html.='</li>'; } $html.='</ul>'; if ($q['page']>1) $html.='<a href="'.esc_url(add_query_arg('notice_queue_page',$q['page']-1)).'">'.esc_html(self::t('Previous','قبل')).'</a> '; if ($q['has_more']) $html.='<a href="'.esc_url(add_query_arg('notice_queue_page',$q['page']+1)).'">'.esc_html(self::t('Next','بعد')).'</a>'; }
        return $html.'</details>';
    }
    private static function form_start($task) { return '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('atlas_notice_action','_wpnonce',true,false).'<input type="hidden" name="action" value="atlas_notice_action"><input type="hidden" name="task" value="'.esc_attr($task).'">'; }
    public static function form() {
        if (!is_user_logged_in()) wp_die('Forbidden','',array('response'=>403)); check_admin_referer('atlas_notice_action');
        $task=isset($_POST['task']) && is_string($_POST['task'])?sanitize_key($_POST['task']):''; $r=new WP_REST_Request('POST','/'); $b=array();
        if ($task==='read') { $r['id']=isset($_POST['id']) && is_string($_POST['id'])?wp_unslash($_POST['id']):''; $result=self::mark_read($r); }
        else {
            foreach ($task==='template'?array('event_type','locale','body','reason'):array('id','reason') as $field) $b[$field]=isset($_POST[$field]) && is_string($_POST[$field])?wp_unslash($_POST[$field]):'';
            if ($task==='template') $b['approve']=isset($_POST['approve']);
            $r->set_header('content-type','application/json'); $r->set_body(wp_json_encode($b)); $result=$task==='template'?self::template($r):($task==='retry'?self::retry($r):self::error('Unknown operation.'));
        }
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()),'',array('response'=>422)); wp_safe_redirect(wp_get_referer()?:home_url('/')); exit;
    }
}
add_action('atlas_operations_ready',array('Atlas_Notifications','register'));
