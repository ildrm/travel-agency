<?php
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='local' || getenv('ATLAS_TEST_DB')!=='atlas_integration') throw new RuntimeException('Isolated test database required.');

$passed=0;
$check=static function($ok,$message) use (&$passed) {
    if (!$ok) throw new RuntimeException($message);
    $passed++;
};
$admin=(int)get_users(array('role'=>'administrator','fields'=>'ID','number'=>1))[0];
$author=wp_insert_user(array('user_login'=>'editorial_'.wp_generate_password(10,false),'user_pass'=>wp_generate_password(30),'role'=>'editor'));
if (is_wp_error($author)) WP_CLI::error('Editorial author fixture unavailable.');
$posts=array();
$request=static function($id,$fingerprint,$decision='publish',$nonce=true) {
    $request=new WP_REST_Request('POST','/agency/v1/editorial/'.$id.'/review');
    $request->set_header('Content-Type','application/json');
    if ($nonce) $request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
    $request->set_body(wp_json_encode(array('fingerprint'=>$fingerprint,'reference'=>'fictional:editorial:review','decision'=>$decision)));
    return rest_do_request($request);
};
try {
    wp_set_current_user($author);
    foreach (array('en'=>array('Fictional paper map','A fictional guide to reading the paper map.'),'fa'=>array('نقشه کاغذی ساختگی','راهنمای ساختگی خواندن نقشه کاغذی.')) as $lang=>$copy) {
        $id=wp_insert_post(array('post_type'=>'atlas_guide','post_status'=>'publish','post_title'=>$copy[0],'post_content'=>$copy[1],'post_excerpt'=>$copy[1],'post_author'=>$author),true);
        if (!$id || is_wp_error($id)) throw new RuntimeException('Guide fixture failed.');
        $posts[$lang]=$id;
        $check(get_post_status($id)==='draft','WordPress publish cannot bypass agency review.');
    }
    foreach ($posts as $lang=>$id) {
        $_POST['atlas_guide_nonce']=wp_create_nonce('atlas_guide_details_'.$id);
        $_POST['atlas_guide']=array('language'=>$lang,'alternate'=>$posts[$lang==='en'?'fa':'en'],'source_reference'=>'fictional-source-001','verified_at'=>gmdate('Y-m-d'));
        Atlas_Editorial::save_details($id);
    }
    unset($_POST['atlas_guide_nonce'],$_POST['atlas_guide']);
    $en=$posts['en']; $fa=$posts['fa'];
    $fingerprint=Atlas_Editorial::fingerprint(get_post($en));
    $check($request($en,$fingerprint)->get_status()===403,'Content author cannot approve through the review API.');
    wp_set_current_user($admin);
    $check($request($en,$fingerprint,'publish',false)->get_status()===403,'Agency review requires an authenticated nonce.');
    $check($request($en,str_repeat('0',64))->get_status()===409,'An obsolete fingerprint cannot approve edited copy.');
    $result=$request($en,$fingerprint);
    $check($result->get_status()===200 && get_post_status($en)==='publish','Agency review publishes the exact reviewed WordPress revision.');
    $check(Atlas_Editorial::alternate(get_post($en))===null,'Only a published translation can be advertised as an alternate.');
    $check($request($fa,Atlas_Editorial::fingerprint(get_post($fa)))->get_status()===200,'Reviewed Persian version publishes independently.');
    $check(Atlas_Editorial::alternate(get_post($en))->ID===$fa && Atlas_Editorial::alternate(get_post($fa))->ID===$en,'Both published languages resolve the same reciprocal translation pair.');
    $check(str_contains(Atlas_Editorial::url(get_post($fa)),'lang=fa') && str_contains(Atlas_Editorial::sitemap_entry(array('loc'=>'wrong'),get_post($fa),'atlas_guide')['loc'],'lang=fa'),'Persian sitemap and canonical URLs preserve the content language.');
    wp_set_current_user($author);
    wp_update_post(array('ID'=>$en,'post_content'=>'Fictional revised text.','post_status'=>'publish'));
    $check(get_post_status($en)==='draft' && Atlas_Editorial::alternate(get_post($fa))===null,'A material edit requires a fresh review and removes the stale alternate.');
    wp_set_current_user($admin);
    $check($request($en,$fingerprint)->get_status()===409,'The prior evidence cannot republish revised content.');
    $check($request($fa,Atlas_Editorial::fingerprint(get_post($fa)),'withdraw')->get_status()===200 && get_post_status($fa)==='draft','Agency withdrawal preserves a draft without a public alternate.');
    $_GET['atlas_area']='account';
    $check(!empty(Atlas_Editorial::robots(array())['noindex']),'Account route receives noindex metadata.');
    unset($_GET['atlas_area']);
    WP_CLI::log("Atlas editorial checks: $passed passed, 0 failed.");
} finally {
    wp_set_current_user($admin);
    foreach ($posts as $id) wp_delete_post($id,true);
    wp_delete_user($author);
}
