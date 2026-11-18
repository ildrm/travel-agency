<?php
if (!defined('ABSPATH')) { exit; }
use Atlas\Core\Auth;
use Atlas\Core\DB;

/** Shared protected partner storage and browser policy; no email or network delivery. */
final class Atlas_Partner_Security {
    public static function error($code,$message,$status=422) { return new WP_Error($code,__($message,'atlas-operations'),array('status'=>$status)); }
    public static function response($data,$status=200) { $r=new WP_REST_Response($data,$status); $r->header('Cache-Control','private, no-store'); return $r; }
    public static function permission($r) { return is_user_logged_in() && wp_verify_nonce((string)$r->get_header('X-WP-Nonce'),'wp_rest') ? true : self::error('atlas_forbidden','Sign in with a valid security token.',403); }
    public static function t($en,$fa) { return isset($_GET['lang']) && $_GET['lang']==='fa' ? $fa : $en; }
    public static function post($name) { return isset($_POST[$name]) && is_string($_POST[$name]) ? wp_unslash($_POST[$name]) : ''; }
    public static function manager($org,$user=0) {
        $user=$user?:get_current_user_id();
        return $user>0 && user_can($user,'atlas_manage_records') && (user_can($user,'manage_options') || in_array(Auth::member_role((int)$org,$user),array('owner','partner_admin','manager'),true));
    }
    public static function roles($org,$user=0) {
        $user=$user?:get_current_user_id();
        if (!self::manager($org,$user)) return array();
        if (user_can($user,'manage_options')) return Auth::organization_roles();
        $roles=array('viewer','contributor','editor','leader'); $role=Auth::member_role((int)$org,$user);
        if (in_array($role,array('partner_admin','owner'),true)) $roles[]='manager';
        if ($role==='owner') $roles[]='partner_admin';
        return $roles; // Partner owners cannot delegate ownership or agency authority.
    }
    public static function active_org($org,$lock=false) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT id,status FROM '.DB::table('organizations').' WHERE id=%d'.($lock?' FOR UPDATE':''),(int)$org));
    }
    public static function active($row) { return $row && in_array($row->status,array('active','approved'),true); }
    public static function install($name,$ddl,$columns,$unique) {
        global $wpdb;
        $table=$wpdb->prefix.$name;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        if (get_option($name.'_schema')!=='1') dbDelta("CREATE TABLE $table (\n".str_replace(', ',",\n",$ddl)."\n) ENGINE=InnoDB ".$wpdb->get_charset_collate().';');
        $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
        $actual=$wpdb->get_results("SHOW COLUMNS FROM `$table`",ARRAY_A); $found=array();
        foreach ((array)$actual as $c) $found[$c['Field']]=strtolower(preg_replace('/\b(bigint|int|tinyint)\([0-9]+\)/','$1',$c['Type']));
        $ok=strtoupper((string)$engine)==='INNODB';
        foreach ($columns as $col=>$type) if (($found[$col]??null)!==$type) $ok=false;
        $indexes=array(); foreach ((array)$wpdb->get_results("SHOW INDEX FROM `$table`",ARRAY_A) as $i) { if ((int)$i['Non_unique']===0) $indexes[$i['Key_name']][(int)$i['Seq_in_index']]=$i['Column_name']; }
        foreach ($unique as $index=>$shape) { $parts=$indexes[$index]??array(); ksort($parts); if (array_values($parts)!==$shape) $ok=false; }
        if ($ok) update_option($name.'_schema','1',false); else delete_option($name.'_schema');
        return $ok;
    }
    public static function form_start($action) { return '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field($action,'_wpnonce',true,false).'<input type="hidden" name="action" value="'.esc_attr($action).'"><input type="hidden" name="request_key" value="'.esc_attr(wp_generate_uuid4()).'">'; }
    public static function input($name,$label,$value='',$type='text') { return '<label>'.esc_html($label).'<input type="'.esc_attr($type).'" name="'.esc_attr($name).'" value="'.esc_attr((string)$value).'" required></label>'; }
    public static function org_ids() {
        global $wpdb;
        if (Auth::agency()) return $wpdb->get_col('SELECT id FROM '.DB::table('organizations').' ORDER BY id LIMIT 100');
        return $wpdb->get_col($wpdb->prepare('SELECT m.org_id FROM '.DB::table('memberships').' m INNER JOIN '.DB::table('organizations')." o ON o.id=m.org_id WHERE m.user_id=%d AND m.status='active' AND m.role IN ('owner','partner_admin','manager') AND o.status IN ('approved','active') ORDER BY m.org_id LIMIT 100",get_current_user_id()));
    }
    public static function selected_orgs() {
        $ids=self::org_ids(); if (!$ids) return array();
        $selected=isset($_GET['atlas_partner_org']) && is_scalar($_GET['atlas_partner_org']) ? (int)$_GET['atlas_partner_org'] : (int)$ids[0];
        return in_array((string)$selected,array_map('strval',$ids),true)?array($selected):array();
    }
    public static function selector() {
        $ids=self::org_ids(); $selected=self::selected_orgs();
        $html='<form method="get" action="'.esc_url(home_url('/')).'"><input type="hidden" name="atlas_area" value="portal"><input type="hidden" name="lang" value="'.(isset($_GET['lang'])&&$_GET['lang']==='fa'?'fa':'en').'"><label>'.esc_html(self::t('Organization','سازمان')).'<select name="atlas_partner_org">';
        foreach ($ids as $id) $html.='<option value="'.esc_attr($id).'"'.selected(in_array((int)$id,$selected,true),true,false).'>'.esc_html((string)$id).'</option>';
        return $html.'</select></label><button>'.esc_html(self::t('Show organization','نمایش سازمان')).'</button></form>';
    }
    public static function pagination($parameter,$page,$count) {
        $args=array('atlas_area'=>'portal','lang'=>isset($_GET['lang'])&&$_GET['lang']==='fa'?'fa':'en','atlas_partner_org'=>self::selected_orgs()[0]??0);
        $html='<nav aria-label="'.esc_attr(self::t('Record pages','صفحات سوابق')).'">';
        if ($page>1) $html.='<a href="'.esc_url(add_query_arg(array_merge($args,array($parameter=>$page-1)),home_url('/'))).'">'.esc_html(self::t('Previous','قبل')).'</a> ';
        if ($count===25) $html.='<a href="'.esc_url(add_query_arg(array_merge($args,array($parameter=>$page+1)),home_url('/'))).'">'.esc_html(self::t('Next','بعد')).'</a>';
        return $html.'</nav>';
    }
}

