<?php
namespace Atlas\Core;
if (!defined('ABSPATH')) { exit; }
final class Auth {
    /** Organization roles are scoped ceilings, never WordPress/agency roles. */
    private const ORG_ROLES = [
        'viewer'=>['read'],
        'contributor'=>['read','write'],
        'editor'=>['read','write'],
        'leader'=>['read','write'],
        'manager'=>['read','write'],
        'partner_admin'=>['read','write'],
        'owner'=>['read','write'],
    ];
    public static function actor(int $actor): bool { return $actor > 0 && get_current_user_id() === $actor && is_user_logged_in(); }
    public static function agency(): bool { return is_user_logged_in() && current_user_can('manage_options'); }
    public static function organization_roles(): array { return array_keys(self::ORG_ROLES); }
    public static function member_role(int $org, int $user = 0): ?string {
        global $wpdb;
        $user = $user ?: get_current_user_id();
        if ($org <= 0 || $user <= 0) { return null; }
        $role=$wpdb->get_var($wpdb->prepare('SELECT m.role FROM ' . DB::table('memberships') . ' m INNER JOIN ' . DB::table('organizations') . " o ON o.id=m.org_id WHERE m.org_id=%d AND m.user_id=%d AND m.status=%s AND o.status IN ('approved','active')", $org, $user, 'active'));
        return is_string($role) && isset(self::ORG_ROLES[$role]) ? $role : null;
    }
    public static function member(int $org, int $user = 0): bool {
        return self::member_role($org,$user) !== null;
    }
    public static function role_allows(int $org, string $operation, ?array $allowedRoles = null): bool {
        $role=self::member_role($org);
        return $role !== null && in_array($operation,self::ORG_ROLES[$role],true)
            && ($allowedRoles === null || in_array($role,$allowedRoles,true));
    }
    public static function can(string $cap, ?int $orgId = null, ?int $objectId = null, string $operation = 'write'): bool {
        if (!is_user_logged_in()) { return false; }
        if (self::agency()) { return true; }
        if (!in_array($operation,['read','write'],true) || ($orgId !== null && $orgId < 0)) { return false; }
        if (!current_user_can($cap)) { return false; }
        if ($orgId && !self::role_allows($orgId,$operation)) { return false; }
        if ($objectId) {
            $object = get_post($objectId);
            if (!$object) { return false; }
            $objectOrg = (int)get_post_meta($objectId, '_atlas_org_id', true);
            if ($objectOrg !== (int)($orgId ?? 0)) { return false; }
            // An unscoped capability is not ownership of every agency/customer
            // draft. Only its author may access it without the agency override.
            if ($objectOrg === 0 && (int)$object->post_author !== get_current_user_id()) { return false; }
        }
        return true;
    }
    public static function require(string $cap, ?int $orgId = null, ?int $objectId = null, string $operation = 'write'): true|\WP_Error {
        return self::can($cap,$orgId,$objectId,$operation) ? true : new \WP_Error('atlas_forbidden', __('You do not have access to this record.', 'atlas-core'), ['status'=>403]);
    }
}
