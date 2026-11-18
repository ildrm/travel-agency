<?php
if (!defined('ABSPATH')) { exit; }

/** Native WordPress editing for reviewed localized guides and editorial pages. */
final class Atlas_Editorial {
    private const TYPE='atlas_guide';
    private const DETAILS='_atlas_editorial_details';
    private const REVIEW='_atlas_editorial_review';

    public static function register(): void {
        register_post_type(self::TYPE,array(
            'labels'=>array('name'=>__('Travel guides','atlas-operations'),'singular_name'=>__('Travel guide','atlas-operations')),
            'public'=>true,'show_in_rest'=>true,'has_archive'=>false,'rewrite'=>array('slug'=>'guides','with_front'=>false),
            'supports'=>array('title','editor','excerpt','revisions','author','thumbnail'),'taxonomies'=>array('category','post_tag'),
        ));
        add_filter('wp_insert_post_data',array(__CLASS__,'protect_publication'),20,2);
        add_action('add_meta_boxes_'.self::TYPE,array(__CLASS__,'meta_boxes'));
        add_action('save_post_'.self::TYPE,array(__CLASS__,'save_details'));
        add_action('admin_post_atlas_editorial_review',array(__CLASS__,'form_review'));
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_action('wp',array(__CLASS__,'frontend'));
        add_action('wp_head',array(__CLASS__,'metadata'));
        add_filter('wp_robots',array(__CLASS__,'robots'));
        add_filter('wp_sitemaps_posts_query_args',array(__CLASS__,'sitemap'),10,2);
        add_filter('wp_sitemaps_posts_entry',array(__CLASS__,'sitemap_entry'),10,3);
        add_filter('old_slug_redirect_url',array(__CLASS__,'redirect_url'));
        add_filter('atlas_operations_dashboard',array(__CLASS__,'dashboard'),40,2);
    }

    public static function routes(): void {
        register_rest_route('agency/v1','/editorial/(?P<id>\d+)/review',array(
            'methods'=>'POST','permission_callback'=>static function($request) {
                $permission=Atlas_Partner_Security::permission($request);
                return is_wp_error($permission)?$permission:current_user_can('manage_options');
            },'callback'=>static function($request) {
                $b=$request->get_json_params();
                if (!is_array($b) || array_diff(array_keys($b),array('fingerprint','reference','decision'))) return self::error('Review details are required.',422);
                return self::review((int)$request['id'],$b['fingerprint']??null,$b['reference']??null,$b['decision']??null);
            },
        ));
    }

    private static function t($en,$fa) { return Atlas_Partner_Security::t($en,$fa); }
    private static function error($message,$status) { return new WP_Error('atlas_editorial_error',$message,array('status'=>$status)); }
    private static function details(int $id): array { return (array)get_post_meta($id,self::DETAILS,true); }
    private static function date_valid($value): bool {
        $date=is_string($value)?DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC')):false;
        return $date && $date->format('Y-m-d')===$value && $value<=gmdate('Y-m-d');
    }

