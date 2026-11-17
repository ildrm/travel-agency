<?php
/** Isolated staging only: wp eval-file tests/inventory-integration.php. */
if (!defined('WP_CLI') || !WP_CLI) exit("Run via WP-CLI on an isolated local test site.\n");
use Atlas\Core\DB;
use Atlas\Core\Records;
use Atlas\Inventory\Local;
use Atlas\Inventory\Routes;
if (!class_exists(Local::class) || !class_exists(Records::class) || !function_exists('atlas_operation_schemas')) WP_CLI::error('Activate Atlas Core, Atlas Operations and Atlas Local Inventory.');
if (wp_get_environment_type()!=='local') WP_CLI::error('This test requires an isolated local environment.');
if (!defined('ATLAS_DEMO_MODE')) {
    $blocked=Local::inspect('00000000-0000-0000-0000-000000000000');
    if (!is_wp_error($blocked) || $blocked->get_error_code()!=='atlas_disabled') WP_CLI::error('Default-off gate did not reject a local read.');
    WP_CLI::log('PASS inventory disabled without explicit local demo flag');
    define('ATLAS_DEMO_MODE',true);
}
if (ATLAS_DEMO_MODE!==true) WP_CLI::error('Explicit local ATLAS_DEMO_MODE=true is required for inventory tests.');
$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
if (!$admins) WP_CLI::error('An administrator test actor is required.');
wp_set_current_user((int)$admins[0]);
$failed=[]; $skipped=[]; $coreIds=[]; $resourceIds=[]; $tempUser=0;
$check=static function($condition,$label) use (&$failed) { if ($condition) WP_CLI::log('PASS '.$label); else { $failed[]=$label; WP_CLI::warning('FAIL '.$label); } };
$must=static function($v,$label) { if (is_wp_error($v)) throw new RuntimeException($label.': '.$v->get_error_message()); return $v; };
$create=static function($type,$fields) use (&$coreIds,$must) { $r=$must(Records::create($type,$fields,get_current_user_id()),'create '.$type); $coreIds[]=$r['id']; return $r['id']; };
try {
    $check(Local::migrate()===true && Local::migrate()===true,'schema migration is repeatable');
    $property=$create('property',['name_en'=>'Inventory Test Hotel','timezone'=>'Asia/Tehran']);
    $units=$create('unit_type',['property_id'=>$property,'name'=>'Test rooms','capacity'=>2,'units'=>2,'whole_property'=>false]);
    $res=$must(Local::register_resource('unit_type',$units,2,0),'hotel resource'); $hotel=$res['id']; $resourceIds[]=$hotel;
    $first=$must(Local::allocate_stay($hotel,'2027-06-10','2027-06-13',1,'stay-first-001'),'first stay');
    $check(is_wp_error(Local::allocate_stay($hotel,'2027-06-11','2027-06-12',1,'stay-short-001')),'minimum stay enforced');
    $check(is_wp_error(Local::blackout($hotel,'2027-06-11','2027-06-12',true)),'blackout cannot override allocation');
    $check(is_wp_error(Local::allocate_stay($hotel,'2027-06-12','2027-06-14',2,'stay-full-001')),'one full night rejects entire stay');
    $second=$must(Local::allocate_stay($hotel,'2027-06-13','2027-06-15',2,'stay-second-001'),'post-rollback stay');
    $check($second['state']==='allocated','failed multi-night allocation rolled back later nights');
    $replay=Local::allocate_stay($hotel,'2027-06-13','2027-06-15',2,'stay-second-001');
    $check(!is_wp_error($replay) && $replay['id']===$second['id'] && $replay['replayed'],'same reference is idempotent');
    $check(is_wp_error(Local::allocate_stay($hotel,'2027-06-13','2027-06-16',1,'stay-second-001')),'changed reuse of reference conflicts');
    $check(is_wp_error(Local::capacity($hotel,1,2)),'capacity cannot be reduced below allocations');
    $check(Local::release($second['id'])===true,'stay release returns capacity once');
    $check(is_wp_error(Local::release($second['id'])),'duplicate release is rejected');
    $check(Local::blackout($hotel,'2027-06-15','2027-06-17',true)===true,'blackout can be set on empty nights');
    $check(is_wp_error(Local::allocate_stay($hotel,'2027-06-15','2027-06-17',1,'blocked-stay-1')),'blackout enforced');
    $villa=$create('property',['name_en'=>'Test villa','timezone'=>'Asia/Tehran']);
    $villaUnit=$create('unit_type',['property_id'=>$villa,'name'=>'Whole villa','capacity'=>4,'units'=>1,'whole_property'=>true]);
    $villaRes=$must(Local::register_resource('unit_type',$villaUnit,1,0),'villa resource'); $resourceIds[]=$villaRes['id'];
    $must(Local::allocate_stay($villaRes['id'],'2027-06-10','2027-06-13',1,'villa-first-01'),'villa stay');
    $check(is_wp_error(Local::allocate_stay($villaRes['id'],'2027-06-12','2027-06-14',1,'villa-clash-01')),'whole villa exclusive on overlapping night');
    $otherUnit=$create('unit_type',['property_id'=>$villa,'name'=>'Other room','capacity'=>1,'units'=>1,'whole_property'=>false]);
    $check(is_wp_error(Local::register_resource('unit_type',$otherUnit,1,0)),'whole villa cannot coexist with another category');
    $vehicle=$create('vehicle',['category'=>'car','label'=>'Test car','capacity'=>4]);
    $vres=$must(Local::register_resource('vehicle',$vehicle,1,5),'vehicle resource'); $car=$vres['id']; $resourceIds[]=$car;
    if (wp_get_environment_type()==='local') {
        if (!defined('ATLAS_TEST_CLOCK')) define('ATLAS_TEST_CLOCK',true);
        DB::set_test_now('2027-01-10 10:00:00');
        $v1=$must(Local::allocate_vehicle($car,'2027-01-10T09:55:00Z','2027-01-10T10:10:00Z','rental','vehicle-first-1'),'rental');
        $check(is_wp_error(Local::allocate_vehicle($car,'2027-01-10T10:14:00Z','2027-01-10T10:18:00Z','maintenance','vehicle-maint-1')),'turnaround blocks overlap');
        $next=$must(Local::allocate_vehicle($car,'2027-01-10T10:20:00Z','2027-01-10T11:00:00Z','rental','vehicle-next-01'),'next rental');
        $check(Local::start_vehicle($v1['id'])===true,'pickup records in-use state');
        DB::set_test_now('2027-01-10 10:25:00');
        $check(is_wp_error(Local::start_vehicle($next['id'])),'next pickup blocked while previous vehicle is out');
        DB::set_test_now('2027-01-10 10:30:00');
        $return=$must(Local::return_vehicle($v1['id'],'2027-01-10T10:30:00Z','Scratch disputed',true),'late return');
        $check($return['conflict_count']===1 && $return['state']==='disputed','late return blocks conflicting next allocation and records dispute');
        $check(is_wp_error(Local::start_vehicle($next['id'])),'conflicting next pickup stays blocked');
        $check(is_wp_error(Local::allocate_vehicle($car,'2027-01-11T10:00:00Z','2027-01-11T11:00:00Z','rental','vehicle-later-1')),'unresolved dispute blocks new allocation');
        $check(Local::resolve_dispute($v1['id'],'Reviewed inspection')===true,'agency can resolve dispute with reason');
        DB::set_test_now(null);
    } else { $skipped[]='late return requires local environment and test clock'; }
    $request=new WP_REST_Request('GET','/atlas-inventory/v1/resources/'.$hotel);
    $check(is_wp_error(Routes::permission($request)),'REST without nonce rejected');
    $request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
    $check(Routes::permission($request)===true,'authorized REST nonce accepted');
    $response=rest_do_request($request);
    $check($response->get_status()===200 && ($response->get_data()['mode']??'')==='internal_only','authorized REST resource endpoint returns internal inventory');
    $name='inventory-test-'.wp_generate_password(8,false,false);
    $tempUser=wp_create_user($name,wp_generate_password(24,true,true),$name.'@example.invalid');
    if (is_wp_error($tempUser)) throw new RuntimeException('Could not create isolation test user');
    $user=new WP_User($tempUser); $user->add_cap('atlas_manage_records'); wp_set_current_user($tempUser);
    $check(is_wp_error(Local::inspect($hotel)),'another user with capability cannot access owned resource');
    wp_set_current_user((int)$admins[0]);
} catch (Throwable $e) { $failed[]='Fixture error: '.$e->getMessage(); WP_CLI::warning(end($failed)); }
finally {
    wp_set_current_user((int)$admins[0]);
    if (wp_get_environment_type()==='local' && defined('ATLAS_TEST_CLOCK') && ATLAS_TEST_CLOCK===true) DB::set_test_now(null);
    global $wpdb;
    foreach ($resourceIds as $id) { $wpdb->delete($wpdb->prefix.'atlas_local_allocations',['resource_id'=>$id]); $wpdb->delete($wpdb->prefix.'atlas_local_nights',['resource_id'=>$id]); $wpdb->delete($wpdb->prefix.'atlas_local_resources',['id'=>$id]); }
    foreach ($coreIds as $id) $wpdb->delete(DB::table('records'),['id'=>$id]);
    if ($tempUser && !is_wp_error($tempUser)) { require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($tempUser); }
}
foreach ($skipped as $s) WP_CLI::log('SKIP '.$s);
WP_CLI::log('Failures: '.count($failed).'; skipped: '.count($skipped));
if ($failed) WP_CLI::error(implode('; ',$failed));
WP_CLI::success('Local inventory smoke checks completed; skipped tests need follow-up.');
