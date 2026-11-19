<?php
/** Fresh, isolated fictional stay/browser actors. Never repairs or overwrites other demonstration/customer records. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='local' || !defined('ATLAS_DEMO_MODE') || ATLAS_DEMO_MODE!==true) throw new RuntimeException('Resource browser seeding requires explicit local demonstration mode.');
if (getenv('ATLAS_TEST_DB')) throw new RuntimeException('Unset ATLAS_TEST_DB for this seed; it must target the database served by the local browser runtime.');
if (!class_exists('Atlas_Resource_Booking') || !class_exists('Atlas\Inventory\Local') || !class_exists('WC_Product_Simple')) throw new RuntimeException('Activate the Atlas resource bridge, local inventory and WooCommerce first.');
require_once ABSPATH.'wp-admin/includes/user.php';
$require=static function($condition,$message) { if (!$condition) throw new RuntimeException($message); };
$require(Atlas_Resource_Booking::enabled(),'Resource booking is not enabled in this local fixture runtime.');
$ready=Atlas\Core\DB::ready(); $require(!is_wp_error($ready),'Migrate the current Core schema before resource browser seeding.');
$inventory=Atlas\Inventory\Local::migrate(); $require(!is_wp_error($inventory),'Local inventory migration is not ready.');
$require(get_woocommerce_currency()==='IRR','This browser fixture requires the local store to use IRR.');
$checkout_page=get_post(wc_get_page_id('checkout')); $require($checkout_page && str_contains($checkout_page->post_content,'wp:woocommerce/checkout'),'Configure the native WooCommerce Checkout Blocks page before this fixture.');
$gateways=WC()->payment_gateways()->payment_gateways(); $require(isset($gateways['atlas_simulator']) && $gateways['atlas_simulator']->enabled==='yes','Activate the explicitly local Atlas simulator before this fixture.');
$origin=wp_parse_url(home_url('/')); $require(in_array($origin['host']??'',array('127.0.0.1','localhost','::1'),true),'Resource browser fixtures are restricted to loopback WordPress URLs.');
$root=dirname(__DIR__); $runtime=$root.'/.runtime'; if (!is_dir($runtime)) mkdir($runtime,0700,true);
$users=array(); $records=array(); $resources=array(); $offers=array(); $products=array(); $orgs=array(); $keys=array(); $success=false;
$tag=strtolower(gmdate('YmdHis').'-'.wp_generate_password(6,false,false));
$credentials=array();
$call=static function($method,$route,$body,$key='') use(&$keys) { $r=new WP_REST_Request($method,$route); $r->set_header('content-type','application/json'); $r->set_body(wp_json_encode($body)); if ($key!=='') { $r->set_header('Idempotency-Key',$key); $keys[]=$key; } return rest_do_request($r); };
try {
    foreach (array('customer'=>'customer','supplier'=>'subscriber','agency'=>'administrator') as $name=>$role) {
        $login='atlas_stay_'.$name.'_'.str_replace('-','',$tag); $password=wp_generate_password(32,true,true);
        $id=wp_insert_user(array('user_login'=>$login,'user_pass'=>$password,'user_email'=>'stay-'.$name.'-'.$tag.'@example.test','display_name'=>'Fictional stay '.$name,'role'=>$role));
        $require(!is_wp_error($id),'Could not create a dedicated fictional browser actor.'); $users[$name]=(int)$id;
        update_user_meta($id,'locale','en_US'); update_user_meta($id,'_atlas_resource_browser_fixture',$tag);
        $credentials[$name.'_user']=$login; $credentials[$name.'_password']=$password;
        if ($name==='supplier' || $name==='agency') foreach (array('atlas_manage_records','atlas_manage_offers') as $cap) get_userdata($id)->add_cap($cap);
        if ($name==='agency') get_userdata($id)->add_cap('atlas_manage_bookings');
    }
    global $wpdb;
    $require($wpdb->insert(Atlas\Core\DB::table('organizations'),array('name'=>'Fictional browser lodging operator '.$tag,'status'=>'active','created_at'=>Atlas\Core\DB::now()))===1,'Could not create the isolated fictional organization.');
    $org=(int)$wpdb->insert_id; $orgs[]=$org;
    $require($wpdb->insert(Atlas\Core\DB::table('memberships'),array('org_id'=>$org,'user_id'=>$users['supplier'],'role'=>'contributor','status'=>'active','updated_at'=>Atlas\Core\DB::now()))===1,'Could not create the explicit supplier membership.');
    $create_reviewed=static function($type,$fields) use($call,$require,$users,&$records,$tag) {
        wp_set_current_user($users['supplier']);
        $created=$call('POST','/agency/v1/operations/'.$type,$fields,'resource_browser_'.$tag.'_'.$type);
        $require($created->get_status()===200,'Could not save the fictional '.$type.' through the normal API.'); $record=$created->get_data(); $records[]=$record['id'];
        $submitted=$call('PATCH','/agency/v1/operations/'.$type.'/'.$record['id'],array('version'=>$record['version'],'changes'=>array('state'=>'submitted')));
        $require($submitted->get_status()===200,'Could not submit the fictional '.$type.' for review.');
        wp_set_current_user($users['agency']); $submitted=$submitted->get_data();
        $approved=$call('PATCH','/agency/v1/operations/'.$type.'/'.$record['id'],array('version'=>$submitted['version'],'changes'=>array('state'=>'approved')));
        $require($approved->get_status()===200,'Independent agency review of the fictional '.$type.' failed.'); return $approved->get_data();
    };
    $property=$create_reviewed('property',array('org_id'=>$org,'name_en'=>'Fictional browser house','name_fa'=>'خانه فرضی مرورگر','address'=>'Fictional local test address; no real property.','timezone'=>'Asia/Tehran','features'=>array(),'policy'=>'Fictional manually reviewed local allotment only.'));
    $unit=$create_reviewed('unit_type',array('org_id'=>$org,'property_id'=>$property['id'],'name'=>'Fictional browser double room','capacity'=>2,'units'=>2,'whole_property'=>false));
    wp_set_current_user($users['supplier']); $resource=Atlas\Inventory\Local::register_resource('unit_type',$unit['id'],1,0);
    $require(!is_wp_error($resource),'Could not register the reviewed fictional room-night resource.'); $resources[]=$resource['id'];
    $now=new DateTimeImmutable(Atlas\Core\DB::now(),new DateTimeZone('UTC')); $local=$now->setTimezone(new DateTimeZone('Asia/Tehran'));
    $check_in=$local->modify('+7 days')->format('Y-m-d'); $check_out=$local->modify('+10 days')->format('Y-m-d');
    $nightly=8000000; $tax=100000; $total=3*$nightly+$tax;
    $title='Fictional three-night browser stay '.$tag;
    $offer=Atlas\Core\Offers::save(0,array('kind'=>'stay','org_id'=>$org,'title_en'=>$title,'title_fa'=>'اقامت سه‌شب فرضی مرورگر','description_en'=>'A fictional local-only browser journey. Two-person room, three local nights, explicit reviewed tax and separate supplier acceptance. No real room or supplier is represented.','description_fa'=>'نمایش فرضی و فقط محلی: اتاق دونفره، سه شب محلی، مالیات صریح و پذیرش جداگانه تأمین‌کننده. هیچ ملک واقعی معرفی نمی‌شود.','registration_open_at'=>$now->modify('-1 day')->format('Y-m-d\TH:i:s\Z'),'registration_close_at'=>$now->modify('+30 days')->format('Y-m-d\TH:i:s\Z'),'timezone'=>'Asia/Tehran','capacity'=>0,'amount_irr'=>0,'mode'=>'request_only','status'=>'draft','product_id'=>0),$users['supplier']);
    $require(!is_wp_error($offer),'Could not create the fictional request-only accommodation offer.'); $offers[]=$offer;
    wp_set_current_user($users['agency']);
    $rateFields=array('version'=>0,'name_en'=>'Standard stay','name_fa'=>'اقامت استاندارد','resource_id'=>$resource['id'],'start_date'=>$local->format('Y-m-d'),'end_date'=>$local->modify('+60 days')->format('Y-m-d'),'timezone'=>'Asia/Tehran','valid_until'=>$now->modify('+30 days')->format('Y-m-d\TH:i:s\Z'),'unit_amount_irr'=>$nightly,'tax_per_unit_irr'=>$tax,'protection_irr'=>0,'min_duration'=>1,'max_duration'=>30,'terms_en'=>'Fictional reviewed rate: 8,000,000 IRR per local night plus 100,000 IRR per reserved room in disclosed tax. Checkout date is exclusive. Payment does not confirm supplier acceptance. No deposit is collected.','terms_fa'=>'قیمت فرضی بازبینی‌شده: هر شب محلی ۸٬۰۰۰٬۰۰۰ ریال به‌علاوه ۱۰۰٬۰۰۰ ریال مالیات هر اتاق. روز خروج محاسبه نمی‌شود. پرداخت به معنی پذیرش تأمین‌کننده نیست. ودیعه دریافت نمی‌شود.','policy_reference'=>'fictional-browser-stay-policy-v1','supplier_reference'=>'fictional-browser-allotment-'.$tag,'supplier_users'=>array($users['supplier']),'published'=>true,'reason'=>'Fictional independent review for one hidden local browser journey.');
    $rate=$call('POST','/agency/v1/resource-offers/'.$offer,$rateFields);
    $require($rate->get_status()===200,'Normal agency rate review failed.');
    $plan=$rate->get_data()['id'];
    $records[]=$plan;
    $alternateNightly=$nightly+1000000;
    $alternate=$call('POST','/agency/v1/resource-offers/'.$offer,array_replace($rateFields,[
        'plan_id'=>wp_generate_uuid4(),
        'name_en'=>'Flexible stay',
        'name_fa'=>'اقامت انعطاف‌پذیر',
        'unit_amount_irr'=>$alternateNightly,
        'terms_en'=>'Fictional flexible plan at 9,000,000 IRR per night plus 100,000 IRR per reserved room.',
        'terms_fa'=>'طرح فرضی انعطاف‌پذیر با قیمت ۹٬۰۰۰٬۰۰۰ ریال برای هر شب و ۱۰۰٬۰۰۰ ریال برای هر اتاق.',
    ]));
    $require($alternate->get_status()===200,'Second reviewed rate plan failed.');
    $alternatePlan=$alternate->get_data()['id'];
    $records[]=$alternatePlan;
    $product=new WC_Product_Simple(); $product->set_name($title); $product->set_status('publish'); $product->set_virtual(true); $product->set_catalog_visibility('hidden'); $product->set_tax_status('none');
    $product->set_regular_price('1'); // The browser must observe persisted dated quote pricing, never this placeholder.
    $product_id=$product->save(); $require($product_id>0,'Could not create the fictional virtual checkout product.'); $products[]=$product_id;
    $activated=$call('POST','/agency/v1/resource-offers/'.$offer.'/activate',array('version'=>1,'product_id'=>$product_id));
    $require($activated->get_status()===200,'Normal reviewed resource activation failed.');
    // Do not seed this reverse marker manually: the normal agency activation must establish both directions.
    $require((int)get_post_meta($product_id,'_atlas_offer_id',true)===(int)$offer,'Reviewed activation did not create the reciprocal Woo product link. Fix the activation path before this browser proof.');
    // Dedicated test stock avoids consuming or replenishing the storefront demo offer.
    $eventProduct = new WC_Product_Simple();
    $eventProduct->set_name('A morning in nature · browser checkout');
    $eventProduct->set_virtual(true);
    $eventProduct->set_catalog_visibility('hidden');
    $eventProduct->set_regular_price('11800000');
    $eventProduct->set_tax_status('none');
    $eventProductId = $eventProduct->save();
    $require($eventProductId>0,'Could not create the fictional event checkout product.');
    $products[]=$eventProductId;
    $event = Atlas\Core\Offers::save(0,[
        'kind'=>'event','org_id'=>0,'title_en'=>'A morning in nature · browser checkout',
        'title_fa'=>'صبحی در طبیعت · آزمایش ثبت سفارش','description_en'=>'Fictional booking test.',
        'description_fa'=>'آزمایش رزرو فرضی.','registration_open_at'=>$now->modify('-1 day')->format('Y-m-d\TH:i:s\Z'),
        'registration_close_at'=>$now->modify('+1 day')->format('Y-m-d\TH:i:s\Z'),
        'timezone'=>'Asia/Tehran','capacity'=>4,'amount_irr'=>11800000,'mode'=>'local',
        'status'=>'published','product_id'=>$eventProductId,
    ],$users['agency']);
    $require(!is_wp_error($event),'Could not prepare fresh fictional event capacity.');
    $offers[]=$event;
    update_post_meta($eventProductId,'_atlas_offer_id',$event);
    $merch = new WC_Product_Simple();
    $merch->set_name('Paper trail journal · fictional shipping check');
    $merch->set_catalog_visibility('hidden');
    $merch->set_regular_price('2500000');
    $merch->set_manage_stock(true);
    $merch->set_stock_quantity(2);
    $merchId=$merch->save();
    $require($merchId>0,'Could not create the fictional physical checkout product.');
    $products[]=$merchId;
    $fixture=array('fixture_run'=>$tag,'event_offer_id'=>$event,'merch_product_id'=>$merchId,'scope'=>'fresh fictional local browser stay and event','base_url'=>home_url('/'),'offer_id'=>$offer,'product_id'=>$product_id,'resource_id'=>$resource['id'],'title'=>$title,'timezone'=>'Asia/Tehran','check_in'=>$check_in,'check_out'=>$check_out,'nights'=>3,'quantity'=>1,'guests_per_unit'=>2,'nightly_irr'=>$nightly,'rate_plan_id'=>$plan,'alternate_rate_plan_id'=>$alternatePlan,'alternate_amount_irr'=>3*$alternateNightly+$tax,'tax_per_unit_irr'=>$tax,'expected_amount_irr'=>$total,'placeholder_product_price_irr'=>1,'customer_email'=>'stay-customer-'.$tag.'@example.test','checkout_url'=>add_query_arg('lang','en',wc_get_checkout_url()),'created_at'=>$now->format('Y-m-d\TH:i:s\Z'));
    $previous_mask=umask(0077);
    try { foreach (array('resource-browser-credentials.json'=>$credentials,'resource-browser-fixtures.json'=>$fixture) as $name=>$data) { $path=$runtime.'/'.$name; $require(file_put_contents($path,wp_json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))!==false,'Could not save private resource browser fixture data.'); chmod($path,0600); } }
    finally { umask($previous_mask); }
    $success=true; WP_CLI::success('Prepared a fresh fictional three-night stay and three isolated browser actors. Private fixture files are in .runtime/resource-browser-*.json; no credentials were printed.');
} finally {
    if (!$success) {
        foreach ($resources as $id) { $wpdb->delete($wpdb->prefix.'atlas_local_nights',array('resource_id'=>$id)); $wpdb->delete($wpdb->prefix.'atlas_local_resources',array('id'=>$id)); }
        foreach ($records as $id) { $wpdb->delete(Atlas\Core\DB::table('records'),array('id'=>$id)); $wpdb->delete(Atlas\Core\DB::table('audit'),array('object_id'=>$id)); $wpdb->delete(Atlas\Core\DB::table('outbox'),array('aggregate_id'=>$id)); }
        foreach ($offers as $id) { $wpdb->delete(Atlas\Core\DB::table('inventory'),array('offer_id'=>$id)); wp_delete_post($id,true); }
        foreach ($products as $id) wp_delete_post($id,true);
        foreach ($orgs as $id) { $wpdb->delete(Atlas\Core\DB::table('memberships'),array('org_id'=>$id)); $wpdb->delete(Atlas\Core\DB::table('organizations'),array('id'=>$id)); }
        foreach ($keys as $key) $wpdb->delete(Atlas\Core\DB::table('idempotency'),array('idem_key'=>$key));
        foreach ($users as $id) wp_delete_user($id);
    }
    wp_set_current_user(0);
}
