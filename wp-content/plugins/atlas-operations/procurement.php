<?php
if (!defined('ABSPATH')) { exit; }
use Atlas\Core\Auth;
use Atlas\Core\DB;

/** Private manual procurement; no payout adapter, public upload or carrier proof. */
final class Atlas_Procurement {
    private static $ready=false;
    public static function table($events=false) { global $wpdb; return $wpdb->prefix.($events?'atlas_procurement_events':'atlas_procurement'); }
    public static function ready() { return self::$ready; }
    public static function register() {
        if (!class_exists('Atlas_Partner_Security') || !class_exists('Atlas_Partner_Contracts')) return;
        $s='Atlas_Partner_Security';
        $a=$s::install('atlas_procurement',"id char(36) NOT NULL, type varchar(12) NOT NULL, rfq_id char(36) NOT NULL, quote_id char(36) NULL, org_id bigint unsigned NOT NULL, version bigint unsigned NOT NULL, state varchar(32) NOT NULL, quantity bigint unsigned NOT NULL, amount_irr bigint unsigned NOT NULL DEFAULT 0, payload longtext NOT NULL, created_by bigint unsigned NOT NULL, created_at datetime(6) NOT NULL, updated_at datetime(6) NOT NULL, PRIMARY KEY  (id), UNIQUE KEY award_once (quote_id), KEY rfq_type (rfq_id,type), KEY org_type (org_id,type)",array('id'=>'char(36)','type'=>'varchar(12)','rfq_id'=>'char(36)','quote_id'=>'char(36)','org_id'=>'bigint unsigned','version'=>'bigint unsigned','state'=>'varchar(32)','quantity'=>'bigint unsigned','amount_irr'=>'bigint unsigned','payload'=>'longtext','created_by'=>'bigint unsigned','created_at'=>'datetime(6)','updated_at'=>'datetime(6)'),array('PRIMARY'=>array('id'),'award_once'=>array('quote_id')));
        $b=$s::install('atlas_procurement_events',"id char(36) NOT NULL, aggregate_id char(36) NOT NULL, action varchar(40) NOT NULL, actor_id bigint unsigned NOT NULL, payload longtext NOT NULL, evidence_hash char(64) NULL, created_at datetime(6) NOT NULL, PRIMARY KEY  (id), UNIQUE KEY evidence_once (evidence_hash), KEY object_time (aggregate_id,created_at)",array('id'=>'char(36)','aggregate_id'=>'char(36)','action'=>'varchar(40)','actor_id'=>'bigint unsigned','payload'=>'longtext','evidence_hash'=>'char(64)','created_at'=>'datetime(6)'),array('PRIMARY'=>array('id'),'evidence_once'=>array('evidence_hash')));
        self::$ready=$a && $b; add_action('rest_api_init',array(__CLASS__,'routes'));
        add_filter('atlas_private_file_scope',array(__CLASS__,'file_scope'),10,5);
        add_filter('atlas_operations_dashboard',static function($html,$customer){return $customer?$html:$html.self::panel();},15,2);
    }
    public static function routes() {
        $p=array('permission_callback'=>array('Atlas_Partner_Security','permission'));
        register_rest_route('agency/v1','/procurement/rfqs',array(array_merge($p,array('methods'=>'GET','callback'=>static function($r){return self::listing($r,'rfq');})),array_merge($p,array('methods'=>'POST','callback'=>static function($r){return self::command($r,'rfq');}))));
        register_rest_route('agency/v1','/procurement/orders',array_merge($p,array('methods'=>'GET','callback'=>static function($r){return self::listing($r,'order');})));
        register_rest_route('agency/v1','/procurement/(?P<id>[a-f0-9-]{36})',array_merge($p,array('methods'=>'GET','callback'=>array(__CLASS__,'get'))));
        foreach (array('quote','award','artwork','art_review','sample','authorize','milestone','shipment','inspection','return_request','return_resolve','payable','settlement_intent','settlement_approve','settlement_evidence','close') as $op) register_rest_route('agency/v1','/procurement/(?P<id>[a-f0-9-]{36})/'.$op,array_merge($p,array('methods'=>'POST','callback'=>static function($r) use ($op){return self::command($r,$op);})));
    }
    private static function gate($r) { $p=Atlas_Partner_Security::permission($r); return is_wp_error($p)?$p:(self::$ready?true:Atlas_Partner_Security::error('atlas_procurement_unavailable','Procurement storage is unavailable.',503)); }
    private static function scope($org,$write=false) { return $org>0 && Auth::can('atlas_manage_records',(int)$org,null,$write?'write':'read'); }
    private static function row($id,$lock=false) { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%s'.($lock?' FOR UPDATE':''),$id)); }
    /** A document belongs to one persisted award, never a client-supplied organization. */
    public static function file_scope($previous,$type,$ref,$write,$actor) {
        if ($type!=='procurement') return $previous;
        if (!self::$ready || !is_user_logged_in() || (int)$actor!==get_current_user_id() || !is_string($ref) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$ref)) return false;
        $row=self::row($ref); if (!$row || $row->type!=='order' || (int)$row->created_by<1) return false;
        $org=(int)$row->org_id;
        if (Auth::agency()) {
            if (!current_user_can('atlas_manage_records') || ($write && !Atlas_Partner_Security::active(Atlas_Partner_Security::active_org($org)))) return false;
        } elseif (!self::scope($org,(bool)$write)) return false;
        return array('owner_id'=>(int)$row->created_by,'org_id'=>$org);
    }
    /** Read-only consumer: no nested storage/scanner transaction inside an award mutation. */
    private static function clean_artwork($fileId,$digest,$awardId) {
        global $wpdb;
        if (!class_exists('Atlas_Private_Files') || !is_string($fileId) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$fileId) || !is_string($digest) || !preg_match('/^[a-f0-9]{64}$/D',$digest)) throw new DomainException('private artwork');
        // Keep scan/purge and purpose withdrawal serialized until this revision commits.
        $file=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Atlas_Private_Files::table().' WHERE id=%s FOR UPDATE',$fileId));
        if (!$file || $file->state!=='clean' || $file->expires_at<=DB::now() || $file->context_type!=='procurement' || $file->context_ref!==$awardId || !hash_equals($file->digest,$digest)) throw new DomainException('private artwork');
        $policy=$wpdb->get_row($wpdb->prepare('SELECT state,payload FROM '.Atlas_Private_Files::table('policies').' WHERE id=%s FOR UPDATE',$file->policy_id)); $purpose=$policy?json_decode($policy->payload,true):null;
        if (!$policy || $policy->state!=='approved' || !is_array($purpose) || ($purpose['expires_at']??'')<=DB::now() || !in_array('procurement',$purpose['context_types']??array(),true)) throw new DomainException('private purpose');
        $reference=Atlas_Private_Files::clean_reference($fileId,'procurement',$awardId);
        if (!$reference || !hash_equals($digest,$reference['digest'])) throw new DomainException('private artwork');
        return $reference;
    }
    private static function quantity($v,$zero=false) { return is_int($v) && $v>=($zero?0:1) && $v<=1000000; }
    private static function money($v,$zero=false) {
        if (!is_string($v) && !is_int($v)) throw new InvalidArgumentException('money'); $v=(string)$v;
        if (!preg_match('/^(0|[1-9][0-9]{0,18})$/D',$v) || strlen($v)>strlen((string)PHP_INT_MAX) || (strlen($v)===strlen((string)PHP_INT_MAX) && strcmp($v,(string)PHP_INT_MAX)>0) || (!$zero && $v==='0')) throw new InvalidArgumentException('money'); return (int)$v;
    }
    private static function text($v,$max=2000) { if (!is_string($v) || trim($v)==='' || strlen($v)>$max) throw new InvalidArgumentException('text'); return sanitize_textarea_field($v); }
    private static function reference($v) { if (!is_string($v) || !preg_match('/^[A-Za-z0-9._:-]{8,120}$/D',$v)) throw new InvalidArgumentException('reference'); return $v; }
    private static function utc($v) {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)$/D',$v)) throw new InvalidArgumentException('date');
        try { $d=new DateTimeImmutable($v); } catch (Throwable $e) { throw new InvalidArgumentException('date'); }
        $e=DateTimeImmutable::getLastErrors(); if (is_array($e) && ($e['warning_count'] || $e['error_count'])) throw new InvalidArgumentException('date'); return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
    private static function require_fields($b,$names) { if (!is_array($b) || array_diff(array_keys($b),$names) || count($b)!==count($names)) throw new InvalidArgumentException('fields'); }
    private static function private_row($row,$org) {
        if (Auth::agency()) return true; if (!$row || !self::scope($org)) return false;
        if ($row->type==='rfq') { $p=json_decode($row->payload,true); return in_array($org,$p['recipients'],true); }
        return (int)$row->org_id===$org;
    }
    private static function project($row) {
        $payload=json_decode($row->payload,true); if ($row->type==='rfq' && !Auth::agency()) unset($payload['recipients']);
        return array('id'=>$row->id,'type'=>$row->type,'rfq_id'=>$row->rfq_id,'org_id'=>(int)$row->org_id,'version'=>(int)$row->version,'state'=>$row->state,'quantity'=>(int)$row->quantity,'amount_irr'=>$row->type==='rfq' && !Auth::agency()?null:(string)$row->amount_irr,'currency'=>'IRR','money_status'=>'unverified_manual_workflow','payload'=>$payload);
    }
    public static function listing($r,$type) {
        global $wpdb; $ok=self::gate($r); if (is_wp_error($ok)) return $ok; $org=(int)$r->get_param('org_id'); $page=(int)($r->get_param('page')?:1);
        if ($page<1 || $page>10000 || (!Auth::agency() && !self::scope($org))) return Atlas_Partner_Security::error('atlas_not_found','Procurement record unavailable.',404);
        $where=$wpdb->prepare('type=%s',$type);
        if (!Auth::agency()) $where.=$type==='rfq'?$wpdb->prepare(" AND JSON_CONTAINS(payload,%s,'$.recipients')",wp_json_encode($org)):$wpdb->prepare(' AND org_id=%d',$org);
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table()." WHERE $where ORDER BY created_at DESC,id LIMIT 25 OFFSET %d",($page-1)*25));
        if (!DB::audit(get_current_user_id(),$org,'procurement.list',$type,'read')) return Atlas_Partner_Security::error('atlas_audit_failed','Read could not be recorded.',503);
        return Atlas_Partner_Security::response(array('items'=>array_map(array(__CLASS__,'project'),$rows),'page'=>$page,'limit'=>25));
    }
    public static function get($r) {
        global $wpdb; $ok=self::gate($r); if (is_wp_error($ok)) return $ok; $org=(int)$r->get_param('org_id'); $row=self::row((string)$r['id']);
        if (!$row || !self::private_row($row,$org)) return Atlas_Partner_Security::error('atlas_not_found','Procurement record unavailable.',404);
        $out=self::project($row); $page=(int)($r->get_param('page')?:1); if ($page<1 || $page>10000) return Atlas_Partner_Security::error('atlas_invalid_page','Invalid page.');
        if ($row->type==='rfq') {
            $where=Auth::agency()?'':$wpdb->prepare(' AND org_id=%d',$org);
            $children=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table()." WHERE rfq_id=%s AND type<>'rfq' $where ORDER BY created_at DESC,id LIMIT 25 OFFSET %d",$row->id,($page-1)*25)); $out['children']=array_map(array(__CLASS__,'project'),$children);
        } else { $out['events']=$wpdb->get_results($wpdb->prepare('SELECT action,actor_id,payload,created_at FROM '.self::table(true).' WHERE aggregate_id=%s ORDER BY created_at DESC,id LIMIT 25 OFFSET %d',$row->id,($page-1)*25),ARRAY_A); }
        $out['page']=$page; if (!DB::audit(get_current_user_id(),(int)$row->org_id,'procurement.read',$row->id,'read')) return Atlas_Partner_Security::error('atlas_audit_failed','Read could not be recorded.',503); return Atlas_Partner_Security::response($out);
    }
    public static function command($r,$op) {
        global $wpdb; $s='Atlas_Partner_Security'; $ok=self::gate($r); if (is_wp_error($ok)) return $ok;
        $b=$r->get_json_params(); $key=(string)$r->get_header('Idempotency-Key'); $actor=get_current_user_id();
        if (!is_array($b) || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$key)) return $s::error('atlas_invalid_request','A bounded request and request key are required.');
        $agencyOps=array('rfq','award','art_review','sample','authorize','inspection','return_request','return_resolve','payable','settlement_intent','settlement_approve','settlement_evidence','close');
        if (in_array($op,$agencyOps,true) && (!Auth::agency() || !current_user_can('atlas_manage_records'))) return $s::error('atlas_forbidden','Agency procurement authority required.',403);
        if (in_array($op,array('payable','settlement_intent','settlement_approve','settlement_evidence','close'),true) && (!Auth::agency() || !current_user_can('atlas_manage_finance'))) return $s::error('atlas_forbidden','Agency finance authority required.',403);
        if ($op==='quote' && Auth::agency()) return $s::error('atlas_forbidden','Quotes require the invited supplier account.',403);
        if (isset($b['org_id']) && (!is_int($b['org_id']) || $b['org_id']<1)) return $s::error('atlas_invalid_request','A valid organization context is required.');
        $id=$op==='rfq'?'':(string)$r['id']; $initial=$id?self::row($id):null; $org=(int)($b['org_id']??0);
        if ($op!=='rfq' && !$initial) return $s::error('atlas_not_found','Procurement record unavailable.',404);
        if ($op!=='rfq' && !Auth::agency() && !self::private_row($initial,$org)) return $s::error('atlas_not_found','Procurement record unavailable.',404);
        if (!Auth::agency() && !self::scope($org,true)) return $s::error('atlas_forbidden','Scoped procurement write authority required.',403);
        ksort($b,SORT_STRING); $hash=hash('sha256',wp_json_encode(array($id,$b))); $scope='user:'.$actor; $operation='procurement.'.$op; $event=$b; $evidenceHash=null; $result=null;
        $wpdb->query('START TRANSACTION');
        try {
            $lockOrg=$initial && $initial->type==='order'?(int)$initial->org_id:$org;
            if ($op==='award' && isset($b['quote_id']) && is_string($b['quote_id'])) { $candidate=self::row($b['quote_id']); if ($candidate) $lockOrg=(int)$candidate->org_id; }
            // Membership/contract changes lock the same organization before objects.
            if ($lockOrg>0) {
                $organization=$s::active_org($lockOrg,true);
                $historical=Auth::agency() && in_array($op,array('inspection','return_request','return_resolve','shipment','milestone','close'),true);
                if (!$organization || (!$s::active($organization) && !$historical)) throw new DomainException('organization');
            }
            $rfq=$op==='rfq'?null:self::row($initial->rfq_id,true); $row=$op==='rfq'?null:self::row($id,true);
            if ($op!=='rfq' && (!$rfq || !$row || (!Auth::agency() && (!self::private_row($row,$org) || !self::scope($org,true))))) throw new DomainException('scope');
            $prior=$wpdb->get_row($wpdb->prepare('SELECT request_hash,response FROM '.DB::table('idempotency').' WHERE scope=%s AND operation=%s AND idem_key=%s',$scope,$operation,$key));
            if ($prior) { $wpdb->query('ROLLBACK'); return hash_equals($prior->request_hash,$hash)?$s::response(json_decode($prior->response,true)):$s::error('atlas_idempotency_conflict','The request key was reused.',409); }
            $now=DB::now(); $new=false;
            if ($op==='rfq') {
                self::require_fields($b,array('title','requirements','quantity','budget_irr','deadline','delivery_deadline','recipients'));
                if (!self::quantity($b['quantity']) || !is_array($b['recipients']) || !$b['recipients'] || count($b['recipients'])>100) throw new InvalidArgumentException('quantity');
                foreach ($b['recipients'] as $recipient) if (!is_int($recipient) || !$s::active($s::active_org($recipient))) throw new InvalidArgumentException('recipient');
                $deadline=self::utc($b['deadline']); $delivery=self::utc($b['delivery_deadline']); if ($deadline<=$now || $delivery<=$deadline) throw new DomainException('deadline');
                $id=wp_generate_uuid4(); $p=array('title'=>self::text($b['title'],180),'requirements'=>self::text($b['requirements'],4000),'deadline'=>$deadline,'delivery_deadline'=>$delivery,'recipients'=>array_values(array_unique($b['recipients']))); $type='rfq'; $state='open'; $quantity=$b['quantity']; $amount=self::money($b['budget_irr']); $org=0; $rfqId=$id; $quoteId=null; $new=true;
            } elseif ($op==='quote') {
                self::require_fields($b,array('org_id','expected_version','quantity','unit_irr','setup_irr','shipping_irr','tax_irr','expiry','lead_days','terms'));
                $rp=json_decode($rfq->payload,true); if ($row->type!=='rfq' || $rfq->state!=='open' || $now>=$rp['deadline'] || !self::scope($org,true) || !in_array($org,$rp['recipients'],true) || $b['expected_version']!==(int)$rfq->version || !self::quantity($b['quantity']) || $b['quantity']>(int)$rfq->quantity || !self::quantity($b['lead_days'])) throw new DomainException('quote');
                $unit=self::money($b['unit_irr']); $setup=self::money($b['setup_irr'],true); $shipping=self::money($b['shipping_irr'],true); $tax=self::money($b['tax_irr'],true); foreach (array($unit,$setup,$shipping,$tax) as $v) if ($v>1000000000000) throw new InvalidArgumentException('amount limit');
                $expiry=self::utc($b['expiry']); if ($expiry<=$now) throw new DomainException('expiry');
                $p=array('unit_irr'=>(string)$unit,'setup_irr'=>(string)$setup,'shipping_irr'=>(string)$shipping,'tax_irr'=>(string)$tax,'expiry'=>$expiry,'lead_days'=>$b['lead_days'],'terms'=>self::text($b['terms'],4000),'currency'=>'IRR'); $id=wp_generate_uuid4(); $type='quote'; $state='submitted'; $quantity=$b['quantity']; $amount=$unit*$quantity+$setup+$shipping+$tax; $rfqId=$rfq->id; $quoteId=null; $new=true;
            } elseif ($op==='award') {
                self::require_fields($b,array('expected_version','quote_id','quantity','reason'));
                if ($row->type!=='rfq' || !is_int($b['expected_version']) || $b['expected_version']!==(int)$rfq->version || $rfq->state!=='open' || !self::quantity($b['quantity']) || !is_string($b['quote_id'])) throw new DomainException('award');
                $quote=self::row($b['quote_id'],true); if (!$quote || $quote->type!=='quote' || $quote->rfq_id!==$rfq->id || $quote->state!=='submitted' || $b['quantity']>(int)$quote->quantity) throw new DomainException('quote');
                $qp=json_decode($quote->payload,true); $rp=json_decode($rfq->payload,true); if ($now>=$qp['expiry'] || !Atlas_Partner_Contracts::has_active_contract((int)$quote->org_id,'manufacturing')) throw new DomainException('quote or contract');
                if ((new DateTimeImmutable($now,new DateTimeZone('UTC')))->modify('+'.(int)$qp['lead_days'].' days')->format('Y-m-d H:i:s.u')>$rp['delivery_deadline']) throw new DomainException('delivery deadline');
                $awarded=(int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(quantity),0) FROM '.self::table()." WHERE rfq_id=%s AND type='order'",$rfq->id)); if ($awarded+$b['quantity']>(int)$rfq->quantity) throw new DomainException('over award');
                $id=wp_generate_uuid4(); $type='order'; $state='awaiting_proof'; $quantity=$b['quantity']; $org=(int)$quote->org_id; $amount=(int)$qp['unit_irr']*$quantity+(int)$qp['setup_irr']+(int)$qp['shipping_irr']+(int)$qp['tax_irr'];
                $spent=(int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(amount_irr),0) FROM '.self::table()." WHERE rfq_id=%s AND type='order'",$rfq->id)); if ($amount>(int)$rfq->amount_irr-$spent) throw new DomainException('RFQ budget');
                $p=array('quote_snapshot'=>array('id'=>$quote->id,'version'=>(int)$quote->version,'quantity'=>(int)$quote->quantity,'terms'=>$qp),'quote_snapshot_hash'=>hash('sha256',$quote->payload),'rfq_snapshot'=>array('title'=>$rp['title'],'requirements'=>$rp['requirements'],'delivery_deadline'=>$rp['delivery_deadline']),'award_reason'=>self::text($b['reason'],190),'artwork'=>null,'artwork_history'=>array(),'sample_approved'=>false,'production_completed'=>false,'shipped'=>0,'received'=>0,'accepted'=>0,'rejected'=>0,'credited_quantity'=>0,'credit_irr'=>'0','return_quantity'=>0,'payable_irr'=>null,'settlements'=>array(),'file_access'=>'scoped_clean_private_file_required');
                $rfqId=$rfq->id; $quoteId=$quote->id; $new=true;
                if ($wpdb->update(self::table(),array('state'=>'awarded','version'=>(int)$quote->version+1,'updated_at'=>$now),array('id'=>$quote->id))!==1 || $wpdb->update(self::table(),array('state'=>$awarded+$quantity===(int)$rfq->quantity?'awarded':'open','version'=>(int)$rfq->version+1,'updated_at'=>$now),array('id'=>$rfq->id))!==1) throw new RuntimeException('award');
            } else {
                if ($row->type!=='order' || !isset($b['expected_version']) || !is_int($b['expected_version']) || $b['expected_version']!==(int)$row->version || $row->state==='closed') throw new DomainException('version');
                $p=json_decode($row->payload,true); $state=$row->state; $org=(int)$row->org_id; $quantity=(int)$row->quantity; $amount=(int)$row->amount_irr;
                $base=array('expected_version'); if (!Auth::agency()) $base[]='org_id';
                if ($op==='artwork') {
                    self::require_fields($b,array_merge($base,array('file_reference','sha256','rights_attested'))); if ((int)$p['shipped'] || $p['payable_irr']!==null || !is_string($b['sha256']) || !preg_match('/^[a-f0-9]{64}$/D',$b['sha256']) || $b['rights_attested']!==true) throw new DomainException('artwork');
                    $file=self::clean_artwork($b['file_reference'],$b['sha256'],$id);
                    $history=$p['artwork_history']??array(); if (count($history)>=100) throw new DomainException('revision limit'); if ($p['artwork']) $history[]=$p['artwork'];
                    $p['artwork_history']=$history; $p['file_access']='scoped_clean_private_file_required';
                    $p['artwork']=array('revision'=>(int)($p['artwork']['revision']??0)+1,'file_reference'=>$file['id'],'sha256'=>$file['digest'],'policy_id'=>$file['policy_id'],'proposed_by'=>$actor,'proposed_at'=>$now,'rights_attested'=>true,'approved'=>false); $p['sample_approved']=false; $p['production_completed']=false; $state='proof_pending';
                } elseif ($op==='art_review') {
                    self::require_fields($b,array_merge($base,array('proof_revision','decision','scan_review_reference','reason'))); if ($state!=='proof_pending' || !$p['artwork'] || $b['proof_revision']!==$p['artwork']['revision'] || $p['artwork']['proposed_by']===$actor || !in_array($b['decision'],array('approved','rejected'),true)) throw new DomainException('proof');
                    if ($b['decision']==='approved') self::clean_artwork($p['artwork']['file_reference'],$p['artwork']['sha256'],$id);
                    $p['artwork']['approved']=$b['decision']==='approved'; $p['artwork']['scan_review_reference']=self::reference($b['scan_review_reference']); $p['artwork']['reviewed_by']=$actor; $p['sample_approved']=false; self::text($b['reason'],190); $state=$p['artwork']['approved']?'proof_approved':'proof_rejected';
                } elseif ($op==='sample') {
                    self::require_fields($b,array_merge($base,array('proof_revision','decision','evidence_reference','reason'))); if (!in_array($state,array('proof_approved','sample_rejected'),true) || empty($p['artwork']['approved']) || $b['proof_revision']!==$p['artwork']['revision'] || !in_array($b['decision'],array('approved','rejected'),true) || (int)$p['shipped']) throw new DomainException('sample');
                    if ($b['decision']==='approved') self::clean_artwork($p['artwork']['file_reference'],$p['artwork']['sha256'],$id);
                    self::reference($b['evidence_reference']); self::text($b['reason'],190); $p['sample_approved']=$b['decision']==='approved'; $p['artwork']['sample_review']=array('approved'=>$p['sample_approved'],'evidence_reference'=>$b['evidence_reference'],'reviewed_by'=>$actor,'reviewed_at'=>$now); $state=$p['sample_approved']?'sample_approved':'sample_rejected';
                } elseif ($op==='authorize') {
                    self::require_fields($b,array_merge($base,array('reason'))); if (empty($p['artwork']['approved']) || !$p['sample_approved'] || $state!=='sample_approved' || !Atlas_Partner_Contracts::has_active_contract($org,'manufacturing')) throw new DomainException('production gate'); self::clean_artwork($p['artwork']['file_reference'],$p['artwork']['sha256'],$id); self::text($b['reason'],190); $state='authorized';
                } elseif ($op==='milestone') {
                    self::require_fields($b,array_merge($base,array('milestone','evidence_reference','reason'))); $next=array('authorized'=>'started','started'=>'completed'); if (($next[$state]??null)!==($b['milestone']??null)) throw new DomainException('milestone'); if ($b['milestone']==='started') self::clean_artwork($p['artwork']['file_reference']??null,$p['artwork']['sha256']??null,$id); self::reference($b['evidence_reference']); self::text($b['reason'],190); $state=$b['milestone']; $p['production_completed']=$state==='completed';
                } elseif ($op==='shipment') {
                    self::require_fields($b,array_merge($base,array('quantity','tracking_reference','reason'))); if (!$p['production_completed'] || $p['payable_irr']!==null || !self::quantity($b['quantity']) || $p['shipped']+$b['quantity']>$quantity) throw new DomainException('shipment'); self::reference($b['tracking_reference']); self::text($b['reason'],190); $p['shipped']+=$b['quantity']; $state=$p['shipped']===$quantity?'shipped_manual':'partial_shipment';
                } elseif ($op==='inspection') {
                    self::require_fields($b,array_merge($base,array('received','accepted','rejected','evidence_reference','reason'))); if (!self::quantity($b['received']) || !self::quantity($b['accepted'],true) || !self::quantity($b['rejected'],true) || $b['accepted']+$b['rejected']!==$b['received'] || $p['received']+$b['received']>$p['shipped'] || $p['payable_irr']!==null) throw new DomainException('inspection'); self::reference($b['evidence_reference']); self::text($b['reason'],190); foreach (array('received','accepted','rejected') as $f) $p[$f]+=$b[$f]; $state=$p['accepted']===$quantity && !$p['rejected']?'qc_passed':'qc_exception';
                } elseif ($op==='return_request') {
                    self::require_fields($b,array_merge($base,array('accepted_quantity','rejected_quantity','reason'))); if ($p['payable_irr']!==null || !self::quantity($b['accepted_quantity'],true) || !self::quantity($b['rejected_quantity'],true) || $b['accepted_quantity']+$b['rejected_quantity']<1 || $b['accepted_quantity']>$p['accepted'] || $b['rejected_quantity']>$p['rejected']) throw new DomainException('return'); $p['accepted']-=$b['accepted_quantity']; $p['rejected']-=$b['rejected_quantity']; $p['return_quantity']+=$b['accepted_quantity']+$b['rejected_quantity']; self::text($b['reason'],190); $state='return_pending';
                } elseif ($op==='return_resolve') {
                    self::require_fields($b,array_merge($base,array('replacement_quantity','credit_quantity','credit_irr','evidence_reference','reason'))); $credit=self::money($b['credit_irr'],true); if (!self::quantity($b['replacement_quantity'],true) || !self::quantity($b['credit_quantity'],true) || !$p['return_quantity'] || $b['replacement_quantity']+$b['credit_quantity']!==$p['return_quantity'] || (int)$p['credit_irr']+$credit>$amount || ($b['credit_quantity']===0 && $credit!==0)) throw new DomainException('return resolution'); self::reference($b['evidence_reference']); self::text($b['reason'],190); $p['accepted']+=$b['replacement_quantity']; $p['credited_quantity']+=$b['credit_quantity']; $p['credit_irr']=(string)((int)$p['credit_irr']+$credit); $p['return_quantity']=0; $state=$p['accepted']+$p['credited_quantity']===$quantity && !$p['rejected']?'qc_passed':'qc_exception';
                } elseif ($op==='payable') {
                    self::require_fields($b,array_merge($base,array('invoice_reference','reason'))); if ($state!=='qc_passed' || $p['return_quantity'] || $p['rejected'] || $p['accepted']+$p['credited_quantity']!==$quantity || $p['payable_irr']!==null) throw new DomainException('QC'); $p['invoice_reference']=self::reference($b['invoice_reference']); self::text($b['reason'],190); $p['payable_irr']=(string)($amount-(int)$p['credit_irr']); $state='payable_approved';
                } elseif ($op==='settlement_intent') {
                    self::require_fields($b,array_merge($base,array('amount_irr','reason'))); $intent=self::money($b['amount_irr']); $reserved=0; foreach ($p['settlements'] as $settlement) $reserved+=(int)$settlement['amount_irr']; if ($p['payable_irr']===null || count($p['settlements'])>=100 || $intent>(int)$p['payable_irr']-$reserved) throw new DomainException('settlement cap'); $p['settlements'][]=array('id'=>wp_generate_uuid4(),'amount_irr'=>(string)$intent,'state'=>'requested','requested_by'=>$actor,'reason'=>self::text($b['reason'],190),'money_status'=>'unverified');
                } elseif (in_array($op,array('settlement_approve','settlement_evidence'),true)) {
                    self::require_fields($b,array_merge($base,$op==='settlement_approve'?array('intent_id','reason'):array('intent_id','evidence_reference','reason'))); $found=false;
                    foreach ($p['settlements'] as &$intent) if ($intent['id']===$b['intent_id']) {
                        if ($op==='settlement_approve') { if ($intent['state']!=='requested' || $intent['requested_by']===$actor) throw new DomainException('independent finance approval'); $intent['state']='approved'; $intent['approved_by']=$actor; }
                        else { if ($intent['state']!=='approved') throw new DomainException('intent'); $ref=self::reference($b['evidence_reference']); $evidenceHash=hash('sha256','manual_bank_evidence:'.$ref); if ($wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table(true).' WHERE evidence_hash=%s',$evidenceHash))) throw new DomainException('duplicate evidence'); $intent['state']='manual_evidence_recorded'; $intent['evidence_reference']=$ref; $intent['money_status']='manual_evidence_unverified'; }
                        self::text($b['reason'],190); $found=true; break;
                    } unset($intent); if (!$found) throw new DomainException('intent');
                } elseif ($op==='close') {
                    self::require_fields($b,array_merge($base,array('reason'))); if ($p['payable_irr']===null || $p['return_quantity'] || (!$p['settlements'] && (int)$p['payable_irr']>0)) throw new DomainException('close'); $sum=0; foreach ($p['settlements'] as $intent) { if ($intent['state']!=='manual_evidence_recorded') throw new DomainException('evidence'); $sum+=(int)$intent['amount_irr']; } if ($sum!==(int)$p['payable_irr']) throw new DomainException('balance'); self::text($b['reason'],190); $state='closed';
                } else throw new InvalidArgumentException('operation');
                if ($wpdb->update(self::table(),array('payload'=>wp_json_encode($p),'version'=>(int)$row->version+1,'state'=>$state,'updated_at'=>$now),array('id'=>$id,'version'=>(int)$row->version))!==1) throw new RuntimeException('update');
            }
            if ($new && !$wpdb->insert(self::table(),array('id'=>$id,'type'=>$type,'rfq_id'=>$rfqId,'quote_id'=>$quoteId,'org_id'=>$org,'version'=>1,'state'=>$state,'quantity'=>$quantity,'amount_irr'=>$amount,'payload'=>wp_json_encode($p),'created_by'=>$actor,'created_at'=>$now,'updated_at'=>$now))) throw new RuntimeException('insert');
            $result=self::project(self::row($id));
            if (!$wpdb->insert(self::table(true),array('id'=>wp_generate_uuid4(),'aggregate_id'=>$id,'action'=>$op,'actor_id'=>$actor,'payload'=>wp_json_encode($event),'evidence_hash'=>$evidenceHash,'created_at'=>$now)) || !DB::audit($actor,$org,'procurement.'.$op,$id,'success',$b['reason']??'manual procurement') || !DB::emit('procurement.'.$op,$id) || !$wpdb->insert(DB::table('idempotency'),array('scope'=>$scope,'operation'=>$operation,'idem_key'=>$key,'request_hash'=>$hash,'response'=>wp_json_encode($result),'created_at'=>$now))) throw new RuntimeException('audit');
            $wpdb->query('COMMIT'); return $s::response($result,$new?201:200);
        } catch (InvalidArgumentException $e) { $wpdb->query('ROLLBACK'); return $s::error('atlas_invalid_procurement','Check the required fields, exact IRR amounts, dates and evidence references.'); }
        catch (DomainException $e) { $wpdb->query('ROLLBACK'); return $s::error('atlas_procurement_conflict','Authority, revision, quantity or workflow gate requires review.',409); }
        catch (Throwable $e) { $wpdb->query('ROLLBACK'); $prior=$wpdb->get_row($wpdb->prepare('SELECT request_hash,response FROM '.DB::table('idempotency').' WHERE scope=%s AND operation=%s AND idem_key=%s',$scope,$operation,$key)); if ($prior) return hash_equals($prior->request_hash,$hash)?$s::response(json_decode($prior->response,true)):$s::error('atlas_idempotency_conflict','The request key was reused.',409); if ($evidenceHash && $wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table(true).' WHERE evidence_hash=%s',$evidenceHash))) return $s::error('atlas_procurement_conflict','Evidence is already associated with a different settlement.',409); return $s::error('atlas_procurement_failed','Procurement change could not be recorded.',503); }
    }
    private static function labels() {
        return array('title'=>array('RFQ title','عنوان درخواست قیمت'),'requirements'=>array('Specifications, dimensions, materials, IP and inspection requirements','مشخصات، ابعاد، مواد، حقوق اثر و الزامات بازرسی'),'quantity'=>array('Quantity','تعداد'),'budget_irr'=>array('Approved maximum RFQ budget (IRR)','حداکثر بودجه مصوب درخواست (ریال)'),'deadline'=>array('Quote deadline (UTC ISO 8601)','مهلت پیشنهاد (UTC، ISO 8601)'),'delivery_deadline'=>array('Required delivery (UTC ISO 8601)','مهلت تحویل (UTC، ISO 8601)'),'recipients'=>array('Invited organization IDs, comma-separated','شناسه سازمان‌های دعوت‌شده با ویرگول انگلیسی'),'unit_irr'=>array('Unit price (IRR)','قیمت واحد (ریال)'),'setup_irr'=>array('Setup fee (IRR)','هزینه راه‌اندازی (ریال)'),'shipping_irr'=>array('Shipping fee (IRR)','هزینه ارسال (ریال)'),'tax_irr'=>array('Explicit quoted tax (IRR)','مالیات صریح پیشنهادی (ریال)'),'expiry'=>array('Quote expiry (UTC ISO 8601)','پایان اعتبار قیمت (UTC، ISO 8601)'),'lead_days'=>array('Production lead days','مدت تولید به روز'),'terms'=>array('Delivery, price and cancellation terms','شرایط قیمت، تحویل و لغو'),'reason'=>array('Reason / review notes','دلیل / یادداشت بررسی'),'file_reference'=>array('Private artwork reference (no public URL)','مرجع خصوصی اثر (بدون نشانی عمومی)'),'sha256'=>array('Artwork SHA-256','هش SHA-256 اثر'),'rights_attested'=>array('I attest that the required artwork rights are held.','اظهار می‌کنم حقوق لازم برای اثر در اختیار است.'),'decision'=>array('Review outcome: approved or rejected','نتیجه بررسی: approved یا rejected'),'scan_review_reference'=>array('Manual private-file security review reference','مرجع بررسی دستی امنیت فایل خصوصی'),'evidence_reference'=>array('Manual evidence reference','مرجع شواهد دستی'),'tracking_reference'=>array('Manual carrier/tracking reference','مرجع دستی حمل یا رهگیری'),'received'=>array('Quantity received in this inspection','تعداد دریافتی در این بازرسی'),'accepted'=>array('Quantity accepted','تعداد پذیرفته‌شده'),'rejected'=>array('Quantity rejected','تعداد مردود'),'accepted_quantity'=>array('Accepted goods to return','تعداد کالای پذیرفته‌شده برای مرجوعی'),'rejected_quantity'=>array('Rejected goods to return','تعداد کالای مردود برای مرجوعی'),'replacement_quantity'=>array('Reviewed replacement quantity received','تعداد جایگزین دریافت‌شده و بررسی‌شده'),'credit_quantity'=>array('Quantity resolved by agreed credit','تعداد تسویه‌شده با بستانکاری توافقی'),'credit_irr'=>array('Agreed invoice credit (IRR)','بستانکاری توافقی صورتحساب (ریال)'),'invoice_reference'=>array('Reviewed supplier invoice reference','مرجع صورتحساب بررسی‌شده تأمین‌کننده'),'amount_irr'=>array('Settlement intent amount (IRR)','مبلغ قصد تسویه (ریال)'));
    }
    private static function form_html($op,$label,$fields,$row=null,$hidden=array()) {
        $s='Atlas_Partner_Security'; $url=$row?'procurement/'.$row['id'].'/'.$op:'procurement/rfqs';
        if ($row) $hidden['expected_version']=$row['version'];
        $html='<details><summary>'.esc_html($label).'</summary><form data-atlas-procurement data-endpoint="'.esc_attr($url).'">';
        foreach ($hidden as $name=>$value) $html.='<input type="hidden" name="'.esc_attr($name).'" value="'.esc_attr((string)$value).'" data-type="'.(is_int($value)?'integer':'text').'">';
        $labels=self::labels();
        foreach ($fields as $name=>$type) {
            $pair=$labels[$name]??array($name,$name); $text=$s::t($pair[0],$pair[1]);
            if ($op==='artwork' && $name==='file_reference') {
                $html.='<label>'.esc_html($s::t('Clean private artwork for this award','فایل خصوصی پاک برای همین تخصیص')).'<select name="file_reference" data-artwork-file required><option value="">'.esc_html($s::t('Select a scanned file','انتخاب فایل اسکن‌شده')).'</option>';
                if (class_exists('Atlas_Private_Files')) {
                    $r=new WP_REST_Request('GET','/'); $r->set_param('context_type','procurement'); $r->set_param('context_ref',$row['id']); $files=Atlas_Private_Files::listing($r);
                    if (!is_wp_error($files)) foreach ($files->get_data()['items'] as $file) if (Atlas_Private_Files::clean_reference($file['id'],'procurement',$row['id'])) $html.='<option value="'.esc_attr($file['id']).'" data-digest="'.esc_attr($file['digest']).'">'.esc_html($file['id'].' — '.$file['mime']).'</option>';
                }
                $html.='</select></label>';
            }
            elseif ($op==='artwork' && $name==='sha256') $html.='<label>'.esc_html($text).'<input name="sha256" readonly required pattern="[a-f0-9]{64}" maxlength="64"></label>';
            elseif ($name==='decision') $html.='<label>'.esc_html($text).'<select name="decision"><option value="approved">'.esc_html($s::t('Approve','تأیید')).'</option><option value="rejected">'.esc_html($s::t('Reject','رد')).'</option></select></label>';
            elseif ($type==='boolean') $html.='<label><input type="checkbox" name="'.esc_attr($name).'" data-type="boolean" required>'.esc_html($text).'</label>';
            elseif ($type==='textarea') $html.='<label>'.esc_html($text).'<textarea name="'.esc_attr($name).'" required maxlength="4000"></textarea></label>';
            else $html.='<label>'.esc_html($text).'<input name="'.esc_attr($name).'" data-type="'.esc_attr($type).'" '.($type==='integer'?'type="number" min="0" max="1000000"':'type="text"').' required maxlength="2000"></label>';
        }
        return $html.'<button>'.esc_html($label).'</button><p role="status" aria-live="polite"></p></form></details>';
    }
    public static function panel() {
        $s='Atlas_Partner_Security'; if (!is_user_logged_in()) return ''; if (!self::$ready) return '<p>'.esc_html($s::t('Procurement storage is unavailable.','سامانه تدارکات در دسترس نیست.')).'</p>';
        $org=isset($_GET['atlas_proc_org']) && is_scalar($_GET['atlas_proc_org'])?(int)$_GET['atlas_proc_org']:0;
        if (!Auth::agency() && !self::scope($org)) {
            global $wpdb; $org=(int)$wpdb->get_var($wpdb->prepare('SELECT m.org_id FROM '.DB::table('memberships').' m INNER JOIN '.DB::table('organizations')." o ON o.id=m.org_id WHERE m.user_id=%d AND m.status='active' AND o.status IN ('active','approved') ORDER BY m.org_id LIMIT 1",get_current_user_id()));
            if (!self::scope($org)) return '';
        }
        wp_enqueue_script('atlas-procurement',plugins_url('assets/procurement.js',__FILE__),array(),'0.1.0',true);
        wp_localize_script('atlas-procurement','atlasProcurement',array('base'=>esc_url_raw(rest_url('agency/v1/')),'nonce'=>wp_create_nonce('wp_rest'),'saved'=>$s::t('Recorded. Refresh to continue with the new revision.','ثبت شد. برای ادامه با نسخه جدید صفحه را تازه کنید.'),'failed'=>$s::t('Could not record. Check the fields and current revision.','ثبت نشد. فیلدها و نسخه فعلی را بررسی کنید.')));
        $html='<details><summary>'.esc_html($s::t('Private RFQs, awards and production','درخواست قیمت، تخصیص و تولید خصوصی')).'</summary><p>'.esc_html($s::t('Manual operational records. Artwork must be uploaded under an approved purpose policy and pass private-file scanning before an exact file and digest can be attached. Rights, proof and physical sample approvals remain separate. Manufacturer QA returns here occur before invoice approval.','سوابق عملیاتی دستی است. اثر باید با سیاست هدف تأییدشده بارگذاری و در خدمت فایل خصوصی اسکن شود تا فایل و هش دقیق پیوست شود. تأیید حقوق اثر، نسخه نهایی و نمونه فیزیکی جداگانه است. مرجوعی کنترل کیفیت تولیدکننده در این بخش پیش از تأیید صورتحساب است.')).'</p>';
        $html.='<form method="get" action="'.esc_url(home_url('/')).'"><input type="hidden" name="atlas_area" value="portal"><label>'.esc_html($s::t('Supplier organization context','سازمان تأمین‌کننده')).'<input type="number" min="1" name="atlas_proc_org" value="'.esc_attr($org).'"></label><button>'.esc_html($s::t('Show scoped records','نمایش سوابق مجاز')).'</button></form>';
        if (Auth::agency()) $html.=self::form_html('rfq',$s::t('Create approved-budget RFQ','ایجاد درخواست قیمت با بودجه مصوب'),array('title'=>'text','requirements'=>'textarea','quantity'=>'integer','budget_irr'=>'money','deadline'=>'text','delivery_deadline'=>'text','recipients'=>'integers'));
        $page=isset($_GET['atlas_proc_page']) && is_scalar($_GET['atlas_proc_page'])?max(1,min(10000,(int)$_GET['atlas_proc_page'])):1;
        foreach (array('rfq','order') as $type) {
            $r=new WP_REST_Request('GET','/'); $r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest')); $r->set_query_params(array('org_id'=>$org,'page'=>$page)); $list=self::listing($r,$type); if (is_wp_error($list)) continue;
            foreach ($list->get_data()['items'] as $row) {
                $p=$row['payload']; $html.='<article><h3>'.esc_html(($p['title']??$p['rfq_snapshot']['title']??$row['id']).' — '.$row['state']).'</h3><p>'.($row['amount_irr']===null?esc_html($s::t('Agency budget is private.','بودجه آژانس خصوصی است.')):'<bdi dir="ltr">'.esc_html($row['amount_irr'].' IRR').'</bdi>').' — '.esc_html($s::t('Quantity ','تعداد ').$row['quantity']).'</p>';
                if ($type==='rfq') {
                    $html.='<p>'.nl2br(esc_html($p['requirements'])).'</p><p>'.esc_html($p['deadline']).'</p>';
                    if (!Auth::agency() && $row['state']==='open' && DB::now()<$p['deadline'] && self::scope($org,true)) $html.=self::form_html('quote',$s::t('Submit confidential quote','ارسال قیمت محرمانه'),array('quantity'=>'integer','unit_irr'=>'money','setup_irr'=>'money','shipping_irr'=>'money','tax_irr'=>'money','expiry'=>'text','lead_days'=>'integer','terms'=>'textarea'),$row,array('org_id'=>$org));
                    $r->set_url_params(array('id'=>$row['id'])); $r->set_query_params(array('org_id'=>$org,'page'=>1)); $full=self::get($r);
                    if (!is_wp_error($full)) foreach ($full->get_data()['children'] as $child) {
                        $html.='<p>'.esc_html($child['type'].' — '.$child['id'].' — '.$child['amount_irr'].' IRR').'</p>';
                        if ($child['type']==='quote') { $html.='<p>'.nl2br(esc_html($child['payload']['terms'])).'</p>'; if (Auth::agency() && $child['state']==='submitted' && $row['state']==='open') $html.=self::form_html('award',$s::t('Award this quote (full or split quantity)','تخصیص این پیشنهاد (تعداد کامل یا بخشی)'),array('quantity'=>'integer','reason'=>'text'),$row,array('quote_id'=>$child['id'])); }
                    }
                } else {
                    $html.='<p>'.esc_html($s::t('Money status: manual and unverified','وضعیت پول: دستی و تأییدنشده')).'</p><p>'.nl2br(esc_html($p['quote_snapshot']['terms']['terms'])).'</p><p>'.esc_html($s::t('Accepted quote hash: ','هش پیشنهاد پذیرفته‌شده: ').$p['quote_snapshot_hash']).'</p>';
                    if ($p['artwork']) $html.='<p>'.esc_html($s::t('Artwork revision / private reference / SHA-256: ','نسخه اثر / مرجع خصوصی / SHA-256: ').$p['artwork']['revision'].' / '.$p['artwork']['file_reference'].' / '.$p['artwork']['sha256']).'</p><p>'.esc_html($s::t('Current sample approved: ','نمونه فعلی تأیید شده: ').($p['sample_approved']?$s::t('Yes','بله'):$s::t('No','خیر'))).'</p>';
                    $documentUrl=add_query_arg(array('atlas_area'=>'portal','atlas_proc_org'=>$org,'lang'=>(($_GET['lang']??'')==='fa'?'fa':'en'),'document_context_type'=>'procurement','document_context_ref'=>$row['id']),home_url('/'));
                    $html.='<p><a href="'.esc_url($documentUrl).'">'.esc_html($s::t('Upload and review private artwork files for this award','بارگذاری و بررسی فایل خصوصی اثر برای این تخصیص')).'</a></p>';
                    if ($p['artwork'] && class_exists('Atlas_Private_Files') && Atlas_Private_Files::clean_reference($p['artwork']['file_reference'],'procurement',$row['id'])) {
                        $fileId=$p['artwork']['file_reference'];
                        $html.='<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('atlas_private_download_'.$fileId,'_wpnonce',true,false).'<input type="hidden" name="action" value="atlas_private_download"><input type="hidden" name="file_id" value="'.esc_attr($fileId).'"><button>'.esc_html($s::t('Download current private proof','دریافت خصوصی نسخه فعلی')).'</button></form>';
                    }
                    foreach ($p['artwork_history']??array() as $revision) $html.='<p>'.esc_html($s::t('Retained prior proof revision: ','نسخه پیشین ثبت‌شده: ').$revision['revision'].' — '.$revision['file_reference'].' — '.$revision['sha256']).'</p>';
                    $html.='<p>'.esc_html($s::t('Shipped / received / accepted / rejected / return pending: ','ارسال / دریافت / پذیرش / مردود / در انتظار مرجوعی: ').$p['shipped'].' / '.$p['received'].' / '.$p['accepted'].' / '.$p['rejected'].' / '.$p['return_quantity']).'</p>';
                    if ($p['payable_irr']!==null) $html.='<p>'.esc_html($s::t('Reviewed payable (IRR): ','بدهی بررسی‌شده (ریال): ').$p['payable_irr']).'</p>';
                    foreach ($p['settlements'] as $intent) $html.='<p>'.esc_html($intent['id'].' — '.$intent['amount_irr'].' IRR — '.$intent['state'].' — '.$intent['money_status']).'</p>';
                    $h=Auth::agency()?array():array('org_id'=>$org); $reason=array('reason'=>'text');
                    if ($row['state']!=='closed') {
                        if (!$p['shipped'] && $p['payable_irr']===null) $html.=self::form_html('artwork',$s::t('Propose artwork revision','پیشنهاد نسخه اثر'),array('file_reference'=>'text','sha256'=>'text','rights_attested'=>'boolean'),$row,$h);
                        if (Auth::agency() && $row['state']==='proof_pending') $html.=self::form_html('art_review',$s::t('Review exact artwork revision','بررسی نسخه دقیق اثر'),array('decision'=>'text','scan_review_reference'=>'text','reason'=>'text'),$row,array('proof_revision'=>$p['artwork']['revision']));
                        if (Auth::agency() && in_array($row['state'],array('proof_approved','sample_rejected'),true)) $html.=self::form_html('sample',$s::t('Record physical sample review','ثبت بررسی نمونه فیزیکی'),array('decision'=>'text','evidence_reference'=>'text','reason'=>'text'),$row,array('proof_revision'=>$p['artwork']['revision']));
                        if (Auth::agency() && $row['state']==='sample_approved') $html.=self::form_html('authorize',$s::t('Authorize production','اجازه تولید'),$reason,$row);
                        if (in_array($row['state'],array('authorized','started'),true)) $html.=self::form_html('milestone',$s::t('Record next production milestone','ثبت مرحله بعدی تولید'),array('evidence_reference'=>'text','reason'=>'text'),$row,array_merge($h,array('milestone'=>$row['state']==='authorized'?'started':'completed')));
                        if ($p['production_completed'] && $p['shipped']<$row['quantity']) $html.=self::form_html('shipment',$s::t('Record manual shipment','ثبت ارسال دستی'),array('quantity'=>'integer','tracking_reference'=>'text','reason'=>'text'),$row,$h);
                        if (Auth::agency() && $p['received']<$p['shipped']) $html.=self::form_html('inspection',$s::t('Inspect delivered quantities','بازرسی تعداد تحویلی'),array('received'=>'integer','accepted'=>'integer','rejected'=>'integer','evidence_reference'=>'text','reason'=>'text'),$row);
                        if (Auth::agency() && $p['payable_irr']===null && $p['accepted']+$p['rejected']>0) $html.=self::form_html('return_request',$s::t('Open manufacturer QA return','ایجاد مرجوعی کنترل کیفیت تولیدکننده'),array('accepted_quantity'=>'integer','rejected_quantity'=>'integer','reason'=>'text'),$row);
                        if (Auth::agency() && $p['return_quantity']) $html.=self::form_html('return_resolve',$s::t('Record agreed return resolution','ثبت نتیجه توافقی مرجوعی'),array('replacement_quantity'=>'integer','credit_quantity'=>'integer','credit_irr'=>'money','evidence_reference'=>'text','reason'=>'text'),$row);
                        if (Auth::agency() && current_user_can('atlas_manage_finance')) {
                            if ($row['state']==='qc_passed' && $p['payable_irr']===null) $html.=self::form_html('payable',$s::t('Approve reviewed invoice payable','تأیید بدهی صورتحساب بررسی‌شده'),array('invoice_reference'=>'text','reason'=>'text'),$row);
                            if ($p['payable_irr']!==null) $html.=self::form_html('settlement_intent',$s::t('Record settlement intent (no transfer)','ثبت قصد تسویه (بدون انتقال)'),array('amount_irr'=>'money','reason'=>'text'),$row);
                            foreach ($p['settlements'] as $intent) { $html.='<p>'.esc_html($intent['id'].' — '.$intent['amount_irr'].' IRR — '.$intent['state'].' — '.$intent['money_status']).'</p>'; if ($intent['state']==='requested') $html.=self::form_html('settlement_approve',$s::t('Independently approve settlement intent','تأیید مستقل قصد تسویه'),$reason,$row,array('intent_id'=>$intent['id'])); if ($intent['state']==='approved') $html.=self::form_html('settlement_evidence',$s::t('Record manual bank evidence (unverified)','ثبت شواهد دستی بانکی (تأییدنشده)'),array('evidence_reference'=>'text','reason'=>'text'),$row,array('intent_id'=>$intent['id'])); }
                            if ($p['payable_irr']!==null) $html.=self::form_html('close',$s::t('Close reconciled manual workflow','بستن گردش کار دستی تطبیق‌داده‌شده'),$reason,$row);
                        }
                    }
                }
                $html.='</article>';
            }
        }
        $args=array('atlas_area'=>'portal','atlas_proc_org'=>$org,'lang'=>isset($_GET['lang'])&&$_GET['lang']==='fa'?'fa':'en');
        $html.='<nav aria-label="'.esc_attr($s::t('Procurement pages','صفحات تدارکات')).'">'; if ($page>1) $html.='<a href="'.esc_url(add_query_arg(array_merge($args,array('atlas_proc_page'=>$page-1)),home_url('/'))).'">'.esc_html($s::t('Previous','قبل')).'</a> '; $html.='<a href="'.esc_url(add_query_arg(array_merge($args,array('atlas_proc_page'=>$page+1)),home_url('/'))).'">'.esc_html($s::t('Next','بعد')).'</a></nav>';
        return $html.'</details>';
    }
}
add_action('atlas_operations_ready',array('Atlas_Procurement','register'));
