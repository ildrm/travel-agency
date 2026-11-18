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
    $vehicle=$create('vehicle',['category'=>'car','label'=>'Test car','capacity'=>4,'license_class'=>'test-class-B']);
    $vres=$must(Local::register_resource('vehicle',$vehicle,1,5),'vehicle resource'); $car=$vres['id']; $resourceIds[]=$car;
    $rentalPolicy=['policy_reference'=>'fictional-policy-v1','minimum_age'=>25,'coverage_reference'=>'fictional-coverage-v1','valid_until'=>'2028-01-01T00:00:00Z'];
    $must(Local::configure_rental_policy($car,$rentalPolicy),'reviewed rental policy');
    $eligibility=['renter_reference'=>'fictional-renter-01','age_years'=>30,'age_evidence_reference'=>'fictional-age-case-01','coverage_evidence_reference'=>'fictional-coverage-case-01','review_reference'=>'fictional-review-01','license_class'=>'test-class-B','license_expires_at'=>'2028-01-01T00:00:00Z','license_evidence_reference'=>'fictional-license-case-01'];
    if (wp_get_environment_type()==='local') {
        if (!defined('ATLAS_TEST_CLOCK')) define('ATLAS_TEST_CLOCK',true);
        DB::set_test_now('2027-01-10 10:00:00');
        $v1=$must(Local::allocate_vehicle($car,'2027-01-10T09:55:00Z','2027-01-10T10:10:00Z','rental','vehicle-first-1'),'rental');
        $check(is_wp_error(Local::allocate_vehicle($car,'2027-01-10T10:14:00Z','2027-01-10T10:18:00Z','maintenance','vehicle-maint-1')),'turnaround blocks overlap');
        $next=$must(Local::allocate_vehicle($car,'2027-01-10T10:20:00Z','2027-01-10T11:00:00Z','rental','vehicle-next-01'),'next rental');
        $missingReview=Local::start_vehicle($v1['id']);
        $check(is_wp_error($missingReview) && $missingReview->get_error_code()==='atlas_eligibility_required','pickup rejects missing manual eligibility review');
        $expired=$eligibility; $expired['license_expires_at']='2027-01-10T09:59:00Z';
        $expiredReview=Local::review_eligibility($v1['id'],$expired);
        $check(is_wp_error($expiredReview) && $expiredReview->get_error_code()==='atlas_license_expired','TC-RENT-002 expired reviewed license prevents handover approval');
        $underAge=$eligibility; $underAge['age_years']=24;
        $ageDenied=Local::review_eligibility($v1['id'],$underAge);
        $check(is_wp_error($ageDenied) && $ageDenied->get_error_code()==='atlas_age_policy','reviewed age must satisfy the explicitly configured agency policy');
        $wrongClass=$eligibility; $wrongClass['license_class']='another-class';
        $check(is_wp_error(Local::review_eligibility($v1['id'],$wrongClass)),'reviewed license class must match the immutable vehicle snapshot');
        $missingCoverage=$eligibility; unset($missingCoverage['coverage_evidence_reference']);
        $check(is_wp_error(Local::review_eligibility($v1['id'],$missingCoverage)),'pickup review requires age and coverage evidence references');
        $rawDocument=$eligibility; $rawDocument['license_number']='Do not retain this field';
        $check(is_wp_error(Local::review_eligibility($v1['id'],$rawDocument)),'raw licence or identity fields are rejected');
        $approvedReview=$must(Local::review_eligibility($v1['id'],$eligibility),'eligibility review');
        $replayedReview=$must(Local::review_eligibility($v1['id'],$eligibility),'eligibility review replay');
        $check(!$approvedReview['replayed'] && $replayedReview['replayed'],'reviewed evidence is retry-safe and does not duplicate approval');
        $changedReview=$eligibility; $changedReview['review_reference']='fictional-new-review';
        $check(is_wp_error(Local::review_eligibility($v1['id'],$changedReview)),'approved eligibility evidence is immutable');
        $must(Local::review_eligibility($next['id'],$eligibility),'next rental eligibility');
        $check(Local::start_vehicle($v1['id'])===true,'pickup records in-use state');
        DB::set_test_now('2027-01-10 10:25:00');
        $check(is_wp_error(Local::return_vehicle($v1['id'],'2027-01-10T09:59:00Z','Invalid pre-pickup return',false)),'return cannot precede actual pickup even after planned start');
        $inUse=$must(Local::inspect($car),'pickup evidence');
        $picked=array_values(array_filter($inUse['allocations'],static fn($a)=>$a['id']===$v1['id']));
        $check(count($picked)===1 && str_starts_with((string)$picked[0]['picked_up_at'],'2027-01-10 10:00:00'),'actual pickup evidence is persisted separately from planned start');
        $inUseDenied=Local::start_vehicle($next['id']);
        $check(is_wp_error($inUseDenied) && $inUseDenied->get_error_code()==='atlas_vehicle_in_use','next pickup blocked while previous vehicle is out');
        DB::set_test_now('2027-01-10 10:30:00');
        $return=$must(Local::return_vehicle($v1['id'],'2027-01-10T10:30:00Z','Scratch disputed',true),'late return');
        $check($return['conflict_count']===1 && $return['state']==='disputed','late return blocks conflicting next allocation and records dispute');
        $check(is_wp_error(Local::start_vehicle($next['id'])),'conflicting next pickup stays blocked');
        $check(is_wp_error(Local::allocate_vehicle($car,'2027-01-11T10:00:00Z','2027-01-11T11:00:00Z','rental','vehicle-later-1')),'unresolved dispute blocks new allocation');
        $check(Local::resolve_dispute($v1['id'],'Reviewed inspection')===true,'agency can resolve dispute with reason');
        DB::set_test_now('2027-01-11 10:00:00');
        $legacy=$must(Local::allocate_vehicle($car,'2027-01-11T09:55:00Z','2027-01-11T10:10:00Z','rental','legacy-pickup-01'),'legacy rental');
        $must(Local::review_eligibility($legacy['id'],$eligibility),'legacy rental eligibility');
        $must(Local::start_vehicle($legacy['id']),'legacy pickup');
        // Simulate an old v1 in-use row. Migration must not invent actual evidence.
        global $wpdb;
        $wpdb->update($wpdb->prefix.'atlas_local_allocations',['picked_up_at'=>null],['id'=>$legacy['id']]);
        update_option('atlas_local_inventory_schema','1',false);
        $must(Local::migrate(),'legacy migration');
        DB::set_test_now('2027-01-11 10:10:00');
        $missing=Local::return_vehicle($legacy['id'],'2027-01-11T10:05:00Z','Legacy return',false);
        $check(is_wp_error($missing) && $missing->get_error_code()==='atlas_pickup_review','legacy pickup remains unknown and return fails closed after migration');
        $check(is_wp_error(Local::allocate_vehicle($car,'2027-01-12T10:00:00Z','2027-01-12T11:00:00Z','rental','legacy-still-out')),'unknown legacy pickup still blocks new assignments');
        $check(Local::reconcile_pickup($legacy['id'],'2027-01-11T10:00:00Z','Reviewed fictional handover evidence')===true,'agency can explicitly reconcile missing pickup with reason');
        $check(is_wp_error(Local::reconcile_pickup($legacy['id'],'2027-01-11T10:01:00Z','Attempt overwrite')),'recorded pickup evidence cannot be silently overwritten');
        $check(!is_wp_error(Local::return_vehicle($legacy['id'],'2027-01-11T10:05:00Z','Reviewed return',false)),'return can complete after explicit pickup reconciliation');
        DB::set_test_now('2027-01-12 10:00:00');
        $stale=$must(Local::allocate_vehicle($car,'2027-01-12T10:00:00Z','2027-01-12T10:10:00Z','rental','license-expiry-at-pickup'),'expiry rental');
        $soon=$eligibility; $soon['license_expires_at']='2027-01-12T10:01:00Z';
        $must(Local::review_eligibility($stale['id'],$soon),'pre-expiry review');
        DB::set_test_now('2027-01-12 10:02:00');
        $expiredAtPickup=Local::start_vehicle($stale['id']);
        $check(is_wp_error($expiredAtPickup) && $expiredAtPickup->get_error_code()==='atlas_license_expired','license expiry is rechecked at actual pickup after prior approval');
        $must(Local::release($stale['id']),'release expired license rental');
        $bike=$create('vehicle',['category'=>'bicycle','label'=>'Test bicycle','capacity'=>1]);
        $bikeResource=$must(Local::register_resource('vehicle',$bike,1,0),'bicycle resource'); $resourceIds[]=$bikeResource['id'];
        $bikeRental=$must(Local::allocate_vehicle($bikeResource['id'],'2027-01-12T10:00:00Z','2027-01-12T11:00:00Z','rental','bicycle-rental-01'),'bicycle allocation');
        $bikeMissing=Local::start_vehicle($bikeRental['id']);
        $check(is_wp_error($bikeMissing) && $bikeMissing->get_error_code()==='atlas_policy_required','bicycle pickup also needs an explicit reviewed age and coverage policy');
        $bikePolicy=$rentalPolicy; $bikePolicy['minimum_age']=16;
        $must(Local::configure_rental_policy($bikeResource['id'],$bikePolicy),'bicycle policy');
        $bikeReview=$eligibility; unset($bikeReview['license_class'],$bikeReview['license_expires_at'],$bikeReview['license_evidence_reference']);
        $must(Local::review_eligibility($bikeRental['id'],$bikeReview),'bicycle eligibility without license data');
        $check(Local::start_vehicle($bikeRental['id'])===true,'reviewed bicycle eligibility does not require unnecessary license evidence');
        DB::set_test_now(null);
    } else { $skipped[]='late return requires local environment and test clock'; }
    // Two separately booted WordPress processes use independent real DB connections.
    // A shared release gate puts both contenders immediately before allocation.
    $raceProperty=$create('property',['name_en'=>'Concurrent Test Hotel','timezone'=>'Asia/Tehran']);
    $raceUnit=$create('unit_type',['property_id'=>$raceProperty,'name'=>'One room','capacity'=>1,'units'=>1,'whole_property'=>false]);
    $raceRes=$must(Local::register_resource('unit_type',$raceUnit,1,0),'race resource'); $resourceIds[]=$raceRes['id'];
    $raceVehicle=$create('vehicle',['category'=>'car','label'=>'Concurrent test car','capacity'=>4,'license_class'=>'test-class-B']);
    $raceVehicleRes=$must(Local::register_resource('vehicle',$raceVehicle,1,0),'vehicle race resource'); $resourceIds[]=$raceVehicleRes['id'];
    foreach ([['id'=>$raceRes['id'],'mode'=>'stay'],['id'=>$raceVehicleRes['id'],'mode'=>'vehicle']] as $raceTarget) {
    $script=tempnam(sys_get_temp_dir(),'atlas-room-race-'); $gate=tempnam(sys_get_temp_dir(),'atlas-room-gate-'); unlink($gate);
    $child=<<<'CHILD'
<?php
// Pin the same permitted local/test DB explicitly; never allocate in another DB.
if ($argv[7]==='atlas_integration') { putenv('ATLAS_TEST_DB=atlas_integration'); } else { putenv('ATLAS_TEST_DB'); }
require $argv[1].'wp-load.php';
if (DB_NAME!==$argv[7]) { fwrite(STDERR,"Inventory race database selection mismatch\n"); exit(3); }
wp_set_current_user((int)$argv[3]);
file_put_contents($argv[5].'.'.$argv[6].'.ready','ready');
for ($i=0; !is_file($argv[5]) && $i<2000; $i++) { usleep(10000); }
if (!is_file($argv[5])) { fwrite(STDERR,"Room race release gate timed out\n"); exit(2); }
$r=$argv[8]==='stay'?\Atlas\Inventory\Local::allocate_stay($argv[2],'2027-07-20','2027-07-21',1,$argv[4]):\Atlas\Inventory\Local::allocate_vehicle($argv[2],'2027-07-20T09:00:00Z','2027-07-20T10:00:00Z','rental',$argv[4]);
echo 'ATLAS_ROOM_RESULT:'.wp_json_encode((is_wp_error($r)?['accepted'=>false,'code'=>$r->get_error_code()]:['accepted'=>true,'id'=>$r['id']])+['database_matches'=>true])."\n";
CHILD;
    file_put_contents($script,$child); $workers=[]; $raceOutput=[];
    try {
        foreach (['a','b'] as $suffix) {
            $pipes=[]; $process=proc_open([PHP_BINARY,$script,ABSPATH,$raceTarget['id'],(string)$admins[0],'inventory-race-'.wp_generate_uuid4(),$gate,$suffix,DB_NAME,$raceTarget['mode']],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
            if (is_resource($process)) { fclose($pipes[0]); $workers[]=[$process,$pipes[1],$pipes[2]]; }
        }
        for ($i=0; (!is_file($gate.'.a.ready') || !is_file($gate.'.b.ready')) && $i<1000; $i++) { usleep(10000); }
        $bothReady=is_file($gate.'.a.ready') && is_file($gate.'.b.ready'); file_put_contents($gate,'go');
        foreach ($workers as [$process,$stdout,$stderr]) {
            $output=stream_get_contents($stdout); $error=stream_get_contents($stderr); fclose($stdout); fclose($stderr); $exit=proc_close($process);
            $parsed=null; if (preg_match('/ATLAS_ROOM_RESULT:(\{[^\r\n]+\})/',$output,$match)) $parsed=json_decode($match[1],true);
            $raceOutput[]=['exit'=>$exit,'result'=>$parsed,'output'=>$output,'error'=>$error];
        }
        $accepted=count(array_filter($raceOutput,static fn($r)=>$r['exit']===0 && ($r['result']['database_matches']??false)===true && ($r['result']['accepted']??false)===true));
        $rejected=count(array_filter($raceOutput,static fn($r)=>$r['exit']===0 && ($r['result']['database_matches']??false)===true && ($r['result']['accepted']??null)===false && ($r['result']['code']??'')==='atlas_unavailable'));
        $label=$raceTarget['mode']==='stay'?'TC-STAY-001 two independent DB contenders allocate the last room exactly once':'TC-RENT-001 two independent DB contenders allocate an overlapping vehicle exactly once';
        $check($bothReady && count($raceOutput)===2 && $accepted===1 && $rejected===1,$label);
        global $wpdb;
        $committed=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->prefix."atlas_local_allocations WHERE resource_id=%s AND state='allocated'",$raceTarget['id']));
        if ($raceTarget['mode']==='stay') {
            $allocated=(int)$wpdb->get_var($wpdb->prepare('SELECT allocated FROM '.$wpdb->prefix.'atlas_local_nights WHERE resource_id=%s AND local_date=%s',$raceTarget['id'],'2027-07-20'));
            $check($allocated===1 && $committed===1,'last-room race preserves persisted capacity and one committed allocation');
        } else { $check($committed===1,'vehicle race preserves one committed interval allocation'); }
        if (!$bothReady || $accepted!==1 || $rejected!==1) foreach ($raceOutput as $result) WP_CLI::log('Race diagnostic: '.wp_json_encode($result));
    } finally {
        foreach ([$script,$gate,$gate.'.a.ready',$gate.'.b.ready'] as $file) if (is_file($file)) unlink($file);
    }
    }
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
    if (isset($legacy)) $check(is_wp_error(Local::reconcile_pickup($legacy['id'],'2027-01-11T10:00:00Z','Unauthorized change')),'non-agency actor cannot reconcile pickup evidence');
    $reviewDenied=Local::review_eligibility($v1['id'],$eligibility);
    $check(is_wp_error($reviewDenied) && $reviewDenied->get_error_code()==='atlas_forbidden','non-agency actor cannot approve rental eligibility');
    $policyDenied=Local::configure_rental_policy($car,$rentalPolicy);
    $check(is_wp_error($policyDenied) && $policyDenied->get_error_code()==='atlas_forbidden','non-agency actor cannot configure reviewed rental policy');
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
