<?php
namespace Atlas\Core;
if (!defined('ABSPATH')) { exit; }

/** Public discovery uses only reviewed offer snapshots, never arbitrary record payloads. */
final class Catalog {
    public const KINDS=['tour','event','cohort','stay','rental','transfer','flight','rail','bus'];
    public const LANGUAGES=['en','fa','ar','tr','de','fr','es','ru','zh','it'];
    public const ACCESSIBILITY=['step_free','accessible_toilet','hearing_support','visual_support','low_mobility','sign_language'];
    public const UNITS=['per_person','per_group','per_night','per_day','per_item','on_request'];
    private static function error(string $message,string $locale=''): \WP_Error { $locale=$locale?:((str_starts_with(determine_locale(),'fa'))?'fa':'en');return new \WP_Error('atlas_invalid_catalog',$locale==='fa'?'فیلترها یا اطلاعات فهرست معتبر نیست.':$message,['status'=>422]); }
    public static function normalize(string $text): string {
        $text=strtr($text,['ي'=>'ی','ى'=>'ی','ك'=>'ک',"\u{200C}"=>' ',"\u{200D}"=>' ',"\u{0640}"=>'','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        $text=preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u','',$text)??'';
        $text=function_exists('mb_strtolower')?mb_strtolower($text,'UTF-8'):strtolower($text);
        return trim(preg_replace('/\s+/u',' ',preg_replace('/[^\p{L}\p{N}\s]/u',' ',$text)??'')??'');
    }
    private static function utc($value): string|false {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/D',$value)) return false;
        try { $date=new \DateTimeImmutable($value);$errors=\DateTimeImmutable::getLastErrors();if (is_array($errors)&&($errors['warning_count']||$errors['error_count'])) return false;return $date->format('Y-m-d\TH:i:s.u\Z'); } catch (\Throwable $e) { return false; }
    }
    private static function list_of($value,array $allowed,int $limit=10): array|false {
        if (!is_array($value)||!array_is_list($value)||count($value)>$limit) return false;
        foreach ($value as $item) if (!is_string($item)||!in_array($item,$allowed,true)) return false;
        return array_values(array_unique($value));
    }
    public static function metadata($raw,array $offer): array|\WP_Error {
        $fields=['destinations','starts_at','ends_at','duration_minutes','languages','accessibility','accessibility_note_en','accessibility_note_fa','price_unit','inclusions_en','inclusions_fa','exclusions_en','exclusions_fa','terms_en','terms_fa','source_reference','reviewed_at','review_due_at'];
        if (!is_array($raw)||array_diff(array_keys($raw),$fields)) return self::error('Unknown catalog metadata field.');
        $m=['destinations'=>[],'starts_at'=>'','ends_at'=>'','duration_minutes'=>0,'languages'=>[],'accessibility'=>[],'price_unit'=>($offer['mode']??'')==='request_only'?'on_request':'per_person'];
        foreach (['accessibility_note_en','accessibility_note_fa','inclusions_en','inclusions_fa','exclusions_en','exclusions_fa','terms_en','terms_fa','source_reference','reviewed_at','review_due_at'] as $key) $m[$key]='';
        $m=array_merge($m,$raw);
        if (!is_array($m['destinations'])||!array_is_list($m['destinations'])||count($m['destinations'])>12) return self::error('Use at most twelve typed destinations.');
        $ids=[];foreach ($m['destinations'] as &$place) {
            if (!is_array($place)||array_diff(array_keys($place),['id','type','parent_id','country_code','name_en','name_fa'])||!is_string($place['id']??null)||!preg_match('/^[a-z0-9][a-z0-9-]{1,63}$/D',$place['id'])||isset($ids[$place['id']])||!in_array($place['type']??'', ['country','city','place'],true)||!is_string($place['country_code']??null)||!preg_match('/^[A-Z]{2}$/D',$place['country_code'])) return self::error('Invalid or duplicate destination identity.');
            $ids[$place['id']]=true;$place['parent_id']=$place['parent_id']??'';
            if (!is_string($place['parent_id'])||($place['parent_id']!==''&&(!preg_match('/^[a-z0-9][a-z0-9-]{1,63}$/D',$place['parent_id'])||$place['parent_id']===$place['id']))) return self::error('Invalid destination parent.');
            foreach (['name_en','name_fa'] as $key) { if (!is_string($place[$key]??'' )||strlen($place[$key]??'')>190) return self::error('Invalid destination label.');$place[$key]=sanitize_text_field($place[$key]??''); }
            if ($place['name_en']===''&&$place['name_fa']==='') return self::error('A destination needs a localized label.');
        } unset($place);
        $starts=$m['starts_at']===''?'':self::utc($m['starts_at']);$ends=$m['ends_at']===''?'':self::utc($m['ends_at']);
        if ($starts===false||$ends===false||(($starts==='')!==($ends===''))||($starts!==''&&$starts>=$ends)) return self::error('Use a valid increasing UTC service interval.');
        $m['starts_at']=$starts;$m['ends_at']=$ends;
        if (!is_int($m['duration_minutes'])||$m['duration_minutes']<0||$m['duration_minutes']>5256000) return self::error('Invalid duration.');
        if (!$m['duration_minutes']&&$starts) $m['duration_minutes']=(int)ceil((strtotime($ends)-strtotime($starts))/60);
        if ($m['duration_minutes']>5256000) return self::error('Service duration is too long.');
        $languages=self::list_of($m['languages'],self::LANGUAGES);$access=self::list_of($m['accessibility'],self::ACCESSIBILITY);
        if ($languages===false||$access===false||!in_array($m['price_unit'],self::UNITS,true)||(($offer['mode']??'')==='request_only'&&$m['price_unit']!=='on_request')||(($offer['mode']??'')==='local'&&$m['price_unit']==='on_request')) return self::error('Invalid catalog language, accessibility or price unit.');
        $m['languages']=$languages;$m['accessibility']=$access;
        foreach (['accessibility_note_en','accessibility_note_fa','inclusions_en','inclusions_fa','exclusions_en','exclusions_fa','terms_en','terms_fa'] as $key) { if (!is_string($m[$key])||strlen($m[$key])>3000) return self::error('Catalog text must be bounded plain text.');$m[$key]=sanitize_textarea_field($m[$key]); }
        if (!is_string($m['source_reference'])||($m['source_reference']!==''&&!preg_match('/^[A-Za-z0-9._:-]{8,120}$/D',$m['source_reference']))) return self::error('Use an opaque source reference, not private documents.');
        foreach (['reviewed_at','review_due_at'] as $key) { if ($m[$key]!=='') { $stamp=self::utc($m[$key]);if (!$stamp) return self::error('Invalid information review date.');$m[$key]=$stamp; } }
        $now=(new \DateTimeImmutable(DB::now(),new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        if (($m['reviewed_at']!==''&&$m['reviewed_at']>$now)||($m['review_due_at']!==''&&($m['reviewed_at']===''||$m['review_due_at']<=$m['reviewed_at']))) return self::error('Invalid information review interval.');
        if ($access&&($m['source_reference']===''||$m['reviewed_at']===''||($m['accessibility_note_en']===''&&$m['accessibility_note_fa']===''))) return self::error('Declared accessibility needs reviewed source and explanatory notes.');
        $m['updated_at']=$now;
        $publicText=implode(' ',array_map(static fn($key)=>(string)($offer[$key]??''),['title_en','title_fa','description_en','description_fa']));
        $destinationText='';foreach ($m['destinations'] as $place) $destinationText.=' '.$place['id'].' '.$place['parent_id'].' '.$place['country_code'].' '.$place['name_en'].' '.$place['name_fa'];
        $m['_destinations']=self::normalize($destinationText);$m['_search']=self::normalize($publicText.' '.$destinationText.' '.implode(' ',$languages));
        return $m;
    }
    public static function public_metadata(array $m): array {
        $out=[];foreach (['starts_at','ends_at','accessibility_note_en','accessibility_note_fa','price_unit','inclusions_en','inclusions_fa','exclusions_en','exclusions_fa','terms_en','terms_fa','reviewed_at','review_due_at','updated_at'] as $key) if (isset($m[$key])&&is_string($m[$key])) $out[$key]=$m[$key];
        $out['duration_minutes']=isset($m['duration_minutes'])&&is_int($m['duration_minutes'])?max(0,$m['duration_minutes']):0;
        foreach (['languages'=>self::LANGUAGES,'accessibility'=>self::ACCESSIBILITY] as $key=>$allowed) $out[$key]=is_array($m[$key]??null)?array_values(array_filter($m[$key],static fn($value)=>is_string($value)&&in_array($value,$allowed,true))):[];
        $out['destinations']=[];foreach (is_array($m['destinations']??null)?array_slice($m['destinations'],0,12):[] as $place) { if (!is_array($place)) continue;$clean=[];foreach (['id','type','parent_id','country_code','name_en','name_fa'] as $key) if (isset($place[$key])&&is_string($place[$key])) $clean[$key]=$place[$key];if (isset($clean['id'],$clean['name_en'],$clean['name_fa'])) $out['destinations'][]=$clean; }
        return $out;
    }
    public static function filters(array $raw): array|\WP_Error {
        $locale=$raw['locale']??($raw['lang']??'en');if (!is_string($locale)||!in_array($locale,['en','fa'],true)) return self::error('Locale must be en or fa.');
        $dated=['check_in','check_out','pickup_local','return_local','pickup_offset','return_offset'];
        $allowed=array_merge(['q','kind','destination','start_from','start_to','min_price_irr','max_price_irr','price_unit','language','accessibility','duration_min','duration_max','availability','sort','page','limit','locale','lang','_fields','_embed','_envelope','rest_route'],$dated);
        if (array_diff(array_keys($raw),$allowed)) return self::error('Unknown catalog filter.',$locale);
        $f=['locale'=>$locale,'q'=>'','kind'=>'','destination'=>'','start_from'=>'','start_to'=>'','min_price_irr'=>null,'max_price_irr'=>null,'price_unit'=>'','language'=>'','accessibility'=>[],'duration_min'=>null,'duration_max'=>null,'availability'=>'','sort'=>'newest','page'=>1,'limit'=>20];
        foreach (['q','kind','destination','start_from','start_to','price_unit','language','availability','sort'] as $key) if (isset($raw[$key])) { if (!is_string($raw[$key])||strlen($raw[$key])>($key==='q'?190:100)) return self::error('Invalid filter value.',$locale);$f[$key]=sanitize_text_field($raw[$key]); }
        if (($f['kind']!==''&&!in_array($f['kind'],self::KINDS,true))||($f['price_unit']!==''&&!in_array($f['price_unit'],self::UNITS,true))||($f['language']!==''&&!in_array($f['language'],self::LANGUAGES,true))||($f['availability']!==''&&!in_array($f['availability'],['open','not_open','closed','sold_out','on_request'],true))||!in_array($f['sort'],['newest','date','price_asc','price_desc','title'],true)) return self::error('Unsupported catalog filter.',$locale);
        foreach (['page'=>[1,100000],'limit'=>[1,50],'min_price_irr'=>[0,PHP_INT_MAX],'max_price_irr'=>[0,PHP_INT_MAX],'duration_min'=>[0,5256000],'duration_max'=>[0,5256000]] as $key=>$bounds) if (isset($raw[$key])&&$raw[$key]!=='') {
            $value=$raw[$key];if ((!is_int($value)&&!is_string($value))||!preg_match('/^(0|[1-9][0-9]{0,18})$/D',(string)$value)||strlen((string)$value)>strlen((string)$bounds[1])||(strlen((string)$value)===strlen((string)$bounds[1])&&strcmp((string)$value,(string)$bounds[1])>0)||(int)$value<$bounds[0]) return self::error('Invalid numeric filter.',$locale);$f[$key]=(int)$value;
        }
        foreach (['start_from','start_to'] as $key) if ($f[$key]!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$f[$key])||!Eligibility::date($f[$key]))) return self::error('Dates must be valid YYYY-MM-DD UTC dates.',$locale);
        if (($f['start_from']!==''&&$f['start_to']!==''&&$f['start_from']>$f['start_to'])||($f['min_price_irr']!==null&&$f['max_price_irr']!==null&&$f['min_price_irr']>$f['max_price_irr'])||($f['duration_min']!==null&&$f['duration_max']!==null&&$f['duration_min']>$f['duration_max'])) return self::error('Filter interval is reversed.',$locale);
        $access=$raw['accessibility']??[];if (is_string($access)) $access=$access===''?[]:explode(',',$access);$access=self::list_of($access,self::ACCESSIBILITY);if ($access===false) return self::error('Invalid accessibility filter.',$locale);$f['accessibility']=$access;
        $f['q']=self::normalize($f['q']);$f['destination']=self::normalize($f['destination']);if (count(explode(' ',$f['q']))>8) return self::error('Use at most eight search terms.',$locale);
        foreach ($dated as $key) { $value=$raw[$key]??''; if (!is_string($value) || strlen($value)>32) return self::error('Invalid dated resource filter.',$locale); $f[$key]=$value; }
        foreach (['check_in','check_out'] as $key) if ($f[$key]!=='' && !Eligibility::date($f[$key])) return self::error('Use valid stay dates.',$locale);
        if (($f['check_in']==='')!==($f['check_out']==='') || ($f['check_in']!=='' && $f['check_in']>=$f['check_out'])) return self::error('A complete increasing stay interval is required.',$locale);
        foreach (['pickup_local','return_local'] as $key) if ($f[$key]!=='') {
            $format=strlen($f[$key])===16?'Y-m-d\TH:i':'Y-m-d\TH:i:s'; $stamp=\DateTimeImmutable::createFromFormat('!'.$format,$f[$key],new \DateTimeZone('UTC'));
            if (!$stamp || $stamp->format($format)!==$f[$key]) return self::error('Use valid local rental times.',$locale);
        }
        if (($f['pickup_local']==='')!==($f['return_local']==='') || ($f['pickup_local']!=='' && $f['pickup_local']>=$f['return_local'])) return self::error('A complete increasing rental interval is required.',$locale);
        foreach (['pickup_offset','return_offset'] as $key) if ($f[$key]!=='' && !preg_match('/^[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00)$/D',$f[$key])) return self::error('Invalid explicit UTC offset.',$locale);
        return $f;
    }
    private static function json(string $path): string { return "JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(i.offer_snapshot),i.offer_snapshot,'{}'),'$.".$path."'))"; }
    private static function where(array $f): array {
        global $wpdb;$j=static fn($path)=>self::json($path);$clauses=["i.status='published'",$j('status')."='published'","p.post_type='atlas_offer'","p.post_status='private'"];$values=[];
        if ($f['kind']!=='') { $clauses[]='i.kind=%s';$values[]=$f['kind']; }
        $fallback="CONCAT_WS(' ',".$j('title_en').','.$j('title_fa').','.$j('description_en').','.$j('description_fa').')';
        $search="LOWER(REPLACE(REPLACE(COALESCE(".$j('catalog._search').",$fallback),'ي','ی'),'ك','ک'))";
        foreach (array_filter(explode(' ',$f['q'])) as $token) { $clauses[]="$search LIKE %s";$values[]='%'.$wpdb->esc_like($token).'%'; }
        if ($f['destination']!=='') { $clauses[]=$j('catalog._destinations').' LIKE %s';$values[]='%'.$wpdb->esc_like($f['destination']).'%'; }
        if ($f['start_from']!=='') { $clauses[]=$j('catalog.ends_at').'>%s';$values[]=$f['start_from'].'T00:00:00.000000Z'; }
        if ($f['start_to']!=='') { $clauses[]=$j('catalog.starts_at').'<%s';$values[]=(new \DateTimeImmutable($f['start_to'],new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d\T00:00:00.000000\Z'); }
        foreach (['min_price_irr'=>'>=','max_price_irr'=>'<='] as $key=>$op) if ($f[$key]!==null) { $clauses[]=$j('mode')."='local' AND CAST(".$j('amount_irr')." AS UNSIGNED)$op%d";$values[]=$f[$key]; }
        if ($f['price_unit']!=='') { $clauses[]="COALESCE(".$j('catalog.price_unit').",IF(".$j('mode')."='request_only','on_request','per_person'))=%s";$values[]=$f['price_unit']; }
        foreach (['duration_min'=>'>=','duration_max'=>'<='] as $key=>$op) if ($f[$key]!==null) { $clauses[]='CAST('.$j('catalog.duration_minutes')." AS UNSIGNED)$op%d";$values[]=$f[$key]; }
        foreach (array_merge($f['language']!==''?[['languages',$f['language']]]:[],array_map(static fn($value)=>['accessibility',$value],$f['accessibility'])) as [$key,$value]) { $clauses[]="JSON_CONTAINS(IF(JSON_VALID(i.offer_snapshot),i.offer_snapshot,'{}'),%s,'$.catalog.$key')=1";$values[]=wp_json_encode($value); }
        $now=(new \DateTimeImmutable(DB::now(),new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        if ($f['availability']==='on_request') $clauses[]=$j('mode')."='request_only'";
        elseif ($f['availability']!=='') {
            $clauses[]=$j('mode')."='local'";
            if ($f['availability']==='not_open') { $clauses[]=$j('registration_open_at').'>%s';$values[]=$now; }
            elseif ($f['availability']==='closed') { $clauses[]=$j('registration_close_at').'<=%s';$values[]=$now; }
            else { $clauses[]=$j('registration_open_at').'<=%s AND '.$j('registration_close_at').'>%s';$values[]=$now;$values[]=$now;$counter=$f['availability']==='open'?'i.capacity>i.allocated':'i.capacity<=i.allocated';$clauses[]="(i.kind IN ('stay','rental') OR $counter)"; }
        }
        return [implode(' AND ',$clauses),$values];
    }
    public static function availability(array $d,int $capacity,int $allocated,array $filters=[]): array {
        $now=(new \DateTimeImmutable(DB::now(),new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');$status='unavailable';
        if (($d['mode']??'')==='request_only') $status='on_request';
        elseif (!empty($d['registration_open_at'])&&!empty($d['registration_close_at'])) { if ($now<$d['registration_open_at']) $status='not_open';elseif ($now>=$d['registration_close_at']) $status='closed';else $status=$capacity>$allocated?'open':'sold_out'; }
        return apply_filters('atlas_catalog_availability',['status'=>$status,'source'=>($d['mode']??'')==='local'?'local_allocation':'request_only','checked_at'=>$now,'remaining'=>($d['mode']??'')==='local'?max(0,$capacity-$allocated):null,'admission_rechecked_at_hold'=>true],$d,$filters,$capacity,$allocated);
    }
    private static function item(int $id,array $d,int $capacity,int $allocated,string $locale,array $filters=[]): array {
        $out=Offers::projection($id,$d);$meta=self::public_metadata(is_array($d['catalog']??null)?$d['catalog']:[]);$other=$locale==='fa'?'en':'fa';$content=!empty($d['title_'.$locale])?$locale:$other;
        $descriptionLocale=!empty($d['description_'.$locale])?$locale:$other;
        $out['title']=$d['title_'.$content]??'';$out['description']=$d['description_'.$descriptionLocale]??'';$out['locale']=$locale;$out['content_locale']=$content;$out['description_locale']=$descriptionLocale;$out['translation_fallback']=$content!==$locale||($descriptionLocale!==$locale&&!empty($out['description']));$out['catalog']=$meta;
        $out['price']=['amount_irr'=>$d['mode']==='local'?(string)$d['amount_irr']:null,'currency'=>'IRR','unit'=>$d['mode']==='request_only'?'on_request':($meta['price_unit']??'per_person')];
        if ($d['mode']==='request_only') unset($out['amount_irr']);
        $out['availability']=self::availability($d+['id'=>$id],$capacity,$allocated,$filters);$now=$out['availability']['checked_at'];$out['information_review']=['status'=>empty($meta['reviewed_at'])?'unreviewed':(!empty($meta['review_due_at'])&&$meta['review_due_at']<=$now?'overdue':'recorded'),'reviewed_at'=>$meta['reviewed_at']??'','review_due_at'=>$meta['review_due_at']??''];
        $out['url']=add_query_arg(['offer_id'=>$id,'lang'=>$locale],home_url('/'));
        return apply_filters('atlas_catalog_item',$out,$d+['id'=>$id],$filters);
    }
    public static function search(array $raw=[]): array|\WP_Error {
        global $wpdb;$f=self::filters($raw);if (is_wp_error($f)) return $f;
        if (is_wp_error(DB::ready())) return new \WP_Error('atlas_catalog_unavailable',$f['locale']==='fa'?'فهرست موقتاً در دسترس نیست.':'Catalog is temporarily unavailable.',['status'=>503]);
        [$where,$args]=self::where($f);
        $visibility=apply_filters('atlas_catalog_public_where',['sql'=>$where,'args'=>$args],$f);
        if (!is_array($visibility) || !is_string($visibility['sql']??null) || !is_array($visibility['args']??null)) return new \WP_Error('atlas_catalog_unavailable','Catalog visibility is temporarily unavailable.',['status'=>503]);
        $where=$visibility['sql']; $args=$visibility['args'];
        $from=' FROM '.DB::table('inventory')." i INNER JOIN $wpdb->posts p ON p.ID=i.offer_id WHERE $where";
        $count='SELECT COUNT(*)'.$from;$total=$wpdb->get_var($args?$wpdb->prepare($count,$args):$count);if ($wpdb->last_error!==''||$total===null) return new \WP_Error('atlas_catalog_unavailable','Catalog is temporarily unavailable.',['status'=>503]);
        $sort=['newest'=>'i.offer_id DESC','date'=>"COALESCE(NULLIF(".self::json('catalog.starts_at').",''),'9999') ASC,i.offer_id ASC",'price_asc'=>"(".self::json('mode')."='request_only') ASC,COALESCE(".self::json('catalog.price_unit').",'per_person') ASC,CAST(".self::json('amount_irr')." AS UNSIGNED) ASC,i.offer_id ASC",'price_desc'=>"(".self::json('mode')."='request_only') ASC,COALESCE(".self::json('catalog.price_unit').",'per_person') ASC,CAST(".self::json('amount_irr')." AS UNSIGNED) DESC,i.offer_id ASC",'title'=>"COALESCE(NULLIF(".self::json('title_'.$f['locale']).",''),".self::json('title_'.($f['locale']==='fa'?'en':'fa')).') ASC,i.offer_id ASC'][$f['sort']];
        $query='SELECT i.offer_id,i.capacity,i.allocated,i.offer_snapshot'.$from.' ORDER BY '.$sort.' LIMIT %d OFFSET %d';$args[]=$f['limit'];$args[]=($f['page']-1)*$f['limit'];$rows=$wpdb->get_results($wpdb->prepare($query,$args));
        if ($rows===null||$wpdb->last_error!=='') return new \WP_Error('atlas_catalog_unavailable','Catalog is temporarily unavailable.',['status'=>503]);
        $items=[];foreach ($rows as $row) { $d=json_decode($row->offer_snapshot,true);if (is_array($d)&&($d['status']??'')==='published') $items[]=self::item((int)$row->offer_id,$d,(int)$row->capacity,(int)$row->allocated,$f['locale'],$f); }
        return ['items'=>$items,'total'=>(int)$total,'page'=>$f['page'],'limit'=>$f['limit'],'total_pages'=>(int)ceil((int)$total/$f['limit']),'filters'=>$f,'facets'=>['kinds'=>self::KINDS,'languages'=>self::LANGUAGES,'accessibility'=>self::ACCESSIBILITY,'price_units'=>self::UNITS],'generated_at'=>(new \DateTimeImmutable(DB::now(),new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z')];
    }
    public static function compare($ids,string $locale='en'): array|\WP_Error {
        global $wpdb;if (!in_array($locale,['en','fa'],true)) return self::error('Invalid locale.');if (is_string($ids)) $ids=explode(',',$ids);
        if (!is_array($ids)||!array_is_list($ids)||count($ids)<1||count($ids)>4) return self::error('Choose one to four public offers.',$locale);
        $valid=[];foreach ($ids as $id) { if ((!is_int($id)&&!is_string($id))||!preg_match('/^[1-9][0-9]{0,14}$/D',(string)$id)||in_array((int)$id,$valid,true)) return self::error('Invalid or duplicate offer reference.',$locale);$valid[]=(int)$id; }
        $items=[];foreach ($valid as $id) { $offer=Offers::get($id,true);if (is_wp_error($offer)) return new \WP_Error('atlas_offer_unavailable',$locale==='fa'?'یکی از پیشنهادها در دسترس نیست.':'One or more offers are unavailable.',['status'=>404]);$row=$wpdb->get_row($wpdb->prepare('SELECT capacity,allocated,offer_snapshot FROM '.DB::table('inventory').' WHERE offer_id=%d AND status=%s',$id,'published'));$d=$row?json_decode($row->offer_snapshot,true):null;if (!$row||!is_array($d)||($d['status']??'')!=='published') return new \WP_Error('atlas_offer_unavailable','One or more offers are unavailable.',['status'=>404]);$items[]=self::item($id,$d,(int)$row->capacity,(int)$row->allocated,$locale); }
        return ['items'=>$items,'locale'=>$locale,'comparison_unit'=>'IRR','availability_rechecked_at_hold'=>true];
    }
}
