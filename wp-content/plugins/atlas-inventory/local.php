<?php
namespace Atlas\Inventory;
if (!defined('ABSPATH')) { exit; }
use Atlas\Core\Auth;
use Atlas\Core\DB;
use Atlas\Core\Records;
use WP_Error;

/** Internal exception carries the SQL errno captured before rollback resets it. */
final class InventorySqlFailure extends \RuntimeException {}

/** Internal-only local capacity. Parent-row locks serialize each resource. */
final class Local {
    private const VERSION='3';
    private static function table(string $name): string {
        global $wpdb;
        if (!in_array($name,['resources','nights','allocations'],true)) { throw new \InvalidArgumentException('Unknown table'); }
        return $wpdb->prefix.'atlas_local_'.$name;
    }
    private static function failure(string $code,string $message,int $status=409): WP_Error {
        $fa=['atlas_disabled'=>'موجودی عملیاتی در این محیط فعال نیست.','atlas_invalid'=>'اطلاعات واردشده معتبر نیست.','atlas_forbidden'=>'دسترسی مجاز نیست.','atlas_not_found'=>'مورد موردنظر پیدا نشد.','atlas_invalid_state'=>'وضعیت فعلی این عملیات را مجاز نمی‌کند.','atlas_unavailable'=>'ظرفیت یا بازه درخواستی در دسترس نیست.','atlas_conflict'=>'این تغییر با تخصیص موجود تداخل دارد.','atlas_min_stay'=>'حداقل مدت اقامت رعایت نشده است.','atlas_property_conflict'=>'این ملک با واحد ثبت‌شده تداخل دارد.','atlas_storage'=>'ثبت عملیات انجام نشد. دوباره تلاش کنید.','atlas_dependency'=>'مدیریت موجودی در حال حاضر در دسترس نیست.','atlas_resource_changed'=>'اطلاعات منبع تغییر کرده است؛ بررسی مجدد لازم است.','atlas_reference_conflict'=>'این شماره پیگیری برای اطلاعات دیگری استفاده شده است.','atlas_vehicle_in_use'=>'وسیله هنوز بازگردانده نشده یا اختلاف باز دارد.','atlas_capacity_conflict'=>'ظرفیت کمتر از تخصیص موجود است.','atlas_pickup_review'=>'زمان تحویل خودرو مستند نیست؛ بررسی مسئول مجاز لازم است.'];
        $fa+=['atlas_policy_required'=>'خط‌مشی بررسی‌شده و معتبر اجاره لازم است.','atlas_eligibility_required'=>'بررسی مسئول مجاز درباره سن، پوشش و گواهینامه لازم است.','atlas_license_expired'=>'گواهینامه برای زمان تحویل معتبر نیست.','atlas_age_policy'=>'سن بررسی‌شده با خط‌مشی انتخابی سازگار نیست.'];
        $requested=isset($_GET['lang']) && is_string($_GET['lang']) ? sanitize_key(wp_unslash($_GET['lang'])) : '';
        $isFa=$requested==='fa' || ($requested!=='en' && str_starts_with(determine_locale(),'fa'));
        return new WP_Error($code,$isFa && isset($fa[$code])?$fa[$code]:$message,['status'=>$status]);
    }
    public static function migrate(): true|WP_Error {
        global $wpdb;
        if (get_option('atlas_local_inventory_schema')===self::VERSION) { return true; }
        $lock='atlas_local_schema_'.md5($wpdb->prefix);
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock))!=='1') { return self::failure('atlas_schema_locked','Inventory migration is already running.',503); }
        try {
            require_once ABSPATH.'wp-admin/includes/upgrade.php'; $collate=$wpdb->get_charset_collate();
            $schemas=[
              'resources'=>"id char(36) NOT NULL, org_id bigint(20) unsigned NOT NULL, record_type varchar(24) NOT NULL, record_id char(36) NOT NULL, property_id char(36) NOT NULL DEFAULT '', kind varchar(16) NOT NULL, timezone varchar(64) NOT NULL DEFAULT '', vehicle_kind varchar(16) NOT NULL DEFAULT '', license_class varchar(100) NOT NULL DEFAULT '', rental_policy text DEFAULT NULL, policy_reviewed_by bigint unsigned NOT NULL DEFAULT 0, policy_reviewed_at datetime(6) DEFAULT NULL, capacity int unsigned NOT NULL DEFAULT 1, min_nights int unsigned NOT NULL DEFAULT 1, turnaround_minutes int unsigned NOT NULL DEFAULT 0, whole_property tinyint NOT NULL DEFAULT 0, created_at datetime(6) NOT NULL, PRIMARY KEY  (id), UNIQUE KEY record_unique (record_type,record_id), KEY property_id (property_id)",
              'nights'=>"resource_id char(36) NOT NULL, local_date date NOT NULL, allocated int unsigned NOT NULL DEFAULT 0, blocked tinyint NOT NULL DEFAULT 0, PRIMARY KEY  (resource_id,local_date)",
              'allocations'=>"id char(36) NOT NULL, resource_id char(36) NOT NULL, kind varchar(20) NOT NULL, state varchar(24) NOT NULL, start_at varchar(32) NOT NULL, end_at varchar(32) NOT NULL, effective_end varchar(32) NOT NULL, picked_up_at datetime(6) DEFAULT NULL, eligibility_review text DEFAULT NULL, quantity int unsigned NOT NULL DEFAULT 1, reference varchar(120) NOT NULL, linked_type varchar(32) NOT NULL DEFAULT '', linked_id char(36) NOT NULL DEFAULT '', request_hash char(64) NOT NULL, note text NOT NULL, disputed tinyint NOT NULL DEFAULT 0, actor_id bigint(20) unsigned NOT NULL, created_at datetime(6) NOT NULL, updated_at datetime(6) NOT NULL, PRIMARY KEY  (id), UNIQUE KEY resource_reference (resource_id,reference), KEY resource_state (resource_id,state), KEY resource_interval (resource_id,start_at,effective_end)"
            ];
            foreach ($schemas as $name=>$schema) {
                $table=self::table($name); dbDelta("CREATE TABLE $table (\n".preg_replace('/, (?![^()]*\))/',",\n",$schema)."\n) ENGINE=InnoDB $collate;");
                $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
                $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
                if ($exists!==$table || strtoupper((string)$engine)!=='INNODB') { return self::failure('atlas_schema_failed','Local inventory requires transactional tables.',503); }
                if (in_array($name,['allocations','resources'],true)) {
                    $columns=$wpdb->get_results('SHOW COLUMNS FROM '.$table,ARRAY_A);
                    $required=$name==='allocations'?['picked_up_at','eligibility_review']:['vehicle_kind','license_class','rental_policy','policy_reviewed_by','policy_reviewed_at'];
                    if (!is_array($columns) || array_diff($required,array_column($columns,'Field'))) return self::failure('atlas_schema_failed','Rental evidence migration is incomplete.',503);
                }
            }
            update_option('atlas_local_inventory_schema',self::VERSION,false); return true;
        } catch (\Throwable $e) { return self::failure('atlas_schema_failed','Local inventory migration failed.',503); }
        finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    public static function available(): bool { return wp_get_environment_type()==='local' && defined('ATLAS_DEMO_MODE') && ATLAS_DEMO_MODE===true; }
    private static function ready(): true|WP_Error {
        if (!self::available()) return self::failure('atlas_disabled','Local inventory is disabled in this environment.',403);
        if (!class_exists(DB::class) || !class_exists(Records::class) || get_option('atlas_local_inventory_schema')!==self::VERSION) { return self::failure('atlas_dependency','Local inventory is unavailable until core and schema are ready.',503); }
        return DB::ready();
    }
    private static function date(string $value): \DateTimeImmutable|false {
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('UTC'));
        return $d && $d->format('Y-m-d')===$value ? $d : false;
    }
    private static function instant(string $value): \DateTimeImmutable|false {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',$value)) { return false; }
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new \DateTimeZone('UTC'));
        return $d && $d->format('Y-m-d\TH:i:s\Z')===$value ? $d : false;
    }
    private static function authorized(object $r): true|WP_Error {
        if (!Auth::can('atlas_manage_records',(int)$r->org_id ?: null)) { return self::failure('atlas_forbidden','Access denied.',403); }
        $record=Records::get($r->record_type,$r->record_id,get_current_user_id());
        if (is_wp_error($record) || (int)$record['org_id']!==(int)$r->org_id) { return self::failure('atlas_forbidden','Access denied.',403); }
        if ($r->record_type==='unit_type') {
            if ((string)($record['fields']['property_id']??'')!==$r->property_id || (int)!empty($record['fields']['whole_property'])!==(int)$r->whole_property) return self::failure('atlas_resource_changed','Property or whole-unit classification changed; reconcile local inventory.',409);
            $parent=Records::get('property',$r->property_id,get_current_user_id());
            if (is_wp_error($parent) || (int)$parent['org_id']!==(int)$r->org_id || ($parent['fields']['timezone']??'')!==$r->timezone) return self::failure('atlas_resource_changed','Property or time zone changed; reconcile local inventory.',409);
        }
        if ($r->record_type==='vehicle' && $r->vehicle_kind!=='') {
            if (($record['fields']['category']??'')!==$r->vehicle_kind || trim((string)($record['fields']['license_class']??''))!==$r->license_class) return self::failure('atlas_resource_changed','Vehicle category or license class changed; reconcile its local resource.',409);
        }
        return true;
    }
    private static function resource(string $id,bool $lock=false): object {
        global $wpdb; $ready=self::ready(); if (is_wp_error($ready)) { return $ready; }
        $r=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('resources').' WHERE id=%s'.($lock?' FOR UPDATE':''),$id));
        if (!$r) { return self::failure('atlas_not_found','Resource not found.',404); }
        $allowed=self::authorized($r); return is_wp_error($allowed)?$allowed:$r;
    }
    private static function sql_failure(string $message): InventorySqlFailure {
        global $wpdb;
        $connection=$wpdb->dbh;
        try { $errno=$connection instanceof \mysqli?mysqli_errno($connection):0; }
        catch (\Throwable $ignored) { $errno=0; }
        return new InventorySqlFailure($message,$errno);
    }
    private static function query_ok($result): void { if ($result===false) { throw self::sql_failure('Local inventory storage failed'); } }
    /** Retry only documented contention failures, in a completely fresh transaction. */
    private static function rollback_and_retry(\Throwable $error,int $attempt): bool {
        global $wpdb;
        $errno=($error instanceof InventorySqlFailure || $error instanceof \mysqli_sql_exception)?(int)$error->getCode():0;
        $wpdb->query('ROLLBACK');
        if ($attempt<2 && in_array($errno,[1020,1213,1205],true)) {
            usleep(10000*($attempt+1)); // 10ms then 20ms; at most three total attempts.
            return true;
        }
        return false;
    }
    private static function audit(int $org,string $action,string $id,string $reason=''): void {
        if (!DB::audit(get_current_user_id(),$org,$action,$id,'success',$reason)) { throw self::sql_failure('Audit failed'); }
    }

    /** Resource points at an authorized core domain record; no client org_id is trusted. */
    public static function register_resource(string $recordType,string $recordId,int $minStay=1,int $turnaroundMinutes=0): array|WP_Error {
        global $wpdb; $ready=self::ready(); if (is_wp_error($ready)) return $ready;
        if (!in_array($recordType,['unit_type','vehicle'],true) || !preg_match('/^[a-f0-9-]{36}$/i',$recordId) || $minStay<1 || $minStay>365 || $turnaroundMinutes<0 || $turnaroundMinutes>10080) return self::failure('atlas_invalid','Invalid resource configuration.',422);
        $record=Records::get($recordType,$recordId,get_current_user_id()); if (is_wp_error($record)) return $record;
        $org=(int)$record['org_id']; if (!Auth::can('atlas_manage_records',$org?:null)) return self::failure('atlas_forbidden','Access denied.',403);
        $fields=$record['fields']; $kind=$recordType==='vehicle'?'vehicle':'stay'; $property=''; $zone=''; $whole=0; $capacity=1; $vehicleKind=''; $licenseClass='';
        if ($kind==='stay') {
            $property=(string)($fields['property_id']??''); $parent=Records::get('property',$property,get_current_user_id());
            if (is_wp_error($parent) || (int)$parent['org_id']!==$org || !$property) return self::failure('atlas_forbidden','Property does not belong to the same organization.',403);
            $zone=(string)($parent['fields']['timezone']??''); if (!in_array($zone,timezone_identifiers_list(),true)) return self::failure('atlas_invalid','Property requires a named IANA time zone.',422);
            $whole=!empty($fields['whole_property'])?1:0; $capacity=(int)($fields['units']??0);
            if ($capacity<1 || $capacity>10000 || ($whole && $capacity!==1)) return self::failure('atlas_invalid','Invalid unit count or whole-villa capacity.',422);
        }
        if ($kind==='vehicle') {
            $vehicleKind=(string)($fields['category']??''); $licenseClass=trim((string)($fields['license_class']??''));
            if (!in_array($vehicleKind,['bicycle','motorcycle','car','van'],true) || strlen($licenseClass)>100 || ($vehicleKind!=='bicycle' && $licenseClass==='')) return self::failure('atlas_invalid','A known vehicle category and required license class must be configured.',422);
        }
        if ($wpdb->query('START TRANSACTION')===false) return self::failure('atlas_storage','Could not start an inventory transaction.',503);
        try {
            if ($property) {
                // The parent core record is the shared mutex when multiple unit types target one property.
                $parent_lock=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.DB::table('records').' WHERE id=%s AND type=%s FOR UPDATE',$property,'property'));
                if (!$parent_lock) throw new \RuntimeException('Missing property');
                $existing=$wpdb->get_row($wpdb->prepare('SELECT id,whole_property FROM '.self::table('resources').' WHERE property_id=%s AND (whole_property=1 OR %d=1) LIMIT 1 FOR UPDATE',$property,$whole));
                if ($wpdb->last_error!=='') throw new \RuntimeException('Property resource read failed');
                if ($existing) { $wpdb->query('ROLLBACK'); return self::failure('atlas_property_conflict','A whole-property resource cannot overlap room categories.'); }
            }
            $id=wp_generate_uuid4();
            $ok=$wpdb->insert(self::table('resources'),['id'=>$id,'org_id'=>$org,'record_type'=>$recordType,'record_id'=>$recordId,'property_id'=>$property,'kind'=>$kind,'timezone'=>$zone,'vehicle_kind'=>$vehicleKind,'license_class'=>$licenseClass,'capacity'=>$capacity,'min_nights'=>$minStay,'turnaround_minutes'=>$turnaroundMinutes,'whole_property'=>$whole,'created_at'=>DB::now()]);
            if (!$ok) { $wpdb->query('ROLLBACK'); return self::failure('atlas_duplicate','This record already has a local resource.'); }
            self::audit($org,'local.resource.create',$id); self::query_ok($wpdb->query('COMMIT'));
            return ['id'=>$id,'kind'=>$kind,'capacity'=>$capacity,'mode'=>'internal_only'];
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); return self::failure('atlas_storage','Resource could not be saved.',503); }
    }
    /** Update operational capacity only when no allocated night would be oversold. */
    public static function capacity(string $id,int $capacity,int $minStay): true|WP_Error {
        global $wpdb; if ($capacity<1 || $capacity>10000 || $minStay<1 || $minStay>365) return self::failure('atlas_invalid','Invalid capacity or minimum stay.',422);
        if ($wpdb->query('START TRANSACTION')===false) return self::failure('atlas_storage','Could not start an inventory transaction.',503);
        try {
            $r=self::resource($id,true); if (is_wp_error($r)) { $wpdb->query('ROLLBACK'); return $r; }
            $source=Records::get($r->record_type,$r->record_id,get_current_user_id());
            if (is_wp_error($source) || $capacity>(int)($source['fields']['units']??0)) { $wpdb->query('ROLLBACK'); return self::failure('atlas_invalid','Capacity exceeds the registered unit count.',422); }
            if ($r->kind!=='stay' || ((int)$r->whole_property===1 && $capacity!==1)) { $wpdb->query('ROLLBACK'); return self::failure('atlas_invalid','Whole villas have exactly one unit.',422); }
            $allocations=$wpdb->get_col($wpdb->prepare('SELECT allocated FROM '.self::table('nights').' WHERE resource_id=%s FOR UPDATE',$id));
            if ($wpdb->last_error!=='') throw new \RuntimeException('Night read failed');
            $max=$allocations?max(array_map('intval',$allocations)):0;
            if ($max>$capacity) { $wpdb->query('ROLLBACK'); return self::failure('atlas_capacity_conflict','Capacity is below an existing allocation.'); }
            self::query_ok($wpdb->update(self::table('resources'),['capacity'=>$capacity,'min_nights'=>$minStay],['id'=>$id])); self::audit((int)$r->org_id,'local.capacity.update',$id);
            self::query_ok($wpdb->query('COMMIT')); return true;
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); return self::failure('atlas_storage','Could not update capacity.',503); }
    }
    private static function duplicate(string $resource,string $ref,string $hash): array|WP_Error|null {
        global $wpdb; $row=$wpdb->get_row($wpdb->prepare('SELECT id,request_hash,state FROM '.self::table('allocations').' WHERE resource_id=%s AND reference=%s FOR UPDATE',$resource,$ref));
        if ($wpdb->last_error!=='') throw self::sql_failure('Reference read failed');
        if (!$row) return null;
        if (!hash_equals($row->request_hash,$hash)) return self::failure('atlas_reference_conflict','Reference was already used for different details.');
        return ['id'=>$row->id,'state'=>$row->state,'mode'=>'internal_only','replayed'=>true];
    }
    private static function linked(object $r,string $type,string $id): true|WP_Error {
        if ($id==='') return true;
        if (!preg_match('/^[a-f0-9-]{36}$/i',$id) || !in_array($type,['stay_request','rental_request','transfer_request'],true)) return self::failure('atlas_invalid','Invalid linked request.',422);
        $request=Records::get($type,$id,get_current_user_id());
        if (is_wp_error($request) || ((int)$request['org_id']!==0 && (int)$request['org_id']!==(int)$r->org_id)) return self::failure('atlas_forbidden','Linked request is not accessible.',403);
        if ($type==='stay_request' && ($request['fields']['property_id']??'')!==$r->property_id) return self::failure('atlas_invalid','Request belongs to another property.',422);
        return true;
    }
    private static function reference(string $ref): bool { return (bool)preg_match('/^[A-Za-z0-9._:-]{8,120}$/',$ref); }
    /** Current read after the resource mutex; do not reuse an older RR snapshot. */
    private static function locked_night(string $id,string $night): object {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT allocated,blocked FROM '.self::table('nights').' WHERE resource_id=%s AND local_date=%s FOR UPDATE',$id,$night));
        if ($wpdb->last_error!=='') throw self::sql_failure('Night read failed');
        if ($row) return $row;
        // All service writers hold the same resource row. A guarded insert is
        // safe here and avoids INSERT IGNORE's stale-snapshot conflict on MariaDB.
        self::query_ok($wpdb->insert(self::table('nights'),['resource_id'=>$id,'local_date'=>$night,'allocated'=>0,'blocked'=>0]));
        return (object)['allocated'=>0,'blocked'=>0];
    }
    public static function allocate_stay(string $id,string $checkIn,string $checkOut,int $qty,string $reference,string $requestId=''): array|WP_Error {
        global $wpdb; $start=self::date($checkIn); $end=self::date($checkOut);
        if (!$start || !$end || $end<=$start || $qty<1 || $qty>10000 || !self::reference($reference)) return self::failure('atlas_invalid','Invalid stay dates, quantity or reference.',422);
        $nights=(int)$start->diff($end)->days; if ($nights>365) return self::failure('atlas_invalid','Stay is too long.',422);
        $hash=hash('sha256',wp_json_encode(['stay',$checkIn,$checkOut,$qty,$requestId]));
        for ($attempt=0;$attempt<3;$attempt++) {
        if ($wpdb->query('START TRANSACTION')===false) {
            $error=self::sql_failure('Could not start an inventory transaction');
            if (self::rollback_and_retry($error,$attempt)) continue;
            return self::failure('atlas_storage','Could not start an inventory transaction.',503);
        }
        try {
            $r=self::resource($id,true); if ($wpdb->last_error!=='') throw self::sql_failure('Resource read failed');
            if (is_wp_error($r)) { $wpdb->query('ROLLBACK'); return $r; }
            $linked=self::linked($r,'stay_request',$requestId); if (is_wp_error($linked)) { $wpdb->query('ROLLBACK'); return $linked; }
            $source=Records::get($r->record_type,$r->record_id,get_current_user_id());
            if (is_wp_error($source) || (int)($source['fields']['units']??0)<(int)$r->capacity) { $wpdb->query('ROLLBACK'); return self::failure('atlas_resource_changed','Unit count changed; reconcile local inventory.'); }
            if ($r->kind!=='stay' || $nights<(int)$r->min_nights) { $wpdb->query('ROLLBACK'); return self::failure('atlas_min_stay','Minimum stay is not satisfied.',422); }
            $prior=self::duplicate($id,$reference,$hash); if ($prior!==null) { $wpdb->query('ROLLBACK'); return $prior; }
            for ($d=$start;$d<$end;$d=$d->modify('+1 day')) {
                $night=$d->format('Y-m-d');
                $row=self::locked_night($id,$night);
                if (!$row || (int)$row->blocked || (int)$row->allocated+$qty>(int)$r->capacity) { $wpdb->query('ROLLBACK'); return self::failure('atlas_unavailable','One or more nights cannot be allocated.'); }
                self::query_ok($wpdb->query($wpdb->prepare('UPDATE '.self::table('nights').' SET allocated=allocated+%d WHERE resource_id=%s AND local_date=%s',$qty,$id,$night)));
            }
            $allocation=wp_generate_uuid4(); $now=DB::now();
            self::query_ok($wpdb->insert(self::table('allocations'),['id'=>$allocation,'resource_id'=>$id,'kind'=>'stay','state'=>'allocated','start_at'=>$checkIn,'end_at'=>$checkOut,'effective_end'=>$checkOut,'quantity'=>$qty,'reference'=>$reference,'linked_type'=>$requestId!==''?'stay_request':'','linked_id'=>$requestId,'request_hash'=>$hash,'note'=>'','disputed'=>0,'actor_id'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]));
            self::audit((int)$r->org_id,'local.stay.allocate',$allocation); self::query_ok($wpdb->query('COMMIT'));
            return ['id'=>$allocation,'state'=>'allocated','mode'=>'internal_only','replayed'=>false];
        } catch (\Throwable $e) {
            if (self::rollback_and_retry($e,$attempt)) continue;
            return self::failure('atlas_storage','Stay allocation failed.',503);
        }
        }
        return self::failure('atlas_storage','Stay allocation could not complete after bounded retries.',503);
    }
    public static function blackout(string $id,string $startDate,string $endDate,bool $blocked): true|WP_Error {
        global $wpdb; $start=self::date($startDate); $end=self::date($endDate);
        if (!$start || !$end || $end<=$start || $start->diff($end)->days>365) return self::failure('atlas_invalid','Invalid blackout interval.',422);
        if ($wpdb->query('START TRANSACTION')===false) return self::failure('atlas_storage','Could not start an inventory transaction.',503);
        try {
            $r=self::resource($id,true); if (is_wp_error($r)) { $wpdb->query('ROLLBACK'); return $r; }
            if ($r->kind!=='stay') { $wpdb->query('ROLLBACK'); return self::failure('atlas_invalid','Not a stay resource.',422); }
            for ($d=$start;$d<$end;$d=$d->modify('+1 day')) {
                $night=$d->format('Y-m-d'); $row=self::locked_night($id,$night);
                if (!$row || ($blocked && (int)$row->allocated>0)) { $wpdb->query('ROLLBACK'); return self::failure('atlas_conflict','A committed allocation prevents this block.'); }
                self::query_ok($wpdb->update(self::table('nights'),['blocked'=>$blocked?1:0],['resource_id'=>$id,'local_date'=>$night]));
            }
            self::audit((int)$r->org_id,$blocked?'local.blackout.create':'local.blackout.remove',$id); self::query_ok($wpdb->query('COMMIT')); return true;
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); return self::failure('atlas_storage','Blackout update failed.',503); }
    }

    public static function allocate_vehicle(string $id,string $startUtc,string $endUtc,string $kind,string $reference,string $requestId=''): array|WP_Error {
        global $wpdb; $start=self::instant($startUtc); $end=self::instant($endUtc);
        if (!$start || !$end || $end<=$start || $end->getTimestamp()-$start->getTimestamp()>366*86400 || !in_array($kind,['rental','transfer','maintenance'],true) || !self::reference($reference)) return self::failure('atlas_invalid','Invalid vehicle interval or reference.',422);
        $hash=hash('sha256',wp_json_encode([$kind,$startUtc,$endUtc,$requestId]));
        for ($attempt=0;$attempt<3;$attempt++) {
        if ($wpdb->query('START TRANSACTION')===false) {
            $error=self::sql_failure('Could not start an inventory transaction');
            if (self::rollback_and_retry($error,$attempt)) continue;
            return self::failure('atlas_storage','Could not start an inventory transaction.',503);
        }
        try {
            $r=self::resource($id,true); if ($wpdb->last_error!=='') throw self::sql_failure('Resource read failed');
            if (is_wp_error($r)) { $wpdb->query('ROLLBACK'); return $r; }
            if ($r->kind!=='vehicle') { $wpdb->query('ROLLBACK'); return self::failure('atlas_invalid','Not a vehicle resource.',422); }
            if ($r->vehicle_kind==='') { $wpdb->query('ROLLBACK'); return self::failure('atlas_resource_changed','An agency must review the legacy vehicle classification before new assignments.',409); }
            $linkedType=$kind==='rental'?'rental_request':($kind==='transfer'?'transfer_request':'');
            if ($requestId!=='' && $linkedType==='') { $wpdb->query('ROLLBACK'); return self::failure('atlas_invalid','Maintenance cannot link a customer request.',422); }
            $linked=self::linked($r,$linkedType,$requestId); if (is_wp_error($linked)) { $wpdb->query('ROLLBACK'); return $linked; }
            $prior=self::duplicate($id,$reference,$hash); if ($prior!==null) { $wpdb->query('ROLLBACK'); return $prior; }
            $effective=$end->modify('+'.(int)$r->turnaround_minutes.' minutes')->format('Y-m-d\TH:i:s\Z');
            // An unreturned vehicle blocks new assignments, even beyond its originally scheduled end.
            $overlap=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table('allocations')." WHERE resource_id=%s AND state IN ('allocated','in_use','blocked_conflict','returned','disputed') AND (state IN ('in_use','disputed') OR (start_at<%s AND effective_end>%s)) LIMIT 1 FOR UPDATE",$id,$effective,$startUtc));
            if ($wpdb->last_error!=='') throw self::sql_failure('Interval read failed');
            if ($overlap) { $wpdb->query('ROLLBACK'); return self::failure('atlas_unavailable','Vehicle interval conflicts with an assignment or maintenance.'); }
            $allocation=wp_generate_uuid4(); $now=DB::now();
            self::query_ok($wpdb->insert(self::table('allocations'),['id'=>$allocation,'resource_id'=>$id,'kind'=>$kind,'state'=>'allocated','start_at'=>$startUtc,'end_at'=>$endUtc,'effective_end'=>$effective,'quantity'=>1,'reference'=>$reference,'linked_type'=>$requestId!==''?$linkedType:'','linked_id'=>$requestId,'request_hash'=>$hash,'note'=>'','disputed'=>0,'actor_id'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]));
            self::audit((int)$r->org_id,'local.vehicle.allocate',$allocation); self::query_ok($wpdb->query('COMMIT'));
            return ['id'=>$allocation,'state'=>'allocated','mode'=>'internal_only','replayed'=>false];
        } catch (\Throwable $e) {
            if (self::rollback_and_retry($e,$attempt)) continue;
            return self::failure('atlas_storage','Vehicle allocation failed.',503);
        }
        }
        return self::failure('atlas_storage','Vehicle allocation could not complete after bounded retries.',503);
    }
    /** Lock order is always resource first, allocation second. */
    private static function allocation(string $id): object {
        global $wpdb; $ready=self::ready(); if (is_wp_error($ready)) return $ready;
        $resource=$wpdb->get_var($wpdb->prepare('SELECT resource_id FROM '.self::table('allocations').' WHERE id=%s',$id));
        if (!$resource) return self::failure('atlas_not_found','Allocation not found.',404);
        $r=self::resource($resource,true); if (is_wp_error($r)) return $r;
        $a=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('allocations').' WHERE id=%s AND resource_id=%s FOR UPDATE',$id,$resource));
        if (!$a) return self::failure('atlas_not_found','Allocation not found.',404);
        $a->resource=$r; return $a;
    }
    private static function rental_policy(object $resource): array|WP_Error {
        $policy=json_decode((string)$resource->rental_policy,true);
        if ($resource->vehicle_kind==='' || !is_array($policy) || !isset($policy['policy_reference'],$policy['minimum_age'],$policy['coverage_reference'],$policy['valid_until']) || (int)$resource->policy_reviewed_by<1 || empty($resource->policy_reviewed_at)) return self::failure('atlas_policy_required','A current agency-reviewed rental policy is required.');
        $until=self::instant((string)$policy['valid_until']);
        if (!$until || $until<=new \DateTimeImmutable(DB::now(),new \DateTimeZone('UTC'))) return self::failure('atlas_policy_required','The reviewed rental policy has expired.');
        $policy['hash']=hash('sha256',wp_json_encode([$resource->vehicle_kind,$resource->license_class,$policy]));
        return $policy;
    }
    /** Agency configures policy values; the application invents no legal age/class threshold. */
    public static function configure_rental_policy(string $id,array $policy): true|WP_Error {
        global $wpdb;
        $ready=self::ready(); if (is_wp_error($ready)) return $ready;
        if (!Auth::agency()) return self::failure('atlas_forbidden','Agency review authority is required.',403);
        if (array_diff(array_keys($policy),['policy_reference','minimum_age','coverage_reference','valid_until']) || !is_string($policy['policy_reference']??null) || !self::reference($policy['policy_reference']) || !is_int($policy['minimum_age']??null) || $policy['minimum_age']<0 || $policy['minimum_age']>125 || !is_string($policy['coverage_reference']??null) || !self::reference($policy['coverage_reference']) || !is_string($policy['valid_until']??null)) return self::failure('atlas_invalid','Explicit policy, age, coverage references and expiry are required.',422);
        $until=self::instant($policy['valid_until']); if (!$until || $until<=new \DateTimeImmutable(DB::now(),new \DateTimeZone('UTC'))) return self::failure('atlas_policy_required','A future reviewed policy expiry is required.');
        $policy=['policy_reference'=>$policy['policy_reference'],'minimum_age'=>$policy['minimum_age'],'coverage_reference'=>$policy['coverage_reference'],'valid_until'=>$policy['valid_until']];
        if ($wpdb->query('START TRANSACTION')===false) return self::failure('atlas_storage','Could not start an inventory transaction.',503);
        try {
            $r=self::resource($id,true); if (is_wp_error($r)) { $wpdb->query('ROLLBACK'); return $r; }
            if ($r->kind!=='vehicle') { $wpdb->query('ROLLBACK'); return self::failure('atlas_invalid','Not a vehicle resource.',422); }
            $source=Records::get('vehicle',$r->record_id,get_current_user_id()); if (is_wp_error($source)) { $wpdb->query('ROLLBACK'); return $source; }
            $kind=(string)($source['fields']['category']??''); $class=trim((string)($source['fields']['license_class']??''));
            if (!in_array($kind,['bicycle','motorcycle','car','van'],true) || strlen($class)>100 || ($kind!=='bicycle' && $class==='')) { $wpdb->query('ROLLBACK'); return self::failure('atlas_invalid','Configure a known vehicle category and required license class first.',422); }
            $encoded=wp_json_encode($policy);
            if ($r->vehicle_kind===$kind && $r->license_class===$class && $r->rental_policy===$encoded) { $wpdb->query('ROLLBACK'); return true; }
            if (!empty($r->rental_policy)) {
                $active=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table('allocations')." WHERE resource_id=%s AND kind='rental' AND state IN ('allocated','in_use','disputed','blocked_conflict') LIMIT 1 FOR UPDATE",$id));
                if ($wpdb->last_error!=='') throw new \RuntimeException('Policy allocation read failed');
                if ($active) { $wpdb->query('ROLLBACK'); return self::failure('atlas_conflict','Release or resolve active rentals before changing their reviewed policy.'); }
            }
            self::query_ok($wpdb->update(self::table('resources'),['vehicle_kind'=>$kind,'license_class'=>$class,'rental_policy'=>$encoded,'policy_reviewed_by'=>get_current_user_id(),'policy_reviewed_at'=>DB::now()],['id'=>$id]));
            self::audit((int)$r->org_id,'local.rental.policy.review',$id,$policy['policy_reference']); self::query_ok($wpdb->query('COMMIT')); return true;
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); return self::failure('atlas_storage','Could not record rental policy review.',503); }
    }
    /** Store bounded manual-review evidence references, never licence numbers/photos or identity documents. */
    public static function review_eligibility(string $allocation,array $review): array|WP_Error {
        global $wpdb;
        $ready=self::ready(); if (is_wp_error($ready)) return $ready;
        if (!Auth::agency()) return self::failure('atlas_forbidden','Agency review authority is required.',403);
        $allowed=['renter_reference','age_years','age_evidence_reference','coverage_evidence_reference','review_reference','license_class','license_expires_at','license_evidence_reference'];
        if (array_diff(array_keys($review),$allowed) || !is_int($review['age_years']??null) || $review['age_years']<0 || $review['age_years']>125) return self::failure('atlas_invalid','Only bounded manual eligibility review fields are accepted.',422);
        foreach (['renter_reference','age_evidence_reference','coverage_evidence_reference','review_reference'] as $field) if (!is_string($review[$field]??null) || !self::reference($review[$field])) return self::failure('atlas_invalid','Opaque renter and reviewed evidence references are required.',422);
        if ($wpdb->query('START TRANSACTION')===false) return self::failure('atlas_storage','Could not start an inventory transaction.',503);
        try {
            $a=self::allocation($allocation); if (is_wp_error($a)) { $wpdb->query('ROLLBACK'); return $a; }
            if ($a->kind!=='rental' || $a->state!=='allocated') { $wpdb->query('ROLLBACK'); return self::failure('atlas_invalid_state','Review an unstarted rental allocation.'); }
            $policy=self::rental_policy($a->resource); if (is_wp_error($policy)) { $wpdb->query('ROLLBACK'); return $policy; }
            if ($review['age_years']<(int)$policy['minimum_age']) { $wpdb->query('ROLLBACK'); return self::failure('atlas_age_policy','Reviewed renter age does not satisfy the configured policy.'); }
            if (self::instant($policy['valid_until'])<=self::instant($a->start_at)) { $wpdb->query('ROLLBACK'); return self::failure('atlas_policy_required','The reviewed policy expires before scheduled pickup.'); }
            $data=['renter_reference'=>$review['renter_reference'],'age_years'=>$review['age_years'],'age_evidence_reference'=>$review['age_evidence_reference'],'coverage_evidence_reference'=>$review['coverage_evidence_reference'],'review_reference'=>$review['review_reference']];
            if ($a->resource->vehicle_kind!=='bicycle') {
                $expires=is_string($review['license_expires_at']??null)?self::instant($review['license_expires_at']):false;
                if (!is_string($review['license_class']??null) || $review['license_class']!==$a->resource->license_class || !is_string($review['license_evidence_reference']??null) || !self::reference($review['license_evidence_reference']) || !$expires) { $wpdb->query('ROLLBACK'); return self::failure('atlas_eligibility_required','Matching license class, exact expiry and review evidence are required.'); }
                if ($expires<=new \DateTimeImmutable(DB::now(),new \DateTimeZone('UTC')) || $expires<=self::instant($a->start_at)) { $wpdb->query('ROLLBACK'); return self::failure('atlas_license_expired','The reviewed license does not remain valid for pickup.'); }
                $data+=['license_class'=>$review['license_class'],'license_expires_at'=>$review['license_expires_at'],'license_evidence_reference'=>$review['license_evidence_reference']];
            } else {
                foreach (['license_class','license_expires_at','license_evidence_reference'] as $field) if (isset($review[$field]) && $review[$field]!=='') { $wpdb->query('ROLLBACK'); return self::failure('atlas_invalid','Do not collect license evidence for this bicycle policy.',422); }
            }
            $hash=hash('sha256',wp_json_encode($data));
            $old=json_decode((string)$a->eligibility_review,true);
            if (is_array($old)) {
                $wpdb->query('ROLLBACK');
                return ($old['request_hash']??'')===$hash && ($old['policy_hash']??'')===$policy['hash'] ? ['allocation_id'=>$allocation,'state'=>'reviewed','replayed'=>true] : self::failure('atlas_conflict','Review evidence is immutable; release the unstarted allocation and create a new one for changed evidence.');
            }
            $data+=['request_hash'=>$hash,'policy_hash'=>$policy['hash'],'policy_reference'=>$policy['policy_reference'],'coverage_reference'=>$policy['coverage_reference'],'vehicle_kind'=>$a->resource->vehicle_kind,'reviewed_by'=>get_current_user_id(),'reviewed_at'=>DB::now()];
            self::query_ok($wpdb->update(self::table('allocations'),['eligibility_review'=>wp_json_encode($data),'updated_at'=>DB::now()],['id'=>$allocation]));
            self::audit((int)$a->resource->org_id,'local.rental.eligibility.review',$allocation,$data['review_reference']); self::query_ok($wpdb->query('COMMIT'));
            return ['allocation_id'=>$allocation,'state'=>'reviewed','replayed'=>false];
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); return self::failure('atlas_storage','Could not record eligibility review.',503); }
    }
    private static function eligibility_at_pickup(object $allocation): true|WP_Error {
        $policy=self::rental_policy($allocation->resource); if (is_wp_error($policy)) return $policy;
        $review=json_decode((string)$allocation->eligibility_review,true);
        if (!is_array($review) || ($review['policy_hash']??'')!==$policy['hash'] || ($review['vehicle_kind']??'')!==$allocation->resource->vehicle_kind || (int)($review['reviewed_by']??0)<1 || !isset($review['age_years']) || $review['age_years']<(int)$policy['minimum_age']) return self::failure('atlas_eligibility_required','An agency eligibility review against the current policy is required.');
        if ($allocation->resource->vehicle_kind!=='bicycle') {
            $expires=is_string($review['license_expires_at']??null)?self::instant($review['license_expires_at']):false;
            if (!$expires || ($review['license_class']??'')!==$allocation->resource->license_class || $expires<=new \DateTimeImmutable(DB::now(),new \DateTimeZone('UTC'))) return self::failure('atlas_license_expired','The reviewed license has expired before actual pickup.');
        }
        return true;
    }
    public static function start_vehicle(string $allocation): true|WP_Error {
        global $wpdb; if ($wpdb->query('START TRANSACTION')===false) return self::failure('atlas_storage','Could not start an inventory transaction.',503);
        try {
            $a=self::allocation($allocation); if (is_wp_error($a)) { $wpdb->query('ROLLBACK'); return $a; }
            $now=(new \DateTimeImmutable(DB::now(),new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
            if ($a->kind!=='rental' || $a->state!=='allocated' || !empty($a->picked_up_at) || $now<$a->start_at || $now>=$a->end_at) { $wpdb->query('ROLLBACK'); return self::failure('atlas_invalid_state','Rental is not eligible for pickup.'); }
            $eligible=self::eligibility_at_pickup($a);
            if (is_wp_error($eligible)) { $wpdb->query('ROLLBACK'); return $eligible; }
            $active=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table('allocations')." WHERE resource_id=%s AND state IN ('in_use','disputed') AND id<>%s LIMIT 1 FOR UPDATE",$a->resource_id,$allocation));
            if ($wpdb->last_error!=='') throw new \RuntimeException('Active rental read failed');
            if ($active) { $wpdb->query('ROLLBACK'); return self::failure('atlas_vehicle_in_use','Previous rental is not returned.'); }
            $pickedUp=DB::now();
            self::query_ok($wpdb->update(self::table('allocations'),['state'=>'in_use','picked_up_at'=>$pickedUp,'updated_at'=>$pickedUp],['id'=>$allocation])); self::audit((int)$a->resource->org_id,'local.rental.pickup',$allocation); self::query_ok($wpdb->query('COMMIT')); return true;
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); return self::failure('atlas_storage','Could not record pickup.',503); }
    }
    public static function return_vehicle(string $allocation,string $actualUtc,string $inspection,bool $disputed): array|WP_Error {
        global $wpdb; $actual=self::instant($actualUtc);
        if (!$actual || trim($inspection)==='' || strlen($inspection)>2000) return self::failure('atlas_invalid','Return time and inspection are required.',422);
        if ($wpdb->query('START TRANSACTION')===false) return self::failure('atlas_storage','Could not start an inventory transaction.',503);
        try {
            $a=self::allocation($allocation); if (is_wp_error($a)) { $wpdb->query('ROLLBACK'); return $a; }
            $now=new \DateTimeImmutable(DB::now(),new \DateTimeZone('UTC'));
            if ($a->kind!=='rental' || $a->state!=='in_use') { $wpdb->query('ROLLBACK'); return self::failure('atlas_invalid_state','Return time or rental state is invalid.'); }
            // Legacy allocations deliberately keep NULL evidence after migration. Do not
            // invent a pickup timestamp from a planned start or a mutable update time.
            if (empty($a->picked_up_at)) { $wpdb->query('ROLLBACK'); return self::failure('atlas_pickup_review','Pickup evidence is missing; authorized manual reconciliation is required.'); }
            $pickup=new \DateTimeImmutable($a->picked_up_at,new \DateTimeZone('UTC'));
            if ($actual<$pickup || $actual>$now || $actualUtc<$a->start_at) { $wpdb->query('ROLLBACK'); return self::failure('atlas_invalid_state','Return cannot precede actual pickup or be in the future.'); }
            $effective=$actual->modify('+'.(int)$a->resource->turnaround_minutes.' minutes')->format('Y-m-d\TH:i:s\Z');
            if ($effective<$a->effective_end) $effective=$a->effective_end;
            $conflicts=$wpdb->get_col($wpdb->prepare('SELECT id FROM '.self::table('allocations')." WHERE resource_id=%s AND id<>%s AND state='allocated' AND start_at<%s AND effective_end>%s ORDER BY start_at,id FOR UPDATE",$a->resource_id,$allocation,$effective,$a->start_at));
            if ($wpdb->last_error!=='') throw new \RuntimeException('Return conflict read failed');
            foreach ($conflicts as $other) { self::query_ok($wpdb->update(self::table('allocations'),['state'=>'blocked_conflict','updated_at'=>DB::now()],['id'=>$other,'state'=>'allocated'])); self::audit((int)$a->resource->org_id,'local.vehicle.conflict',$other); }
            self::query_ok($wpdb->update(self::table('allocations'),['state'=>$disputed?'disputed':'returned','effective_end'=>$effective,'note'=>sanitize_textarea_field($inspection),'disputed'=>$disputed?1:0,'updated_at'=>DB::now()],['id'=>$allocation]));
            self::audit((int)$a->resource->org_id,'local.rental.return',$allocation,$disputed?'inspection disputed':'inspection recorded'); self::query_ok($wpdb->query('COMMIT'));
            return ['id'=>$allocation,'state'=>$disputed?'disputed':'returned','conflict_count'=>count($conflicts),'mode'=>'internal_only'];
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); return self::failure('atlas_storage','Could not record return.',503); }
    }
    public static function release(string $allocation): true|WP_Error {
        global $wpdb; if ($wpdb->query('START TRANSACTION')===false) return self::failure('atlas_storage','Could not start an inventory transaction.',503);
        try {
            $a=self::allocation($allocation); if (is_wp_error($a)) { $wpdb->query('ROLLBACK'); return $a; }
            if (!in_array($a->state,['allocated','blocked_conflict'],true)) { $wpdb->query('ROLLBACK'); return self::failure('atlas_invalid_state','Only an unstarted allocation may be released.'); }
            if ($a->kind==='stay') {
                $start=self::date($a->start_at); $end=self::date($a->end_at);
                for ($d=$start;$d<$end;$d=$d->modify('+1 day')) {
                    $date=$d->format('Y-m-d');
                    $changed=$wpdb->query($wpdb->prepare('UPDATE '.self::table('nights').' SET allocated=allocated-%d WHERE resource_id=%s AND local_date=%s AND allocated >= %d',(int)$a->quantity,$a->resource_id,$date,(int)$a->quantity));
                    if ($changed!==1) throw new \RuntimeException('Inventory mismatch');
                }
            }
            self::query_ok($wpdb->update(self::table('allocations'),['state'=>'released','updated_at'=>DB::now()],['id'=>$allocation])); self::audit((int)$a->resource->org_id,'local.allocation.release',$allocation); self::query_ok($wpdb->query('COMMIT')); return true;
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); return self::failure('atlas_storage','Release failed; capacity was not changed.',503); }
    }
    public static function resolve_dispute(string $allocation,string $reason): true|WP_Error {
        global $wpdb; $ready=self::ready(); if (is_wp_error($ready)) return $ready;
        if (!Auth::agency() || trim($reason)==='' || strlen($reason)>190) return self::failure('atlas_forbidden','Agency authority and reason required.',403);
        if ($wpdb->query('START TRANSACTION')===false) return self::failure('atlas_storage','Could not start an inventory transaction.',503);
        try {
            $a=self::allocation($allocation); if (is_wp_error($a)) { $wpdb->query('ROLLBACK'); return $a; }
            if ($a->state!=='disputed') { $wpdb->query('ROLLBACK'); return self::failure('atlas_invalid_state','Allocation is not disputed.'); }
            self::query_ok($wpdb->update(self::table('allocations'),['state'=>'returned','updated_at'=>DB::now()],['id'=>$allocation]));
            self::audit((int)$a->resource->org_id,'local.rental.dispute.resolve',$allocation,sanitize_text_field($reason));
            self::query_ok($wpdb->query('COMMIT')); return true;
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); return self::failure('atlas_storage','Could not resolve dispute.',503); }
    }
    /** Explicit evidence reconciliation for legacy in-use rows; never rewrites evidence. */
    public static function reconcile_pickup(string $allocation,string $pickedUpUtc,string $reason): true|WP_Error {
        global $wpdb; $pickedUp=self::instant($pickedUpUtc);
        $ready=self::ready(); if (is_wp_error($ready)) return $ready;
        if (!Auth::agency()) return self::failure('atlas_forbidden','Agency authority is required.',403);
        if (!$pickedUp || trim($reason)==='' || strlen($reason)>190) return self::failure('atlas_invalid','Valid pickup evidence and a review reason are required.',422);
        if ($wpdb->query('START TRANSACTION')===false) return self::failure('atlas_storage','Could not start an inventory transaction.',503);
        try {
            $a=self::allocation($allocation); if (is_wp_error($a)) { $wpdb->query('ROLLBACK'); return $a; }
            $now=new \DateTimeImmutable(DB::now(),new \DateTimeZone('UTC'));
            if ($a->kind!=='rental' || $a->state!=='in_use' || !empty($a->picked_up_at) || $pickedUpUtc<$a->start_at || $pickedUp>$now) { $wpdb->query('ROLLBACK'); return self::failure('atlas_invalid_state','Only missing pickup evidence for an in-use rental may be reconciled.'); }
            self::query_ok($wpdb->update(self::table('allocations'),['picked_up_at'=>$pickedUp->format('Y-m-d H:i:s.u'),'updated_at'=>DB::now()],['id'=>$allocation]));
            self::audit((int)$a->resource->org_id,'local.rental.pickup.reconcile',$allocation,sanitize_text_field($reason));
            self::query_ok($wpdb->query('COMMIT')); return true;
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); return self::failure('atlas_storage','Could not reconcile pickup evidence.',503); }
    }
    public static function inspect(string $id): array|WP_Error {
        global $wpdb; $r=self::resource($id); if (is_wp_error($r)) return $r;
        $items=$wpdb->get_results($wpdb->prepare('SELECT id,kind,state,start_at,end_at,effective_end,picked_up_at,eligibility_review,quantity,reference,linked_type,linked_id,note,disputed FROM '.self::table('allocations').' WHERE resource_id=%s ORDER BY start_at,id LIMIT 200',$id),ARRAY_A);
        if (!is_array($items)) return self::failure('atlas_storage','Unable to read local inventory.',503);
        foreach ($items as &$item) {
            $item['pickup_review_required']=$item['state']==='in_use' && empty($item['picked_up_at']);
            if ($item['kind']==='rental') { $allocation=(object)$item; $allocation->resource=$r; $eligible=self::eligibility_at_pickup($allocation); $item['eligibility_status']=is_wp_error($eligible)?$eligible->get_error_code():'reviewed'; }
            unset($item['eligibility_review']); // Do not expose renter age or evidence references through the general resource projection.
        } unset($item);
        return ['id'=>$id,'kind'=>$r->kind,'vehicle_kind'=>$r->vehicle_kind,'license_class'=>$r->license_class,'rental_policy'=>$r->rental_policy?json_decode($r->rental_policy,true):null,'policy_reviewed_at'=>$r->policy_reviewed_at,'capacity'=>(int)$r->capacity,'timezone'=>$r->timezone,'min_nights'=>(int)$r->min_nights,'turnaround_minutes'=>(int)$r->turnaround_minutes,'mode'=>'internal_only','allocations'=>$items];
    }
}

