<?php
/** Real transactional extension boundary checks in the dedicated fictional DB. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='local' || getenv('ATLAS_TEST_DB')!=='atlas_integration') throw new RuntimeException('Isolated integration DB required.');
if (!defined('ATLAS_TEST_CLOCK')) define('ATLAS_TEST_CLOCK',true);
use Atlas\Core\DB;
use Atlas\Core\Offers;
use Atlas\Core\Booking;
use Atlas\Core\VerifiedPayment;
global $wpdb;
$passed=0; $failed=0;
$check=static function($ok,$label) use (&$passed,&$failed) { if ($ok) { $passed++; echo "PASS $label\n"; } else { $failed++; echo "FAIL $label\n"; } };
$admin=(int)get_users(array('role'=>'administrator','number'=>1,'fields'=>'ID'))[0]; wp_set_current_user($admin);
$suffix=strtolower(wp_generate_password(10,false,false));
$make=static function() use($admin) { return Offers::save(0,array('kind'=>'event','org_id'=>0,'title_en'=>'Fictional extension test','title_fa'=>'آزمون افزونه','description_en'=>'','description_fa'=>'','registration_open_at'=>'2027-06-01T00:00:00Z','registration_close_at'=>'2027-06-02T00:00:00Z','timezone'=>'UTC','capacity'=>10,'amount_irr'=>1000000,'mode'=>'local','status'=>'published','product_id'=>0),$admin); };
DB::set_test_now('2027-06-01T10:00:00Z');
$offer=$make(); if (is_wp_error($offer)) WP_CLI::error('Fixture offer failed: '.$offer->get_error_message());
$unknown=Booking::hold($offer,1,1,$admin,'unsupported-'.$suffix,array(),array('unrecognized'=>'yes'));
$check(is_wp_error($unknown) && $unknown->get_error_code()==='atlas_invalid_options','unknown options cannot silently become a quoted booking');
$table=$wpdb->prefix.'atlas_test_extra_capacity';
$wpdb->query("CREATE TEMPORARY TABLE $table (booking_id char(36) PRIMARY KEY, hold_id char(36) NOT NULL, state varchar(16) NOT NULL, quantity int NOT NULL) ENGINE=InnoDB");
$mode='valid';
$prepare=static function($prepared,$d,$qty,$user,$options) use (&$mode) {
    if (!array_key_exists('test_extra',$options)) return $prepared;
    if ($mode==='throw') throw new RuntimeException('Fictional storage interruption');
    $prepared['unit_amount_irr']=1250000; $prepared['snapshot']=array('test_extra'=>'validated'); $prepared['handled_options']=true; return $prepared;
};
$created=static function($ok,$booking,$hold,$d,$qty) use ($wpdb,$table,&$mode) {
    if (empty($d['booking_options']['test_extra'])) return $ok;
    if (!$wpdb->insert($table,array('booking_id'=>$booking,'hold_id'=>$hold,'state'=>'active','quantity'=>$qty))) return new WP_Error('test_storage','Additional write failed.');
    return $mode==='reject' ? new WP_Error('test_capacity','Fictional session is full.',array('status'=>409)) : $ok;
};
$release=static function($ok,$booking) use($wpdb,$table) { $r=$wpdb->query($wpdb->prepare("UPDATE $table SET state='released' WHERE booking_id=%s AND state='active'",$booking)); return $r===false?false:$ok; };
$consume=static function($ok,$booking) use($wpdb,$table) { $r=$wpdb->query($wpdb->prepare("UPDATE $table SET state='consumed' WHERE booking_id=%s AND state='active'",$booking)); return $r===false?false:$ok; };
add_filter('atlas_core_prepare_hold',$prepare,99,6); add_filter('atlas_core_hold_created',$created,99,8); add_filter('atlas_core_hold_released',$release,99,3); add_filter('atlas_core_hold_consumed',$consume,99,3);
try {
    $a=Booking::hold($offer,1,2,$admin,'extension-'.$suffix,array(),array('test_extra'=>'selected'));
    $again=Booking::hold($offer,1,2,$admin,'extension-'.$suffix,array(),array('test_extra'=>'selected'));
    $row=is_array($a)?$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE booking_id=%s",$a['booking_id'])):null;
    $check(is_array($a) && $a['quote']['amount']===2500000 && $row && (int)$row->quantity===2 && $row->state==='active','authoritative option quote and auxiliary capacity are persisted with the core hold');
    $check(is_array($a) && is_array($again) && $a['booking_id']===$again['booking_id'] && (int)$wpdb->get_var("SELECT COUNT(*) FROM $table")===1,'idempotent retry never reserves auxiliary capacity twice');
    $changed=Booking::hold($offer,1,2,$admin,'extension-'.$suffix,array(),array('test_extra'=>'other'));
    $check(is_wp_error($changed) && $changed->get_error_code()==='atlas_idempotency_conflict','changed options cannot reuse a successful hold key');
    DB::set_test_now('2027-06-01T10:11:00Z');
    $expired=is_array($a)?Booking::expire($a['hold_id']):$a;
    $state=is_array($a)?$wpdb->get_var($wpdb->prepare("SELECT state FROM $table WHERE booking_id=%s",$a['booking_id'])):null;
    $check(is_array($expired) && $expired['state']==='released' && $state==='released','ordinary expiry releases core and auxiliary allocation atomically');
    $b=Booking::hold($offer,1,1,$admin,'consume-'.$suffix,array(),array('test_extra'=>'selected'));
    $proof=VerifiedPayment::from_adapter('local_extension','paid-'.$suffix,1250000);
    $paid=is_array($b) && $proof instanceof VerifiedPayment?Booking::verify($b['booking_id'],$proof):$b;
    $state=is_array($b)?$wpdb->get_var($wpdb->prepare("SELECT state FROM $table WHERE booking_id=%s",$b['booking_id'])):null;
    $check(is_array($paid) && $paid['status']==='paid_pending_confirmation' && $state==='consumed','valid independent monetary verification consumes both allocations');
    $before=(int)$wpdb->get_var($wpdb->prepare('SELECT allocated FROM '.DB::table('inventory').' WHERE offer_id=%d',$offer));
    $beforeRows=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table"); $mode='reject';
    $reject=Booking::hold($offer,1,1,$admin,'reject-'.$suffix,array(),array('test_extra'=>'selected'));
    $after=(int)$wpdb->get_var($wpdb->prepare('SELECT allocated FROM '.DB::table('inventory').' WHERE offer_id=%d',$offer));
    $check(is_wp_error($reject) && $reject->get_error_code()==='test_capacity' && $before===$after && (int)$wpdb->get_var("SELECT COUNT(*) FROM $table")===$beforeRows,'auxiliary rejection rolls back core counters and all tentative records');
    $mode='throw'; $interrupted=Booking::hold($offer,1,1,$admin,'throw-'.$suffix,array(),array('test_extra'=>'selected'));
    $check(is_wp_error($interrupted) && (int)$wpdb->get_var($wpdb->prepare('SELECT allocated FROM '.DB::table('inventory').' WHERE offer_id=%d',$offer))===$before,'extension exception leaves no partial admission');
} finally {
    remove_filter('atlas_core_prepare_hold',$prepare,99); remove_filter('atlas_core_hold_created',$created,99); remove_filter('atlas_core_hold_released',$release,99); remove_filter('atlas_core_hold_consumed',$consume,99);
    $wpdb->query("DROP TEMPORARY TABLE IF EXISTS $table"); DB::set_test_now(null);
}
echo "Booking extension checks: $passed passed, $failed failed\n";
if ($failed) WP_CLI::error('Transactional extension regression failed.');
WP_CLI::success('Transactional extension boundaries verified.');
