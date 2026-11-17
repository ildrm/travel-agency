<?php
// Fictional local-only fixtures; never imports real traveler or merchant data.
if (wp_get_environment_type() !== 'local' || !defined('ATLAS_DEMO_MODE') || !ATLAS_DEMO_MODE) {
    WP_CLI::error('Seeding is restricted to the explicit local demonstration runtime.');
}
if (!class_exists('Atlas\Core\Offers') || !class_exists('WooCommerce')) {
    WP_CLI::error('Activate atlas-core and WooCommerce first.');
}
$admins = get_users(['role' => 'administrator', 'number' => 1]);
wp_set_current_user($admins[0]->ID);
$privatePath = dirname(__DIR__) . '/.runtime/credentials.json';
$private = json_decode(file_get_contents($privatePath), true);
if (empty($private['customer_user']) || !username_exists($private['customer_user'])) {
    $private['customer_user'] = 'atlas_demo_customer';
    $private['customer_password'] = wp_generate_password(32, true, true);
    $customer = wp_insert_user(['user_login'=>$private['customer_user'], 'user_pass'=>$private['customer_password'],
        'user_email'=>'customer@example.test', 'role'=>'customer', 'display_name'=>'Fictional local customer']);
    if (is_wp_error($customer)) { WP_CLI::error('Could not create fictional local customer.'); }
    file_put_contents($privatePath, wp_json_encode($private, JSON_PRETTY_PRINT));
    chmod($privatePath, 0600);
}
$fixtures = get_option('atlas_demo_fixtures', []);
$open = gmdate('Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS);
$close = gmdate('Y-m-d\TH:i:s\Z', time() + 14 * DAY_IN_SECONDS);
$offers = [
    ['event', 'A morning in nature · local demonstration', 'صبحی در طبیعت · نمایش محلی', 'A fictional small-group excursion with breakfast, a walking route and a clear meeting plan. No real departure or supplier is represented.', 'گردش گروهی نمونه با صبحانه و مسیر پیاده‌روی. این نمایش محلی است و سفر یا تأمین‌کنندهٔ واقعی را معرفی نمی‌کند.', 11800000],
    ['cohort', 'Professional tour leadership · demonstration cohort', 'راهنمای حرفه‌ای تور · دورهٔ نمایشی', 'A fictional in-person learning cohort. Enrollment and payment are demonstrated locally; this is not an accredited qualification.', 'یک دورهٔ حضوری نمونه برای نمایش ثبت‌نام محلی؛ این دوره مدرک معتبر حرفه‌ای صادر نمی‌کند.', 18000000],
    ['tour', 'London, Paris, Rome · itinerary request', 'لندن، پاریس، رم · درخواست برنامهٔ سفر', 'An illustrative five-day itinerary request. Travel times, lodging and prices require supplier confirmation; no flight or visa is included or issued.', 'درخواست نمونهٔ برنامهٔ پنج‌روزه؛ زمان‌ها و اقامت باید تأیید شوند و بلیت یا ویزایی صادر نمی‌شود.', 0],
    ['stay', 'A place to slow down · accommodation request', 'فرصتی برای آرامش · درخواست اقامت', 'Tell the agency your dates and occupancy. This request does not reserve a room.', 'تاریخ و تعداد مهمان را اعلام کنید؛ این درخواست اتاقی را رزرو نمی‌کند.', 0],
    ['rental', 'Find your own pace · vehicle request', 'با ریتم خودتان · درخواست وسیلهٔ نقلیه', 'Request a bicycle, motorcycle, car or van. Availability, eligibility and deposit terms need confirmation.', 'درخواست دوچرخه، موتورسیکلت، خودرو یا ون؛ موجودی و شرایط باید تأیید شوند.', 0],
    ['transfer', 'Arrive with a plan · transfer request', 'با برنامه برسید · درخواست ترانسفر', 'Share pickup, drop-off and passenger requirements. No live dispatch or tracking is promised.', 'مبدأ، مقصد و تعداد مسافر را مشخص کنید؛ اعزام یا رهگیری زنده‌ای وعده داده نمی‌شود.', 0],
    ['flight', 'Your next connection · passenger inquiry', 'مسیر بعدی شما · درخواست بلیت', 'Request information about a flight, train or bus. No live fare, seat, PNR or ticket issuance.', 'درخواست اطلاعات پرواز، قطار یا اتوبوس؛ قیمت یا صندلی زنده و صدور بلیت وجود ندارد.', 0],
];
foreach ($offers as [$kind, $en, $fa, $descriptionEn, $descriptionFa, $amount]) {
    if (!empty($fixtures[$kind]) && get_post($fixtures[$kind])) {
        continue; // Never overwrite a user's subsequent local edits or booked offer versions.
    }
    $existing = get_posts(['post_type'=>'atlas_offer','post_status'=>'private','title'=>$en,'numberposts'=>1]);
    if ($existing) {
        $fixtures[$kind] = $existing[0]->ID;
        update_option('atlas_demo_fixtures', $fixtures, false);
        continue;
    }
    $productId = 0;
    if ($amount > 0) {
        $product = new WC_Product_Simple();
        $product->set_name($en);
        $product->set_virtual(true);
        $product->set_catalog_visibility('hidden');
        $product->set_regular_price((string) $amount);
        $product->set_tax_status('none');
        $productId = $product->save();
    }
    $id = Atlas\Core\Offers::save(0, [
        'kind' => $kind, 'org_id' => 0, 'title_en' => $en, 'title_fa' => $fa,
        'description_en' => $descriptionEn, 'description_fa' => $descriptionFa,
        'registration_open_at' => $amount ? $open : '', 'registration_close_at' => $amount ? $close : '',
        'timezone' => 'Asia/Tehran', 'capacity' => $amount ? 30 : 0,
        'amount_irr' => $amount, 'mode' => $amount ? 'local' : 'request_only',
        'status' => in_array($kind, ['tour','cohort'], true) ? 'draft' : 'published', 'product_id' => $productId,
    ], get_current_user_id());
    if (is_wp_error($id)) {
        WP_CLI::error($id->get_error_message());
    }
    if ($productId) { update_post_meta($productId, '_atlas_offer_id', $id); }
    $fixtures[$kind] = $id;
    update_option('atlas_demo_fixtures', $fixtures, false);
}
if (empty($fixtures['merch_product'])) {
    $product = new WC_Product_Simple();
    $product->set_name('Paper trail journal · local demonstration');
    $product->set_description('A fictional physical souvenir product for testing shipping and checkout.');
    $product->set_regular_price('2500000');
    $product->set_manage_stock(true);
    $product->set_stock_quantity(10);
    $fixtures['merch_product'] = $product->save();
}
// Use Woo's public installer/filter to obtain its actual nested block templates.
// A self-closing checkout comment has no inner blocks and renders an empty form.
$wooPages = [];
$capturePages = static function ($pages) use (&$wooPages) { $wooPages = $pages; return $pages; };
add_filter('woocommerce_create_pages', $capturePages, 999);
WC_Install::create_pages();
remove_filter('woocommerce_create_pages', $capturePages, 999);
foreach (['cart', 'checkout'] as $pageKind) {
    if (empty($wooPages[$pageKind]['content'])) { WP_CLI::error('WooCommerce default block template unavailable.'); }
}
foreach (['Discover' => '[atlas_catalog]', 'My experiences' => '[atlas_account]', 'Partner workspace' => '[atlas_portal]', 'Cart' => $wooPages['cart']['content'], 'Checkout' => $wooPages['checkout']['content']] as $title => $content) {
    $key = 'page_' . sanitize_title($title);
    if (!empty($fixtures[$key]) && ($existingPage = get_post($fixtures[$key]))) {
        // Repair only our exact earlier invalid fixture, preserving all edited pages.
        if (in_array($title, ['Cart','Checkout'], true)
            && trim($existingPage->post_content) === '<!-- wp:woocommerce/' . strtolower($title) . ' /-->') {
            wp_update_post(['ID'=>$existingPage->ID, 'post_content'=>$content]);
        }
        continue;
    }
    if (in_array($title, ['Cart','Checkout'], true) && wc_get_page_id(strtolower($title)) > 0) {
        $fixtures[$key] = wc_get_page_id(strtolower($title));
        continue;
    }
    $page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $content]);
    $fixtures[$key] = $page;
    if ($title === 'Cart') { update_option('woocommerce_cart_page_id', $page); }
    if ($title === 'Checkout') { update_option('woocommerce_checkout_page_id', $page); }
    if ($title === 'My experiences') { update_option('woocommerce_myaccount_page_id', $page); }
}
foreach (['cart','checkout'] as $pageKind) {
    update_option('woocommerce_' . $pageKind . '_page_id', $fixtures['page_' . $pageKind]);
}
if (!empty($fixtures['page_my-experiences'])) { update_option('woocommerce_myaccount_page_id', $fixtures['page_my-experiences']); }
update_option('atlas_demo_fixtures', $fixtures, false);
file_put_contents(dirname(__DIR__) . '/.runtime/browser-fixtures.json', wp_json_encode($fixtures));
WP_CLI::success('Seeded fictional local fixtures; real supplier/payment capabilities remain disabled.');