/** The REST surface is staff-only and requires both nonce and scoped service checks. */
final class Routes {
    public static function permission(\WP_REST_Request $request): true|WP_Error {
        if (!Local::available() || !is_user_logged_in() || !Auth::can('atlas_manage_records')) return new WP_Error('atlas_forbidden','Access denied.',['status'=>403]);
        $nonce=$request->get_header('X-WP-Nonce');
        if (!is_string($nonce) || !wp_verify_nonce($nonce,'wp_rest')) return new WP_Error('atlas_nonce','Valid REST nonce required.',['status'=>403]);
        return true;
    }
    public static function register(): void {
        register_rest_route('atlas-inventory/v1','/resources',['methods'=>'POST','permission_callback'=>[self::class,'permission'],'callback'=>[self::class,'create']]);
        register_rest_route('atlas-inventory/v1','/resources/(?P<id>[a-fA-F0-9-]{36})',['methods'=>'GET','permission_callback'=>[self::class,'permission'],'callback'=>[self::class,'inspect']]);
        foreach (['stay','vehicle','capacity','blackout','start','return','release','resolve-dispute','reconcile-pickup','rental-policy','eligibility-review'] as $action) {
            register_rest_route('atlas-inventory/v1','/'.$action,['methods'=>'POST','permission_callback'=>[self::class,'permission'],'callback'=>static function ($request) use ($action) { return self::dispatch($action,$request); }]);
        }
    }
    private static function invalid(): WP_Error { return new WP_Error('atlas_invalid','Invalid or missing JSON fields.',['status'=>422]); }
    public static function create(\WP_REST_Request $r): array|WP_Error {
        $d=$r->get_json_params(); if (!is_array($d) || !is_string($d['record_type']??null) || !is_string($d['record_id']??null) || !is_int($d['min_stay']??null) || !is_int($d['turnaround_minutes']??null)) return self::invalid();
        return Local::register_resource($d['record_type'],$d['record_id'],$d['min_stay'],$d['turnaround_minutes']);
    }
    public static function inspect(\WP_REST_Request $r): array|WP_Error { return Local::inspect((string)$r['id']); }
    public static function dispatch(string $action,\WP_REST_Request $r): array|WP_Error {
        $d=$r->get_json_params(); if (!is_array($d)) return self::invalid();
        if (array_key_exists('request_id',$d) && !is_string($d['request_id'])) return self::invalid();
        $strings=static function (...$names) use ($d) { foreach ($names as $name) if (!isset($d[$name]) || !is_string($d[$name])) return false; return true; };
        $result=self::invalid();
        switch ($action) {
            case 'stay': if ($strings('resource_id','check_in','check_out','reference') && is_int($d['quantity']??null)) $result=Local::allocate_stay($d['resource_id'],$d['check_in'],$d['check_out'],$d['quantity'],$d['reference'],is_string($d['request_id']??'')?$d['request_id']??'':''); break;
            case 'vehicle': if ($strings('resource_id','start_utc','end_utc','kind','reference')) $result=Local::allocate_vehicle($d['resource_id'],$d['start_utc'],$d['end_utc'],$d['kind'],$d['reference'],is_string($d['request_id']??'')?$d['request_id']??'':''); break;
            case 'capacity': if ($strings('resource_id') && is_int($d['capacity']??null) && is_int($d['min_stay']??null)) $result=Local::capacity($d['resource_id'],$d['capacity'],$d['min_stay']); break;
            case 'blackout': if ($strings('resource_id','start_date','end_date') && is_bool($d['blocked']??null)) $result=Local::blackout($d['resource_id'],$d['start_date'],$d['end_date'],$d['blocked']); break;
            case 'start': if ($strings('allocation_id')) $result=Local::start_vehicle($d['allocation_id']); break;
            case 'return': if ($strings('allocation_id','actual_utc','inspection') && is_bool($d['disputed']??null)) $result=Local::return_vehicle($d['allocation_id'],$d['actual_utc'],$d['inspection'],$d['disputed']); break;
            case 'release': if ($strings('allocation_id')) $result=Local::release($d['allocation_id']); break;
            case 'resolve-dispute': if ($strings('allocation_id','reason')) $result=Local::resolve_dispute($d['allocation_id'],$d['reason']); break;
            case 'reconcile-pickup': if ($strings('allocation_id','picked_up_utc','reason')) $result=Local::reconcile_pickup($d['allocation_id'],$d['picked_up_utc'],$d['reason']); break;
            case 'rental-policy': if ($strings('resource_id') && is_array($d['policy']??null)) $result=Local::configure_rental_policy($d['resource_id'],$d['policy']); break;
            case 'eligibility-review': if ($strings('allocation_id') && is_array($d['review']??null)) $result=Local::review_eligibility($d['allocation_id'],$d['review']); break;
        }
        return is_wp_error($result)?$result:(is_array($result)?$result:['ok'=>true]);
    }
}
