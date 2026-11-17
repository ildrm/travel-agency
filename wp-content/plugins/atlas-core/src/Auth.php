<?php
namespace Atlas\Core;
if (!defined('ABSPATH')) { exit; }
final class Auth {
    public static function actor(int $actor): bool { return $actor > 0 && get_current_user_id() === $actor && is_user_logged_in(); }
    public static function agency(): bool { return is_user_logged_in() && current_user_can('manage_options'); }
    public static function member(int $org, int $user = 0): bool {
        global $wpdb;
        $user = $user ?: get_current_user_id();
        if ($org <= 0 || $user <= 0) { return false; }
        return (bool)$wpdb->get_var($wpdb->prepare('SELECT 1 FROM ' . DB::table('memberships') . ' m INNER JOIN ' . DB::table('organizations') . " o ON o.id=m.org_id WHERE m.org_id=%d AND m.user_id=%d AND m.status=%s AND o.status IN ('approved','active')", $org, $user, 'active'));
    }
    public static function can(string $cap, ?int $orgId = null, ?int $objectId = null): bool {
        if (!is_user_logged_in()) { return false; }
        if (self::agency()) { return true; }
        if (!current_user_can($cap)) { return false; }
        if ($orgId && !self::member($orgId)) { return false; }
        if ($objectId && get_post_meta($objectId, '_atlas_org_id', true) && (int)get_post_meta($objectId, '_atlas_org_id', true) !== (int)$orgId) { return false; }
        return true;
    }
    public static function require(string $cap, ?int $orgId = null, ?int $objectId = null): true|\WP_Error {
        return self::can($cap,$orgId,$objectId) ? true : new \WP_Error('atlas_forbidden', __('You do not have access to this record.', 'atlas-core'), ['status'=>403]);
    }
}
