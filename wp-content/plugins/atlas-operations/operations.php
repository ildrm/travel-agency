<?php
if (!defined('ABSPATH')) { exit; }
use Atlas\Core\Records;
use Atlas\Core\Offers;
use Atlas\Core\Auth;

final class Atlas_Operations {
    private static $rendering_auth=false;
    private const CUSTOMER = array('stay_request','ticket_request','transfer_request','rental_request','support_case','enrollment','partner_application');
    private const AGENCY_ONLY = array('finance_case','certificate','attendance','assessment','event_checkin','production','rfq');
    private const DEMO_ONLY = array('assessment','certificate','rfq','supplier_quote','production');
    public static function boot() {
        foreach (atlas_operation_schemas() as $name => $schema) { Records::register_type($name, $schema); }
        Atlas_Event_Checkins::install();
        Atlas_Support_Workflow::register();
        Atlas_Partner_Workflow::register();
        Atlas_Reservation_Status::register();
        Atlas_Finance_UI::register();
        add_action('rest_api_init', array('Atlas_Event_Checkins','routes'));
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_action('template_redirect', array(__CLASS__, 'private_workspace_headers'), 0);
        add_action('admin_post_atlas_operation_save', array(__CLASS__, 'form_save'));
        add_action('admin_post_atlas_operation_transition', array(__CLASS__, 'form_transition'));
        add_action('admin_post_atlas_operation_edit', array(__CLASS__, 'form_edit'));
        add_action('admin_post_atlas_offer_save', array(__CLASS__, 'form_offer_save'));
        add_action('admin_post_atlas_offer_transition', array(__CLASS__, 'form_offer_transition'));
        add_action('woocommerce_login_form_end',array(__CLASS__,'auth_intent_field'));
        add_action('woocommerce_register_form_end',array(__CLASS__,'auth_intent_field'));
        add_filter('woocommerce_login_redirect',array(__CLASS__,'auth_redirect'),10,2);
        add_filter('woocommerce_registration_redirect',array(__CLASS__,'auth_redirect'),10,1);
        add_filter('atlas_core_validate_tour_publication', array('Atlas_Operation_Workflows','validate_publication'),10,3);
        add_filter('atlas_core_validate_cohort_publication', array('Atlas_Operation_Workflows','validate_cohort_publication'),10,3);
        add_filter('atlas_core_validate_tour_hold', array('Atlas_Operation_Workflows','validate_tour_hold'),10,3);
        add_filter('atlas_core_validate_cohort_hold', array('Atlas_Operation_Workflows','validate_cohort_hold'),10,3);
        add_filter('atlas_core_record_update_guard',array(__CLASS__,'dispatch_record_guard'),10,3);
        add_shortcode('atlas_catalog', array(__CLASS__, 'catalog'));
        add_shortcode('atlas_offer', array(__CLASS__, 'offer'));
        add_shortcode('atlas_portal', array(__CLASS__, 'portal'));
        add_shortcode('atlas_account', array(__CLASS__, 'account'));
        do_action('atlas_operations_ready');
    }
    public static function private_workspace_headers(): void {
        $workspace=in_array($_GET['atlas_area']??'', ['account','portal'], true);
        $page=is_singular()?get_queried_object():null;
        $hasWorkspace=$page instanceof WP_Post && (has_shortcode($page->post_content,'atlas_account')
            || has_shortcode($page->post_content,'atlas_portal'));
        $commerce=function_exists('is_checkout') && (is_checkout() || is_cart() || is_account_page());
        if (!$workspace && !$hasWorkspace && !$commerce) return;
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE',true);
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Robots-Tag: noindex');
    }
    public static function routes() {
        foreach (array('stay-requests'=>'stay_request','ticket-requests'=>'ticket_request','transfer-requests'=>'transfer_request','rental-requests'=>'rental_request','support-cases'=>'support_case','rfqs'=>'rfq') as $route=>$type) {
            register_rest_route('agency/v1','/'.$route,array('methods'=>'POST','permission_callback'=>array(__CLASS__,'logged_in'),'callback'=>function ($request) use ($type) { return self::rest_create($request,$type); }));
        }
        register_rest_route('agency/v1','/cohorts/(?P<id>[a-f0-9-]+)/materials',array('methods'=>'GET','permission_callback'=>array(__CLASS__,'logged_in'),'callback'=>array(__CLASS__,'course_materials')));
        register_rest_route('agency/v1','/cohorts/(?P<id>[a-f0-9-]+)/enrollments',array('methods'=>'POST','permission_callback'=>array(__CLASS__,'logged_in'),'callback'=>array(__CLASS__,'enroll')));
        register_rest_route('agency/v1','/rfqs/(?P<id>[a-f0-9-]+)',array('methods'=>'GET','permission_callback'=>array(__CLASS__,'logged_in'),'callback'=>array(__CLASS__,'invited_rfq')));
        register_rest_route('agency/v1', '/operations/(?P<type>[a-z_]+)', array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'rest_list'),'permission_callback'=>array(__CLASS__,'logged_in')),
            array('methods'=>'POST','callback'=>array(__CLASS__,'rest_create'),'permission_callback'=>array(__CLASS__,'logged_in')),
        ));
        register_rest_route('agency/v1', '/operations/(?P<type>[a-z_]+)/(?P<id>[a-f0-9-]+)', array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'rest_get'),'permission_callback'=>array(__CLASS__,'logged_in')),
            array('methods'=>'PATCH','callback'=>array(__CLASS__,'rest_update'),'permission_callback'=>array(__CLASS__,'logged_in')),
        ));
    }
    public static function logged_in() { return is_user_logged_in(); }
    /** Records holds the request row lock here, matching dispatch's assignment lock order. */
    public static function dispatch_record_guard($guard,$type,$id) {
        if ($guard===true && $type==='transfer_request' && class_exists('Atlas_Dispatch') && Atlas_Dispatch::has_live_request($id,true)) return new WP_Error('atlas_dispatch_managed',self::t('Use the dispatch workspace while this request has an active assignment.','تا زمانی که درخواست دارای تخصیص فعال است از فضای اعزام استفاده کنید.'),array('status'=>409));
        return $guard;
    }
    private static function protected_procurement($type) { return class_exists('Atlas_Procurement') && in_array($type,array('rfq','supplier_quote','production'),true); }
    private static function authorize_type($type) {
        $schemas = atlas_operation_schemas();
        if (class_exists('Atlas_Academy') && Atlas_Academy::enabled() && in_array($type,array('attendance','assessment','certificate'),true)) return new WP_Error('atlas_academy_managed',self::t('Use the academy learning workspace.','از فضای آموزش برای این عملیات استفاده کنید.'),array('status'=>409));
        if ($type==='event_checkin') return new WP_Error('atlas_checkin_unavailable', __('Check-in is unavailable until duplicate protection is configured.', 'atlas-operations'),array('status'=>503));
        if (in_array($type,self::DEMO_ONLY,true) && !(function_exists('wp_get_environment_type') && wp_get_environment_type()==='local' && defined('ATLAS_DEMO_MODE') && ATLAS_DEMO_MODE===true)) return new WP_Error('atlas_feature_unavailable', __('This feature is not enabled.', 'atlas-operations'),array('status'=>503));
        if (!isset($schemas[$type])) { return new WP_Error('atlas_unknown_type', __('Unknown operation.', 'atlas-operations'), array('status'=>404)); }
        if (in_array($type, self::AGENCY_ONLY, true) && !Auth::agency()) { return new WP_Error('atlas_forbidden', __('Access denied.', 'atlas-operations'), array('status'=>403)); }
        if (!current_user_can($schemas[$type]['capability']) && !Auth::agency()) { return new WP_Error('atlas_forbidden', __('Access denied.', 'atlas-operations'), array('status'=>403)); }
        return true;
    }
    /** Reject arbitrary nested content, deep structures and oversized values before core persistence. */
    private static function clean_nested($value, $depth = 0) {
        if (!is_array($value) || $depth > 4 || count($value) > 100) { return new WP_Error('atlas_invalid_array', __('Invalid structured data.', 'atlas-operations'), array('status'=>422)); }
        $result = array();
        foreach ($value as $key=>$item) {
            if (!is_int($key) && (!is_string($key) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $key))) { return new WP_Error('atlas_invalid_array', __('Invalid structured field.', 'atlas-operations'), array('status'=>422)); }
            if (is_array($item)) { $item = self::clean_nested($item, $depth + 1); if (is_wp_error($item)) return $item; }
            elseif (is_string($item)) { if (strlen($item)>2000) return new WP_Error('atlas_invalid_array', __('Structured field is too long.', 'atlas-operations'), array('status'=>422)); $item=sanitize_text_field($item); }
            elseif (!is_int($item) && !is_bool($item) && $item !== null) { return new WP_Error('atlas_invalid_array', __('Invalid structured value.', 'atlas-operations'), array('status'=>422)); }
            $result[$key] = $item;
        }
        return $result;
    }
    private static function validate($type, $input, $partial = false) {
        $schemas = atlas_operation_schemas();
        if (!isset($schemas[$type]) || !is_array($input)) return new WP_Error('atlas_invalid_input', __('Invalid input.', 'atlas-operations'), array('status'=>422));
        $out = array();
        if ($type==='support_case' && !$partial && array_diff(array_keys($input),array('category','subject','message','booking_id'))) return new WP_Error('atlas_forbidden',__('Use the support workflow for staff fields.','atlas-operations'),array('status'=>403));
        if ($type==='partner_application' && array_diff(array_keys($input),array('organization_name','contact','partner_types','markets','state'))) return new WP_Error('atlas_forbidden',__('Review fields are managed by the agency workflow.','atlas-operations'),array('status'=>403));
        foreach ($input as $name=>$value) {
            if ($name === 'org_id') {
                if ($value === '') continue;
                if (in_array($type, self::CUSTOMER, true)) return new WP_Error('atlas_forbidden', __('Customer requests cannot select an organization.', 'atlas-operations'), array('status'=>403));
                if (!is_int($value) && !(is_string($value) && ctype_digit($value))) return new WP_Error('atlas_invalid_input', __('Invalid organization.', 'atlas-operations'), array('status'=>422));
                $out[$name] = (int)$value; continue;
            }
            if (!isset($schemas[$type]['fields'][$name])) return new WP_Error('atlas_unknown_field', __('Unknown field.', 'atlas-operations'), array('status'=>422));
            if ($name === 'state' && (!$partial || !is_string($value))) return new WP_Error('atlas_invalid_state', __('State cannot be set here.', 'atlas-operations'), array('status'=>422));
            $definition = $schemas[$type]['fields'][$name]; $kind = $definition['type'];
            if ($value === '' && empty($definition['required'])) continue;
            if ($kind === 'int') {
                if ((!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value))) || strlen((string)$value)>15) return new WP_Error('atlas_invalid_field', __('Enter a whole number.', 'atlas-operations'), array('status'=>422));
                $value = (int)$value;
                if ($value < 0 || ($value === 0 && in_array($name, array('quantity','guests','passengers','capacity','units','learner_id','offer_id','occurrence_id'), true))) return new WP_Error('atlas_invalid_field', __('Value must be positive.', 'atlas-operations'), array('status'=>422));
            } elseif ($kind === 'bool') {
                if ($value === '1') $value = true; elseif ($value === '0') $value = false; if (!is_bool($value)) return new WP_Error('atlas_invalid_field', __('Invalid choice.', 'atlas-operations'), array('status'=>422));
            } elseif ($kind === 'array') {
                $value = self::clean_nested($value); if (is_wp_error($value)) return $value;
            } else {
                if (!is_string($value) || strlen($value) > ($definition['max'] ?? 10000)) return new WP_Error('atlas_invalid_field', __('Invalid text.', 'atlas-operations'), array('status'=>422));
                $value = sanitize_text_field($value);
                if ($kind==='timezone' && !in_array($value,timezone_identifiers_list(),true)) return new WP_Error('atlas_invalid_timezone',__('Enter a valid IANA time zone.','atlas-operations'),array('status'=>422));
                if (isset($definition['enum']) && !in_array($value,$definition['enum'],true)) return new WP_Error('atlas_invalid_field',__('Invalid choice.','atlas-operations'),array('status'=>422));
                if ($kind==='datetime') {
                    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new DateTimeZone('UTC'));
                    if (!$parsed || $parsed->format('Y-m-d\TH:i:s\Z')!==$value) return new WP_Error('atlas_invalid_datetime', __('Enter a valid UTC instant such as 2027-06-20T09:00:00Z.', 'atlas-operations'),array('status'=>422));
                }
                if (in_array($name,array('check_in','check_out','travel_date'),true)) {
                    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));
                    if (!$parsed || $parsed->format('Y-m-d') !== $value) return new WP_Error('atlas_invalid_date', __('Enter a valid date.', 'atlas-operations'),array('status'=>422));
                }
                if (!empty($definition['required']) && trim($value) === '') return new WP_Error('atlas_required_field', __('A required field is empty.', 'atlas-operations'), array('status'=>422));
            }
            $out[$name] = $value;
        }
        if (isset($out['category']) && in_array($type,array('vehicle','rental_request'),true) && !in_array($out['category'],array('bicycle','motorcycle','car','van'),true)) return new WP_Error('atlas_invalid_category', __('Invalid vehicle category.', 'atlas-operations'),array('status'=>422));
        if (isset($out['vehicle_class']) && $type === 'transfer_request' && !in_array($out['vehicle_class'],array('car','van','accessible'),true)) return new WP_Error('atlas_invalid_class', __('Invalid vehicle class.', 'atlas-operations'),array('status'=>422));
        if ($type==='ticket_request') {
            if (($out['passengers']??0)>50 || ($out['baggage']??0)>100 || (isset($out['origin'],$out['destination']) && strcasecmp($out['origin'],$out['destination'])===0) || (isset($out['origin_zone'])!==isset($out['destination_zone']))) return new WP_Error('atlas_invalid_ticket_request',self::t('Provide different locations, both time zones if known, and bounded passenger and baggage counts.','مبدأ و مقصد متفاوت، هر دو منطقه زمانی در صورت اطلاع و تعداد معتبر مسافر و بار را وارد کنید.'),array('status'=>422));
        }
        if ($type==='partner_application' && isset($out['partner_types'])) {
            if (!array_is_list($out['partner_types']) || count($out['partner_types'])<1) return new WP_Error('atlas_invalid_partner',__('Select at least one partner type.','atlas-operations'),array('status'=>422));
            foreach ($out['partner_types'] as $partner_type) if (!is_string($partner_type) || !in_array($partner_type,array('guide','accommodation','manufacturer','transport','rental','instructor','venue','catering','other'),true)) return new WP_Error('atlas_invalid_partner',__('Unknown partner type.','atlas-operations'),array('status'=>422));
        }
        if (!$partial) foreach ($schemas[$type]['fields'] as $name=>$definition) if (!empty($definition['required']) && !array_key_exists($name, $out)) return new WP_Error('atlas_required_field', __('A required field is missing.', 'atlas-operations'), array('status'=>422));
        foreach (array(array('check_in','check_out'), array('start','end'), array('pickup_at','dropoff_at')) as $pair) {
            if (isset($out[$pair[0]], $out[$pair[1]]) && (strtotime($out[$pair[0]]) === false || strtotime($out[$pair[1]]) <= strtotime($out[$pair[0]]))) return new WP_Error('atlas_invalid_interval', __('End must follow start.', 'atlas-operations'), array('status'=>422));
        }
        return $out;
    }
    public static function enroll($request) {
        if (class_exists('Atlas_Academy') && Atlas_Academy::enabled()) return Atlas_Academy::enroll($request);
        global $wpdb;
        $body=$request->get_json_params(); $id=(string)$request['id']; $user=get_current_user_id();
        if (!$user || !is_user_logged_in()) return new WP_Error('atlas_forbidden',__('Sign in required.','atlas-operations'),array('status'=>403));
        if (!is_array($body) || array_diff(array_keys($body),array('booking_id','learner_id')) || !isset($body['booking_id'],$body['learner_id']) || !is_string($body['booking_id']) || !is_int($body['learner_id']) || $body['learner_id']!==$user || !preg_match('/^[a-f0-9-]{36}$/',$id)) return new WP_Error('atlas_invalid_enrollment',__('Valid learner and reservation are required.','atlas-operations'),array('status'=>422));
        $row=$wpdb->get_row($wpdb->prepare('SELECT payload,state FROM '.Atlas\Core\DB::table('records').' WHERE id=%s AND type=%s',$id,'cohort'));
        $cohort=$row ? json_decode($row->payload,true) : null;
        if (!$row || $row->state!=='approved' || !is_array($cohort)) return new WP_Error('atlas_not_found',__('Cohort unavailable.','atlas-operations'),array('status'=>404));
        $offer=Offers::get((int)($cohort['offer_id']??0),true);
        $booking=Atlas\Core\Booking::get($body['booking_id'],$user);
        if (is_wp_error($offer) || $offer['kind']!=='cohort' || is_wp_error($booking) || (int)$booking['user_id']!==$user || (int)$booking['offer_id']!==(int)$offer['id'] || (int)$booking['quantity']!==1) return new WP_Error('atlas_invalid_enrollment',__('An active reservation for this cohort is required.','atlas-operations'),array('status'=>409));
        $fields=array('cohort_id'=>$id,'learner_id'=>$user,'booking_id'=>$body['booking_id'],'hold_id'=>(string)$booking['hold_id']);
        $key='enrollment_'.$body['booking_id']; $scope='user:'.$user; $canonical=$fields; ksort($canonical,SORT_STRING); $hash=hash('sha256',wp_json_encode($canonical));
        $find=static function() use ($wpdb,$scope,$key) { return $wpdb->get_row($wpdb->prepare('SELECT request_hash,response FROM '.Atlas\Core\DB::table('idempotency').' WHERE scope=%s AND operation=%s AND idem_key=%s',$scope,'record.enrollment',$key)); };
        $prior=$find();
        if ($prior) return hash_equals($prior->request_hash,$hash) ? Records::get('enrollment',(string)(json_decode($prior->response,true)['id']??''),$user) : new WP_Error('atlas_idempotency_conflict',__('Enrollment request changed.','atlas-operations'),array('status'=>409));
        // Match Core's inventory -> hold -> booking lock order. Linking an enrollment
        // never allocates another seat, and validation/record/idempotency/audit commit together.
        $wpdb->query('START TRANSACTION');
        try {
            $inventory=$wpdb->get_row($wpdb->prepare('SELECT offer_id FROM '.Atlas\Core\DB::table('inventory').' WHERE offer_id=%d FOR UPDATE',(int)$booking['offer_id']));
            $hold=$wpdb->get_row($wpdb->prepare('SELECT id,state,expires_at FROM '.Atlas\Core\DB::table('holds').' WHERE id=%s FOR UPDATE',(string)$booking['hold_id']));
            $locked=$wpdb->get_row($wpdb->prepare('SELECT id,user_id,offer_id,quantity,status FROM '.Atlas\Core\DB::table('bookings').' WHERE id=%s FOR UPDATE',$body['booking_id']));
            $prior=$find();
            if ($prior) { $wpdb->query('ROLLBACK'); return hash_equals($prior->request_hash,$hash) ? Records::get('enrollment',(string)(json_decode($prior->response,true)['id']??''),$user) : new WP_Error('atlas_idempotency_conflict',__('Enrollment request changed.','atlas-operations'),array('status'=>409)); }
            $paid=$locked && in_array($locked->status,array('paid_pending_confirmation','confirmed'),true);
            $unpaid=$locked && in_array($locked->status,array('held','pending_payment'),true);
            if (!$inventory || !$hold || !$locked || (int)$locked->user_id!==$user || (int)$locked->quantity!==1 || (int)$locked->offer_id!==(int)$offer['id'] ||
                (!$paid && (!$unpaid || $hold->state!=='active' || Atlas\Core\DB::now()>=$hold->expires_at)) || ($paid && $hold->state!=='consumed') ||
                Atlas_Operation_Workflows::cohort_for_offer((int)$offer['id'],(int)$offer['org_id'],true)!==$id) {
                $wpdb->query('ROLLBACK'); return new WP_Error('atlas_invalid_enrollment',__('An active reservation for this cohort is required.','atlas-operations'),array('status'=>409));
            }
            $record_id=wp_generate_uuid4(); $now=Atlas\Core\DB::now();
            if (!$wpdb->insert(Atlas\Core\DB::table('records'),array('id'=>$record_id,'type'=>'enrollment','org_id'=>0,'owner_id'=>$user,'state'=>'draft','payload'=>wp_json_encode($fields),'version'=>1,'created_at'=>$now,'updated_at'=>$now)) ||
                !$wpdb->insert(Atlas\Core\DB::table('idempotency'),array('scope'=>$scope,'operation'=>'record.enrollment','idem_key'=>$key,'request_hash'=>$hash,'response'=>wp_json_encode(array('id'=>$record_id)),'created_at'=>$now)) ||
                !Atlas\Core\DB::audit($user,0,'enrollment.link',$record_id,'success')) throw new RuntimeException('enrollment storage');
            $wpdb->query('COMMIT'); return Records::get('enrollment',$record_id,$user);
        } catch (Throwable $error) { $wpdb->query('ROLLBACK'); return new WP_Error('atlas_enrollment_failed',__('Enrollment could not be recorded.','atlas-operations'),array('status'=>503)); }
    }
    public static function course_materials($request) {
        if (class_exists('Atlas_Academy') && Atlas_Academy::enabled()) return Atlas_Academy::materials($request);
        global $wpdb;
        $user=get_current_user_id(); $cohort_id=(string)$request['id'];
        $enrollments=Records::query('enrollment',array('limit'=>100),$user); $authorized=false;
        if (!is_wp_error($enrollments)) foreach ($enrollments as $e) {
            if (($e['fields']['cohort_id']??'')!==$cohort_id || (int)($e['fields']['learner_id']??0)!==$user) continue;
            $booking=Atlas\Core\Booking::get((string)($e['fields']['booking_id']??''),$user);
            if (!is_wp_error($booking) && $booking['status']==='confirmed' && (int)$booking['user_id']===$user) { $authorized=true; break; }
        }
        if (!$authorized) return new WP_Error('atlas_not_found',__('Materials unavailable.','atlas-operations'),array('status'=>404));
        $cohort=$wpdb->get_row($wpdb->prepare('SELECT payload,org_id,state FROM '.Atlas\Core\DB::table('records').' WHERE id=%s AND type=%s',$cohort_id,'cohort'));
        $fields=$cohort ? json_decode($cohort->payload,true) : null;
        if (!$cohort || !in_array($cohort->state,array('approved','closed'),true) || !is_array($fields) || (int)$booking['offer_id']!==(int)($fields['offer_id']??0)) return new WP_Error('atlas_not_found',__('Materials unavailable.','atlas-operations'),array('status'=>404));
        $course=$wpdb->get_row($wpdb->prepare('SELECT payload,org_id,state FROM '.Atlas\Core\DB::table('records').' WHERE id=%s AND type=%s',(string)($fields['course_id']??''),'course'));
        $content=$course ? json_decode($course->payload,true) : null;
        if (!$course || (int)$course->org_id!==(int)$cohort->org_id || !in_array($course->state,array('approved','closed'),true) || !is_array($content)) return new WP_Error('atlas_not_found',__('Materials unavailable.','atlas-operations'),array('status'=>404));
        $materials=array(); foreach (($content['materials']??array()) as $item) if (is_array($item) && isset($item['title'],$item['body']) && is_string($item['title']) && is_string($item['body'])) $materials[]=array('title'=>$item['title'],'body'=>$item['body']);
        $response=new WP_REST_Response(array('course_title_en'=>$content['title_en']??'','course_title_fa'=>$content['title_fa']??'','materials'=>$materials),200);
        $response->header('Cache-Control','private, no-store'); return $response;
    }
    private static function check_links($type,$data,$org,$for_bid=true) {
        if ($type==='support_case' && !empty($data['booking_id']) && is_wp_error(Atlas\Core\Booking::get((string)$data['booking_id'],get_current_user_id()))) return new WP_Error('atlas_invalid_booking',__('The related booking is unavailable.','atlas-operations'),array('status'=>422));
        $parents=array('cohort'=>array('course_id','course'),'unit_type'=>array('property_id','property'),'stay_rate'=>array('unit_type_id','unit_type'),'attendance'=>array('cohort_id','cohort'),'assessment'=>array('cohort_id','cohort'),'certificate'=>array('cohort_id','cohort'));
        if (isset($parents[$type])) {
            list($field,$parent_type)=$parents[$type];
            $parent=Records::get($parent_type,(string)($data[$field]??''),get_current_user_id());
            if (is_wp_error($parent) || ((int)$parent['org_id']!==(int)$org && !(Auth::agency() && !$org))) return new WP_Error('atlas_invalid_parent',__('The referenced record is unavailable.','atlas-operations'),array('status'=>422));
        }
        if ($type==='rfq' && isset($data['recipients'])) foreach ($data['recipients'] as $recipient) if (!is_int($recipient) || $recipient<1) return new WP_Error('atlas_invalid_recipient',__('Invalid supplier organization.','atlas-operations'),array('status'=>422));
        if ($type==='supplier_quote') {
            $id=(string)($data['rfq_id']??'');
            $rfq=self::load_invited_rfq($id,$org); if (is_wp_error($rfq)) return $rfq;
            if ($for_bid && (empty($rfq['deadline']) || !Atlas_Operation_Workflows::future($rfq['deadline']) || empty($data['expiry']) || !Atlas_Operation_Workflows::future($data['expiry']))) return new WP_Error('atlas_quote_closed',__('This request or quote is no longer open for submission.','atlas-operations'),array('status'=>422));
        }
        if ($type==='production') {
            $quote=Records::get('supplier_quote',(string)($data['quote_id']??''),get_current_user_id());
            if (is_wp_error($quote) || ($quote['fields']['rfq_id']??'')!==($data['rfq_id']??'') || !in_array($quote['state'],array('submitted','approved'),true)) return new WP_Error('atlas_invalid_quote',__('An eligible quote for this RFQ is required.','atlas-operations'),array('status'=>422));
            if ($for_bid && (empty($quote['fields']['expiry']) || !Atlas_Operation_Workflows::future($quote['fields']['expiry']))) return new WP_Error('atlas_quote_expired',__('The supplier quote expired; obtain a renewed quote.','atlas-operations'),array('status'=>422));
        }
        return true;
    }
    private static function load_invited_rfq($id,$org) {
        global $wpdb;
        if (!is_string($id) || !preg_match('/^[a-f0-9-]{36}$/',$id) || (!$org && !Auth::agency()) || (!Auth::agency() && (!current_user_can('atlas_manage_records') || !Auth::member((int)$org)))) return new WP_Error('atlas_not_found',__('Request not found.','atlas-operations'),array('status'=>404));
        $row=$wpdb->get_row($wpdb->prepare('SELECT payload,state FROM '.Atlas\Core\DB::table('records').' WHERE id=%s AND type=%s',$id,'rfq'));
        if (!$row || !in_array($row->state,array('submitted','approved'),true)) return new WP_Error('atlas_not_found',__('Request not found.','atlas-operations'),array('status'=>404));
        $fields=json_decode($row->payload,true); if (!is_array($fields) || (!Auth::agency() && !in_array((int)$org,$fields['recipients']??array(),true))) return new WP_Error('atlas_not_found',__('Request not found.','atlas-operations'),array('status'=>404));
        return array('id'=>$id,'title'=>$fields['title']??'','requirements'=>$fields['requirements']??'','quantity'=>$fields['quantity']??0,'deadline'=>$fields['deadline']??'','state'=>$row->state);
    }
    public static function invited_rfq($request) {
        $gate=self::authorize_type('supplier_quote'); if (is_wp_error($gate)) return $gate;
        $org=(int)$request->get_param('org_id'); return self::load_invited_rfq((string)$request['id'],$org);
    }
    public static function rest_list($request) { $type = $request['type']; $ok = self::authorize_type($type); if (is_wp_error($ok)) return $ok; return Records::query($type,array(),get_current_user_id()); }
    public static function rest_get($request) { $ok=self::authorize_type($request['type']); if (is_wp_error($ok)) return $ok; return Records::get($request['type'],$request['id'],get_current_user_id()); }
    public static function rest_create($request,$forced_type=null) {
        $type=$forced_type ?: $request['type']; $ok=self::authorize_type($type); if (is_wp_error($ok)) return $ok;
        if (self::protected_procurement($type)) return new WP_Error('atlas_procurement_managed',self::t('Use the procurement workspace.','از فضای تدارکات استفاده کنید.'),array('status'=>409));
        if ($type==='enrollment') return new WP_Error('atlas_forbidden',__('Use the cohort enrollment workflow.','atlas-operations'),array('status'=>403));
        $data=self::validate($type,$request->get_json_params()); if (is_wp_error($data)) return $data;
        $links=self::check_links($type,$data,(int)($data['org_id']??0)); if (is_wp_error($links)) return $links;
        if ($type==='support_case') { $data['state']='new'; $data['priority']='normal'; }
        $key=(string)$request->get_header('Idempotency-Key');
        if (!preg_match('/^[a-zA-Z0-9_-]{16,128}$/', $key) || !method_exists(Records::class,'create_idempotent')) return new WP_Error('atlas_idempotency_required', __('A valid request key is required.', 'atlas-operations'),array('status'=>400));
        return Records::create_idempotent($type,$data,get_current_user_id(),$key);
    }
    public static function rest_update($request) {
        $type=$request['type']; $ok=self::authorize_type($type); if (is_wp_error($ok)) return $ok;
        if (self::protected_procurement($type)) return new WP_Error('atlas_procurement_managed',self::t('Use the procurement workspace.','از فضای تدارکات استفاده کنید.'),array('status'=>409));
        if ($type==='enrollment') return new WP_Error('atlas_forbidden',__('Enrollment is managed by the cohort workflow.','atlas-operations'),array('status'=>403));
        if ($type==='support_case') return new WP_Error('atlas_forbidden',__('Use the support reply or triage workflow.','atlas-operations'),array('status'=>403));
        $body=$request->get_json_params(); if (!is_array($body) || !isset($body['version'],$body['changes']) || !is_int($body['version']) || !is_array($body['changes'])) return new WP_Error('atlas_invalid_input', __('Version and changes are required.', 'atlas-operations'),array('status'=>422));
        $record=Records::get($type,$request['id'],get_current_user_id()); if (is_wp_error($record)) return $record;
        $changes=self::validate($type,$body['changes'],true); if (is_wp_error($changes)) return $changes;
        if ($type==='cohort' && class_exists('Atlas_Academy')) {
            $guard=Atlas_Academy::guard_cohort_changes($request['id'],$changes); if (is_wp_error($guard)) return $guard;
        }
        $for_bid=!Auth::agency() || $record['state']==='draft' || ($changes['state']??'')==='submitted';
        $links=self::check_links($type,array_merge($record['fields'],$changes),(int)$record['org_id'],$for_bid); if (is_wp_error($links)) return $links;
        $editable=array('state');
        if ($type==='production' && $record['state']==='approved' && Auth::agency()) $editable=array_merge($editable,array('milestones','shipment_ref','inspection','invoice_ref','payout_evidence'));
        if ($record['state'] !== 'draft' && array_diff(array_keys($changes),$editable)) return new WP_Error('atlas_locked', __('Submitted records cannot be edited.', 'atlas-operations'),array('status'=>409));
        if (isset($changes['state'])) { $candidate=$record; $candidate['fields']=array_merge($record['fields'],$changes); $check=Atlas_Operation_Workflows::before_transition($type,$candidate,$changes['state']); if (is_wp_error($check)) return $check; }
        return Records::update($type,$request['id'],$changes,get_current_user_id(),$body['version']);
    }
    private static function language() { return isset($_GET['lang']) && in_array($_GET['lang'], array('en','fa'), true) ? sanitize_key(wp_unslash($_GET['lang'])) : (str_starts_with(determine_locale(),'fa') ? 'fa' : 'en'); }
    private static function t($en,$fa) { return self::language()==='fa' ? $fa : $en; }
    private static function offer_url($id) { return add_query_arg(array('offer_id'=>(int)$id,'lang'=>self::language()), home_url('/')); }
    public static function catalog($attributes=array()) {
        $atts=shortcode_atts(array('kind'=>''),$attributes,'atlas_catalog');
        $filters=array(); if ($atts['kind']!=='') $filters['kind']=sanitize_key($atts['kind']);
        $offers=Offers::listing($filters); if (is_wp_error($offers)) return '<p>'.esc_html__('Catalog is temporarily unavailable.','atlas-operations').'</p>';
        if (!$offers) return '<p>'.esc_html(self::t('No published offers yet.','هنوز پیشنهادی منتشر نشده است.')).'</p>';
        $html='<div class="atlas-offer-list">'; foreach ($offers as $offer) { $title=self::language()==='fa' && !empty($offer['title_fa'])?$offer['title_fa']:$offer['title_en']; $price=$offer['mode']==='request_only' ? self::t('Request a quote; availability unconfirmed.','درخواست قیمت؛ موجودی تأیید نشده است.') : number_format_i18n((int)$offer['amount_irr']).' IRR'; $html.='<article><h3><a href="'.esc_url(self::offer_url($offer['id'])).'">'.esc_html($title).'</a></h3><p>'.esc_html($price).'</p></article>'; } return $html.'</div>';
    }
    public static function offer($attributes=array()) {
        $atts=shortcode_atts(array('id'=>0),$attributes,'atlas_offer'); $id=(int)$atts['id']; if (!$id && isset($_GET['offer_id'])) $id=absint($_GET['offer_id']);
        $offer=Offers::get($id,true); if (is_wp_error($offer) || !$offer) return '<h1>'.esc_html(self::t('Offer unavailable','پیشنهاد در دسترس نیست')).'</h1>';
        $fa=self::language()==='fa'; $title=$fa && !empty($offer['title_fa'])?$offer['title_fa']:$offer['title_en']; $desc=$fa && !empty($offer['description_fa'])?$offer['description_fa']:$offer['description_en'];
        $price=$offer['mode']==='request_only' ? self::t('Request a quote; availability unconfirmed.','درخواست قیمت؛ موجودی تأیید نشده است.') : number_format_i18n((int)$offer['amount_irr']).' IRR'; $html='<article><h1>'.esc_html($title).'</h1><p>'.esc_html($desc).'</p><p>'.esc_html($price).'</p>';
        if (class_exists('Atlas_Catalog_UI')) $html.=Atlas_Catalog_UI::details($offer);
        if ($offer['mode']==='request_only') { $request_types=['flight'=>'ticket_request','rail'=>'ticket_request','bus'=>'ticket_request','stay'=>'stay_request','transfer'=>'transfer_request','rental'=>'rental_request'];
            $task=$request_types[$offer['kind']]??'support_case';
            $request_url=add_query_arg(['atlas_area'=>'account','lang'=>self::language(),'atlas_task'=>$task,'atlas_service'=>$offer['kind']],home_url('/')).'#atlas-task-'.$task; $html.='<p>'.esc_html(self::t('Request only; availability is not confirmed.','فقط درخواست؛ موجودی تأیید نشده است.')).'</p><p><a href="'.esc_url($request_url).'">'.esc_html(self::t('Start an inquiry','ثبت درخواست')).'</a></p>'; }
        elseif (!is_user_logged_in()) $html.='<p><a href="'.esc_url(add_query_arg(array('atlas_area'=>'account','lang'=>self::language(),'atlas_return'=>self::offer_url($id)),home_url('/'))).'">'.esc_html(self::t('Sign in or register to reserve.','برای رزرو وارد شوید یا حساب بسازید.')).'</a></p>';
        elseif (empty($offer['product_id']) || !function_exists('wc_get_checkout_url')) $html.='<p>'.esc_html(self::t('Reservation is temporarily unavailable.','رزرو موقتاً در دسترس نیست.')).'</p>';
        elseif ($offer['kind']==='cohort' && !(class_exists('Atlas_Academy') && Atlas_Academy::enabled() ? Atlas_Academy::cohort_for_offer($id,(int)$offer['org_id']) : Atlas_Operation_Workflows::cohort_for_offer($id,(int)$offer['org_id']))) $html.='<p>'.esc_html(self::t('Enrollment is temporarily unavailable.','ثبت‌نام موقتاً در دسترس نیست.')).'</p>';
        else {
            wp_enqueue_script('atlas-booking', plugins_url('assets/booking.js', __FILE__), array(), ATLAS_CORE_VERSION, true);
            wp_localize_script('atlas-booking','atlasBooking',array(
                'holdUrl'=>esc_url_raw(rest_url('agency/v1/holds')), 'cartUrl'=>esc_url_raw(rest_url('wc/store/v1/cart/add-item')),
                'travelersUrl'=>esc_url_raw(rest_url('agency/v1/travelers')), 'cohortsUrl'=>esc_url_raw(rest_url('agency/v1/cohorts/')), 'learnerId'=>get_current_user_id(), 'restNonce'=>wp_create_nonce('wp_rest'), 'storeNonce'=>wp_create_nonce('wc_store_api'), 'checkoutUrl'=>esc_url_raw(add_query_arg('lang',self::language(),wc_get_checkout_url())),
                'invalid'=>self::t('Enter a valid quantity.','تعداد معتبر وارد کنید.'), 'unavailable'=>self::t('Secure reservation is unavailable in this browser.','رزرو امن در این مرورگر در دسترس نیست.'),
                'working'=>self::t('Checking availability…','در حال بررسی موجودی…'), 'error'=>self::t('Reservation could not be completed.','رزرو انجام نشد.'),
                'network'=>self::t('Connection interrupted. If the reservation appears twice in your cart, open the cart in a new tab, remove the duplicated service line, then return here and retry the same hold.','ارتباط قطع شد. اگر رزرو دوبار در سبد است، سبد را در برگه جدید باز کنید، ردیف تکراری را حذف کنید و برای تلاش مجدد به همین صفحه برگردید.'),
                'loadingTravelers'=>self::t('Loading travelers…','در حال دریافت مسافران…'), 'selectTravelers'=>self::t('Select one traveler per place.','برای هر نفر یک مسافر انتخاب کنید.'),
                'addTravelers'=>self::t('Add travelers in your account first.','ابتدا مسافران را در حساب خود اضافه کنید.'), 'travelersUnavailable'=>self::t('Traveler details are unavailable.','اطلاعات مسافران در دسترس نیست.')
            ));
            $academy=$offer['kind']==='cohort' && class_exists('Atlas_Academy') && Atlas_Academy::enabled();
            $needs=!empty($offer['requires_travelers']) && !$academy; $cohort=$offer['kind']==='cohort' ? ($academy?Atlas_Academy::cohort_for_offer($id,(int)$offer['org_id']):Atlas_Operation_Workflows::cohort_for_offer($id,(int)$offer['org_id'])) : '';
            $html.='<form data-atlas-booking data-cohort="'.esc_attr($cohort).'" data-offer="'.esc_attr($id).'" data-version="'.esc_attr($offer['version']).'" data-product="'.esc_attr($offer['product_id']).'" data-needs-travelers="'.($needs?'1':'0').'"><label>'.esc_html(self::t('Places','تعداد نفرات')).'<input name="quantity" type="number" min="1" max="'.($offer['kind']==='cohort' && !$academy?'1':'50').'" value="1" required></label>';
            if ($needs) $html.='<fieldset><legend>'.esc_html(self::t('Travelers','مسافران')).'</legend><div data-atlas-traveler-options></div><a href="'.esc_url(add_query_arg(array('atlas_area'=>'account','lang'=>self::language()),home_url('/'))).'">'.esc_html(self::t('Manage travelers','مدیریت مسافران')).'</a></fieldset>';
            $html.=apply_filters('atlas_operations_booking_fields','',$offer);
            $html.='<button type="submit" data-atlas-reserve>'.esc_html(self::t('Reserve','رزرو')).'</button><p role="status" aria-live="polite" data-atlas-reserve-status></p><a target="_blank" rel="noopener noreferrer" href="'.esc_url(add_query_arg('lang',self::language(),wc_get_cart_url())).'">'.esc_html(self::t('Open cart in a new tab','سبد را در برگه جدید باز کنید')).'</a></form>';
        }
        return apply_filters('atlas_operations_offer',$html,$offer).'</article>';
    }
    public static function form_save() {
        if (!is_user_logged_in()) wp_die(esc_html__('Sign in required.','atlas-operations'),'',array('response'=>403));
        check_admin_referer('atlas_operation_save'); $type=isset($_POST['record_type'])?sanitize_key(wp_unslash($_POST['record_type'])):'';
        $ok=self::authorize_type($type); if (is_wp_error($ok)) wp_die(esc_html($ok->get_error_message()),'',array('response'=>403));
        if (self::protected_procurement($type)) wp_die(esc_html(self::t('Use the procurement workspace.','از فضای تدارکات استفاده کنید.')),'',array('response'=>409));
        if ($type==='enrollment') wp_die(esc_html__('Enrollment is managed by the cohort workflow.','atlas-operations'),'',array('response'=>403));
        $raw=isset($_POST['fields']) && is_array($_POST['fields'])?wp_unslash($_POST['fields']):array();
        foreach ($raw as $key=>$value) if (isset(atlas_operation_schemas()[$type]['fields'][$key]['type']) && atlas_operation_schemas()[$type]['fields'][$key]['type']==='array' && is_string($value)) { $decoded=json_decode($value,true); $raw[$key]=is_array($decoded)?$decoded:$value; }
        $data=self::validate($type,$raw); if (is_wp_error($data)) wp_die(esc_html($data->get_error_message()),'',array('response'=>422));
        $links=self::check_links($type,$data,(int)($data['org_id']??0)); if (is_wp_error($links)) wp_die(esc_html($links->get_error_message()),'',array('response'=>422));
        if ($type==='support_case') { $data['state']='new'; $data['priority']='normal'; }
        $key=isset($_POST['idempotency_key']) ? sanitize_text_field(wp_unslash($_POST['idempotency_key'])) : '';
        if (!preg_match('/^[a-zA-Z0-9_-]{16,128}$/',$key) || !method_exists(Records::class,'create_idempotent')) wp_die(esc_html__('Request cannot be safely saved.','atlas-operations'),'',array('response'=>503));
        $result=Records::create_idempotent($type,$data,get_current_user_id(),$key); if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()),'',array('response'=>403));
        wp_safe_redirect(add_query_arg(array('atlas_saved'=>'1','lang'=>self::language()),wp_get_referer() ?: home_url('/'))); exit;
    }
    public static function form_offer_save() {
        if (!is_user_logged_in()) wp_die(esc_html__('Sign in required.','atlas-operations'),'',array('response'=>403));
        check_admin_referer('atlas_offer_save');
        $get=static function($name) { return isset($_POST[$name]) && is_string($_POST[$name]) ? sanitize_text_field(wp_unslash($_POST[$name])) : ''; };
        foreach (array('org_id','capacity','amount_irr','product_id','min_age','max_age','guardian_below') as $numeric) { $value=$get($numeric); if ($value!=='' && (!ctype_digit($value) || strlen($value)>15)) wp_die(esc_html__('Enter nonnegative whole numbers for quantities and IRR.','atlas-operations'),'',array('response'=>422)); }
        $data=array('kind'=>sanitize_key($get('kind')),'org_id'=>absint($get('org_id')),'title_en'=>$get('title_en'),'title_fa'=>$get('title_fa'),'description_en'=>$get('description_en'),'description_fa'=>$get('description_fa'),'registration_open_at'=>$get('registration_open_at'),'registration_close_at'=>$get('registration_close_at'),'timezone'=>$get('timezone'),'capacity'=>absint($get('capacity')),'amount_irr'=>absint($get('amount_irr')),'mode'=>sanitize_key($get('mode')),'status'=>'draft','product_id'=>Auth::agency()?absint($get('product_id')):0);
        $policy=array(); foreach (array('min_age','max_age','guardian_below') as $key) { $value=$get($key); if ($value!=='') $policy[$key]=(int)$value; }
        if ($policy) { $policy['age_on']=$get('age_on'); $data['eligibility']=$policy; }
        $result=Offers::save(0,$data,get_current_user_id()); if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()),'',array('response'=>422));
        wp_safe_redirect(add_query_arg(array('atlas_saved'=>'1','lang'=>self::language()),wp_get_referer() ?: home_url('/'))); exit;
    }
    public static function form_offer_transition() {
        if (!is_user_logged_in()) wp_die(esc_html__('Sign in required.','atlas-operations'),'',array('response'=>403));
        check_admin_referer('atlas_offer_transition');
        $id=isset($_POST['offer_id'])?absint($_POST['offer_id']):0; $version=isset($_POST['version'])?absint($_POST['version']):0;
        $target=isset($_POST['target'])?sanitize_key(wp_unslash($_POST['target'])):'';
        $offer=Offers::get($id,false); if (is_wp_error($offer)) wp_die(esc_html($offer->get_error_message()),'',array('response'=>404));
        $allowed=array('draft'=>array('submitted'),'submitted'=>array('approved'),'approved'=>array('published'),'published'=>array('suspended'));
        if (!in_array($target,$allowed[$offer['status']]??array(),true)) wp_die(esc_html__('Transition unavailable.','atlas-operations'),'',array('response'=>409));
        if (in_array($target,array('approved','published','suspended'),true) && (!Auth::agency() || (int)get_post_field('post_author',$id)===get_current_user_id())) wp_die(esc_html__('An independent agency reviewer is required.','atlas-operations'),'',array('response'=>403));
        $result=Offers::save($id,array('status'=>$target,'expected_version'=>$version),get_current_user_id());
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()),'',array('response'=>409));
        wp_safe_redirect(add_query_arg(array('atlas_saved'=>'1','lang'=>self::language()),wp_get_referer() ?: home_url('/'))); exit;
    }
    private static function offer_admin() {
        if (!current_user_can('atlas_manage_offers') && !Auth::agency()) return '';
        $html='<details><summary>'.esc_html(self::t('Offers and departures','پیشنهادها و زمان‌های سفر')).'</summary><ul>';
        $ids=get_posts(array('post_type'=>'atlas_offer','post_status'=>'private','posts_per_page'=>100,'fields'=>'ids'));
        foreach ($ids as $id) {
            $offer=Offers::get((int)$id,false); if (is_wp_error($offer)) continue;
            $html.='<li>'.esc_html($offer['title_en'] ?: $offer['title_fa']).' — '.esc_html($offer['status']).' ('.esc_html((string)$id).')';
            $next=array('draft'=>'submitted','submitted'=>'approved','approved'=>'published','published'=>'suspended')[$offer['status']]??'';
            if ($next && ($next==='submitted' || Auth::agency())) $html.='<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('atlas_offer_transition','_wpnonce',true,false).'<input type="hidden" name="action" value="atlas_offer_transition"><input type="hidden" name="offer_id" value="'.esc_attr($id).'"><input type="hidden" name="version" value="'.esc_attr($offer['version']).'"><input type="hidden" name="target" value="'.esc_attr($next).'"><button>'.esc_html($next).'</button></form>';
            $html.='</li>';
        }
        $html.='</ul><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('atlas_offer_save','_wpnonce',true,false).'<input type="hidden" name="action" value="atlas_offer_save">';
        $html.='<label>'.esc_html(self::t('Service type','نوع خدمت')).'<select name="kind">'; foreach (array('tour','event','cohort','stay','rental','transfer','flight','rail','bus') as $kind) $html.='<option value="'.esc_attr($kind).'">'.esc_html($kind).'</option>'; $html.='</select></label>';
        $html.='<label>'.esc_html(self::t('Mode','نوع ارائه')).'<select name="mode"><option value="local">Local capacity</option><option value="request_only">Request only</option></select></label>';
        $fields=array('org_id'=>'Organization ID','title_en'=>'English title','title_fa'=>'Persian title','description_en'=>'English description','description_fa'=>'Persian description','registration_open_at'=>'Registration open UTC (ISO 8601)','registration_close_at'=>'Registration close UTC (ISO 8601)','timezone'=>'IANA time zone','capacity'=>'Capacity','amount_irr'=>'Price IRR','min_age'=>'Minimum age (optional)','max_age'=>'Maximum age (optional)','guardian_below'=>'Guardian required below age (optional)','age_on'=>'Age evaluated on date YYYY-MM-DD');
        if (Auth::agency()) $fields['product_id']='WooCommerce product ID';
        foreach ($fields as $name=>$label) $html.='<label>'.esc_html($label).'<input type="text" name="'.esc_attr($name).'"'.($name==='timezone'?' value="UTC"':'').'></label>';
        return $html.'<button>'.esc_html(self::t('Create draft','ایجاد پیش‌نویس')).'</button></form></details>';
    }
    public static function form_edit() {
        if (!is_user_logged_in()) wp_die(esc_html__('Sign in required.','atlas-operations'),'',array('response'=>403));
        check_admin_referer('atlas_operation_edit');
        $type=isset($_POST['record_type'])?sanitize_key(wp_unslash($_POST['record_type'])):'';
        $id=isset($_POST['record_id'])?sanitize_text_field(wp_unslash($_POST['record_id'])):'';
        $version=isset($_POST['version'])?absint($_POST['version']):0;
        $raw=isset($_POST['fields']) && is_array($_POST['fields'])?wp_unslash($_POST['fields']):array();
        if (isset($raw['milestones']) && is_string($raw['milestones'])) { $decoded=json_decode($raw['milestones'],true); if (is_array($decoded)) $raw['milestones']=$decoded; }
        $req=new WP_REST_Request('PATCH','/agency/v1/operations/'.$type.'/'.$id); $req['type']=$type; $req['id']=$id;
        $req->set_header('content-type','application/json'); $req->set_body(wp_json_encode(array('version'=>$version,'changes'=>$raw)));
        $result=self::rest_update($req); if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()),'',array('response'=>422));
        wp_safe_redirect(add_query_arg(array('atlas_saved'=>'1','lang'=>self::language()),wp_get_referer() ?: home_url('/'))); exit;
    }
    private static function invited_list() {
        if (!current_user_can('atlas_manage_records') || !(function_exists('wp_get_environment_type') && wp_get_environment_type()==='local' && defined('ATLAS_DEMO_MODE') && ATLAS_DEMO_MODE===true)) return '';
        global $wpdb;
        $orgs=$wpdb->get_col($wpdb->prepare('SELECT org_id FROM '.Atlas\Core\DB::table('memberships').' WHERE user_id=%d AND status=%s',get_current_user_id(),'active'));
        $ids=$wpdb->get_col($wpdb->prepare('SELECT id FROM '.Atlas\Core\DB::table('records').' WHERE type=%s AND state IN (%s,%s) ORDER BY created_at DESC LIMIT 100','rfq','submitted','approved'));
        $html='<section><h3>'.esc_html(self::t('Invited procurement requests','درخواست‌های خرید دریافتی')).'</h3><ul>';
        foreach ($ids as $id) foreach ($orgs as $org) { $rfq=self::load_invited_rfq($id,(int)$org); if (!is_wp_error($rfq)) $html.='<li>'.esc_html($rfq['title']).' — '.esc_html($id).' ('.esc_html((string)$org).')</li>'; }
        return $html.'</ul></section>';
    }
    public static function form_transition() {
        if (!is_user_logged_in()) wp_die(esc_html__('Sign in required.','atlas-operations'),'',array('response'=>403));
        check_admin_referer('atlas_operation_transition');
        $type=isset($_POST['record_type'])?sanitize_key(wp_unslash($_POST['record_type'])):'';
        $id=isset($_POST['record_id'])?sanitize_text_field(wp_unslash($_POST['record_id'])):'';
        $target=isset($_POST['target'])?sanitize_key(wp_unslash($_POST['target'])):'';
        $version=isset($_POST['version'])?absint($_POST['version']):0;
        $ok=self::authorize_type($type); if (is_wp_error($ok)) wp_die(esc_html($ok->get_error_message()),'',array('response'=>403));
        if (self::protected_procurement($type)) wp_die(esc_html(self::t('Use the procurement workspace.','از فضای تدارکات استفاده کنید.')),'',array('response'=>409));
        if ($type==='enrollment') wp_die(esc_html__('Enrollment is managed by the cohort workflow.','atlas-operations'),'',array('response'=>403));
        if ($type==='support_case') wp_die(esc_html__('Use the support reply or triage workflow.','atlas-operations'),'',array('response'=>403));
        $record=Records::get($type,$id,get_current_user_id()); if (is_wp_error($record)) wp_die(esc_html($record->get_error_message()),'',array('response'=>404));
        $check=Atlas_Operation_Workflows::before_transition($type,$record,$target); if (is_wp_error($check)) wp_die(esc_html($check->get_error_message()),'',array('response'=>422));
        $result=Records::update($type,$id,array('state'=>$target),get_current_user_id(),$version);
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()),'',array('response'=>409));
        wp_safe_redirect(add_query_arg(array('atlas_saved'=>'1','lang'=>self::language()),wp_get_referer() ?: home_url('/'))); exit;
    }
    private static function checkin_panel() {
        if (!Auth::agency()) return '';
        wp_enqueue_script('atlas-checkin',plugins_url('assets/checkin.js',__FILE__),array(),'0.1.0',true);
        wp_localize_script('atlas-checkin','atlasCheckin',array('base'=>esc_url_raw(rest_url('agency/v1/events/')),'nonce'=>wp_create_nonce('wp_rest'),'invalid'=>self::t('Enter valid numbers.','اعداد معتبر وارد کنید.'),'success'=>self::t('Attendance recorded.','حضور ثبت شد.'),'error'=>self::t('Check-in failed.','ثبت حضور انجام نشد.')));
        return '<details><summary>'.esc_html(self::t('Event check-in','ثبت حضور رویداد')).'</summary><p>'.esc_html(self::t('Confirmed bookings only. Attendee number is the place within the booking.','فقط رزروهای تأییدشده. شماره شرکت‌کننده جایگاه او در رزرو است.')).'</p><form data-atlas-checkin><label>'.esc_html(self::t('Event offer ID','شناسه رویداد')).'<input name="offer_id" type="number" min="1" required></label><label>'.esc_html(self::t('Booking ID','شناسه رزرو')).'<input name="booking_id" required></label><label>'.esc_html(self::t('Attendee number','شماره شرکت‌کننده')).'<input name="attendee_index" type="number" min="1" required></label><button>'.esc_html(self::t('Check in','ثبت حضور')).'</button><p role="status" aria-live="polite"></p></form></details>';
    }
    private static function inventory_panel() {
        if (!class_exists('Atlas\\Inventory\\Local') || (!current_user_can('atlas_manage_records') && !Auth::agency())) return '';
        wp_enqueue_script('atlas-inventory-operations',plugins_url('assets/inventory.js',__FILE__),array(),'0.1.0',true);
        wp_localize_script('atlas-inventory-operations','atlasInventory',array('base'=>esc_url_raw(rest_url('atlas-inventory/v1/')),'nonce'=>wp_create_nonce('wp_rest'),'working'=>self::t('Saving…','در حال ذخیره…'),'error'=>self::t('Operation failed.','عملیات انجام نشد.')));
        $html='<details><summary>'.esc_html(self::t('Internal stay and vehicle inventory','موجودی داخلی اقامت و خودرو')).'</summary><p>'.esc_html(self::t('Internal planning only; no live availability or booking is promised.','فقط برنامه‌ریزی داخلی؛ موجودی یا رزرو زنده تضمین نمی‌شود.')).'</p><form data-atlas-inventory><label>'.esc_html(self::t('Action','عملیات')).'<select>';
        foreach (array('resources','stay','vehicle','capacity','blackout','rental-policy','eligibility-review','start','return','release','resolve-dispute') as $action) $html.='<option value="'.esc_attr($action).'">'.esc_html($action).'</option>';
        return $html.'</select></label><div data-inventory-fields></div><button>'.esc_html(self::t('Run action','اجرای عملیات')).'</button><p role="status" aria-live="polite"></p></form></details>';
    }
    private static function traveler_form() {
        wp_enqueue_script('atlas-travelers',plugins_url('assets/travelers.js',__FILE__),array(),'0.1.0',true);
        wp_localize_script('atlas-travelers','atlasTraveler',array('url'=>esc_url_raw(rest_url('agency/v1/travelers')),'nonce'=>wp_create_nonce('wp_rest'),
            'unavailable'=>self::t('Secure submission is unavailable.','ثبت امن در دسترس نیست.'),'saving'=>self::t('Saving…','در حال ذخیره…'),
            'saved'=>self::t('Traveler saved.','مسافر ذخیره شد.'),'error'=>self::t('Traveler could not be saved.','مسافر ذخیره نشد.'),'retry'=>self::t('Connection interrupted. Retry the same request.','ارتباط قطع شد. همان درخواست را دوباره تلاش کنید.')));
        return '<section><h3>'.esc_html(self::t('Add traveler','افزودن مسافر')).'</h3><p>'.esc_html(self::t('Details are self-declared; identity is not verified.','اطلاعات به اظهار شما ثبت می‌شود و هویت تأیید نمی‌شود.')).'</p><form data-atlas-traveler-form><label>'.esc_html(self::t('Name','نام')).'<input name="name" required maxlength="180"></label><label>'.esc_html(self::t('Birth date','تاریخ تولد')).'<input name="birth_date" type="date" required></label><label>'.esc_html(self::t('Guardian name, if required','نام سرپرست، در صورت نیاز')).'<input name="guardian_name" maxlength="180"></label><label><input name="guardian_attested" type="checkbox">'.esc_html(self::t('I attest to the guardian information.','اطلاعات سرپرست را تأیید می‌کنم.')).'</label><button>'.esc_html(self::t('Save traveler','ذخیره مسافر')).'</button><p role="status" aria-live="polite"></p></form></section>';
    }
    private static function dashboard($customer=false) {
        if (!is_user_logged_in()) {
            if ($customer && shortcode_exists('woocommerce_my_account')) { self::$rendering_auth=true; try { return do_shortcode('[woocommerce_my_account]'); } finally { self::$rendering_auth=false; } }
            return '<p><a href="'.esc_url(wp_login_url(get_permalink())).'">'.esc_html(self::t('Sign in to continue.','برای ادامه وارد شوید.')).'</a></p>';
        }
        wp_enqueue_script('atlas-workspace',plugins_url('assets/workspace.js',__FILE__),[],ATLAS_CORE_VERSION,true);
        wp_enqueue_script('atlas-itinerary',plugins_url('assets/itinerary.js',__FILE__),array(),'0.1.0',true);
        $schemas=atlas_operation_schemas(); $html='<section class="atlas-dashboard"><h2>'.esc_html($customer?self::t('Reservations and requests','رزروها و درخواست‌ها'):self::t('Operations','عملیات')).'</h2>';
        if (isset($_GET['atlas_saved'])) $html.='<p role="status">'.esc_html(self::t('Saved as draft.','پیش‌نویس ذخیره شد.')).'</p>';
        if (!$customer) { $html.=Atlas_Partner_Workflow::panel(); $html.=Atlas_Booking_Queue::panel(); $html.=Atlas_Finance_UI::panel(); $html.=self::offer_admin(); $html.=self::invited_list(); $html.=self::inventory_panel(); $html.=self::checkin_panel(); $html.=Atlas_Support_Workflow::queue(); } else {
            $html.=Atlas_Reservation_Status::customer_panel();
            $html.=self::traveler_form();
            wp_enqueue_script('atlas-materials',plugins_url('assets/materials.js',__FILE__),array(),'0.1.0',true);
            wp_localize_script('atlas-materials','atlasMaterials',array('base'=>esc_url_raw(rest_url('agency/v1/cohorts/')),'nonce'=>wp_create_nonce('wp_rest'),'language'=>self::language(),'loading'=>self::t('Loading…','در حال دریافت…'),'unavailable'=>self::t('Materials are available after enrollment is confirmed.','محتوا پس از تأیید ثبت‌نام در دسترس است.'),'empty'=>self::t('No materials have been published.','محتوایی منتشر نشده است.')));
        }
        foreach ($schemas as $type=>$schema) {
            if ($customer && !in_array($type,self::CUSTOMER,true)) continue;
            if (!$customer && in_array($type,self::CUSTOMER,true) && !($type==='ticket_request' && Auth::agency())) continue;
            if (is_wp_error(self::authorize_type($type))) continue;
            $names=array('stay_request'=>array('Stay request','درخواست اقامت'),'ticket_request'=>array('Passenger ticket request','درخواست بلیت مسافر'),'transfer_request'=>array('Transfer request','درخواست ترانسفر'),'rental_request'=>array('Vehicle rental request','درخواست اجاره خودرو'),'support_case'=>array('Support case','درخواست پشتیبانی'),'enrollment'=>array('My enrollments','ثبت‌نام‌های من'),'partner_application'=>array('Apply as a partner','درخواست همکاری'));
            $label=isset($names[$type]) ? self::t($names[$type][0],$names[$type][1]) : ucwords(str_replace('_',' ',$type));
            $opened=$customer && (($_GET['atlas_task']??'')===$type
                || ($type==='support_case' && isset($_GET['support_cases_page'])));
            $html.='<details id="atlas-task-'.esc_attr($type).'"'.($opened?' open':'').'><summary>'.esc_html($label).'</summary>';
            if ($type==='ticket_request') $html.='<p>'.esc_html(self::t('Ask about a flight, train or bus. Availability must be confirmed by the agency. Submit service needs without identity or medical documents.','درباره پرواز، قطار یا اتوبوس درخواست بفرستید. موجودی باید توسط آژانس تأیید شود. نیازهای سفر را بدون اسناد هویتی یا پزشکی وارد کنید.')).'</p>';
            $cases=null;
            if ($type==='support_case') {
                $page=isset($_GET['support_cases_page']) && is_scalar($_GET['support_cases_page'])?max(1,absint($_GET['support_cases_page'])):1;
                $cases=Atlas_Support_Workflow::cases($page);
                $records=is_wp_error($cases)?$cases:$cases['items'];
            } else $records=Records::query($type,array('limit'=>25),get_current_user_id());
            if (is_wp_error($records)) $html.='<p>'.esc_html($records->get_error_message()).'</p>';
            elseif (!$records) $html.='<p>'.esc_html(self::t('No records yet.','هنوز موردی وجود ندارد.')).'</p>';
            else { $html.='<ul>'; foreach ($records as $r) { $f=$r['fields']; $name=$f['title_en']??$f['title']??$f['subject']??$f['name_en']??$r['id'];
                    $state=$r['state'];
                    if ($type==='ticket_request') { $name=($f['origin']??'').' → '.($f['destination']??'').' · '.($f['travel_date']??''); $labels=array('draft'=>self::t('Draft','پیش‌نویس'),'submitted'=>self::t('Awaiting review','در انتظار بررسی'),'approved'=>self::t('Reviewed inquiry','درخواست بررسی‌شده'),'rejected'=>self::t('Unavailable','در دسترس نیست'),'closed'=>self::t('Closed','بسته')); $state=$labels[$state]??$state; }
                    $html.='<li>'.esc_html($name).' — '.esc_html($state).' <code>'.esc_html($r['id']).'</code>';
                    if ($type==='ticket_request' && !$customer && Auth::agency()) $html.='<p>'.esc_html(($f['transport_mode']??'').' · '.($f['passengers']??'').' · '.($f['contact']??'').' · '.($f['accessibility']??'')).'</p>';
                    $actions=in_array($type,array('enrollment','support_case'),true)?array():($r['state']==='draft'?array('submitted'):($r['state']==='submitted' && Auth::agency() && $type!=='partner_application'?array('approved','rejected'):($r['state']==='approved' && Auth::agency() && $type!=='partner_application'?array('closed'):array())));
                    foreach ($actions as $target) { $html.='<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('atlas_operation_transition','_wpnonce',true,false).'<input type="hidden" name="action" value="atlas_operation_transition"><input type="hidden" name="record_type" value="'.esc_attr($type).'"><input type="hidden" name="record_id" value="'.esc_attr($r['id']).'"><input type="hidden" name="version" value="'.esc_attr($r['version']).'"><input type="hidden" name="target" value="'.esc_attr($target).'"><button type="submit">'.esc_html($target==='submitted'?self::t('Submit','ارسال'):($target==='approved'?self::t('Approve','تأیید'):($target==='closed'?self::t('Close','بستن'):self::t('Reject','رد')))).'</button></form>'; }
                    if ($type==='enrollment' && !empty($f['cohort_id'])) $html.='<button type="button" data-atlas-materials="'.esc_attr($f['cohort_id']).'">'.esc_html(self::t('View course materials','مشاهده محتوای دوره')).'</button><div data-materials-output aria-live="polite"></div>';
                    if ($type==='support_case') $html.='<p>'.nl2br(esc_html($f['message'])).'</p>'.Atlas_Support_Workflow::panel($r);
                    if ($type==='production' && $r['state']==='approved' && Auth::agency()) {
                        $html.='<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('atlas_operation_edit','_wpnonce',true,false).'<input type="hidden" name="action" value="atlas_operation_edit"><input type="hidden" name="record_type" value="production"><input type="hidden" name="record_id" value="'.esc_attr($r['id']).'"><input type="hidden" name="version" value="'.esc_attr($r['version']).'">';
                        foreach (array('shipment_ref'=>'Shipment reference','inspection'=>'Inspection outcome','invoice_ref'=>'Invoice reference','payout_evidence'=>'Manual payout evidence reference') as $field=>$label) $html.='<label>'.esc_html($label).'<input name="fields['.esc_attr($field).']" value="'.esc_attr($f[$field]??'').'"></label>';
                        $html.='<button>'.esc_html(self::t('Save evidence','ذخیره شواهد')).'</button></form>';
                    }
                    $html.='</li>'; } $html.='</ul>'; }
            if (is_array($cases)) $html.=Atlas_Support_Workflow::case_links($cases,'support_cases_page');
            if ($type==='enrollment' || (!$customer && $type==='ticket_request') || self::protected_procurement($type)) { $html.='</details>'; continue; }
            $html.='<form method="post" '.($type==='tour_version'?'data-atlas-tour-builder':'').' action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="atlas_operation_save"><input type="hidden" name="record_type" value="'.esc_attr($type).'"><input type="hidden" name="idempotency_key" value="'.esc_attr(wp_generate_uuid4()).'">'.wp_nonce_field('atlas_operation_save','_wpnonce',true,false);
            if (!in_array($type,self::CUSTOMER,true)) $html.='<label>'.esc_html(self::t('Organization ID (leave blank for agency)','شناسه سازمان (برای آژانس خالی بگذارید)')).'<input type="number" min="0" name="fields[org_id]"></label>';
            foreach ($schema['fields'] as $name=>$field) {
                if ($name==='state') continue; $id='atlas-'.esc_attr($type.'-'.$name); $field_names=array('pickup'=>array('Pickup','مبدأ'),'dropoff'=>array('Drop-off','مقصد'),'pickup_at'=>array('Pickup time (UTC, ISO 8601)','زمان مبدأ (UTC، ISO 8601)'),'dropoff_at'=>array('Drop-off time (UTC, ISO 8601)','زمان مقصد (UTC، ISO 8601)'),'pickup_zone'=>array('Pickup IANA time zone','منطقه زمانی مبدأ (IANA)'),'dropoff_zone'=>array('Drop-off IANA time zone','منطقه زمانی مقصد (IANA)'),'passengers'=>array('Passengers','تعداد مسافران'),'luggage'=>array('Bags','تعداد بار'),'vehicle_class'=>array('Vehicle class: car, van or accessible','نوع خودرو: car، van یا accessible'),'contact'=>array('Contact','راه تماس'),'subject'=>array('Subject','موضوع'),'message'=>array('Message','پیام'),'category'=>array('Category','دسته‌بندی'),'guests'=>array('Guests','تعداد مهمانان'),'check_in'=>array('Check-in date (YYYY-MM-DD)','تاریخ ورود (YYYY-MM-DD)'),'check_out'=>array('Check-out date (YYYY-MM-DD)','تاریخ خروج (YYYY-MM-DD)'));
                if ($type==='support_case' && !in_array($name,array('category','subject','message','booking_id'),true)) continue;
                if ($type==='partner_application' && !in_array($name,array('organization_name','contact','partner_types','markets'),true)) continue;
                if ($type==='ticket_request') $field_names+=array('origin'=>array('Departure location','مبدأ'),'destination'=>array('Arrival location','مقصد'),'travel_date'=>array('Local travel date','تاریخ محلی سفر'),'transport_mode'=>array('Mode','شیوه سفر'),'origin_zone'=>array('Departure time zone (IANA)','منطقه زمانی مبدأ (IANA)'),'destination_zone'=>array('Arrival time zone (IANA)','منطقه زمانی مقصد (IANA)'),'fare_class'=>array('Fare preference','ترجیح کلاس سفر'),'baggage'=>array('Bags','تعداد بار'),'accessibility'=>array('Practical accessibility needs','نیازهای عملی دسترس‌پذیری'));
                $label=isset($field_names[$name])?self::t($field_names[$name][0],$field_names[$name][1]):ucwords(str_replace('_',' ',$name)); $required=!empty($field['required'])?' required':'';
                $html.='<label for="'.$id.'">'.esc_html($label).'</label>';
                if ($type==='ticket_request' && isset($field['enum'])) { $choices=array('flight'=>self::t('Flight','پرواز'),'rail'=>self::t('Rail','قطار'),'bus'=>self::t('Bus','اتوبوس'),'standard'=>self::t('Standard','معمولی'),'premium'=>self::t('Premium','ویژه'),'flexible'=>self::t('Flexible','انعطاف‌پذیر'),'any'=>self::t('Any','همه')); $preferred=$opened && $name==='transport_mode' ? ($_GET['atlas_service']??'') : '';
                    $html.='<select id="'.$id.'" name="fields['.esc_attr($name).']"'.$required.'><option value="">—</option>'; foreach($field['enum'] as $value) $html.='<option value="'.esc_attr($value).'"'.selected($preferred===$value,true,false).'>'.esc_html($choices[$value]).'</option>'; $html.='</select>'; }
                elseif ($field['type']==='array') $html.='<textarea id="'.$id.'" name="fields['.esc_attr($name).']" '.($type==='tour_version' && in_array($name,array('stops','legs','days'),true)?'data-atlas-structure="'.esc_attr($name).'"':'').' placeholder="[]"></textarea>';
                elseif ($field['type']==='bool') $html.='<select id="'.$id.'" name="fields['.esc_attr($name).']"><option value="">—</option><option value="1">Yes / بله</option><option value="0">No / خیر</option></select>';
                elseif (in_array($name,['message','notes'],true)) $html.='<textarea id="'.$id.'" name="fields['.esc_attr($name).']" rows="4"'.$required.'></textarea>';
                else {
                    $input_type=in_array($name,['check_in','check_out','travel_date'],true)?'date':($field['type']==='int'?'number':'text');
                    $html.='<input id="'.$id.'" name="fields['.esc_attr($name).']" type="'.$input_type.'"'.$required.'>';
                }
            }
            $html.='<button type="submit">'.esc_html(self::t('Save draft','ذخیره پیش‌نویس')).'</button></form></details>';
        }
        return apply_filters('atlas_operations_dashboard',$html,$customer).'</section>';
    }
    public static function portal() { return self::dashboard(false); }
    public static function account() { return self::dashboard(true); }
    private static function auth_intent_url() {
        $fallback=add_query_arg(array('atlas_area'=>'account','lang'=>self::language()),home_url('/'));
        $task_url=static function($query) use ($fallback) {
            $task=$query['atlas_task']??'';
            if (!in_array($task,['support_case','ticket_request','stay_request','transfer_request','rental_request'],true)) return $fallback;
            $args=['atlas_task'=>$task];
            $service=$query['atlas_service']??'';
            if ($task==='ticket_request' && in_array($service,['flight','rail','bus'],true)) $args['atlas_service']=$service;
            return add_query_arg($args,$fallback).'#atlas-task-'.$task;
        };
        $candidate=isset($_REQUEST['atlas_return']) && is_string($_REQUEST['atlas_return']) ? wp_unslash($_REQUEST['atlas_return']) : '';
        if (!$candidate) return $task_url($_GET);
        $parts=wp_parse_url($candidate); $home=wp_parse_url(home_url('/'));
        if (!$parts || !$home || ($parts['scheme']??'')!==($home['scheme']??'') || strtolower($parts['host']??'')!==strtolower($home['host']??'') || ($parts['port']??null)!==($home['port']??null)) return $fallback;
        parse_str($parts['query']??'',$query); $offer_id=isset($query['offer_id']) && is_scalar($query['offer_id'])?absint($query['offer_id']):0;
        if (!$offer_id && ($query['atlas_area']??'')==='account') return $task_url($query);
        if (!$offer_id || is_wp_error(Offers::get($offer_id,true))) return $fallback;
        return self::offer_url($offer_id);
    }
    public static function auth_intent_field() {
        if (self::$rendering_auth || isset($_REQUEST['atlas_return'])) echo '<input type="hidden" name="atlas_return" value="'.esc_attr(self::auth_intent_url()).'">';
    }
    public static function auth_redirect($redirect,$user=null) { return isset($_REQUEST['atlas_return']) ? self::auth_intent_url() : $redirect; }
}
