<?php
if (!defined('ABSPATH')) { exit; }
use Atlas\Core\Auth;
use Atlas\Core\DB;

/** Versioned confidential terms and recorded attestations; no legal-signature claim. */
final class Atlas_Partner_Contracts {
    private static $ready=false;
    private const TEXT_FIELDS=array('agency_party'=>180,'partner_party'=>180,'territories'=>1000,'pricing_terms'=>4000,'settlement_terms'=>2000,'cancellation_terms'=>2000,'termination_terms'=>2000,'dispute_terms'=>2000,'marketing_claims'=>1000);
    private const SERVICES=array('tour','event','cohort','accommodation','transfer','rental','ticketing','manufacturing','merchandise');
    public static function table($versions=false) { global $wpdb; return $wpdb->prefix.($versions?'atlas_partner_contract_versions':'atlas_partner_contracts'); }
    public static function install() {
        $s='Atlas_Partner_Security';
        $contracts=$s::install('atlas_partner_contracts',
            'id char(36) NOT NULL, org_id bigint unsigned NOT NULL, title varchar(180) NOT NULL, current_revision int unsigned NOT NULL, version bigint unsigned NOT NULL, state varchar(20) NOT NULL, created_by bigint unsigned NOT NULL, created_at datetime(6) NOT NULL, updated_at datetime(6) NOT NULL, PRIMARY KEY  (id), KEY org_state (org_id,state)',
            array('id'=>'char(36)','org_id'=>'bigint unsigned','title'=>'varchar(180)','current_revision'=>'int unsigned','version'=>'bigint unsigned','state'=>'varchar(20)','created_by'=>'bigint unsigned','created_at'=>'datetime(6)','updated_at'=>'datetime(6)'),array('PRIMARY'=>array('id')));
        $versions=$s::install('atlas_partner_contract_versions',
            "id char(36) NOT NULL, contract_id char(36) NOT NULL, revision int unsigned NOT NULL, snapshot longtext NOT NULL, snapshot_hash char(64) NOT NULL, proposed_by bigint unsigned NOT NULL, created_at datetime(6) NOT NULL, review_state varchar(16) NOT NULL DEFAULT 'pending', reviewed_by bigint unsigned NOT NULL DEFAULT 0, reviewed_at datetime(6) NULL, review_reason varchar(190) NOT NULL DEFAULT '', evidence_reference varchar(120) NOT NULL DEFAULT '', accepted_by bigint unsigned NOT NULL DEFAULT 0, accepted_at datetime(6) NULL, acceptance_statement varchar(190) NOT NULL DEFAULT '', PRIMARY KEY  (id), UNIQUE KEY revision_once (contract_id,revision)",
            array('id'=>'char(36)','contract_id'=>'char(36)','revision'=>'int unsigned','snapshot'=>'longtext','snapshot_hash'=>'char(64)','proposed_by'=>'bigint unsigned','created_at'=>'datetime(6)','review_state'=>'varchar(16)','reviewed_by'=>'bigint unsigned','reviewed_at'=>'datetime(6)','review_reason'=>'varchar(190)','evidence_reference'=>'varchar(120)','accepted_by'=>'bigint unsigned','accepted_at'=>'datetime(6)','acceptance_statement'=>'varchar(190)'),array('PRIMARY'=>array('id'),'revision_once'=>array('contract_id','revision')));
        return self::$ready=$contracts && $versions;
    }
    public static function register() {
        self::install(); add_action('rest_api_init',array(__CLASS__,'routes')); add_action('admin_post_atlas_partner_contract',array(__CLASS__,'form'));
        add_filter('atlas_operations_dashboard',static function($html,$customer){return $customer?$html:$html.self::panel();},11,2);
    }
    public static function routes() {
        $p=array('permission_callback'=>array('Atlas_Partner_Security','permission'));
        $base='/organizations/(?P<org_id>\d+)/contracts';
        register_rest_route('agency/v1',$base,array(array_merge($p,array('methods'=>'GET','callback'=>array(__CLASS__,'listing'))),array_merge($p,array('methods'=>'POST','callback'=>static function($r){return self::mutate($r,'create');}))));
        register_rest_route('agency/v1',$base.'/(?P<id>[a-f0-9-]{36})',array_merge($p,array('methods'=>'GET','callback'=>array(__CLASS__,'get'))));
        foreach (array('versions'=>'revise','review'=>'review','accept'=>'accept','close'=>'close') as $path=>$op) register_rest_route('agency/v1',$base.'/(?P<id>[a-f0-9-]{36})/'.$path,array_merge($p,array('methods'=>'POST','callback'=>static function($r) use ($op){return self::mutate($r,$op);})));
    }
    private static function guard($r,$org) {
        $s='Atlas_Partner_Security'; $ok=$s::permission($r); if (is_wp_error($ok)) return $ok;
        if (!self::$ready) return $s::error('atlas_storage_unavailable','Partner storage is unavailable.',503);
        return $s::manager($org)?true:$s::error('atlas_not_found','Partner record unavailable.',404);
    }
    private static function utc($value) {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)$/D',$value)) return false;
        try { $d=new DateTimeImmutable($value); } catch (Throwable $e) { return false; }
        $errors=DateTimeImmutable::getLastErrors(); if (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) return false;
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
    private static function snapshot($input) {
        $s='Atlas_Partner_Security'; $names=array_merge(array_keys(self::TEXT_FIELDS),array('effective_from','effective_until','services','confidential'));
        if (!is_array($input) || array_diff(array_keys($input),$names) || count($input)!==count($names)) return $s::error('atlas_invalid_contract','Complete, bounded contract terms are required.');
        $out=array(); foreach (self::TEXT_FIELDS as $name=>$max) { if (!isset($input[$name]) || !is_string($input[$name]) || trim($input[$name])==='' || strlen($input[$name])>$max) return $s::error('atlas_invalid_contract','Complete, bounded contract terms are required.'); $out[$name]=sanitize_textarea_field($input[$name]); }
        $from=self::utc($input['effective_from']??null); $until=self::utc($input['effective_until']??null);
        if (!$from || !$until || $until<=$from || !is_bool($input['confidential']??null) || !is_array($input['services']??null) || !$input['services'] || count($input['services'])>count(self::SERVICES)) return $s::error('atlas_invalid_contract','Valid UTC dates, service scope and confidentiality are required.');
        foreach ($input['services'] as $service) if (!is_string($service) || !in_array($service,self::SERVICES,true)) return $s::error('atlas_invalid_contract','Unknown contract service.');
        $services=array_values(array_unique($input['services'])); sort($services,SORT_STRING);
        return array_merge($out,array('effective_from'=>$from,'effective_until'=>$until,'services'=>$services,'confidential'=>$input['confidential']));
    }
    private static function effective($contract,$version) {
        if ($contract->state==='closed') return 'closed';
        if (!$version || !(int)$version->accepted_by || $version->review_state!=='approved') return $contract->state;
        $terms=json_decode($version->snapshot,true); return DB::now()>=$terms['effective_until']?'expired':(DB::now()<$terms['effective_from']?'future':'active');
    }
    private static function project($c,$v=null) {
        return array('id'=>$c->id,'org_id'=>(int)$c->org_id,'title'=>$c->title,'revision'=>(int)$c->current_revision,'version'=>(int)$c->version,'state'=>$c->state,'effective_state'=>self::effective($c,$v));
    }
    public static function listing($r) {
        global $wpdb; $org=(int)$r['org_id']; $ok=self::guard($r,$org); if (is_wp_error($ok)) return $ok;
        $page=(int)($r->get_param('page')?:1); if ($page<1 || $page>10000) return Atlas_Partner_Security::error('atlas_invalid_page','Invalid page.');
        $items=array(); foreach ($wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table().' WHERE org_id=%d ORDER BY updated_at DESC,id LIMIT 25 OFFSET %d',$org,($page-1)*25)) as $row) {
            $v=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table(true).' WHERE contract_id=%s AND revision=%d',$row->id,$row->current_revision)); $items[]=self::project($row,$v);
        }
        if (!DB::audit(get_current_user_id(),$org,'partner.contract_list','contracts','read')) return Atlas_Partner_Security::error('atlas_audit_failed','Read could not be recorded.',503);
        return Atlas_Partner_Security::response(array('items'=>$items,'page'=>$page,'limit'=>25));
    }
    public static function get($r) {
        global $wpdb; $org=(int)$r['org_id']; $ok=self::guard($r,$org); if (is_wp_error($ok)) return $ok;
        $c=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%s AND org_id=%d',(string)$r['id'],$org));
        if (!$c) return Atlas_Partner_Security::error('atlas_not_found','Partner record unavailable.',404);
        $page=(int)($r->get_param('page')?:1); if ($page<1 || $page>10000) return Atlas_Partner_Security::error('atlas_invalid_page','Invalid page.');
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table(true).' WHERE contract_id=%s ORDER BY revision DESC LIMIT 25 OFFSET %d',$c->id,($page-1)*25));
        $current=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table(true).' WHERE contract_id=%s AND revision=%d',$c->id,$c->current_revision));
        if (!DB::audit(get_current_user_id(),$org,'partner.contract_read',$c->id,'read')) return Atlas_Partner_Security::error('atlas_audit_failed','Read could not be recorded.',503);
        $out=self::project($c,$current); $out['versions']=array();
        foreach ($rows as $v) $out['versions'][]=array('revision'=>(int)$v->revision,'snapshot'=>json_decode($v->snapshot,true),'snapshot_hash'=>$v->snapshot_hash,'proposed_by'=>(int)$v->proposed_by,'review_state'=>$v->review_state,'reviewed_by'=>(int)$v->reviewed_by,'review_reason'=>$v->review_reason,'evidence_reference'=>$v->evidence_reference,'accepted_by'=>(int)$v->accepted_by,'accepted_at'=>$v->accepted_at,'acceptance_statement'=>$v->acceptance_statement);
        $out['page']=$page; $out['limit']=25; return Atlas_Partner_Security::response($out);
    }
    public static function mutate($r,$op) {
        global $wpdb; $s='Atlas_Partner_Security'; $org=(int)$r['org_id']; $ok=self::guard($r,$org); if (is_wp_error($ok)) return $ok;
        if (!in_array($op,array('create','revise','review','accept','close'),true)) return $s::error('atlas_invalid_action','Invalid action.');
        $b=$r->get_json_params(); $key=(string)$r->get_header('Idempotency-Key'); $actor=get_current_user_id();
        $allowed=array('create'=>array('title','snapshot'),'revise'=>array('expected_version','snapshot','reason'),'review'=>array('expected_version','decision','reason','evidence_reference'),'accept'=>array('expected_version','snapshot_hash','authority_attested'),'close'=>array('expected_version','reason'));
        if (!is_array($b) || array_diff(array_keys($b),$allowed[$op]) || count($b)!==count($allowed[$op]) || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$key) || ($op!=='create' && (!is_int($b['expected_version']??null) || $b['expected_version']<1))) return $s::error('atlas_invalid_contract','A complete request, current version and request key are required.');
        if (in_array($op,array('create','review','close'),true) && !Auth::agency()) return $s::error('atlas_forbidden','Agency review required.',403);
        if ($op==='accept' && (Auth::agency() || ($b['authority_attested']??null)!==true || !is_string($b['snapshot_hash']??null) || !preg_match('/^[a-f0-9]{64}$/D',$b['snapshot_hash']))) return $s::error('atlas_forbidden','A scoped partner representative must explicitly attest authority and accept the exact version.',403);
        if ($op==='create' && (!is_string($b['title']??null) || trim($b['title'])==='' || strlen($b['title'])>180)) return $s::error('atlas_invalid_contract','A bounded title is required.');
        if (isset($b['reason']) && (!is_string($b['reason']) || trim($b['reason'])==='' || strlen($b['reason'])>190)) return $s::error('atlas_invalid_reason','A bounded reason is required.');
        if ($op==='review' && (!in_array($b['decision'],array('approved','rejected'),true) || !is_string($b['evidence_reference']) || !preg_match('/^[A-Za-z0-9._:-]{8,120}$/D',$b['evidence_reference']))) return $s::error('atlas_invalid_review','A decision and reviewed evidence reference are required.');
        if (isset($b['snapshot'])) { $terms=self::snapshot($b['snapshot']); if (is_wp_error($terms)) return $terms; $b['snapshot']=$terms; }
        ksort($b,SORT_STRING); $scope='user:'.$actor; $operation='partner.contract.'.$op; $id=$op==='create'?'':(string)$r['id']; $hash=hash('sha256',wp_json_encode(array($org,$id,$b)));
        $wpdb->query('START TRANSACTION');
        try {
            if (!$s::active($s::active_org($org,true)) || !$s::manager($org)) throw new DomainException('scope');
            $prior=$wpdb->get_row($wpdb->prepare('SELECT request_hash,response FROM '.DB::table('idempotency').' WHERE scope=%s AND operation=%s AND idem_key=%s',$scope,$operation,$key));
            if ($prior) { $wpdb->query('ROLLBACK'); return hash_equals($prior->request_hash,$hash)?$s::response(json_decode($prior->response,true)):$s::error('atlas_idempotency_conflict','The request key was reused.',409); }
            $now=DB::now(); $revision=1;
            if ($op==='create') {
                $id=wp_generate_uuid4(); if (!$wpdb->insert(self::table(),array('id'=>$id,'org_id'=>$org,'title'=>sanitize_text_field($b['title']),'current_revision'=>1,'version'=>1,'state'=>'negotiating','created_by'=>$actor,'created_at'=>$now,'updated_at'=>$now))) throw new RuntimeException('create');
            } else {
                $c=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%s AND org_id=%d FOR UPDATE',$id,$org));
                if (!$c || (int)$c->version!==$b['expected_version'] || $c->state==='closed') throw new DomainException('version');
                $v=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table(true).' WHERE contract_id=%s AND revision=%d FOR UPDATE',$id,$c->current_revision)); if (!$v) throw new RuntimeException('version');
                $revision=(int)$c->current_revision;
                if ($op==='revise') $revision++;
                if ($op==='review') {
                    if ((int)$v->proposed_by===$actor || $v->review_state!=='pending' || (int)$v->accepted_by) throw new DomainException('independent review');
                    if ($wpdb->update(self::table(true),array('review_state'=>$b['decision'],'reviewed_by'=>$actor,'reviewed_at'=>$now,'review_reason'=>sanitize_text_field($b['reason']),'evidence_reference'=>$b['evidence_reference']),array('id'=>$v->id))!==1) throw new RuntimeException('review');
                }
                if ($op==='accept') {
                    $snapshot=json_decode($v->snapshot,true);
                    if ($v->review_state!=='approved' || (int)$v->accepted_by || !hash_equals($v->snapshot_hash,$b['snapshot_hash']) || $now>=$snapshot['effective_until']) throw new DomainException('accept');
                    if ($wpdb->update(self::table(true),array('accepted_by'=>$actor,'accepted_at'=>$now,'acceptance_statement'=>'Authenticated representative attested authority and accepted this exact snapshot; legal identity/signature not verified.'),array('id'=>$v->id,'accepted_by'=>0))!==1) throw new RuntimeException('accept');
                }
                $state=$op==='review'?$b['decision']:($op==='accept'?'accepted':($op==='close'?'closed':'negotiating'));
                if ($wpdb->update(self::table(),array('current_revision'=>$revision,'version'=>(int)$c->version+1,'state'=>$state,'updated_at'=>$now),array('id'=>$id,'version'=>(int)$c->version))!==1) throw new RuntimeException('update');
            }
            if (in_array($op,array('create','revise'),true)) {
                $snapshot=wp_json_encode($b['snapshot']); if (!$wpdb->insert(self::table(true),array('id'=>wp_generate_uuid4(),'contract_id'=>$id,'revision'=>$revision,'snapshot'=>$snapshot,'snapshot_hash'=>hash('sha256',$snapshot),'proposed_by'=>$actor,'created_at'=>$now))) throw new RuntimeException('snapshot');
            }
            $result=array('id'=>$id,'org_id'=>$org,'revision'=>$revision,'version'=>$op==='create'?1:$b['expected_version']+1,'state'=>$op==='create'?'negotiating':$state);
            if (!DB::audit($actor,$org,'partner.contract_'.$op,$id,'success',$b['reason']??'explicit version acceptance') || !DB::emit('partner.contract_'.$op,$id) || !$wpdb->insert(DB::table('idempotency'),array('scope'=>$scope,'operation'=>$operation,'idem_key'=>$key,'request_hash'=>$hash,'response'=>wp_json_encode($result),'created_at'=>$now))) throw new RuntimeException('audit');
            $wpdb->query('COMMIT'); return $s::response($result,$op==='create'?201:200);
        } catch (DomainException $e) { $wpdb->query('ROLLBACK'); return $s::error('atlas_contract_conflict','The contract, authority or version changed. Refresh before continuing.',409); }
        catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            $prior=$wpdb->get_row($wpdb->prepare('SELECT request_hash,response FROM '.DB::table('idempotency').' WHERE scope=%s AND operation=%s AND idem_key=%s',$scope,$operation,$key));
            if ($prior) return hash_equals($prior->request_hash,$hash)?$s::response(json_decode($prior->response,true)):$s::error('atlas_idempotency_conflict','The request key was reused.',409);
            return $s::error('atlas_contract_failed','Contract change could not be recorded.',503);
        }
    }
    /** Used by publication/commitment policy; preserves valid historic acceptance during negotiation. */
    public static function has_active_contract($org,$service='') {
        global $wpdb; if (!self::$ready || !Atlas_Partner_Security::active(Atlas_Partner_Security::active_org((int)$org))) return false;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT v.contract_id,v.snapshot FROM '.self::table(true).' v INNER JOIN '.self::table()." c ON c.id=v.contract_id WHERE c.org_id=%d AND c.state<>'closed' AND v.review_state='approved' AND v.accepted_by>0 ORDER BY v.contract_id,v.revision DESC LIMIT 1001",(int)$org));
        if (count($rows)>1000) return false;
        $seen=array();
        foreach ($rows as $row) {
            if (isset($seen[$row->contract_id])) continue; $seen[$row->contract_id]=true;
            $t=json_decode($row->snapshot,true); if (is_array($t) && DB::now()>=$t['effective_from'] && DB::now()<$t['effective_until'] && ($service==='' || in_array($service,$t['services'],true))) return true;
        }
        return false;
    }
    private static function terms_form($terms=array()) {
        $s='Atlas_Partner_Security'; $labels=array('agency_party'=>array('Agency legal party','طرف حقوقی آژانس'),'partner_party'=>array('Partner legal party','طرف حقوقی همکار'),'territories'=>array('Territories','محدوده‌ها'),'pricing_terms'=>array('Commission/net price, calculation basis and tax terms','کمیسیون یا نرخ خالص، مبنای محاسبه و شرایط مالیات'),'settlement_terms'=>array('Settlement cycle and terms','دوره و شرایط تسویه'),'cancellation_terms'=>array('Cancellation terms','شرایط لغو'),'termination_terms'=>array('Termination terms','شرایط خاتمه'),'dispute_terms'=>array('Dispute handling','رسیدگی به اختلاف'),'marketing_claims'=>array('Permitted marketing claims','ادعاهای تبلیغاتی مجاز'));
        $html=''; foreach ($labels as $name=>$label) $html.='<label>'.esc_html($s::t($label[0],$label[1])).'<textarea name="'.$name.'" required maxlength="'.self::TEXT_FIELDS[$name].'">'.esc_html($terms[$name]??'').'</textarea></label>';
        foreach (array('effective_from'=>array('Effective from (UTC ISO 8601)','شروع اعتبار (UTC، ISO 8601)'),'effective_until'=>array('Effective until (UTC ISO 8601)','پایان اعتبار (UTC، ISO 8601)')) as $name=>$label) { $value=isset($terms[$name])?str_replace(' ','T',$terms[$name]).'Z':''; $html.=$s::input($name,$s::t($label[0],$label[1]),$value); }
        $html.=$s::input('services',$s::t('Service codes, comma-separated: '.implode(', ',self::SERVICES),'کد خدمات با ویرگول انگلیسی: '.implode(', ',self::SERVICES)),implode(',',$terms['services']??array())).'<label><input type="checkbox" name="confidential" value="1"'.(!empty($terms['confidential'])?' checked':'').'>'.esc_html($s::t('Confidential terms','شرایط محرمانه')).'</label>';
        return $html;
    }
    public static function panel() {
        if (!is_user_logged_in()) return ''; $s='Atlas_Partner_Security'; $html='<details><summary>'.esc_html($s::t('Partner contracts and revisions','قراردادها و نسخه‌های همکاری')).'</summary><p>'.esc_html($s::t('Recorded review and acceptance do not verify legal authority or create a digital signature. Qualified legal review is required.','ثبت بررسی و پذیرش، اختیار حقوقی را تأیید نمی‌کند و امضای دیجیتال ایجاد نمی‌کند. بررسی حقوقی تخصصی لازم است.')).'</p>';
        if (!self::$ready) return '<p>'.esc_html($s::t('Partner contract storage is unavailable.','سامانه نگهداری قراردادها در دسترس نیست.')).'</p>';
        $html.=$s::selector();
        foreach ($s::selected_orgs() as $org) if ($s::manager($org)) {
            $html.='<h3>'.esc_html($s::t('Organization ','سازمان ').$org).'</h3>';
            if (Auth::agency()) $html.=$s::form_start('atlas_partner_contract').'<input type="hidden" name="operation" value="create"><input type="hidden" name="org_id" value="'.esc_attr($org).'">'.$s::input('title',$s::t('Contract title','عنوان قرارداد')).self::terms_form().'<button>'.esc_html($s::t('Propose contract','پیشنهاد قرارداد')).'</button></form>';
            $page=isset($_GET['atlas_contract_page']) && is_scalar($_GET['atlas_contract_page'])?max(1,min(10000,(int)$_GET['atlas_contract_page'])):1;
            $r=new WP_REST_Request('GET','/'); $r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest')); $r->set_url_params(array('org_id'=>(int)$org)); $r->set_query_params(array('page'=>$page)); $rows=self::listing($r); if (is_wp_error($rows)) continue;
            foreach ($rows->get_data()['items'] as $item) {
                $r->set_url_params(array('org_id'=>(int)$org,'id'=>$item['id'])); $r->set_query_params(array('page'=>1)); $full=self::get($r); if (is_wp_error($full)) continue; $data=$full->get_data(); $current=$data['versions'][0]??null; if (!$current) continue;
                $html.='<article><h4>'.esc_html($item['title'].' — '.$item['effective_state']).'</h4>';
                foreach ($data['versions'] as $v) { $html.='<details><summary>'.esc_html($s::t('Revision ','نسخه ').$v['revision'].' — '.$v['review_state'].' — '.($v['accepted_by']?$s::t('accepted','پذیرفته‌شده'):$s::t('not accepted','پذیرفته‌نشده'))).'</summary><dl>'; foreach ($v['snapshot'] as $field=>$value) $html.='<dt>'.esc_html($field).'</dt><dd>'.nl2br(esc_html(is_array($value)?implode(', ',$value):(is_bool($value)?($value?'yes':'no'):$value))).'</dd>'; $html.='</dl><p>'.esc_html($v['snapshot_hash']).'</p><p>'.esc_html($v['acceptance_statement']).'</p></details>'; }
                if ($item['state']!=='closed') {
                    $common='<input type="hidden" name="org_id" value="'.esc_attr($org).'"><input type="hidden" name="id" value="'.esc_attr($item['id']).'"><input type="hidden" name="expected_version" value="'.esc_attr($item['version']).'">';
                    $html.=$s::form_start('atlas_partner_contract').$common.'<input type="hidden" name="operation" value="revise">'.self::terms_form($current['snapshot']).$s::input('reason',$s::t('Negotiation reason','دلیل مذاکره')).'<button>'.esc_html($s::t('Propose a new revision','پیشنهاد نسخه جدید')).'</button></form>';
                    if (Auth::agency() && $current['review_state']==='pending') $html.=$s::form_start('atlas_partner_contract').$common.'<input type="hidden" name="operation" value="review"><label>'.esc_html($s::t('Independent review','بررسی مستقل')).'<select name="decision"><option value="approved">Approve / تأیید</option><option value="rejected">Reject / رد</option></select></label>'.$s::input('evidence_reference',$s::t('Reviewed evidence reference','مرجع شواهد بررسی‌شده')).$s::input('reason',$s::t('Review reason','دلیل بررسی')).'<button>'.esc_html($s::t('Record review','ثبت بررسی')).'</button></form>';
                    if (!Auth::agency() && $current['review_state']==='approved' && !$current['accepted_by']) $html.=$s::form_start('atlas_partner_contract').$common.'<input type="hidden" name="operation" value="accept"><input type="hidden" name="snapshot_hash" value="'.esc_attr($current['snapshot_hash']).'"><label><input type="checkbox" name="authority_attested" value="1" required>'.esc_html($s::t('I attest that I am authorized to accept this exact version for my organization.','اظهار می‌کنم اختیار پذیرش این نسخه دقیق را برای سازمان خود دارم.')).'</label><button>'.esc_html($s::t('Record acceptance','ثبت پذیرش')).'</button></form>';
                    if (Auth::agency()) $html.=$s::form_start('atlas_partner_contract').$common.'<input type="hidden" name="operation" value="close">'.$s::input('reason',$s::t('Closure reason','دلیل بستن')).'<button>'.esc_html($s::t('Close contract for new commitments','بستن قرارداد برای تعهدات جدید')).'</button></form>';
                }
                $html.='</article>';
            }
            $html.=$s::pagination('atlas_contract_page',$page,count($rows->get_data()['items']));
        }
        return $html.'</details>';
    }
    public static function form() {
        check_admin_referer('atlas_partner_contract'); $s='Atlas_Partner_Security'; $op=$s::post('operation');
        $r=new WP_REST_Request('POST','/'); $r->set_header('Content-Type','application/json'); $r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest')); $r->set_header('Idempotency-Key',$s::post('request_key')); $r->set_url_params(array('org_id'=>absint($s::post('org_id')),'id'=>$s::post('id')));
        $b=$op==='create'?array('title'=>$s::post('title')):array('expected_version'=>(int)$s::post('expected_version'));
        if (in_array($op,array('create','revise'),true)) { $terms=array(); foreach (self::TEXT_FIELDS as $name=>$max) $terms[$name]=$s::post($name); foreach (array('effective_from','effective_until') as $name) $terms[$name]=$s::post($name); $terms['services']=array_map('trim',explode(',',$s::post('services'))); $terms['confidential']=$s::post('confidential')==='1'; $b['snapshot']=$terms; }
        if (in_array($op,array('revise','review','close'),true)) $b['reason']=$s::post('reason');
        if ($op==='review') { $b['decision']=$s::post('decision'); $b['evidence_reference']=$s::post('evidence_reference'); }
        if ($op==='accept') { $b['snapshot_hash']=$s::post('snapshot_hash'); $b['authority_attested']=$s::post('authority_attested')==='1'; }
        $r->set_body(wp_json_encode($b)); $result=self::mutate($r,$op); nocache_headers();
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()),'',array('response'=>(int)($result->get_error_data()['status']??422)));
        wp_safe_redirect(add_query_arg(array('atlas_area'=>'portal','lang'=>isset($_GET['lang'])&&$_GET['lang']==='fa'?'fa':'en'),home_url('/'))); exit;
    }
}
add_action('atlas_operations_ready',array('Atlas_Partner_Contracts','register'));
