<?php
if (!defined('ABSPATH')) { exit; }
use Atlas\Core\DB;

/** Minimal owner portability through WordPress's confirmed privacy-request workflow. */
final class Atlas_Private_File_Privacy {
    private const LIMIT=50;
    public static function register() {
        add_filter('wp_privacy_personal_data_exporters',static function($exporters){$exporters['atlas-private-files']=array('exporter_friendly_name'=>__('Atlas private document metadata','atlas-operations'),'callback'=>array(__CLASS__,'export'));return $exporters;});
        add_filter('wp_privacy_personal_data_erasers',static function($erasers){$erasers['atlas-private-files']=array('eraser_friendly_name'=>__('Atlas private document erasure review','atlas-operations'),'callback'=>array(__CLASS__,'erase'));return $erasers;});
    }
    private static function error($message) { return new WP_Error('atlas_private_privacy_denied',__($message,'atlas-operations')); }
    private static function text($en,$fa,$user) { return $user && str_starts_with(get_user_locale($user),'fa')?$fa:$en; }
    /** Core passes only email/page; bind them back to the privileged confirmed request. */
    private static function identity($email,$action) {
        if (!is_string($email) || strlen($email)>254 || !is_email($email) || !is_user_logged_in()) return self::error('A confirmed WordPress privacy request is required.');
        $cap=$action==='export_personal_data'?'export_others_personal_data':'erase_others_personal_data';
        if (!current_user_can($cap) || ($action==='remove_personal_data' && !current_user_can('delete_users'))) return self::error('WordPress privacy operator authority is required.');
        $id=$_POST['id']??null; $nonce=$_POST['security']??null;
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int)$id<1 || !is_string($nonce)) return self::error('The privacy request and its security token are required.');
        $id=(int)$id; $prefix=$action==='export_personal_data'?'wp-privacy-export-personal-data-':'wp-privacy-erase-personal-data-';
        if (!wp_verify_nonce(wp_unslash($nonce),$prefix.$id)) return self::error('The privacy request security token is invalid.');
        $request=wp_get_user_request($id);
        if (!$request || $request->action_name!==$action || !in_array($request->status,array('request-confirmed','request-completed'),true) || strcasecmp($request->email,$email)!==0) return self::error('Confirm the matching privacy request before processing private documents.');
        $user=get_user_by('email',$email);
        if ($request->user_id>0 && (!$user || (int)$request->user_id!==(int)$user->ID)) return self::error('The privacy request no longer matches the original account.');
        if (!class_exists('Atlas_Private_Files') || get_option('atlas_private_files_schema')!=='1' || DB::ready()!==true) return self::error('Private document metadata is unavailable.');
        return array('user'=>$user?:null,'request'=>$id);
    }
    private static function page($page) {
        return (is_int($page) || (is_string($page) && ctype_digit($page))) && (int)$page>0 && (int)$page<=10000?(int)$page:false;
    }
    public static function export($email,$page=1) {
        global $wpdb; $identity=self::identity($email,'export_personal_data'); if (is_wp_error($identity)) return $identity;
        $page=self::page($page); if (!$page) return self::error('Invalid private document export page.');
        $user=$identity['user']; if (!$user) return array('data'=>array(),'done'=>true);
        // No scope/membership OR condition: an organization colleague is not the owner.
        // Deliberately omit content, paths, keys, filenames, digest, uploader and context references.
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,mime,size_bytes,state,created_at,expires_at,legal_hold,erasure_requested FROM '.Atlas_Private_Files::table().' WHERE owner_id=%d ORDER BY id LIMIT %d OFFSET %d',$user->ID,self::LIMIT+1,($page-1)*self::LIMIT),ARRAY_A);
        if ($wpdb->last_error!=='' || !is_array($rows) || !DB::audit(get_current_user_id(),0,'private.privacy_export',(string)$user->ID,'read','privacy_request:'.$identity['request'].':page:'.$page)) return self::error('Private document export could not be audited.');
        $data=array();
        foreach (array_slice($rows,0,self::LIMIT) as $row) {
            $values=array(
                array('name'=>self::text('Document reference','مرجع سند',$user),'value'=>$row['id']),
                array('name'=>self::text('Media type','نوع رسانه',$user),'value'=>$row['mime']),
                array('name'=>self::text('Size in bytes','اندازه به بایت',$user),'value'=>(string)$row['size_bytes']),
                array('name'=>self::text('Storage state','وضعیت نگهداری',$user),'value'=>$row['state']),
                array('name'=>self::text('Created at UTC','زمان ثبت به UTC',$user),'value'=>$row['created_at']),
                array('name'=>self::text('Retention ends at UTC','پایان نگهداری به UTC',$user),'value'=>$row['expires_at']),
                array('name'=>self::text('Legal hold recorded','منع قانونی حذف ثبت شده',$user),'value'=>$row['legal_hold']?'yes':'no'),
                array('name'=>self::text('Erasure review requested','درخواست بررسی حذف',$user),'value'=>$row['erasure_requested']?'yes':'no'),
                array('name'=>self::text('Document access','دسترسی به سند',$user),'value'=>self::text('Use the normal private attachment download with current object permission. File bytes are not placed in this archive.','برای دریافت فایل از پیوست خصوصی با مجوز فعلی استفاده کنید. محتوای فایل در این بایگانی قرار نمی‌گیرد.',$user)),
            );
            $data[]=array('group_id'=>'atlas-private-files','group_label'=>self::text('Private document metadata','فراداده اسناد خصوصی',$user),'item_id'=>'private-document-'.$row['id'],'data'=>$values);
        }
        return array('data'=>$data,'done'=>count($rows)<=self::LIMIT);
    }
    public static function erase($email,$page=1) {
        global $wpdb; $identity=self::identity($email,'remove_personal_data'); if (is_wp_error($identity)) return $identity;
        $page=self::page($page); if (!$page) return self::error('Invalid private document erasure page.');
        $user=$identity['user']; if (!$user) return array('items_removed'=>false,'items_retained'=>false,'messages'=>array(),'done'=>true);
        $wpdb->query('START TRANSACTION');
        try {
            $rows=$wpdb->get_results($wpdb->prepare('SELECT id,org_id,state,version,expires_at,legal_hold,erasure_requested FROM '.Atlas_Private_Files::table().' WHERE owner_id=%d ORDER BY id LIMIT %d OFFSET %d FOR UPDATE',$user->ID,self::LIMIT+1,($page-1)*self::LIMIT));
            if ($wpdb->last_error!=='' || !is_array($rows)) throw new RuntimeException('metadata');
            $holds=0; $retention=0; $expired=0; $purged=0; $pending=0;
            foreach (array_slice($rows,0,self::LIMIT) as $row) {
                if ($row->state==='purged') { $purged++; continue; }
                $pending++; if ($row->legal_hold) $holds++; if ($row->expires_at>DB::now()) $retention++; else $expired++;
                if (!$row->erasure_requested) {
                    if ($wpdb->update(Atlas_Private_Files::table(),array('erasure_requested'=>1,'version'=>(int)$row->version+1,'updated_at'=>DB::now()),array('id'=>$row->id,'owner_id'=>$user->ID,'version'=>(int)$row->version))!==1 || !DB::audit(get_current_user_id(),(int)$row->org_id,'private.privacy_erasure_request',$row->id,'success','privacy_request:'.$identity['request'])) throw new RuntimeException('audit');
                }
            }
            $wpdb->query('COMMIT');
            $message=self::text('Erasure review is recorded for %1$d document(s). No file bytes were automatically deleted. %2$d are under legal hold; %3$d remain within retention; %4$d have expired and require manual purge review. %5$d already-purged audit tombstone(s) are retained.','درخواست بررسی حذف برای %1$d سند ثبت است. هیچ فایل به‌طور خودکار حذف نشد. %2$d سند منع قانونی حذف دارند؛ %3$d در دوره نگهداری هستند؛ %4$d منقضی شده و نیازمند بررسی دستی حذف هستند. %5$d سابقه حداقلی فایل قبلاً حذف‌شده نگهداری می‌شود.',$user);
            return array('items_removed'=>false,'items_retained'=>(bool)$rows,'messages'=>$rows?array(sprintf($message,$pending,$holds,$retention,$expired,$purged)):array(),'done'=>count($rows)<=self::LIMIT);
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); return self::error('Private document erasure review could not be recorded; no deletion was performed.'); }
    }
}
add_action('atlas_operations_ready',array('Atlas_Private_File_Privacy','register'));
