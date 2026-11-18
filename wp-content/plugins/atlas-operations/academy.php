<?php
/** Reviewed academy delivery and completion. No accreditation or supplier verification is implied. */
if (!defined('ABSPATH')) { exit; }
use Atlas\Core\Auth;
use Atlas\Core\DB;
use Atlas\Core\Records;

final class Atlas_Academy {
    private const TYPES = array('academy_plan','academy_progress','academy_certificate','academy_authorization','academy_prerequisite');
    private const CAPS = array('atlas_academy_schedule','atlas_academy_instruct','atlas_academy_review','atlas_academy_issue');
    private static $participant_cache=array();
    public static function grant_capabilities() {
        $role=get_role('administrator');
        if ($role) foreach (self::CAPS as $cap) $role->add_cap($cap);
    }
    public static function register() {
        foreach (self::TYPES as $type) Records::register_type($type,array('fields'=>array('data'=>array('type'=>'array','required'=>true)), 'capability'=>'atlas_academy_schedule','organization_roles'=>array('read'=>array(),'write'=>array()),'initial_states'=>array(),'transitions'=>array('state'=>array())));
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_shortcode('atlas_academy',array(__CLASS__,'panel'));
        add_filter('atlas_operations_dashboard',static function($html,$customer) { return $html.self::panel(); },10,2);
        add_filter('atlas_core_validate_cohort_publication',array(__CLASS__,'validate_offer'),30,3);
        add_filter('atlas_core_validate_cohort_hold',array(__CLASS__,'validate_offer'),30,3);
        add_filter('atlas_core_validate_cohort_participants',array(__CLASS__,'validate_participants'),10,6);
        add_filter('atlas_core_prepare_hold',array(__CLASS__,'prepare_learners'),20,6);
        add_filter('atlas_core_before_payment_initiation',array(__CLASS__,'before_payment'),20,4);
        add_filter('atlas_core_before_confirmation',array(__CLASS__,'before_confirmation'),20,4);
        add_filter('atlas_operations_booking_fields',array(__CLASS__,'learner_fields'),20,2);
        add_filter('atlas_notification_events',array(__CLASS__,'notification_events'));
        add_filter('atlas_notification_recipients',array(__CLASS__,'notification_recipients'),20,2);
    }
    public static function enabled() { return (defined('ATLAS_ACADEMY_ENABLED') && ATLAS_ACADEMY_ENABLED===true) || (wp_get_environment_type()==='local' && defined('ATLAS_DEMO_MODE') && ATLAS_DEMO_MODE===true); }
    private static function t($en,$fa) { $language=$_GET['lang']??''; return (in_array($language,array('en','fa'),true) ? $language==='fa' : str_starts_with(determine_locale(),'fa')) ? $fa : $en; }
    private static function error($code,$en,$fa,$status=422) { return new WP_Error('atlas_academy_'.$code,self::t($en,$fa),array('status'=>$status)); }
    private static function uuid($seed) { $h=hash('sha256',$seed); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12); }
    private static function row($type,$id,$lock=false) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('records').' WHERE type=%s AND id=%s'.($lock?' FOR UPDATE':''),$type,$id));
    }
    private static function data($row) { return $row ? (json_decode($row->payload,true) ?: array()) : array(); }
    private static function project($row) { return array('id'=>$row->id,'version'=>(int)$row->version,'state'=>$row->state,'fields'=>self::data($row)); }
    private static function agency($cap) { return Auth::agency() && current_user_can($cap); }
    private static function instructor($plan,$session=null,$operation='read') {
        $user=get_current_user_id();
        if (!$user || !current_user_can('atlas_academy_instruct') || !Auth::role_allows((int)$plan['org_id'],$operation)) return false;
        foreach ($plan['sessions']??array() as $item) if (($session===null || $item['id']===$session) && in_array($user,$item['instructors'],true)) return true;
        return false;
    }
    private static function staff($plan) { return self::agency('atlas_academy_schedule') || self::agency('atlas_academy_review') || self::agency('atlas_academy_issue') || self::instructor($plan); }
    public static function permission() { return self::enabled() && is_user_logged_in() ? true : self::error('unavailable','Academy workspace unavailable.','فضای آموزش در دسترس نیست.',403); }
    public static function routes() {
        $private=array('permission_callback'=>array(__CLASS__,'permission'));
        register_rest_route('agency/v1','/academy/authorizations',array_merge($private,array('methods'=>'GET','callback'=>array(__CLASS__,'my_authorizations'))));
        register_rest_route('agency/v1','/academy/authorizations',array_merge($private,array('methods'=>'POST','callback'=>array(__CLASS__,'authorize_payer'))));
        register_rest_route('agency/v1','/academy/authorizations/(?P<id>[a-f0-9-]{36})/revoke',array_merge($private,array('methods'=>'POST','callback'=>array(__CLASS__,'revoke_authorization'))));
        register_rest_route('agency/v1','/academy/cohorts/(?P<id>[a-f0-9-]{36})/payer-authorizations',array_merge($private,array('methods'=>'GET','callback'=>array(__CLASS__,'payer_authorizations'))));
        register_rest_route('agency/v1','/academy/cohorts/(?P<id>[a-f0-9-]{36})/prerequisite-review',array_merge($private,array('methods'=>'POST','callback'=>array(__CLASS__,'review_prerequisites'))));
        register_rest_route('agency/v1','/academy/cohorts/(?P<id>[a-f0-9-]{36})/prerequisite-review',array_merge($private,array('methods'=>'GET','callback'=>array(__CLASS__,'read_prerequisite'))));
        register_rest_route('agency/v1','/academy/cohorts/(?P<id>[a-f0-9-]{36})',array_merge($private,array('methods'=>'GET','callback'=>array(__CLASS__,'workspace'))));
        register_rest_route('agency/v1','/academy/cohorts/(?P<id>[a-f0-9-]{36})/plan',array_merge($private,array('methods'=>'POST','callback'=>array(__CLASS__,'save_plan'))));
        register_rest_route('agency/v1','/academy/cohorts/(?P<id>[a-f0-9-]{36})/roster',array_merge($private,array('methods'=>'GET','callback'=>array(__CLASS__,'roster'))));
        register_rest_route('agency/v1','/academy/enrollments/(?P<id>[a-f0-9-]{36})',array_merge($private,array('methods'=>'GET','callback'=>array(__CLASS__,'progress'))));
        foreach (array('attendance','assessment','consent','appeal','appeal-review','issue') as $action) register_rest_route('agency/v1','/academy/enrollments/(?P<id>[a-f0-9-]{36})/'.$action,array_merge($private,array('methods'=>'POST','callback'=>static function($r) use($action) { return self::mutate($r,$action); })));
        register_rest_route('agency/v1','/academy/certificates/(?P<id>[a-f0-9-]{36})/revoke',array_merge($private,array('methods'=>'POST','callback'=>array(__CLASS__,'revoke'))));
        register_rest_route('agency/v1','/academy/verify/(?P<reference>[a-f0-9]{64})',array('methods'=>'GET','permission_callback'=>'__return_true','callback'=>array(__CLASS__,'verify')));
    }
    private static function body($request,$allowed) {
        $body=$request->get_json_params();
        if (!is_array($body) || array_diff(array_keys($body),$allowed)) return self::error('invalid','Invalid academy request.','درخواست آموزش نامعتبر است.');
        return $body;
    }
    private static function text($value,$max=1000) { return is_string($value) && trim($value)!=='' && strlen($value)<=$max && sanitize_text_field($value)===$value; }
    private static function instant($value) {
        if (!is_string($value)) return false;
        $time=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new DateTimeZone('UTC'));
        return $time && $time->format('Y-m-d\TH:i:s\Z')===$value;
    }
    /** Only unambiguous reviewed cohort/course associations can acquire participants. */
    public static function cohort_for_offer($offer,$org,$lock=false) {
        global $wpdb; $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.DB::table('records').' WHERE type=%s AND org_id=%d AND state IN (%s,%s) ORDER BY id LIMIT 1001'.($lock?' FOR UPDATE':''),'cohort',$org,'approved','submitted'));
        if (count($rows)>1000) return null; $found=null;
        foreach ($rows as $row) { $f=self::data($row); if ((int)($f['offer_id']??0)!==(int)$offer) continue; if ($row->state!=='approved' || $found!==null) return null; $course=self::row('course',(string)($f['course_id']??''),$lock); if (!$course || $course->state!=='approved' || (int)$course->org_id!==$org) return null; $found=$row->id; }
        return $found;
    }
    private static function requirements($cohort) {
        $c=self::data($cohort); $course=self::row('course',(string)($c['course_id']??'')); $course_fields=self::data($course); $plan=self::plan($cohort->id); $p=self::data($plan);
        $course_text=$p['course_snapshot']['prerequisites']??($course_fields['prerequisites']??''); $cohort_text=$p['cohort_prerequisites']??($c['prerequisites']??'');
        return array('required'=>trim($course_text.$cohort_text)!=='','hash'=>hash('sha256',wp_json_encode(array($cohort->id,$plan?$plan->id:'',$plan?(int)$plan->version:0,$course_text,$cohort_text))));
    }
    private static function prerequisite($cohort,$learner,$lock=false) {
        $requirements=self::requirements($cohort); if (!$requirements['required']) return array('id'=>'','version'=>0);
        $row=self::row('academy_prerequisite',self::uuid('academy-prerequisite:'.$cohort->id.':'.$learner),$lock); $f=self::data($row);
        if (!$row || $row->state!=='approved' || (int)$row->owner_id!==$learner || (int)$row->org_id!==(int)$cohort->org_id || ($f['requirements_hash']??'')!==$requirements['hash'] || !self::instant($f['valid_until']??null) || strtotime($f['valid_until'])<=strtotime(DB::now()) || (int)($f['reviewer']??0)<1) return self::error('prerequisites','Agency review of the learner prerequisites is required.','بررسی پیش‌نیازهای فراگیر توسط آژانس لازم است.',409);
        return array('id'=>$row->id,'version'=>(int)$row->version);
    }
    public static function review_prerequisites($request) {
        if (!self::agency('atlas_academy_review')) return self::error('forbidden','Academy reviewer access required.','دسترسی بازبین آموزش لازم است.',403);
        $b=self::body($request,array('version','learner_id','approved','valid_until','evidence_reference','reason')); if (is_wp_error($b)) return $b;
        if (!is_int($b['version']??null) || !is_int($b['learner_id']??null) || !get_userdata($b['learner_id']) || $b['learner_id']===get_current_user_id() || !is_bool($b['approved']??null) || !self::instant($b['valid_until']??null) || strtotime($b['valid_until'])<=strtotime(DB::now()) || strtotime($b['valid_until'])>strtotime(DB::now())+366*DAY_IN_SECONDS || !self::text($b['evidence_reference']??null,180) || !self::text($b['reason']??null)) return self::error('review','An independent review, bounded expiry and evidence reference are required.','بررسی مستقل، انقضای محدود و شناسه شواهد لازم است.');
        $cohort_id=(string)$request['id'];
        return self::transaction(static function() use($b,$cohort_id) {
            $cohort=self::row('cohort',$cohort_id,true); if (!$cohort || $cohort->state!=='approved') return self::error('cohort','Reviewed cohort unavailable.','گروه بازبینی‌شده در دسترس نیست.',404);
            $requirements=self::requirements($cohort); $id=self::uuid('academy-prerequisite:'.$cohort_id.':'.$b['learner_id']); $old=self::row('academy_prerequisite',$id,true);
            if (($old?(int)$old->version:0)!==$b['version']) return self::error('version','Prerequisite review changed. Reload it.','بررسی پیش‌نیاز تغییر کرده است. دوباره دریافت کنید.',409);
            $history=$old?(self::data($old)['history']??array()):array(); if (count($history)>=100) return self::error('review_limit','Create a new reviewed cohort for further policy changes.','برای تغییرات بیشتر گروه بازبینی‌شده جدید بسازید.',409);
            $history[]=array('approved'=>$b['approved'],'actor'=>get_current_user_id(),'at'=>DB::now(),'reference'=>$b['evidence_reference'],'reason'=>$b['reason']);
            $f=array('cohort_id'=>$cohort_id,'learner_id'=>$b['learner_id'],'requirements_hash'=>$requirements['hash'],'valid_until'=>$b['valid_until'],'reviewer'=>get_current_user_id(),'reviewed_at'=>DB::now(),'evidence_reference'=>$b['evidence_reference'],'reason'=>$b['reason'],'history'=>$history);
            return self::project(self::put('academy_prerequisite',$id,(int)$cohort->org_id,$b['learner_id'],$b['approved']?'approved':'rejected',$f,$old,'prerequisite_reviewed',$b['reason']));
        });
    }
    public static function read_prerequisite($request) {
        if (!self::agency('atlas_academy_review')) return self::error('forbidden','Academy reviewer access required.','دسترسی بازبین آموزش لازم است.',403);
        $learner=$request->get_param('learner_id'); if (!(is_int($learner) || (is_string($learner) && ctype_digit($learner))) || (int)$learner<1) return self::error('learner','A learner account reference is required.','شناسه حساب فراگیر لازم است.');
        $row=self::row('academy_prerequisite',self::uuid('academy-prerequisite:'.(string)$request['id'].':'.(int)$learner)); return array('review'=>$row?self::project($row):null);
    }
    private static function evidence_hash($row) { return hash('sha256',wp_json_encode(array($row->id,(int)$row->version,self::data($row)))); }
    public static function authorize_payer($request) {
        $b=self::body($request,array('cohort_id','payer_id','eligibility_reference','expires_at','learner_attested','reason')); if (is_wp_error($b)) return $b; $learner=get_current_user_id();
        if (!is_string($b['cohort_id']??null) || !preg_match('/^[a-f0-9-]{36}$/',$b['cohort_id']) || !is_int($b['payer_id']??null) || $b['payer_id']<1 || $b['payer_id']===$learner || !get_userdata($b['payer_id']) || !is_string($b['eligibility_reference']??null) || ($b['learner_attested']??false)!==true || !self::instant($b['expires_at']??null) || strtotime($b['expires_at'])<=strtotime(DB::now()) || strtotime($b['expires_at'])>strtotime(DB::now())+90*DAY_IN_SECONDS || !self::text($b['reason']??null)) return self::error('authorization','Choose an identified payer, one cohort, your own evidence and an expiry within 90 days; explicit learner consent is required.','پرداخت‌کننده مشخص، یک گروه، شواهد خود و انقضای حداکثر ۹۰ روزه را انتخاب کنید؛ رضایت صریح فراگیر لازم است.');
        return self::transaction(static function() use($b,$learner) {
            $cohort=self::row('cohort',$b['cohort_id'],true); $c=self::data($cohort); $offer=Atlas\Core\Offers::get((int)($c['offer_id']??0),true);
            if (!$cohort || $cohort->state!=='approved' || is_wp_error($offer) || $offer['kind']!=='cohort' || self::cohort_for_offer((int)$offer['id'],(int)$offer['org_id'])!==$cohort->id || strtotime($b['expires_at'])>strtotime($offer['registration_close_at'])) return self::error('cohort','Choose a published cohort and an authorization expiry before admission closes.','گروه منتشرشده و انقضای اجازه پیش از پایان پذیرش را انتخاب کنید.',409);
            $evidence=null;
            if ($b['eligibility_reference']!=='') {
                $person=Records::get('traveler',$b['eligibility_reference'],$learner);
                if (is_wp_error($person) || (int)$person['owner_id']!==$learner || (int)$person['org_id']!==0) return self::error('evidence','Only your own private learner evidence can be authorized.','فقط شواهد خصوصی خود را می‌توانید مجاز کنید.',404);
                $eligible=Atlas\Core\Eligibility::evaluate($offer['eligibility']??array(),$person['fields']); if (is_wp_error($eligible)) return $eligible;
                $evidence=self::row('traveler',$b['eligibility_reference'],true);
                if (!$evidence || (int)$evidence->owner_id!==$learner || (int)$evidence->version!==(int)$person['version']) return self::error('evidence','Learner evidence changed. Review it before granting access.','شواهد فراگیر تغییر کرده است. پیش از اجازه بازبینی کنید.',409);
            } elseif (!empty($offer['eligibility'])) return self::error('evidence','Your own age/guardian evidence is required by this cohort.','شواهد سن و سرپرست متعلق به شما برای این گروه لازم است.');
            $f=array('cohort_id'=>$cohort->id,'offer_id'=>(int)$offer['id'],'learner_id'=>$learner,'payer_id'=>$b['payer_id'],'eligibility_reference'=>$b['eligibility_reference'],'eligibility_version'=>$evidence?(int)$evidence->version:0,'eligibility_hash'=>$evidence?self::evidence_hash($evidence):'','expires_at'=>$b['expires_at'],'accepted_at'=>DB::now(),'actor'=>$learner,'reason'=>$b['reason'],'scope'=>'one-cohort-eligibility-and-enrollment-only','self_declared_evidence'=>true);
            return self::project(self::put('academy_authorization',wp_generate_uuid4(),0,$learner,'accepted',$f,null,'payer_authorized',$b['reason']));
        });
    }
    public static function revoke_authorization($request) {
        $b=self::body($request,array('version','reason')); if (is_wp_error($b)) return $b;
        if (!is_int($b['version']??null) || !self::text($b['reason']??null)) return self::error('authorization','Current version and revocation reason required.','نسخه جاری و دلیل لغو لازم است.'); $id=(string)$request['id'];
        return self::transaction(static function() use($b,$id) {
            $row=self::row('academy_authorization',$id,true);
            if (!$row || (int)$row->owner_id!==get_current_user_id()) return self::error('not_found','Learner authorization not found.','اجازه فراگیر یافت نشد.',404);
            if ((int)$row->version!==$b['version'] || $row->state!=='accepted') return self::error('version','Authorization changed. Reload it.','اجازه تغییر کرده است. دوباره دریافت کنید.',409);
            $f=self::data($row); $f['revoked_at']=DB::now(); $f['revoked_by']=get_current_user_id(); $f['revocation_reason']=$b['reason'];
            return self::project(self::put('academy_authorization',$id,0,(int)$row->owner_id,'revoked',$f,$row,'payer_authorization_revoked',$b['reason']));
        });
    }
    public static function my_authorizations($request=null) {
        $offset=$request?$request->get_param('offset'):0; if ($offset===null) $offset=0;
        if (!(is_int($offset) || (is_string($offset) && ctype_digit($offset))) || (int)$offset<0 || (int)$offset>1000000) return self::error('offset','Invalid authorization list position.','موقعیت فهرست اجازه‌ها نامعتبر است.');
        global $wpdb; $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.DB::table('records').' WHERE type=%s AND owner_id=%d ORDER BY created_at DESC,id LIMIT 101 OFFSET %d','academy_authorization',get_current_user_id(),(int)$offset)); $items=array();
        foreach (array_slice($rows,0,100) as $row) { $record=self::project($row); unset($record['fields']['eligibility_hash']); $items[]=$record; }
        return array('items'=>$items,'has_more'=>count($rows)>100);
    }
    private static function addressed($cohort,$payer) {
        global $wpdb; $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.DB::table('records')." WHERE type=%s AND state=%s AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.cohort_id'))=%s AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.payer_id')) AS UNSIGNED)=%d ORDER BY id LIMIT 101",'academy_authorization','accepted',$cohort,$payer)); $items=array();
        foreach (array_slice($rows,0,100) as $row) { $f=self::data($row); if (strtotime($f['expires_at'])<=strtotime(DB::now()) || (int)$row->owner_id!==(int)$f['learner_id']) continue; $user=get_userdata((int)$f['learner_id']); if (!$user) continue; $items[]=array('authorization_id'=>$row->id,'authorization_version'=>(int)$row->version,'learner_id'=>(int)$f['learner_id'],'learner_name'=>$user->display_name,'eligibility_reference'=>$f['eligibility_reference'],'expires_at'=>$f['expires_at']); }
        return $items;
    }
    public static function payer_authorizations($request) { return array('items'=>self::addressed((string)$request['id'],get_current_user_id())); }
    private static function participant_key($offer,$payer,$qty,$options) { return hash('sha256',wp_json_encode(array((int)$offer['id'],$payer,$qty,$options))); }
    public static function notification_events($events) { return array_merge($events,array('academy.payer_authorized','academy.payer_authorization_revoked','academy.prerequisite_reviewed','academy.learner_linked','academy.schedule_published','academy.schedule_saved','academy.attendance','academy.assessment','academy.consent','academy.appeal','academy.appeal_review','academy.certificate_issued','academy.certificate_revoked','academy.issue','academy.revocation_recorded')); }
    public static function notification_recipients($previous,$event) {
        $type=$event['event_type']??''; if (!str_starts_with($type,'academy.')) return $previous;
        $id=$event['aggregate_id']??''; $users=array(); $reference=$id;
        if (in_array($type,array('academy.payer_authorized','academy.payer_authorization_revoked'),true)) { $row=self::row('academy_authorization',$id); $f=self::data($row); if ($row) $users=array((int)$row->owner_id,(int)($f['payer_id']??0)); }
        elseif ($type==='academy.prerequisite_reviewed') { $row=self::row('academy_prerequisite',$id); if ($row) $users=array((int)$row->owner_id); }
        elseif ($type==='academy.learner_linked') { $row=self::row('enrollment',$id); if ($row) $users=array((int)$row->owner_id); }
        elseif (in_array($type,array('academy.certificate_issued','academy.certificate_revoked'),true)) { $row=self::row('academy_certificate',$id); if ($row) $users=array((int)$row->owner_id); }
        elseif ($type==='academy.schedule_published') {
            $row=self::row('academy_plan',$id); $f=self::data($row); $reference=$f['cohort_id']??$id;
            if ($row) foreach ($f['sessions']??array() as $session) foreach ($session['instructors'] as $user) if (Auth::member((int)$row->org_id,(int)$user) && user_can((int)$user,'atlas_academy_instruct')) $users[]=(int)$user;
        } elseif (in_array($type,array('academy.schedule_saved','academy.issue','academy.revocation_recorded'),true)) return array(array('user'=>0,'reference'=>$id));
        else { $row=self::row('academy_progress',$id); if ($row) $users=array((int)$row->owner_id); }
        if ($type==='academy.appeal') foreach (get_users(array('role'=>'administrator','fields'=>'ids','number'=>100)) as $user) if (user_can((int)$user,'atlas_academy_review')) $users[]=(int)$user;
        $result=array(); foreach (array_unique($users) as $user) if ($user>0 && get_userdata($user)) $result[]=array('user'=>$user,'reference'=>$reference);
        return $result;
    }
    public static function validate_participants($prior,$offer,$payer,$qty,$refs,$options) {
        if ($prior!==null || ($offer['kind']??'')!=='cohort' || !self::enabled()) return $prior;
        $cohort_id=self::cohort_for_offer((int)$offer['id'],(int)$offer['org_id'],true); $cohort=$cohort_id?self::row('cohort',$cohort_id):null;
        if (!$cohort) return self::error('cohort','Reviewed cohort unavailable.','گروه بازبینی‌شده در دسترس نیست.',409);
        $legacy=!array_key_exists('academy_learners',$options);
        if ($legacy) {
            if ($qty!==1 || $options) return self::error('learners','Select one authorized learner per place.','برای هر نفر یک فراگیر مجاز انتخاب کنید.');
            $valid=Atlas\Core\Eligibility::validate($offer,$payer,1,$refs); if (is_wp_error($valid)) return $valid;
            $prerequisite=self::prerequisite($cohort,$payer,true); if (is_wp_error($prerequisite)) return $prerequisite;
            $learners=array(array('learner_id'=>$payer,'authorization_id'=>'','authorization_version'=>0,'eligibility_reference'=>$refs[0]??'','prerequisite_review_id'=>$prerequisite['id'],'prerequisite_review_version'=>$prerequisite['version']));
            self::$participant_cache[self::participant_key($offer,$payer,$qty,$options)]=array('cohort_id'=>$cohort_id,'learners'=>$learners);
            return self::requirements($cohort)['required']?true:null;
        }
        if ($refs || array_diff(array_keys($options),array('academy_learners')) || !is_array($options['academy_learners']) || !array_is_list($options['academy_learners']) || count($options['academy_learners'])!==$qty || $qty<1 || $qty>100) return self::error('learners','Select one distinct authorized learner per place; do not mix traveler lists.','برای هر نفر یک فراگیر مجاز یکتا انتخاب کنید و فهرست مسافران را ترکیب نکنید.');
        $selected=$options['academy_learners']; $ids=array(); $grant_ids=array(); $evidence_ids=array();
        foreach ($selected as $item) {
            if (!is_array($item) || array_diff(array_keys($item),array('learner_id','authorization_id','authorization_version','eligibility_reference')) || !is_int($item['learner_id']??null) || $item['learner_id']<1 || isset($ids[$item['learner_id']]) || !get_userdata($item['learner_id']) || !is_string($item['authorization_id']??null) || !is_string($item['eligibility_reference']??null) || !is_int($item['authorization_version']??null)) return self::error('learners','Learner selections must be distinct authenticated account references.','انتخاب فراگیران باید شناسه حساب‌های یکتای احراز‌شده باشد.');
            $ids[$item['learner_id']]=true;
            if ($item['learner_id']!==$payer) { if (!preg_match('/^[a-f0-9-]{36}$/',$item['authorization_id'])) return self::error('authorization','Learner-accepted payer authorization required.','اجازه پرداخت‌کننده با پذیرش فراگیر لازم است.',403); $grant_ids[]=$item['authorization_id']; }
            elseif ($item['authorization_id']!=='' || $item['authorization_version']!==0) return self::error('authorization','Self enrollment cannot borrow another authorization.','ثبت‌نام شخصی نمی‌تواند از اجازه دیگری استفاده کند.',403);
            if ($item['eligibility_reference']!=='') { if (!preg_match('/^[a-f0-9-]{36}$/',$item['eligibility_reference'])) return self::error('evidence','Invalid eligibility evidence reference.','شناسه شواهد صلاحیت نامعتبر است.'); $evidence_ids[]=$item['eligibility_reference']; }
        }
        sort($grant_ids,SORT_STRING); $grants=array(); foreach ($grant_ids as $id) $grants[$id]=self::row('academy_authorization',$id,true);
        sort($evidence_ids,SORT_STRING); $evidence=array(); foreach (array_unique($evidence_ids) as $id) $evidence[$id]=self::row('traveler',$id,true);
        $learners=array(); usort($selected,static function($a,$b) { return $a['learner_id']<=>$b['learner_id']; });
        foreach ($selected as $item) {
            $learner=$item['learner_id']; $grant=null;
            if ($learner!==$payer) {
                $grant=$grants[$item['authorization_id']]??null; $g=self::data($grant);
                if (!$grant || $grant->state!=='accepted' || (int)$grant->org_id!==0 || (int)$grant->owner_id!==$learner || (int)($g['actor']??0)!==$learner || (int)($g['payer_id']??0)!==$payer || ($g['cohort_id']??'')!==$cohort_id || (int)($g['offer_id']??0)!==(int)$offer['id'] || (int)$grant->version!==$item['authorization_version'] || ($g['eligibility_reference']??'')!==$item['eligibility_reference'] || !self::instant($g['expires_at']??null) || strtotime($g['expires_at'])<=strtotime(DB::now())) return self::error('authorization','Learner authorization is missing, expired, revoked or addressed to another payer/cohort.','اجازه فراگیر ناموجود، منقضی، لغوشده یا متعلق به پرداخت‌کننده یا گروه دیگری است.',403);
            }
            $person=$item['eligibility_reference']!==''?($evidence[$item['eligibility_reference']]??null):null;
            if ($person) {
                if ((int)$person->org_id!==0 || (int)$person->owner_id!==$learner || ($grant && ((int)$person->version!==(int)$g['eligibility_version'] || !hash_equals($g['eligibility_hash'],self::evidence_hash($person))))) return self::error('evidence','Authorized learner evidence changed or belongs to another account.','شواهد مجاز فراگیر تغییر کرده یا متعلق به حساب دیگری است.',403);
                $valid=Atlas\Core\Eligibility::evaluate($offer['eligibility']??array(),self::data($person)); if (is_wp_error($valid)) return $valid;
            } elseif ($item['eligibility_reference']!=='' || !empty($offer['eligibility'])) return self::error('evidence','Learner-owned age/guardian evidence is required.','شواهد سن و سرپرست متعلق به فراگیر لازم است.');
            $prerequisite=self::prerequisite($cohort,$learner,true); if (is_wp_error($prerequisite)) return $prerequisite;
            $item['eligibility_version']=$person?(int)$person->version:0; $item['eligibility_source']='learner-declared'; $item['prerequisite_review_id']=$prerequisite['id']; $item['prerequisite_review_version']=$prerequisite['version']; $learners[]=$item;
        }
        self::$participant_cache[self::participant_key($offer,$payer,$qty,$options)]=array('cohort_id'=>$cohort_id,'learners'=>$learners);
        return true;
    }
    public static function prepare_learners($prepared,$offer,$qty,$payer,$options,$now) {
        if (is_wp_error($prepared) || ($offer['kind']??'')!=='cohort' || !self::enabled()) return $prepared;
        $validated=self::$participant_cache[self::participant_key($offer,$payer,$qty,$options)]??null;
        if (!$validated) return self::error('learners','Validated learner bindings are required.','پیوند معتبر فراگیران لازم است.',409);
        $prepared['snapshot']['academy_cohort_id']=$validated['cohort_id']; $prepared['snapshot']['academy_learners']=$validated['learners']; $prepared['handled_options']=true; return $prepared;
    }
    /** Accepted bindings are immutable; later grant revocation affects future commitments only. */
    public static function booking_learners($booking,$cohort_id) {
        $snapshot=json_decode($booking->snapshot,true) ?: array(); $options=$snapshot['booking_options']??array();
        if (!isset($options['academy_learners'])) return (int)$booking->quantity===1 ? array(array('learner_id'=>(int)$booking->user_id,'authorization_id'=>'','eligibility_reference'=>'')) : self::error('binding','Accepted learner bindings are unavailable.','پیوند پذیرفته‌شده فراگیران در دسترس نیست.',409);
        $items=$options['academy_learners']; $seen=array();
        if (($options['academy_cohort_id']??'')!==$cohort_id || !is_array($items) || !array_is_list($items) || count($items)!==(int)$booking->quantity) return self::error('binding','Accepted cohort and learner count do not match.','گروه و تعداد فراگیران پذیرفته‌شده مطابقت ندارد.',409);
        foreach ($items as $item) { if (!is_array($item) || !is_int($item['learner_id']??null) || $item['learner_id']<1 || isset($seen[$item['learner_id']]) || !is_string($item['authorization_id']??null) || ((int)$booking->user_id!==$item['learner_id'] && !preg_match('/^[a-f0-9-]{36}$/',$item['authorization_id']))) return self::error('binding','Invalid accepted learner binding.','پیوند پذیرفته‌شده فراگیر نامعتبر است.',409); $seen[$item['learner_id']]=true; }
        return $items;
    }
    public static function bound_learner($booking,$learner,$cohort_id) { $items=self::booking_learners($booking,$cohort_id); if (is_wp_error($items)) return false; foreach ($items as $item) if ($item['learner_id']===$learner) return true; return false; }
    private static function linked_rows($booking,$cohort_id) {
        global $wpdb; $items=self::booking_learners($booking,$cohort_id); if (is_wp_error($items)) return $items; $expected=array_column($items,'learner_id'); sort($expected,SORT_NUMERIC);
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.DB::table('records')." WHERE type=%s AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.booking_id'))=%s ORDER BY owner_id,id",'enrollment',$booking->id)); $actual=array();
        foreach ($rows as $row) { $f=self::data($row); if ((int)$row->org_id!==0 || (int)$row->owner_id!==(int)($f['learner_id']??0) || ($f['cohort_id']??'')!==$cohort_id || ($f['hold_id']??'')!==$booking->hold_id || !in_array((int)$row->owner_id,$expected,true)) return self::error('binding','Enrollment links need agency reconciliation.','پیوندهای ثبت‌نام نیازمند تطبیق آژانس است.',409); $actual[]=(int)$row->owner_id; }
        sort($actual,SORT_NUMERIC);
        return $actual===$expected ? $rows : self::error('enrollment_required','Link every accepted learner before payment or confirmation.','پیش از پرداخت یا تأیید، همه فراگیران پذیرفته‌شده را پیوند دهید.',409);
    }
    public static function before_payment($valid,$booking_id,$payer,$order_id=0) {
        if ($valid!==true) return $valid; $booking=self::booking($booking_id); if (!$booking) return $valid; $snapshot=json_decode($booking->snapshot,true) ?: array(); if (($snapshot['kind']??'')!=='cohort' || (!self::enabled() && !isset($snapshot['booking_options']['academy_learners']))) return $valid;
        $cohort_id=$snapshot['booking_options']['academy_cohort_id']??self::cohort_for_offer((int)$booking->offer_id,(int)($snapshot['org_id']??0));
        if (!is_string($cohort_id) || (int)$booking->user_id!==$payer) return self::error('binding','Accepted cohort binding unavailable.','پیوند گروه پذیرفته‌شده در دسترس نیست.',409);
        $linked=self::linked_rows($booking,$cohort_id); return is_wp_error($linked)?$linked:true;
    }
    public static function before_confirmation($valid,$booking_id,$booking,$actor) { return $valid===true ? self::before_payment(true,$booking_id,(int)$booking->user_id,0) : $valid; }
    public static function enroll($request) {
        global $wpdb; $payer=get_current_user_id(); $b=self::body($request,array('booking_id','learner_id')); if (is_wp_error($b)) return $b; $cohort_id=(string)$request['id'];
        if (!$payer || !is_string($b['booking_id']??null) || !preg_match('/^[a-f0-9-]{36}$/',$b['booking_id']) || !preg_match('/^[a-f0-9-]{36}$/',$cohort_id)) return self::error('binding','A valid accepted booking is required.','رزرو پذیرفته‌شده معتبر لازم است.');
        $booking=self::booking($b['booking_id']); if (!$booking || (int)$booking->user_id!==$payer) return self::error('not_found','Purchaser reservation not found.','رزرو خریدار یافت نشد.',404);
        $learners=self::booking_learners($booking,$cohort_id); if (is_wp_error($learners)) return $learners;
        if (isset($b['learner_id']) && (!is_int($b['learner_id']) || count($learners)!==1 || $b['learner_id']!==$learners[0]['learner_id'])) return self::error('binding','Enrollment must include the exact accepted learner set.','ثبت‌نام باید دقیقاً شامل مجموعه فراگیران پذیرفته‌شده باشد.');
        $fields=array('cohort_id'=>$cohort_id,'learner_id'=>$learners[0]['learner_id'],'booking_id'=>$booking->id,'hold_id'=>$booking->hold_id); $canonical=$fields;
        if (count($learners)!==1 || $learners[0]['learner_id']!==$payer) $canonical=array('cohort_id'=>$cohort_id,'booking_id'=>$booking->id,'hold_id'=>$booking->hold_id,'learner_ids'=>array_column($learners,'learner_id'));
        ksort($canonical,SORT_STRING); $hash=hash('sha256',wp_json_encode($canonical)); $scope='user:'.$payer; $key='enrollment_'.$booking->id;
        $find=static function() use($wpdb,$scope,$key) { return $wpdb->get_row($wpdb->prepare('SELECT request_hash,response FROM '.DB::table('idempotency').' WHERE scope=%s AND operation=%s AND idem_key=%s',$scope,'record.enrollment',$key)); };
        $result=static function() use($booking,$cohort_id) { $rows=self::linked_rows($booking,$cohort_id); if (is_wp_error($rows)) return $rows; $items=array_map(static function($row) { return array('id'=>$row->id,'type'=>'enrollment','org_id'=>0,'owner_id'=>(int)$row->owner_id,'state'=>$row->state,'fields'=>self::data($row),'version'=>(int)$row->version,'created_at'=>$row->created_at,'updated_at'=>$row->updated_at); },$rows); return count($items)===1?$items[0]:array('booking_id'=>$booking->id,'items'=>$items,'enrolled_count'=>count($items)); };
        $prior=$find(); if ($prior) return hash_equals($prior->request_hash,$hash)?$result():self::error('idempotency','Enrollment request changed.','درخواست ثبت‌نام تغییر کرده است.',409);
        $wpdb->query('START TRANSACTION');
        try {
            $inventory=$wpdb->get_row($wpdb->prepare('SELECT offer_id FROM '.DB::table('inventory').' WHERE offer_id=%d FOR UPDATE',(int)$booking->offer_id));
            $hold=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('holds').' WHERE id=%s FOR UPDATE',$booking->hold_id)); $locked=self::booking($booking->id,true);
            $prior=$find(); if ($prior) { $wpdb->query('ROLLBACK'); return hash_equals($prior->request_hash,$hash)?$result():self::error('idempotency','Enrollment request changed.','درخواست ثبت‌نام تغییر کرده است.',409); }
            $cohort=self::row('cohort',$cohort_id,true); $c=self::data($cohort);
            $paid=$locked && in_array($locked->status,array('paid_pending_confirmation','confirmed'),true); $unpaid=$locked && in_array($locked->status,array('held','pending_payment'),true);
            if (!$inventory || !$hold || !$locked || !$cohort || $cohort->state!=='approved' || (int)$locked->user_id!==$payer || (int)$locked->offer_id!==(int)($c['offer_id']??0) || self::cohort_for_offer((int)$locked->offer_id,(int)$cohort->org_id,true)!==$cohort_id || (!$paid && (!$unpaid || $hold->state!=='active' || DB::now()>=$hold->expires_at)) || ($paid && $hold->state!=='consumed')) { $wpdb->query('ROLLBACK'); return self::error('binding','An allocated accepted hold for this exact cohort is required.','نگهداشت تخصیص‌یافته و پذیرفته‌شده برای همین گروه لازم است.',409); }
            $accepted=self::booking_learners($locked,$cohort_id); if (is_wp_error($accepted) || $accepted!==$learners) { $wpdb->query('ROLLBACK'); return is_wp_error($accepted)?$accepted:self::error('binding','Accepted learner set changed.','مجموعه فراگیران پذیرفته‌شده تغییر کرده است.',409); }
            $existing=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.DB::table('records')." WHERE type=%s AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.booking_id'))=%s LIMIT 1 FOR UPDATE",'enrollment',$booking->id));
            if ($existing) { $wpdb->query('ROLLBACK'); return self::error('binding','Partial enrollment links require agency reconciliation.','پیوندهای ناقص ثبت‌نام نیازمند تطبیق آژانس است.',409); }
            $ids=array(); $now=DB::now();
            foreach ($learners as $item) {
                if (!get_userdata($item['learner_id'])) throw new RuntimeException('learner unavailable'); $id=wp_generate_uuid4(); $fields=array('cohort_id'=>$cohort_id,'learner_id'=>$item['learner_id'],'booking_id'=>$booking->id,'hold_id'=>$booking->hold_id);
                if ($wpdb->insert(DB::table('records'),array('id'=>$id,'type'=>'enrollment','org_id'=>0,'owner_id'=>$item['learner_id'],'state'=>'draft','payload'=>wp_json_encode($fields),'version'=>1,'created_at'=>$now,'updated_at'=>$now))!==1 || !DB::audit($payer,(int)$cohort->org_id,'enrollment.link',$id,'success') || !DB::emit('academy.learner_linked',$id)) throw new RuntimeException('learner link'); $ids[]=$id;
            }
            if ($wpdb->insert(DB::table('idempotency'),array('scope'=>$scope,'operation'=>'record.enrollment','idem_key'=>$key,'request_hash'=>$hash,'response'=>wp_json_encode(array('id'=>$ids[0],'ids'=>$ids)),'created_at'=>$now))!==1) throw new RuntimeException('enrollment idempotency');
            if ($wpdb->query('COMMIT')===false) throw new RuntimeException('enrollment commit'); return $result();
        } catch (Throwable $error) { $wpdb->query('ROLLBACK'); return self::error('storage','Learner links could not be recorded; no partial enrollment was committed.','پیوندهای فراگیران ثبت نشد؛ هیچ ثبت‌نام ناقصی نهایی نشده است.',503); }
    }
    public static function materials($request) {
        global $wpdb; $user=get_current_user_id(); $cohort_id=(string)$request['id'];
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.DB::table('records')." WHERE type=%s AND org_id=0 AND owner_id=%d AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.cohort_id'))=%s ORDER BY id LIMIT 101",'enrollment',$user,$cohort_id)); $authorized=false;
        foreach ($rows as $row) { $e=self::data($row); $booking=self::booking((string)($e['booking_id']??'')); if ($booking && $booking->status==='confirmed' && (int)($e['learner_id']??0)===$user && ($e['hold_id']??'')===$booking->hold_id && self::bound_learner($booking,$user,$cohort_id)) { $authorized=true; break; } }
        if (!$authorized) return self::error('not_found','Materials unavailable.','محتوای آموزشی در دسترس نیست.',404);
        $cohort=self::row('cohort',$cohort_id); $c=self::data($cohort); $course=self::row('course',(string)($c['course_id']??'')); $f=self::data($course);
        if (!$cohort || !$course || !in_array($cohort->state,array('approved','closed'),true) || !in_array($course->state,array('approved','closed'),true) || (int)$course->org_id!==(int)$cohort->org_id || (int)$booking->offer_id!==(int)($c['offer_id']??0)) return self::error('not_found','Materials unavailable.','محتوای آموزشی در دسترس نیست.',404);
        $materials=array(); foreach ($f['materials']??array() as $item) if (is_array($item) && is_string($item['title']??null) && is_string($item['body']??null)) $materials[]=array('title'=>$item['title'],'body'=>$item['body']);
        return new WP_REST_Response(array('course_title_en'=>$f['title_en']??'','course_title_fa'=>$f['title_fa']??'','materials'=>$materials),200,array('Cache-Control'=>'private, no-store','X-Robots-Tag'=>'noindex'));
    }
    /** Global delivery lock serializes cross-cohort room/instructor checks and state changes. */
    private static function transaction($callback) {
        global $wpdb;
        $name='atlas_academy_'.md5(DB_NAME.':'.$wpdb->prefix);
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$name))!=='1') return self::error('busy','Academy records are changing. Please retry.','اطلاعات آموزش در حال تغییر است. دوباره تلاش کنید.',409);
        try {
            if ($wpdb->query('START TRANSACTION')===false) throw new RuntimeException('transaction');
            $result=$callback();
            if (is_wp_error($result)) { $wpdb->query('ROLLBACK'); return $result; }
            if ($wpdb->query('COMMIT')===false) throw new RuntimeException('commit');
            return $result;
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            return self::error('storage','Academy change could not be recorded.','تغییر آموزش ثبت نشد.',503);
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name)); }
    }
    private static function put($type,$id,$org,$owner,$state,$fields,$row,$action,$reason='') {
        global $wpdb;
        $values=array('state'=>$state,'payload'=>wp_json_encode($fields),'version'=>$row ? (int)$row->version+1 : 1,'updated_at'=>DB::now());
        if ($row) $ok=$wpdb->update(DB::table('records'),$values,array('id'=>$id,'version'=>(int)$row->version));
        else $ok=$wpdb->insert(DB::table('records'),array_merge($values,array('id'=>$id,'type'=>$type,'org_id'=>$org,'owner_id'=>$owner,'created_at'=>DB::now())));
        if ($ok!==1 || !DB::audit(get_current_user_id(),$org,'academy.'.$action,$id,'success',$reason) || !DB::emit('academy.'.$action,$id)) throw new RuntimeException('academy persistence');
        return self::row($type,$id);
    }
    private static function plan($cohort,$lock=false) { return self::row('academy_plan',self::uuid('academy-plan:'.$cohort),$lock); }
    /** Root generic cohort editor must call this for changes; delivery changes use the reviewed plan. */
    public static function guard_cohort_changes($cohort_id,$changes) {
        if (self::plan($cohort_id) && array_intersect(array_keys($changes),array('course_id','offer_id','sessions','instructors','venue','timezone'))) return self::error('plan_managed','Edit delivery through the academy schedule workspace.','برنامه را از فضای برنامه‌ریزی آموزش ویرایش کنید.',409);
        return true;
    }
    public static function validate_offer($valid,$offer,$offer_id) {
        global $wpdb;
        if (!$valid) {
            if (!self::enabled() || ($offer['kind']??'')!=='cohort') return $valid;
            $cohort_id=self::cohort_for_offer((int)$offer_id,(int)($offer['org_id']??0)); $cohort=$cohort_id?self::row('cohort',$cohort_id):null; $plan=$cohort?self::plan($cohort_id):null;
            if (!$cohort || !$plan || $plan->state!=='published' || !self::requirements($cohort)['required'] || empty(self::data($plan)['policy']['reference']) || (int)(self::data($plan)['policy']['reviewer']??0)<1) return $valid;
            $valid=true; // Each learner still needs protected prerequisite review in validate_participants().
        }
        $plans=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.DB::table('records').' WHERE type=%s AND org_id=%d','academy_plan',(int)($offer['org_id']??0)));
        foreach ($plans as $row) { $p=self::data($row); if ((int)($p['offer_id']??0)===(int)$offer_id && $row->state!=='published') return false; }
        return $valid; // Existing unscheduled cohorts remain governed by the core fail-closed eligibility path.
    }
    public static function save_plan($request) {
        if (!self::agency('atlas_academy_schedule')) return self::error('forbidden','Schedule reviewer access required.','دسترسی بازبین برنامه لازم است.',403);
        $body=self::body($request,array('version','sessions','syllabus_version','policy_reference','required_sessions','assessment_required','rubric_version','publish','exception_reason','exception_reference','reason'));
        if (is_wp_error($body)) return $body;
        if (!isset($body['version'],$body['sessions'],$body['required_sessions'],$body['assessment_required'],$body['publish']) || !is_int($body['version']) || !is_bool($body['assessment_required']) || !is_bool($body['publish']) || !self::text($body['syllabus_version']??null,100) || !self::text($body['policy_reference']??null,180) || !self::text($body['rubric_version']??null,100) || !self::text($body['reason']??null) || !is_array($body['sessions']) || !array_is_list($body['sessions']) || count($body['sessions'])<1 || count($body['sessions'])>100 || !is_array($body['required_sessions']) || !array_is_list($body['required_sessions']) || !$body['required_sessions']) return self::error('policy','A reviewed syllabus, completion policy and sessions are required.','نسخه سرفصل، سیاست تکمیل بازبینی‌شده و جلسات لازم است.');
        $cohort_id=(string)$request['id'];
        return self::transaction(static function() use($body,$cohort_id) {
            global $wpdb;
            $cohort=self::row('cohort',$cohort_id,true); $c=self::data($cohort); $course=self::row('course',(string)($c['course_id']??''),true); $course_data=self::data($course);
            if (!$cohort || !$course || $cohort->state!=='approved' || $course->state!=='approved' || (int)$cohort->org_id<=0 || (int)$course->org_id!==(int)$cohort->org_id) return self::error('cohort','An approved course and cohort in the same organization are required.','دوره و گروه تأییدشده در یک سازمان لازم است.',409);
            $old=self::plan($cohort_id,true);
            if (($old ? (int)$old->version : 0)!==$body['version']) return self::error('version','Schedule changed. Reload it.','برنامه تغییر کرده است. دوباره بارگیری کنید.',409);
            if ($old && ($wpdb->get_var($wpdb->prepare('SELECT id FROM '.DB::table('bookings').' WHERE offer_id=%d LIMIT 1',(int)$c['offer_id'])) || $wpdb->get_var($wpdb->prepare('SELECT id FROM '.DB::table('records')." WHERE type=%s AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.cohort_id'))=%s LIMIT 1",'enrollment',$cohort_id)))) return self::error('frozen','A cohort with accepted reservations retains its agreed delivery snapshot. Create a new cohort for changed delivery.','گروه دارای رزرو پذیرفته‌شده، برنامه توافق‌شده را حفظ می‌کند. برای برنامه جدید گروه دیگری بسازید.',409);
            $sessions=array(); $ids=array();
            foreach ($body['sessions'] as $s) {
                if (!is_array($s) || array_diff(array_keys($s),array('id','start','end','timezone','room','instructors')) || !is_string($s['id']??null) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/',$s['id']) || isset($ids[$s['id']]) || !self::instant($s['start']??null) || !self::instant($s['end']??null) || $s['start']>=$s['end'] || !is_string($s['timezone']??null) || !in_array($s['timezone'],timezone_identifiers_list(),true) || !self::text($s['room']??null,180) || !is_array($s['instructors']??null) || !array_is_list($s['instructors']) || !$s['instructors'] || count($s['instructors'])>20) return self::error('session','Each session needs a unique reference, ordered UTC times, named zone, room and instructors.','هر جلسه به شناسه یکتا، زمان مرتب UTC، منطقه زمانی، اتاق و مدرس نیاز دارد.');
                $s['instructors']=array_values(array_unique($s['instructors'],SORT_REGULAR));
                foreach ($s['instructors'] as $user) if (!is_int($user) || $user<1 || !Auth::member((int)$cohort->org_id,$user) || !user_can($user,'atlas_academy_instruct')) return self::error('instructor','Instructors must be authorized active members of this organization.','مدرسان باید اعضای فعال و مجاز همین سازمان باشند.');
                foreach ($sessions as $prior) if ($s['start']<$prior['end'] && $s['end']>$prior['start']) return self::error('session_overlap','Sessions within a cohort cannot overlap.','جلسات یک گروه نباید هم‌پوشانی داشته باشند.',409);
                sort($s['instructors'],SORT_NUMERIC); $ids[$s['id']]=true; $sessions[]=$s;
            }
            foreach ($body['required_sessions'] as $id) if (!is_string($id) || !isset($ids[$id])) return self::error('required_session','Required attendance must refer to scheduled sessions.','حضور الزامی باید به جلسات برنامه اشاره کند.');
            $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.DB::table('records').' WHERE type=%s AND state IN (%s,%s) ORDER BY id LIMIT 1001 FOR UPDATE','academy_plan','draft','published'));
            if (count($rows)>1000) return self::error('review_limit','Schedule review limit reached. Ask the agency to review.','ظرفیت بررسی برنامه پر شده است. با آژانس تماس بگیرید.',409);
            $conflicts=array();
            foreach ($rows as $other) {
                if ($old && $other->id===$old->id) continue;
                foreach (self::data($other)['sessions']??array() as $o) foreach ($sessions as $s) if ($s['start']<$o['end'] && $s['end']>$o['start'] && ($s['room']===$o['room'] || array_intersect($s['instructors'],$o['instructors']))) $conflicts[]=array('plan_id'=>$other->id,'session'=>$s['id'],'other_session'=>$o['id']);
            }
            if ($conflicts && (!self::text($body['exception_reason']??null) || !self::text($body['exception_reference']??null,180))) return self::error('schedule_conflict','Instructor or room already has an overlapping session. Reassign or record a reviewed exception.','مدرس یا اتاق جلسه هم‌زمان دارد. تخصیص را تغییر دهید یا استثنای بازبینی‌شده ثبت کنید.',409);
            $snapshot=$old ? self::data($old)['course_snapshot'] : array('course_id'=>$course->id,'record_version'=>(int)$course->version,'syllabus_version'=>$body['syllabus_version'],'title_en'=>$course_data['title_en']??'','title_fa'=>$course_data['title_fa']??'','syllabus'=>$course_data['syllabus']??'','prerequisites'=>$course_data['prerequisites']??'');
            if ($old && $snapshot['syllabus_version']!==$body['syllabus_version']) return self::error('snapshot','The agreed syllabus snapshot cannot be replaced. Create a new cohort.','سرفصل توافق‌شده قابل جایگزینی نیست. گروه جدید بسازید.',409);
            $fields=array('cohort_id'=>$cohort_id,'org_id'=>(int)$cohort->org_id,'offer_id'=>(int)$c['offer_id'],'course_snapshot'=>$snapshot,'cohort_prerequisites'=>$old?(self::data($old)['cohort_prerequisites']??($c['prerequisites']??'')):($c['prerequisites']??''),'sessions'=>$sessions,'policy'=>array('reference'=>$body['policy_reference'],'required_sessions'=>array_values(array_unique($body['required_sessions'])),'assessment_required'=>$body['assessment_required'],'rubric_version'=>$body['rubric_version'],'reviewer'=>get_current_user_id(),'reviewed_at'=>DB::now()),'exception'=>$conflicts ? array('conflicts'=>$conflicts,'reason'=>$body['exception_reason'],'reference'=>$body['exception_reference'],'reviewer'=>get_current_user_id(),'reviewed_at'=>DB::now()) : null,'reason'=>$body['reason']);
            $state=$body['publish']?'published':'draft';
            return self::project(self::put('academy_plan',self::uuid('academy-plan:'.$cohort_id),(int)$cohort->org_id,(int)$cohort->owner_id,$state,$fields,$old,$state==='published'?'schedule_published':'schedule_saved',$body['reason']));
        });
    }
    public static function workspace($request) {
        $row=self::plan((string)$request['id']); $plan=self::data($row);
        if (!$row || !self::staff($plan)) {
            $cohort=self::row('cohort',(string)$request['id']);
            if (!$row && $cohort && self::agency('atlas_academy_schedule')) $plan=array('cohort_id'=>$cohort->id,'org_id'=>(int)$cohort->org_id);
            else return self::error('not_found','Academy workspace not found.','فضای آموزش یافت نشد.',404);
        }
        $instructors=array();
        if (self::agency('atlas_academy_schedule')) {
            global $wpdb;
            $users=$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.DB::table('memberships').' WHERE org_id=%d AND status=%s LIMIT 501',(int)$plan['org_id'],'active'));
            foreach ($users as $id) if (Auth::member((int)$plan['org_id'],(int)$id) && user_can((int)$id,'atlas_academy_instruct')) $instructors[]=array('id'=>(int)$id,'name'=>get_userdata((int)$id)->display_name);
        }
        return array('plan'=>$row ? self::project($row) : null,'instructors'=>$instructors);
    }
    private static function context($enrollment_id,$lock=false,$require_confirmed=true) {
        $enrollment=self::row('enrollment',$enrollment_id,$lock); $e=self::data($enrollment);
        $plan=self::plan((string)($e['cohort_id']??''),$lock); $p=self::data($plan);
        $booking=self::booking((string)($e['booking_id']??''),$lock);
        if (!$enrollment || (int)$enrollment->org_id!==0 || (int)$enrollment->owner_id!==(int)($e['learner_id']??0) || !$plan || $plan->state!=='published' || !$booking || (($require_confirmed || !self::progress_row($enrollment_id)) && $booking->status!=='confirmed') || (int)$booking->offer_id!==(int)($p['offer_id']??0) || !self::bound_learner($booking,(int)($e['learner_id']??0),(string)($e['cohort_id']??'')) || (string)$booking->hold_id!==(string)($e['hold_id']??'')) return self::error('enrollment','A confirmed, exactly bound learner enrollment is required.','ثبت‌نام تأییدشده با پیوند دقیق فراگیر لازم است.',409);
        return array($enrollment,$e,$plan,$p,$booking);
    }
    private static function booking($id,$lock=false) { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('bookings').' WHERE id=%s'.($lock?' FOR UPDATE':''),$id)); }
    private static function progress_row($enrollment,$lock=false) { return self::row('academy_progress',self::uuid('academy-progress:'.$enrollment),$lock); }
    private static function initial($enrollment,$e,$plan) { return array('enrollment_id'=>$enrollment->id,'cohort_id'=>$e['cohort_id'],'learner_id'=>(int)$e['learner_id'],'booking_id'=>$e['booking_id'],'plan_id'=>$plan->id,'plan_version'=>(int)$plan->version,'attendance'=>array(),'assessments'=>array(),'appeals'=>array(),'history'=>array(),'disclosure_consent'=>false,'certificate_ids'=>array()); }
    private static function readable($e,$p) { return (int)$e['learner_id']===get_current_user_id() || self::staff($p); }
    private static function view_progress($enrollment,$e,$plan) {
        $row=self::progress_row($enrollment->id); $fields=$row ? self::data($row) : self::initial($enrollment,$e,$plan); $certificates=array();
        foreach ($fields['certificate_ids'] as $id) { $certificate=self::row('academy_certificate',$id); if ($certificate) { $certificate=self::project($certificate); unset($certificate['fields']['reference_hash']); $certificates[]=$certificate; } }
        if ((int)$e['learner_id']!==get_current_user_id() && !self::agency('atlas_academy_review') && !self::agency('atlas_academy_issue')) {
            $attendance=array();
            foreach (self::data($plan)['sessions'] as $session) if (self::instructor(self::data($plan),$session['id']) && isset($fields['attendance'][$session['id']])) $attendance[$session['id']]=$fields['attendance'][$session['id']];
            $fields=array('enrollment_id'=>$enrollment->id,'cohort_id'=>$e['cohort_id'],'learner_id'=>(int)$e['learner_id'],'attendance'=>$attendance);
            $certificates=array();
        }
        return array('id'=>$row ? $row->id : self::uuid('academy-progress:'.$enrollment->id),'version'=>$row ? (int)$row->version : 0,'state'=>$row ? $row->state : 'in_progress','fields'=>$fields,'plan'=>self::project($plan),'certificates'=>$certificates);
    }
    public static function progress($request) {
        $context=self::context((string)$request['id'],false,false); if (is_wp_error($context)) return self::error('not_found','Learner record not found.','پرونده فراگیر یافت نشد.',404);
        list($enrollment,$e,$plan,$p)=$context;
        if (!self::readable($e,$p)) return self::error('not_found','Learner record not found.','پرونده فراگیر یافت نشد.',404);
        return self::view_progress($enrollment,$e,$plan);
    }
    public static function roster($request) {
        global $wpdb;
        $plan=self::plan((string)$request['id']); $p=self::data($plan);
        if (!$plan || !self::staff($p)) return self::error('not_found','Roster not found.','فهرست فراگیران یافت نشد.',404);
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.DB::table('records')." WHERE type=%s AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.cohort_id'))=%s ORDER BY id LIMIT 501",'enrollment',(string)$request['id']));
        if (count($rows)>500) return self::error('roster_limit','Ask the agency to review this roster.','برای بررسی فهرست با آژانس تماس بگیرید.',409);
        $items=array();
        foreach ($rows as $row) { $context=self::context($row->id); if (is_wp_error($context)) continue; $e=self::data($row); $user=get_userdata((int)$e['learner_id']); $items[]=array('enrollment_id'=>$row->id,'learner_id'=>(int)$e['learner_id'],'name'=>$user ? $user->display_name : self::t('Learner','فراگیر')); }
        return array('items'=>$items);
    }
    private static function current_certificate($fields,$lock=false) { $ids=$fields['certificate_ids']; return $ids ? self::row('academy_certificate',end($ids),$lock) : null; }
    public static function mutate($request,$action) {
        $allowed=array('version','reason');
        $extra=array('attendance'=>array('session','attended'),'assessment'=>array('rubric_version','passed'),'consent'=>array('disclosure_consent'),'appeal'=>array('target'),'appeal-review'=>array('appeal_id','decision'),'issue'=>array());
        $body=self::body($request,array_merge($allowed,$extra[$action])); if (is_wp_error($body)) return $body;
        if (!isset($body['version']) || !is_int($body['version']) || !self::text($body['reason']??null)) return self::error('reason','Current version and a reason are required.','نسخه جاری و دلیل لازم است.');
        $enrollment_id=(string)$request['id'];
        return self::transaction(static function() use($body,$enrollment_id,$action) {
            $context=self::context($enrollment_id,true,!in_array($action,array('consent','appeal','appeal-review'),true)); if (is_wp_error($context)) return $context;
            list($enrollment,$e,$plan,$p)=$context; $learner=(int)$e['learner_id']; $actor=get_current_user_id();
            $own=$actor===$learner;
            if (($action==='attendance' && !self::agency('atlas_academy_review') && !self::instructor($p,$body['session']??'','write')) || (in_array($action,array('assessment','appeal-review'),true) && (!self::agency('atlas_academy_review') || $own)) || ($action==='issue' && (!self::agency('atlas_academy_issue') || $own)) || (in_array($action,array('consent','appeal'),true) && !$own)) return self::error('forbidden','This academy action is not permitted.','این اقدام آموزشی مجاز نیست.',403);
            $row=self::progress_row($enrollment_id,true); $f=$row ? self::data($row) : self::initial($enrollment,$e,$plan);
            if (($row ? (int)$row->version : 0)!==$body['version']) return self::error('version','Learner record changed. Reload it.','پرونده فراگیر تغییر کرده است. دوباره بارگیری کنید.',409);
            if ((int)$f['plan_version']!==(int)$plan->version || count($f['history'])>=500) return self::error('review_limit','Agency review is required before further changes.','پیش از تغییر بیشتر بررسی آژانس لازم است.',409);
            $certificate=self::current_certificate($f,true);
            if (in_array($action,array('attendance','assessment'),true) && $certificate && $certificate->state==='issued') return self::error('issued','Revoke the issued record before changing completion evidence.','پیش از تغییر شواهد تکمیل، گواهی صادرشده را لغو کنید.',409);
            $before=null; $after=null;
            if ($action==='attendance') {
                $session=null; foreach ($p['sessions'] as $s) if ($s['id']===($body['session']??null)) $session=$s;
                if (!$session || !isset($body['attended']) || !is_bool($body['attended']) || strtotime($session['end'])>strtotime(DB::now())) return self::error('attendance','Attendance requires a finished assigned session and a present/absent decision.','حضور نیازمند جلسه تخصیص‌یافته پایان‌یافته و تصمیم حضور یا غیبت است.');
                $before=$f['attendance'][$session['id']]??null;
                $after=array('attended'=>$body['attended'],'actor'=>$actor,'at'=>DB::now(),'reason'=>$body['reason']); $f['attendance'][$session['id']]=$after;
            } elseif ($action==='assessment') {
                $end=max(array_column($p['sessions'],'end'));
                if (!isset($body['passed']) || !is_bool($body['passed']) || ($body['rubric_version']??null)!==$p['policy']['rubric_version'] || strtotime($end)>strtotime(DB::now())) return self::error('assessment','Use the reviewed rubric after the final session.','پس از آخرین جلسه از معیار بازبینی‌شده استفاده کنید.');
                foreach ($f['appeals'] as $appeal) if ($appeal['status']==='open') return self::error('appeal_open','Resolve the open appeal before another assessment.','پیش از ارزیابی مجدد اعتراض باز را بررسی کنید.',409);
                $latest=$f['assessments'] ? end($f['assessments']) : null; $retake=false;
                foreach ($f['appeals'] as $appeal) if ($appeal['target']==='assessment' && ($appeal['assessment_attempt']??0)===($latest['attempt']??0) && ($appeal['decision']??'')==='retake') $retake=true;
                if ($latest && !$latest['passed'] && !$retake) return self::error('retake','A reviewed retake appeal is required before another attempt.','پیش از نوبت بعدی، اعتراض بازبینی‌شده با اجازه تکرار لازم است.',409);
                $after=array('attempt'=>count($f['assessments'])+1,'rubric_version'=>$body['rubric_version'],'passed'=>$body['passed'],'reviewer'=>$actor,'at'=>DB::now(),'reason'=>$body['reason']); $f['assessments'][]=$after;
            } elseif ($action==='consent') {
                if (!isset($body['disclosure_consent']) || !is_bool($body['disclosure_consent'])) return self::error('consent','Choose whether public course/date disclosure is allowed.','اجازه نمایش عمومی دوره و تاریخ را مشخص کنید.');
                $before=$f['disclosure_consent']; $after=$body['disclosure_consent']; $f['disclosure_consent']=$after;
            } elseif ($action==='appeal') {
                $latest=$f['assessments'] ? end($f['assessments']) : null;
                if (!in_array($body['target']??null,array('assessment','revocation'),true) || ($body['target']==='assessment' && (!$latest || $latest['passed'])) || ($body['target']==='revocation' && (!$certificate || $certificate->state!=='revoked'))) return self::error('appeal','Only a failed assessment or revoked record can be appealed.','فقط ارزیابی مردود یا گواهی لغوشده قابل اعتراض است.',409);
                foreach ($f['appeals'] as $appeal) if ($appeal['status']==='open') return self::error('appeal_open','An appeal is already open.','اعتراض باز وجود دارد.',409);
                $after=array('id'=>wp_generate_uuid4(),'target'=>$body['target'],'assessment_attempt'=>$latest['attempt']??0,'certificate_id'=>$certificate ? $certificate->id : null,'original_reviewer'=>$body['target']==='assessment' ? $latest['reviewer'] : (self::data($certificate)['revoked_by']??0),'status'=>'open','actor'=>$actor,'at'=>DB::now(),'reason'=>$body['reason']); $f['appeals'][]=$after;
            } elseif ($action==='appeal-review') {
                $found=false;
                foreach ($f['appeals'] as &$appeal) if ($appeal['id']===($body['appeal_id']??null) && $appeal['status']==='open') {
                    if ((int)$appeal['original_reviewer']===$actor) return self::error('independent_review','A different reviewer must decide the appeal.','بازبین دیگری باید درباره اعتراض تصمیم بگیرد.',403);
                    $decisions=$appeal['target']==='assessment' ? array('upheld','retake') : array('upheld','reissue_allowed');
                    if (!in_array($body['decision']??null,$decisions,true)) return self::error('decision','Select an applicable appeal decision.','تصمیم مناسب اعتراض را انتخاب کنید.');
                    $before=$appeal; $appeal['status']='resolved'; $appeal['decision']=$body['decision']; $appeal['reviewer']=$actor; $appeal['reviewed_at']=DB::now(); $appeal['review_reason']=$body['reason']; $after=$appeal; $found=true;
                }
                unset($appeal);
                if (!$found) return self::error('appeal','Open appeal not found.','اعتراض باز یافت نشد.',409);
            } elseif ($action==='issue') {
                if ($certificate && $certificate->state==='issued') return self::view_progress($enrollment,$e,$plan);
                foreach ($p['policy']['required_sessions'] as $s) if (empty($f['attendance'][$s]['attended'])) return self::error('completion','Required attendance is incomplete.','حضور الزامی کامل نیست.',409);
                $latest=$f['assessments'] ? end($f['assessments']) : null;
                if ($p['policy']['assessment_required'] && (!$latest || !$latest['passed'] || $latest['rubric_version']!==$p['policy']['rubric_version'])) return self::error('completion','A passing reviewed assessment is required.','ارزیابی بازبینی‌شده با نتیجه قبولی لازم است.',409);
                $reissue=false;
                foreach ($f['appeals'] as $appeal) { if ($appeal['status']==='open') return self::error('appeal_open','Resolve the open appeal before issuing.','پیش از صدور، اعتراض باز را بررسی کنید.',409); if ($certificate && ($appeal['certificate_id']??'')===$certificate->id && ($appeal['decision']??'')==='reissue_allowed') $reissue=true; }
                if ($certificate && $certificate->state==='revoked' && !$reissue) return self::error('revoked','A reviewed reissue appeal is required; revoked references remain revoked.','اعتراض بازبینی‌شده برای صدور مجدد لازم است؛ شناسه قبلی لغوشده می‌ماند.',409);
                $reference=bin2hex(random_bytes(32)); $id=self::uuid('academy-certificate:'.$reference);
                $after=array('enrollment_id'=>$enrollment_id,'progress_id'=>self::uuid('academy-progress:'.$enrollment_id),'cohort_id'=>$e['cohort_id'],'learner_id'=>$learner,'reference'=>$reference,'reference_hash'=>hash('sha256',$reference),'issued_by'=>$actor,'issued_at'=>DB::now(),'reason'=>$body['reason'],'course_snapshot'=>$p['course_snapshot'],'policy_reference'=>$p['policy']['reference'],'assessment_attempt'=>$latest['attempt']??null,'evidence_version'=>$body['version'],'accreditation_claim'=>false);
                self::put('academy_certificate',$id,(int)$p['org_id'],$learner,'issued',$after,null,'certificate_issued',$body['reason']); $f['certificate_ids'][]=$id;
            }
            $f['history'][]=array('action'=>$action,'actor'=>$actor,'at'=>DB::now(),'reason'=>$body['reason'],'before'=>$before,'after'=>$after);
            $state=$action==='issue'?'complete':($row ? $row->state : 'in_progress');
            self::put('academy_progress',self::uuid('academy-progress:'.$enrollment_id),(int)$p['org_id'],$learner,$state,$f,$row,str_replace('-','_',$action),$body['reason']);
            return self::view_progress($enrollment,$e,$plan);
        });
    }
    public static function revoke($request) {
        if (!self::agency('atlas_academy_issue')) return self::error('forbidden','Authorized issuer access required.','دسترسی صادرکننده مجاز لازم است.',403);
        $body=self::body($request,array('version','reason')); if (is_wp_error($body)) return $body;
        if (!is_int($body['version']??null) || !self::text($body['reason']??null)) return self::error('reason','Current version and revocation reason are required.','نسخه جاری و دلیل لغو لازم است.');
        $id=(string)$request['id'];
        return self::transaction(static function() use($body,$id) {
            $row=self::row('academy_certificate',$id,true); $f=self::data($row);
            if (!$row || (int)($f['learner_id']??0)===get_current_user_id()) return self::error('not_found','Issued record not found.','گواهی صادرشده یافت نشد.',404);
            if ((int)$row->version!==$body['version'] || $row->state!=='issued') return self::error('version','Issued record changed. Reload it.','گواهی تغییر کرده است. دوباره بارگیری کنید.',409);
            $f['revoked_by']=get_current_user_id(); $f['revoked_at']=DB::now(); $f['revocation_reason']=$body['reason'];
            $row=self::put('academy_certificate',$id,(int)$row->org_id,(int)$row->owner_id,'revoked',$f,$row,'certificate_revoked',$body['reason']);
            $progress=self::row('academy_progress',$f['progress_id'],true); $p=self::data($progress);
            if (!$progress) throw new RuntimeException('missing progress');
            $p['history'][]=array('action'=>'revoke','actor'=>get_current_user_id(),'at'=>DB::now(),'reason'=>$body['reason'],'certificate_id'=>$id);
            self::put('academy_progress',$progress->id,(int)$progress->org_id,(int)$progress->owner_id,'in_progress',$p,$progress,'revocation_recorded',$body['reason']);
            $result=self::project($row); unset($result['fields']['reference_hash']); return $result;
        });
    }
    public static function verify($request) {
        if (!self::enabled()) return self::error('not_found','Verification record not found.','گواهی برای بررسی یافت نشد.',404);
        global $wpdb;
        $bucket='atlas_academy_verify_'.hash_hmac('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown'),wp_salt('nonce'));
        $lock='atlas_acv_'.substr($bucket,-40);
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1') return self::error('rate','Please retry later.','بعداً دوباره تلاش کنید.',429);
        try { $count=(int)get_transient($bucket); if ($count>=30) return self::error('rate','Please retry later.','بعداً دوباره تلاش کنید.',429); if (!set_transient($bucket,$count+1,MINUTE_IN_SECONDS)) return self::error('rate_storage','Verification is temporarily unavailable.','بررسی موقتاً در دسترس نیست.',503); } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
        $reference=(string)$request['reference']; $row=self::row('academy_certificate',self::uuid('academy-certificate:'.$reference)); $f=self::data($row);
        if (!$row || !isset($f['reference_hash']) || !hash_equals($f['reference_hash'],hash('sha256',$reference))) return self::error('not_found','Verification record not found.','گواهی برای بررسی یافت نشد.',404);
        $result=array('status'=>$row->state==='issued'?'valid':'revoked');
        $progress=self::row('academy_progress',$f['progress_id']); $p=self::data($progress);
        if ($row->state==='issued' && !empty($p['disclosure_consent'])) { $result['issue_date']=substr($f['issued_at'],0,10); $result['course']=self::t($f['course_snapshot']['title_en'],$f['course_snapshot']['title_fa']?:$f['course_snapshot']['title_en']); }
        return new WP_REST_Response($result,200,array('Cache-Control'=>'no-store','X-Robots-Tag'=>'noindex'));
    }
    public static function learner_fields($html,$offer) {
        if (($offer['kind']??'')!=='cohort' || !self::enabled() || !is_user_logged_in()) return $html;
        $cohort=self::cohort_for_offer((int)$offer['id'],(int)$offer['org_id']); if (!$cohort) return $html;
        $user=get_current_user_id(); $html.='<fieldset data-academy-learners><legend>'.esc_html(self::t('Learners for this cohort','فراگیران این گروه')).'</legend><p>'.esc_html(self::t('Each learner authorizes this payer for this cohort. Paying does not grant access to another learner’s assessments.','هر فراگیر به این پرداخت‌کننده برای همین گروه اجازه می‌دهد. پرداخت، اجازه مشاهده ارزیابی فراگیر دیگر را نمی‌دهد.')).'</p>';
        $html.='<label><input type="checkbox" data-academy-learner data-learner-id="'.esc_attr($user).'" data-authorization="" data-authorization-version="0" data-eligibility="" checked>'.esc_html(self::t('Include me as a learner','خودم نیز فراگیر هستم')).'</label>';
        $travelers=Records::query('traveler',array('limit'=>100),$user);
        $html.='<label>'.esc_html(self::t('My private eligibility profile, if this cohort requires one','پرونده خصوصی صلاحیت خودم، در صورت نیاز گروه')).'<select data-academy-self-evidence><option value="">—</option>';
        if (!is_wp_error($travelers)) foreach ($travelers as $person) $html.='<option value="'.esc_attr($person['id']).'">'.esc_html($person['fields']['name']??'').'</option>';
        $html.='</select></label>';
        foreach (self::addressed($cohort,$user) as $grant) $html.='<label><input type="checkbox" data-academy-learner data-learner-id="'.esc_attr($grant['learner_id']).'" data-authorization="'.esc_attr($grant['authorization_id']).'" data-authorization-version="'.esc_attr($grant['authorization_version']).'" data-eligibility="'.esc_attr($grant['eligibility_reference']).'">'.esc_html($grant['learner_name'].' · '.self::t('Authorization expires ','انقضای اجازه ').$grant['expires_at']).'</label>';
        return $html.'<p>'.esc_html(self::t('Learners can grant or revoke payer permission in their Academy account. Missing policy evidence or prerequisite review blocks commitment.','فراگیران می‌توانند اجازه پرداخت‌کننده را در حساب آموزش بدهند یا لغو کنند. نبود شواهد سیاست یا بررسی پیش‌نیاز مانع پذیرش رزرو است.')).'</p></fieldset>';
    }
    private static function authorization_panel() {
        global $wpdb; $user=get_current_user_id();
        $html='<details><summary>'.esc_html(self::t('Authorize a payer for my learning','اجازه به پرداخت‌کننده برای آموزش من')).'</summary><p>'.esc_html(self::t('My payer/learner account reference: ','شناسه حساب پرداخت‌کننده / فراگیر من: ').$user).'</p><p>'.esc_html(self::t('Permission is limited to one cohort and expires within 90 days. Revocation blocks future commitments; already accepted bookings keep their agreed learner binding. No birth date is disclosed to the payer.','اجازه به یک گروه و حداکثر ۹۰ روز محدود است. لغو، رزروهای آینده را متوقف می‌کند؛ رزرو پذیرفته‌شده پیوند فراگیر توافق‌شده را حفظ می‌کند. تاریخ تولد به پرداخت‌کننده نمایش داده نمی‌شود.')).'</p><form data-academy-authorize><label>'.esc_html(self::t('Course and cohort','دوره و گروه')).'<select name="cohort_id" required><option value="">—</option>';
        $offers=$wpdb->get_results($wpdb->prepare('SELECT offer_id,offer_snapshot FROM '.DB::table('inventory').' WHERE kind=%s AND status=%s ORDER BY offer_id DESC LIMIT 101','cohort','published'));
        foreach ($offers as $row) { $offer=json_decode($row->offer_snapshot,true); $cohort=is_array($offer)?self::cohort_for_offer((int)$row->offer_id,(int)$offer['org_id']):null; if (!$cohort) continue; $c=self::data(self::row('cohort',$cohort)); $html.='<option value="'.esc_attr($cohort).'">'.esc_html(self::t($offer['title_en'],$offer['title_fa']?:$offer['title_en']).' · '.($c['venue']??'').' · '.$offer['timezone']).'</option>'; }
        $html.='</select></label><label>'.esc_html(self::t('Identified payer account reference','شناسه حساب پرداخت‌کننده مشخص')).'<input name="payer_id" type="number" min="1" required></label><label>'.esc_html(self::t('My own eligibility profile','پرونده صلاحیت متعلق به خودم')).'<select name="eligibility_reference"><option value="">—</option>';
        $people=Records::query('traveler',array('limit'=>100),$user); if (!is_wp_error($people)) foreach ($people as $person) $html.='<option value="'.esc_attr($person['id']).'">'.esc_html($person['fields']['name']??'').'</option>';
        $html.='</select></label><label>'.esc_html(self::t('Authorization expiry (UTC)','انقضای اجازه (UTC)')).'<input name="expires_at" type="datetime-local" step="1" required></label><label><input name="learner_attested" type="checkbox" required>'.esc_html(self::t('I am this learner; this profile represents me, and I authorize this payer to use it only for eligibility and enrollment in this cohort. This is my declaration, not verified identity.','من این فراگیر هستم؛ پرونده متعلق به من است و فقط برای صلاحیت و ثبت‌نام همین گروه به این پرداخت‌کننده اجازه استفاده می‌دهم. این اظهار من است و احراز هویت تأییدشده نیست.')).'</label><label>'.esc_html(self::t('Consent reason','دلیل رضایت')).'<input name="reason" maxlength="1000" required></label><button>'.esc_html(self::t('Grant scoped payer permission','اعطای اجازه محدود پرداخت‌کننده')).'</button></form><button type="button" data-academy-authorizations-refresh>'.esc_html(self::t('Refresh my authorizations','دریافت اجازه‌های من')).'</button><div data-academy-authorizations></div></details>';
        return $html;
    }
    public static function panel() {
        if (!self::enabled()) return '';
        wp_enqueue_script('atlas-academy',plugins_url('assets/academy.js',__FILE__),array(),'0.1.0',true);
        $labels=array('load'=>self::t('Load','دریافت'),'save'=>self::t('Save','ذخیره'),'loading'=>self::t('Loading…','در حال دریافت…'),'error'=>self::t('The request could not be completed.','درخواست انجام نشد.'),'saved'=>self::t('Recorded.','ثبت شد.'),'choose'=>self::t('Choose a learner','فراگیر را انتخاب کنید'),'session'=>self::t('Session','جلسه'),'start'=>self::t('Start (UTC)','شروع (UTC)'),'end'=>self::t('End (UTC)','پایان (UTC)'),'zone'=>self::t('IANA time zone','منطقه زمانی IANA'),'room'=>self::t('Room reference','شناسه اتاق'),'instructors'=>self::t('Assigned instructors','مدرسان مسئول'),'required'=>self::t('Required attendance','حضور الزامی'),'remove'=>self::t('Remove session','حذف جلسه'),'present'=>self::t('Present','حاضر'),'absent'=>self::t('Absent','غایب'),'attendance'=>self::t('Record attendance','ثبت حضور'),'reason'=>self::t('Reason / correction explanation','دلیل / توضیح اصلاح'),'assessment'=>self::t('Record assessment','ثبت ارزیابی'),'pass'=>self::t('Pass','قبول'),'fail'=>self::t('Not passed','مردود'),'rubric'=>self::t('Reviewed rubric version','نسخه معیار بازبینی‌شده'),'issue'=>self::t('Issue completion record','صدور گواهی تکمیل'),'revoke'=>self::t('Revoke record','لغو گواهی'),'consent'=>self::t('Allow public verification to disclose course title and issue date','اجازه نمایش نام دوره و تاریخ صدور در بررسی عمومی'),'appeal'=>self::t('Submit appeal','ثبت اعتراض'),'assessmentTarget'=>self::t('Failed assessment','ارزیابی مردود'),'revocationTarget'=>self::t('Revoked record','گواهی لغوشده'),'review'=>self::t('Review appeal','بررسی اعتراض'),'upheld'=>self::t('Original decision upheld','تأیید تصمیم قبلی'),'retake'=>self::t('Allow new assessment attempt','اجازه ارزیابی مجدد'),'reissue'=>self::t('Allow a separate new record','اجازه صدور گواهی جدید'),'valid'=>self::t('Valid completion record','گواهی تکمیل معتبر'),'revoked'=>self::t('Revoked completion record','گواهی تکمیل لغوشده'),'empty'=>self::t('No confirmed learners yet.','هنوز فراگیر تأییدشده‌ای وجود ندارد.'),'complete'=>self::t('Completion record issued','گواهی تکمیل صادر شد'),'in_progress'=>self::t('In progress','در حال تکمیل'),'reference'=>self::t('Verification reference','شناسه بررسی'),'status'=>self::t('Status','وضعیت'));
        wp_localize_script('atlas-academy','atlasAcademy',array('base'=>esc_url_raw(rest_url('agency/v1/academy/')),'nonce'=>is_user_logged_in()?wp_create_nonce('wp_rest'):'','lang'=>self::t('en','fa'),'labels'=>$labels,'schedule'=>self::agency('atlas_academy_schedule'),'review'=>self::agency('atlas_academy_review'),'issue'=>self::agency('atlas_academy_issue'),'user'=>get_current_user_id()));
        $html='<section data-atlas-academy><h2>'.esc_html(self::t('Academy learning and completion','آموزش و تکمیل دوره')).'</h2><p>'.esc_html(self::t('Agency completion records do not imply accreditation.','گواهی تکمیل آژانس به معنی اعتباربخشی رسمی نیست.')).'</p><p role="status" aria-live="polite" data-academy-status></p>';
        $html.='<form data-academy-verify><label>'.esc_html(self::t('Verify an opaque completion reference','بررسی شناسه گواهی تکمیل')).'<input name="reference" pattern="[a-f0-9]{64}" minlength="64" maxlength="64" required autocomplete="off"></label><button>'.esc_html(self::t('Verify','بررسی')).'</button></form><div data-academy-verification></div>';
        if (!is_user_logged_in()) return $html.'<p>'.esc_html(self::t('Sign in to view your learning record.','برای مشاهده پرونده آموزشی وارد شوید.')).'</p></section>';
        $cohorts=Records::query('cohort',array('limit'=>100),get_current_user_id());
        $html.='<label>'.esc_html(self::t('Course cohort','گروه آموزشی')).'<select data-academy-cohort><option value="">—</option>';
        $options=array();
        if (!is_wp_error($cohorts)) foreach ($cohorts as $cohort) { $plan=self::plan($cohort['id']); if (!self::agency('atlas_academy_schedule') && (!$plan || !self::staff(self::data($plan)))) continue; $options[$cohort['id']]=($cohort['fields']['venue']??'').' · '.$cohort['id']; }
        global $wpdb;
        $assigned=$wpdb->get_results($wpdb->prepare('SELECT r.* FROM '.DB::table('records').' r INNER JOIN '.DB::table('memberships').' m ON m.org_id=r.org_id WHERE r.type=%s AND m.user_id=%d AND m.status=%s ORDER BY r.id LIMIT 501','academy_plan',get_current_user_id(),'active'));
        foreach ($assigned as $row) { $p=self::data($row); if (self::staff($p)) $options[$p['cohort_id']]=($p['course_snapshot']['title_en']??'').' · '.$p['cohort_id']; }
        foreach ($options as $id=>$name) $html.='<option value="'.esc_attr($id).'">'.esc_html($name).'</option>';
        $html.='</select></label><div data-academy-schedule></div><div data-academy-roster></div><div data-academy-progress></div>';
        $enrollments=Records::query('enrollment',array('limit'=>100),get_current_user_id());
        if (!is_wp_error($enrollments)) foreach ($enrollments as $enrollment) if ((int)($enrollment['fields']['learner_id']??0)===get_current_user_id()) $html.='<button type="button" data-academy-enrollment="'.esc_attr($enrollment['id']).'">'.esc_html(self::t('Open my learner record','مشاهده پرونده آموزشی من')).' · '.esc_html($enrollment['fields']['cohort_id']).'</button>';
        return $html.self::authorization_panel().'</section>';
    }
}
add_action('atlas_operations_ready',array('Atlas_Academy','register'));
register_activation_hook(__DIR__.'/atlas-operations.php',array('Atlas_Academy','grant_capabilities'));
