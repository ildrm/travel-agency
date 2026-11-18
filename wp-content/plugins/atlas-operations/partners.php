<?php
if (!defined('ABSPATH')) { exit; }
use Atlas\Core\Auth;
use Atlas\Core\DB;
use Atlas\Core\Records;

/** Agency-reviewed partner onboarding; never grants WordPress administration. */
final class Atlas_Partner_Workflow {
    private static function error($code,$message,$status=422) { return new WP_Error($code,__($message,'atlas-operations'),array('status'=>$status)); }
    public static function register() {
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_action('admin_post_atlas_partner_review',array(__CLASS__,'form_review'));
        add_action('admin_post_atlas_partner_member',array(__CLASS__,'form_member'));
    }
    public static function routes() {
        register_rest_route('agency/v1','/partner-applications/(?P<id>[a-f0-9-]{36})/review',array('methods'=>'POST','permission_callback'=>static function(){return Auth::agency();},'callback'=>array(__CLASS__,'review')));
        register_rest_route('agency/v1','/organizations/(?P<id>\d+)/members',array(
            array('methods'=>'GET','permission_callback'=>static function(){return Auth::agency();},'callback'=>array(__CLASS__,'members')),
            array('methods'=>'POST','permission_callback'=>static function(){return Auth::agency();},'callback'=>array(__CLASS__,'change_member')),
        ));
    }
    private static function grant_ceiling($user_id) {
        $user=get_userdata($user_id); if (!$user) return false;
        // These are only the global ceiling. Core still requires active organization
        // membership and its operation-specific role on every scoped read or write.
        $user->add_cap('atlas_manage_records'); $user->add_cap('atlas_manage_offers');
        $caps=get_user_meta($user_id,$user->cap_key,true);
        return is_array($caps) && !empty($caps['atlas_manage_records']) && !empty($caps['atlas_manage_offers']);
    }
    /** A persisted active membership is mandatory before account ceilings are set. */
    public static function activate_member_ceiling($user_id,$org_id,array $capabilities) {
        if (!Auth::actor((int)$user_id) || !Auth::member((int)$org_id,(int)$user_id) || !$capabilities || array_diff($capabilities,array('atlas_manage_records','atlas_manage_offers'))) return false;
        // Mutate the current WP_User instance too: a separate get_userdata object
        // would persist caps while current_user_can kept its old request-local map.
        $user=wp_get_current_user(); if ((int)$user->ID!==(int)$user_id) return false;
        foreach ($capabilities as $capability) $user->add_cap($capability);
        $caps=get_user_meta($user_id,$user->cap_key,true);
        foreach ($capabilities as $capability) if (empty($caps[$capability])) return false;
        return true;
    }
    public static function review($request) {
        global $wpdb;
        if (!Auth::agency()) return self::error('atlas_forbidden','Agency access required.',403);
        $body=$request->get_json_params(); $id=(string)$request['id'];
        if (!is_array($body) || array_diff(array_keys($body),array('version','decision','evidence_reference','reason','initial_role')) || !isset($body['version'],$body['decision'],$body['evidence_reference']) || !is_int($body['version']) || !in_array($body['decision'],array('approved','rejected'),true) || !is_string($body['evidence_reference']) || !preg_match('/^[A-Za-z0-9._:-]{8,120}$/',$body['evidence_reference']) || (isset($body['reason']) && (!is_string($body['reason']) || strlen($body['reason'])>1000))) return self::error('atlas_invalid_review','A current version, decision and reviewed evidence reference are required.');
        $role=$body['initial_role']??'contributor';
        if (!is_string($role) || !in_array($role,Auth::organization_roles(),true)) return self::error('atlas_invalid_role','Unknown organization role.');
        $reason=sanitize_textarea_field($body['reason']??''); $hash=hash('sha256',wp_json_encode(array($body['version'],$body['decision'],$body['evidence_reference'],$reason,$role))); $actor=get_current_user_id();
        $wpdb->query('START TRANSACTION');
        try {
            $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('records').' WHERE id=%s AND type=%s FOR UPDATE',$id,'partner_application'));
            if (!$row || (int)$row->owner_id===$actor || !get_userdata((int)$row->owner_id)) { $wpdb->query('ROLLBACK'); return self::error('atlas_review_forbidden','An independent agency reviewer and applicant are required.',403); }
            $fields=json_decode($row->payload,true)?:array();
            if (in_array($row->state,array('approved','rejected'),true) && hash_equals((string)($fields['review_hash']??''),$hash) && (int)($fields['reviewed_by']??0)===$actor) {
                $wpdb->query('ROLLBACK'); if ($row->state==='approved' && !self::grant_ceiling((int)$row->owner_id)) return self::error('atlas_capability_pending','Organization approved; account capability setup requires review.',503);
                return Records::get('partner_application',$id,$actor);
            }
            if ($row->state!=='submitted' || (int)$row->version!==$body['version']) { $wpdb->query('ROLLBACK'); return self::error('atlas_conflict','Application changed or is not awaiting review.',409); }
            $org=0;
            if ($body['decision']==='approved') {
                if (empty($fields['organization_name']) || !$wpdb->insert(DB::table('organizations'),array('name'=>$fields['organization_name'],'status'=>'approved','created_at'=>DB::now()))) throw new RuntimeException('organization');
                $org=(int)$wpdb->insert_id;
                if (!$wpdb->insert(DB::table('memberships'),array('org_id'=>$org,'user_id'=>(int)$row->owner_id,'role'=>$role,'status'=>'active','updated_at'=>DB::now()))) throw new RuntimeException('membership');
            }
            $fields['state']=$body['decision']; $fields['organization_id']=$org; $fields['review_reason']=$reason; $fields['reviewed_by']=$actor; $fields['review_hash']=$hash;
            if ($wpdb->update(DB::table('records'),array('state'=>$body['decision'],'payload'=>wp_json_encode($fields),'version'=>$body['version']+1,'updated_at'=>DB::now()),array('id'=>$id,'version'=>$body['version']))!==1 || !DB::audit($actor,$org,'partner.application_review',$id,'success',$body['evidence_reference']) || !DB::emit('partner.reviewed',$id)) throw new RuntimeException('review');
            $wpdb->query('COMMIT');
            if ($org && !self::grant_ceiling((int)$row->owner_id)) return self::error('atlas_capability_pending','Organization approved; account capability setup requires review.',503);
            return Records::get('partner_application',$id,$actor);
        } catch (Throwable $error) { $wpdb->query('ROLLBACK'); return self::error('atlas_review_failed','Application review could not be saved.',503); }
    }
    public static function members($request) {
        global $wpdb;
        if (!Auth::agency()) return self::error('atlas_forbidden','Agency access required.',403);
        $org=(int)$request['id']; $rows=$wpdb->get_results($wpdb->prepare('SELECT user_id,role,status,updated_at FROM '.DB::table('memberships').' WHERE org_id=%d ORDER BY user_id LIMIT 100',$org),ARRAY_A);
        $response=new WP_REST_Response(array('organization_id'=>$org,'members'=>$rows,'roles'=>Auth::organization_roles()),200); $response->header('Cache-Control','private, no-store'); return $response;
    }
    public static function change_member($request) {
        global $wpdb;
        if (!Auth::agency()) return self::error('atlas_forbidden','Agency access required.',403);
        $body=$request->get_json_params(); $org=(int)$request['id']; $key=(string)$request->get_header('Idempotency-Key');
        if (!is_array($body) || array_diff(array_keys($body),array('user_id','role','status','expected_updated_at','reason')) || !isset($body['user_id'],$body['role'],$body['status'],$body['expected_updated_at'],$body['reason']) || !is_int($body['user_id']) || $body['user_id']<1 || !get_userdata($body['user_id']) || !is_string($body['role']) || !in_array($body['role'],Auth::organization_roles(),true) || !in_array($body['status'],array('active','revoked'),true) || !is_string($body['expected_updated_at']) || strlen($body['expected_updated_at'])>32 || !is_string($body['reason']) || trim($body['reason'])==='' || strlen($body['reason'])>190 || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$key)) return self::error('atlas_invalid_membership','A known user, role, status, revision, reason and request key are required.');
        $hash=hash('sha256',wp_json_encode(array($org,$body['user_id'],$body['role'],$body['status'],$body['expected_updated_at'],$body['reason']))); $scope='user:'.get_current_user_id();
        $wpdb->query('START TRANSACTION');
        try {
            $organization=$wpdb->get_row($wpdb->prepare('SELECT id,status FROM '.DB::table('organizations').' WHERE id=%d FOR UPDATE',$org));
            if (!$organization || !in_array($organization->status,array('active','approved'),true)) { $wpdb->query('ROLLBACK'); return self::error('atlas_not_found','Approved organization unavailable.',404); }
            $prior=$wpdb->get_row($wpdb->prepare('SELECT request_hash,response FROM '.DB::table('idempotency').' WHERE scope=%s AND operation=%s AND idem_key=%s',$scope,'partner.member',$key));
            if ($prior) { $wpdb->query('ROLLBACK'); if (!hash_equals($prior->request_hash,$hash)) return self::error('atlas_idempotency_conflict','The request key was reused.',409); if ($body['status']==='active') self::grant_ceiling($body['user_id']); return new WP_REST_Response(json_decode($prior->response,true),200); }
            $member=$wpdb->get_row($wpdb->prepare('SELECT role,status,updated_at FROM '.DB::table('memberships').' WHERE org_id=%d AND user_id=%d FOR UPDATE',$org,$body['user_id']));
            if (($member && !hash_equals($member->updated_at,$body['expected_updated_at'])) || (!$member && $body['expected_updated_at']!=='')) { $wpdb->query('ROLLBACK'); return self::error('atlas_conflict','Membership changed. Refresh before updating.',409); }
            $now=DB::now(); $values=array('role'=>$body['role'],'status'=>$body['status'],'updated_at'=>$now);
            $ok=$member ? $wpdb->update(DB::table('memberships'),$values,array('org_id'=>$org,'user_id'=>$body['user_id'])) : $wpdb->insert(DB::table('memberships'),array_merge($values,array('org_id'=>$org,'user_id'=>$body['user_id'])));
            $result=array('organization_id'=>$org,'user_id'=>$body['user_id'],'role'=>$body['role'],'status'=>$body['status'],'updated_at'=>$now);
            if ($ok===false || !DB::audit(get_current_user_id(),$org,'partner.membership',(string)$body['user_id'],'success',sanitize_text_field($body['reason'])) || !$wpdb->insert(DB::table('idempotency'),array('scope'=>$scope,'operation'=>'partner.member','idem_key'=>$key,'request_hash'=>$hash,'response'=>wp_json_encode($result),'created_at'=>$now))) throw new RuntimeException('membership');
            $wpdb->query('COMMIT');
            if ($body['status']==='active' && !self::grant_ceiling($body['user_id'])) return self::error('atlas_capability_pending','Membership saved; account capability setup requires review.',503);
            return new WP_REST_Response($result,200);
        } catch (Throwable $error) { $wpdb->query('ROLLBACK'); return self::error('atlas_membership_failed','Membership could not be changed.',503); }
    }
    private static function text($en,$fa) { return isset($_GET['lang']) && $_GET['lang']==='fa' ? $fa : $en; }
    public static function panel() {
        if (!Auth::agency()) return '';
        global $wpdb; $applications=Records::query('partner_application',array('limit'=>50),get_current_user_id()); if (is_wp_error($applications)) return '';
        $html='<details><summary>'.esc_html(self::text('Partner applications and staff','درخواست همکاری و کارکنان')).'</summary>';
        foreach ($applications as $application) {
            $f=$application['fields']; $html.='<article><h3>'.esc_html($f['organization_name']??$application['id']).'</h3><p>'.esc_html($f['contact']??'').' — '.esc_html($application['state']).'</p>';
            if ($application['state']==='submitted') {
                $html.='<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('atlas_partner_review','_wpnonce',true,false).'<input type="hidden" name="action" value="atlas_partner_review"><input type="hidden" name="application_id" value="'.esc_attr($application['id']).'"><input type="hidden" name="version" value="'.esc_attr($application['version']).'"><label>'.esc_html(self::text('Decision','تصمیم')).'<select name="decision"><option value="approved">Approve / تأیید</option><option value="rejected">Reject / رد</option></select></label><label>'.esc_html(self::text('Reviewed evidence reference','مرجع شواهد بررسی‌شده')).'<input name="evidence_reference" required pattern="[A-Za-z0-9._:-]{8,120}"></label><label>'.esc_html(self::text('Public reason for applicant','دلیل قابل نمایش به درخواست‌کننده')).'<textarea name="reason" maxlength="1000"></textarea></label><button>'.esc_html(self::text('Record independent review','ثبت بررسی مستقل')).'</button></form>';
            }
            $html.='</article>';
        }
        $orgs=$wpdb->get_results('SELECT id,name FROM '.DB::table('organizations')." WHERE status IN ('active','approved') ORDER BY id LIMIT 100");
        $html.='<p>'.esc_html(self::text('Agency controls staff membership. Organization roles never grant WordPress administration.','آژانس عضویت کارکنان را کنترل می‌کند. نقش سازمانی دسترسی مدیریت وردپرس نمی‌دهد.')).'</p><ul>';
        foreach ($orgs as $org) {
            $html.='<li>'.esc_html($org->name).' <code>'.esc_html((string)$org->id).'</code><ul>';
            $members=$wpdb->get_results($wpdb->prepare('SELECT user_id,role,status,updated_at FROM '.DB::table('memberships').' WHERE org_id=%d ORDER BY user_id',$org->id));
            foreach ($members as $member) $html.='<li>'.esc_html((string)$member->user_id).' — '.esc_html($member->role.' / '.$member->status).' <code>'.esc_html($member->updated_at).'</code></li>';
            $html.='</ul></li>';
        }
        $html.='</ul><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('atlas_partner_member','_wpnonce',true,false).'<input type="hidden" name="action" value="atlas_partner_member"><input type="hidden" name="idempotency_key" value="'.esc_attr(wp_generate_uuid4()).'">';
        foreach (array('org_id'=>self::text('Organization ID','شناسه سازمان'),'user_id'=>self::text('User ID','شناسه کاربر'),'expected_updated_at'=>self::text('Current membership revision; blank for new member','نسخه فعلی عضویت؛ برای عضو جدید خالی'),'reason'=>self::text('Reason','دلیل')) as $name=>$label) $html.='<label>'.esc_html($label).'<input name="'.esc_attr($name).'"'.($name==='expected_updated_at'?'':' required').'></label>';
        $html.='<label>'.esc_html(self::text('Role','نقش')).'<select name="role">'; foreach (Auth::organization_roles() as $role) $html.='<option value="'.esc_attr($role).'">'.esc_html($role).'</option>';
        return $html.'</select></label><label>'.esc_html(self::text('Status','وضعیت')).'<select name="status"><option value="active">Active / فعال</option><option value="revoked">Revoked / لغوشده</option></select></label><button>'.esc_html(self::text('Save membership','ذخیره عضویت')).'</button></form></details>';
    }
    public static function form_review() {
        if (!Auth::agency()) wp_die(esc_html__('Agency access required.','atlas-operations'),'',array('response'=>403)); check_admin_referer('atlas_partner_review');
        $r=new WP_REST_Request('POST','/'); $r->set_url_params(array('id'=>sanitize_text_field(wp_unslash($_POST['application_id']??'')))); $r->set_header('content-type','application/json'); $r->set_body(wp_json_encode(array('version'=>absint($_POST['version']??0),'decision'=>sanitize_key(wp_unslash($_POST['decision']??'')),'evidence_reference'=>sanitize_text_field(wp_unslash($_POST['evidence_reference']??'')),'reason'=>sanitize_textarea_field(wp_unslash($_POST['reason']??'')))));
        $result=self::review($r); if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()),'',array('response'=>422)); wp_safe_redirect(wp_get_referer()?:home_url('/')); exit;
    }
    public static function form_member() {
        if (!Auth::agency()) wp_die(esc_html__('Agency access required.','atlas-operations'),'',array('response'=>403)); check_admin_referer('atlas_partner_member');
        $r=new WP_REST_Request('POST','/'); $r->set_url_params(array('id'=>absint($_POST['org_id']??0))); $r->set_header('content-type','application/json'); $r->set_header('Idempotency-Key',sanitize_text_field(wp_unslash($_POST['idempotency_key']??''))); $r->set_body(wp_json_encode(array('user_id'=>absint($_POST['user_id']??0),'role'=>sanitize_key(wp_unslash($_POST['role']??'')),'status'=>sanitize_key(wp_unslash($_POST['status']??'')),'expected_updated_at'=>sanitize_text_field(wp_unslash($_POST['expected_updated_at']??'')),'reason'=>sanitize_text_field(wp_unslash($_POST['reason']??'')))));
        $result=self::change_member($r); if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()),'',array('response'=>422)); wp_safe_redirect(wp_get_referer()?:home_url('/')); exit;
    }
}
