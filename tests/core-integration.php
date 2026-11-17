<?php
/** Run only in an isolated local WP-CLI environment; this creates fictional test fixtures. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local') { throw new RuntimeException('Local WP-CLI environment required.'); }
if (!defined('ATLAS_TEST_CLOCK')) { define('ATLAS_TEST_CLOCK', true); }
use Atlas\Core\DB;
use Atlas\Core\Offers;
use Atlas\Core\Booking;
use Atlas\Core\VerifiedPayment;
use Atlas\Core\Money;
$passed=0; $failed=0;
$check=static function(bool $ok,string $name) use (&$passed,&$failed): void { if ($ok) { $passed++; echo "PASS $name\n"; } else { $failed++; echo "FAIL $name\n"; } };
$users=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
if (!$users) { WP_CLI::error('A local administrator fixture is required.'); }
$admin=(int)$users[0]; wp_set_current_user($admin); $nonce=substr(wp_generate_password(12,false,false),0,12);
$user=wp_insert_user(['user_login'=>'atlas_test_'.$nonce,'user_pass'=>wp_generate_password(32),'user_email'=>'atlas_test_'.$nonce.'@example.invalid','role'=>'subscriber']);
if (is_wp_error($user)) { WP_CLI::error('Could not create isolated user fixture.'); }
$user=(int)$user;
$check(DB::migrate()===true && DB::migrate()===true,'migration idempotence');
$make=static function(int $cap=1) use ($admin): int {
    wp_set_current_user($admin);
    $r=Offers::save(0,['kind'=>'event','org_id'=>0,'title_en'=>'Fictional test occurrence','title_fa'=>'','description_en'=>'','description_fa'=>'','registration_open_at'=>'2027-06-01T05:30:00.000000Z','registration_close_at'=>'2027-06-15T14:30:00.000000Z','timezone'=>'Asia/Tehran','capacity'=>$cap,'amount_irr'=>1000000,'mode'=>'local','status'=>'published','product_id'=>0],$admin);
    if (is_wp_error($r)) { throw new RuntimeException('Offer setup failed: '.$r->get_error_message()); }
    return $r;
};
$offer=$make(2); wp_set_current_user($user);
DB::set_test_now('2027-06-01T05:29:59.999000Z');
$check(is_wp_error(Booking::hold($offer,1,1,$user,'before-open-'.$nonce)),'TC-REG-001 before open by 1ms denied');
DB::set_test_now('2027-06-01T05:30:00.000000Z');
$a=Booking::hold($offer,1,1,$user,'open-'.$nonce);
$check(is_array($a),'TC-REG-001 exactly open admitted');
$retry=Booking::hold($offer,1,1,$user,'open-'.$nonce);
$check(is_array($a) && is_array($retry) && $a['booking_id']===$retry['booking_id'],'TC-REG-004 identical idempotent retry');
$conflict=Booking::hold($offer,1,2,$user,'open-'.$nonce);
$check(is_wp_error($conflict) && $conflict->get_error_code()==='atlas_idempotency_conflict','TC-REG-004 differing payload conflicts');
$second=Booking::hold($offer,1,1,$user,'second-'.$nonce);
$third=Booking::hold($offer,1,1,$user,'third-'.$nonce);
$check(is_array($second) && is_wp_error($third),'capacity never permits a third seat');
$check(Money::irr_to_toman(1250000)==='125000' && Money::irr_to_toman(1250001)==='125000.1','IRR/toman uses exact integer conversion');
if (is_array($a)) {
    $wrong=VerifiedPayment::from_adapter('local_test','wrong-'.$nonce,999999);
    $check($wrong instanceof VerifiedPayment && is_wp_error(Booking::verify($a['booking_id'],$wrong)),'payment amount mismatch fails closed');
    $proof=VerifiedPayment::from_adapter('local_test','ok-'.$nonce,1000000);
    $paid=$proof instanceof VerifiedPayment ? Booking::verify($a['booking_id'],$proof) : $proof;
    $again=$proof instanceof VerifiedPayment ? Booking::verify($a['booking_id'],$proof) : $proof;
    $check(is_array($paid) && $paid['status']==='paid_pending_confirmation' && is_array($again) && $again['status']===$paid['status'],'timely proof without paid_at and duplicate replay');
    $check(is_wp_error(Booking::confirm($a['booking_id'],$user,'unauthorized')),'customer cannot confirm own booking');
    wp_set_current_user($admin); $confirmed=Booking::confirm($a['booking_id'],$admin,'Fictional test confirmation');
    $check(is_array($confirmed) && $confirmed['status']==='confirmed','agency confirmation separate from payment');
}
$near=$make(); wp_set_current_user($user); DB::set_test_now('2027-06-15T14:29:59.999000Z');
$last=Booking::hold($near,1,1,$user,'last-ms-'.$nonce);
$check(is_array($last) && str_starts_with($last['expires_at'],'2027-06-15T14:30:00.'),'TC-REG-001 close minus 1ms admitted, capped deadline');
DB::set_test_now('2027-06-15T14:30:00.000000Z');
$check(is_wp_error(Booking::hold($near,1,1,$user,'at-close-'.$nonce)),'TC-REG-001 exact close denied');
if (is_array($last)) {
    $released=Booking::expire($last['hold_id']); DB::set_test_now('2027-06-15T14:30:00.001000Z');
    $late=VerifiedPayment::from_adapter('local_test','late-'.$nonce,1000000,'2027-06-15T14:29:59.999000Z');
    $out=$late instanceof VerifiedPayment ? Booking::verify($last['booking_id'],$late) : $late;
    $check(is_array($released) && $released['state']==='released' && is_array($out) && $out['status']==='exception' && $out['payment_status']==='verified','TC-REG-003 released then verified stays exception');
}
$lateOffer=$make(); wp_set_current_user($user); DB::set_test_now('2027-06-15T14:29:59.000000Z');
$l=Booking::hold($lateOffer,1,1,$user,'late-valid-'.$nonce); DB::set_test_now('2027-06-15T14:30:00.001000Z');
if (is_array($l)) { $proof=VerifiedPayment::from_adapter('local_test','timely-provider-'.$nonce,1000000,'2027-06-15T14:29:59.500000Z'); $r=$proof instanceof VerifiedPayment ? Booking::verify($l['booking_id'],$proof) : $proof; $check(is_array($r) && $r['status']==='paid_pending_confirmation','late local verification with trusted timely provider time and unreleased hold'); }
$unknownOffer=$make(); wp_set_current_user($user); DB::set_test_now('2027-06-15T14:29:59.000000Z');
$u=Booking::hold($unknownOffer,1,1,$user,'late-unknown-'.$nonce); DB::set_test_now('2027-06-15T14:30:00.001000Z');
if (is_array($u)) { $proof=VerifiedPayment::from_adapter('local_test','unknown-time-'.$nonce,1000000); $r=$proof instanceof VerifiedPayment ? Booking::verify($u['booking_id'],$proof) : $proof; $check(is_array($r) && $r['status']==='exception','late verification without trusted provider time enters exception'); }
// Simulate legacy published offers with no reviewed domain records; admission must fail closed.
global $wpdb;
foreach (['tour','cohort'] as $kind) {
    wp_set_current_user($admin);
    $base=['kind'=>$kind,'org_id'=>0,'title_en'=>'Fictional unreviewed '.$kind,'title_fa'=>'','description_en'=>'','description_fa'=>'','registration_open_at'=>'2027-06-01T05:30:00.000000Z','registration_close_at'=>'2027-06-15T14:30:00.000000Z','timezone'=>'Asia/Tehran','capacity'=>3,'amount_irr'=>1000000,'mode'=>'local','status'=>'published','product_id'=>0];
    $forbidden=Offers::save(0,$base,$admin);
    $check(is_wp_error($forbidden),$kind.' publication denied without approved linked review');
    $base['status']='draft'; $legacy=Offers::save(0,$base,$admin);
    if (is_wp_error($legacy)) { $check(false,$kind.' unreviewed legacy hold denied'); continue; }
    $snapshot=$wpdb->get_var($wpdb->prepare('SELECT offer_snapshot FROM '.DB::table('inventory').' WHERE offer_id=%d',$legacy));
    $data=json_decode($snapshot,true); $data['status']='published';
    $wpdb->update(DB::table('inventory'),['status'=>'published','offer_snapshot'=>wp_json_encode($data)],['offer_id'=>$legacy]);
    wp_set_current_user($user); DB::set_test_now('2027-06-01T06:00:00.000000Z');
    $unreviewed=Booking::hold($legacy,1,1,$user,'review-'.$kind.'-'.$nonce);
    $check(is_wp_error($unreviewed) && $unreviewed->get_error_code()==='atlas_'.$kind.'_review_required',$kind.' unreviewed legacy hold denied');
    if ($kind==='cohort') { $group=Booking::hold($legacy,1,2,$user,'cohort-group-'.$nonce); $check(is_wp_error($group) && $group->get_error_code()==='atlas_group_enrollment_unavailable','cohort group admission fails closed until implemented'); }
}
// Two isolated PHP workers exercise separate real database connections. Called only by the parent-scheduled test.
$raceOffer=$make();
$script=tempnam(sys_get_temp_dir(),'atlas-race-');
$child=<<<'CHILD'
<?php
define('ATLAS_TEST_CLOCK',true); require $argv[1].'wp-load.php';
wp_set_current_user((int)$argv[3]); \Atlas\Core\DB::set_test_now('2027-06-01T06:00:00.000000Z');
$r=\Atlas\Core\Booking::hold((int)$argv[2],1,1,(int)$argv[3],$argv[4]);
echo is_wp_error($r) ? 'ATLAS_RESULT:rejected' : 'ATLAS_RESULT:accepted';
CHILD;
file_put_contents($script,$child); $workers=[];
foreach (['a','b'] as $suffix) {
    $pipes=[]; $proc=proc_open([PHP_BINARY,$script,ABSPATH,(string)$raceOffer,(string)$user,'race-'.$nonce.'-'.$suffix],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
    if (is_resource($proc)) { fclose($pipes[0]); $workers[]=[$proc,$pipes[1],$pipes[2]]; }
}
$results=[]; foreach ($workers as [$proc,$stdout,$stderr]) { $o=stream_get_contents($stdout); $err=stream_get_contents($stderr); fclose($stdout); fclose($stderr); $exit=proc_close($proc); $results[]=[$exit,$o,$err]; }
unlink($script);
$accepted=count(array_filter($results,static fn($r)=>$r[0]===0 && str_contains($r[1],'ATLAS_RESULT:accepted')));
$rejected=count(array_filter($results,static fn($r)=>$r[0]===0 && str_contains($r[1],'ATLAS_RESULT:rejected')));
$check(count($results)===2 && $accepted===1 && $rejected===1,'TC-REG-002 two-connection last-seat race');
if ($accepted!==1 || $rejected!==1) { foreach ($results as $r) { echo 'Race worker stdout: '.$r[1]."\nRace worker stderr: ".$r[2]."\n"; } }
// Self-declared traveler eligibility is calculated server-side from owner-scoped records.
wp_set_current_user($admin);
$ageOffer=Offers::save(0,['kind'=>'event','org_id'=>0,'title_en'=>'Fictional age-limited event','title_fa'=>'','description_en'=>'','description_fa'=>'','registration_open_at'=>'2027-06-01T05:30:00.000000Z','registration_close_at'=>'2027-06-15T14:30:00.000000Z','timezone'=>'Asia/Tehran','capacity'=>4,'amount_irr'=>1000000,'mode'=>'local','status'=>'published','product_id'=>0,'eligibility'=>['min_age'=>12,'max_age'=>70,'age_on'=>'2027-06-20']],$admin);
wp_set_current_user($user); DB::set_test_now('2027-06-01T06:00:00.000000Z');
$minor=\Atlas\Core\Records::create('traveler',['name'=>'Fictional Minor','birth_date'=>'2012-07-01'],$user);
$minorGuarded=\Atlas\Core\Records::create('traveler',['name'=>'Fictional Minor','birth_date'=>'2012-07-01','guardian_name'=>'Fictional Guardian','guardian_attested'=>true],$user);
$tooYoung=\Atlas\Core\Records::create('traveler',['name'=>'Fictional Child','birth_date'=>'2020-01-01','guardian_name'=>'Fictional Guardian','guardian_attested'=>true],$user);
if (!is_wp_error($ageOffer) && is_array($minor) && is_array($minorGuarded) && is_array($tooYoung)) {
    $missing=Booking::hold($ageOffer,1,1,$user,'guardian-missing-'.$nonce,[$minor['id']]);
    $under=Booking::hold($ageOffer,1,1,$user,'age-under-'.$nonce,[$tooYoung['id']]);
    $ok=Booking::hold($ageOffer,1,1,$user,'guardian-ok-'.$nonce,[$minorGuarded['id']]);
    $duplicates=Booking::hold($ageOffer,1,2,$user,'duplicate-travelers-'.$nonce,[$minorGuarded['id'],$minorGuarded['id']]);
    $check(is_wp_error($missing) && $missing->get_error_code()==='atlas_guardian_required' && is_wp_error($under) && $under->get_error_code()==='atlas_ineligible' && is_array($ok) && is_wp_error($duplicates),'age, guardian self-attestation and unique participant enforcement');
    wp_set_current_user($admin); $private=\Atlas\Core\Records::get('traveler',$minorGuarded['id'],$admin);
    $check(is_wp_error($private),'traveler record is owner-only even to other logged-in actors');
} else { $check(false,'traveler and age-policy fixture setup'); }
// Isolated scoped-record fixtures: membership and object checks must survive direct-ID requests.
global $wpdb;
$orgAName='Fictional org A '.$nonce; $orgBName='Fictional org B '.$nonce;
$wpdb->insert(DB::table('organizations'),['name'=>$orgAName,'status'=>'approved','created_at'=>DB::now()]); $orgA=(int)$wpdb->insert_id;
$wpdb->insert(DB::table('organizations'),['name'=>$orgBName,'status'=>'approved','created_at'=>DB::now()]); $orgB=(int)$wpdb->insert_id;
$other=wp_insert_user(['user_login'=>'atlas_other_'.$nonce,'user_pass'=>wp_generate_password(32),'user_email'=>'atlas_other_'.$nonce.'@example.invalid','role'=>'subscriber']);
if (is_wp_error($other) || !$orgA || !$orgB) { WP_CLI::error('Could not create organization fixtures.'); }
$other=(int)$other; (new WP_User($user))->add_cap('atlas_manage_records'); (new WP_User($other))->add_cap('atlas_manage_records');
$wpdb->insert(DB::table('memberships'),['org_id'=>$orgA,'user_id'=>$user,'role'=>'contributor','status'=>'active','updated_at'=>DB::now()]);
$wpdb->insert(DB::table('memberships'),['org_id'=>$orgB,'user_id'=>$other,'role'=>'contributor','status'=>'active','updated_at'=>DB::now()]);
\Atlas\Core\Records::register_type('test_private',['fields'=>['title'=>['type'=>'string','required'=>true,'max'=>180]],'capability'=>'atlas_manage_records']);
wp_set_current_user($user); $record=\Atlas\Core\Records::create_idempotent('test_private',['org_id'=>$orgA,'title'=>'Private A'],$user,'record-'.$nonce);
$repeat=\Atlas\Core\Records::create_idempotent('test_private',['org_id'=>$orgA,'title'=>'Private A'],$user,'record-'.$nonce);
$changed=\Atlas\Core\Records::create_idempotent('test_private',['org_id'=>$orgA,'title'=>'Different'],$user,'record-'.$nonce);
$check(is_array($record) && is_array($repeat) && $record['id']===$repeat['id'] && is_wp_error($changed) && $changed->get_error_code()==='atlas_idempotency_conflict','scoped record create idempotency and conflict');
if (is_array($record)) {
    wp_set_current_user($other); $access=\Atlas\Core\Records::get('test_private',$record['id'],$other);
    $check(is_wp_error($access) && $access->get_error_code()==='atlas_not_found','TC-AUTH-001 cross-organization record denied');
    $wpdb->update(DB::table('organizations'),['status'=>'suspended'],['id'=>$orgA]);
    wp_set_current_user($user);
    $suspended=\Atlas\Core\Records::get('test_private',$record['id'],$user);
    $suspendedList=\Atlas\Core\Records::query('test_private',[],$user);
    $suspendedCreate=\Atlas\Core\Records::create('test_private',['org_id'=>$orgA,'title'=>'blocked'],$user);
    $check(is_wp_error($suspended) && is_array($suspendedList) && !$suspendedList && is_wp_error($suspendedCreate),'suspended organization denies member read, list and create');
    wp_set_current_user($admin); $agencyOverride=\Atlas\Core\Records::get('test_private',$record['id'],$admin);
    $check(is_array($agencyOverride),'agency retains controlled access to suspended organization');
    $wpdb->update(DB::table('organizations'),['status'=>'approved'],['id'=>$orgA]);
    wp_set_current_user($user); $restored=\Atlas\Core\Records::get('test_private',$record['id'],$user);
    $check(is_array($restored),'approved organization restores active member access');
    $before=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('records').' WHERE type=%s AND org_id=%d','test_private',$orgA));
    DB::set_test_audit_failure(true);
    try {
        $failedCreate=\Atlas\Core\Records::create('test_private',['org_id'=>$orgA,'title'=>'No audit create'],$user);
        $failedIdempotent=\Atlas\Core\Records::create_idempotent('test_private',['org_id'=>$orgA,'title'=>'No audit idem'],$user,'auditfail-'.$nonce);
        $failedUpdate=\Atlas\Core\Records::update('test_private',$record['id'],['title'=>'No audit update'],$user,$record['version']);
    } finally { DB::set_test_audit_failure(false); }
    $after=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('records').' WHERE type=%s AND org_id=%d','test_private',$orgA));
    $idemCount=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::table('idempotency').' WHERE scope=%s AND operation=%s AND idem_key=%s','user:'.$user,'record.test_private','auditfail-'.$nonce));
    $unchanged=\Atlas\Core\Records::get('test_private',$record['id'],$user);
    $check(is_wp_error($failedCreate) && is_wp_error($failedIdempotent) && is_wp_error($failedUpdate) && $before===$after && $idemCount===0 && is_array($unchanged) && $unchanged['version']===$record['version'] && $unchanged['fields']['title']==='Private A','audit failure rolls back direct create, idempotent create and update');
    $wpdb->update(DB::table('memberships'),['status'=>'revoked'],['org_id'=>$orgA,'user_id'=>$user]);
    wp_set_current_user($user); $revoked=\Atlas\Core\Records::get('test_private',$record['id'],$user);
    $check(is_wp_error($revoked),'TC-AUTH-003 revoked org member loses creator access');
}
DB::set_test_now(null);
echo "Atlas core checks: $passed passed, $failed failed\n";
if ($failed) { WP_CLI::error('Atlas core integration checks failed.'); }
WP_CLI::success('Atlas core integration checks passed.');
