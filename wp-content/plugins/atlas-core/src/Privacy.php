<?php
namespace Atlas\Core;
if (!defined('ABSPATH')) { exit; }
final class Privacy {
    public static function register(): void {
        add_filter('wp_privacy_personal_data_exporters',static function(array $exporters): array { $exporters['atlas-bookings']=['exporter_friendly_name'=>__('Atlas bookings','atlas-core'),'callback'=>[self::class,'export']]; return $exporters; });
        add_filter('wp_privacy_personal_data_erasers',static function(array $erasers): array { $erasers['atlas-bookings']=['eraser_friendly_name'=>__('Atlas bookings','atlas-core'),'callback'=>[self::class,'erase']]; return $erasers; });
        add_filter('cron_schedules',static function(array $s): array { $s['atlas_minute']=['interval'=>60,'display'=>__('Every minute','atlas-core')]; return $s; });
        add_action('atlas_core_expire_holds',[Jobs::class,'run']);
        if (!wp_next_scheduled('atlas_core_expire_holds')) { wp_schedule_event(time()+60,'atlas_minute','atlas_core_expire_holds'); }
    }
    public static function export(string $email,int $page=1): array {
        global $wpdb; $u=get_user_by('email',$email); if (!$u) { return ['data'=>[],'done'=>true]; }
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,status,amount_irr,created_at FROM '.DB::table('bookings').' WHERE user_id=%d ORDER BY id LIMIT 50 OFFSET %d',$u->ID,max(0,$page-1)*50),ARRAY_A);
        $data=[]; foreach ($rows as $row) { $data[]=['group_id'=>'atlas-bookings','group_label'=>__('Atlas bookings','atlas-core'),'item_id'=>'booking-'.$row['id'],'data'=>[['name'=>__('Booking ID','atlas-core'),'value'=>$row['id']],['name'=>__('Status','atlas-core'),'value'=>$row['status']],['name'=>__('Amount (IRR)','atlas-core'),'value'=>(string)$row['amount_irr']],['name'=>__('Created (UTC)','atlas-core'),'value'=>$row['created_at']]]]; }
        $travelers=$wpdb->get_results($wpdb->prepare('SELECT id,payload FROM '.DB::table('records').' WHERE type=%s AND org_id=0 AND owner_id=%d ORDER BY id LIMIT 50 OFFSET %d','traveler',$u->ID,max(0,$page-1)*50),ARRAY_A);
        foreach ($travelers as $traveler) {
            $fields=json_decode($traveler['payload'],true) ?: []; $items=[];
            foreach (['name'=>__('Name','atlas-core'),'birth_date'=>__('Birth date','atlas-core'),'guardian_name'=>__('Guardian name','atlas-core'),'guardian_attested'=>__('Guardian self-attestation','atlas-core')] as $key=>$label) {
                if (array_key_exists($key,$fields)) { $items[]=['name'=>$label,'value'=>is_bool($fields[$key]) ? ($fields[$key] ? 'yes' : 'no') : (string)$fields[$key]]; }
            }
            $data[]=['group_id'=>'atlas-travelers','group_label'=>__('Atlas travelers','atlas-core'),'item_id'=>'traveler-'.$traveler['id'],'data'=>$items];
        }
        return ['data'=>$data,'done'=>count($rows)<50 && count($travelers)<50];
    }
    public static function erase(string $email,int $page=1): array {
        // Preserve financial/booking records until an approved retention and erasure policy exists.
        return ['items_removed'=>false,'items_retained'=>true,'messages'=>[__('Atlas booking and financial records require manual retention review.','atlas-core')],'done'=>true];
    }
}
