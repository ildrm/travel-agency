<?php
namespace Atlas\Core;
if (!defined('ABSPATH')) { exit; }

final class DB {
    private const SCHEMA = '6';
    private const TABLES = ['organizations', 'memberships', 'records', 'inventory', 'holds', 'bookings', 'payments', 'refunds', 'ledger', 'idempotency', 'audit', 'outbox'];

    public static function table(string $name): string {
        global $wpdb;
        if (!in_array($name, self::TABLES, true)) { throw new \InvalidArgumentException('Unknown Atlas table'); }
        return $wpdb->prefix . 'atlas_' . $name;
    }
    public static function ready(): true|\WP_Error {
        if (get_option('atlas_core_schema') !== self::SCHEMA || get_option('atlas_core_schema_validated') !== self::SCHEMA) {
            return new \WP_Error('atlas_schema_pending', __('Atlas Core database migration is required. Booking is unavailable.', 'atlas-core'), ['status' => 503]);
        }
        return true;
    }
    public static function activate(): void {
        $result = self::migrate();
        if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message())); }
        if ($role = get_role('administrator')) {
            foreach (['atlas_manage_records','atlas_manage_offers','atlas_approve_offers','atlas_manage_bookings','atlas_manage_finance'] as $cap) { $role->add_cap($cap); }
        }
    }
    public static function migrate(): true|\WP_Error {
        global $wpdb;
        $lock = $wpdb->get_var("SELECT GET_LOCK('atlas_core_schema_" . md5($wpdb->prefix) . "', 0)");
        if ((string)$lock !== '1') { return new \WP_Error('atlas_migration_locked', __('Atlas schema migration is already running.', 'atlas-core')); }
        $success = false;
        try {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $collate = $wpdb->get_charset_collate();
            $schemas = [
              'organizations' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT, name varchar(190) NOT NULL, status varchar(32) NOT NULL DEFAULT 'pending', created_at datetime(6) NOT NULL, PRIMARY KEY  (id)",
              'memberships' => "org_id bigint(20) unsigned NOT NULL, user_id bigint(20) unsigned NOT NULL, role varchar(64) NOT NULL, status varchar(24) NOT NULL, updated_at datetime(6) NOT NULL, PRIMARY KEY  (org_id,user_id), KEY user_status (user_id,status)",
              'records' => "id char(36) NOT NULL, type varchar(64) NOT NULL, org_id bigint(20) unsigned NOT NULL DEFAULT 0, owner_id bigint(20) unsigned NOT NULL, state varchar(64) NOT NULL DEFAULT 'draft', payload longtext NOT NULL, version bigint(20) unsigned NOT NULL DEFAULT 1, created_at datetime(6) NOT NULL, updated_at datetime(6) NOT NULL, PRIMARY KEY  (id), KEY scoped (type,org_id,owner_id), KEY record_state (type,state)",
              'inventory' => "offer_id bigint(20) unsigned NOT NULL, capacity bigint(20) unsigned NOT NULL, allocated bigint(20) unsigned NOT NULL DEFAULT 0, version bigint(20) unsigned NOT NULL DEFAULT 1, kind varchar(32) NOT NULL, status varchar(24) NOT NULL, offer_snapshot longtext NOT NULL, PRIMARY KEY  (offer_id), KEY public_kind (status,kind)",
              'holds' => "id char(36) NOT NULL, booking_id char(36) NOT NULL, offer_id bigint(20) unsigned NOT NULL, user_id bigint(20) unsigned NOT NULL, quantity bigint(20) unsigned NOT NULL, accepted_at datetime(6) NOT NULL, expires_at datetime(6) NOT NULL, state varchar(24) NOT NULL, PRIMARY KEY  (id), UNIQUE KEY booking_id (booking_id), KEY expiry (state,expires_at), KEY user_id (user_id)",
              'bookings' => "id char(36) NOT NULL, hold_id char(36) NOT NULL, offer_id bigint(20) unsigned NOT NULL, user_id bigint(20) unsigned NOT NULL, status varchar(40) NOT NULL, quantity bigint(20) unsigned NOT NULL, amount_irr bigint(20) unsigned NOT NULL, offer_version bigint(20) unsigned NOT NULL, snapshot longtext NOT NULL, participants longtext NOT NULL, order_id bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime(6) NOT NULL, updated_at datetime(6) NOT NULL, PRIMARY KEY  (id), UNIQUE KEY hold_id (hold_id), KEY user_status (user_id,status)",
              'payments' => "id char(36) NOT NULL, booking_id char(36) NOT NULL, provider varchar(64) NOT NULL, provider_ref varchar(190) NOT NULL, amount_irr bigint(20) unsigned NOT NULL, currency char(3) NOT NULL, status varchar(24) NOT NULL, exception_code varchar(64) NOT NULL DEFAULT '', verified_at datetime(6) NOT NULL, paid_at datetime(6) NULL, PRIMARY KEY  (id), UNIQUE KEY provider_reference (provider,provider_ref), KEY booking_id (booking_id), KEY exception_code (exception_code)",
              'refunds' => "id char(36) NOT NULL, payment_id char(36) NOT NULL, booking_id char(36) NOT NULL, amount_irr bigint(20) unsigned NOT NULL, status varchar(24) NOT NULL, requested_by bigint(20) unsigned NOT NULL, reason varchar(190) NOT NULL, provider varchar(64) NOT NULL, provider_ref varchar(190) NULL, created_at datetime(6) NOT NULL, updated_at datetime(6) NOT NULL, PRIMARY KEY  (id), UNIQUE KEY provider_ref (provider,provider_ref), KEY payment_status (payment_id,status)",
              'ledger' => "id char(36) NOT NULL, payment_id char(36) NOT NULL, refund_id char(36) NOT NULL DEFAULT '', event_type varchar(32) NOT NULL, amount_irr bigint(20) NOT NULL, currency char(3) NOT NULL, created_at datetime(6) NOT NULL, PRIMARY KEY  (id), UNIQUE KEY event_once (payment_id,refund_id,event_type), KEY payment_id (payment_id)",
              'idempotency' => "scope varchar(190) NOT NULL, operation varchar(64) NOT NULL, idem_key varchar(128) NOT NULL, request_hash char(64) NOT NULL, response longtext NOT NULL, created_at datetime(6) NOT NULL, PRIMARY KEY  (scope,operation,idem_key)",
              'audit' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT, actor_id bigint(20) unsigned NOT NULL DEFAULT 0, org_id bigint(20) unsigned NOT NULL DEFAULT 0, action varchar(100) NOT NULL, object_id varchar(100) NOT NULL, result varchar(24) NOT NULL, reason varchar(190) NOT NULL DEFAULT '', created_at datetime(6) NOT NULL, PRIMARY KEY  (id), KEY object_time (object_id,created_at)",
              'outbox' => "id char(36) NOT NULL, event_type varchar(100) NOT NULL, aggregate_id varchar(100) NOT NULL, payload longtext NOT NULL, status varchar(24) NOT NULL DEFAULT 'pending', attempts bigint(20) unsigned NOT NULL DEFAULT 0, claimed_at datetime(6) NULL, available_at datetime(6) NULL, created_at datetime(6) NOT NULL, PRIMARY KEY  (id), KEY status_time (status,created_at)",
            ];
            $needsDdl=get_option('atlas_core_schema') !== self::SCHEMA || get_option('atlas_core_schema_validated') !== self::SCHEMA;
            foreach ($schemas as $name => $fields) {
                $table = self::table($name);
                if ($needsDdl) {
                    dbDelta("CREATE TABLE $table (\n" . preg_replace('/, (?![^()]*\))/', ",\n", $fields) . "\n) ENGINE=InnoDB $collate;");
                    if ($wpdb->last_error !== '') { return self::schema_error($name); }
                }
                $valid=self::validate_table($name,$fields);
                if (is_wp_error($valid)) { return $valid; }
            }
            update_option('atlas_core_schema', self::SCHEMA, false);
            update_option('atlas_core_schema_validated', self::SCHEMA, false);
            $success = true;
            return true;
        } catch (\Throwable $e) {
            return new \WP_Error('atlas_migration_failed', __('Atlas database migration failed; writes remain disabled.', 'atlas-core'));
        } finally {
            if (!$success) { delete_option('atlas_core_schema_validated'); }
            $wpdb->get_var("SELECT RELEASE_LOCK('atlas_core_schema_" . md5($wpdb->prefix) . "')");
        }
    }
    private static function schema_error(string $name): \WP_Error {
        return new \WP_Error('atlas_schema_failed',sprintf(__('Atlas table %s does not match the required transactional schema. Writes remain unavailable.', 'atlas-core'),$name));
    }
    private static function column_type(string $type): string {
        // MySQL may omit integer display widths that MariaDB retains.
        return preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/','$1',strtolower(trim($type)));
    }
    private static function validate_table(string $name,string $definition): true|\WP_Error {
        global $wpdb;
        $table=self::table($name);
        $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
        $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
        if ($exists !== $table || strtoupper((string)$engine) !== 'INNODB') { return self::schema_error($name); }
        $columns=[];
        foreach ($wpdb->get_results("SHOW COLUMNS FROM $table",ARRAY_A) ?: [] as $column) { $columns[$column['Field']]=$column; }
        $indexes=[];
        foreach ($wpdb->get_results("SHOW INDEX FROM $table",ARRAY_A) ?: [] as $index) {
            $key=$index['Key_name']; $indexes[$key]['unique']=(int)$index['Non_unique']===0;
            $indexes[$key]['columns'][(int)$index['Seq_in_index']]=$index['Column_name'];
            if ($index['Sub_part'] !== null) { $indexes[$key]['prefix']=true; }
        }
        // Only a guarded local CLI test can alter the inspected metadata. No DDL
        // or live table is modified to prove that a failed guard stays closed.
        if ($name==='payments' && self::$testSchemaFault==='missing_column') { unset($columns['currency']); }
        if ($name==='payments' && self::$testSchemaFault==='nonunique_reference' && isset($indexes['provider_reference'])) { $indexes['provider_reference']['unique']=false; }
        foreach (preg_split('/, (?![^()]*\))/', $definition) as $part) {
            if (preg_match('/^(PRIMARY KEY|UNIQUE KEY|KEY)\s+(?:([a-z0-9_]+)\s*)?\(([^)]+)\)$/i',$part,$m)) {
                $key=strtoupper($m[1])==='PRIMARY KEY' ? 'PRIMARY' : $m[2];
                $expected=explode(',',str_replace(' ','',$m[3])); $actual=$indexes[$key] ?? null;
                if (!$actual || !empty($actual['prefix']) || $actual['unique']!==(strtoupper($m[1])!=='KEY')) { return self::schema_error($name); }
                ksort($actual['columns']);
                if (array_values($actual['columns'])!==$expected) { return self::schema_error($name); }
                continue;
            }
            if (!preg_match('/^([a-z][a-z0-9_]*)\s+([a-z]+(?:\(\d+(?:,\d+)?\))?(?: unsigned)?)/i',$part,$m)) { return self::schema_error($name); }
            $column=$columns[$m[1]] ?? null;
            if (!$column || self::column_type($column['Type'])!==self::column_type($m[2]) || ($column['Null']==='NO')!==(stripos($part,'NOT NULL')!==false)) { return self::schema_error($name); }
            if (stripos($part,'AUTO_INCREMENT')!==false && stripos($column['Extra'],'auto_increment')===false) { return self::schema_error($name); }
        }
        return true;
    }
    private static ?string $testSchemaFault = null;
    public static function set_test_schema_fault(?string $fault): void {
        if (PHP_SAPI !== 'cli' || !defined('ATLAS_TEST_CLOCK') || ATLAS_TEST_CLOCK !== true || wp_get_environment_type() !== 'local') { throw new \LogicException('Test schema fault injection is unavailable.'); }
        if ($fault !== null && !in_array($fault,['missing_column','nonunique_reference'],true)) { throw new \InvalidArgumentException('Unknown schema fault.'); }
        self::$testSchemaFault=$fault;
    }
    private static ?string $testNow = null;
    public static function set_test_now(?string $utc): void {
        if (PHP_SAPI !== 'cli' || !defined('ATLAS_TEST_CLOCK') || ATLAS_TEST_CLOCK !== true || wp_get_environment_type() !== 'local') { throw new \LogicException('Test clock is unavailable.'); }
        self::$testNow = $utc === null ? null : (new \DateTimeImmutable($utc))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
    public static function now(): string { return self::$testNow ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'); }
    private static bool $testAuditFailure = false;
    public static function set_test_audit_failure(bool $fail): void {
        if (PHP_SAPI !== 'cli' || !defined('ATLAS_TEST_CLOCK') || ATLAS_TEST_CLOCK !== true || wp_get_environment_type() !== 'local') { throw new \LogicException('Test fault injection is unavailable.'); }
        self::$testAuditFailure=$fail;
    }
    public static function audit(int $actor, int $org, string $action, string $id, string $result, string $reason = ''): bool {
        global $wpdb;
        if (self::$testAuditFailure) { return false; }
        // WordPress treats its own taxonomy object_id as an integer by default.
        // Our object IDs may be UUIDs; inherited field formats would corrupt the
        // audit reference while still reporting a successful database insert.
        return false !== $wpdb->insert(self::table('audit'), ['actor_id'=>$actor, 'org_id'=>$org, 'action'=>$action, 'object_id'=>$id, 'result'=>$result, 'reason'=>substr($reason,0,190), 'created_at'=>self::now()], ['%d','%d','%s','%s','%s','%s','%s']);
    }
    public static function emit(string $type, string $id): bool {
        global $wpdb;
        return false !== $wpdb->insert(self::table('outbox'), ['id'=>wp_generate_uuid4(), 'event_type'=>$type, 'aggregate_id'=>$id, 'payload'=>wp_json_encode(['id'=>$id]), 'status'=>'pending', 'attempts'=>0, 'created_at'=>self::now()]);
    }
}