final class Atlas_Partner_Invitations {
    private static $ready=false;
    public static function table() { global $wpdb; return $wpdb->prefix.'atlas_partner_invitations'; }
    public static function install() {
        self::$ready=Atlas_Partner_Security::install('atlas_partner_invitations',
            "id char(36) NOT NULL, org_id bigint unsigned NOT NULL, email varchar(254) NOT NULL, role varchar(32) NOT NULL, capability_ceiling varchar(120) NOT NULL, token_hash char(64) NOT NULL, state varchar(16) NOT NULL, created_by bigint unsigned NOT NULL, accepted_by bigint unsigned NOT NULL DEFAULT 0, request_key varchar(128) NOT NULL, request_hash char(64) NOT NULL, created_at datetime(6) NOT NULL, expires_at datetime(6) NOT NULL, accepted_at datetime(6) NULL, revoke_reason varchar(190) NOT NULL DEFAULT '', PRIMARY KEY  (id), UNIQUE KEY token_once (token_hash), UNIQUE KEY create_once (created_by,request_key), KEY org_state (org_id,state)",
            array('id'=>'char(36)','org_id'=>'bigint unsigned','email'=>'varchar(254)','role'=>'varchar(32)','capability_ceiling'=>'varchar(120)','token_hash'=>'char(64)','state'=>'varchar(16)','created_by'=>'bigint unsigned','accepted_by'=>'bigint unsigned','request_key'=>'varchar(128)','request_hash'=>'char(64)','created_at'=>'datetime(6)','expires_at'=>'datetime(6)','accepted_at'=>'datetime(6)','revoke_reason'=>'varchar(190)'),
            array('PRIMARY'=>array('id'),'token_once'=>array('token_hash'),'create_once'=>array('created_by','request_key')));
        return self::$ready;
    }
    public static function register() {
        self::install(); add_action('rest_api_init',array(__CLASS__,'routes')); add_action('admin_post_atlas_partner_invitation',array(__CLASS__,'form'));
        add_filter('atlas_operations_dashboard',static function($html,$customer){return $html.self::panel($customer);},10,2);
    }
    public static function routes() {
        $p=array('permission_callback'=>array('Atlas_Partner_Security','permission'));
        register_rest_route('agency/v1','/organizations/(?P<org_id>\d+)/invitations',array(array_merge($p,array('methods'=>'GET','callback'=>array(__CLASS__,'listing'))),array_merge($p,array('methods'=>'POST','callback'=>array(__CLASS__,'create')))));
        register_rest_route('agency/v1','/organizations/(?P<org_id>\d+)/invitations/(?P<id>[a-f0-9-]{36})/revoke',array_merge($p,array('methods'=>'POST','callback'=>array(__CLASS__,'revoke'))));
        register_rest_route('agency/v1','/invitations/accept',array_merge($p,array('methods'=>'POST','callback'=>array(__CLASS__,'accept'))));
    }
    private static function guard($r,$org=null) {
        $p=Atlas_Partner_Security::permission($r); if (is_wp_error($p)) return $p;
        if (!self::$ready) return Atlas_Partner_Security::error('atlas_storage_unavailable','Partner storage is unavailable.',503);
        if ($org!==null && !Atlas_Partner_Security::manager($org)) return Atlas_Partner_Security::error('atlas_not_found','Partner record unavailable.',404);
        return true;
    }
    private static function project($row) {
        return array('id'=>$row->id,'org_id'=>(int)$row->org_id,'email'=>$row->email,'role'=>$row->role,'state'=>$row->state==='pending' && DB::now()>=$row->expires_at?'expired':$row->state,'expires_at'=>$row->expires_at,'created_at'=>$row->created_at,'accepted_by'=>(int)$row->accepted_by);
    }
    public static function listing($r) {
        global $wpdb; $org=(int)$r['org_id']; $ok=self::guard($r,$org); if (is_wp_error($ok)) return $ok;
        $page=(int)($r->get_param('page')?:1); if ($page<1 || $page>10000) return Atlas_Partner_Security::error('atlas_invalid_page','Invalid page.');
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table().' WHERE org_id=%d ORDER BY created_at DESC,id LIMIT 25 OFFSET %d',$org,($page-1)*25));
        if (!DB::audit(get_current_user_id(),$org,'partner.invitation_list','invitations','read')) return Atlas_Partner_Security::error('atlas_audit_failed','Read could not be recorded.',503);
        return Atlas_Partner_Security::response(array('items'=>array_map(array(__CLASS__,'project'),$rows),'page'=>$page,'limit'=>25));
    }
    public static function create($r) {
        global $wpdb; $org=(int)$r['org_id']; $ok=self::guard($r,$org); if (is_wp_error($ok)) return $ok;
        $b=$r->get_json_params(); $key=(string)$r->get_header('Idempotency-Key');
        if (!is_array($b) || array_diff(array_keys($b),array('email','role','expires_in_hours')) || !isset($b['email'],$b['role'],$b['expires_in_hours']) || !is_string($b['email']) || strlen($b['email'])>254 || !is_email($b['email']) || !is_string($b['role']) || !in_array($b['role'],Atlas_Partner_Security::roles($org),true) || !is_int($b['expires_in_hours']) || $b['expires_in_hours']<1 || $b['expires_in_hours']>168 || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$key)) return Atlas_Partner_Security::error('atlas_invalid_invitation','A valid email, permitted role, expiry of 1–168 hours and request key are required.');
        $email=strtolower($b['email']); $actor=get_current_user_id(); $hash=hash('sha256',wp_json_encode(array($org,$email,$b['role'],$b['expires_in_hours'])));
        $wpdb->query('START TRANSACTION');
        try {
            if (!Atlas_Partner_Security::active(Atlas_Partner_Security::active_org($org,true)) || !Atlas_Partner_Security::manager($org) || !in_array($b['role'],Atlas_Partner_Security::roles($org),true)) throw new DomainException('scope');
            $prior=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE created_by=%d AND request_key=%s',$actor,$key));
            if ($prior) { $wpdb->query('ROLLBACK'); return hash_equals($prior->request_hash,$hash)?Atlas_Partner_Security::response(self::project($prior)):Atlas_Partner_Security::error('atlas_idempotency_conflict','The request key was reused.',409); }
            $existing=get_user_by('email',$email);
            if ($existing && $wpdb->get_var($wpdb->prepare('SELECT user_id FROM '.DB::table('memberships').' WHERE org_id=%d AND user_id=%d',$org,$existing->ID))) throw new DomainException('membership');
            if ($wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table()." WHERE org_id=%d AND email=%s AND state='pending' AND expires_at>%s",$org,$email,DB::now()))) throw new DomainException('pending');
            $id=wp_generate_uuid4(); $token=bin2hex(random_bytes(32)); $now=DB::now(); $expires=(new DateTimeImmutable($now,new DateTimeZone('UTC')))->modify('+'.$b['expires_in_hours'].' hours')->format('Y-m-d H:i:s.u');
            $capabilities=array(); foreach (array('atlas_manage_records','atlas_manage_offers') as $capability) if (current_user_can($capability)) $capabilities[]=$capability;
            if (!$wpdb->insert(self::table(),array('id'=>$id,'org_id'=>$org,'email'=>$email,'role'=>$b['role'],'capability_ceiling'=>wp_json_encode($capabilities),'token_hash'=>hash('sha256',$token),'state'=>'pending','created_by'=>$actor,'request_key'=>$key,'request_hash'=>$hash,'created_at'=>$now,'expires_at'=>$expires)) || !DB::audit($actor,$org,'partner.invitation_create',$id,'success') || !DB::emit('partner.invitation_created',$id)) throw new RuntimeException('write');
            $wpdb->query('COMMIT'); return Atlas_Partner_Security::response(array('id'=>$id,'org_id'=>$org,'email'=>$email,'role'=>$b['role'],'state'=>'pending','expires_at'=>$expires,'token'=>$token,'delivery'=>'manual_once'),201);
        } catch (DomainException $e) { $wpdb->query('ROLLBACK'); return Atlas_Partner_Security::error('atlas_invitation_conflict','Organization, existing membership or pending invitation requires review.',409); }
        catch (Throwable $e) {
            $wpdb->query('ROLLBACK'); $prior=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE created_by=%d AND request_key=%s',$actor,$key));
            if ($prior) return hash_equals($prior->request_hash,$hash)?Atlas_Partner_Security::response(self::project($prior)):Atlas_Partner_Security::error('atlas_idempotency_conflict','The request key was reused.',409);
            return Atlas_Partner_Security::error('atlas_invitation_failed','Invitation could not be recorded.',503);
        }
    }
    public static function revoke($r) {
        global $wpdb; $org=(int)$r['org_id']; $ok=self::guard($r,$org); if (is_wp_error($ok)) return $ok;
        $b=$r->get_json_params(); if (!is_array($b) || array_keys($b)!==array('reason') || !is_string($b['reason']) || trim($b['reason'])==='' || strlen($b['reason'])>190) return Atlas_Partner_Security::error('atlas_invalid_reason','A bounded reason is required.');
        $wpdb->query('START TRANSACTION');
        try {
            Atlas_Partner_Security::active_org($org,true); if (!Atlas_Partner_Security::manager($org)) throw new DomainException('scope');
            $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%s AND org_id=%d FOR UPDATE',(string)$r['id'],$org));
            if (!$row || !in_array($row->state,array('pending','revoked'),true)) throw new DomainException('state');
            if ($row->state==='pending' && ($wpdb->update(self::table(),array('state'=>'revoked','revoke_reason'=>sanitize_text_field($b['reason'])),array('id'=>$row->id))!==1 || !DB::audit(get_current_user_id(),$org,'partner.invitation_revoke',$row->id,'success',$b['reason']))) throw new RuntimeException('write');
            $wpdb->query('COMMIT'); return Atlas_Partner_Security::response(array('id'=>$row->id,'state'=>'revoked'));
        } catch (DomainException $e) { $wpdb->query('ROLLBACK'); return Atlas_Partner_Security::error('atlas_not_found','Partner record unavailable.',404); }
        catch (Throwable $e) { $wpdb->query('ROLLBACK'); return Atlas_Partner_Security::error('atlas_invitation_failed','Invitation could not be revoked.',503); }
    }
    public static function accept($r) {
        global $wpdb; $ok=self::guard($r); if (is_wp_error($ok)) return $ok;
        $b=$r->get_json_params(); if (!is_array($b) || array_keys($b)!==array('token') || !is_string($b['token']) || !preg_match('/^[a-f0-9]{64}$/D',$b['token'])) return Atlas_Partner_Security::error('atlas_invitation_unavailable','Invitation unavailable.',404);
        $hash=hash('sha256',$b['token']); $row=$wpdb->get_row($wpdb->prepare('SELECT org_id FROM '.self::table().' WHERE token_hash=%s',$hash));
        if (!$row) return Atlas_Partner_Security::error('atlas_invitation_unavailable','Invitation unavailable.',404);
        $org=(int)$row->org_id; $actor=get_current_user_id(); $wpdb->query('START TRANSACTION');
        try {
            $organization=Atlas_Partner_Security::active_org($org,true);
            $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE token_hash=%s FOR UPDATE',$hash));
            if (!$row || $row->state!=='pending' || !Atlas_Partner_Security::active($organization) || !hash_equals($row->email,strtolower(wp_get_current_user()->user_email)) || !in_array($row->role,Atlas_Partner_Security::roles($org,(int)$row->created_by),true)) throw new DomainException('unavailable');
            $capabilities=json_decode($row->capability_ceiling,true);
            if (!is_array($capabilities) || !$capabilities || array_diff($capabilities,array('atlas_manage_records','atlas_manage_offers'))) throw new DomainException('capability ceiling');
            foreach ($capabilities as $capability) if (!user_can((int)$row->created_by,$capability)) throw new DomainException('capability revoked');
            if (DB::now()>=$row->expires_at) {
                if ($wpdb->update(self::table(),array('state'=>'expired'),array('id'=>$row->id))!==1 || !DB::audit($actor,$org,'partner.invitation_expire',$row->id,'success')) throw new RuntimeException('expiry');
                $wpdb->query('COMMIT'); return Atlas_Partner_Security::error('atlas_invitation_unavailable','Invitation unavailable.',404);
            }
            if ($wpdb->get_var($wpdb->prepare('SELECT user_id FROM '.DB::table('memberships').' WHERE org_id=%d AND user_id=%d FOR UPDATE',$org,$actor))) throw new DomainException('existing membership');
            if (!$wpdb->insert(DB::table('memberships'),array('org_id'=>$org,'user_id'=>$actor,'role'=>$row->role,'status'=>'active','updated_at'=>DB::now())) || $wpdb->update(self::table(),array('state'=>'accepted','accepted_by'=>$actor,'accepted_at'=>DB::now()),array('id'=>$row->id,'state'=>'pending'))!==1 || !DB::audit($actor,$org,'partner.invitation_accept',$row->id,'success') || !DB::emit('partner.member_accepted',$row->id)) throw new RuntimeException('write');
            $wpdb->query('COMMIT');
            $ceiling=Atlas_Partner_Workflow::activate_member_ceiling($actor,$org,$capabilities);
            return Atlas_Partner_Security::response(array('id'=>$row->id,'org_id'=>$org,'state'=>'accepted','role'=>$row->role,'capability_setup'=>$ceiling?'ready':'requires_review'));
        } catch (DomainException $e) { $wpdb->query('ROLLBACK'); return Atlas_Partner_Security::error('atlas_invitation_unavailable','Invitation unavailable.',404); }
        catch (Throwable $e) { $wpdb->query('ROLLBACK'); return Atlas_Partner_Security::error('atlas_invitation_failed','Invitation could not be accepted.',503); }
    }
    public static function panel($customer=false) {
        if (!is_user_logged_in()) return ''; $s='Atlas_Partner_Security';
        if (!self::$ready) return '<p>'.esc_html($s::t('Partner invitation storage is unavailable.','سامانه نگهداری دعوت‌ها در دسترس نیست.')).'</p>';
        $html='<details><summary>'.esc_html($s::t('Staff invitations','دعوت کارکنان')).'</summary><p>'.esc_html($s::t('Invitation tokens are shown once for manual delivery. No email is sent. Sign in with the invited email to accept.','رمز دعوت فقط یک‌بار برای تحویل دستی نمایش داده می‌شود. ایمیلی ارسال نمی‌شود. برای پذیرش با ایمیل دعوت‌شده وارد شوید.')).'</p>';
        $html.=$s::form_start('atlas_partner_invitation').'<input type="hidden" name="operation" value="accept">'.$s::input('token',$s::t('Invitation token','رمز دعوت')).'<button>'.esc_html($s::t('Accept invitation','پذیرش دعوت')).'</button></form>';
        if (!$customer) {
            $html.=$s::selector(); $orgs=$s::selected_orgs();
            foreach ($orgs as $org) if ($s::manager((int)$org)) {
                $html.='<h3>'.esc_html($s::t('Organization ','سازمان ').$org).'</h3>'.$s::form_start('atlas_partner_invitation').'<input type="hidden" name="operation" value="create"><input type="hidden" name="org_id" value="'.esc_attr($org).'">'.$s::input('email',$s::t('Invited email','ایمیل دعوت‌شونده'),'','email').$s::input('expires_in_hours',$s::t('Expires in hours (1–168)','اعتبار به ساعت (۱–۱۶۸)'),24,'number').'<label>'.esc_html($s::t('Role','نقش')).'<select name="role">';
                foreach ($s::roles($org) as $role) $html.='<option>'.esc_html($role).'</option>';
                $html.='</select></label><button>'.esc_html($s::t('Create invitation','ایجاد دعوت')).'</button></form>';
                $page=isset($_GET['atlas_invitation_page']) && is_scalar($_GET['atlas_invitation_page'])?max(1,min(10000,(int)$_GET['atlas_invitation_page'])):1;
                $r=new WP_REST_Request('GET','/'); $r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest')); $r->set_url_params(array('org_id'=>(int)$org)); $r->set_query_params(array('page'=>$page)); $list=self::listing($r);
                if (!is_wp_error($list)) foreach ($list->get_data()['items'] as $item) {
                    $html.='<p>'.esc_html($item['email'].' — '.$item['role'].' — '.$item['state'].' — '.$item['expires_at']).'</p>';
                    if ($item['state']==='pending') $html.=$s::form_start('atlas_partner_invitation').'<input type="hidden" name="operation" value="revoke"><input type="hidden" name="org_id" value="'.esc_attr($org).'"><input type="hidden" name="id" value="'.esc_attr($item['id']).'">'.$s::input('reason',$s::t('Reason','دلیل')).'<button>'.esc_html($s::t('Revoke invitation','لغو دعوت')).'</button></form>';
                }
                if (!is_wp_error($list)) $html.=$s::pagination('atlas_invitation_page',$page,count($list->get_data()['items']));
            }
        }
        return $html.'</details>';
    }
    public static function form() {
        check_admin_referer('atlas_partner_invitation'); $s='Atlas_Partner_Security'; $op=$s::post('operation');
        $r=new WP_REST_Request('POST','/'); $r->set_header('Content-Type','application/json'); $r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest')); $r->set_header('Idempotency-Key',$s::post('request_key')); $r->set_url_params(array('org_id'=>absint($s::post('org_id')),'id'=>$s::post('id')));
        $b=$op==='create'?array('email'=>$s::post('email'),'role'=>$s::post('role'),'expires_in_hours'=>(int)$s::post('expires_in_hours')):($op==='revoke'?array('reason'=>$s::post('reason')):array('token'=>$s::post('token'))); $r->set_body(wp_json_encode($b));
        $result=in_array($op,array('create','revoke','accept'),true)?self::$op($r):$s::error('atlas_invalid_action','Invalid action.');
        nocache_headers(); if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()),'',array('response'=>(int)($result->get_error_data()['status']??422)));
        $data=$result->get_data(); $html='<p>'.esc_html($s::t('Recorded.','ثبت شد.')).'</p>';
        if (isset($data['token'])) $html.='<p>'.esc_html($s::t('Copy this token now and deliver it privately. It will not be shown again. No email was sent.','این رمز را اکنون کپی و خصوصی تحویل دهید. دوباره نمایش داده نمی‌شود. ایمیلی ارسال نشده است.')).'</p><code>'.esc_html($data['token']).'</code>';
        $html.='<p><a href="'.esc_url(add_query_arg(array('atlas_area'=>$op==='accept'?'account':'portal','lang'=>isset($_GET['lang'])&&$_GET['lang']==='fa'?'fa':'en'),home_url('/'))).'">'.esc_html($s::t('Return to account','بازگشت به حساب')).'</a></p>';
        wp_die($html,esc_html($s::t('Staff invitation','دعوت کارکنان')),array('response'=>200));
    }
}
add_action('atlas_operations_ready',array('Atlas_Partner_Invitations','register'));
