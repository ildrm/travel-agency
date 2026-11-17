<?php
namespace Atlas\Core;
if (!defined('ABSPATH')) { exit; }
final class Records {
    private static array $types = [];
    public static function register_type(string $type, array $schema): true|\WP_Error {
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $type) || isset(self::$types[$type]) || empty($schema['fields']) || !is_array($schema['fields'])) { return new \WP_Error('atlas_invalid_schema', 'Invalid or duplicate record schema.'); }
        foreach ($schema['fields'] as $name=>$field) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', (string)$name) || !is_array($field) || !in_array($field['type'] ?? '', ['string','int','bool','array','datetime','timezone'], true)) { return new \WP_Error('atlas_invalid_schema', 'Invalid field definition.'); }
        }
        self::$types[$type] = $schema; return true;
    }
    private static function valid(string $type, array $fields, bool $partial = false): array|\WP_Error {
        $schema = self::$types[$type] ?? null;
        if (!$schema) { return new \WP_Error('atlas_unknown_type', 'Unknown record type.', ['status'=>400]); }
        $out = [];
        foreach ($fields as $name=>$value) {
            if (!isset($schema['fields'][$name])) { return new \WP_Error('atlas_unknown_field', 'Unknown record field.', ['status'=>400]); }
            $def = $schema['fields'][$name]; $kind=$def['type'];
            if (($kind==='string' || $kind==='datetime' || $kind==='timezone') && !is_string($value)) { return new \WP_Error('atlas_invalid_field','Invalid field type.', ['status'=>422]); }
            if ($kind==='int' && !is_int($value)) { return new \WP_Error('atlas_invalid_field','Invalid field type.', ['status'=>422]); }
            if ($kind==='bool' && !is_bool($value)) { return new \WP_Error('atlas_invalid_field','Invalid field type.', ['status'=>422]); }
            if ($kind==='array' && !is_array($value)) { return new \WP_Error('atlas_invalid_field','Invalid field type.', ['status'=>422]); }
            if (is_string($value) && strlen($value) > (int)($def['max'] ?? 10000)) { return new \WP_Error('atlas_invalid_field','Field is too long.', ['status'=>422]); }
            if ($kind==='array' && strlen(wp_json_encode($value)) > (int)($def['max'] ?? 50000)) { return new \WP_Error('atlas_invalid_field','Field is too long.', ['status'=>422]); }
            if (isset($def['enum']) && !in_array($value,$def['enum'],true)) { return new \WP_Error('atlas_invalid_field','Value is not permitted.', ['status'=>422]); }
            if ($kind==='timezone' && !in_array($value, timezone_identifiers_list(),true)) { return new \WP_Error('atlas_invalid_field','Invalid IANA time zone.', ['status'=>422]); }
            if ($kind==='datetime' && (strtotime($value) === false || !preg_match('/(?:Z|[+-]00:00)$/', $value))) { return new \WP_Error('atlas_invalid_field','UTC timestamp required.', ['status'=>422]); }
            $out[$name]=$value;
        }
        if (!$partial) { foreach ($schema['fields'] as $name=>$def) { if (!empty($def['required']) && !array_key_exists($name,$out)) { return new \WP_Error('atlas_required_field','Required field is missing.', ['status'=>422]); } } }
        return $out;
    }
    private static function project(object $row): array {
        return ['id'=>$row->id,'type'=>$row->type,'org_id'=>(int)$row->org_id,'owner_id'=>(int)$row->owner_id,'state'=>$row->state,'fields'=>json_decode($row->payload,true) ?: [],'version'=>(int)$row->version,'created_at'=>$row->created_at,'updated_at'=>$row->updated_at];
    }
    private static function allowed(object $row, int $actor): bool {
        if (!self::actor_ok($actor) || !isset(self::$types[$row->type])) { return false; }
        if ($row->type==='traveler') { return (int)$row->org_id===0 && (int)$row->owner_id===$actor; }
        if (Auth::agency()) { return true; }
        $cap=self::$types[$row->type]['capability'] ?? 'read';
        return current_user_can($cap) && ((int)$row->org_id > 0 ? Auth::member((int)$row->org_id,$actor) : (int)$row->owner_id === $actor);
    }
    private static function actor_ok(int $actor): bool { return Auth::actor($actor); }
    public static function create(string $type, array $fields, int $actorId): array|\WP_Error {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            $record=self::create_in_transaction($type,$fields,$actorId);
            if (is_wp_error($record)) { $wpdb->query('ROLLBACK'); return $record; }
            $wpdb->query('COMMIT'); return $record;
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); return new \WP_Error('atlas_storage_error','Record could not be saved.', ['status'=>503]); }
    }
    private static function create_in_transaction(string $type, array $fields, int $actorId): array|\WP_Error {
        global $wpdb;
        if (!self::actor_ok($actorId) || !isset(self::$types[$type])) { return new \WP_Error('atlas_forbidden','Access denied.', ['status'=>403]); }
        $org=(int)($fields['org_id'] ?? 0); unset($fields['org_id']);
        if (isset($fields['owner_id']) || !Auth::can(self::$types[$type]['capability'] ?? 'read', $org ?: null)) { return new \WP_Error('atlas_forbidden','Access denied.', ['status'=>403]); }
        $values=self::valid($type,$fields); if (is_wp_error($values)) { return $values; }
        $state=(string)($values['state'] ?? 'draft');
        $initial=self::$types[$type]['initial_states'] ?? ['draft'];
        if (!in_array($state,$initial,true) || (in_array($state,['approved','published'],true) && !Auth::agency())) { return new \WP_Error('atlas_state_forbidden','Initial state is not permitted.', ['status'=>403]); }
        $id=wp_generate_uuid4(); $now=DB::now();
        $ok=$wpdb->insert(DB::table('records'), ['id'=>$id,'type'=>$type,'org_id'=>$org,'owner_id'=>$actorId,'state'=>$state,'payload'=>wp_json_encode($values),'version'=>1,'created_at'=>$now,'updated_at'=>$now]);
        if (!$ok) { return new \WP_Error('atlas_storage_error','Could not save record.', ['status'=>503]); }
        if (!DB::audit($actorId,$org,'record.create',$id,'success')) { return new \WP_Error('atlas_audit_failed','Record could not be audited.', ['status'=>503]); }
        return self::get($type,$id,$actorId);
    }
    private static function canonical(mixed $value): mixed {
        if (!is_array($value)) { return $value; }
        if (!array_is_list($value)) { ksort($value,SORT_STRING); }
        foreach ($value as $key=>$item) { $value[$key]=self::canonical($item); }
        return $value;
    }
    public static function create_idempotent(string $type, array $fields, int $actorId, string $key): array|\WP_Error {
        global $wpdb;
        if (!self::actor_ok($actorId) || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$key) || !isset(self::$types[$type])) { return new \WP_Error('atlas_invalid_request','Invalid authenticated request.', ['status'=>400]); }
        $scope='user:'.$actorId; $operation='record.'.$type; $hash=hash('sha256',wp_json_encode(self::canonical($fields)));
        $fetch=static function () use ($wpdb,$scope,$operation,$key) { return $wpdb->get_row($wpdb->prepare('SELECT request_hash,response FROM '.DB::table('idempotency').' WHERE scope=%s AND operation=%s AND idem_key=%s',$scope,$operation,$key)); };
        $existing=$fetch();
        if ($existing) { if (!hash_equals($existing->request_hash,$hash)) { return new \WP_Error('atlas_idempotency_conflict','Idempotency key was reused.', ['status'=>409]); } return self::get($type,(string)json_decode($existing->response,true)['id'],$actorId); }
        $wpdb->query('START TRANSACTION');
        try {
            $record=self::create_in_transaction($type,$fields,$actorId); if (is_wp_error($record)) { $wpdb->query('ROLLBACK'); return $record; }
            if (!$wpdb->insert(DB::table('idempotency'),['scope'=>$scope,'operation'=>$operation,'idem_key'=>$key,'request_hash'=>$hash,'response'=>wp_json_encode(['id'=>$record['id']]),'created_at'=>DB::now()])) { throw new \RuntimeException('duplicate'); }
            $wpdb->query('COMMIT'); return $record;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK'); $existing=$fetch();
            if ($existing && hash_equals($existing->request_hash,$hash)) { return self::get($type,(string)json_decode($existing->response,true)['id'],$actorId); }
            return new \WP_Error($existing ? 'atlas_idempotency_conflict' : 'atlas_storage_error',$existing ? 'Idempotency key was reused.' : 'Record could not be saved.', ['status'=>$existing ? 409 : 503]);
        }
    }
    public static function get(string $type, string $id, int $actorId): array|\WP_Error {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('records').' WHERE id=%s AND type=%s',$id,$type));
        if (!$row || !self::allowed($row,$actorId)) { return new \WP_Error('atlas_not_found','Record not found.', ['status'=>404]); }
        return self::project($row);
    }
    public static function update(string $type, string $id, array $changes, int $actorId, int $expectedVersion): array|\WP_Error {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('records').' WHERE id=%s AND type=%s FOR UPDATE',$id,$type));
            if (!$row || !self::allowed($row,$actorId)) { $wpdb->query('ROLLBACK'); return new \WP_Error('atlas_not_found','Record not found.', ['status'=>404]); }
            if (isset($changes['org_id']) || isset($changes['owner_id'])) { $wpdb->query('ROLLBACK'); return new \WP_Error('atlas_forbidden','Ownership cannot be changed here.', ['status'=>403]); }
            $values=self::valid($type,$changes,true); if (is_wp_error($values)) { $wpdb->query('ROLLBACK'); return $values; }
            if ((int)$row->version !== $expectedVersion) { $wpdb->query('ROLLBACK'); return new \WP_Error('atlas_conflict','Record changed.', ['status'=>409]); }
            $current=json_decode($row->payload,true) ?: []; $next=array_merge($current,$values);
            if (isset($values['state']) && $values['state'] !== $row->state) {
                $allowed=self::$types[$type]['transitions']['state'][$row->state] ?? [];
                if (!in_array($values['state'],$allowed,true)) { $wpdb->query('ROLLBACK'); return new \WP_Error('atlas_invalid_transition','Transition not permitted.', ['status'=>409]); }
                $agency=self::$types[$type]['agency_transitions']['state'][$row->state] ?? [];
                if ((!Auth::agency() && in_array($values['state'],$agency,true)) || (!Auth::agency() && in_array($values['state'],['approved','published'],true))) { $wpdb->query('ROLLBACK'); return new \WP_Error('atlas_forbidden','Agency approval required.', ['status'=>403]); }
            }
            $updated=$wpdb->update(DB::table('records'),['payload'=>wp_json_encode($next),'state'=>$next['state'] ?? $row->state,'version'=>$expectedVersion+1,'updated_at'=>DB::now()],['id'=>$id,'version'=>$expectedVersion]);
            if ($updated !== 1) { $wpdb->query('ROLLBACK'); return new \WP_Error('atlas_conflict','Record changed.', ['status'=>409]); }
            if (!DB::audit($actorId,(int)$row->org_id,'record.update',$id,'success')) { $wpdb->query('ROLLBACK'); return new \WP_Error('atlas_audit_failed','Record could not be audited.', ['status'=>503]); }
            $wpdb->query('COMMIT'); return self::get($type,$id,$actorId);
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); return new \WP_Error('atlas_storage_error','Record could not be updated.', ['status'=>503]); }
    }
    public static function query(string $type, array $filters, int $actorId): array|\WP_Error {
        global $wpdb;
        if (!self::actor_ok($actorId) || !isset(self::$types[$type])) { return new \WP_Error('atlas_forbidden','Access denied.', ['status'=>403]); }
        $limit=min(100,max(1,(int)($filters['limit'] ?? 25))); $offset=max(0,(int)($filters['offset'] ?? 0));
        $records=DB::table('records'); $members=DB::table('memberships'); $organizations=DB::table('organizations');
        if (Auth::agency() && $type!=='traveler') { $sql=$wpdb->prepare("SELECT r.* FROM $records r WHERE r.type=%s ORDER BY r.created_at DESC LIMIT %d OFFSET %d",$type,$limit,$offset); }
        else {
            if (!current_user_can(self::$types[$type]['capability'] ?? 'read')) { return new \WP_Error('atlas_forbidden','Access denied.', ['status'=>403]); }
            $sql=$wpdb->prepare("SELECT r.* FROM $records r WHERE r.type=%s AND ((r.org_id=0 AND r.owner_id=%d) OR (r.org_id>0 AND EXISTS (SELECT 1 FROM $members m INNER JOIN $organizations o ON o.id=m.org_id WHERE m.org_id=r.org_id AND m.user_id=%d AND m.status=%s AND o.status IN ('approved','active')))) ORDER BY r.created_at DESC LIMIT %d OFFSET %d",$type,$actorId,$actorId,'active',$limit,$offset);
        }
        $result=[]; foreach ($wpdb->get_results($sql) as $row) { if (self::allowed($row,$actorId)) { $result[]=self::project($row); } }
        return $result;
    }
}