    public static function fingerprint(WP_Post $post): string {
        return self::hash_fields($post->ID,(array)$post);
    }
    private static function hash_fields(int $id,array $post): string {
        return hash('sha256',wp_json_encode(array(
            $post['post_title']??'',$post['post_content']??'',$post['post_excerpt']??'',
            (int)($post['post_author']??0),$post['post_password']??'',self::details($id),
        )));
    }
    private static function reviewed(int $id,string $fingerprint): bool {
        $review=get_post_meta($id,self::REVIEW,true);
        return is_array($review) && !empty($review['reviewed_by']) && hash_equals((string)$review['fingerprint'],$fingerprint);
    }
    public static function protect_publication(array $data,array $post): array {
        if (($data['post_type']??'')!==self::TYPE || !in_array($data['post_status']??'',array('publish','future'),true)) return $data;
        $id=(int)($post['ID']??0);
        if (!$id || !self::reviewed($id,self::hash_fields($id,wp_unslash($data)))) $data['post_status']='draft';
        return $data;
    }
    public static function review(int $id,$fingerprint,$reference,$decision) {
        $post=get_post($id);
        if (!current_user_can('manage_options') || !current_user_can('edit_post',$id)) return self::error('Agency review required.',403);
        if (!$post || $post->post_type!==self::TYPE || !in_array($post->post_status,array('draft','pending','publish'),true)) return self::error('Editorial record unavailable.',404);
        if (!is_string($fingerprint) || !hash_equals(self::fingerprint($post),$fingerprint)) return self::error('Content changed. Review the current revision.',409);
        if (!is_string($reference) || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$reference) || !in_array($decision,array('publish','withdraw'),true)) return self::error('A decision and its evidence reference are required.',422);
        $details=self::details($id);
        if ($decision==='publish' && (!in_array($details['language']??'',array('en','fa'),true) || !self::date_valid($details['verified_at']??null) || empty($details['source_reference']) || empty(trim($post->post_title)) || empty(trim($post->post_content)) || empty(trim($post->post_excerpt)) || $post->post_password!=='')) return self::error('Language, title, body, summary and verified source are required.',422);
        $prior=get_post_meta($id,self::REVIEW,true);
        if ($decision==='publish') {
            update_post_meta($id,self::REVIEW,array('fingerprint'=>$fingerprint,'reference'=>$reference,'reviewed_by'=>get_current_user_id(),'reviewed_at'=>gmdate('c')));
        } else {
            delete_post_meta($id,self::REVIEW);
        }
        $saved=wp_update_post(array('ID'=>$id,'post_status'=>$decision==='publish'?'publish':'draft'),true);
        $expected=$decision==='publish'?'publish':'draft';
        if (is_wp_error($saved) || get_post_status($id)!==$expected) {
            if ($prior) update_post_meta($id,self::REVIEW,$prior); else delete_post_meta($id,self::REVIEW);
            return self::error('Could not record the decision. Reload before retrying.',409);
        }
        \Atlas\Core\DB::audit(get_current_user_id(),0,'editorial.'.$decision,(string)$id,'success',$reference);
        return Atlas_Partner_Security::response(array('id'=>$id,'status'=>$expected,'public_url'=>$expected==='publish'?self::url(get_post($id)):null));
    }

    public static function meta_boxes(): void {
        add_meta_box('atlas-guide-details',__('Translation and source review','atlas-operations'),array(__CLASS__,'edit_details'),self::TYPE,'normal','default');
    }
    public static function edit_details(WP_Post $post): void {
        $details=self::details($post->ID);
        wp_nonce_field('atlas_guide_details_'.$post->ID,'atlas_guide_nonce');
        echo '<p><label>'.esc_html(self::t('Content language','زبان محتوا')).' <select name="atlas_guide[language]"><option value="en"'.selected($details['language']??'','en',false).'>English</option><option value="fa"'.selected($details['language']??'','fa',false).'>فارسی</option></select></label></p>';
        foreach (array('alternate'=>array(self::t('Corresponding guide ID','شناسه راهنمای ترجمه‌شده'),'number'),'source_reference'=>array(self::t('Reviewed source reference','مرجع منبع بررسی‌شده'),'text'),'verified_at'=>array(self::t('Source verification date','تاریخ راستی‌آزمایی منبع'),'date')) as $key=>$field) {
            echo '<p><label>'.esc_html($field[0]).' <input type="'.esc_attr($field[1]).'" name="atlas_guide['.esc_attr($key).']" value="'.esc_attr($details[$key]??'').'"></label></p>';
        }
    }
    public static function save_details(int $id): void {
        if (wp_is_post_revision($id) || wp_is_post_autosave($id) || !current_user_can('edit_post',$id) || !is_string($_POST['atlas_guide_nonce']??null) || !wp_verify_nonce(wp_unslash($_POST['atlas_guide_nonce']),'atlas_guide_details_'.$id) || !is_array($_POST['atlas_guide']??null)) return;
        $data=wp_unslash($_POST['atlas_guide']);
        if (!in_array($data['language']??'',array('en','fa'),true) || !is_string($data['source_reference']??null) || !is_string($data['verified_at']??null)) return;
        $alternate=is_scalar($data['alternate']??null)?absint($data['alternate']):0;
        $details=array('language'=>$data['language'],'alternate'=>$alternate===$id?0:$alternate,'source_reference'=>sanitize_text_field(substr($data['source_reference'],0,128)),'verified_at'=>self::date_valid($data['verified_at'])?$data['verified_at']:'');
        if ($details===self::details($id)) return;
        update_post_meta($id,self::DETAILS,$details);
        delete_post_meta($id,self::REVIEW);
        if (get_post_status($id)==='publish') wp_update_post(array('ID'=>$id,'post_status'=>'draft'));
    }

    private static function published($post): bool {
        return $post instanceof WP_Post && $post->post_type===self::TYPE && $post->post_status==='publish' && $post->post_password==='' && self::reviewed($post->ID,self::fingerprint($post));
    }
    public static function url(WP_Post $post): string {
        return add_query_arg('lang',self::details($post->ID)['language']??'en',get_permalink($post));
    }
    public static function alternate(WP_Post $post): ?WP_Post {
        $fields=self::details($post->ID);
        if (empty($fields['alternate'])) return null;
        $other=get_post((int)$fields['alternate']);
        if (!self::published($other)) return null;
        $other_fields=self::details($other->ID);
        return (int)($other_fields['alternate']??0)===$post->ID && ($other_fields['language']??'')!==($fields['language']??'')?$other:null;
    }
    public static function frontend(): void {
        if (!is_singular(self::TYPE) || is_preview()) return;
        $post=get_queried_object();
        if (!self::published($post)) {
            global $wp_query;
            $wp_query->set_404(); status_header(404); nocache_headers();
            return;
        }
        $language=self::details($post->ID)['language'];
        add_filter('atlas_editorial_language',static function() use($language){ return $language; });
        add_filter('atlas_editorial_language_urls',static function($urls) use($post,$language){
            $urls=array($language=>self::url($post)); $other=self::alternate($post);
            if ($other) $urls[self::details($other->ID)['language']]=self::url($other);
            return $urls;
        });
        add_filter('get_canonical_url',static function($url,$canonical) use($post) { return (int)$canonical->ID===(int)$post->ID?self::url($post):$url; },10,2);
    }
    public static function metadata(): void {
        if (!is_singular(self::TYPE) || is_preview()) return;
        $post=get_queried_object(); if (!self::published($post)) return;
        echo '<meta name="description" content="'.esc_attr(wp_strip_all_tags($post->post_excerpt)).'">'."\n";
        $other=self::alternate($post);
        if ($other) foreach (array($post,$other) as $translation) {
            echo '<link rel="alternate" hreflang="'.esc_attr(self::details($translation->ID)['language']).'" href="'.esc_url(self::url($translation)).'">'."\n";
        }
    }
    public static function robots(array $robots): array {
        $catalog_filters=array_filter(array_keys($_GET),static function($key){return is_string($key) && str_starts_with($key,'cat_');});
        if (is_search() || is_preview() || isset($_GET['atlas_area']) || isset($_GET['atlas_kind']) || $catalog_filters || (function_exists('is_cart') && (is_cart() || is_checkout() || is_account_page()))) {
            $robots['noindex']=true;
        }
        return $robots;
    }
    public static function sitemap(array $args,string $type): array {
        if ($type===self::TYPE) $args['meta_query']=array(array('key'=>self::REVIEW,'compare'=>'EXISTS'));
        return $args;
    }
    public static function sitemap_entry(array $entry,WP_Post $post,string $type): array {
        if ($type===self::TYPE && self::published($post)) $entry['loc']=self::url($post);
        return $entry;
    }
    public static function redirect_url($url) {
        if (!is_string($url) || !$url) return $url;
        $post=get_post(url_to_postid($url));
        return self::published($post)?self::url($post):$url;
    }
    public static function dashboard(string $html,bool $customer): string {
        if ($customer || !current_user_can('manage_options')) return $html;
        $html.='<details><summary>'.esc_html(self::t('Review localized guides','بازبینی راهنماهای ترجمه‌شده')).'</summary><a href="'.esc_url(admin_url('edit.php?post_type='.self::TYPE)).'">'.esc_html(self::t('Edit guides in WordPress','ویرایش راهنماها در وردپرس')).'</a>';
        $posts=get_posts(array('post_type'=>self::TYPE,'post_status'=>array('draft','pending','publish'),'numberposts'=>30));
        foreach ($posts as $post) {
            $details=self::details($post->ID);
            $html.='<article><h3>'.esc_html($post->post_title).'</h3><p>'.esc_html(($details['language']??'').' · '.($details['source_reference']??'').' · '.($details['verified_at']??'')).'</p><p>'.esc_html($post->post_excerpt).'</p><a href="'.esc_url(get_edit_post_link($post->ID,'raw')).'">'.esc_html(self::t('Review full content','بازبینی متن کامل')).'</a>';
            $html.=Atlas_Partner_Security::form_start('atlas_editorial_review').'<input type="hidden" name="id" value="'.esc_attr((string)$post->ID).'"><input type="hidden" name="fingerprint" value="'.esc_attr(self::fingerprint($post)).'">';
            $html.=Atlas_Partner_Security::input('reference',self::t('Review evidence reference','مرجع شواهد بازبینی')).'<button name="decision" value="publish">'.esc_html(self::t('Approve and publish','تأیید و انتشار')).'</button><button name="decision" value="withdraw">'.esc_html(self::t('Withdraw guide','لغو انتشار راهنما')).'</button></form></article>';
        }
        return $html.'</details>';
    }
    public static function form_review(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden','',array('response'=>403));
        check_admin_referer('atlas_editorial_review');
        $result=self::review(absint(Atlas_Partner_Security::post('id')),Atlas_Partner_Security::post('fingerprint'),Atlas_Partner_Security::post('reference'),Atlas_Partner_Security::post('decision'));
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()),'',array('response'=>(int)($result->get_error_data()['status']??409)));
        wp_safe_redirect(wp_get_referer()?:home_url('/')); exit;
    }
}
add_action('atlas_operations_ready',array('Atlas_Editorial','register'));
