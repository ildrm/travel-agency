<?php
if (!defined('ABSPATH')) { exit; }
use Atlas\Core\Auth;
use Atlas\Core\DB;

/** Manual chauffeured dispatch, sharing the local rental/maintenance vehicle mutex. */
final class Atlas_Dispatch {
    private static $ready=false;
    private const TERMINAL=array('completed','no_show','canceled','supplier_rejected');
    public static function table($events=false) { global $wpdb; return $wpdb->prefix.($events?'atlas_dispatch_events':'atlas_dispatch'); }
    private static function calendar($resource=false) { global $wpdb; return $wpdb->prefix.($resource?'atlas_local_resources':'atlas_local_allocations'); }
    public static function ready() { return self::$ready; }
    public static function register() {
        if (!class_exists('Atlas_Partner_Security') || !class_exists('Atlas_Partner_Contracts') || !class_exists('Atlas\Inventory\Local')) return;
        $s='Atlas_Partner_Security';
        $a=$s::install('atlas_dispatch',"id char(36) NOT NULL, request_id char(36) NOT NULL, customer_id bigint unsigned NOT NULL, org_id bigint unsigned NOT NULL, resource_id char(36) NOT NULL, allocation_id char(36) NOT NULL, driver_id bigint unsigned NOT NULL, version bigint unsigned NOT NULL, state varchar(24) NOT NULL, start_at varchar(32) NOT NULL, end_at varchar(32) NOT NULL, effective_end varchar(32) NOT NULL, payload longtext NOT NULL, created_at datetime(6) NOT NULL, updated_at datetime(6) NOT NULL, PRIMARY KEY  (id), UNIQUE KEY allocation_once (allocation_id), KEY request_state (request_id,state), KEY driver_interval (driver_id,start_at,effective_end), KEY customer_time (customer_id,created_at), KEY org_state (org_id,state)",array('id'=>'char(36)','request_id'=>'char(36)','customer_id'=>'bigint unsigned','org_id'=>'bigint unsigned','resource_id'=>'char(36)','allocation_id'=>'char(36)','driver_id'=>'bigint unsigned','version'=>'bigint unsigned','state'=>'varchar(24)','start_at'=>'varchar(32)','end_at'=>'varchar(32)','effective_end'=>'varchar(32)','payload'=>'longtext','created_at'=>'datetime(6)','updated_at'=>'datetime(6)'),array('PRIMARY'=>array('id'),'allocation_once'=>array('allocation_id')));
        $b=$s::install('atlas_dispatch_events',"id char(36) NOT NULL, dispatch_id char(36) NOT NULL, actor_id bigint unsigned NOT NULL, action varchar(32) NOT NULL, payload longtext NOT NULL, created_at datetime(6) NOT NULL, PRIMARY KEY  (id), KEY object_time (dispatch_id,created_at)",array('id'=>'char(36)','dispatch_id'=>'char(36)','actor_id'=>'bigint unsigned','action'=>'varchar(32)','payload'=>'longtext','created_at'=>'datetime(6)'),array('PRIMARY'=>array('id')));
        self::$ready=$a && $b && \Atlas\Inventory\Local::available() && get_option('atlas_local_inventory_schema')==='3' && self::calendar_ready();
        add_action('rest_api_init',array(__CLASS__,'routes')); add_filter('atlas_operations_dashboard',static function($html,$customer){return $html.self::panel($customer);},16,2);
    }
    private static function calendar_ready() {
        global $wpdb;
        foreach (array(true=>array('id','org_id','record_id','kind','vehicle_kind','license_class','turnaround_minutes'),false=>array('id','resource_id','state','kind','start_at','end_at','effective_end','picked_up_at','quantity','reference','linked_type','linked_id','request_hash','note','disputed','actor_id','created_at','updated_at')) as $resource=>$required) {
            $table=self::calendar((bool)$resource); $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
            $columns=$wpdb->get_results("SHOW COLUMNS FROM `$table`",ARRAY_A); if (strtoupper((string)$engine)!=='INNODB' || !is_array($columns) || array_diff($required,array_column($columns,'Field'))) return false;
            $indexes=array(); foreach ((array)$wpdb->get_results("SHOW INDEX FROM `$table`",ARRAY_A) as $index) if ((int)$index['Non_unique']===0) $indexes[$index['Key_name']][(int)$index['Seq_in_index']]=$index['Column_name'];
            $expected=$resource?array('PRIMARY'=>array('id')):array('PRIMARY'=>array('id'),'resource_reference'=>array('resource_id','reference'));
            foreach ($expected as $name=>$shape) { $parts=$indexes[$name]??array(); ksort($parts); if (array_values($parts)!==$shape) return false; }
        }
        return true;
    }
    public static function routes() {
        $p=array('permission_callback'=>array('Atlas_Partner_Security','permission'));
        register_rest_route('agency/v1','/dispatch',array(array_merge($p,array('methods'=>'GET','callback'=>array(__CLASS__,'listing'))),array_merge($p,array('methods'=>'POST','callback'=>static function($r){return self::command($r,'assign');}))));
        register_rest_route('agency/v1','/dispatch/(?P<id>[a-f0-9-]{36})',array_merge($p,array('methods'=>'GET','callback'=>array(__CLASS__,'get'))));
        foreach (array('supplier_accept','supplier_reject','policy_accept','confirm','en_route','arrived','waiting','contact_attempt','onboard','completed','no_show','cancel') as $op) register_rest_route('agency/v1','/dispatch/(?P<id>[a-f0-9-]{36})/'.$op,array_merge($p,array('methods'=>'POST','callback'=>static function($r) use ($op){return self::command($r,$op);})));
    }
    public static function has_live_request($request,$lock=false) {
        global $wpdb; if (!self::$ready) return false;
        return (bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table()." WHERE request_id=%s AND state NOT IN ('completed','no_show','canceled','supplier_rejected') LIMIT 1".($lock?' FOR UPDATE':''),$request));
    }
    private static function gate($r) { $p=Atlas_Partner_Security::permission($r); return is_wp_error($p)?$p:(self::$ready?true:Atlas_Partner_Security::error('atlas_dispatch_unavailable','Manual dispatch storage or vehicle inventory is unavailable.',503)); }
    private static function agency() { return Auth::agency() && current_user_can('atlas_manage_records'); }
    private static function member($org,$user,$write=false) {
        $role=Auth::member_role((int)$org,(int)$user); return $role!==null && user_can($user,'atlas_manage_records') && (!$write || $role!=='viewer');
    }
    private static function manager($org,$user) { return self::member($org,$user,true) && in_array(Auth::member_role((int)$org,(int)$user),array('manager','partner_admin','owner'),true); }
    private static function operator($row,$write=false) { $user=get_current_user_id(); return self::member($row->org_id,$user,$write) && ((int)$row->driver_id===$user || self::manager($row->org_id,$user)); }
    private static function row($id,$lock=false) { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%s'.($lock?' FOR UPDATE':''),$id)); }
    private static function text($v,$max=190) { if (!is_string($v) || trim($v)==='' || strlen($v)>$max) throw new InvalidArgumentException('text'); return sanitize_textarea_field($v); }
    private static function ref($v) { if (!is_string($v) || !preg_match('/^[A-Za-z0-9._:-]{8,120}$/D',$v)) throw new InvalidArgumentException('reference'); return $v; }
    private static function utc($v) {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.0{1,6})?(?:Z|\+00:00)$/D',$v)) throw new InvalidArgumentException('date');
        try { $d=new DateTimeImmutable($v); } catch (Throwable $e) { throw new InvalidArgumentException('date'); }
        $e=DateTimeImmutable::getLastErrors(); if (is_array($e) && ($e['warning_count'] || $e['error_count'])) throw new InvalidArgumentException('date'); return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
    private static function policy($v) {
        if (!is_array($v) || array_diff(array_keys($v),array('waiting_minutes','minimum_contact_attempts','waiting_fee_irr','no_show_fee_irr','waiting_terms','no_show_terms','cancellation_terms')) || count($v)!==7 || !is_int($v['waiting_minutes']??null) || $v['waiting_minutes']<1 || $v['waiting_minutes']>240 || !is_int($v['minimum_contact_attempts']??null) || $v['minimum_contact_attempts']<1 || $v['minimum_contact_attempts']>5) throw new InvalidArgumentException('policy');
        $out=array('waiting_minutes'=>$v['waiting_minutes'],'minimum_contact_attempts'=>$v['minimum_contact_attempts']);
        foreach (array('waiting_fee_irr','no_show_fee_irr') as $key) { if ((!is_string($v[$key]) && !is_int($v[$key])) || !preg_match('/^(0|[1-9][0-9]{0,11})$/D',(string)$v[$key])) throw new InvalidArgumentException('IRR'); $out[$key]=(string)$v[$key]; }
        foreach (array('waiting_terms','no_show_terms','cancellation_terms') as $key) $out[$key]=self::text($v[$key],1500);
        return $out;
    }
    private static function fields($body,$names) { if (!is_array($body) || array_diff(array_keys($body),$names) || count($body)!==count($names)) throw new InvalidArgumentException('fields'); }
    private static function project($row) {
        $p=json_decode($row->payload,true); $owner=(int)$row->customer_id===get_current_user_id();
        $out=array('id'=>$row->id,'request_id'=>$row->request_id,'version'=>(int)$row->version,'state'=>$row->state,'updated_at'=>$row->updated_at,'mode'=>'manual_dispatch','live_gps'=>false,'charges_applied'=>false,'money_status'=>'no_collection','pickup_at'=>$p['request_snapshot']['pickup_at'],'dropoff_at'=>$p['request_snapshot']['dropoff_at'],'pickup_zone'=>$p['request_snapshot']['pickup_zone'],'dropoff_zone'=>$p['request_snapshot']['dropoff_zone'],'pickup'=>$p['request_snapshot']['pickup'],'dropoff'=>$p['request_snapshot']['dropoff'],'vehicle_class'=>$p['request_snapshot']['vehicle_class'],'policy'=>$p['policy'],'policy_hash'=>$p['policy_hash'],'policy_accepted'=>(bool)$p['policy_accepted_by'],'driver_assigned'=>(bool)$row->driver_id);
        if (self::agency() || self::operator($row)) {
            if ($row->state==='offered') unset($p['request_snapshot']['contact']);
            $out+=array('org_id'=>(int)$row->org_id,'driver_id'=>(int)$row->driver_id,'resource_id'=>$row->resource_id,'allocation_id'=>$row->allocation_id,'payload'=>$p);
        }
        return $out;
    }
    public static function listing($r) {
        global $wpdb; $ok=self::gate($r); if (is_wp_error($ok)) return $ok; $page=(int)($r->get_param('page')?:1); $customer=$r->get_param('view')==='customer';
        if ($page<1 || $page>10000) return Atlas_Partner_Security::error('atlas_invalid_page','Invalid page.');
        $where='1=1'; $user=get_current_user_id();
        if ($customer) $where=$wpdb->prepare('customer_id=%d',$user);
        elseif (!self::agency()) $where=$wpdb->prepare('driver_id=%d OR org_id IN (SELECT m.org_id FROM '.DB::table('memberships').' m INNER JOIN '.DB::table('organizations')." o ON o.id=m.org_id WHERE m.user_id=%d AND m.status='active' AND m.role IN ('manager','partner_admin','owner') AND o.status IN ('approved','active'))",$user,$user);
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table()." WHERE $where ORDER BY created_at DESC,id LIMIT 25 OFFSET %d",($page-1)*25)); $items=array();
        foreach ($rows as $row) if ((int)$row->customer_id===$user || self::agency() || self::operator($row)) $items[]=self::project($row);
        if (!DB::audit($user,0,'dispatch.list','dispatch','read')) return Atlas_Partner_Security::error('atlas_audit_failed','Read could not be recorded.',503);
        return Atlas_Partner_Security::response(array('items'=>$items,'page'=>$page,'limit'=>25));
    }
    public static function get($r) {
        global $wpdb; $ok=self::gate($r); if (is_wp_error($ok)) return $ok; $row=self::row((string)$r['id']);
        if (!$row || (!self::agency() && (int)$row->customer_id!==get_current_user_id() && !self::operator($row))) return Atlas_Partner_Security::error('atlas_not_found','Dispatch record unavailable.',404);
        $out=self::project($row); if (self::agency() || self::operator($row)) {
            $page=(int)($r->get_param('page')?:1); if ($page<1 || $page>10000) return Atlas_Partner_Security::error('atlas_invalid_page','Invalid page.');
            $out['events']=$wpdb->get_results($wpdb->prepare('SELECT action,actor_id,payload,created_at FROM '.self::table(true).' WHERE dispatch_id=%s ORDER BY created_at DESC,id LIMIT 25 OFFSET %d',$row->id,($page-1)*25),ARRAY_A);
        }
        if (!DB::audit(get_current_user_id(),(int)$row->org_id,'dispatch.read',$row->id,'read')) return Atlas_Partner_Security::error('atlas_audit_failed','Read could not be recorded.',503); return Atlas_Partner_Security::response($out);
    }
    public static function command($r,$op) {
        global $wpdb; $s='Atlas_Partner_Security'; $ok=self::gate($r); if (is_wp_error($ok)) return $ok; $b=$r->get_json_params(); $key=(string)$r->get_header('Idempotency-Key'); $actor=get_current_user_id();
        if (!is_array($b) || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$key)) return $s::error('atlas_invalid_request','A complete request and request key are required.');
        $id=$op==='assign'?'':(string)$r['id']; $initial=$id?self::row($id):null;
        if ($op==='assign' && !self::agency()) return $s::error('atlas_forbidden','Agency dispatcher authority required.',403);
        if ($op!=='assign' && (!$initial || (!self::agency() && (int)$initial->customer_id!==$actor && !self::operator($initial,true)))) return $s::error('atlas_not_found','Dispatch record unavailable.',404);
        if ($op==='confirm' && !self::agency()) return $s::error('atlas_forbidden','Agency dispatcher authority required.',403);
        if (in_array($op,array('supplier_accept','supplier_reject'),true) && (self::agency() || !self::manager((int)$initial->org_id,$actor))) return $s::error('atlas_forbidden','The assigned operator must respond.',403);
        if ($op==='policy_accept' && (int)$initial->customer_id!==$actor) return $s::error('atlas_forbidden','Only the requesting customer can accept these terms.',403);
        if (!in_array($op,array('assign','policy_accept','cancel'),true) && !self::agency() && !self::operator($initial,true)) return $s::error('atlas_forbidden','Assigned operational authority required.',403);
        ksort($b,SORT_STRING); $scope='user:'.$actor; $operation='dispatch.'.$op; $hash=hash('sha256',wp_json_encode(array($id,$b))); $new=$op==='assign';
        if ($wpdb->query('START TRANSACTION')===false) return $s::error('atlas_dispatch_failed','Dispatch transaction could not start.',503);
        try {
            if ($new) {
                self::fields($b,array('request_id','expected_request_version','org_id','resource_id','driver_id','positioning_minutes','policy','vehicle_review_reference','driver_review_reference','coverage_review_reference','review_valid_until','reason'));
                if (!is_string($b['request_id']) || !is_string($b['resource_id']) || !is_int($b['org_id']) || $b['org_id']<1 || !is_int($b['driver_id']) || $b['driver_id']<1 || !is_int($b['expected_request_version']) || !is_int($b['positioning_minutes']) || $b['positioning_minutes']<0 || $b['positioning_minutes']>180) throw new InvalidArgumentException('assignment');
                $org=$b['org_id']; $driver=$b['driver_id']; $resourceId=$b['resource_id'];
            } else { $org=(int)$initial->org_id; $driver=(int)$initial->driver_id; $resourceId=$initial->resource_id; }
            $organization=$s::active_org($org,true);
            // Driver mutex serializes assignments across different vehicles/orgs.
            if (!$wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->users WHERE ID=%d FOR UPDATE",$driver))) throw new DomainException('driver');
            $resource=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::calendar(true).' WHERE id=%s FOR UPDATE',$resourceId));
            if (!$resource || $resource->kind!=='vehicle' || (int)$resource->org_id!==$org || $resource->vehicle_kind==='') throw new DomainException('resource');
            $row=$new?null:self::row($id,true); $allocation=$new?null:$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::calendar().' WHERE id=%s FOR UPDATE',$row->allocation_id));
            if (!$new && (!$row || !$allocation || $allocation->linked_type!=='dispatch' || $allocation->linked_id!==$id)) throw new DomainException('allocation');
            if (!$new && !self::agency() && (int)$row->customer_id!==$actor && !self::operator($row,true)) throw new DomainException('permission changed');
            $prior=$wpdb->get_row($wpdb->prepare('SELECT request_hash,response FROM '.DB::table('idempotency').' WHERE scope=%s AND operation=%s AND idem_key=%s',$scope,$operation,$key));
            if ($prior) { $wpdb->query('ROLLBACK'); return hash_equals($prior->request_hash,$hash)?$s::response(json_decode($prior->response,true)):$s::error('atlas_idempotency_conflict','The request key was reused.',409); }
            $now=DB::now(); $instant=(new DateTimeImmutable($now,new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
            if ($new) {
                if (!$s::active($organization) || !self::member($org,$driver,true) || !Atlas_Partner_Contracts::has_active_contract($org,'transfer')) throw new DomainException('operator or driver');
                $request=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('records').' WHERE id=%s AND type=%s FOR UPDATE',$b['request_id'],'transfer_request'));
                if (!$request || (int)$request->version!==$b['expected_request_version'] || in_array($request->state,array('rejected','closed'),true) || (int)$request->org_id!==0 || self::has_live_request($request->id,true)) throw new DomainException('request');
                $snapshot=json_decode($request->payload,true); $pickup=self::utc($snapshot['pickup_at']??null); $dropoff=self::utc($snapshot['dropoff_at']??null);
                $snapshot['pickup_at']=$pickup; $snapshot['dropoff_at']=$dropoff;
                $start=(new DateTimeImmutable($pickup))->modify('-'.$b['positioning_minutes'].' minutes')->format('Y-m-d\TH:i:s\Z'); $effective=(new DateTimeImmutable($dropoff))->modify('+'.(int)$resource->turnaround_minutes.' minutes')->format('Y-m-d\TH:i:s\Z');
                if ($pickup<=$instant || $dropoff<=$pickup || !is_int($snapshot['passengers']??null) || $snapshot['passengers']<1) throw new DomainException('schedule');
                $vehicle=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('records').' WHERE id=%s AND type=%s FOR UPDATE',$resource->record_id,'vehicle')); $vf=$vehicle?json_decode($vehicle->payload,true):array();
                if (!$vehicle || (int)$vehicle->org_id!==$org || $vehicle->state!=='approved' || ($vf['category']??'')!==$resource->vehicle_kind || trim((string)($vf['license_class']??''))!==$resource->license_class || (int)($vf['capacity']??0)<$snapshot['passengers'] || !in_array($resource->vehicle_kind,array('car','van'),true) || ($snapshot['vehicle_class']==='van' && $resource->vehicle_kind!=='van') || ($snapshot['vehicle_class']==='accessible' && empty($snapshot['accessibility']))) throw new DomainException('vehicle eligibility');
                $reviewUntil=self::utc($b['review_valid_until']); if ($reviewUntil<=$dropoff) throw new DomainException('coverage expiry');
                $overlap=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::calendar()." WHERE resource_id=%s AND state IN ('allocated','in_use','blocked_conflict','returned','disputed') AND (state IN ('in_use','disputed') OR (start_at<%s AND effective_end>%s)) LIMIT 1 FOR UPDATE",$resourceId,$effective,$start));
                $driverOverlap=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table()." WHERE driver_id=%d AND state NOT IN ('completed','no_show','canceled','supplier_rejected') AND (state IN ('en_route','arrived','waiting','onboard') OR (start_at<%s AND effective_end>%s)) LIMIT 1 FOR UPDATE",$driver,$effective,$start));
                if ($overlap || $driverOverlap) throw new DomainException('availability');
                $id=wp_generate_uuid4(); $allocationId=wp_generate_uuid4(); $policy=self::policy($b['policy']); $state='offered';
                $p=array('request_snapshot'=>$snapshot,'request_version'=>(int)$request->version,'request_source_hash'=>hash('sha256',$request->payload),'request_snapshot_hash'=>hash('sha256',wp_json_encode($snapshot)),'vehicle_record_version'=>(int)$vehicle->version,'policy'=>$policy,'policy_hash'=>hash('sha256',wp_json_encode(array($id,$policy))),'policy_accepted_by'=>0,'supplier_accepted_by'=>0,'arrived_at'=>null,'contact_attempts'=>0,'contact_references'=>array(),'positioning_minutes'=>$b['positioning_minutes'],'vehicle_review_reference'=>self::ref($b['vehicle_review_reference']),'driver_review_reference'=>self::ref($b['driver_review_reference']),'coverage_review_reference'=>self::ref($b['coverage_review_reference']),'review_valid_until'=>$reviewUntil,'manual_evidence_only'=>true);
                self::text($b['reason']);
                if (!$wpdb->insert(self::calendar(),array('id'=>$allocationId,'resource_id'=>$resourceId,'kind'=>'transfer','state'=>'allocated','start_at'=>$start,'end_at'=>$dropoff,'effective_end'=>$effective,'quantity'=>1,'reference'=>'dispatch:'.$id,'linked_type'=>'dispatch','linked_id'=>$id,'request_hash'=>$hash,'note'=>'Manual chauffeured dispatch; no GPS or charge.','disputed'=>0,'actor_id'=>$actor,'created_at'=>$now,'updated_at'=>$now)) || !$wpdb->insert(self::table(),array('id'=>$id,'request_id'=>$request->id,'customer_id'=>(int)$request->owner_id,'org_id'=>$org,'resource_id'=>$resourceId,'allocation_id'=>$allocationId,'driver_id'=>$driver,'version'=>1,'state'=>$state,'start_at'=>$start,'end_at'=>$dropoff,'effective_end'=>$effective,'payload'=>wp_json_encode($p),'created_at'=>$now,'updated_at'=>$now))) throw new RuntimeException('assign');
            } else {
                if (!isset($b['expected_version']) || !is_int($b['expected_version']) || $b['expected_version']!==(int)$row->version || in_array($row->state,self::TERMINAL,true)) throw new DomainException('version');
                $p=json_decode($row->payload,true); $state=$row->state; $base=array('expected_version','reason','evidence_reference'); self::text($b['reason']??null); self::ref($b['evidence_reference']??null);
                self::fields($b,array_merge($base,$op==='policy_accept'?array('policy_hash','accepted'):($op==='contact_attempt'?array('channel'):array())));
                if (in_array($op,array('confirm','en_route'),true)) {
                    $vehicle=$wpdb->get_row($wpdb->prepare('SELECT state,version FROM '.DB::table('records').' WHERE id=%s AND type=%s FOR UPDATE',$resource->record_id,'vehicle'));
                    if (!$vehicle || $vehicle->state!=='approved' || (int)$vehicle->version!==$p['vehicle_record_version']) throw new DomainException('vehicle review changed');
                }
                if ($op==='supplier_accept') { if ($state!=='offered' || !$s::active($organization) || !self::manager($org,$actor) || !Atlas_Partner_Contracts::has_active_contract($org,'transfer')) throw new DomainException('operator acceptance'); $p['supplier_accepted_by']=$actor; $state='supplier_accepted'; }
                elseif ($op==='supplier_reject') { if ($state!=='offered' || !self::manager($org,$actor)) throw new DomainException('operator response'); $state='supplier_rejected'; }
                elseif ($op==='policy_accept') { if (!in_array($state,array('offered','supplier_accepted'),true) || $p['policy_accepted_by'] || ($b['accepted']??null)!==true || !is_string($b['policy_hash']) || !hash_equals($p['policy_hash'],$b['policy_hash'])) throw new DomainException('policy'); $p['policy_accepted_by']=$actor; $p['policy_accepted_at']=$now; }
                elseif ($op==='confirm') { if ($state!=='supplier_accepted' || !$p['policy_accepted_by'] || !$p['supplier_accepted_by'] || !$s::active($organization) || !self::member($org,$driver,true) || !Atlas_Partner_Contracts::has_active_contract($org,'transfer') || $instant>=$p['review_valid_until'] || $allocation->state!=='allocated') throw new DomainException('confirmation'); $state='confirmed'; }
                elseif ($op==='en_route') {
                    if ($state!=='confirmed' || !$s::active($organization) || !self::member($org,$driver,true) || !Atlas_Partner_Contracts::has_active_contract($org,'transfer') || $instant<$row->start_at || $instant>=$row->end_at || $instant>=$p['review_valid_until'] || $allocation->state!=='allocated') throw new DomainException('start');
                    if ($wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::calendar()." WHERE resource_id=%s AND id<>%s AND state IN ('in_use','disputed') LIMIT 1 FOR UPDATE",$resourceId,$allocation->id)) || $wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table()." WHERE driver_id=%d AND id<>%s AND state IN ('en_route','arrived','waiting','onboard') LIMIT 1 FOR UPDATE",$driver,$id))) throw new DomainException('prior vehicle/driver not returned');
                    $state='en_route'; $p['en_route_at']=$now; if ($wpdb->update(self::calendar(),array('state'=>'in_use','picked_up_at'=>$now,'updated_at'=>$now),array('id'=>$allocation->id))!==1) throw new RuntimeException('start');
                }
                elseif ($op==='arrived') { if ($state!=='en_route') throw new DomainException('arrival'); $state='arrived'; $p['arrived_at']=$now; }
                elseif ($op==='waiting') { if ($state!=='arrived') throw new DomainException('waiting'); $state='waiting'; }
                elseif ($op==='contact_attempt') { if (!in_array($state,array('arrived','waiting'),true) || !in_array($b['channel'],array('phone','message'),true) || $p['contact_attempts']>=20 || in_array($b['evidence_reference'],$p['contact_references'],true)) throw new DomainException('contact'); $p['contact_attempts']++; $p['contact_references'][]=$b['evidence_reference']; }
                elseif ($op==='onboard') { if (!in_array($state,array('arrived','waiting'),true) || $instant<$p['request_snapshot']['pickup_at'] || $instant>=$p['review_valid_until']) throw new DomainException('boarding'); $state='onboard'; $p['onboard_at']=$now; }
                elseif ($op==='completed') { if ($state!=='onboard') throw new DomainException('completion'); $state='completed'; $p['completed_at']=$now; }
                elseif ($op==='no_show') {
                    $from=max((string)$p['arrived_at'],str_replace('T',' ',rtrim($p['request_snapshot']['pickup_at'],'Z')));
                    $deadline=(new DateTimeImmutable($from,new DateTimeZone('UTC')))->modify('+'.$p['policy']['waiting_minutes'].' minutes')->format('Y-m-d H:i:s.u');
                    if (!in_array($state,array('arrived','waiting'),true) || !$p['policy_accepted_by'] || $now<$deadline || $p['contact_attempts']<$p['policy']['minimum_contact_attempts']) throw new DomainException('no-show policy'); $state='no_show'; $p['no_show_at']=$now;
                }
                elseif ($op==='cancel') { if ($state==='onboard') throw new DomainException('safety escalation required'); $state='canceled'; }
                else throw new InvalidArgumentException('operation');
                if (in_array($state,self::TERMINAL,true)) {
                    $effective=(new DateTimeImmutable($now,new DateTimeZone('UTC')))->modify('+'.(int)$resource->turnaround_minutes.' minutes')->format('Y-m-d\TH:i:s\Z');
                    $calendarState=in_array($allocation->state,array('allocated','blocked_conflict'),true)?'released':'returned';
                    if ($wpdb->update(self::calendar(),array('state'=>$calendarState,'effective_end'=>$calendarState==='returned'?max($allocation->effective_end,$effective):$allocation->effective_end,'updated_at'=>$now),array('id'=>$allocation->id))!==1) throw new RuntimeException('finish');
                    if ($calendarState==='returned') {
                        $conflicts=$wpdb->get_results($wpdb->prepare('SELECT id,linked_type,linked_id FROM '.self::calendar()." WHERE resource_id=%s AND id<>%s AND state='allocated' AND start_at<%s AND effective_end>%s FOR UPDATE",$resourceId,$allocation->id,$effective,$row->start_at));
                        foreach ($conflicts as $other) {
                            if ($wpdb->update(self::calendar(),array('state'=>'blocked_conflict','updated_at'=>$now),array('id'=>$other->id))!==1 || !DB::audit($actor,$org,'dispatch.calendar_conflict',$other->id,'success','actual completion extended vehicle buffer')) throw new RuntimeException('conflict');
                            if ($other->linked_type==='dispatch') {
                                $changed=$wpdb->query($wpdb->prepare('UPDATE '.self::table()." SET state='schedule_conflict',version=version+1,updated_at=%s WHERE id=%s AND state IN ('offered','supplier_accepted','confirmed')",$now,$other->linked_id));
                                if ($changed===false || ($changed===1 && (!$wpdb->insert(self::table(true),array('id'=>wp_generate_uuid4(),'dispatch_id'=>$other->linked_id,'actor_id'=>$actor,'action'=>'schedule_conflict','payload'=>wp_json_encode(array('cause'=>'actual_vehicle_buffer_extended')),'created_at'=>$now)) || !DB::emit('dispatch.schedule_conflict',$other->linked_id)))) throw new RuntimeException('conflict event');
                            }
                        }
                        $driverConflicts=$wpdb->get_results($wpdb->prepare('SELECT id,org_id FROM '.self::table()." WHERE driver_id=%d AND id<>%s AND state IN ('offered','supplier_accepted','confirmed') AND start_at<%s AND effective_end>%s FOR UPDATE",$driver,$id,$effective,$row->start_at));
                        foreach ($driverConflicts as $other) { if ($wpdb->update(self::table(),array('state'=>'schedule_conflict','updated_at'=>$now),array('id'=>$other->id))!==1 || $wpdb->query($wpdb->prepare('UPDATE '.self::table().' SET version=version+1 WHERE id=%s',$other->id))!==1 || !$wpdb->insert(self::table(true),array('id'=>wp_generate_uuid4(),'dispatch_id'=>$other->id,'actor_id'=>$actor,'action'=>'schedule_conflict','payload'=>wp_json_encode(array('cause'=>'actual_driver_availability_extended')),'created_at'=>$now)) || !DB::audit($actor,(int)$other->org_id,'dispatch.driver_conflict',$other->id,'success','actual completion extended driver availability') || !DB::emit('dispatch.schedule_conflict',$other->id)) throw new RuntimeException('driver conflict'); }
                    }
                }
                if ($wpdb->update(self::table(),array('state'=>$state,'payload'=>wp_json_encode($p),'version'=>(int)$row->version+1,'updated_at'=>$now),array('id'=>$id,'version'=>(int)$row->version))!==1) throw new RuntimeException('update');
            }
            $result=self::project(self::row($id));
            if (!$wpdb->insert(self::table(true),array('id'=>wp_generate_uuid4(),'dispatch_id'=>$id,'actor_id'=>$actor,'action'=>$op,'payload'=>wp_json_encode($b),'created_at'=>$now)) || !DB::audit($actor,$org,'dispatch.'.$op,$id,'success',$b['reason']??'manual dispatch') || !DB::emit('dispatch.'.$op,$id) || !$wpdb->insert(DB::table('idempotency'),array('scope'=>$scope,'operation'=>$operation,'idem_key'=>$key,'request_hash'=>$hash,'response'=>wp_json_encode($result),'created_at'=>$now))) throw new RuntimeException('audit');
            if ($wpdb->query('COMMIT')===false) throw new RuntimeException('commit'); return $s::response($result,$new?201:200);
        } catch (InvalidArgumentException $e) { $wpdb->query('ROLLBACK'); return $s::error('atlas_invalid_dispatch','Check the request, review evidence, dates and explicit waiting policy.'); }
        catch (DomainException $e) { $wpdb->query('ROLLBACK'); return $s::error('atlas_dispatch_conflict','Authority, vehicle/driver availability, revision or dispatch policy requires review.',409); }
        catch (Throwable $e) { $wpdb->query('ROLLBACK'); $prior=$wpdb->get_row($wpdb->prepare('SELECT request_hash,response FROM '.DB::table('idempotency').' WHERE scope=%s AND operation=%s AND idem_key=%s',$scope,$operation,$key)); if ($prior) return hash_equals($prior->request_hash,$hash)?$s::response(json_decode($prior->response,true)):$s::error('atlas_idempotency_conflict','The request key was reused.',409); return $s::error('atlas_dispatch_failed','Dispatch change could not be recorded.',503); }
    }
    private static function action_form($row,$op,$label,$extra=array(),$hidden=array()) {
        $s='Atlas_Partner_Security'; $hidden=array_merge(array('expected_version'=>$row['version'],'evidence_reference'=>wp_generate_uuid4()),$hidden);
        $html='<form data-atlas-dispatch data-endpoint="dispatch/'.esc_attr($row['id']).'/'.esc_attr($op).'">';
        foreach ($hidden as $name=>$value) $html.='<input type="hidden" name="'.esc_attr($name).'" value="'.esc_attr((string)$value).'" data-type="'.(is_int($value)?'integer':'text').'">';
        if (!isset($hidden['reason'])) $html.='<label>'.esc_html($s::t('Manual operational notes / reason','یادداشت عملیاتی دستی / دلیل')).'<input name="reason" maxlength="190" required></label>';
        foreach ($extra as $name=>$type) {
            if ($name==='accepted') $html.='<label><input name="accepted" type="checkbox" data-type="boolean" required>'.esc_html($s::t('I explicitly accept the displayed waiting, no-show and cancellation policy.','شرایط نمایش‌داده‌شده انتظار، عدم حضور و لغو را صریحاً می‌پذیرم.')).'</label>';
            elseif ($name==='channel') $html.='<label>'.esc_html($s::t('Contact attempt already made','تلاش برای تماس که انجام شده است')).'<select name="channel"><option value="phone">'.esc_html($s::t('Phone','تلفن')).'</option><option value="message">'.esc_html($s::t('Message','پیام')).'</option></select></label>';
        }
        return $html.'<button>'.esc_html($label).'</button><p role="status" aria-live="polite"></p></form>';
    }
    private static function assignment_form() {
        global $wpdb; $s='Atlas_Partner_Security';
        $requests=$wpdb->get_results('SELECT id,owner_id,version,payload FROM '.DB::table('records')." WHERE type='transfer_request' AND org_id=0 AND state NOT IN ('closed','rejected') ORDER BY created_at DESC LIMIT 100");
        $resources=$wpdb->get_results('SELECT r.id,r.org_id,r.vehicle_kind,v.payload FROM '.self::calendar(true).' r INNER JOIN '.DB::table('records')." v ON v.id=r.record_id AND v.type='vehicle' AND v.state='approved' AND v.org_id=r.org_id WHERE r.kind='vehicle' AND r.vehicle_kind IN ('car','van') ORDER BY r.org_id,r.id LIMIT 100");
        $drivers=$wpdb->get_results('SELECT m.org_id,m.user_id,u.display_name FROM '.DB::table('memberships')." m INNER JOIN $wpdb->users u ON u.ID=m.user_id INNER JOIN ".DB::table('organizations')." o ON o.id=m.org_id WHERE m.status='active' AND m.role<>'viewer' AND o.status IN ('approved','active') ORDER BY m.org_id,m.user_id LIMIT 500");
        $html='<details><summary>'.esc_html($s::t('Offer a reviewed driver/vehicle assignment','ارائه تخصیص راننده و خودروی بررسی‌شده')).'</summary><p>'.esc_html($s::t('The operator and customer must accept before agency confirmation. Review references attest a manual safety/coverage check; no license documents are collected here.','پیش از تأیید آژانس، پذیرش اپراتور و مشتری لازم است. مراجع بررسی، ثبت بررسی دستی ایمنی و پوشش هستند؛ مدارک گواهینامه در این بخش جمع‌آوری نمی‌شود.')).'</p><form data-atlas-dispatch data-endpoint="dispatch"><label>'.esc_html($s::t('Transfer inquiry','درخواست ترانسفر')).'<select name="request_id" data-request-select required>';
        foreach ($requests as $request) { if (self::has_live_request($request->id)) continue; $p=json_decode($request->payload,true); $html.='<option value="'.esc_attr($request->id).'" data-version="'.esc_attr($request->version).'">'.esc_html(($p['pickup']??'').' → '.($p['dropoff']??'').' — '.($p['pickup_at']??'').' — '.$request->id).'</option>'; }
        $html.='</select></label><input type="hidden" name="expected_request_version" data-type="integer"><label>'.esc_html($s::t('Vehicle / operator organization','خودرو / سازمان اپراتور')).'<select name="resource_id" data-resource-select required>';
        foreach ($resources as $resource) { $p=json_decode($resource->payload,true); $html.='<option value="'.esc_attr($resource->id).'" data-org="'.esc_attr($resource->org_id).'">'.esc_html(($p['label']??$resource->vehicle_kind).' — '.$resource->org_id.' — '.$resource->id).'</option>'; }
        $html.='</select></label><input type="hidden" name="org_id" data-type="integer"><label>'.esc_html($s::t('Active scoped driver account','حساب راننده فعال در سازمان')).'<select name="driver_id" data-driver-select data-type="integer" required>';
        foreach ($drivers as $driver) if (self::member((int)$driver->org_id,(int)$driver->user_id,true)) $html.='<option value="'.esc_attr($driver->user_id).'" data-org="'.esc_attr($driver->org_id).'">'.esc_html($driver->display_name.' — '.$driver->user_id).'</option>';
        $html.='</select></label>';
        $inputs=array('positioning_minutes'=>array('Positioning buffer before pickup (minutes)','بازه آماده‌سازی پیش از سوار شدن (دقیقه)','integer','0'),'vehicle_review_reference'=>array('Reviewed vehicle safety evidence reference','مرجع شواهد بررسی ایمنی خودرو','text',''),'driver_review_reference'=>array('Reviewed driver eligibility evidence reference','مرجع شواهد بررسی صلاحیت راننده','text',''),'coverage_review_reference'=>array('Reviewed insurance/coverage evidence reference','مرجع شواهد بررسی بیمه و پوشش','text',''),'review_valid_until'=>array('Review valid until (UTC ISO 8601, whole seconds)','اعتبار بررسی تا (UTC، ISO 8601، ثانیه کامل)','text',''),'policy.waiting_minutes'=>array('Minimum waiting minutes','حداقل دقیقه انتظار','integer','15'),'policy.minimum_contact_attempts'=>array('Minimum distinct contact attempts before no-show','حداقل تلاش مستقل تماس پیش از عدم حضور','integer','2'),'policy.waiting_fee_irr'=>array('Disclosed waiting fee (IRR; never charged automatically)','هزینه انتظار اعلام‌شده (ریال؛ بدون برداشت خودکار)','text','0'),'policy.no_show_fee_irr'=>array('Disclosed no-show fee (IRR; never charged automatically)','هزینه عدم حضور اعلام‌شده (ریال؛ بدون برداشت خودکار)','text','0'));
        foreach ($inputs as $name=>$i) $html.='<label>'.esc_html($s::t($i[0],$i[1])).'<input name="'.esc_attr($name).'" data-type="'.esc_attr($i[2]).'" value="'.esc_attr($i[3]).'" required maxlength="120"></label>';
        foreach (array('waiting_terms'=>array('Explicit waiting policy and fee basis','شرایط صریح انتظار و مبنای هزینه'),'no_show_terms'=>array('Explicit no-show policy','شرایط صریح عدم حضور'),'cancellation_terms'=>array('Explicit cancellation policy','شرایط صریح لغو')) as $name=>$i) $html.='<label>'.esc_html($s::t($i[0],$i[1])).'<textarea name="policy.'.$name.'" maxlength="1500" required></textarea></label>';
        $html.='<label>'.esc_html($s::t('Assignment review reason','دلیل بررسی تخصیص')).'<input name="reason" maxlength="190" required></label><button>'.esc_html($s::t('Offer reviewed assignment','ارائه تخصیص بررسی‌شده')).'</button><p role="status" aria-live="polite"></p></form></details>';
        return $html;
    }
    public static function panel($customer=false) {
        $s='Atlas_Partner_Security'; if (!is_user_logged_in()) return ''; if (!self::$ready) return self::agency()?'<p>'.esc_html($s::t('Dispatch requires ready local vehicle inventory.','اعزام نیازمند آماده بودن تقویم محلی خودرو است.')).'</p>':'';
        if (!headers_sent()) nocache_headers();
        wp_enqueue_script('atlas-dispatch',plugins_url('assets/dispatch.js',__FILE__),array(),'0.1.0',true);
        wp_localize_script('atlas-dispatch','atlasDispatch',array('base'=>esc_url_raw(rest_url('agency/v1/')),'nonce'=>wp_create_nonce('wp_rest'),'saved'=>$s::t('Recorded. Refresh to see the current stage.','ثبت شد. برای دیدن مرحله فعلی صفحه را تازه کنید.'),'failed'=>$s::t('Could not record. Check the current stage and policy.','ثبت نشد. مرحله و شرایط فعلی را بررسی کنید.'),'refresh'=>$s::t('Refresh status','تازه‌سازی وضعیت')));
        $html='<details><summary>'.esc_html($s::t($customer?'My transfer status':'Manual transfer dispatch',$customer?'وضعیت ترانسفر من':'اعزام دستی ترانسفر')).'</summary><p>'.esc_html($s::t('Statuses are recorded operational updates. No live location/GPS is shown. These controls never collect waiting, cancellation or no-show fees.','وضعیت‌ها به صورت عملیاتی ثبت می‌شوند. موقعیت یا GPS زنده نمایش داده نمی‌شود. این کنترل‌ها هزینه انتظار، لغو یا عدم حضور را برداشت نمی‌کنند.')).'</p>';
        if (!$customer && self::agency()) $html.=self::assignment_form();
        $page=isset($_GET['atlas_dispatch_page']) && is_scalar($_GET['atlas_dispatch_page'])?max(1,min(10000,(int)$_GET['atlas_dispatch_page'])):1;
        $r=new WP_REST_Request('GET','/'); $r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest')); $r->set_query_params(array('view'=>$customer?'customer':'operator','page'=>$page)); $list=self::listing($r); if (is_wp_error($list)) return $html.'<p>'.esc_html($list->get_error_message()).'</p></details>';
        $labels=array('offered'=>array('Awaiting operator acceptance','در انتظار پذیرش اپراتور'),'supplier_accepted'=>array('Operator accepted; agency confirmation pending','اپراتور پذیرفته؛ در انتظار تأیید آژانس'),'confirmed'=>array('Manually confirmed','تأیید دستی'),'en_route'=>array('Driver reported en route','راننده حرکت را گزارش کرده'),'arrived'=>array('Driver reported arrival','راننده رسیدن را گزارش کرده'),'waiting'=>array('Waiting under accepted policy','انتظار طبق شرایط پذیرفته‌شده'),'onboard'=>array('Passenger boarding recorded','سوار شدن مسافر ثبت شد'),'completed'=>array('Transfer completion recorded','پایان ترانسفر ثبت شد'),'no_show'=>array('No-show recorded; fee review separate','عدم حضور ثبت شد؛ بررسی هزینه جداگانه است'),'canceled'=>array('Canceled; fee/refund review separate','لغو شد؛ بررسی هزینه یا بازپرداخت جداگانه است'),'supplier_rejected'=>array('Operator rejected assignment','اپراتور تخصیص را رد کرد'),'schedule_conflict'=>array('Schedule conflict; agency replacement review required','تداخل زمان؛ بررسی جایگزین توسط آژانس لازم است'));
        foreach ($list->get_data()['items'] as $item) {
            $label=$labels[$item['state']]??array($item['state'],$item['state']); $html.='<article><h3>'.esc_html($s::t($label[0],$label[1])).'</h3><p>'.esc_html($item['pickup'].' → '.$item['dropoff']).'</p><p><bdi dir="ltr">'.esc_html($item['pickup_at'].' / '.$item['pickup_zone'].' → '.$item['dropoff_at'].' / '.$item['dropoff_zone']).'</bdi></p><p>'.esc_html($s::t('Last recorded update: ','آخرین به‌روزرسانی ثبت‌شده: ').$item['updated_at']).'</p><dl>';
            foreach (array('waiting_terms','no_show_terms','cancellation_terms') as $field) $html.='<dt>'.esc_html($s::t($field==='waiting_terms'?'Waiting policy':($field==='no_show_terms'?'No-show policy':'Cancellation policy'),$field==='waiting_terms'?'شرایط انتظار':($field==='no_show_terms'?'شرایط عدم حضور':'شرایط لغو'))).'</dt><dd>'.nl2br(esc_html($item['policy'][$field])).'</dd>';
            $html.='</dl><p>'.esc_html($s::t('Waiting minutes / contact attempts / waiting fee IRR / no-show fee IRR: ','دقایق انتظار / تلاش تماس / هزینه انتظار ریال / هزینه عدم حضور ریال: ').$item['policy']['waiting_minutes'].' / '.$item['policy']['minimum_contact_attempts'].' / '.$item['policy']['waiting_fee_irr'].' / '.$item['policy']['no_show_fee_irr']).'</p>';
            $row=self::row($item['id']); $owner=(int)$row->customer_id===get_current_user_id();
            if ($owner && !$item['policy_accepted'] && in_array($item['state'],array('offered','supplier_accepted'),true)) $html.=self::action_form($item,'policy_accept',$s::t('Accept these exact dispatch terms','پذیرش همین شرایط اعزام'),array('accepted'=>'boolean'),array('policy_hash'=>$item['policy_hash'],'reason'=>'Customer explicitly accepted displayed dispatch terms'));
            if (!$customer && self::manager((int)$row->org_id,get_current_user_id()) && !self::agency() && $item['state']==='offered') { $html.=self::action_form($item,'supplier_accept',$s::t('Operator accepts assignment','پذیرش تخصیص توسط اپراتور')); $html.=self::action_form($item,'supplier_reject',$s::t('Operator rejects assignment','رد تخصیص توسط اپراتور')); }
            if (!$customer && self::agency() && $item['state']==='supplier_accepted' && $item['policy_accepted']) $html.=self::action_form($item,'confirm',$s::t('Record agency confirmation','ثبت تأیید آژانس'));
            if (!$customer && (self::agency() || self::operator($row,true))) {
                $next=array('confirmed'=>'en_route','en_route'=>'arrived','onboard'=>'completed');
                if (isset($next[$item['state']])) { $op=$next[$item['state']]; $nl=$labels[$op]; $html.=self::action_form($item,$op,$s::t($nl[0],$nl[1])); }
                if (in_array($item['state'],array('arrived','waiting'),true)) {
                    if ($item['state']==='arrived') $html.=self::action_form($item,'waiting',$s::t('Record waiting','ثبت انتظار'));
                    $html.=self::action_form($item,'contact_attempt',$s::t('Record contact attempt already made (no message is sent)','ثبت تلاش تماس انجام‌شده (پیامی ارسال نمی‌شود)'),array('channel'=>'text'));
                    $html.=self::action_form($item,'onboard',$s::t('Record boarding','ثبت سوار شدن'));
                    $html.=self::action_form($item,'no_show',$s::t('Record no-show after policy gate','ثبت عدم حضور پس از احراز شرایط'));
                }
            }
            if (!in_array($item['state'],array_merge(self::TERMINAL,array('onboard')),true) && ($owner || self::agency() || self::operator($row,true))) $html.=self::action_form($item,'cancel',$s::t('Cancel with recorded reason; no charge','لغو با ثبت دلیل؛ بدون برداشت وجه'));
            $html.='</article>';
        }
        $args=array('atlas_area'=>$customer?'account':'portal','lang'=>isset($_GET['lang'])&&$_GET['lang']==='fa'?'fa':'en'); $html.='<nav aria-label="'.esc_attr($s::t('Dispatch pages','صفحات اعزام')).'">'; if ($page>1) $html.='<a href="'.esc_url(add_query_arg(array_merge($args,array('atlas_dispatch_page'=>$page-1)),home_url('/'))).'">'.esc_html($s::t('Previous','قبل')).'</a> '; if (count($list->get_data()['items'])===25) $html.='<a href="'.esc_url(add_query_arg(array_merge($args,array('atlas_dispatch_page'=>$page+1)),home_url('/'))).'">'.esc_html($s::t('Next','بعد')).'</a>'; return $html.'</nav></details>';
    }
}
add_action('atlas_operations_ready',array('Atlas_Dispatch','register'));
