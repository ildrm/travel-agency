<?php
if (!defined('ABSPATH')) { exit; }
use Atlas\Core\Auth;
use Atlas\Core\Catalog;
use Atlas\Core\DB;
use Atlas\Core\Offers;

/** Progressive catalog: real GET filters work without JavaScript. */
final class Atlas_Catalog_UI {
    private const PARAMS=['q','kind','destination','start_from','start_to','min_price_irr','max_price_irr','price_unit','language','accessibility','duration_min','duration_max','availability','sort','page','limit'];
    private static function lang() {
        $requested=$_GET['lang']??null;
        if (in_array($requested,['en','fa'],true)) return $requested;
        return str_starts_with(determine_locale(),'fa')?'fa':'en';
    }
    private static function t($en,$fa) { return self::lang()==='fa'?$fa:$en; }
    public static function register() {
        add_shortcode('atlas_catalog',[__CLASS__,'render']);
        add_shortcode('atlas_catalog_metadata',[__CLASS__,'editor']);
        add_shortcode('atlas_catalog_offer',[__CLASS__,'details_shortcode']);
        add_filter('atlas_operations_dashboard',static function($html,$customer) { return $customer?$html:$html.self::editor(); },10,2);
        add_action('admin_post_atlas_catalog_metadata',[__CLASS__,'save_form']);
        add_action('rest_api_init',[__CLASS__,'routes']);
    }
    private static function assets() {
        wp_enqueue_style('atlas-catalog',plugins_url('assets/catalog.css',__FILE__),[],ATLAS_CORE_VERSION.'-'.filemtime(__DIR__.'/assets/catalog.css'));
        wp_enqueue_script('atlas-catalog',plugins_url('assets/catalog.js',__FILE__),[],ATLAS_CORE_VERSION.'-'.filemtime(__DIR__.'/assets/catalog.js'),true);
    }
    private static function labels() {
        return [
            'tour'=>self::t('Tours','تورها'),'event'=>self::t('Events','رویدادها'),'cohort'=>self::t('Academy cohorts','دوره‌های آموزشگاه'),'stay'=>self::t('Stays','اقامت'),'rental'=>self::t('Rentals','اجاره'),'transfer'=>self::t('Transfers','ترانسفر'),'flight'=>self::t('Flights','پرواز'),'rail'=>self::t('Rail','قطار'),'bus'=>self::t('Bus','اتوبوس'),
            'step_free'=>self::t('Step-free access','دسترسی بدون پله'),'accessible_toilet'=>self::t('Accessible toilet','سرویس بهداشتی مناسب‌سازی‌شده'),'hearing_support'=>self::t('Hearing support','پشتیبانی شنوایی'),'visual_support'=>self::t('Visual support','پشتیبانی بینایی'),'low_mobility'=>self::t('Reduced-mobility provision','امکانات برای تحرک محدود'),'sign_language'=>self::t('Sign language','زبان اشاره'),
            'open'=>self::t('Registration open','ثبت‌نام باز است'),'not_open'=>self::t('Registration not yet open','ثبت‌نام هنوز آغاز نشده'),'closed'=>self::t('Registration closed','ثبت‌نام بسته است'),'sold_out'=>self::t('No unallocated places','ظرفیت آزاد موجود نیست'),'on_request'=>self::t('Request only; availability unconfirmed','فقط درخواست؛ موجودی تأیید نشده'),'unavailable'=>self::t('Unavailable','در دسترس نیست'),'dates_required'=>self::t('Select dates to check availability','برای بررسی موجودی، تاریخ را انتخاب کنید'),
            'per_person'=>self::t('per person','برای هر نفر'),'per_group'=>self::t('per group','برای هر گروه'),'per_night'=>self::t('per night','برای هر شب'),'per_day'=>self::t('per started 24-hour period','برای هر بازهٔ ۲۴ ساعتهٔ آغازشده'),'per_item'=>self::t('per item','برای هر مورد'),
            'featured'=>self::t('Featured first','پیشنهادهای منتخب'),'newest'=>self::t('Newest','جدیدترین'),'date'=>self::t('Service date','تاریخ خدمت'),'price_asc'=>self::t('Price within each unit, low to high','قیمت در هر واحد، کم به زیاد'),'price_desc'=>self::t('Price within each unit, high to low','قیمت در هر واحد، زیاد به کم'),'title'=>self::t('Title','عنوان'),
            'recorded'=>self::t('Review recorded','بازبینی ثبت شده'),'unreviewed'=>self::t('Review date not recorded','تاریخ بازبینی ثبت نشده'),'overdue'=>self::t('Review overdue; confirm details','مهلت بازبینی گذشته؛ جزئیات را بررسی کنید')
        ];
    }
    private static function url(array $filters,array $extra=[]) {
        $args=['lang'=>self::lang()];foreach (self::PARAMS as $key) if (isset($filters[$key])&&$filters[$key]!==''&&$filters[$key]!==[]&&$filters[$key]!==null) $args['cat_'.$key]=is_array($filters[$key])?implode(',',$filters[$key]):$filters[$key];
        return add_query_arg(array_merge($args,$extra),home_url('/')).'#atlas-offers';
    }
    private static function raw($attributes=[]) {
        $raw=['locale'=>self::lang(),'limit'=>6,'sort'=>'featured'];foreach (self::PARAMS as $key) if (isset($_GET['cat_'.$key])) $raw[$key]=wp_unslash($_GET['cat_'.$key]);
        foreach (['min_price_irr','max_price_irr','duration_min','duration_max'] as $key) {
            $value=$raw[$key]??'';
            if (is_string($value)&&preg_match('/^[0-9۰-۹٠-٩]+$/uD',$value)) $raw[$key]=ltrim(Catalog::normalize($value),'0')?:'0';
        }
        if (!isset($raw['kind'])&&!empty($attributes['kind'])) $raw['kind']=$attributes['kind'];return $raw;
    }
    private static function input($name,$label,$value,$type='text',$attrs='') {
        return '<label>'.esc_html($label).'<input type="'.esc_attr($type).'" name="cat_'.esc_attr($name).'" value="'.esc_attr(is_scalar($value)?(string)$value:'').'" '.$attrs.'></label>';
    }
    private static function select($name,$label,array $values,$selected,$all=true) {
        $labels=self::labels();$html='<label>'.esc_html($label).'<select name="cat_'.esc_attr($name).'">';if ($all) $html.='<option value="">'.esc_html(self::t('Any','همه')).'</option>';
        foreach ($values as $value) $html.='<option value="'.esc_attr($value).'"'.selected($selected,$value,false).'>'.esc_html($labels[$value]??strtoupper($value)).'</option>';return $html.'</select></label>';
    }
    private static function compare_ids() {
        $raw=$_GET['cat_compare']??'';if (!is_string($raw)||$raw==='') return [];$ids=explode(',',$raw);if (count($ids)>4) return [];
        foreach ($ids as $id) if (!preg_match('/^[1-9][0-9]{0,14}$/D',$id)) return [];return array_values(array_unique(array_map('intval',$ids)));
    }
    private static function price($item) {
        if ($item['price']['amount_irr']===null) return self::t('Request a quote; no confirmed rate','درخواست قیمت؛ نرخ تأییدشده ندارد');
        $prefix=($item['price']['from']??false)===true?self::t('From ','از '):'';
        $amount=preg_replace('/\B(?=(\d{3})+(?!\d))/',',',$item['price']['amount_irr']);
        return $prefix.$amount.' IRR · '.(self::labels()[$item['price']['unit']]??$item['price']['unit']);
    }
    private static function date_text($item) {
        $m=$item['catalog'];if (empty($m['starts_at'])) return self::t('Service date not specified','تاریخ خدمت مشخص نشده');
        $zone=new DateTimeZone($item['timezone']);return wp_date('Y-m-d H:i',strtotime($m['starts_at']),$zone).' – '.wp_date('Y-m-d H:i',strtotime($m['ends_at']),$zone).' · '.$item['timezone'];
    }
    private static function places($item) {
        $names=[];foreach ($item['catalog']['destinations']??[] as $p) $names[]=$p['name_'.self::lang()]?:($p['name_en']?:$p['name_fa']);return implode(' · ',$names);
    }
    public static function render($attributes=[]) {
        self::assets();$raw=self::raw($attributes);$result=Catalog::search($raw);$safe=is_wp_error($result)?Catalog::filters($raw):$result['filters'];$f=$safe;
        if (is_wp_error($safe)) { $f=Catalog::filters(['locale'=>self::lang()]);foreach (self::PARAMS as $key) if (isset($raw[$key])&&is_scalar($raw[$key])) $f[$key]=sanitize_text_field((string)$raw[$key]);$access=$raw['accessibility']??[];if (is_string($access)) $access=explode(',',$access);$f['accessibility']=is_array($access)?array_values(array_filter($access,static fn($value)=>is_string($value)&&in_array($value,Catalog::ACCESSIBILITY,true))):[]; }
        $compare=self::compare_ids();
        $compareArgs=$compare?['cat_compare'=>implode(',',$compare)]:[];
        $clearFilters=self::url([],$compareArgs);
        $html='<section class="atlas-catalog" data-atlas-catalog><form method="get" action="'.esc_url(home_url('/').'#atlas-offers').'" data-catalog-filter><input type="hidden" name="lang" value="'.esc_attr(self::lang()).'"><input type="hidden" name="cat_page" value="1">';
        if ($compare) $html.='<input type="hidden" name="cat_compare" value="'.esc_attr(implode(',',$compare)).'">';
        if (isset($_GET['cat_limit'])&&!is_wp_error($safe)) $html.='<input type="hidden" name="cat_limit" value="'.esc_attr($safe['limit']).'">';
        $html.='<div class="atlas-catalog-filters">'.self::input('q',self::t('Search offers','جست‌وجوی پیشنهادها'),$f['q']??'','search','maxlength="190"').self::input('destination',self::t('Destination name or ID','نام یا شناسه مقصد'),$f['destination']??'','text','maxlength="100"');
        $html.=self::select('kind',self::t('Offering type','نوع پیشنهاد'),Catalog::KINDS,$f['kind']??'').'</div>';
        $advanced=['language','start_from','start_to','min_price_irr','max_price_irr','price_unit','duration_min','duration_max','availability','accessibility','sort'];
        $open=is_wp_error($safe);
        foreach ($advanced as $key) {
            if (isset($raw[$key]) && $raw[$key]!=='' && $raw[$key]!==[] && !($key==='sort' && $raw[$key]==='featured')) $open=true;
        }
        $html.='<details class="atlas-refine"'.($open?' open':'').'><summary>'.esc_html(self::t('More filters','فیلترهای بیشتر')).'</summary><div class="atlas-catalog-filters">';
        $html.=self::select('language',self::t('Service language','زبان ارائه خدمت'),Catalog::LANGUAGES,$f['language']??'');
        $html.=self::input('start_from',self::t('From date (UTC)','از تاریخ (UTC)'),$f['start_from']??'','date').self::input('start_to',self::t('Through date (UTC)','تا پایان تاریخ (UTC)'),$f['start_to']??'','date');
        $numeric='inputmode="numeric" pattern="[0-9۰-۹٠-٩]+"';
        $html.=self::input('min_price_irr',self::t('Minimum IRR price','حداقل قیمت به ریال'),$f['min_price_irr']??'','text',$numeric.' maxlength="19"').self::input('max_price_irr',self::t('Maximum IRR price','حداکثر قیمت به ریال'),$f['max_price_irr']??'','text',$numeric.' maxlength="19"');
        $html.=self::select('price_unit',self::t('Price unit','واحد قیمت'),Catalog::UNITS,$f['price_unit']??'');
        $html.=self::input('duration_min',self::t('Minimum duration (minutes)','حداقل مدت (دقیقه)'),$f['duration_min']??'','text',$numeric.' maxlength="7"').self::input('duration_max',self::t('Maximum duration (minutes)','حداکثر مدت (دقیقه)'),$f['duration_max']??'','text',$numeric.' maxlength="7"');
        $html.=self::select('availability',self::t('Availability','وضعیت ثبت‌نام'),['open','not_open','closed','sold_out','on_request'],$f['availability']??'').self::select('sort',self::t('Sort','مرتب‌سازی'),['featured','newest','date','price_asc','price_desc','title'],$f['sort']??'newest',false).'</div><fieldset><legend>'.esc_html(self::t('Declared accessibility features — read the offer notes','امکانات دسترس‌پذیری اعلام‌شده — توضیحات پیشنهاد را بخوانید')).'</legend>';
        $chosen=is_array($f['accessibility']??null)?$f['accessibility']:explode(',',is_string($f['accessibility']??null)?$f['accessibility']:'');foreach (Catalog::ACCESSIBILITY as $value) $html.='<label class="atlas-catalog-check"><input type="checkbox" name="cat_accessibility[]" value="'.esc_attr($value).'"'.checked(in_array($value,$chosen,true),true,false).'>'.esc_html(self::labels()[$value]).'</label>';
        $html.='</fieldset></details><button type="submit">'.esc_html(self::t('Apply filters','اعمال فیلترها')).'</button> <a href="'.esc_url($clearFilters).'">'.esc_html(self::t('Clear all filters','حذف همه فیلترها')).'</a><p role="status" data-catalog-loading data-loading-text="'.esc_attr(self::t('Loading matching offers…','در حال دریافت پیشنهادهای مطابق…')).'"></p></form>';
        if (is_wp_error($result)) {
            $html.='<div class="atlas-catalog-notice"><p role="alert" tabindex="-1" data-catalog-result-focus>';
            if (is_wp_error($safe)) {
                $html.=esc_html(self::t('Some filters could not be applied. Check the search terms, dates and ranges, then apply the filters again.','بعضی از فیلترها اعمال نشدند. عبارت جست‌وجو، تاریخ‌ها و بازه‌ها را بررسی کنید و دوباره فیلترها را اعمال کنید.')).'</p>';
                if (self::lang()==='en') $html.='<p>'.esc_html($safe->get_error_message()).'</p>';
            } else {
                $html.=esc_html(self::t('The catalog is temporarily unavailable. Your filters are still selected.','فهرست موقتاً در دسترس نیست. فیلترهای شما همچنان انتخاب شده‌اند.')).'</p><a href="'.esc_url(self::url($f,$compareArgs)).'">'.esc_html(self::t('Try again','تلاش مجدد')).'</a>';
            }
            return $html.'</div></section>';
        }
        $html.='<p class="atlas-catalog-hint">'.esc_html(self::t('Service-date filters use UTC days. Price filters use the listed rate in rials (IRR). Choose dates on the offer to see the exact stay or rental quote.','فیلتر تاریخ خدمت بر اساس روز UTC است. فیلتر قیمت بر اساس نرخ درج‌شده به ریال (IRR) است. برای دیدن قیمت دقیق اقامت یا اجاره، تاریخ‌ها را در صفحهٔ پیشنهاد انتخاب کنید.')).'</p>';
        $chipNames=['q'=>self::t('Search','جست‌وجو'),'kind'=>self::t('Type','نوع'),'destination'=>self::t('Destination','مقصد'),'start_from'=>self::t('From','از'),'start_to'=>self::t('Through','تا'),'min_price_irr'=>self::t('Minimum IRR','حداقل ریال'),'max_price_irr'=>self::t('Maximum IRR','حداکثر ریال'),'price_unit'=>self::t('Price unit','واحد قیمت'),'language'=>self::t('Language','زبان'),'accessibility'=>self::t('Accessibility','دسترس‌پذیری'),'duration_min'=>self::t('Minimum minutes','حداقل دقیقه'),'duration_max'=>self::t('Maximum minutes','حداکثر دقیقه'),'availability'=>self::t('Registration','ثبت‌نام')];
        $chips='';
        foreach ($chipNames as $key=>$name) {
            $value=$f[$key]??null;
            if ($value===''||$value===null||$value===[]) continue;
            $remove=$f; unset($remove[$key]); $remove['page']=1;
            $values=is_array($value)?$value:[$value];
            $display=implode(', ',array_map(static fn($v)=>self::labels()[$v]??(string)$v,$values));
            $label=$name.': '.$display;
            $chips.='<a aria-label="'.esc_attr(sprintf(self::t('Remove filter: %s','حذف فیلتر: %s'),$label)).'" href="'.esc_url(self::url($remove,$compareArgs)).'"><bdi>'.esc_html($label).'</bdi> <span aria-hidden="true">×</span></a>';
        }
        if ($chips!=='') $html.='<nav aria-label="'.esc_attr(self::t('Applied filters','فیلترهای اعمال‌شده')).'" class="atlas-catalog-chips">'.$chips.'</nav>';
        $html.='<p class="atlas-catalog-result-summary" role="status" tabindex="-1" data-catalog-result-focus>';
        if (!$result['total']) {
            $html.=esc_html(self::t('No offers match these filters. Change a filter or clear all to try again.','پیشنهادی با این فیلترها یافت نشد. فیلترها را تغییر دهید یا حذف کنید.')).'</p><p><a href="'.esc_url($clearFilters).'">'.esc_html(self::t('Show all offers','نمایش همهٔ پیشنهادها')).'</a></p>';
        } elseif ($result['page']>$result['total_pages']) {
            $html.=esc_html(self::t('This page of offers is no longer available.','این صفحه از پیشنهادها دیگر در دسترس نیست.')).'</p><p><a href="'.esc_url(self::url(array_merge($f,['page'=>1]),$compareArgs)).'">'.esc_html(self::t('Go to the first page of matching offers','رفتن به صفحهٔ اول پیشنهادهای مطابق')).'</a></p>';
        } else {
            $html.=esc_html(sprintf(self::t('%d matching offers, page %d of %d','%d پیشنهاد مطابق، صفحهٔ %d از %d'),$result['total'],$result['page'],$result['total_pages'])).'</p>';
        }
        if ($compare) $html.=self::comparison($compare,$f);
        if (!$result['items']) return $html.'</section>';
        $html.='<div class="atlas-offer-list">';foreach ($result['items'] as $item) {
            $selected=in_array($item['id'],$compare,true);$next=$selected?array_values(array_diff($compare,[$item['id']])):array_merge($compare,[$item['id']]);
            $html.='<article data-catalog-offer="'.esc_attr($item['id']).'"><h3><a lang="'.esc_attr($item['content_locale']).'" href="'.esc_url($item['url']).'"><bdi>'.esc_html($item['title']).'</bdi></a></h3>';
            $places=self::places($item);
            if ($places!=='') $html.='<p>'.esc_html($places).'</p>';
            $html.='<p><bdi>'.esc_html(self::price($item)).'</bdi></p><p><bdi>'.esc_html(self::date_text($item)).'</bdi></p><p>'.esc_html(self::labels()[$item['availability']['status']]).'</p>';
            if ($item['content_locale']!==self::lang()) $html.='<p>'.esc_html(self::t('Offer title is shown in Persian.','عنوان پیشنهاد به انگلیسی نمایش داده می‌شود.')).'</p>';
            if ($item['information_review']['status']==='overdue') $html.='<p>'.esc_html(self::t('Information review overdue; confirm details with the agency.','مهلت بازبینی اطلاعات گذشته است؛ جزئیات را با آژانس بررسی کنید.')).'</p>';
            elseif ($item['information_review']['status']==='unreviewed') $html.='<p>'.esc_html(self::t('Information review date not recorded.','تاریخ بازبینی اطلاعات ثبت نشده است.')).'</p>';
            if (count($next)<=4) {
                $action=$selected?self::t('Remove from comparison','حذف از مقایسه'):self::t('Add to comparison','افزودن به مقایسه');
                $html.='<a class="atlas-catalog-compare-link" data-catalog-compare="'.esc_attr($item['id']).'" href="'.esc_url(self::url($f,$next?['cat_compare'=>implode(',',$next)]:[])).'">'.esc_html($action).' <span class="atlas-catalog-sr-only" lang="'.esc_attr($item['content_locale']).'">'.esc_html($item['title']).'</span></a>';
            }
            else $html.='<p>'.esc_html(self::t('Compare up to four offers; remove one first.','حداکثر چهار پیشنهاد را مقایسه کنید؛ ابتدا یکی را حذف کنید.')).'</p>';
            $html.='</article>';
        }
        $html.='</div>';
        if ($result['total_pages']>1) {
            $html.='<nav class="atlas-catalog-pagination" aria-label="'.esc_attr(self::t('Catalog pages','صفحات فهرست')).'">';
            if ($result['page']>1) $html.='<a data-catalog-prev href="'.esc_url(self::url(array_merge($f,['page'=>$result['page']-1]),$compareArgs)).'">'.esc_html(self::t('Previous page','صفحه قبل')).'</a>';
            if ($result['page']<$result['total_pages']) $html.='<a data-catalog-next href="'.esc_url(self::url(array_merge($f,['page'=>$result['page']+1]),$compareArgs)).'">'.esc_html(self::t('Next page','صفحه بعد')).'</a>';
            $html.='</nav>';
        }
        return $html.'</section>';
    }
    private static function comparison(array $ids,array $filters) {
        $result=Catalog::compare($ids,self::lang());
        $clear='<a href="'.esc_url(self::url($filters)).'">'.esc_html(self::t('Clear comparison','پاک کردن مقایسه')).'</a>';
        $html='<section class="atlas-catalog-comparison" data-atlas-comparison><h3>'.esc_html(self::t('Compare offers','مقایسه پیشنهادها')).'</h3>';
        if (is_wp_error($result)) {
            return $html.'<p role="alert">'.esc_html(self::t('A selected offer is unavailable. Clear the comparison and choose from the current offers.','یکی از پیشنهادهای انتخاب‌شده در دسترس نیست. مقایسه را پاک کنید و از پیشنهادهای فعلی انتخاب کنید.')).'</p>'.$clear.'</section>';
        }
        $html.='<p>'.esc_html(self::t('Compare each price unit and its terms. Request-only offers have no confirmed rate.','واحد قیمت و شرایط هر پیشنهاد را بررسی کنید. پیشنهادهای درخواستی نرخ قطعی ندارند.')).'</p><p>'.$clear.'</p><div class="atlas-catalog-table" role="region" aria-label="'.esc_attr(self::t('Offer comparison table','جدول مقایسهٔ پیشنهادها')).'" tabindex="0"><table><caption>'.esc_html(self::t('Public offer comparison','مقایسه اطلاعات عمومی پیشنهادها')).'</caption><thead><tr><th scope="col">'.esc_html(self::t('Attribute','ویژگی')).'</th>';
        foreach ($result['items'] as $item) {
            $remaining=array_values(array_diff($ids,[$item['id']]));
            $removeUrl=self::url($filters,$remaining?['cat_compare'=>implode(',',$remaining)]:[]);
            $html.='<th scope="col"><a lang="'.esc_attr($item['content_locale']).'" href="'.esc_url($item['url']).'"><bdi>'.esc_html($item['title']).'</bdi></a><br><a class="atlas-catalog-compare-link" href="'.esc_url($removeUrl).'">'.esc_html(self::t('Remove','حذف')).' <span class="atlas-catalog-sr-only" lang="'.esc_attr($item['content_locale']).'">'.esc_html($item['title']).'</span></a></th>';
        }
        $html.='</tr></thead><tbody>';
        $rows=['destination'=>self::t('Destination','مقصد'),'price'=>self::t('Price and unit','قیمت و واحد'),'date'=>self::t('Service time and zone','زمان خدمت و منطقه زمانی'),'availability'=>self::t('Registration','ثبت‌نام'),'language'=>self::t('Languages','زبان‌ها'),'accessibility'=>self::t('Declared accessibility','دسترس‌پذیری اعلام‌شده'),'inclusions'=>self::t('Inclusions','خدمات شامل'),'exclusions'=>self::t('Exclusions','خدمات خارج'),'terms'=>self::t('Terms','شرایط'),'review'=>self::t('Information review','بازبینی اطلاعات')];
        foreach ($rows as $key=>$label) { $html.='<tr><th scope="row">'.esc_html($label).'</th>';foreach ($result['items'] as $item) { $m=$item['catalog'];switch ($key) {case 'destination':$value=self::places($item);break;case 'price':$value=self::price($item);break;case 'date':$value=self::date_text($item);break;case 'availability':$value=self::labels()[$item['availability']['status']];break;case 'language':$value=implode(', ',$m['languages']??[]);break;case 'accessibility':$value=$m['accessibility_note_'.self::lang()]??'';break;case 'review':$value=(self::labels()[$item['information_review']['status']]??'').' · '.($m['reviewed_at']??'');break;default:$value=$m[$key.'_'.self::lang()]??'';}$html.='<td>'.nl2br(esc_html($value!==''?$value:self::t('Not specified','مشخص نشده'))).'</td>'; }$html.='</tr>'; }
        return $html.'</tbody></table></div></section>';
    }
    public static function details_shortcode($atts=[]) { $id=isset($atts['id'])?(int)$atts['id']:0;$offer=Offers::get($id,true);return is_wp_error($offer)?'':self::details($offer); }
    public static function details(array $offer) {
        $result=Catalog::compare([$offer['id']],self::lang());if (is_wp_error($result)) return '';$item=$result['items'][0];$m=$item['catalog'];$html='<section class="atlas-catalog-details"><h2>'.esc_html(self::t('Service details','جزئیات خدمت')).'</h2><p>'.esc_html(self::places($item)).'</p><p>'.esc_html(self::date_text($item)).'</p><p>'.esc_html(self::price($item)).'</p><p>'.esc_html(self::labels()[$item['availability']['status']]).'</p>';
        $html.='<p>'.esc_html(self::t('Service languages: ','زبان‌های خدمت: ').implode(', ',$m['languages']??[])).'</p><p>'.esc_html(self::t('Duration in minutes: ','مدت به دقیقه: ').(!empty($m['duration_minutes'])?$m['duration_minutes']:self::t('Not specified','مشخص نشده'))).'</p><p>'.esc_html(self::t('Availability checked at (UTC): ','زمان بررسی موجودی (UTC): ').$item['availability']['checked_at']).'</p>';
        foreach (['accessibility_note'=>self::t('Declared accessibility','دسترس‌پذیری اعلام‌شده'),'inclusions'=>self::t('Inclusions','خدمات شامل'),'exclusions'=>self::t('Exclusions','خدمات خارج'),'terms'=>self::t('Terms','شرایط')] as $key=>$label) { $text=$m[$key.'_'.self::lang()]??'';if ($text!=='') $html.='<h3>'.esc_html($label).'</h3><p>'.nl2br(esc_html($text)).'</p>'; }
        return $html.'<p>'.esc_html(self::t('Information review: ','وضعیت بازبینی اطلاعات: ').(self::labels()[$item['information_review']['status']]??'').' · '.($m['reviewed_at']??'')).'</p></section>';
    }
    public static function routes() {
        register_rest_route('agency/v1','/catalog/offers/(?P<id>\d+)/metadata',['methods'=>'POST','permission_callback'=>static function($r) { return is_user_logged_in()&&wp_verify_nonce((string)$r->get_header('X-WP-Nonce'),'wp_rest'); },'callback'=>[__CLASS__,'save_api']]);
    }
    public static function save_api($request) {
        $body=$request->get_json_params();if (!is_array($body)||array_diff(array_keys($body),['catalog','expected_version'])||!is_int($body['expected_version']??null)||!is_array($body['catalog']??null)) return new WP_Error('atlas_invalid_catalog',self::t('Typed catalog metadata and an expected version are required.','اطلاعات ساختاری فهرست و نسخه فعلی لازم است.'),['status'=>422]);
        $result=Offers::save((int)$request['id'],$body,get_current_user_id());if (is_wp_error($result)) return $result;$response=new WP_REST_Response(Offers::get($result,false));$response->header('Cache-Control','private, no-store');return $response;
    }
    public static function editor() {
        if (!is_user_logged_in()||(!Auth::agency()&&!current_user_can('atlas_manage_offers'))) return '';self::assets();
        $html='<details class="atlas-catalog-editor"><summary>'.esc_html(self::t('Offer discovery metadata','اطلاعات جست‌وجو و فهرست پیشنهاد')).'</summary><p>'.esc_html(self::t('Create an offer draft first, then load its ID here. Changes use its version and the same review/booking impact guards. Source references remain private.','ابتدا پیش‌نویس پیشنهاد را بسازید و شناسه آن را اینجا باز کنید. تغییرات با نسخه فعلی و کنترل بازبینی و اثر رزرو ذخیره می‌شوند. مرجع منبع خصوصی می‌ماند.')).'</p><form method="get" action="'.esc_url(home_url('/')).'"><input type="hidden" name="atlas_area" value="portal"><input type="hidden" name="lang" value="'.esc_attr(self::lang()).'"><label>'.esc_html(self::t('Offer ID','شناسه پیشنهاد')).'<input type="number" name="cat_edit_offer" min="1" required></label><button>'.esc_html(self::t('Load authorized offer','نمایش پیشنهاد مجاز')).'</button></form>';
        $id=isset($_GET['cat_edit_offer'])&&is_scalar($_GET['cat_edit_offer'])?absint($_GET['cat_edit_offer']):0;if (!$id) return $html.'</details>';
        $offer=Offers::get($id,false);if (is_wp_error($offer)) return $html.'<p role="alert">'.esc_html(self::t('Offer unavailable.','پیشنهاد در دسترس نیست.')).'</p></details>';
        global $wpdb;$snapshot=json_decode((string)$wpdb->get_var($wpdb->prepare('SELECT offer_snapshot FROM '.DB::table('inventory').' WHERE offer_id=%d',$id)),true);$m=$snapshot['catalog']??[];
        $html.='<h3>'.esc_html($offer['title_en']?:$offer['title_fa']).'</h3><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="atlas_catalog_metadata"><input type="hidden" name="offer_id" value="'.esc_attr($id).'"><input type="hidden" name="expected_version" value="'.esc_attr($offer['version']).'"><input type="hidden" name="lang" value="'.esc_attr(self::lang()).'">'.wp_nonce_field('atlas_catalog_metadata','_wpnonce',true,false);
        $html.='<fieldset><legend>'.esc_html(self::t('Destinations (up to 12; stable IDs)','مقصدها (حداکثر ۱۲؛ شناسه ثابت)')).'</legend><div data-catalog-destinations>';
        $places=$m['destinations']??[];$places[]=[];foreach ($places as $index=>$p) { $html.='<div data-destination-row>';foreach (['id'=>self::t('Stable place ID','شناسه ثابت مکان'),'type'=>self::t('Type: country, city or place','نوع: country، city یا place'),'parent_id'=>self::t('Parent ID (optional)','شناسه والد (اختیاری)'),'country_code'=>self::t('Country code (ISO, uppercase)','کد کشور (ISO، حروف بزرگ)'),'name_en'=>self::t('English destination name','نام انگلیسی مقصد'),'name_fa'=>self::t('Persian destination name','نام فارسی مقصد')] as $field=>$label) $html.='<label>'.esc_html($label).'<input name="catalog[destinations]['.$index.']['.$field.']" value="'.esc_attr($p[$field]??($field==='type'?'city':'')).'"></label>';$html.='</div>'; }
        $html.='</div><button type="button" data-add-destination>'.esc_html(self::t('Add destination','افزودن مقصد')).'</button></fieldset><div class="atlas-catalog-filters">';
        foreach (['starts_at'=>self::t('Service starts (UTC ISO, ending Z)','شروع خدمت (UTC ISO، با Z پایانی)'),'ends_at'=>self::t('Service ends (UTC ISO, ending Z)','پایان خدمت (UTC ISO، با Z پایانی)'),'duration_minutes'=>self::t('Duration minutes (0 derives from interval)','مدت به دقیقه (۰ از بازه محاسبه می‌شود)'),'source_reference'=>self::t('Private reviewed-source reference','مرجع خصوصی منبع بررسی‌شده'),'reviewed_at'=>self::t('Information reviewed at (UTC ISO)','زمان بازبینی اطلاعات (UTC ISO)'),'review_due_at'=>self::t('Next review due (UTC ISO)','مهلت بازبینی بعدی (UTC ISO)')] as $key=>$label) $html.='<label>'.esc_html($label).'<input name="catalog['.$key.']" value="'.esc_attr($m[$key]??($key==='duration_minutes'?0:'')).'"></label>';
        $html.='<label>'.esc_html(self::t('Price unit','واحد قیمت')).'<select name="catalog[price_unit]">';foreach (Catalog::UNITS as $unit) $html.='<option value="'.esc_attr($unit).'"'.selected($m['price_unit']??($offer['mode']==='request_only'?'on_request':'per_person'),$unit,false).'>'.esc_html(self::labels()[$unit]).'</option>';$html.='</select></label></div>';
        foreach (['languages'=>Catalog::LANGUAGES,'accessibility'=>Catalog::ACCESSIBILITY] as $key=>$values) { $html.='<fieldset><legend>'.esc_html($key==='languages'?self::t('Service languages','زبان‌های خدمت'):self::t('Declared accessibility (reviewed notes required)','دسترس‌پذیری اعلام‌شده (توضیح بازبینی‌شده لازم است)')).'</legend>';foreach ($values as $value) $html.='<label class="atlas-catalog-check"><input type="checkbox" name="catalog['.$key.'][]" value="'.esc_attr($value).'"'.checked(in_array($value,$m[$key]??[],true),true,false).'>'.esc_html(self::labels()[$value]??strtoupper($value)).'</label>';$html.='</fieldset>'; }
        foreach (['accessibility_note'=>self::t('Accessibility notes','توضیحات دسترس‌پذیری'),'inclusions'=>self::t('Inclusions','خدمات شامل'),'exclusions'=>self::t('Exclusions','خدمات خارج'),'terms'=>self::t('Terms','شرایط')] as $key=>$label) foreach (['en','fa'] as $locale) $html.='<label>'.esc_html($label.' ('.strtoupper($locale).')').'<textarea name="catalog['.$key.'_'.$locale.']" maxlength="3000">'.esc_textarea($m[$key.'_'.$locale]??'').'</textarea></label>';
        return $html.'<button>'.esc_html(self::t('Save typed metadata','ذخیره اطلاعات ساختاری')).'</button></form></details>';
    }
    public static function save_form() {
        if (!is_user_logged_in()) wp_die(esc_html__('Sign in required.','atlas-operations'),'',['response'=>403]);check_admin_referer('atlas_catalog_metadata');$raw=isset($_POST['catalog'])&&is_array($_POST['catalog'])?wp_unslash($_POST['catalog']):[];
        $raw['destinations']=array_values(array_filter(is_array($raw['destinations']??null)?$raw['destinations']:[],static function($p) { return is_array($p)&&(!empty($p['id'])||!empty($p['name_en'])||!empty($p['name_fa'])); }));
        $duration=$raw['duration_minutes']??'0';if (!is_string($duration)||!preg_match('/^(0|[1-9][0-9]{0,6})$/D',$duration)) wp_die(esc_html(self::t('Invalid duration.','مدت معتبر نیست.')),'',['response'=>422]);$raw['duration_minutes']=(int)$duration;
        foreach (['languages','accessibility'] as $key) $raw[$key]=$raw[$key]??[];$id=isset($_POST['offer_id'])&&is_scalar($_POST['offer_id'])?absint($_POST['offer_id']):0;$version=isset($_POST['expected_version'])&&is_scalar($_POST['expected_version'])?absint($_POST['expected_version']):0;
        $result=Offers::save($id,['catalog'=>$raw,'expected_version'=>$version],get_current_user_id());if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()),'',['response'=>(int)($result->get_error_data()['status']??422)]);
        $locale=isset($_POST['lang'])&&$_POST['lang']==='fa'?'fa':'en';wp_safe_redirect(add_query_arg(['atlas_area'=>'portal','lang'=>$locale,'cat_edit_offer'=>$id],home_url('/')));exit;
    }
}
add_action('atlas_operations_ready',['Atlas_Catalog_UI','register']);
