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
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)$/D', $value)) { return false; }
        try { $d=new \DateTimeImmutable($value); } catch (\Throwable $e) { return false; }
        $errors=\DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) { return false; }
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
        $keys=['kind','org_id','title_en','title_fa','description_en','description_fa','registration_open_at','registration_close_at','timezone','capacity','amount_irr','mode','status','product_id','eligibility','catalog'];
        foreach ($data as $key=>$unused) { if (!in_array($key,$keys,true)) { return new \WP_Error('atlas_invalid_field','Unknown offer field.', ['status'=>400]); } }
        $d=array_merge($old,$data); $d['org_id']=$org;
        if (!in_array($d['kind'] ?? '',self::KINDS,true) || !in_array($d['status'] ?? '',self::STATUSES,true) || !in_array($d['mode'] ?? '',['local','request_only'],true)) { return new \WP_Error('atlas_invalid_offer','Invalid offer type, mode or status.', ['status'=>422]); }
        if (in_array($d['status'],['approved','published'],true) && !Auth::agency()) { return new \WP_Error('atlas_forbidden','Agency approval required.', ['status'=>403]); }
        if ($d['kind']==='tour' && $d['status']==='published' && apply_filters('atlas_core_validate_tour_publication', false, $d, $id) !== true) { return new \WP_Error('atlas_tour_review_required','An agency-approved itinerary version is required before publication.', ['status'=>409]); }
        if ($d['kind']==='cohort' && $d['status']==='published' && apply_filters('atlas_core_validate_cohort_publication', false, $d, $id) !== true) { return new \WP_Error('atlas_cohort_review_required','An agency-approved course and cohort are required before publication.', ['status'=>409]); }
        if ($d['mode']==='local' && !in_array($d['kind'],['tour','event','cohort'],true)) {
            if (!in_array($d['kind'],['stay','rental'],true) || apply_filters('atlas_core_validate_resource_publication',false,$d,$id,$actorId)!==true) return new \WP_Error('atlas_unsupported_mode','A reviewed resource booking configuration is required.', ['status'=>422]);
        }
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
        $previousProduct=(int)($old['product_id']??0);
        $productId=$d['product_id'];
        if ($productId!==$previousProduct && !Auth::agency()) return new \WP_Error('atlas_forbidden','Agency authority is required to link a WooCommerce product.', ['status'=>403]);
        $catalog=$d['catalog']??[];
        if (!array_key_exists('catalog',$data) && is_array($catalog)) { unset($catalog['_search'],$catalog['_destinations'],$catalog['updated_at']); }
        $catalog=Catalog::metadata($catalog,$d);if (is_wp_error($catalog)) return $catalog;$d['catalog']=$catalog;
        $d['version']=(int)($old['version'] ?? 0)+1; $d['currency']='IRR';
        $wpdb->query('START TRANSACTION');
        try {
            if ($id) {
                $inventory=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::table('inventory').' WHERE offer_id=%d FOR UPDATE',$id));
                if (!$inventory || (int)$inventory->version !== $expectedVersion || (int)$inventory->version !== (int)($old['version'] ?? 0)) { $wpdb->query('ROLLBACK'); return new \WP_Error('atlas_conflict','Offer changed; reload before editing.', ['status'=>409]); }
                if ($d['capacity'] < (int)$inventory->allocated) { $wpdb->query('ROLLBACK'); return new \WP_Error('atlas_capacity_conflict','Capacity is below allocated inventory.', ['status'=>409]); }
                $onlySuspend = count($data) === 1 && ($data['status'] ?? '') === 'suspended';
                if ((int)$inventory->allocated > 0 && !$onlySuspend) { $wpdb->query('ROLLBACK'); return new \WP_Error('atlas_impact_review_required','Allocated bookings require impact review before changing this offer.', ['status'=>409]); }
                if ($productId!==$previousProduct && $wpdb->get_var($wpdb->prepare('SELECT id FROM '.DB::table('bookings').' WHERE offer_id=%d LIMIT 1',$id))) { $wpdb->query('ROLLBACK'); return new \WP_Error('atlas_product_history','An offer with booking history must keep its WooCommerce product.', ['status'=>409]); }
                $result=wp_update_post(['ID'=>$id,'post_title'=>$d['title_en'] ?: $d['title_fa']],true);
            } else {
                $result=wp_insert_post(['post_type'=>'atlas_offer','post_status'=>'private','post_title'=>$d['title_en'] ?: $d['title_fa'],'post_author'=>$actorId],true);
            }
            if (is_wp_error($result) || !$result) { throw new \RuntimeException('offer write'); }
            $id=(int)$result;
            $binding=$productId!==$previousProduct || $d['status']==='published'?self::bind_product($productId,$previousProduct,$id):true;
            if (is_wp_error($binding)) {
                $wpdb->query('ROLLBACK'); clean_post_cache($id); self::clear_product_cache($productId,$previousProduct);
                return $binding;
            }
            update_post_meta($id,'_atlas_data',$d); update_post_meta($id,'_atlas_org_id',$org);
            $existing=$wpdb->get_var($wpdb->prepare('SELECT offer_id FROM '.DB::table('inventory').' WHERE offer_id=%d',$id));
            $ok=$existing ? $wpdb->update(DB::table('inventory'),['capacity'=>$d['capacity'],'version'=>$d['version'],'kind'=>$d['kind'],'status'=>$d['status'],'offer_snapshot'=>wp_json_encode($d)],['offer_id'=>$id]) : $wpdb->insert(DB::table('inventory'),['offer_id'=>$id,'capacity'=>$d['capacity'],'allocated'=>0,'version'=>$d['version'],'kind'=>$d['kind'],'status'=>$d['status'],'offer_snapshot'=>wp_json_encode($d)]);
            if ($ok===false || !DB::audit($actorId,$org,'offer.save',(string)$id,'success')) { throw new \RuntimeException('inventory write'); }
            $extension=apply_filters('atlas_core_offer_saved',true,$id,$d,$actorId);
            if ($extension!==true) { $wpdb->query('ROLLBACK'); clean_post_cache($id); self::clear_product_cache($productId,$previousProduct); return is_wp_error($extension)?$extension:new \WP_Error('atlas_offer_binding_failed','Offer could not be linked to its reviewed source.', ['status'=>409]); }
            if ($wpdb->query('COMMIT')===false) throw new \RuntimeException('commit');
            self::clear_product_cache($productId,$previousProduct);
            return $id;
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); if ($id) clean_post_cache($id); self::clear_product_cache($productId,$previousProduct); return new \WP_Error('atlas_storage_error','Offer could not be saved.', ['status'=>503]); }
    }
    /** Called inside the offer transaction. Lock products in ID order before editing link metadata. */
    private static function bind_product(int $productId,int $previousProduct,int $offerId): true|\WP_Error {
        global $wpdb;
        $products=array_unique(array_filter([$productId,$previousProduct]));
        sort($products,SORT_NUMERIC);
        foreach ($products as $id) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID=%d FOR UPDATE",$id));
        if ($productId) {
            $product=function_exists('wc_get_product')?wc_get_product($productId):false;
            if (!$product || get_post_type($productId)!=='product' || !$product->is_virtual() || !$product->is_type('simple')) return new \WP_Error('atlas_invalid_service_product','Select a virtual simple WooCommerce product for this service.', ['status'=>422]);
            $links=$wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s FOR UPDATE",$productId,'_atlas_offer_id'));
            foreach ($links as $id) if ((int)$id!==$offerId) return new \WP_Error('atlas_product_in_use','The WooCommerce product already belongs to another offer.', ['status'=>409]);
            if (count($links)>1) return new \WP_Error('atlas_product_conflict','The WooCommerce product link requires review.', ['status'=>409]);
            if (!$links) {
                // Woo uses these same line-item tables for both HPOS and classic order storage.
                $items=$wpdb->prefix.'woocommerce_order_items';
                $itemMeta=$wpdb->prefix.'woocommerce_order_itemmeta';
                $history=$wpdb->get_var($wpdb->prepare("SELECT m.meta_id FROM $itemMeta m INNER JOIN $items i ON i.order_item_id=m.order_item_id AND i.order_item_type='line_item' WHERE m.meta_key='_product_id' AND m.meta_value=%s LIMIT 1",(string)$productId));
                if ($wpdb->last_error!=='') throw new \RuntimeException('product history');
                if ($history) return new \WP_Error('atlas_product_history','Select a dedicated product with no existing WooCommerce order history.', ['status'=>409]);
                if (!add_post_meta($productId,'_atlas_offer_id',$offerId,true)) throw new \RuntimeException('product link');
            }
        }
        if ($previousProduct && $previousProduct!==$productId) delete_post_meta($previousProduct,'_atlas_offer_id',$offerId);
        return true;
    }
    private static function clear_product_cache(int $current,int $previous): void {
        foreach (array_unique(array_filter([$current,$previous])) as $id) wp_cache_delete($id,'post_meta');
    }
    public static function projection(int $id,array $d): array {
        $d['catalog']=Catalog::public_metadata(is_array($d['catalog']??null)?$d['catalog']:[]);
        return array_intersect_key(['id'=>$id,'requires_travelers'=>!empty($d['eligibility'])]+$d,array_flip(['id','kind','org_id','title_en','title_fa','description_en','description_fa','registration_open_at','registration_close_at','timezone','capacity','amount_irr','currency','version','mode','status','product_id','eligibility','requires_travelers','catalog']));
    }
    public static function get(int $id, bool $public = true): array|\WP_Error {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT offer_snapshot FROM '.DB::table('inventory').' WHERE offer_id=%d',$id));
        $d=$row ? json_decode($row->offer_snapshot,true) : null;
        if (!is_array($d) || get_post_type($id)!=='atlas_offer' || get_post_status($id)!=='private' || ($public && $d['status']!=='published')) { return new \WP_Error('atlas_not_found','Offer not found.', ['status'=>404]); }
        if (!$public && !Auth::can('atlas_manage_offers',(int)$d['org_id'] ?: null,$id,'read')) { return new \WP_Error('atlas_not_found','Offer not found.', ['status'=>404]); }
        if ($public && apply_filters('atlas_public_offer_visible',true,$id,$d)!==true) { return new \WP_Error('atlas_not_found','Offer not found.', ['status'=>404]); }
        return self::projection($id,$d);
    }
    public static function listing(array $filters = []): array|\WP_Error {
        $result=Catalog::search($filters);return is_wp_error($result)?$result:$result['items'];
    }
}
