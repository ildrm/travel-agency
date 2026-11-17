<?php
namespace Atlas\Core;
if (!defined('ABSPATH')) { exit; }
final class Offers {
    private const KINDS=['tour','event','cohort','stay','rental','transfer','flight','rail','bus'];
    private const STATUSES=['draft','submitted','approved','published','suspended'];
    public static function register_types(): void {
        foreach (['atlas_offer'=>'Offers','atlas_tour'=>'Tours','atlas_course'=>'Courses','atlas_property'=>'Properties','atlas_vehicle'=>'Vehicles'] as $type=>$label) {
            register_post_type($type, ['label'=>__($label,'atlas-core'),'public'=>false,'show_ui'=>false,'show_in_rest'=>false,'supports'=>['title']]);
        }
    }
    private static function utc(string $value): string|false {
        try { $d=new \DateTimeImmutable($value); } catch (\Throwable $e) { return false; }
        if (!preg_match('/(?:Z|[+-]00:00)$/', $value)) { return false; }
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
    public static function save(int $id, array $data, int $actorId): int|\WP_Error {
        global $wpdb;
        if (!Auth::actor($actorId)) { return new \WP_Error('atlas_forbidden','Access denied.', ['status'=>403]); }
        $expectedVersion=$data['expected_version'] ?? null; unset($data['expected_version']);
        if ($id && (!is_int($expectedVersion) || $expectedVersion < 1)) { return new \WP_Error('atlas_version_required','Expected offer version is required.', ['status'=>409]); }
        $old=$id ? get_post_meta($id,'_atlas_data',true) : [];
        if ($id && (get_post_type($id)!=='atlas_offer' || !is_array($old))) { return new \WP_Error('atlas_not_found','Offer not found.', ['status'=>404]); }
        $org=(int)($data['org_id'] ?? ($old['org_id'] ?? 0));
        if ($id && (int)($old['org_id'] ?? 0)!==$org) { return new \WP_Error('atlas_forbidden','Cannot transfer ownership here.', ['status'=>403]); }
        if (!Auth::can('atlas_manage_offers',$org ?: null, $id ?: null)) { return new \WP_Error('atlas_forbidden','Access denied.', ['status'=>403]); }
        $keys=['kind','org_id','title_en','title_fa','description_en','description_fa','registration_open_at','registration_close_at','timezone','capacity','amount_irr','mode','status','product_id','eligibility'];
        foreach ($data as $key=>$unused) { if (!in_array($key,$keys,true)) { return new \WP_Error('atlas_invalid_field','Unknown offer field.', ['status'=>400]); } }
        $d=array_merge($old,$data); $d['org_id']=$org;
        if (!in_array($d['kind'] ?? '',self::KINDS,true) || !in_array($d['status'] ?? '',self::STATUSES,true) || !in_array($d['mode'] ?? '',['local','request_only'],true)) { return new \WP_Error('atlas_invalid_offer','Invalid offer type, mode or status.', ['status'=>422]); }
        if (in_array($d['status'],['approved','published'],true) && !Auth::agency()) { return new \WP_Error('atlas_forbidden','Agency approval required.', ['status'=>403]); }
        if ($d['kind']==='tour' && $d['status']==='published' && apply_filters('atlas_core_validate_tour_publication', false, $d, $id) !== true) { return new \WP_Error('atlas_tour_review_required','An agency-approved itinerary version is required before publication.', ['status'=>409]); }
        if ($d['kind']==='cohort' && $d['status']==='published' && apply_filters('atlas_core_validate_cohort_publication', false, $d, $id) !== true) { return new \WP_Error('atlas_cohort_review_required','An agency-approved course and cohort are required before publication.', ['status'=>409]); }
        if ($d['mode']==='local' && !in_array($d['kind'],['tour','event','cohort'],true)) { return new \WP_Error('atlas_unsupported_mode','This service is request-only.', ['status'=>422]); }
        if (empty($d['title_en']) && empty($d['title_fa'])) { return new \WP_Error('atlas_invalid_offer','A localized title is required.', ['status'=>422]); }
        foreach (['title_en','title_fa','description_en','description_fa'] as $key) { $d[$key]=sanitize_textarea_field((string)($d[$key] ?? '')); if (strlen($d[$key])>10000) { return new \WP_Error('atlas_invalid_offer','Text is too long.', ['status'=>422]); } }
        if (isset($d['eligibility']) && (!is_array($d['eligibility']) || strlen(wp_json_encode($d['eligibility'])) > 5000)) { return new \WP_Error('atlas_invalid_eligibility','Invalid eligibility policy.', ['status'=>422]); }
        $d['eligibility']=$d['eligibility'] ?? [];
        if ($d['eligibility'] && !isset($d['eligibility']['guardian_below'])) { $d['eligibility']['guardian_below']=18; }
        $policy=Eligibility::policy($d['eligibility']); if (is_wp_error($policy)) { return $policy; }
        $d['timezone']=(string)($d['timezone'] ?? 'UTC'); if (!in_array($d['timezone'],timezone_identifiers_list(),true)) { return new \WP_Error('atlas_invalid_timezone','Invalid IANA time zone.', ['status'=>422]); }
        $open=self::utc((string)($d['registration_open_at'] ?? '')); $close=self::utc((string)($d['registration_close_at'] ?? ''));
        if ($d['mode']==='local' && (!$open || !$close || $open >= $close)) { return new \WP_Error('atlas_invalid_window','A valid UTC registration window is required.', ['status'=>422]); }
        if ($open) { $d['registration_open_at']=(new \DateTimeImmutable($open, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'); } else { $d['registration_open_at']=''; }
        if ($close) { $d['registration_close_at']=(new \DateTimeImmutable($close, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'); } else { $d['registration_close_at']=''; }
        foreach (['capacity','amount_irr','product_id'] as $key) {
            if (isset($d[$key]) && (!is_int($d[$key]) || $d[$key]<0)) { return new \WP_Error('atlas_invalid_amount','Amounts and capacity must be nonnegative integers.', ['status'=>422]); }
            $d[$key]=(int)($d[$key] ?? 0);
        }
        $d['version']=(int)($old['version'] ?? 0)+1; $d['currency']='IRR';
        $wpdb->query('START TRANSACTION');
        try {
            if ($id) {
                $inventory=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('inventory').' WHERE offer_id=%d FOR UPDATE',$id));
                if (!$inventory || (int)$inventory->version !== $expectedVersion || (int)$inventory->version !== (int)($old['version'] ?? 0)) { $wpdb->query('ROLLBACK'); return new \WP_Error('atlas_conflict','Offer changed; reload before editing.', ['status'=>409]); }
                if ($d['capacity'] < (int)$inventory->allocated) { $wpdb->query('ROLLBACK'); return new \WP_Error('atlas_capacity_conflict','Capacity is below allocated inventory.', ['status'=>409]); }
                $onlySuspend = count($data) === 1 && ($data['status'] ?? '') === 'suspended';
                if ((int)$inventory->allocated > 0 && !$onlySuspend) { $wpdb->query('ROLLBACK'); return new \WP_Error('atlas_impact_review_required','Allocated bookings require impact review before changing this offer.', ['status'=>409]); }
                $result=wp_update_post(['ID'=>$id,'post_title'=>$d['title_en'] ?: $d['title_fa']],true);
            } else {
                $result=wp_insert_post(['post_type'=>'atlas_offer','post_status'=>'private','post_title'=>$d['title_en'] ?: $d['title_fa'],'post_author'=>$actorId],true);
            }
            if (is_wp_error($result) || !$result) { throw new \RuntimeException('offer write'); }
            $id=(int)$result; update_post_meta($id,'_atlas_data',$d); update_post_meta($id,'_atlas_org_id',$org);
            $existing=$wpdb->get_var($wpdb->prepare('SELECT offer_id FROM '.DB::table('inventory').' WHERE offer_id=%d',$id));
            $ok=$existing ? $wpdb->update(DB::table('inventory'),['capacity'=>$d['capacity'],'version'=>$d['version'],'kind'=>$d['kind'],'status'=>$d['status'],'offer_snapshot'=>wp_json_encode($d)],['offer_id'=>$id]) : $wpdb->insert(DB::table('inventory'),['offer_id'=>$id,'capacity'=>$d['capacity'],'allocated'=>0,'version'=>$d['version'],'kind'=>$d['kind'],'status'=>$d['status'],'offer_snapshot'=>wp_json_encode($d)]);
            if ($ok===false || !DB::audit($actorId,$org,'offer.save',(string)$id,'success')) { throw new \RuntimeException('inventory write'); }
            $wpdb->query('COMMIT'); return $id;
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); return new \WP_Error('atlas_storage_error','Offer could not be saved.', ['status'=>503]); }
    }
    private static function projection(int $id,array $d): array {
        return array_intersect_key(['id'=>$id,'requires_travelers'=>!empty($d['eligibility'])]+$d,array_flip(['id','kind','org_id','title_en','title_fa','description_en','description_fa','registration_open_at','registration_close_at','timezone','capacity','amount_irr','currency','version','mode','status','product_id','eligibility','requires_travelers']));
    }
    public static function get(int $id, bool $public = true): array|\WP_Error {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT offer_snapshot FROM '.DB::table('inventory').' WHERE offer_id=%d',$id));
        $d=$row ? json_decode($row->offer_snapshot,true) : null;
        if (!is_array($d) || ($public && $d['status']!=='published')) { return new \WP_Error('atlas_not_found','Offer not found.', ['status'=>404]); }
        if (!$public && !Auth::can('atlas_manage_offers',(int)$d['org_id'] ?: null,$id)) { return new \WP_Error('atlas_not_found','Offer not found.', ['status'=>404]); }
        return self::projection($id,$d);
    }
    public static function listing(array $filters = []): array {
        global $wpdb;
        $limit=min(100,max(1,(int)($filters['limit'] ?? 20))); $offset=(max(1,(int)($filters['page'] ?? 1))-1)*$limit;
        if (!empty($filters['kind']) && in_array($filters['kind'],self::KINDS,true)) {
            $sql=$wpdb->prepare('SELECT offer_id,offer_snapshot FROM '.DB::table('inventory').' WHERE status=%s AND kind=%s ORDER BY offer_id DESC LIMIT %d OFFSET %d','published',$filters['kind'],$limit,$offset);
        } else { $sql=$wpdb->prepare('SELECT offer_id,offer_snapshot FROM '.DB::table('inventory').' WHERE status=%s ORDER BY offer_id DESC LIMIT %d OFFSET %d','published',$limit,$offset); }
        $out=[]; foreach ($wpdb->get_results($sql) as $row) { $d=json_decode($row->offer_snapshot,true); if (is_array($d) && $d['status']==='published') { $out[]=self::projection((int)$row->offer_id,$d); } }
        return $out;
    }
}
