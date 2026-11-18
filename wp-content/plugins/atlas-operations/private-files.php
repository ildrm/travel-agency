<?php
if (!defined('ABSPATH')) { exit; }
use Atlas\Core\Auth;
use Atlas\Core\DB;
use Atlas\Core\Records;

/** Purpose-bound encrypted documents. Quarantine is never a downloadable state. */
final class Atlas_Private_Files {
    private static $ready=false;
    public static function table($kind='files') { global $wpdb; return $wpdb->prefix.'atlas_private_'.$kind; }
    private static function t($en,$fa) { return Atlas_Partner_Security::t($en,$fa); }
    private static function error($message,$status=422) { return new WP_Error('atlas_private_file_error',$message,array('status'=>$status)); }
    private static function agency() { return Auth::agency() && current_user_can('atlas_manage_records'); }
    private static function sensitive_access(int $owner): bool {
        if ($owner===get_current_user_id()) return true;
        return self::agency() && current_user_can('atlas_manage_sensitive_documents');
    }
    public static function register() {
        $p=Atlas_Partner_Security::class;
        $a=$p::install('atlas_private_files','id char(36) NOT NULL, owner_id bigint unsigned NOT NULL, org_id bigint unsigned NOT NULL, uploaded_by bigint unsigned NOT NULL, context_type varchar(40) NOT NULL, context_ref varchar(100) NOT NULL, policy_id char(36) NOT NULL, mime varchar(40) NOT NULL, size_bytes int unsigned NOT NULL, digest char(64) NOT NULL, state varchar(24) NOT NULL, version int unsigned NOT NULL, scan_evidence text NOT NULL, legal_hold tinyint unsigned NOT NULL, hold_reason varchar(190) NOT NULL, erasure_requested tinyint unsigned NOT NULL, expires_at datetime(6) NOT NULL, created_at datetime(6) NOT NULL, updated_at datetime(6) NOT NULL, PRIMARY KEY  (id), KEY owner_created (owner_id,created_at), KEY retention (state,expires_at)',array('id'=>'char(36)','state'=>'varchar(24)','digest'=>'char(64)','version'=>'int unsigned','expires_at'=>'datetime(6)'),array('PRIMARY'=>array('id')));
        $b=$p::install('atlas_private_policies','id char(36) NOT NULL, purpose varchar(80) NOT NULL, state varchar(16) NOT NULL, payload longtext NOT NULL, created_by bigint unsigned NOT NULL, approved_by bigint unsigned NOT NULL, created_at datetime(6) NOT NULL, PRIMARY KEY  (id)',array('id'=>'char(36)','payload'=>'longtext','state'=>'varchar(16)','created_by'=>'bigint unsigned','approved_by'=>'bigint unsigned'),array('PRIMARY'=>array('id')));
        self::$ready=$a&&$b;
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_action('admin_post_atlas_private_document',array(__CLASS__,'form'));
        add_action('admin_post_atlas_private_download',array(__CLASS__,'download'));
        add_filter('atlas_operations_dashboard',static function($html){return $html.self::panel();},35,1);
    }
    public static function routes() {
        $permission=array('permission_callback'=>array('Atlas_Partner_Security','permission'));
        register_rest_route('agency/v1','/private-files',array(array_merge($permission,array('methods'=>'GET','callback'=>array(__CLASS__,'listing'))),array_merge($permission,array('methods'=>'POST','callback'=>array(__CLASS__,'upload')))));
        register_rest_route('agency/v1','/private-file-policies',array_merge($permission,array('methods'=>'POST','callback'=>array(__CLASS__,'policy'))));
        foreach (array('approve','revoke') as $action) register_rest_route('agency/v1','/private-file-policies/(?P<id>[a-f0-9-]{36})/'.$action,array_merge($permission,array('methods'=>'POST','callback'=>static function($r) use($action){return self::policy_action($r,$action);})));
        foreach (array('scan','legal_hold','request_erasure','purge') as $action) register_rest_route('agency/v1','/private-files/(?P<id>[a-f0-9-]{36})/'.$action,array_merge($permission,array('methods'=>'POST','callback'=>static function($r) use($action){return self::command($r,$action);})));
    }
    /** Configuration is deliberately external to the web root and database exports. */
    private static function configuration() {
        if (!self::$ready || !function_exists('sodium_crypto_secretbox') || !class_exists('finfo') || !defined('ATLAS_PRIVATE_FILE_ROOT') || !defined('ATLAS_PRIVATE_FILE_KEY') || !is_string(ATLAS_PRIVATE_FILE_KEY)) return self::error('Private storage and a separate encryption key must be configured.',503);
        $root=ATLAS_PRIVATE_FILE_ROOT; $key=base64_decode(ATLAS_PRIVATE_FILE_KEY,true);
        if (!is_string($root) || !str_starts_with($root,'/') || $root==='/' || !is_string($key) || strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES || is_link($root)) return self::error('Private storage configuration is invalid.',503);
        if (!is_dir($root)) {
            $parent=realpath(dirname($root));
            if (!$parent || !self::outside_web($parent.'/'.basename($root)) || !mkdir($root,0700)) return self::error('Private storage is unavailable.',503);
        }
        $root=realpath($root); if (!$root || (fileperms($root)&0077)!==0) return self::error('Private storage permissions must be restricted.',503);
        if (!self::outside_web($root)) return self::error('Private files must be outside all served WordPress directories.',503);
        return array('root'=>$root,'key'=>$key);
    }
    private static function outside_web($path) {
        foreach (array(ABSPATH,WP_CONTENT_DIR,$_SERVER['DOCUMENT_ROOT']??'') as $public) {
            $public=$public!==''?realpath($public):false;
            if ($public && ($path===$public || str_starts_with($path,rtrim($public,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))) return false;
        }
        return true;
    }
    private static function path($configuration,$id) {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$id)?$configuration['root'].'/'.$id.'.blob':false;
    }
    /** Trusted extensions may resolve a custom object only after their own ACL checks. */
    public static function scope($type,$ref,$write=false) {
        global $wpdb; if (!is_user_logged_in() || !is_string($type) || !is_string($ref) || strlen($ref)>100) return false;
        if ($type==='booking') {
            $row=$wpdb->get_row($wpdb->prepare('SELECT user_id FROM '.DB::table('bookings').' WHERE id=%s',$ref));
            return $row && (self::agency() || (int)$row->user_id===get_current_user_id())?array('owner_id'=>(int)$row->user_id,'org_id'=>0):false;
        }
        if ($type==='record') {
            $row=$wpdb->get_row($wpdb->prepare('SELECT type,owner_id,org_id FROM '.DB::table('records').' WHERE id=%s',$ref));
            if (!$row || !in_array($row->type,array('support_case','partner_application'),true)) return false;
            $record=Records::get($row->type,$ref,get_current_user_id()); if (is_wp_error($record)) return false;
            if ($write && (int)$row->org_id>0 && !Auth::can('atlas_manage_records',(int)$row->org_id,null,'write')) return false;
            return array('owner_id'=>(int)$row->owner_id,'org_id'=>(int)$row->org_id);
        }
        $scope=apply_filters('atlas_private_file_scope',false,$type,$ref,$write,get_current_user_id());
        return is_array($scope) && isset($scope['owner_id'],$scope['org_id']) && is_int($scope['owner_id']) && $scope['owner_id']>0 && is_int($scope['org_id']) && $scope['org_id']>=0 ? $scope:false;
    }
    public static function policy($r) {
        global $wpdb; if (!self::agency()) return self::error('Restricted agency review required.',403); $b=$r->get_json_params();
        $keys=array('purpose','context_types','notice_en','notice_fa','retention_days','expires_at','review_reference','sensitive','legal_reference');
        if (!is_array($b) || array_diff(array_keys($b),$keys) || !is_string($b['purpose']??null) || !preg_match('/^[a-z][a-z0-9_]{3,79}$/D',$b['purpose']) || !is_array($b['context_types']??null) || !$b['context_types'] || count($b['context_types'])>8
            || !is_int($b['retention_days']??null) || $b['retention_days']<1 || $b['retention_days']>3650 || !is_bool($b['sensitive']??null)) return self::error('A bounded purpose, context, retention and classification are required.');
        foreach ($b['context_types'] as $v) if (!is_string($v) || !preg_match('/^[a-z][a-z_]{2,39}$/D',$v)) return self::error('Invalid document context.');
        foreach (array('notice_en','notice_fa') as $v) if (!is_string($b[$v]??null) || trim($b[$v])==='' || strlen($b[$v])>2000 || wp_strip_all_tags($b[$v])!==$b[$v]) return self::error('Plain-text purpose and retention notices in both languages are required.');
        foreach (array('review_reference','legal_reference') as $v) if (!is_string($b[$v]??null) || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$b[$v])) return self::error('Independent privacy and legal review references are required.');
        $expiry=is_string($b['expires_at']??null)?DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$b['expires_at'],new DateTimeZone('UTC')):false;
        if (!$expiry || $expiry->format('Y-m-d H:i:s.u')!==$b['expires_at'] || $b['expires_at']<=DB::now()) return self::error('A future UTC policy expiry is required.');
        if ($b['sensitive'] && (!defined('ATLAS_SENSITIVE_DOCUMENTS_ENABLED') || ATLAS_SENSITIVE_DOCUMENTS_ENABLED!==true)) return self::error('Sensitive documents are disabled for this site.',409);
        $id=wp_generate_uuid4(); $wpdb->query('START TRANSACTION');
        if (!$wpdb->insert(self::table('policies'),array('id'=>$id,'purpose'=>$b['purpose'],'state'=>'draft','payload'=>wp_json_encode($b),'created_by'=>get_current_user_id(),'approved_by'=>0,'created_at'=>DB::now())) || !DB::audit(get_current_user_id(),0,'private.policy_draft',$id,'success',$b['review_reference'])) { $wpdb->query('ROLLBACK'); return self::error('Policy could not be saved.',503); }
        $wpdb->query('COMMIT'); return Atlas_Partner_Security::response(array('id'=>$id,'state'=>'draft'),201);
    }
    public static function policy_action($r,$action) {
        global $wpdb; if (!self::agency()) return self::error('Restricted agency review required.',403); $b=$r->get_json_params();
        if (!is_array($b) || array_diff(array_keys($b),array('reason')) || !is_string($b['reason']??null) || trim($b['reason'])==='' || strlen($b['reason'])>190) return self::error('Review reason required.');
        $wpdb->query('START TRANSACTION');
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('policies').' WHERE id=%s FOR UPDATE',(string)$r['id']));
        if (!$row || ($action==='approve' && ($row->state!=='draft' || (int)$row->created_by===get_current_user_id()))) { $wpdb->query('ROLLBACK'); return self::error('A different agency reviewer must approve a draft policy.',409); }
        $state=$action==='approve'?'approved':'revoked';
        if ($wpdb->update(self::table('policies'),array('state'=>$state,'approved_by'=>get_current_user_id()),array('id'=>$row->id))===false || !DB::audit(get_current_user_id(),0,'private.policy_'.$action,$row->id,'success',$b['reason'])) { $wpdb->query('ROLLBACK'); return self::error('Review could not be saved.',503); }
        $wpdb->query('COMMIT'); return Atlas_Partner_Security::response(array('id'=>$row->id,'state'=>$state));
    }
    public static function store($type,$ref,$policyId,$bytes,$consent) {
        global $wpdb; $scope=self::scope($type,$ref,true); if (!$scope) return self::error('Document context unavailable.',404);
        $configuration=self::configuration(); if (is_wp_error($configuration)) return $configuration;
        if ($consent!==true || !is_string($bytes) || strlen($bytes)<8 || strlen($bytes)>5242880 || str_contains(strtolower($bytes),'<?php') || str_contains($bytes,'<?=') || !is_string($policyId)) return self::error('A consented document of at most 5 MiB is required.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime,array('application/pdf','image/png','image/jpeg'),true)) return self::error('Only scanned PDF, PNG and JPEG documents are accepted.');
        if ($mime==='application/pdf' && !str_starts_with($bytes,'%PDF-')) return self::error('Invalid PDF document.');
        if ($mime!=='application/pdf') { $size=@getimagesizefromstring($bytes); if (!$size || $size[0]<1 || $size[1]<1 || $size[0]>10000 || $size[1]>10000 || $size[0]*$size[1]>20000000 || $size['mime']!==$mime) return self::error('Invalid or oversized image.'); }
        $id=wp_generate_uuid4(); $path=self::path($configuration,$id); $created=false;
        $wpdb->query('START TRANSACTION');
        try {
            $lockedOwner=$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID=%d FOR UPDATE",$scope['owner_id']));
            if (!$lockedOwner) throw new DomainException('owner');
            $policy=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('policies').' WHERE id=%s FOR UPDATE',$policyId)); $data=$policy?json_decode($policy->payload,true):null;
            if (!$policy || $policy->state!=='approved' || !$data || $data['expires_at']<=DB::now() || !in_array($type,$data['context_types'],true)) throw new DomainException('policy');
            if (!empty($data['sensitive']) && (!defined('ATLAS_SENSITIVE_DOCUMENTS_ENABLED') || ATLAS_SENSITIVE_DOCUMENTS_ENABLED!==true || !self::sensitive_access($scope['owner_id']))) throw new DomainException('policy');
            // Serialize across every policy for the same owner before enforcing the quota.
            $count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table()." WHERE owner_id=%d AND state<>'purged'",$scope['owner_id'])); if ($count>=100) throw new DomainException('quota');
            $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); $encrypted=$nonce.sodium_crypto_secretbox($bytes,$nonce,$configuration['key']);
            $handle=fopen($path,'xb'); if (!$handle) throw new RuntimeException('storage'); $created=true; chmod($path,0600); $written=fwrite($handle,$encrypted); fclose($handle); if ($written!==strlen($encrypted)) throw new RuntimeException('write');
            $now=DB::now(); $expires=(new DateTimeImmutable($now,new DateTimeZone('UTC')))->modify('+'.$data['retention_days'].' days')->format('Y-m-d H:i:s.u');
            $row=array('id'=>$id,'owner_id'=>$scope['owner_id'],'org_id'=>$scope['org_id'],'uploaded_by'=>get_current_user_id(),'context_type'=>$type,'context_ref'=>$ref,'policy_id'=>$policyId,'mime'=>$mime,'size_bytes'=>strlen($bytes),'digest'=>hash('sha256',$bytes),'state'=>'quarantined','version'=>1,'scan_evidence'=>'{}','legal_hold'=>0,'hold_reason'=>'','erasure_requested'=>0,'expires_at'=>$expires,'created_at'=>$now,'updated_at'=>$now);
            if (!$wpdb->insert(self::table(),$row) || !DB::audit(get_current_user_id(),$scope['org_id'],'private.upload',$id,'success',$policyId)) throw new RuntimeException('record');
            $wpdb->query('COMMIT'); return array('id'=>$id,'state'=>'quarantined','version'=>1,'digest'=>$row['digest'],'expires_at'=>$expires);
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); if ($created) @unlink($path); return self::error($e instanceof DomainException?'An approved current purpose policy and document quota are required.':'Private upload failed.', $e instanceof DomainException?409:503); }
    }
    public static function upload($r) {
        $files=$r->get_file_params(); $f=$files['document']??null;
        if (!is_array($f) || ($f['error']??1)!==UPLOAD_ERR_OK || !is_string($f['tmp_name']??null) || !is_uploaded_file($f['tmp_name']) || filesize($f['tmp_name'])>5242880) return self::error('Select a valid document of at most 5 MiB.');
        $result=self::store($r->get_param('context_type'),$r->get_param('context_ref'),$r->get_param('policy_id'),file_get_contents($f['tmp_name']),$r->get_param('consent')==='1');
        return is_wp_error($result)?$result:Atlas_Partner_Security::response($result,201);
    }
    private static function row($id,$write=false,$lock=false) {
        global $wpdb; if (!is_string($id)) return null;
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%s'.($lock?' FOR UPDATE':''),$id));
        if (!$row || !self::scope($row->context_type,$row->context_ref,$write)) return null;
        $payload=$wpdb->get_var($wpdb->prepare('SELECT payload FROM '.self::table('policies').' WHERE id=%s',$row->policy_id));
        $policy=is_string($payload)?json_decode($payload,true):null;
        if (!is_array($policy) || (!empty($policy['sensitive']) && !self::sensitive_access((int)$row->owner_id))) return null;
        return $row;
    }
    private static function decrypt($row,$configuration) {
        $path=self::path($configuration,$row->id);
        if (!$path || is_link($path) || !is_file($path) || filesize($path)>5242880+64 || (fileperms($path)&0077)!==0) return self::error('Private document unavailable.',503);
        $encoded=file_get_contents($path); if (!is_string($encoded) || strlen($encoded)<SODIUM_CRYPTO_SECRETBOX_NONCEBYTES+SODIUM_CRYPTO_SECRETBOX_MACBYTES) return self::error('Private document damaged.',503);
        $plain=sodium_crypto_secretbox_open(substr($encoded,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($encoded,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$configuration['key']);
        return is_string($plain) && strlen($plain)===(int)$row->size_bytes && hash_equals($row->digest,hash('sha256',$plain))?$plain:self::error('Private document integrity check failed.',503);
    }
    /** Returns bytes to a trusted server caller only after a fresh object authorization. */
    public static function content($id) {
        $row=self::row($id); if (!$row || $row->state!=='clean' || ($row->expires_at<=DB::now() && !self::agency())) return self::error('Document unavailable.',404);
        $configuration=self::configuration(); if (is_wp_error($configuration)) return $configuration;
        $bytes=self::decrypt($row,$configuration); if (is_wp_error($bytes)) return $bytes;
        if (!DB::audit(get_current_user_id(),(int)$row->org_id,'private.download',$row->id,'success')) return self::error('Download could not be audited.',503);
        return array('bytes'=>$bytes,'mime'=>$row->mime,'digest'=>$row->digest,'id'=>$row->id);
    }
    public static function clean_reference($id,$type,$ref) {
        global $wpdb;
        $row=self::row($id);
        if (!$row || $row->state!=='clean' || $row->expires_at<=DB::now() || $row->context_type!==$type || $row->context_ref!==$ref) return false;
        $payload=$wpdb->get_var($wpdb->prepare('SELECT payload FROM '.self::table('policies').' WHERE id=%s AND state=%s',$row->policy_id,'approved'));
        $policy=is_string($payload)?json_decode($payload,true):null;
        if (!is_array($policy) || ($policy['expires_at']??'')<=DB::now()) return false;
        return array('id'=>$row->id,'digest'=>$row->digest,'policy_id'=>$row->policy_id);
    }
    private static function scanner($bytes,$configuration,$row) {
        $extension=apply_filters('atlas_private_file_scan',null,$bytes,array('id'=>$row->id,'digest'=>$row->digest,'mime'=>$row->mime)); if ($extension!==null) return $extension;
        if (!defined('ATLAS_CLAMSCAN_BINARY') || !is_string(ATLAS_CLAMSCAN_BINARY) || !str_starts_with(ATLAS_CLAMSCAN_BINARY,'/') || !is_executable(ATLAS_CLAMSCAN_BINARY) || !function_exists('proc_open')) return self::error('Antivirus scanner unavailable; document remains quarantined.',503);
        $temp=tempnam($configuration['root'],'scan-'); if (!$temp) return self::error('Scan workspace unavailable.',503); chmod($temp,0600);
        try {
            if (file_put_contents($temp,$bytes)!==strlen($bytes)) return self::error('Scan workspace unavailable.',503);
            $pipes=array(); $process=proc_open(array(ATLAS_CLAMSCAN_BINARY,'--no-summary','--stdout','--',$temp),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
            if (!is_resource($process)) return self::error('Scanner could not start.',503);
            fclose($pipes[0]); stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false); $deadline=microtime(true)+20; $code=-1;
            do { $status=proc_get_status($process); fread($pipes[1],8192); fread($pipes[2],8192); if (!$status['running']) { $code=$status['exitcode']; break; } if (microtime(true)>$deadline) { proc_terminate($process,9); break; } usleep(50000); } while (true);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
            return array('state'=>$code===0?'clean':($code===1?'infected':'unavailable'),'engine'=>'ClamAV','reference'=>'clamav:'.$row->digest,'scanned_digest'=>$row->digest);
        } finally { @unlink($temp); }
    }
    public static function command($r,$action) {
        global $wpdb; if ($action!=='request_erasure' && !self::agency()) return self::error('Restricted agency review required.',403);
        $b=$r->get_json_params(); if (!is_array($b) || array_diff(array_keys($b),array('version','reason','active')) || !is_int($b['version']??null) || !is_string($b['reason']??null) || trim($b['reason'])==='' || strlen($b['reason'])>190) return self::error('Current version and bounded review reason required.');
        $configuration=self::configuration(); if (is_wp_error($configuration)) return $configuration;
        $row=self::row((string)$r['id'],$action!=='request_erasure'); if (!$row) return self::error('Document unavailable.',404);
        $scan=null;
        if ($action==='scan') {
            if (!in_array($row->state,array('quarantined','scan_failed'),true)) return self::error('Only quarantined documents can be scanned.',409);
            $plain=self::decrypt($row,$configuration); if (is_wp_error($plain)) return $plain;
            $scan=self::scanner($plain,$configuration,$row); unset($plain);
            if (is_wp_error($scan)) $scan=array('state'=>'unavailable','engine'=>'unavailable','reference'=>'scanner:unavailable','scanned_digest'=>$row->digest);
            if (!is_array($scan) || !in_array($scan['state']??'',array('clean','infected','unavailable'),true) || !is_string($scan['engine']??null) || strlen($scan['engine'])>80 || !is_string($scan['reference']??null) || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$scan['reference']) || !hash_equals($row->digest,(string)($scan['scanned_digest']??''))) return self::error('Scanner evidence does not bind this document.',503);
        }
        $wpdb->query('START TRANSACTION');
        try {
            $row=self::row((string)$r['id'],$action!=='request_erasure',true); if (!$row || (int)$row->version!==$b['version'] || $row->state==='purged') throw new DomainException('version');
            $changes=array('version'=>$b['version']+1,'updated_at'=>DB::now());
            if ($action==='scan') { if (!in_array($row->state,array('quarantined','scan_failed'),true)) throw new DomainException('state'); $changes['state']=$scan['state']==='unavailable'?'scan_failed':$scan['state']; $changes['scan_evidence']=wp_json_encode($scan+array('scanned_at'=>DB::now())); }
            elseif ($action==='legal_hold') { if (!is_bool($b['active']??null)) throw new DomainException('hold'); $changes['legal_hold']=$b['active']?1:0; $changes['hold_reason']=sanitize_text_field($b['reason']); }
            elseif ($action==='request_erasure') $changes['erasure_requested']=1;
            elseif ($action==='purge') { if ((int)$row->legal_hold || $row->expires_at>DB::now()) throw new DomainException('retention'); $changes['state']='purged'; }
            else throw new DomainException('action');
            if ($wpdb->update(self::table(),$changes,array('id'=>$row->id,'version'=>$b['version']))!==1 || !DB::audit(get_current_user_id(),(int)$row->org_id,'private.'.$action,$row->id,'success',$b['reason'])) throw new RuntimeException('record');
            // Metadata tombstone commits first: a crash can leave only an inaccessible
            // encrypted orphan, never downloadable bytes whose erasure is claimed.
            $wpdb->query('COMMIT');
            if ($action==='purge') { $path=self::path($configuration,$row->id); if (is_link($path) || (file_exists($path) && !unlink($path))) return self::error('Document is inaccessible; physical deletion requires operator recovery.',503); }
            return Atlas_Partner_Security::response(array('id'=>$row->id,'state'=>$changes['state']??$row->state,'version'=>$changes['version'],'erasure_requested'=>$changes['erasure_requested']??(int)$row->erasure_requested));
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); return self::error($e instanceof DomainException?'Document changed or retention/legal hold prevents this operation.':'Document operation failed.',$e instanceof DomainException?409:503); }
    }
    public static function listing($r) {
        global $wpdb; $type=$r->get_param('context_type'); $ref=$r->get_param('context_ref'); if (!self::scope($type,$ref)) return self::error('Document context unavailable.',404);
        $files=self::table(); $policies=self::table('policies');
        $restricted=self::agency() && current_user_can('atlas_manage_sensitive_documents')?1:0;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT f.id,f.policy_id,f.mime,f.size_bytes,f.digest,f.state,f.version,f.expires_at,f.legal_hold,f.erasure_requested,f.created_at FROM $files f INNER JOIN $policies p ON p.id=f.policy_id WHERE f.context_type=%s AND f.context_ref=%s AND (f.owner_id=%d OR %d=1 OR JSON_UNQUOTE(JSON_EXTRACT(p.payload,'$.sensitive'))='false') ORDER BY f.created_at DESC,f.id DESC LIMIT 101",$type,$ref,get_current_user_id(),$restricted),ARRAY_A);
        return Atlas_Partner_Security::response(array('items'=>array_slice($rows,0,100),'has_more'=>count($rows)>100));
    }
    public static function download() {
        if (!is_user_logged_in()) wp_die('Sign in required.','',array('response'=>403)); $id=Atlas_Partner_Security::post('file_id'); check_admin_referer('atlas_private_download_'.$id);
        $data=self::content($id); if (is_wp_error($data)) wp_die(esc_html($data->get_error_message()),'',array('response'=>404));
        nocache_headers(); header('Content-Type: application/octet-stream'); header('X-Content-Type-Options: nosniff'); header('Content-Security-Policy: sandbox'); header('Content-Disposition: attachment; filename="document-'.$data['id'].($data['mime']==='application/pdf'?'.pdf':($data['mime']==='image/png'?'.png':'.jpg')).'"'); header('Content-Length: '.strlen($data['bytes'])); echo $data['bytes']; exit;
    }
    public static function form() {
        if (!is_user_logged_in()) wp_die('Sign in required.','',array('response'=>403)); check_admin_referer('atlas_private_document'); $op=Atlas_Partner_Security::post('op'); $r=new WP_REST_Request('POST','/');
        $r->set_header('Content-Type','application/json'); $r->set_url_params(array('id'=>Atlas_Partner_Security::post('file_id')));
        if ($op==='upload') { $r->set_file_params($_FILES); foreach (array('context_type','context_ref','policy_id','consent') as $v) $r->set_param($v,Atlas_Partner_Security::post($v)); $result=self::upload($r); }
        elseif ($op==='policy') { $body=json_decode(Atlas_Partner_Security::post('policy_json'),true); $r->set_body(wp_json_encode($body)); $result=self::policy($r); }
        else { $body=array('version'=>absint(Atlas_Partner_Security::post('version')),'reason'=>Atlas_Partner_Security::post('reason')); if ($op==='legal_hold') $body['active']=Atlas_Partner_Security::post('active')==='1'; $r->set_body(wp_json_encode($body)); $result=in_array($op,array('approve','revoke'),true)?self::policy_action($r,$op):self::command($r,$op); }
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()),'',array('response'=>422)); wp_safe_redirect(wp_get_referer()?:home_url('/')); exit;
    }
    public static function panel() {
        if (!is_user_logged_in()) return ''; global $wpdb;
        $type=isset($_GET['document_context_type']) && is_string($_GET['document_context_type'])?sanitize_key(wp_unslash($_GET['document_context_type'])):''; $ref=isset($_GET['document_context_ref']) && is_string($_GET['document_context_ref'])?sanitize_text_field(wp_unslash($_GET['document_context_ref'])):'';
        $html='<details><summary>'.esc_html(self::t('Private documents','اسناد خصوصی')).'</summary><p>'.esc_html(self::t('Documents require an approved purpose policy. Uploads stay quarantined until an actual antivirus scan succeeds.','اسناد به سیاست هدف تأییدشده نیاز دارند. بارگذاری تا موفقیت اسکن واقعی ضدبدافزار در قرنطینه می‌ماند.')).'</p>';
        $html.='<form method="get"><label>'.esc_html(self::t('Context type','نوع ارتباط')).'<input name="document_context_type" value="'.esc_attr($type).'" required></label><label>'.esc_html(self::t('Context reference','مرجع ارتباط')).'<input name="document_context_ref" value="'.esc_attr($ref).'" required></label><input type="hidden" name="lang" value="'.esc_attr(($_GET['lang']??'')==='fa'?'fa':'en').'"><button>'.esc_html(self::t('Open documents','نمایش اسناد')).'</button></form>';
        if (self::scope($type,$ref)) {
            $policies=$wpdb->get_results('SELECT * FROM '.self::table('policies')." WHERE state='approved' ORDER BY created_at DESC LIMIT 100");
            $html.=str_replace('<form ','<form enctype="multipart/form-data" ',Atlas_Partner_Security::form_start('atlas_private_document')).'<input type="hidden" name="op" value="upload"><input type="hidden" name="context_type" value="'.esc_attr($type).'"><input type="hidden" name="context_ref" value="'.esc_attr($ref).'"><label>'.esc_html(self::t('Approved purpose and retention notice','هدف و اطلاعیه نگهداری تأییدشده')).'<select name="policy_id" required>';
            foreach ($policies as $policy) { $data=json_decode($policy->payload,true); if ($data && $data['expires_at']>DB::now() && in_array($type,$data['context_types'],true)) $html.='<option value="'.esc_attr($policy->id).'">'.esc_html($data[($_GET['lang']??'')==='fa'?'notice_fa':'notice_en']).'</option>'; }
            $html.='</select></label><label>'.esc_html(self::t('PDF, PNG or JPEG, maximum 5 MiB','PDF یا PNG یا JPEG، حداکثر ۵ مگابایت')).'<input name="document" type="file" accept="application/pdf,image/png,image/jpeg" required></label><label><input type="checkbox" name="consent" value="1" required> '.esc_html(self::t('I reviewed this purpose and retention notice and authorize this upload.','هدف و اطلاعیه نگهداری را خواندم و بارگذاری را مجاز می‌دانم.')).'</label><button>'.esc_html(self::t('Upload to quarantine','بارگذاری در قرنطینه')).'</button></form>';
            $r=new WP_REST_Request('GET','/'); $r->set_param('context_type',$type); $r->set_param('context_ref',$ref); $data=self::listing($r)->get_data();
            foreach ($data['items'] as $row) {
                $html.='<article><p><bdi>'.esc_html($row['id']).'</bdi> — '.esc_html($row['state']).' — '.esc_html(self::t('Retention ends','پایان نگهداری')).' <bdi>'.esc_html($row['expires_at']).'</bdi></p>';
                if ($row['state']==='clean') $html.=Atlas_Partner_Security::form_start('atlas_private_download').wp_nonce_field('atlas_private_download_'.$row['id'],'_wpnonce',true,false).'<input type="hidden" name="file_id" value="'.esc_attr($row['id']).'"><button>'.esc_html(self::t('Download private document','دریافت سند خصوصی')).'</button></form>';
                foreach (self::agency()?array('scan','legal_hold','request_erasure','purge'):array('request_erasure') as $op) { $html.=Atlas_Partner_Security::form_start('atlas_private_document').'<input type="hidden" name="op" value="'.esc_attr($op).'"><input type="hidden" name="file_id" value="'.esc_attr($row['id']).'"><input type="hidden" name="version" value="'.esc_attr($row['version']).'"><p>'.esc_html($op).'</p>'.Atlas_Partner_Security::input('reason',self::t('Review reason','دلیل بررسی')); if ($op==='legal_hold') $html.='<label><input type="checkbox" name="active" value="1">'.esc_html(self::t('Retain under legal hold','نگهداری با منع قانونی حذف')).'</label>'; $html.='<button>'.esc_html(self::t('Record action','ثبت اقدام')).'</button></form>'; }
                $html.='</article>';
            }
        }
        if (self::agency()) {
            $html.=Atlas_Partner_Security::form_start('atlas_private_document').'<input type="hidden" name="op" value="policy"><label>'.esc_html(self::t('Purpose policy JSON with both notices and review references','سیاست هدف با دو اطلاعیه و مراجع بررسی')).'<textarea name="policy_json" required></textarea></label><button>'.esc_html(self::t('Create policy draft','ایجاد پیش‌نویس سیاست')).'</button></form>';
            foreach ($wpdb->get_results('SELECT id,purpose,state,created_by FROM '.self::table('policies').' ORDER BY created_at DESC LIMIT 50') as $policy) { $html.='<p>'.esc_html($policy->purpose.' / '.$policy->state).' <bdi>'.esc_html($policy->id).'</bdi></p>'; foreach ($policy->state==='draft'?array('approve','revoke'):array('revoke') as $op) $html.=Atlas_Partner_Security::form_start('atlas_private_document').'<input type="hidden" name="op" value="'.esc_attr($op).'"><input type="hidden" name="file_id" value="'.esc_attr($policy->id).'">'.Atlas_Partner_Security::input('reason',self::t('Independent review reason','دلیل بررسی مستقل')).'<button>'.esc_html(self::t('Record policy decision','ثبت تصمیم سیاست')).' — '.esc_html($op).'</button></form>'; }
        }
        return $html.'</details>';
    }
}
add_action('atlas_operations_ready',array('Atlas_Private_Files','register'));
