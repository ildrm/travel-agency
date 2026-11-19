<?php
/** Reviewed local allotments; never claims a live channel, deposit instrument or automatic supplier acceptance. */
if (!defined('ABSPATH')) exit;
use Atlas\Core\Auth;
use Atlas\Core\DB;
use Atlas\Core\Offers;
use Atlas\Core\Records;

final class Atlas_Resource_Booking {
    public static function enabled() { return (defined('ATLAS_RESOURCE_BOOKING_ENABLED') && ATLAS_RESOURCE_BOOKING_ENABLED===true) || (wp_get_environment_type()==='local' && defined('ATLAS_DEMO_MODE') && ATLAS_DEMO_MODE===true); }
    private static function t($en,$fa) { $lang=$_GET['lang']??''; return (in_array($lang,array('en','fa'),true)?$lang==='fa':str_starts_with(determine_locale(),'fa'))?$fa:$en; }
    private static function error($code,$en,$fa,$status=409) { return new WP_Error('atlas_resource_'.$code,self::t($en,$fa),array('status'=>$status)); }
    private static function table($name) { global $wpdb; if (!in_array($name,array('resources','nights','allocations'),true)) throw new InvalidArgumentException('Inventory table'); return $wpdb->prefix.'atlas_local_'.$name; }
    private static function uuid($seed) { $h=hash('sha256',$seed); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12); }
    private static function data($row) { return $row ? (json_decode($row->payload,true) ?: array()) : array(); }
    private static function row($type,$id,$lock=false) { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('records').' WHERE type=%s AND id=%s'.($lock?' FOR UPDATE':''),$type,$id)); }
    private static function rate($offer,$lock=false,$plan='') { $row=self::row('resource_rate',$plan!==''?$plan:self::uuid('resource-rate:'.$offer),$lock); return $row && (int)(self::data($row)['offer_id']??0)===(int)$offer?$row:null; }
    private static function rates($offer,$lock=false) {
        global $wpdb; $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.DB::table('records')." WHERE type=%s AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.offer_id')) AS UNSIGNED)=%d ORDER BY id LIMIT 51".($lock?' FOR UPDATE':''),'resource_rate',$offer));
        if (!is_array($rows) || $wpdb->last_error!=='' || count($rows)>50) return self::error('rate_storage','Rate plans could not be read safely; agency review is required.','خواندن امن طرح‌های قیمت ممکن نیست؛ بررسی آژانس لازم است.',503);
        return $rows;
    }
    private static function active_rates($offer,$lock=false) {
        $rows=self::rates($offer,$lock); if (is_wp_error($rows)) return $rows;
        $rows=array_filter($rows,static function($row) { $f=self::data($row); return $row->state==='published' && self::instant($f['valid_until']??null) && strtotime($f['valid_until'])>strtotime(DB::now()); });
        usort($rows,static function($a,$b) { $price=(int)self::data($a)['unit_amount_irr']<=>(int)self::data($b)['unit_amount_irr']; return $price?:strcmp($a->id,$b->id); }); return $rows;
    }
    private static function plan_argument($value) { return $value===null || $value==='' ? '' : (is_string($value) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$value)?$value:self::error('plan','Invalid rate-plan reference.','شناسه طرح قیمت نامعتبر است.',422)); }
    private static function record($row) { return array('id'=>$row->id,'version'=>(int)$row->version,'state'=>$row->state,'fields'=>self::data($row)); }
    private static function text($value,$max=1000) { return is_string($value) && trim($value)!=='' && strlen($value)<=$max && sanitize_textarea_field($value)===$value; }
    private static function date($value) { if (!is_string($value)) return false; $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC')); return $d && $d->format('Y-m-d')===$value ? $d : false; }
    private static function instant($value) { if (!is_string($value)) return false; $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new DateTimeZone('UTC')); return $d && $d->format('Y-m-d\TH:i:s\Z')===$value ? $d : false; }
    private static function sql($result) { if ($result===false) throw new RuntimeException('resource storage'); return $result; }
    public static function register() {
        foreach (array('resource_rate','resource_reservation','resource_cancellation') as $type) Records::register_type($type,array('fields'=>array('data'=>array('type'=>'array','required'=>true)),'capability'=>'atlas_manage_bookings','organization_roles'=>array('read'=>array(),'write'=>array()),'initial_states'=>array(),'transitions'=>array('state'=>array())));
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_filter('atlas_core_validate_resource_publication',array(__CLASS__,'publication'),10,4);
        add_filter('atlas_core_validate_resource_hold',array(__CLASS__,'hold_allowed'),10,6);
        add_filter('atlas_core_prepare_hold',array(__CLASS__,'prepare'),30,7);
        add_filter('atlas_core_hold_created',array(__CLASS__,'created'),30,9);
        add_filter('atlas_core_hold_released',array(__CLASS__,'released'),30,3);
        add_filter('atlas_core_hold_consumed',array(__CLASS__,'consumed'),30,3);
        add_filter('atlas_core_before_confirmation',array(__CLASS__,'confirmation'),30,4);
        add_filter('atlas_catalog_availability',array(__CLASS__,'catalog_availability'),30,5);
        add_filter('atlas_catalog_item',array(__CLASS__,'catalog_item'),30,3);
        add_filter('atlas_catalog_public_where',array(__CLASS__,'catalog_where'),30,2);
        add_filter('atlas_operations_booking_fields',array(__CLASS__,'booking_fields'),30,2);
        add_filter('atlas_operations_offer',array(__CLASS__,'offer_details'),30,2);
        add_filter('atlas_operations_dashboard',static function($html,$customer) { return $html.self::panel($customer); },30,2);
        add_filter('atlas_notification_events',array(__CLASS__,'notification_events'));
        add_filter('atlas_notification_recipients',array(__CLASS__,'notification_recipients'),25,2);
    }
    private static function ready() { return self::enabled() && class_exists('Atlas\Inventory\Local') && get_option('atlas_local_inventory_schema')==='3'; }
    public static function private_permission() { return self::ready() && is_user_logged_in() ? true : self::error('unavailable','Resource workspace unavailable.','فضای مدیریت منابع در دسترس نیست.',403); }
    public static function routes() {
        register_rest_route('agency/v1','/resource-offers/(?P<id>\d+)',array(array('methods'=>'GET','permission_callback'=>'__return_true','callback'=>array(__CLASS__,'public_rate')),array('methods'=>'POST','permission_callback'=>array(__CLASS__,'private_permission'),'callback'=>array(__CLASS__,'save_rate'))));
        register_rest_route('agency/v1','/resource-offers/(?P<id>\d+)/activate',array('methods'=>'POST','permission_callback'=>array(__CLASS__,'private_permission'),'callback'=>array(__CLASS__,'activate')));
        register_rest_route('agency/v1','/resource-offers/(?P<id>\d+)/review',array('methods'=>'GET','permission_callback'=>array(__CLASS__,'private_permission'),'callback'=>array(__CLASS__,'review_rate')));
        register_rest_route('agency/v1','/resource-offers/(?P<id>\d+)/availability',array('methods'=>'GET','permission_callback'=>'__return_true','callback'=>array(__CLASS__,'availability_request')));
        register_rest_route('agency/v1','/resource-resources/(?P<id>[a-f0-9-]{36})/reviewers',array('methods'=>'GET','permission_callback'=>array(__CLASS__,'private_permission'),'callback'=>array(__CLASS__,'reviewers')));
        register_rest_route('agency/v1','/resource-bookings',array('methods'=>'GET','permission_callback'=>array(__CLASS__,'private_permission'),'callback'=>array(__CLASS__,'queue')));
        register_rest_route('agency/v1','/resource-bookings/(?P<id>[a-f0-9-]{36})/supplier-review',array('methods'=>'POST','permission_callback'=>array(__CLASS__,'private_permission'),'callback'=>array(__CLASS__,'supplier_review')));
        register_rest_route('agency/v1','/resource-bookings/(?P<id>[a-f0-9-]{36})/cancel-request',array('methods'=>'POST','permission_callback'=>array(__CLASS__,'private_permission'),'callback'=>array(__CLASS__,'cancel_request')));
        register_rest_route('agency/v1','/resource-bookings/(?P<id>[a-f0-9-]{36})/cancel-review',array('methods'=>'POST','permission_callback'=>array(__CLASS__,'private_permission'),'callback'=>array(__CLASS__,'cancel_review')));
    }
    private static function body($request,$keys) { $b=$request->get_json_params(); return is_array($b) && !array_diff(array_keys($b),$keys) ? $b : self::error('input','Invalid resource request.','درخواست منبع نامعتبر است.',422); }
    /** Public callers see only published source records, never arbitrary customer/org records. */
    private static function source($resource,$lock=false) {
        global $wpdb;
        $r=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('resources').' WHERE id=%s'.($lock?' FOR UPDATE':''),$resource));
        if (!$r) return self::error('unavailable','Reviewed resource unavailable.','منبع بازبینی‌شده در دسترس نیست.');
        $source=self::row($r->record_type,$r->record_id,$lock); $f=self::data($source);
        $org=$wpdb->get_var($wpdb->prepare('SELECT status FROM '.DB::table('organizations').' WHERE id=%d',(int)$r->org_id));
        if (!$source || $source->state!=='approved' || (int)$source->org_id!==(int)$r->org_id || !in_array($org,array('active','approved'),true)) return self::error('unavailable','The resource requires agency review.','منبع به بررسی آژانس نیاز دارد.');
        if ($r->kind==='stay') {
            $property=self::row('property',$r->property_id,$lock); $p=self::data($property);
            if ($r->record_type!=='unit_type' || !$property || $property->state!=='approved' || (int)$property->org_id!==(int)$r->org_id || ($f['property_id']??'')!==$r->property_id || ($p['timezone']??'')!==$r->timezone || (int)!empty($f['whole_property'])!==(int)$r->whole_property || (int)($f['units']??0)<(int)$r->capacity || (int)($f['capacity']??0)<1 || ((int)$r->whole_property===1 && (int)$r->capacity!==1)) return self::error('changed','Property inventory changed and needs reconciliation.','موجودی ملک تغییر کرده و نیازمند تطبیق است.');
            $r->occupancy=(int)$f['capacity'];
        } elseif ($r->kind==='vehicle') {
            if ($r->record_type!=='vehicle' || !in_array($r->vehicle_kind,array('bicycle','motorcycle','car','van'),true) || ($r->vehicle_kind!=='bicycle' && $r->license_class==='') || ($f['category']??'')!==$r->vehicle_kind || trim((string)($f['license_class']??''))!==$r->license_class) return self::error('changed','Vehicle classification changed and needs review.','نوع وسیله تغییر کرده و به بررسی نیاز دارد.');
            $policy=json_decode((string)$r->rental_policy,true);
            if (!is_array($policy) || !isset($policy['minimum_age'],$policy['coverage_reference'],$policy['policy_reference']) || !is_int($policy['minimum_age']) || $policy['minimum_age']<0 || !self::text($policy['coverage_reference'],180) || !self::text($policy['policy_reference'],180) || (int)$r->policy_reviewed_by<1 || !self::instant($policy['valid_until']??null) || strtotime($policy['valid_until'])<=strtotime(DB::now())) return self::error('policy','A current reviewed rental and coverage policy is required.','سیاست اجاره و پوشش بازبینی‌شده و معتبر لازم است.');
            $r->policy=$policy;
        } else return self::error('kind','Unsupported resource.','منبع پشتیبانی نمی‌شود.');
        return $r;
    }
    private static function current($offer,$lock=false,$plan='') {
        $rows=$plan!==''?array(self::rate($offer,$lock,$plan)):self::active_rates($offer,$lock); if (is_wp_error($rows)) return $rows; $row=$rows?reset($rows):null; $f=self::data($row);
        if (!self::ready() || !$row || $row->state!=='published' || !self::instant($f['valid_until']??null) || strtotime($f['valid_until'])<=strtotime(DB::now())) return self::error('quote_expired','Reviewed quote expired or unavailable. Request a new quote.','قیمت بازبینی‌شده منقضی یا ناموجود است. قیمت جدید درخواست کنید.');
        return $row;
    }
    public static function publication($valid,$offer,$id,$actor=0) {
        if (!in_array($offer['kind']??'',array('stay','rental'),true)) return $valid;
        // Suspension is a safe recovery state even when the quote/source expired.
        if (($offer['status']??'')==='suspended' && $id>0 && Auth::actor((int)$actor) && Auth::agency()) return true;
        $rate=self::current((int)$id); if (is_wp_error($rate)) return false;
        $f=self::data($rate); $r=self::source($f['resource_id']);
        return !is_wp_error($r) && (int)$r->org_id===(int)($offer['org_id']??0) && $f['kind']===$offer['kind'];
    }
    public static function hold_allowed($valid,$offer,$qty,$user,$options,$now) { return self::publication($valid,$offer,(int)($offer['id']??0),$user); }
    public static function save_rate($request) {
        if (!Auth::agency() || !current_user_can('atlas_manage_bookings')) return self::error('forbidden','Agency resource review required.','بررسی منابع توسط آژانس لازم است.',403);
        $b=self::body($request,array('plan_id','name_en','name_fa','max_guests_per_unit','version','resource_id','start_date','end_date','timezone','valid_until','unit_amount_irr','tax_per_unit_irr','protection_irr','min_duration','max_duration','terms_en','terms_fa','policy_reference','supplier_reference','supplier_users','published','reason'));
        if (is_wp_error($b)) return $b;
        foreach (array('version','unit_amount_irr','tax_per_unit_irr','protection_irr','min_duration','max_duration') as $key) if (!is_int($b[$key]??null) || $b[$key]<0) return self::error('input','Enter nonnegative integer prices and durations.','قیمت و مدت را به‌صورت عدد صحیح نامنفی وارد کنید.',422);
        if (!is_string($b['resource_id']??null) || !preg_match('/^[a-f0-9-]{36}$/',$b['resource_id']) || !self::date($b['start_date']??null) || !self::date($b['end_date']??null) || $b['end_date']<=$b['start_date'] || !in_array($b['timezone']??null,timezone_identifiers_list(),true) || !self::instant($b['valid_until']??null) || strtotime($b['valid_until'])<=strtotime(DB::now()) || $b['min_duration']<1 || $b['max_duration']<$b['min_duration'] || $b['max_duration']>365 || !self::text($b['terms_en']??null,4000) || !self::text($b['terms_fa']??null,4000) || !self::text($b['policy_reference']??null,180) || !self::text($b['supplier_reference']??null,180) || !self::text($b['reason']??null) || !is_bool($b['published']??null) || !is_array($b['supplier_users']??null) || !array_is_list($b['supplier_users']) || count($b['supplier_users'])>20) return self::error('input','Complete the reviewed rate, local date period, named zone and bilingual terms.','قیمت بازبینی‌شده، بازه تاریخ محلی، منطقه زمانی و شرایط دوزبانه را کامل کنید.',422);
        $offer_id=(int)$request['id']; $offer=Offers::get($offer_id,false);
        if (is_wp_error($offer) || !in_array($offer['kind'],array('stay','rental'),true)) return self::error('offer','Select an existing stay or rental offer.','یک پیشنهاد اقامت یا اجاره موجود انتخاب کنید.',422);
        $plan=$b['plan_id']??self::uuid('resource-rate:'.$offer_id);
        if ($plan==='' || is_wp_error(self::plan_argument($plan)) || (isset($b['max_guests_per_unit']) && (!is_int($b['max_guests_per_unit']) || $b['max_guests_per_unit']<1)) || (isset($b['name_en']) && !self::text($b['name_en'],180)) || (isset($b['name_fa']) && !self::text($b['name_fa'],180)) || ($plan!==self::uuid('resource-rate:'.$offer_id) && (!self::text($b['name_en']??null,180) || !self::text($b['name_fa']??null,180)))) return self::error('plan','A valid named bilingual rate plan and occupancy are required.','طرح قیمت معتبر با نام دوزبانه و ظرفیت مهمان لازم است.',422);
        global $wpdb;
        try {
            self::sql($wpdb->query('START TRANSACTION'));
            $locked_offer=$wpdb->get_row($wpdb->prepare('SELECT offer_snapshot FROM '.DB::table('inventory').' WHERE offer_id=%d FOR UPDATE',$offer_id));
            $current_offer=$locked_offer ? json_decode($locked_offer->offer_snapshot,true) : null;
            $all=self::rates($offer_id,true); if (is_wp_error($all)) { $wpdb->query('ROLLBACK'); return $all; }
            $old=self::row('resource_rate',$plan,true); if ($wpdb->last_error!=='') throw new RuntimeException('rate read'); $resource=self::source($b['resource_id'],true);
            if (is_wp_error($resource)) { $wpdb->query('ROLLBACK'); return $resource; }
            if (($old && (int)(self::data($old)['offer_id']??0)!==$offer_id) || (!$old && count($all)>=50)) { $wpdb->query('ROLLBACK'); return self::error('plan','Rate plan belongs to another offer or the review limit was reached.','طرح قیمت متعلق به پیشنهاد دیگری است یا سقف بررسی تکمیل شده است.',409); }
            if (($old ? (int)$old->version : 0)!==$b['version']) { $wpdb->query('ROLLBACK'); return self::error('version','Rate changed. Reload before reviewing.','قیمت تغییر کرده است. پیش از بررسی دوباره دریافت کنید.'); }
            if (!$current_offer || ($current_offer['mode']==='local' && $current_offer['status']==='published')) { $wpdb->query('ROLLBACK'); return self::error('rate_active','Suspend the public offer before reviewing any new or replacement rate; accepted booking terms remain preserved.','پیش از بررسی قیمت جدید یا جایگزین، پیشنهاد عمومی را تعلیق کنید؛ شرایط رزروهای پذیرفته‌شده حفظ می‌شود.'); }
            foreach ($all as $other) if ($other->id!==$plan && (self::data($other)['resource_id']??'')!==$b['resource_id']) { $wpdb->query('ROLLBACK'); return self::error('plan_resource','All plans on one offer must share one physical resource; create another offer for another unit.','طرح‌های یک پیشنهاد باید منبع فیزیکی مشترک داشته باشند؛ برای واحد دیگر پیشنهاد تازه بسازید.',422); }
            $max_guests=$b['max_guests_per_unit']??($offer['kind']==='stay'?$resource->occupancy:1);
            if (($offer['kind']==='stay' && $max_guests>$resource->occupancy) || ($offer['kind']==='rental' && $max_guests!==1)) { $wpdb->query('ROLLBACK'); return self::error('occupancy','Plan occupancy exceeds its physical unit.','ظرفیت مهمان طرح از ظرفیت واحد فیزیکی بیشتر است.',422); }
            if ((int)$resource->org_id!==(int)$offer['org_id'] || ($offer['kind']==='stay' && $resource->kind!=='stay') || ($offer['kind']==='rental' && $resource->kind!=='vehicle') || ($offer['kind']==='stay' && ($resource->timezone!==$b['timezone'] || $b['min_duration']<(int)$resource->min_nights || $b['protection_irr']!==0))) { $wpdb->query('ROLLBACK'); return self::error('mismatch','Rate, resource organization and policies do not match.','قیمت، سازمان منبع و سیاست‌ها همخوان نیستند.',422); }
            foreach ($b['supplier_users'] as $user) if (!is_int($user) || !Auth::member((int)$resource->org_id,$user) || !user_can($user,'atlas_manage_records') || Auth::member_role((int)$resource->org_id,$user)==='viewer') { $wpdb->query('ROLLBACK'); return self::error('supplier','Supplier reviewers must be active authorized members of this resource organization.','بازبینان تأمین‌کننده باید اعضای فعال و مجاز سازمان همین منبع باشند.',422); }
            $fields=$b; unset($fields['version'],$fields['published'],$fields['plan_id']); $fields['name_en']=$b['name_en']??'Reviewed standard rate'; $fields['name_fa']=$b['name_fa']??'قیمت استاندارد بازبینی‌شده'; $fields['max_guests_per_unit']=$max_guests; $fields['offer_id']=$offer_id; $fields['kind']=$offer['kind']; $fields['reviewed_by']=get_current_user_id(); $fields['reviewed_at']=DB::now(); $fields['deposit_instrument']='none'; $fields['billing_unit']=$offer['kind']==='stay'?'local_night':'started_24_hours';
            $id=$plan; $now=DB::now(); $values=array('state'=>$b['published']?'published':'draft','payload'=>wp_json_encode($fields),'version'=>$old ? (int)$old->version+1 : 1,'updated_at'=>$now);
            self::sql($old ? $wpdb->update(DB::table('records'),$values,array('id'=>$id,'version'=>(int)$old->version)) : $wpdb->insert(DB::table('records'),array_merge($values,array('id'=>$id,'type'=>'resource_rate','org_id'=>(int)$resource->org_id,'owner_id'=>(int)$offer['org_id'] ? (int)get_post_field('post_author',$offer_id) : get_current_user_id(),'created_at'=>$now))));
            if (!DB::audit(get_current_user_id(),(int)$resource->org_id,'resource.rate_review',$id,'success',$b['reason'])) throw new RuntimeException('audit');
            self::sql($wpdb->query('COMMIT')); return self::record(self::rate($offer_id,false,$plan));
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); return self::error('storage','Rate review could not be saved.','بررسی قیمت ثبت نشد.',503); }
    }
    public static function activate($request) {
        if (!Auth::agency() || !current_user_can('atlas_manage_bookings')) return self::error('forbidden','Agency review required.','بررسی آژانس لازم است.',403);
        $b=self::body($request,array('version','product_id')); if (is_wp_error($b)) return $b;
        $id=(int)$request['id']; $offer=Offers::get($id,false);
        if (is_wp_error($offer) || !is_int($b['version']??null) || !is_int($b['product_id']??null) || $b['product_id']<1 || !function_exists('wc_get_product') || !wc_get_product($b['product_id']) || (int)get_post_field('post_author',$id)===get_current_user_id()) return self::error('review','An independent agency reviewer and linked WooCommerce product are required.','بازبین مستقل آژانس و محصول مرتبط ووکامرس لازم است.',422);
        $rate=self::current($id); if (is_wp_error($rate)) return $rate; $f=self::data($rate); $catalog=$offer['catalog']??array();
        unset($catalog['_search'],$catalog['_destinations'],$catalog['updated_at']);
        $catalog['price_unit']=$offer['kind']==='stay'?'per_night':(in_array('per_day',Atlas\Core\Catalog::UNITS,true)?'per_day':'per_item'); $catalog['terms_en']=$f['terms_en']; $catalog['terms_fa']=$f['terms_fa'];
        $saved=Offers::save($id,array('expected_version'=>$b['version'],'mode'=>'local','status'=>'published','product_id'=>$b['product_id'],'amount_irr'=>(int)$f['unit_amount_irr'],'catalog'=>$catalog),get_current_user_id());
        return is_wp_error($saved)?$saved:Offers::get($id,false);
    }
    public static function public_rate($request) {
        $plan=self::plan_argument($request->get_param('rate_plan_id')); if (is_wp_error($plan)) return $plan;
        $id=(int)$request['id']; $offer=Offers::get($id,true); $row=self::current($id,false,$plan);
        if (is_wp_error($offer) || is_wp_error($row)) return self::error('not_found','Reviewed quote unavailable.','قیمت بازبینی‌شده در دسترس نیست.',404);
        $f=self::data($row); $resource=self::source($f['resource_id']); if (is_wp_error($resource)) return $resource;
        $plans=self::active_rates($id); if (is_wp_error($plans)) return $plans;
        $public=self::public_plan($row,$resource); $public['plans']=array_map(static function($item) use($resource) { return array_intersect_key(self::public_plan($item,$resource),array_flip(array('plan_id','version','name_en','name_fa','start_date','end_date','unit_amount_irr','capacity_per_unit'))); },$plans);
        return new WP_REST_Response($public,200,array('Cache-Control'=>'no-store'));
    }
    private static function public_plan($row,$resource) {
        $f=self::data($row); $keys=array('name_en','name_fa','kind','start_date','end_date','timezone','valid_until','unit_amount_irr','tax_per_unit_irr','protection_irr','min_duration','max_duration','terms_en','terms_fa','reviewed_at','billing_unit','deposit_instrument'); $public=array_intersect_key($f,array_flip($keys));
        $public['name_en']=$f['name_en']??'Reviewed standard rate'; $public['name_fa']=$f['name_fa']??'قیمت استاندارد بازبینی‌شده';
        $public['plan_id']=$row->id; $public['version']=(int)$row->version; $public['capacity_per_unit']=$f['kind']==='stay'?min($resource->occupancy,(int)($f['max_guests_per_unit']??$resource->occupancy)):1; $public['availability_source']='agency_reviewed_local_allotment'; $public['supplier_confirmation_required']=true; return $public;
    }
    public static function review_rate($request) {
        if (!Auth::agency() || !current_user_can('atlas_manage_bookings')) return self::error('forbidden','Agency rate review required.','بررسی قیمت توسط آژانس لازم است.',403);
        $plan=self::plan_argument($request->get_param('rate_plan_id')); if (is_wp_error($plan)) return $plan;
        $offer=Offers::get((int)$request['id'],false); if (is_wp_error($offer)) return $offer; $rates=self::rates((int)$request['id']); if (is_wp_error($rates)) return $rates; $rate=self::rate((int)$request['id'],false,$plan); if (!$rate && $plan==='' && $rates) $rate=reset($rates);
        return array('offer'=>$offer,'rate'=>$rate?self::record($rate):null,'plans'=>array_map(static function($item) { $f=self::data($item); return array('id'=>$item->id,'version'=>(int)$item->version,'state'=>$item->state,'name_en'=>$f['name_en']??'Reviewed standard rate','name_fa'=>$f['name_fa']??'قیمت استاندارد بازبینی‌شده'); },$rates),'new_plan_id'=>wp_generate_uuid4());
    }
    public static function reviewers($request) {
        if (!Auth::agency() || !current_user_can('atlas_manage_bookings')) return self::error('forbidden','Agency rate review required.','بررسی قیمت توسط آژانس لازم است.',403);
        $resource=self::source((string)$request['id']); if (is_wp_error($resource)) return $resource;
        global $wpdb; $ids=$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.DB::table('memberships').' WHERE org_id=%d AND status=%s LIMIT 501',(int)$resource->org_id,'active')); $items=array();
        foreach ($ids as $id) if (user_can((int)$id,'atlas_manage_records') && Auth::member((int)$resource->org_id,(int)$id) && Auth::member_role((int)$resource->org_id,(int)$id)!=='viewer') { $user=get_userdata((int)$id); if ($user) $items[]=array('id'=>(int)$id,'name'=>$user->display_name); }
        return array('items'=>$items);
    }
    public static function availability_request($request) {
        $offer=Offers::get((int)$request['id'],true); if (is_wp_error($offer)) return $offer;
        $result=self::dated_availability($offer,$request->get_query_params());
        return is_wp_error($result)?$result:new WP_REST_Response($result,200,array('Cache-Control'=>'no-store'));
    }
    /** Read-only advisory quote; the later shared hold is the sole capacity authority. */
    public static function dated_availability($offer,$filters) {
        $plan=self::plan_argument($filters['rate_plan_id']??null); if (is_wp_error($plan)) return $plan;
        if ($plan!=='') { $row=self::current((int)$offer['id'],false,$plan); return is_wp_error($row)?$row:self::rate_availability($offer,$filters,$row); }
        $rates=self::active_rates((int)$offer['id']); if (is_wp_error($rates)) return $rates; $best=null; $error=null;
        foreach ($rates as $row) { $result=self::rate_availability($offer,$filters,$row); if (is_wp_error($result)) { $error=$result; continue; } if ($best===null || (isset($result['amount_per_unit_irr'],$best['amount_per_unit_irr']) && $result['amount_per_unit_irr']<$best['amount_per_unit_irr'])) $best=$result; }
        return $best??$error??self::error('quote_expired','Reviewed quote expired or unavailable. Request a new quote.','قیمت بازبینی‌شده منقضی یا ناموجود است. قیمت جدید درخواست کنید.');
    }
    private static function rate_availability($offer,$filters,$row) {
        if (!self::ready()) return self::error('unavailable','Resource booking is unavailable.','رزرو منبع در دسترس نیست.');
        $f=self::data($row); $r=self::source($f['resource_id']); if (is_wp_error($r)) return $r;
        if ($f['kind']!==$offer['kind'] || (int)$r->org_id!==(int)$offer['org_id']) return self::error('mismatch','Resource offer needs review.','پیشنهاد منبع نیازمند بررسی است.');
        $base=array('status'=>'dates_required','source'=>'agency_reviewed_local_allotment','remaining'=>null,'checked_at'=>(new DateTimeImmutable(DB::now(),new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),'admission_rechecked_at_hold'=>true,'supplier_confirmation_required'=>true,'rate_plan_id'=>$row->id,'rate_version'=>(int)$row->version);
        if ($offer['mode']!=='local') { $base['status']='on_request'; return $base; }
        $now=new DateTimeImmutable(DB::now(),new DateTimeZone('UTC')); $open=new DateTimeImmutable($offer['registration_open_at']); $close=new DateTimeImmutable($offer['registration_close_at']);
        if ($now<$open || $now>=$close) { $base['status']=$now<$open?'not_open':'closed'; return $base; }
        if ($f['kind']==='stay') {
            if (isset($filters['guests_per_unit']) && ((!is_int($filters['guests_per_unit']) && !(is_string($filters['guests_per_unit']) && ctype_digit($filters['guests_per_unit']))) || (int)$filters['guests_per_unit']<1 || (int)$filters['guests_per_unit']>min($r->occupancy,(int)($f['max_guests_per_unit']??$r->occupancy)))) return self::error('occupancy','Guest count exceeds this reviewed plan.','تعداد مهمان از ظرفیت این طرح بازبینی‌شده بیشتر است.',422);
            if (empty($filters['check_in']) || empty($filters['check_out'])) return $base;
            $start=self::date($filters['check_in']); $end=self::date($filters['check_out']);
            if (!$start || !$end || $end<=$start || $start->format('Y-m-d')<$now->setTimezone(new DateTimeZone($f['timezone']))->format('Y-m-d')) return self::error('dates','Enter ordered future local stay dates.','تاریخ محلی آینده و مرتب برای اقامت وارد کنید.',422);
            $duration=(int)$start->diff($end)->days; $localStart=$start->format('Y-m-d'); $localEnd=$end->format('Y-m-d');
            if ($duration<$f['min_duration'] || $duration>$f['max_duration'] || $localStart<$f['start_date'] || $localEnd>$f['end_date'] || $duration<(int)$r->min_nights) return self::error('rate_period','Dates or duration are outside the reviewed rate period.','تاریخ یا مدت خارج از بازه قیمت بازبینی‌شده است.',422);
            global $wpdb; $night=$wpdb->get_row($wpdb->prepare('SELECT MAX(allocated) AS peak,MAX(blocked) AS blocked FROM '.self::table('nights').' WHERE resource_id=%s AND local_date>=%s AND local_date<%s',$r->id,$localStart,$localEnd));
            if ($wpdb->last_error!=='' || !$night) return self::error('storage','Availability could not be checked.','موجودی بررسی نشد.',503);
            $remaining=(int)$night->blocked?0:max(0,(int)$r->capacity-(int)$night->peak);
        } else {
            if (empty($filters['pickup_local']) || empty($filters['return_local'])) return $base;
            $start=self::local_instant($filters['pickup_local'],$f['timezone'],$filters['pickup_offset']??''); $end=self::local_instant($filters['return_local'],$f['timezone'],$filters['return_offset']??'');
            if (!$start || !$end || $end<=$start || $start<$now) return self::error('time','Enter valid ordered local times and resolve clock-change offsets.','زمان محلی مرتب و معتبر وارد و اختلاف هنگام تغییر ساعت را مشخص کنید.',422);
            $duration=(int)ceil(($end->getTimestamp()-$start->getTimestamp())/86400); $localStart=$start->setTimezone(new DateTimeZone($f['timezone']))->format('Y-m-d'); $localEnd=$end->setTimezone(new DateTimeZone($f['timezone']))->format('Y-m-d');
            if ($duration<$f['min_duration'] || $duration>$f['max_duration'] || $localStart<$f['start_date'] || $localEnd>$f['end_date'] || strtotime($r->policy['valid_until'])<$end->getTimestamp()) return self::error('rate_period','Dates or duration are outside the reviewed rate period or coverage.','تاریخ یا مدت خارج از بازه قیمت یا پوشش بازبینی‌شده است.',422);
            $effective=$end->modify('+'.(int)$r->turnaround_minutes.' minutes')->format('Y-m-d\TH:i:s\Z');
            global $wpdb; $overlap=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table('allocations')." WHERE resource_id=%s AND state IN ('allocated','in_use','blocked_conflict','returned','disputed') AND (state IN ('in_use','disputed') OR (start_at<%s AND effective_end>%s)) LIMIT 1",$r->id,$effective,$start->format('Y-m-d\TH:i:s\Z')));
            if ($wpdb->last_error!=='') return self::error('storage','Availability could not be checked.','موجودی بررسی نشد.',503);
            $remaining=$overlap?0:1;
        }
        $base['status']=$remaining>0?'open':'sold_out'; $base['remaining']=$remaining; $base['duration']=$duration; $base['billing_unit']=$f['billing_unit'];
        if ($duration<=intdiv(PHP_INT_MAX-(int)$f['tax_per_unit_irr'],max(1,(int)$f['unit_amount_irr']))) $base['amount_per_unit_irr']=$duration*(int)$f['unit_amount_irr']+(int)$f['tax_per_unit_irr'];
        return $base;
    }
    public static function catalog_availability($result,$offer,$filters=array(),$capacity=0,$allocated=0) {
        if (!in_array($offer['kind']??'',array('stay','rental'),true)) return $result;
        $dated=self::dated_availability($offer,is_array($filters)?$filters:array());
        return is_wp_error($dated) ? array('status'=>'unavailable','source'=>'agency_review_required','remaining'=>null,'checked_at'=>(new DateTimeImmutable(DB::now(),new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),'admission_rechecked_at_hold'=>true) : $dated;
    }
    public static function catalog_item($item,$offer,$filters) {
        if (!in_array($offer['kind']??'',array('stay','rental'),true) || $offer['mode']!=='local') return $item;
        $item['price']=array('amount_irr'=>(string)$offer['amount_irr'],'currency'=>'IRR','unit'=>$offer['kind']==='stay'?'per_night':'per_day','from'=>true,'scope'=>'advertised_base_from');
        $row=self::current((int)$offer['id']);
        if (is_wp_error($row)) { $item['price']['quote_status']='unavailable'; return $item; }
        $f=self::data($row);
        if (isset($item['availability']['amount_per_unit_irr'])) $item['dated_quote']=array('rate_plan_id'=>$item['availability']['rate_plan_id'],'rate_version'=>$item['availability']['rate_version'],'amount_per_unit_irr'=>(string)$item['availability']['amount_per_unit_irr'],'currency'=>'IRR','duration'=>$item['availability']['duration'],'billing_unit'=>$f['billing_unit'],'tax_included'=>true,'optional_protection_included'=>false,'rechecked_at_hold'=>true);
        return $item;
    }
    /** Apply date-aware availability before count/pagination, retaining every previous visibility clause. */
    public static function catalog_where($query,$filters) {
        if (!is_array($query) || !in_array($filters['availability']??'',array('open','sold_out'),true) || (!empty($filters['kind']) && !in_array($filters['kind'],array('stay','rental'),true))) return $query;
        if (!self::ready()) { $query['sql'].=" AND i.kind NOT IN ('stay','rental')"; return $query; }
        global $wpdb; $records=DB::table('records');
        $rows=$wpdb->get_results("SELECT rr.payload AS rate_payload,r.*,s.payload AS source_payload,s.state AS source_state,s.org_id AS source_org,p.payload AS parent_payload,p.state AS parent_state,p.org_id AS parent_org,o.status AS org_status,inv.offer_id,inv.kind AS offer_kind FROM $records rr INNER JOIN ".self::table('resources')." r ON r.id=JSON_UNQUOTE(JSON_EXTRACT(rr.payload,'$.resource_id')) INNER JOIN $records s ON s.id=r.record_id AND s.type=r.record_type LEFT JOIN $records p ON p.id=r.property_id AND p.type='property' INNER JOIN ".DB::table('organizations')." o ON o.id=r.org_id INNER JOIN ".DB::table('inventory')." inv ON inv.offer_id=CAST(JSON_UNQUOTE(JSON_EXTRACT(rr.payload,'$.offer_id')) AS UNSIGNED) WHERE rr.type='resource_rate' AND rr.state='published' AND rr.org_id=r.org_id AND inv.status='published' AND inv.kind IN ('stay','rental') ORDER BY inv.offer_id LIMIT 1001");
        if ($rows===null || $wpdb->last_error!=='' || count($rows)>1000) return self::error('catalog_review','Dated catalogue review is temporarily unavailable.','بررسی تاریخ‌دار فهرست موقتاً در دسترس نیست.',503);
        $clauses=array(); $args=array(); $now=new DateTimeImmutable(DB::now(),new DateTimeZone('UTC'));
        foreach ($rows as $r) {
            $f=json_decode($r->rate_payload,true); $source=json_decode($r->source_payload,true); $parent=json_decode((string)$r->parent_payload,true);
            if (!is_array($f) || !is_array($source) || $r->source_state!=='approved' || (int)$r->source_org!==(int)$r->org_id || !in_array($r->org_status,array('active','approved'),true) || $f['kind']!==$r->offer_kind || !self::instant($f['valid_until']??null) || strtotime($f['valid_until'])<=$now->getTimestamp()) continue;
            if ($f['kind']==='stay') {
                if (empty($filters['check_in']) || empty($filters['check_out']) || $r->kind!=='stay' || $r->record_type!=='unit_type' || $r->parent_state!=='approved' || (int)$r->parent_org!==(int)$r->org_id || !is_array($parent) || ($parent['timezone']??'')!==$r->timezone || ($source['property_id']??'')!==$r->property_id || (int)!empty($source['whole_property'])!==(int)$r->whole_property || (int)($source['units']??0)<(int)$r->capacity || (int)($source['capacity']??0)<1 || ((int)$r->whole_property===1 && (int)$r->capacity!==1)) continue;
                $start=self::date($filters['check_in']); $end=self::date($filters['check_out']); if (!$start || !$end || $end<=$start) continue;
                $duration=(int)$start->diff($end)->days; $localStart=$start->format('Y-m-d'); $localEnd=$end->format('Y-m-d');
                if ($duration<(int)$r->min_nights || $localStart<$now->setTimezone(new DateTimeZone($r->timezone))->format('Y-m-d')) continue;
                $available='NOT EXISTS (SELECT 1 FROM '.self::table('nights').' rn WHERE rn.resource_id=%s AND rn.local_date>=%s AND rn.local_date<%s AND (rn.blocked<>0 OR rn.allocated>=%d))'; $parameters=array($r->id,$localStart,$localEnd,(int)$r->capacity);
            } else {
                if (empty($filters['pickup_local']) || empty($filters['return_local']) || $r->kind!=='vehicle' || $r->record_type!=='vehicle' || !in_array($r->vehicle_kind,array('bicycle','motorcycle','car','van'),true) || ($source['category']??'')!==$r->vehicle_kind || trim((string)($source['license_class']??''))!==$r->license_class || ($r->vehicle_kind!=='bicycle' && $r->license_class==='')) continue;
                $policy=json_decode((string)$r->rental_policy,true);
                if (!is_array($policy) || !isset($policy['minimum_age'],$policy['coverage_reference'],$policy['policy_reference']) || !is_int($policy['minimum_age']) || $policy['minimum_age']<0 || (int)$r->policy_reviewed_by<1 || !self::instant($policy['valid_until']??null)) continue;
                $start=self::local_instant($filters['pickup_local'],$f['timezone'],$filters['pickup_offset']??''); $end=self::local_instant($filters['return_local'],$f['timezone'],$filters['return_offset']??'');
                if (!$start || !$end || $end<=$start || $start<$now || strtotime($policy['valid_until'])<$end->getTimestamp()) continue;
                $duration=(int)ceil(($end->getTimestamp()-$start->getTimestamp())/86400); $localStart=$start->setTimezone(new DateTimeZone($f['timezone']))->format('Y-m-d'); $localEnd=$end->setTimezone(new DateTimeZone($f['timezone']))->format('Y-m-d'); $effective=$end->modify('+'.(int)$r->turnaround_minutes.' minutes')->format('Y-m-d\TH:i:s\Z');
                $available="NOT EXISTS (SELECT 1 FROM ".self::table('allocations')." ra WHERE ra.resource_id=%s AND ra.state IN ('allocated','in_use','blocked_conflict','returned','disputed') AND (ra.state IN ('in_use','disputed') OR (ra.start_at<%s AND ra.effective_end>%s)))"; $parameters=array($r->id,$effective,$start->format('Y-m-d\TH:i:s\Z'));
            }
            if ($duration<$f['min_duration'] || $duration>$f['max_duration'] || $localStart<$f['start_date'] || $localEnd>$f['end_date']) continue;
            $clauses[]='(i.offer_id=%d AND '.(($filters['availability']==='sold_out')?'NOT ':'').'('.$available.'))'; $args[]=(int)$r->offer_id; array_push($args,...$parameters);
        }
        $query['sql'].=" AND (i.kind NOT IN ('stay','rental') OR (".($clauses?implode(' OR ',$clauses):'0=1').'))'; array_push($query['args'],...$args); return $query;
    }
    /** Convert named-zone local times strictly; ambiguity requires a matching explicit numeric offset. */
    public static function local_instant($value,$zone,$offset='') {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/',$value) || !in_array($zone,timezone_identifiers_list(),true) || !is_string($offset) || ($offset!=='' && !preg_match('/^[+-]\d{2}:\d{2}$/',$offset))) return false;
        $format=strlen($value)===19?'Y-m-d\TH:i:s':'Y-m-d\TH:i';
        $naive=DateTimeImmutable::createFromFormat('!'.$format,$value,new DateTimeZone('UTC')); if (!$naive || $naive->format($format)!==$value) return false;
        $tz=new DateTimeZone($zone); $offsets=array(); foreach ($tz->getTransitions($naive->getTimestamp()-172800,$naive->getTimestamp()+172800) as $transition) $offsets[$transition['offset']]=true;
        $candidates=array();
        foreach (array_keys($offsets) as $seconds) { $candidate=$naive->modify(($seconds>=0?'-':'+').abs($seconds).' seconds'); $local=$candidate->setTimezone($tz); if ($local->format($format)===$value && ($offset==='' || $local->format('P')===$offset)) $candidates[]=$candidate; }
        return count($candidates)===1 ? $candidates[0] : false;
    }
    public static function prepare($prepared,$offer,$qty,$user,$options,$now) {
        if (is_wp_error($prepared) || !in_array($offer['kind']??'',array('stay','rental'),true)) return $prepared;
        $plan=self::plan_argument($options['rate_plan_id']??null); if (is_wp_error($plan)) return $plan;
        $plans=self::active_rates((int)$offer['id'],true); if (is_wp_error($plans)) return $plans;
        if ($plan==='' && count($plans)>1) return self::error('plan_required','Choose and review one named rate plan before reserving.','پیش از رزرو یک طرح قیمت را انتخاب و بررسی کنید.',422);
        $rate=self::current((int)$offer['id'],true,$plan); if (is_wp_error($rate)) return $rate; $f=self::data($rate);
        $allowed=$offer['kind']==='stay'?array('rate_plan_id','rate_version','check_in','check_out','guests_per_unit','terms_accepted'):array('rate_plan_id','rate_version','pickup_local','return_local','pickup_offset','return_offset','protection','terms_accepted');
        if (array_diff(array_keys($options),$allowed) || !isset($options['rate_version']) || !(is_int($options['rate_version']) || (is_string($options['rate_version']) && ctype_digit($options['rate_version']))) || (int)$options['rate_version']!==(int)$rate->version || ($options['terms_accepted']??false)!==true) return self::error('terms','Review the current dated quote and explicitly accept its terms.','قیمت تاریخ‌دار جاری را بررسی و شرایط آن را صریحاً بپذیرید.',422);
        $r=self::source($f['resource_id'],true); if (is_wp_error($r)) return $r;
        if ((int)$r->org_id!==(int)$offer['org_id'] || $f['kind']!==$offer['kind']) return self::error('mismatch','Resource quote does not match this offer.','قیمت منبع با این پیشنهاد مطابقت ندارد.');
        $nights=array(); $protection=false;
        if ($f['kind']==='stay') {
            $start=self::date($options['check_in']??null); $end=self::date($options['check_out']??null);
            $guests=$options['guests_per_unit']??null;
            if (!$start || !$end || $end<=$start || (!is_int($guests) && !(is_string($guests) && ctype_digit($guests))) || (int)$guests<1 || (int)$guests>min($r->occupancy,(int)($f['max_guests_per_unit']??$r->occupancy)) || $qty>(int)$r->capacity || $start->format('Y-m-d')<(new DateTimeImmutable($now,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($r->timezone))->format('Y-m-d')) return self::error('dates','Choose valid future local stay dates, rooms and plan occupancy.','تاریخ محلی آینده، اتاق و ظرفیت مهمان طرح معتبر انتخاب کنید.',422);
            $duration=(int)$start->diff($end)->days; $startValue=$start->format('Y-m-d'); $endValue=$end->format('Y-m-d'); $effective=$endValue;
            if ($duration<$f['min_duration'] || $duration>$f['max_duration'] || $startValue<$f['start_date'] || $endValue>$f['end_date']) return self::error('rate_period','Dates or duration are outside the reviewed rate period.','تاریخ یا مدت خارج از بازه قیمت بازبینی‌شده است.',422);
            if ($duration<(int)$r->min_nights) return self::error('minimum','Minimum stay is not satisfied.','حداقل مدت اقامت رعایت نشده است.',422);
            for ($d=$start;$d<$end;$d=$d->modify('+1 day')) {
                $date=$d->format('Y-m-d'); global $wpdb; $night=$wpdb->get_row($wpdb->prepare('SELECT allocated,blocked FROM '.self::table('nights').' WHERE resource_id=%s AND local_date=%s FOR UPDATE',$r->id,$date));
                if ($wpdb->last_error!=='') throw new RuntimeException('night read');
                if (!$night) { self::sql($wpdb->insert(self::table('nights'),array('resource_id'=>$r->id,'local_date'=>$date,'allocated'=>0,'blocked'=>0))); $night=(object)array('allocated'=>0,'blocked'=>0); }
                if ((int)$night->blocked || (int)$night->allocated+$qty>(int)$r->capacity) return self::error('unavailable','One or more requested nights are unavailable.','یک یا چند شب درخواستی در دسترس نیست.');
                $nights[]=$date;
            }
        } else {
            if ($qty!==1) return self::error('quantity','Reserve one individually assigned vehicle per booking.','برای هر رزرو یک وسیله مشخص انتخاب کنید.',422);
            $start=self::local_instant($options['pickup_local']??null,$f['timezone'],$options['pickup_offset']??''); $end=self::local_instant($options['return_local']??null,$f['timezone'],$options['return_offset']??'');
            if (!$start || !$end || $end<=$start || $start->getTimestamp()<strtotime($now)) return self::error('time','Enter ordered future local times. At a clock change, specify a valid UTC offset or request staff review.','زمان محلی آینده و مرتب وارد کنید. هنگام تغییر ساعت، اختلاف معتبر UTC را مشخص کنید یا بررسی مسئول بخواهید.',422);
            if (isset($options['protection']) && !is_bool($options['protection'])) return self::error('protection','Protection requires an explicit choice.','پوشش نیازمند انتخاب صریح است.',422);
            $protection=$options['protection']??false; $duration=(int)ceil(($end->getTimestamp()-$start->getTimestamp())/86400); $startValue=$start->format('Y-m-d\TH:i:s\Z'); $endValue=$end->format('Y-m-d\TH:i:s\Z'); $effective=$end->modify('+'.(int)$r->turnaround_minutes.' minutes')->format('Y-m-d\TH:i:s\Z');
            if (strtotime($r->policy['valid_until'])<$end->getTimestamp()) return self::error('coverage','Reviewed rental coverage does not extend through the requested return.','پوشش بازبینی‌شده تا زمان بازگشت درخواستی معتبر نیست.');
            global $wpdb; $overlap=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table('allocations')." WHERE resource_id=%s AND state IN ('allocated','in_use','blocked_conflict','returned','disputed') AND (state IN ('in_use','disputed') OR (start_at<%s AND effective_end>%s)) LIMIT 1 FOR UPDATE",$r->id,$effective,$startValue));
            if ($wpdb->last_error!=='') throw new RuntimeException('interval read');
            if ($overlap) return self::error('unavailable','Vehicle conflicts with a rental, transfer, turnaround or maintenance interval.','بازه وسیله با اجاره، ترانسفر، آماده‌سازی یا تعمیرات تداخل دارد.');
        }
        $localStart=$f['kind']==='stay'?$startValue:$start->setTimezone(new DateTimeZone($f['timezone']))->format('Y-m-d'); $localEnd=$f['kind']==='stay'?$endValue:$end->setTimezone(new DateTimeZone($f['timezone']))->format('Y-m-d');
        if ($duration<$f['min_duration'] || $duration>$f['max_duration'] || $localStart<$f['start_date'] || $localEnd>$f['end_date'] || strtotime($f['valid_until'])<=strtotime($now)) return self::error('rate_period','Dates or duration are outside the reviewed rate period.','تاریخ یا مدت خارج از بازه قیمت بازبینی‌شده است.',422);
        if ($protection && (int)$f['tax_per_unit_irr']>PHP_INT_MAX-(int)$f['protection_irr']) return self::error('amount','Quote amount is out of range.','مبلغ قیمت خارج از محدوده است.',422);
        $extras=(int)$f['tax_per_unit_irr']+($protection?(int)$f['protection_irr']:0);
        if ($extras<0 || $duration>intdiv(PHP_INT_MAX-$extras,max(1,(int)$f['unit_amount_irr']))) return self::error('amount','Quote amount is out of range.','مبلغ قیمت خارج از محدوده است.',422);
        $amount=$duration*(int)$f['unit_amount_irr']+$extras;
        $snapshot=array('resource_id'=>$r->id,'rate_id'=>$rate->id,'rate_version'=>(int)$rate->version,'rate_name_en'=>$f['name_en']??'Reviewed standard rate','rate_name_fa'=>$f['name_fa']??'قیمت استاندارد بازبینی‌شده','max_guests_per_unit'=>$f['kind']==='stay'?min($r->occupancy,(int)($f['max_guests_per_unit']??$r->occupancy)):1,'kind'=>$f['kind'],'start'=>$startValue,'end'=>$endValue,'effective_end'=>$effective,'timezone'=>$f['timezone'],'duration'=>$duration,'billing_unit'=>$f['billing_unit'],'unit_rate_irr'=>(int)$f['unit_amount_irr'],'tax_per_unit_irr'=>(int)$f['tax_per_unit_irr'],'protection_irr'=>$protection?(int)$f['protection_irr']:0,'terms_en'=>$f['terms_en'],'terms_fa'=>$f['terms_fa'],'policy_reference'=>$f['policy_reference'],'supplier_reviewed_at'=>$f['reviewed_at'],'supplier_confirmation_required'=>true,'deposit_instrument'=>'none','guests_per_unit'=>$f['kind']==='stay'?(int)$guests:null);
        return array('unit_amount_irr'=>$amount,'primary_quantity'=>0,'snapshot'=>array('resource_booking'=>$snapshot),'handled_options'=>true,'resource_booking'=>array('resource'=>$r,'snapshot'=>$snapshot,'supplier_users'=>$f['supplier_users'],'nights'=>$nights,'allocation_id'=>wp_generate_uuid4()));
    }
    /** Core owns transaction boundaries in all four hold callbacks. */
    public static function created($valid,$booking,$hold,$offer,$qty,$user,$prepared,$deadline) {
        if ($valid!==true || empty($prepared['resource_booking'])) return $valid;
        global $wpdb; $extra=$prepared['resource_booking']; $r=$extra['resource']; $s=$extra['snapshot'];
        foreach ($extra['nights'] as $date) if ($wpdb->query($wpdb->prepare('UPDATE '.self::table('nights').' SET allocated=allocated+%d WHERE resource_id=%s AND local_date=%s AND blocked=0 AND allocated+%d<=%d',$qty,$r->id,$date,$qty,(int)$r->capacity))!==1) return self::error('unavailable','Requested nights changed. Retry the quote.','شب‌های درخواستی تغییر کرده است. دوباره قیمت بگیرید.');
        $now=DB::now();
        self::sql($wpdb->insert(self::table('allocations'),array('id'=>$extra['allocation_id'],'resource_id'=>$r->id,'kind'=>$s['kind']==='stay'?'stay':'rental','state'=>'allocated','start_at'=>$s['start'],'end_at'=>$s['end'],'effective_end'=>$s['effective_end'],'quantity'=>$qty,'reference'=>'booking:'.$booking,'linked_type'=>'booking','linked_id'=>$booking,'request_hash'=>hash('sha256',wp_json_encode($s)),'note'=>'','disputed'=>0,'actor_id'=>$user,'created_at'=>$now,'updated_at'=>$now)));
        $f=array('booking_id'=>$booking,'hold_id'=>$hold,'offer_id'=>(int)$offer['id'],'allocation_id'=>$extra['allocation_id'],'resource_id'=>$r->id,'quantity'=>$qty,'deadline'=>$deadline,'quote'=>$s,'supplier_users'=>$extra['supplier_users'],'supplier_status'=>'pending','supplier_history'=>array());
        self::sql($wpdb->insert(DB::table('records'),array('id'=>$booking,'type'=>'resource_reservation','org_id'=>(int)$r->org_id,'owner_id'=>$user,'state'=>'held','payload'=>wp_json_encode($f),'version'=>1,'created_at'=>$now,'updated_at'=>$now)));
        if (!DB::audit($user,(int)$r->org_id,'resource.held',$booking,'success') || !DB::emit('resource.held',$booking)) throw new RuntimeException('resource events');
        return true;
    }
    private static function transition($valid,$booking,$hold,$target) {
        if ($valid!==true) return $valid;
        global $wpdb; $row=self::row('resource_reservation',$booking,true); if (!$row) return true; $f=self::data($row);
        if ($f['hold_id']!==$hold) return self::error('mismatch','Additional allocation does not match the hold.','تخصیص اضافی با نگهداشت مطابقت ندارد.');
        if ($row->state===$target) return true;
        if ($row->state!=='held') return self::error('state','Additional allocation cannot make this transition.','این تغییر وضعیت تخصیص اضافی مجاز نیست.');
        $r=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('resources').' WHERE id=%s FOR UPDATE',$f['resource_id']));
        $a=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('allocations').' WHERE id=%s FOR UPDATE',$f['allocation_id']));
        if (!$r || !$a || $a->state!=='allocated' || $a->linked_type!=='booking' || $a->linked_id!==$booking) return self::error('state','Additional allocation requires reconciliation.','تخصیص اضافی نیازمند تطبیق است.');
        if ($target==='released') {
            if ($a->kind==='stay') for ($d=self::date($a->start_at),$end=self::date($a->end_at);$d<$end;$d=$d->modify('+1 day')) if ($wpdb->query($wpdb->prepare('UPDATE '.self::table('nights').' SET allocated=allocated-%d WHERE resource_id=%s AND local_date=%s AND allocated>=%d',(int)$a->quantity,$r->id,$d->format('Y-m-d'),(int)$a->quantity))!==1) throw new RuntimeException('night release mismatch');
            self::sql($wpdb->update(self::table('allocations'),array('state'=>'released','updated_at'=>DB::now()),array('id'=>$a->id)));
        }
        self::sql($wpdb->update(DB::table('records'),array('state'=>$target,'version'=>(int)$row->version+1,'updated_at'=>DB::now()),array('id'=>$booking,'version'=>(int)$row->version)));
        if (!DB::audit(get_current_user_id(),(int)$row->org_id,'resource.'.$target,$booking,'success') || !DB::emit('resource.'.$target,$booking)) throw new RuntimeException('resource event');
        return true;
    }
    public static function released($valid,$booking,$hold) { return self::transition($valid,$booking,$hold,'released'); }
    public static function consumed($valid,$booking,$hold) { return self::transition($valid,$booking,$hold,'consumed'); }
    public static function confirmation($valid,$booking,$core_row,$actor) {
        if ($valid!==true) return $valid; $row=self::row('resource_reservation',$booking,true); if (!$row) return true; $f=self::data($row);
        return $row->state==='consumed' && $f['supplier_status']==='accepted' ? true : self::error('supplier_confirmation','Supplier acceptance is required separately from verified payment.','پذیرش تأمین‌کننده جدا از تأیید پرداخت لازم است.');
    }
    private static function supplier($row,$f) { return Auth::agency() || (current_user_can('atlas_manage_records') && Auth::role_allows((int)$row->org_id,'write') && in_array(get_current_user_id(),$f['supplier_users'],true)); }
    public static function supplier_review($request) {
        $b=self::body($request,array('version','accepted','evidence_reference','reason')); if (is_wp_error($b)) return $b;
        if (!is_int($b['version']??null) || !is_bool($b['accepted']??null) || !is_string($b['evidence_reference']??null) || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$b['evidence_reference']) || !self::text($b['reason']??null,190)) return self::error('review','Version, explicit decision, safe evidence reference and a short reason are required.','نسخه، تصمیم صریح، شناسه امن شواهد و دلیل کوتاه لازم است.',422);
        global $wpdb;
        try {
            self::sql($wpdb->query('START TRANSACTION'));
            $core=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('bookings').' WHERE id=%s FOR UPDATE',(string)$request['id']));
            $row=self::row('resource_reservation',(string)$request['id'],true); $f=self::data($row);
            if (!$core || !$row || !self::supplier($row,$f) || (int)$row->owner_id===get_current_user_id()) { $wpdb->query('ROLLBACK'); return self::error('not_found','Assigned resource booking not found.','رزرو منبع تخصیص‌یافته یافت نشد.',404); }
            if ((int)$row->version!==$b['version'] || !in_array($row->state,array('held','consumed'),true) || !in_array($core->status,array('held','pending_payment','paid_pending_confirmation','confirmed','fulfilled'),true) || ($b['accepted'] && in_array($core->status,array('confirmed','fulfilled'),true))) { $wpdb->query('ROLLBACK'); return self::error('state','Resource booking changed or is no longer open for this supplier decision.','رزرو منبع تغییر کرده یا دیگر برای این تصمیم تأمین‌کننده باز نیست.'); }
            $f['supplier_status']=$b['accepted']?'accepted':'rejected'; $f['supplier_history'][]=array('accepted'=>$b['accepted'],'actor'=>get_current_user_id(),'at'=>DB::now(),'reference'=>$b['evidence_reference'],'reason'=>$b['reason']);
            if (!$b['accepted']) { $review=Atlas\Core\Booking::review_in_transaction($row->id,get_current_user_id(),$b['reason'],$b['evidence_reference']); if (is_wp_error($review)) { $wpdb->query('ROLLBACK'); return $review; } }
            self::sql($wpdb->update(DB::table('records'),array('payload'=>wp_json_encode($f),'version'=>(int)$row->version+1,'updated_at'=>DB::now()),array('id'=>$row->id,'version'=>(int)$row->version)));
            $event=$b['accepted']?'supplier_accepted':'supplier_rejected';
            if (!DB::audit(get_current_user_id(),(int)$row->org_id,'resource.'.$event,$row->id,'success',$b['reason']) || !DB::emit('resource.'.$event,$row->id)) throw new RuntimeException('resource review event');
            self::sql($wpdb->query('COMMIT')); return self::reservation_projection(self::row('resource_reservation',$row->id));
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); return self::error('storage','Supplier review could not be recorded.','بررسی تأمین‌کننده ثبت نشد.',503); }
    }
    private static function cancellation($booking,$lock=false) { return self::row('resource_cancellation',self::uuid('resource-cancellation:'.$booking),$lock); }
    /** Caller owns the transaction; match Core's inventory -> hold -> booking lock order. */
    private static function lock_booking($id) {
        global $wpdb; $reference=$wpdb->get_row($wpdb->prepare('SELECT offer_id,hold_id FROM '.DB::table('bookings').' WHERE id=%s',$id));
        if (!$reference) return self::error('not_found','Resource reservation not found.','رزرو منبع یافت نشد.',404);
        $inventory=$wpdb->get_row($wpdb->prepare('SELECT offer_id FROM '.DB::table('inventory').' WHERE offer_id=%d FOR UPDATE',(int)$reference->offer_id));
        $hold=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('holds').' WHERE id=%s FOR UPDATE',$reference->hold_id));
        $booking=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('bookings').' WHERE id=%s FOR UPDATE',$id));
        if (!$inventory || !$hold || !$booking || $booking->hold_id!==$hold->id || (int)$booking->offer_id!==(int)$inventory->offer_id) return self::error('state','Reservation links require reconciliation.','پیوندهای رزرو نیازمند تطبیق است.');
        return array($booking,$hold);
    }
    private static function cancellation_view($row) {
        $result=self::record($row);
        if (!Auth::agency()) $result['fields']=array_intersect_key($result['fields'],array_flip(array('booking_id','requested_at','customer_reason','withdrawn_at','reviewed_at','decision_reason','refund_review_required')));
        return $result;
    }
    private static function put_cancellation($booking,$mapping,$state,$f,$old,$action,$reason) {
        global $wpdb; $id=self::uuid('resource-cancellation:'.$booking->id); $now=DB::now(); $values=array('state'=>$state,'payload'=>wp_json_encode($f),'version'=>$old?(int)$old->version+1:1,'updated_at'=>$now);
        $written=$old?$wpdb->update(DB::table('records'),$values,array('id'=>$id,'version'=>(int)$old->version)):$wpdb->insert(DB::table('records'),array_merge($values,array('id'=>$id,'type'=>'resource_cancellation','org_id'=>(int)$mapping->org_id,'owner_id'=>(int)$booking->user_id,'created_at'=>$now)));
        if ($written!==1 || !DB::audit(get_current_user_id(),(int)$mapping->org_id,'resource.'.$action,$id,'success',$reason) || !DB::emit('resource.'.$action,$id)) throw new RuntimeException('cancellation record');
        return self::row('resource_cancellation',$id);
    }
    public static function cancel_request($request) {
        $b=self::body($request,array('version','reason','withdraw')); if (is_wp_error($b)) return $b;
        if (!is_int($b['version']??null) || !self::text($b['reason']??null) || (isset($b['withdraw']) && !is_bool($b['withdraw']))) return self::error('cancellation','Current version and your reason are required.','نسخه جاری و دلیل شما لازم است.',422);
        global $wpdb; $id=(string)$request['id'];
        try {
            self::sql($wpdb->query('START TRANSACTION'));
            $locked=self::lock_booking($id); if (is_wp_error($locked)) { $wpdb->query('ROLLBACK'); return $locked; } list($booking,$hold)=$locked;
            $mapping=self::row('resource_reservation',$id,true); $f=self::data($mapping); $old=self::cancellation($id,true);
            if (!$mapping || (int)$booking->user_id!==get_current_user_id() || (int)$mapping->owner_id!==get_current_user_id()) { $wpdb->query('ROLLBACK'); return self::error('not_found','Your resource reservation was not found.','رزرو منبع متعلق به شما یافت نشد.',404); }
            if (!in_array($mapping->state,array('held','consumed'),true) || !in_array($booking->status,array('held','pending_payment','paid_pending_confirmation','confirmed','exception'),true)) { $wpdb->query('ROLLBACK'); return self::error('state','This reservation is no longer open for a cancellation request.','این رزرو دیگر برای درخواست لغو باز نیست.'); }
            $withdraw=$b['withdraw']??false; $previous=self::data($old);
            if (!$withdraw && $old && $old->state==='requested' && ($previous['customer_reason']??'')===$b['reason']) { self::sql($wpdb->query('COMMIT')); return self::cancellation_view($old); }
            if (($old?(int)$old->version:0)!==$b['version'] || ($old && $old->state==='approved') || ($withdraw && (!$old || $old->state!=='requested'))) { $wpdb->query('ROLLBACK'); return self::error('version','Cancellation request changed. Reload it.','درخواست لغو تغییر کرده است. دوباره دریافت کنید.'); }
            $history=$previous['history']??array(); if (count($history)>=100) { $wpdb->query('ROLLBACK'); return self::error('review_limit','Ask the agency to review this cancellation history.','برای بررسی تاریخچه لغو با آژانس تماس بگیرید.'); }
            $history[]=array('action'=>$withdraw?'withdrawn':'requested','actor'=>get_current_user_id(),'at'=>DB::now(),'reason'=>$b['reason']);
            $fields=array('booking_id'=>$id,'requested_by'=>get_current_user_id(),'requested_at'=>DB::now(),'customer_reason'=>$b['reason'],'accepted_policy_reference'=>$f['quote']['policy_reference'],'history'=>$history,'refund_review_required'=>null);
            if ($withdraw) $fields['withdrawn_at']=DB::now();
            $row=self::put_cancellation($booking,$mapping,$withdraw?'withdrawn':'requested',$fields,$old,$withdraw?'cancellation_withdrawn':'cancellation_requested',$b['reason']);
            self::sql($wpdb->query('COMMIT')); return self::cancellation_view($row);
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); return self::error('storage','Cancellation request could not be recorded.','درخواست لغو ثبت نشد.',503); }
    }
    public static function cancel_review($request) {
        if (!Auth::agency() || !current_user_can('atlas_manage_bookings')) return self::error('forbidden','Agency cancellation review required.','بررسی لغو توسط آژانس لازم است.',403);
        $b=self::body($request,array('version','approved','terms_reviewed','policy_reference','evidence_reference','reason')); if (is_wp_error($b)) return $b;
        if (!is_int($b['version']??null) || !is_bool($b['approved']??null) || ($b['terms_reviewed']??false)!==true || !self::text($b['policy_reference']??null,180) || !is_string($b['evidence_reference']??null) || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$b['evidence_reference']) || !self::text($b['reason']??null,190)) return self::error('cancellation_review','Review the agreed terms and supply an explicit decision, policy and evidence reference.','شرایط توافق‌شده را بررسی و تصمیم صریح و شناسه سیاست و شواهد را وارد کنید.',422);
        $id=(string)$request['id']; $canonical=$b; ksort($canonical,SORT_STRING); $hash=hash('sha256',wp_json_encode($canonical)); global $wpdb;
        try {
            self::sql($wpdb->query('START TRANSACTION'));
            $locked=self::lock_booking($id); if (is_wp_error($locked)) { $wpdb->query('ROLLBACK'); return $locked; } list($booking,$hold)=$locked;
            $mapping=self::row('resource_reservation',$id,true); $m=self::data($mapping); $row=self::cancellation($id,true); $f=self::data($row);
            if ((int)$booking->user_id===get_current_user_id()) { $wpdb->query('ROLLBACK'); return self::error('independent_review','A different agency reviewer must decide the customer cancellation request.','بازبین دیگری در آژانس باید درباره درخواست لغو مشتری تصمیم بگیرد.',403); }
            $accepted_snapshot=json_decode($booking->snapshot,true) ?: array();
            if (!$mapping || !$row || (int)$mapping->owner_id!==(int)$booking->user_id || (int)$mapping->org_id!==(int)($accepted_snapshot['org_id']??0) || ($m['hold_id']??'')!==$hold->id || (int)($m['offer_id']??0)!==(int)$booking->offer_id || (int)($m['quantity']??0)!==(int)$booking->quantity || ($m['quote']['kind']??'')!==($accepted_snapshot['kind']??'') || (int)$row->owner_id!==(int)$booking->user_id || (int)($f['requested_by']??0)!==(int)$booking->user_id || ($f['booking_id']??'')!==$id || ($f['accepted_policy_reference']??'')!==($m['quote']['policy_reference']??'') || $b['policy_reference']!==($m['quote']['policy_reference']??'')) { $wpdb->query('ROLLBACK'); return self::error('not_found','Customer-requested cancellation with matching agreed terms was not found.','درخواست لغو مشتری با شرایط توافق‌شده منطبق یافت نشد.',404); }
            if (in_array($row->state,array('approved','rejected'),true) && ($f['review_hash']??'')===$hash) { self::sql($wpdb->query('COMMIT')); return self::cancellation_view($row); }
            if ((int)$row->version!==$b['version'] || $row->state!=='requested' || !in_array($mapping->state,array('held','consumed'),true)) { $wpdb->query('ROLLBACK'); return self::error('version','Cancellation request changed or is no longer pending.','درخواست لغو تغییر کرده یا دیگر در انتظار بررسی نیست.'); }
            $refund_review=null;
            if ($b['approved']) {
                $resource=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('resources').' WHERE id=%s FOR UPDATE',$m['resource_id']));
                $allocation=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('allocations').' WHERE id=%s FOR UPDATE',$m['allocation_id']));
                if (!$resource || !$allocation || (int)$resource->org_id!==(int)$mapping->org_id || $allocation->resource_id!==$resource->id || $allocation->kind!==$m['quote']['kind'] || $allocation->linked_type!=='booking' || $allocation->linked_id!==$id || $allocation->state!=='allocated' || !empty($allocation->picked_up_at) || (int)$allocation->disputed!==0 || (int)$allocation->quantity!==(int)$m['quantity'] || $allocation->start_at!==$m['quote']['start'] || $allocation->end_at!==$m['quote']['end']) { $wpdb->query('ROLLBACK'); return self::error('started','Only a reconciled, unstarted resource allocation can be canceled here.','فقط تخصیص منبع تطبیق‌یافته و شروع‌نشده در این بخش قابل لغو است.'); }
                if ($allocation->kind==='stay') {
                    $zone=$m['quote']['timezone']; $today=(new DateTimeImmutable(DB::now(),new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($zone))->format('Y-m-d');
                    if ($allocation->start_at<=$today) { $wpdb->query('ROLLBACK'); return self::error('started','A stay on or after its local check-in day needs a separate occupancy review.','اقامت از روز ورود محلی به بعد به بررسی جداگانه اشغال نیاز دارد.'); }
                    $start=self::date($allocation->start_at); $end=self::date($allocation->end_at); if (!$start || !$end || $end<=$start || (int)$start->diff($end)->days>365) throw new RuntimeException('stay interval');
                    for ($d=$start;$d<$end;$d=$d->modify('+1 day')) if ($wpdb->query($wpdb->prepare('UPDATE '.self::table('nights').' SET allocated=allocated-%d WHERE resource_id=%s AND local_date=%s AND allocated>=%d',(int)$allocation->quantity,$resource->id,$d->format('Y-m-d'),(int)$allocation->quantity))!==1) throw new RuntimeException('cancellation night mismatch');
                } elseif ($allocation->kind!=='rental') { $wpdb->query('ROLLBACK'); return self::error('kind','This cancellation workflow only handles stays and rentals.','این فرایند لغو فقط برای اقامت و اجاره است.'); }
                if ($wpdb->update(self::table('allocations'),array('state'=>'released','updated_at'=>DB::now()),array('id'=>$allocation->id,'state'=>'allocated'))!==1) throw new RuntimeException('allocation release');
                if (!method_exists('Atlas\Core\Booking','cancel_resource_in_transaction')) throw new RuntimeException('cancellation core unavailable');
                $canceled=Atlas\Core\Booking::cancel_resource_in_transaction($id,get_current_user_id(),$b['reason'],$b['evidence_reference']); if (is_wp_error($canceled)) { $wpdb->query('ROLLBACK'); return $canceled; }
                $refund_review=$canceled['refund_review_required']; $m['cancellation_id']=$row->id; $m['refund_review_required']=$refund_review;
                if ($wpdb->update(DB::table('records'),array('state'=>'released','payload'=>wp_json_encode($m),'version'=>(int)$mapping->version+1,'updated_at'=>DB::now()),array('id'=>$mapping->id,'version'=>(int)$mapping->version))!==1 || !DB::audit(get_current_user_id(),(int)$mapping->org_id,'resource.cancellation_allocation_released',$allocation->id,'success',$b['evidence_reference'])) throw new RuntimeException('cancellation mapping');
            }
            $f['reviewed_by']=get_current_user_id(); $f['reviewed_at']=DB::now(); $f['decision_reason']=$b['reason']; $f['evidence_reference']=$b['evidence_reference']; $f['terms_reviewed']=true; $f['review_hash']=$hash; $f['refund_review_required']=$refund_review;
            $f['history'][]=array('action'=>$b['approved']?'approved':'rejected','actor'=>get_current_user_id(),'at'=>DB::now(),'reason'=>$b['reason'],'evidence_reference'=>$b['evidence_reference']);
            $row=self::put_cancellation($booking,$mapping,$b['approved']?'approved':'rejected',$f,$row,'cancellation_reviewed',$b['reason']); self::sql($wpdb->query('COMMIT')); return self::cancellation_view($row);
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); return self::error('storage','Cancellation review failed; allocation and money state were preserved.','بررسی لغو انجام نشد؛ تخصیص و وضعیت مالی حفظ شد.',503); }
    }
    public static function notification_events($events) { return array_merge($events,array('booking.canceled','booking.refund_review_required','resource.held','resource.consumed','resource.released','resource.supplier_accepted','resource.supplier_rejected','resource.cancellation_requested','resource.cancellation_withdrawn','resource.cancellation_reviewed')); }
    public static function notification_recipients($previous,$event) {
        $type=$event['event_type']??''; if (!str_starts_with($type,'resource.')) return $previous; $id=$event['aggregate_id']??''; $users=array();
        if (str_starts_with($type,'resource.cancellation_')) { $row=self::row('resource_cancellation',$id); $f=self::data($row); $mapping=$row?self::row('resource_reservation',$f['booking_id']):null; $m=self::data($mapping); if ($row) $users[]=(int)$row->owner_id; }
        else { $mapping=self::row('resource_reservation',$id); $m=self::data($mapping); if ($mapping) $users[]=(int)$mapping->owner_id; }
        if (!empty($mapping)) foreach ($m['supplier_users']??array() as $user) if (Auth::member((int)$mapping->org_id,(int)$user) && user_can((int)$user,'atlas_manage_records') && Auth::member_role((int)$mapping->org_id,(int)$user)!=='viewer') $users[]=(int)$user;
        if (in_array($type,array('resource.cancellation_requested','resource.cancellation_withdrawn','resource.supplier_rejected'),true)) foreach (get_users(array('role'=>'administrator','fields'=>'ids','number'=>100)) as $user) if (user_can((int)$user,'atlas_manage_bookings')) $users[]=(int)$user;
        $recipients=array(); foreach (array_unique($users) as $user) if ($user>0 && get_userdata($user)) $recipients[]=array('user'=>$user,'reference'=>$id); return $recipients;
    }
    private static function reservation_projection($row) {
        global $wpdb;
        $result=self::record($row); $f=&$result['fields'];
        $result['booking_status']=$wpdb->get_var($wpdb->prepare('SELECT status FROM '.DB::table('bookings').' WHERE id=%s',$row->id));
        $result['can_supplier_review']=self::supplier($row,$f) && (int)$row->owner_id!==get_current_user_id() && in_array($row->state,array('held','consumed'),true);
        $cancellation=self::cancellation($row->id); $result['cancellation']=$cancellation?self::cancellation_view($cancellation):null;
        $result['can_cancel_request']=(int)$row->owner_id===get_current_user_id() && in_array($row->state,array('held','consumed'),true) && in_array($result['booking_status'],array('held','pending_payment','paid_pending_confirmation','confirmed','exception'),true) && (!$cancellation || $cancellation->state!=='approved');
        $result['can_cancel_review']=Auth::agency() && current_user_can('atlas_manage_bookings') && $cancellation && $cancellation->state==='requested';
        if ($cancellation && (int)$row->owner_id!==get_current_user_id() && !Auth::agency()) $result['cancellation']=array('id'=>$cancellation->id,'state'=>$cancellation->state);
        if (!self::supplier($row,$f)) { unset($f['supplier_users'],$f['quote']['policy_reference']); $f['supplier_history']=array_map(static function($h) { return array('accepted'=>$h['accepted'],'at'=>$h['at']); },$f['supplier_history']); }
        return $result;
    }
    public static function queue($request=null) {
        global $wpdb; $actor=get_current_user_id();
        $offset=$request?$request->get_param('offset'):0; if ($offset===null) $offset=0;
        if (!(is_int($offset) || (is_string($offset) && ctype_digit($offset))) || (int)$offset<0 || (int)$offset>1000000) return self::error('offset','Invalid list position.','موقعیت فهرست نامعتبر است.',422);
        if (Auth::agency()) $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.DB::table('records').' WHERE type=%s ORDER BY created_at DESC,id LIMIT 101 OFFSET %d','resource_reservation',(int)$offset));
        else $rows=$wpdb->get_results($wpdb->prepare('SELECT r.* FROM '.DB::table('records').' r WHERE r.type=%s AND (r.owner_id=%d OR EXISTS (SELECT 1 FROM '.DB::table('memberships').' m WHERE m.org_id=r.org_id AND m.user_id=%d AND m.status=%s)) ORDER BY r.created_at DESC,r.id LIMIT 101 OFFSET %d','resource_reservation',$actor,$actor,'active',(int)$offset));
        $items=array(); foreach (array_slice($rows,0,100) as $row) if ((int)$row->owner_id===$actor || self::supplier($row,self::data($row))) $items[]=self::reservation_projection($row);
        return array('items'=>$items,'has_more'=>count($rows)>100);
    }
    public static function booking_fields($html,$offer) {
        if (!in_array($offer['kind']??'',array('stay','rental'),true)) return $html;
        $row=self::current((int)$offer['id']); if (is_wp_error($row)) { self::script(); return $html.'<p data-resource-unavailable>'.esc_html($row->get_error_message()).'</p>'; }
        $f=self::data($row); self::script();
        $plans=self::active_rates((int)$offer['id']); if (is_wp_error($plans)) return $html.'<p data-resource-unavailable>'.esc_html($plans->get_error_message()).'</p>'; $multiple=count($plans)>1;
        $html.='<fieldset data-resource-booking><legend>'.esc_html(self::t('Dates and reviewed terms','تاریخ و شرایط بازبینی‌شده')).'</legend><input type="hidden" name="rate_version" data-atlas-booking-option value="'.esc_attr($multiple?0:$row->version).'">';
        if ($multiple) { $html.='<label>'.esc_html(self::t('Reviewed rate plan','طرح قیمت بازبینی‌شده')).'<select name="rate_plan_id" data-atlas-booking-option required><option value="">'.esc_html(self::t('Choose a plan and review its terms','طرح را انتخاب و شرایط آن را بررسی کنید')).'</option>'; foreach ($plans as $plan) { $p=self::data($plan); $html.='<option value="'.esc_attr($plan->id).'">'.esc_html(self::t($p['name_en']??'Reviewed standard rate',$p['name_fa']??'قیمت استاندارد بازبینی‌شده').' · '.$p['start_date'].' – '.$p['end_date']).'</option>'; } $html.='</select></label>'; }
        else $html.='<input type="hidden" name="rate_plan_id" data-atlas-booking-option value="'.esc_attr($row->id).'">';
        $fields=$f['kind']==='stay'?array('check_in'=>array('Check-in','ورود','date'),'check_out'=>array('Check-out (exclusive)','خروج (روز خروج محاسبه نمی‌شود)','date'),'guests_per_unit'=>array('Guests per room / unit','مهمان در هر اتاق / واحد','number')):array('pickup_local'=>array('Pickup local time','زمان محلی تحویل','datetime-local'),'return_local'=>array('Return local time','زمان محلی بازگشت','datetime-local'));
        $html.='<div class="atlas-dated-fields">';
        foreach ($fields as $name=>$label) $html.='<label>'.esc_html(self::t($label[0],$label[1])).'<input name="'.esc_attr($name).'" type="'.$label[2].'" data-atlas-booking-option required'.($label[2]==='number'?' min="1" value="1"':'').'></label>';
        $html.='</div><p data-resource-zone>'.esc_html(self::t('Named time zone: ','منطقه زمانی: ').$f['timezone']).'</p>';
        if ($f['kind']==='rental') {
            $html.='<details><summary>'.esc_html(self::t('Clock-change offset, when needed','اختلاف ساعت هنگام تغییر ساعت، در صورت نیاز')).'</summary>';
            foreach (array('pickup_offset','return_offset') as $name) $html.='<label>'.esc_html($name==='pickup_offset'?self::t('Pickup UTC offset (for example +03:30)','اختلاف تحویل با UTC (مانند +03:30)'):self::t('Return UTC offset','اختلاف بازگشت با UTC')).'<input name="'.$name.'" data-atlas-booking-option pattern="[+-][0-9]{2}:[0-9]{2}" placeholder="+03:30"></label>';
            $html.='</details><label><input type="checkbox" name="protection" data-atlas-booking-option data-value-type="bool">'.esc_html(self::t('Choose the disclosed optional protection','انتخاب پوشش اختیاری اعلام‌شده')).' · <span data-resource-protection-amount>'.esc_html(number_format_i18n($f['protection_irr'])).'</span> IRR</label>';
        }
        $html.='<p data-resource-plan-terms>'.esc_html($multiple?self::t('Choose a rate plan to view its full terms.','برای مشاهده شرایط کامل، طرح قیمت را انتخاب کنید.'):self::t($f['terms_en'],$f['terms_fa'])).'</p><p>'.esc_html(self::t('Local allotment only; supplier acceptance and agency confirmation remain separate. No deposit instrument is secured by this checkout.','فقط سهمیه محلی؛ پذیرش تأمین‌کننده و تأیید آژانس جداگانه است. این پرداخت هیچ ابزار ودیعه‌ای را تضمین نمی‌کند.')).'</p><output data-resource-estimate></output><label class="atlas-dated-consent"><input type="checkbox" name="terms_accepted" data-atlas-booking-option data-value-type="bool" required>'.esc_html(self::t('I accept this dated rate and the disclosed terms','قیمت تاریخ‌دار و شرایط اعلام‌شده را می‌پذیرم')) .'</label></fieldset>';
        return $html;
    }
    public static function offer_details($html,$offer) {
        if (!in_array($offer['kind']??'',array('stay','rental'),true)) return $html;
        $row=self::current((int)$offer['id']); if (is_wp_error($row)) return $html;
        $f=self::data($row); return $html.'<p>'.esc_html(self::t('Advertised base rate from: ','قیمت پایه اعلام‌شده از: ').number_format_i18n($offer['amount_irr']).' IRR · '.($f['kind']==='stay'?self::t('per local night','برای هر شب محلی'):self::t('per started 24 hours','برای هر ۲۴ ساعت شروع‌شده'))).'</p><p>'.esc_html(self::t('Select a current dated plan for exact price and terms.','برای قیمت و شرایط دقیق، طرح تاریخ‌دار جاری را انتخاب کنید.')).'</p>';
    }
    private static function script() { wp_enqueue_script('atlas-resource-booking',plugins_url('assets/resource-booking.js',__FILE__),array(),ATLAS_CORE_VERSION.'-'.filemtime(__DIR__.'/assets/resource-booking.js'),true); wp_localize_script('atlas-resource-booking','atlasResources',array('base'=>esc_url_raw(rest_url('agency/v1/')),'nonce'=>is_user_logged_in()?wp_create_nonce('wp_rest'):'','lang'=>self::t('en','fa'),'loading'=>self::t('Loading…','در حال دریافت…'),'saved'=>self::t('Recorded.','ثبت شد.'),'error'=>self::t('Request failed.','درخواست انجام نشد.'))); }
    public static function panel($customer=false) {
        if (!self::ready() || !is_user_logged_in()) return ''; self::script();
        $html='<section data-resource-workspace><h2>'.esc_html(self::t('Stays and vehicle reservations','رزرو اقامت و وسیله')).'</h2><p role="status" aria-live="polite" data-resource-status></p><button type="button" data-resource-refresh>'.esc_html(self::t('Refresh my resource reservations','دریافت رزروهای منابع من')).'</button><div data-resource-queue></div>';
        if (!Auth::agency() || !current_user_can('atlas_manage_bookings') || $customer) return $html.'</section>';
        global $wpdb; $offers=$wpdb->get_results('SELECT offer_id,kind,offer_snapshot FROM '.DB::table('inventory')." WHERE kind IN ('stay','rental') ORDER BY offer_id DESC LIMIT 101"); $resources=$wpdb->get_results('SELECT * FROM '.self::table('resources').' ORDER BY id LIMIT 501');
        $html.='<details><summary>'.esc_html(self::t('Review a dated allotment and rate','بررسی سهمیه و قیمت تاریخ‌دار')).'</summary><form data-resource-rate><label>'.esc_html(self::t('Offer','پیشنهاد')).'<select name="offer_id" required><option value="">—</option>';
        foreach ($offers as $offer) { $o=json_decode($offer->offer_snapshot,true); $html.='<option value="'.esc_attr($offer->offer_id).'" data-version="'.esc_attr($o['version']).'">'.esc_html($o['title_en'].' · '.$offer->kind).'</option>'; }
        $html.='</select></label><label>'.esc_html(self::t('Existing reviewed plan','طرح بازبینی‌شده موجود')).'<select data-resource-plan-editor></select></label><button type="button" data-resource-new-plan>'.esc_html(self::t('Create another named plan','ساخت طرح نام‌دار دیگر')).'</button><input name="plan_id" type="hidden"><label>'.esc_html(self::t('Plan name in English','نام انگلیسی طرح')).'<input name="name_en" required maxlength="180"></label><label>'.esc_html(self::t('Plan name in Persian','نام فارسی طرح')).'<input name="name_fa" required maxlength="180"></label><label>'.esc_html(self::t('Maximum guests per unit','حداکثر مهمان هر واحد')).'<input name="max_guests_per_unit" type="number" min="1" required></label><label>'.esc_html(self::t('Registered resource','منبع ثبت‌شده')).'<select name="resource_id" required>';
        foreach ($resources as $resource) { $source=self::row($resource->record_type,$resource->record_id); $s=self::data($source); $html.='<option value="'.esc_attr($resource->id).'">'.esc_html(($s['name']??$s['label']??$resource->id).' · '.$resource->kind).'</option>'; }
        $html.='</select></label><label>'.esc_html(self::t('Explicitly assigned supplier reviewers (optional; agency always reviews)','بازبینان صریح تأمین‌کننده (اختیاری؛ بررسی آژانس همواره برقرار است)')).'<select name="supplier_users" multiple size="4"></select></label>';
        $fields=array('version'=>array('Current rate version (0 for new)','نسخه قیمت جاری (صفر برای جدید)','number'),'start_date'=>array('Local rate start date','تاریخ محلی شروع قیمت','date'),'end_date'=>array('Local rate end date (exclusive)','تاریخ محلی پایان قیمت (انحصاری)','date'),'timezone'=>array('IANA time zone','منطقه زمانی IANA','text'),'valid_until'=>array('Quote expiry (UTC, ISO timestamp)','انقضای قیمت (زمان UTC به شکل ISO)','text'),'unit_amount_irr'=>array('Rate per night / started 24h (IRR)','قیمت هر شب / ۲۴ ساعت شروع‌شده (ریال)','number'),'tax_per_unit_irr'=>array('Disclosed tax per reserved unit (IRR)','مالیات اعلام‌شده هر واحد رزرو (ریال)','number'),'protection_irr'=>array('Optional rental protection (IRR; zero for stays)','پوشش اختیاری اجاره (ریال؛ صفر برای اقامت)','number'),'min_duration'=>array('Minimum nights / 24h units','حداقل شب / واحد ۲۴ ساعت','number'),'max_duration'=>array('Maximum nights / 24h units','حداکثر شب / واحد ۲۴ ساعت','number'),'terms_en'=>array('Reviewed terms in English','شرایط بازبینی‌شده انگلیسی','text'),'terms_fa'=>array('Reviewed terms in Persian','شرایط بازبینی‌شده فارسی','text'),'policy_reference'=>array('Approved policy reference','شناسه سیاست تأییدشده','text'),'supplier_reference'=>array('Private supplier allotment evidence reference','شناسه خصوصی شواهد سهمیه تأمین‌کننده','text'),'reason'=>array('Review reason','دلیل بررسی','text'));
        foreach ($fields as $name=>$label) $html.='<label>'.esc_html(self::t($label[0],$label[1])).'<input name="'.$name.'" type="'.$label[2].'" required'.($label[2]==='number'?' min="0"':'').'></label>';
        $html.='<p>'.esc_html(self::t('Only selected active supplier reviewers can record acceptance; an empty selection keeps this with the agency. No deposit charge is configured.','فقط بازبینان فعال انتخاب‌شده می‌توانند پذیرش را ثبت کنند؛ انتخاب خالی این اختیار را برای آژانس نگه می‌دارد. هزینه ودیعه تنظیم نشده است.')).'</p><label><input type="checkbox" name="published">'.esc_html(self::t('Publish this reviewed local rate','انتشار قیمت محلی بازبینی‌شده')).'</label><button>'.esc_html(self::t('Save reviewed rate','ذخیره قیمت بازبینی‌شده')).'</button></form>';
        $html.='<form data-resource-activate><p data-resource-activation-offer>'.esc_html(self::t('Select the reviewed offer above before enabling booking.','پیش از فعال‌سازی، پیشنهاد بازبینی‌شده را در بالا انتخاب کنید.')).'</p><input name="offer_id" type="hidden" required><input name="version" type="hidden" required><label>'.esc_html(self::t('Linked WooCommerce product','محصول مرتبط ووکامرس')).'<select name="product_id" required><option value="">—</option>';
        if (function_exists('wc_get_products')) foreach (wc_get_products(array('limit'=>100,'status'=>'publish')) as $product) if ($product->is_virtual()) $html.='<option value="'.esc_attr($product->get_id()).'">'.esc_html($product->get_name()).'</option>';
        return $html.'</select></label><button>'.esc_html(self::t('Enable independently reviewed booking','فعال‌سازی رزرو با بررسی مستقل')).'</button></form></details></section>';
    }
}
add_action('atlas_operations_ready',array('Atlas_Resource_Booking','register'));
