<?php
/** Fictional procurement with real encrypted fixture bytes and a MOCK scanner; no provider requests or funds. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='local' || !defined('ATLAS_DEMO_MODE') || ATLAS_DEMO_MODE!==true || getenv('ATLAS_TEST_DB')!=='atlas_integration') throw new RuntimeException('Isolated local demo CLI required.');
if (!defined('ATLAS_TEST_CLOCK')) define('ATLAS_TEST_CLOCK',true);
use Atlas\Core\DB;
if (!class_exists('Atlas_Procurement') || !Atlas_Procurement::ready()) WP_CLI::error('Protected procurement module must be ready.');
global $wpdb; $passed=0; $failed=0; $suffix=wp_generate_password(10,false,false);
$check=static function($ok,$name) use (&$passed,&$failed) { if ($ok) { $passed++; echo "PASS $name\n"; } else { $failed++; echo "FAIL $name\n"; } };
$admins=get_users(array('role'=>'administrator','number'=>1,'fields'=>'ID')); if (!$admins) WP_CLI::error('Agency fixture required.'); $admin=(int)$admins[0];
$users=array(); foreach (array('supplier_a','supplier_b','finance_reviewer') as $label) {
    $id=wp_insert_user(array('user_login'=>'proc_'.$label.'_'.$suffix,'user_pass'=>wp_generate_password(32),'user_email'=>$label.'_'.$suffix.'@example.invalid','role'=>$label==='finance_reviewer'?'administrator':'customer')); if (is_wp_error($id)) WP_CLI::error('User fixture failed.'); $users[$label]=(int)$id; $u=get_userdata($id); $u->add_cap('atlas_manage_records'); if ($label==='finance_reviewer') $u->add_cap('atlas_manage_finance');
}
$supplierA=$users['supplier_a']; $supplierB=$users['supplier_b']; $reviewer=$users['finance_reviewer']; $orgs=array();
foreach (array($supplierA,$supplierB) as $supplier) { $wpdb->insert(DB::table('organizations'),array('name'=>'Fictional maker '.$supplier,'status'=>'approved','created_at'=>DB::now())); $org=(int)$wpdb->insert_id; $orgs[]=$org; $wpdb->insert(DB::table('memberships'),array('org_id'=>$org,'user_id'=>$supplier,'role'=>'manager','status'=>'active','updated_at'=>DB::now())); }
list($orgA,$orgB)=$orgs; DB::set_test_now('2027-06-01T06:00:00.000000Z');
$call=static function($method,$path,$body=null,$key='',$org=0,$nonce=true) {
    $r=new WP_REST_Request($method,'/agency/v1'.$path); $r->set_header('Content-Type','application/json'); if ($nonce) $r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest')); if ($key!=='') $r->set_header('Idempotency-Key',$key); if ($org) $r->set_query_params(array('org_id'=>$org)); if ($body!==null) $r->set_body(wp_json_encode($body)); return rest_get_server()->dispatch($r);
};
if (!class_exists('Atlas_Private_Files') || defined('ATLAS_PRIVATE_FILE_ROOT') || defined('ATLAS_PRIVATE_FILE_KEY')) WP_CLI::error('Fresh isolated private-file fixture configuration required.');
$privateRoot=sys_get_temp_dir().'/atlas-procurement-fixture-'.$suffix;
define('ATLAS_PRIVATE_FILE_ROOT',$privateRoot); define('ATLAS_PRIVATE_FILE_KEY',base64_encode(random_bytes(32)));
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jGZkAAAAASUVORK5CYII='); $digest=hash('sha256',$png);
wp_set_current_user($admin);
$policy=$call('POST','/private-file-policies',array('purpose'=>'fictional_procurement_artwork','context_types'=>array('procurement'),'notice_en'=>'Fictional artwork test. Files retained one day.','notice_fa'=>'اثر ساختگی آزمایش؛ نگهداری پرونده برای یک روز.','retention_days'=>1,'expires_at'=>'2028-01-01 00:00:00.000000','review_reference'=>'fixture:procurement:privacy','sensitive'=>false,'legal_reference'=>'fixture:procurement:legal'))->get_data();
if (empty($policy['id'])) WP_CLI::error('Private-file purpose fixture failed.'); $policyId=$policy['id'];
wp_set_current_user($reviewer); if ($call('POST','/private-file-policies/'.$policyId.'/approve',array('reason'=>'MOCK fixture independent purpose review'))->get_status()!==200) WP_CLI::error('Independent private policy review failed.');
$mockScan=static function($prior,$bytes,$metadata) use ($png) { return array('state'=>$bytes===$png?'clean':'infected','engine'=>'MOCK_KNOWN_BYTES_ONLY','reference'=>'fixture:procurement:png','scanned_digest'=>hash('sha256',$bytes)); };
add_filter('atlas_private_file_scan',$mockScan,10,3);
$fixtureFiles=array();
$makeFile=static function($actor,$award,$scan=true) use ($png,$policyId,$call,$admin,&$fixtureFiles) {
    wp_set_current_user($actor); $file=Atlas_Private_Files::store('procurement',$award,$policyId,$png,true);
    if (is_wp_error($file)) WP_CLI::error('Actual artwork fixture upload failed: '.$file->get_error_message()); $fixtureFiles[]=$file['id'];
    if ($scan) { wp_set_current_user($admin); if ($call('POST','/private-files/'.$file['id'].'/scan',array('version'=>1,'reason'=>'MOCK scanner binds known fixture bytes'))->get_status()!==200) WP_CLI::error('MOCK artwork scan failed.'); }
    wp_set_current_user($actor); return $file;
};
// Exercise actual independent contract negotiation/review/acceptance as award prerequisite.
$terms=array('agency_party'=>'Fictional agency','partner_party'=>'Fictional maker','territories'=>'Fictional region','pricing_terms'=>'Fictional manual price and tax review.','settlement_terms'=>'No automatic transfer.','cancellation_terms'=>'Manual review.','termination_terms'=>'Manual review.','dispute_terms'=>'Manual review.','marketing_claims'=>'No verified claims.','effective_from'=>'2027-06-01T00:00:00Z','effective_until'=>'2028-06-01T00:00:00Z','services'=>array('manufacturing'),'confidential'=>true);
foreach (array(array($orgA,$supplierA),array($orgB,$supplierB)) as $pair) {
    $base='/organizations/'.$pair[0].'/contracts'; wp_set_current_user($admin); $c=$call('POST',$base,array('title'=>'Fictional manufacturing','snapshot'=>$terms),'pc_'.$pair[0].'_'.$suffix)->get_data(); if (empty($c['id'])) WP_CLI::error('Contract prerequisite failed.'); $path=$base.'/'.$c['id'];
    wp_set_current_user($pair[1]); $call('POST',$path.'/versions',array('expected_version'=>1,'snapshot'=>$terms,'reason'=>'Fictional negotiated terms'),'pv_'.$pair[0].'_'.$suffix);
    wp_set_current_user($admin); $call('POST',$path.'/review',array('expected_version'=>2,'decision'=>'approved','reason'=>'Fictional independent review','evidence_reference'=>'fictional-manufacturing'),'pr_'.$pair[0].'_'.$suffix);
    wp_set_current_user($pair[1]); $detail=$call('GET',$path)->get_data(); $accepted=$call('POST',$path.'/accept',array('expected_version'=>3,'snapshot_hash'=>$detail['versions'][0]['snapshot_hash'],'authority_attested'=>true),'pa_'.$pair[0].'_'.$suffix); $check($accepted->get_status()===200,'manufacturing contract prerequisite accepted for scoped supplier');
}
$counter=0;
wp_set_current_user($admin);
$check($call('GET','/procurement/rfqs',null,'',0,false)->get_status()===403,'agency procurement access still requires an explicit valid nonce');
$do=static function($actor,$id,$op,$fields=array(),$org=0) use ($wpdb,$call,&$counter,$suffix) {
    wp_set_current_user($actor); $version=(int)$wpdb->get_var($wpdb->prepare('SELECT version FROM '.Atlas_Procurement::table().' WHERE id=%s',$id));
    $body=array_merge(array('expected_version'=>$version),$org?array('org_id'=>$org):array(),$fields); return $call('POST','/procurement/'.$id.'/'.$op,$body,'step_'.$suffix.'_'.(++$counter));
};
$newRfq=static function($quantity,$budget) use ($admin,$call,$orgA,$orgB,$suffix,&$counter) { wp_set_current_user($admin); return $call('POST','/procurement/rfqs',array('title'=>'Fictional gifts','requirements'=>'Reviewed sample, private art, manual inspection.','quantity'=>$quantity,'budget_irr'=>(string)$budget,'deadline'=>'2027-06-10T00:00:00Z','delivery_deadline'=>'2027-07-01T00:00:00Z','recipients'=>array($orgA,$orgB)),'rfq_'.$suffix.'_'.(++$counter)); };
$rfq=$newRfq(10,10000)->get_data(); if (empty($rfq['id'])) WP_CLI::error('RFQ fixture failed: '.wp_json_encode($rfq));
$quoteFields=array('quantity'=>6,'unit_irr'=>'100','setup_irr'=>'50','shipping_irr'=>'20','tax_irr'=>'10','expiry'=>'2027-06-20T00:00:00Z','lead_days'=>5,'terms'=>'Fictional IP, shipping and cancellation terms.');
$a=$do($supplierA,$rfq['id'],'quote',$quoteFields,$orgA); $quoteA=$a->get_data(); $quoteFields['quantity']=5; $quoteFields['unit_irr']='200'; $quoteFields['setup_irr']='0'; $quoteFields['shipping_irr']='0'; $quoteFields['tax_irr']='0';
$quoteB=$do($supplierB,$rfq['id'],'quote',$quoteFields,$orgB)->get_data();
$check($a->get_status()===201 && $quoteA['amount_irr']==='680','quote total uses exact quantity, setup, shipping and explicit tax IRR');
wp_set_current_user($supplierA); $private=$call('GET','/procurement/'.$rfq['id'],null,'',$orgA)->get_data(); $childOrgs=array_column($private['children']??array(),'org_id');
$check(!isset($private['payload']['recipients']) && $private['amount_irr']===null && !in_array($orgB,$childOrgs,true),'supplier RFQ view omits agency budget, competitor recipients and quotes');
$check($call('GET','/procurement/'.$quoteB['id'],null,'',$orgA)->get_status()===404,'competitor quote identifier cannot reveal confidential terms');
$award=$do($admin,$rfq['id'],'award',array('quote_id'=>$quoteA['id'],'quantity'=>6,'reason'=>'Fictional split selection')); $order=$award->get_data();
$check($award->get_status()===201 && $order['quantity']===6 && $order['amount_irr']==='680','partial award persists immutable accepted quote snapshot');
$check($do($admin,$rfq['id'],'award',array('quote_id'=>$quoteB['id'],'quantity'=>5,'reason'=>'Intentional excess'))->get_status()===409,'split awards cannot exceed RFQ quantity');
$second=$do($admin,$rfq['id'],'award',array('quote_id'=>$quoteB['id'],'quantity'=>4,'reason'=>'Fictional remaining selection'));
$check($second->get_status()===201 && (int)$wpdb->get_var($wpdb->prepare('SELECT SUM(quantity) FROM '.Atlas_Procurement::table()." WHERE rfq_id=%s AND type='order'",$rfq['id']))===10,'split award reserves exactly the approved total quantity');
$budget=$newRfq(10,1000)->get_data(); $quoteFields['quantity']=2; $quoteFields['unit_irr']='600'; $budgetQuote=$do($supplierA,$budget['id'],'quote',$quoteFields,$orgA)->get_data();
$check($do($admin,$budget['id'],'award',array('quote_id'=>$budgetQuote['id'],'quantity'=>2,'reason'=>'Intentional budget excess'))->get_status()===409,'award total cannot exceed approved RFQ IRR budget');
$check($do($admin,$budget['id'],'award',array('quote_id'=>$budgetQuote['id'],'quantity'=>1,'reason'=>'Within budget'))->get_status()===201,'smaller accepted quantity fits approved budget without changing original quote');
$single=$newRfq(2,1000)->get_data(); $quoteFields['quantity']=2; $quoteFields['unit_irr']='100'; $singleQuote=$do($supplierA,$single['id'],'quote',$quoteFields,$orgA)->get_data();
wp_set_current_user($admin); $singleBody=array('expected_version'=>1,'quote_id'=>$singleQuote['id'],'quantity'=>2,'reason'=>'Fictional single supplier award'); $singleKey='single_'.$suffix;
$singleAward=$call('POST','/procurement/'.$single['id'].'/award',$singleBody,$singleKey); $singleReplay=$call('POST','/procurement/'.$single['id'].'/award',$singleBody,$singleKey);
$check($singleAward->get_status()===201 && $singleReplay->get_status()===200 && $singleReplay->get_data()['id']===$singleAward->get_data()['id'] && (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Atlas_Procurement::table()." WHERE rfq_id=%s AND type='order'",$single['id']))===1,'full single-supplier award retries preserve one production order');
$oid=$order['id']; $snapshot=$order['payload']['quote_snapshot_hash'];
$wpdb->update(DB::table('organizations'),array('status'=>'suspended'),array('id'=>$orgA));
$check($do($admin,$oid,'authorize',array('reason'=>'Suspended partner'))->get_status()===409,'suspended organization cannot receive a new production commitment');
$wpdb->update(DB::table('organizations'),array('status'=>'approved'),array('id'=>$orgA));
$check($do($admin,$oid,'authorize',array('reason'=>'Intentional bypass'))->get_status()===409,'production cannot start without artwork and sample approvals');
$check($do($supplierA,$oid,'artwork',array('file_reference'=>'private-art-v1','sha256'=>str_repeat('a',64),'rights_attested'=>true),$orgA)->get_status()===409,'manually declared opaque references cannot bypass actual private-file scanning');
$file1=$makeFile($supplierA,$oid,false);
$check($do($supplierA,$oid,'artwork',array('file_reference'=>$file1['id'],'sha256'=>$digest,'rights_attested'=>true),$orgA)->get_status()===409,'quarantined actual artwork bytes cannot become a proof revision');
wp_set_current_user($admin); $call('POST','/private-files/'.$file1['id'].'/scan',array('version'=>1,'reason'=>'MOCK known-byte scanner approval'));
$check($do($supplierA,$oid,'artwork',array('file_reference'=>$file1['id'],'sha256'=>str_repeat('a',64),'rights_attested'=>true),$orgA)->get_status()===409,'client digest must match the exact scanned bytes');
$wrongAward=$makeFile($supplierB,$second->get_data()['id']);
$check($do($supplierA,$oid,'artwork',array('file_reference'=>$wrongAward['id'],'sha256'=>$digest,'rights_attested'=>true),$orgA)->get_status()===409,'clean file from another award cannot be attached to this production order');
wp_set_current_user($supplierB); $check(is_wp_error(Atlas_Private_Files::content($file1['id'])) && Atlas_Private_Files::scope('procurement',$oid)===false,'competing supplier cannot decrypt or resolve private artwork context');
$wpdb->update(DB::table('memberships'),array('role'=>'viewer'),array('org_id'=>$orgA,'user_id'=>$supplierA)); wp_set_current_user($supplierA);
$check(Atlas_Private_Files::scope('procurement',$oid,true)===false && is_wp_error(Atlas_Private_Files::store('procurement',$oid,$policyId,$png,true)),'organization viewer cannot upload artwork despite global write capability');
$wpdb->update(DB::table('memberships'),array('role'=>'manager'),array('org_id'=>$orgA,'user_id'=>$supplierA));
$art=$do($supplierA,$oid,'artwork',array('file_reference'=>$file1['id'],'sha256'=>$digest,'rights_attested'=>true),$orgA);
$check($art->get_status()===200 && $art->get_data()['payload']['file_access']==='scoped_clean_private_file_required' && $art->get_data()['payload']['artwork']['policy_id']===$policyId,'proof revision binds actual clean scoped file, digest and approved purpose policy');
$content=Atlas_Private_Files::content($file1['id']); $check(!is_wp_error($content) && $content['bytes']===$png && $content['digest']===$digest && is_file($privateRoot.'/'.$file1['id'].'.blob') && !str_contains(file_get_contents($privateRoot.'/'.$file1['id'].'.blob'),$png),'scoped artwork download decrypts actual encrypted fixture bytes without a public media URL');
$check($do($supplierA,$oid,'art_review',array('proof_revision'=>1,'decision'=>'approved','scan_review_reference'=>'manual-file-review','reason'=>'Unauthorized'),$orgA)->get_status()===403,'supplier cannot approve its own artwork security review');
DB::set_test_now('2027-06-03T06:00:00Z');
$check($do($admin,$oid,'art_review',array('proof_revision'=>1,'decision'=>'approved','scan_review_reference'=>'manual-file-review','reason'=>'Expired bytes must not authorize proof'))->get_status()===409,'file retention expiry blocks later proof approval without erasing revision history');
DB::set_test_now('2027-06-01T06:00:00Z');
$check($do($admin,$oid,'art_review',array('proof_revision'=>1,'decision'=>'approved','scan_review_reference'=>'manual-file-review','reason'=>'Fictional independent artwork review'))->get_status()===200,'agency reviews exact artwork revision');
$check($do($admin,$oid,'sample',array('proof_revision'=>1,'decision'=>'approved','evidence_reference'=>'physical-sample-v1','reason'=>'Fictional physical sample reviewed'))->get_status()===200,'physical sample approval is separate from artwork');
$file2=$makeFile($supplierA,$oid); $revision2=$do($supplierA,$oid,'artwork',array('file_reference'=>$file2['id'],'sha256'=>$digest,'rights_attested'=>true),$orgA);
$history=$revision2->get_data()['payload']['artwork_history']??array();
$check($revision2->get_status()===200 && count($history)===1 && $history[0]['file_reference']===$file1['id'] && $history[0]['sha256']===$digest && $history[0]['approved']===true && $history[0]['sample_review']['approved']===true,'replacement proof retains immutable prior file, rights, review and physical sample snapshot');
$check($do($admin,$oid,'authorize',array('reason'=>'Old sample must not apply'))->get_status()===409,'new artwork revision invalidates prior proof/sample authorization');
$do($admin,$oid,'art_review',array('proof_revision'=>2,'decision'=>'approved','scan_review_reference'=>'manual-file-review-v2','reason'=>'New private proof reviewed'));
$do($admin,$oid,'sample',array('proof_revision'=>2,'decision'=>'approved','evidence_reference'=>'physical-sample-v2','reason'=>'Replacement sample reviewed'));
wp_set_current_user($admin); $call('POST','/private-file-policies/'.$policyId.'/revoke',array('reason'=>'Fictional policy withdrawal'));
$check($do($admin,$oid,'authorize',array('reason'=>'Withdrawn purpose must block production'))->get_status()===409,'withdrawn purpose policy prevents new production commitment using retained clean bytes');
// Restore only the fictional policy fixture; production has no revocation bypass route.
$wpdb->update(Atlas_Private_Files::table('policies'),array('state'=>'approved'),array('id'=>$policyId));
$check($do($admin,$oid,'authorize',array('reason'=>'Fictional written authorization'))->get_status()===200,'only current approved proof and sample authorize production');
$do($supplierA,$oid,'milestone',array('milestone'=>'started','evidence_reference'=>'factory-start-record','reason'=>'Manual progress'),$orgA);
$check($do($supplierA,$oid,'milestone',array('milestone'=>'completed','evidence_reference'=>'factory-complete-record','reason'=>'Manual completion'),$orgA)->get_status()===200,'production milestones advance in order');
$do($supplierA,$oid,'shipment',array('quantity'=>4,'tracking_reference'=>'manual-carrier-part1','reason'=>'Partial shipment'),$orgA);
$do($admin,$oid,'inspection',array('received'=>4,'accepted'=>3,'rejected'=>1,'evidence_reference'=>'inspection-part1','reason'=>'One defective item'));
$check($do($supplierA,$oid,'shipment',array('quantity'=>2,'tracking_reference'=>'manual-carrier-part2','reason'=>'Remaining shipment'),$orgA)->get_status()===200,'inspection of partial delivery does not prevent remaining authorized shipment');
$do($admin,$oid,'inspection',array('received'=>2,'accepted'=>2,'rejected'=>0,'evidence_reference'=>'inspection-part2','reason'=>'Remaining goods reviewed'));
$check($do($admin,$oid,'payable',array('invoice_reference'=>'supplier-invoice-001','reason'=>'Intentional QC bypass'))->get_status()===409,'failed QC blocks invoice payable approval');
$do($admin,$oid,'return_request',array('accepted_quantity'=>0,'rejected_quantity'=>1,'reason'=>'Return defective item'));
$resolved=$do($admin,$oid,'return_resolve',array('replacement_quantity'=>0,'credit_quantity'=>1,'credit_irr'=>'100','evidence_reference'=>'agreed-return-credit','reason'=>'Agreed manual invoice credit'));
$check($resolved->get_status()===200 && $resolved->get_data()['state']==='qc_passed','manufacturer QA return resolves by explicit quantity and credit evidence');
$payable=$do($admin,$oid,'payable',array('invoice_reference'=>'supplier-invoice-001','reason'=>'Accepted quantities and credit reviewed'));
$check($payable->get_status()===200 && $payable->get_data()['payload']['payable_irr']==='580','invoice payable preserves exact award less agreed credit');
$beforeLedger=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DB::table('ledger'));
$check($do($admin,$oid,'settlement_intent',array('amount_irr'=>'581','reason'=>'Intentional excess'))->get_status()===409,'settlement intent cannot exceed approved outstanding payable');
$intent=$do($admin,$oid,'settlement_intent',array('amount_irr'=>'500','reason'=>'Fictional first intent'))->get_data()['payload']['settlements'][0]['id'];
$check($do($admin,$oid,'settlement_approve',array('intent_id'=>$intent,'reason'=>'Intentional self approval'))->get_status()===409,'settlement requester cannot approve their own intent');
$do($reviewer,$oid,'settlement_approve',array('intent_id'=>$intent,'reason'=>'Independent fictional finance review'));
$bankEvidence='fictional-bank-evidence-001-'.$suffix; $bankEvidence2='fictional-bank-evidence-002-'.$suffix;
$manual=$do($admin,$oid,'settlement_evidence',array('intent_id'=>$intent,'evidence_reference'=>$bankEvidence,'reason'=>'Recorded manual evidence only'));
if ($manual->get_status()!==200) echo 'DIAGNOSTIC settlement evidence HTTP '.$manual->get_status().' '.($manual->get_data()['message']??'')."\n";
$check($manual->get_status()===200 && $manual->get_data()['payload']['settlements'][0]['money_status']==='manual_evidence_unverified' && $beforeLedger===(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DB::table('ledger')),'manual bank evidence never creates verified money or a payment ledger event');
$remaining=$do($admin,$oid,'settlement_intent',array('amount_irr'=>'80','reason'=>'Remaining fictional intent'))->get_data()['payload']['settlements'][1]['id'];
$do($reviewer,$oid,'settlement_approve',array('intent_id'=>$remaining,'reason'=>'Independent remaining review'));
$check($do($admin,$oid,'settlement_evidence',array('intent_id'=>$remaining,'evidence_reference'=>$bankEvidence,'reason'=>'Duplicate must fail'))->get_status()===409,'duplicate settlement evidence cannot satisfy a second intent');
$do($admin,$oid,'settlement_evidence',array('intent_id'=>$remaining,'evidence_reference'=>$bankEvidence2,'reason'=>'Separate manual evidence'));
$closed=$do($admin,$oid,'close',array('reason'=>'Fictional manually reconciled record'));
$check($closed->get_status()===200 && $closed->get_data()['state']==='closed' && $closed->get_data()['payload']['quote_snapshot_hash']===$snapshot && $closed->get_data()['money_status']==='unverified_manual_workflow','operational closure preserves original quote and does not assert verified settlement');
wp_set_current_user($supplierB);
$check($call('GET','/procurement/'.$oid,null,'',$orgB)->get_status()===404,'another selected supplier cannot read a competitor production order');
wp_set_current_user($admin); $count=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Atlas_Procurement::table()); DB::set_test_audit_failure(true);
try { $audit=$newRfq(1,100); } finally { DB::set_test_audit_failure(false); }
$check($audit->get_status()===503 && $count===(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Atlas_Procurement::table()),'RFQ persistence rolls back when mandatory audit fails');
$_GET['lang']='fa'; $check(strpos(Atlas_Procurement::panel(),'تدارکات')!==false || strpos(Atlas_Procurement::panel(),'تولید خصوصی')!==false,'Persian procurement controls render'); unset($_GET['lang']);
remove_filter('atlas_private_file_scan',$mockScan,10);
foreach ($fixtureFiles as $fixtureId) { $path=$privateRoot.'/'.$fixtureId.'.blob'; if (is_file($path)) unlink($path); }
if (is_dir($privateRoot)) rmdir($privateRoot);
DB::set_test_now(null); echo "Atlas procurement completion checks: $passed passed, $failed failed\n"; if ($failed) WP_CLI::error('Procurement completion checks failed.'); WP_CLI::success('Private manual procurement fixtures passed; MOCK scanner is not external antivirus evidence.');
