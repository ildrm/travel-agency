<?php
/** Atlas Leisure presentation and optional companion integration. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Explicit locale override; invalid values never change site locale. */
function atlas_theme_requested_language() {
    $content_language = apply_filters( 'atlas_editorial_language', null );
    if ( in_array( $content_language, array( 'en', 'fa' ), true ) ) { return $content_language; }
    if ( isset( $_GET['lang'] ) && is_string( $_GET['lang'] ) ) {
        $lang = sanitize_key( wp_unslash( $_GET['lang'] ) );
        if ( in_array( $lang, array( 'en', 'fa' ), true ) ) { return $lang; }
    }
    $locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
    return str_starts_with( $locale, 'fa' ) ? 'fa' : 'en';
}
/** Make WP/Woo gettext use the selected locale for public requests. */
function atlas_theme_locale( $locale ) {
    if ( is_admin() ) { return $locale; }
    $content_language = apply_filters( 'atlas_editorial_language', null );
    if ( in_array( $content_language, array( 'en', 'fa' ), true ) ) { return 'fa' === $content_language ? 'fa_IR' : 'en_US'; }
    if ( ! isset( $_GET['lang'] ) || ! is_string( $_GET['lang'] ) ) { return $locale; }
    $lang = sanitize_key( wp_unslash( $_GET['lang'] ) );
    return 'fa' === $lang ? 'fa_IR' : ( 'en' === $lang ? 'en_US' : $locale );
}
add_filter( 'locale', 'atlas_theme_locale' );
add_filter( 'determine_locale', 'atlas_theme_locale' );
function atlas_theme_language_attributes( $output ) {
    $lang = atlas_theme_requested_language();
    return 'lang="' . esc_attr( $lang ) . '" dir="' . ( 'fa' === $lang ? 'rtl' : 'ltr' ) . '"';
}
add_filter( 'language_attributes', 'atlas_theme_language_attributes' );
/** Product copy is bilingual even if the site has no installed translation packs. */
function atlas_theme_text( $english, $persian ) {
    return 'fa' === atlas_theme_requested_language() ? $persian : $english;
}
function atlas_theme_setup() {
    load_theme_textdomain( 'atlas-leisure', get_template_directory() . '/languages' );
    add_theme_support( 'woocommerce' );
    add_theme_support( 'editor-styles' );
    register_nav_menus( array( 'primary' => __( 'Primary navigation', 'atlas-leisure' ), 'footer' => __( 'Footer navigation', 'atlas-leisure' ) ) );
    // WordPress otherwise injects a second skip link before our localized body-open link.
    remove_action( 'wp_enqueue_scripts', 'wp_enqueue_block_template_skip_link' );
    remove_action( 'wp_footer', 'the_block_template_skip_link' );
    add_editor_style( 'assets/css/editor.css' );
}
add_action( 'after_setup_theme', 'atlas_theme_setup' );
function atlas_theme_assets() {
    $version = wp_get_theme( get_template() )->get( 'Version' );
    $script = 'assets/js/navigation.js';
    $style  = 'assets/css/main.css';
    wp_enqueue_script( 'atlas-navigation', get_theme_file_uri( $script ), array(), $version . '-' . filemtime( get_theme_file_path( $script ) ), true );
    wp_enqueue_style( 'atlas-leisure', get_theme_file_uri( $style ), array(), $version . '-' . filemtime( get_theme_file_path( $style ) ) );
}
add_action( 'wp_enqueue_scripts', 'atlas_theme_assets' );
/** Preserve only the validated presentation locale in local destination links. */
function atlas_theme_url( $args = array() ) {
    $args['lang'] = atlas_theme_requested_language();
    return add_query_arg( $args, home_url( '/' ) );
}
function atlas_theme_companion( $name, $attributes = array() ) {
    if ( ! shortcode_exists( $name ) ) {
        return '<div class="atlas-notice" role="status">' . esc_html( atlas_theme_text( 'This service is not available yet. Please contact the agency for assistance.', 'این خدمت هنوز در دسترس نیست. برای راهنمایی با مجموعه تماس بگیرید.' ) ) . '</div>';
    }
    $shortcode = '[' . $name;
    foreach ( $attributes as $key => $value ) {
        $shortcode .= ' ' . sanitize_key( $key ) . '="' . esc_attr( $value ) . '"';
    }
    return do_shortcode( $shortcode . ']' );
}
function atlas_theme_language_switch() {
    $base = remove_query_arg( array( 'lang' ) );
    $current = atlas_theme_requested_language();
    $urls = apply_filters( 'atlas_editorial_language_urls', array( 'en' => add_query_arg( 'lang', 'en', $base ), 'fa' => add_query_arg( 'lang', 'fa', $base ) ) );
    $links = array();
    foreach ( array( 'en' => 'EN', 'fa' => 'فارسی' ) as $language => $label ) {
        if ( isset( $urls[ $language ] ) ) { $links[] = '<a lang="' . $language . '" hreflang="' . $language . '"' . ( $language === $current ? ' aria-current="true"' : '' ) . ' href="' . esc_url( $urls[ $language ] ) . '">' . $label . '</a>'; }
    }
    return '<nav class="atlas-language" aria-label="' . esc_attr( atlas_theme_text( 'Language', 'زبان' ) ) . '">' . implode( '<span aria-hidden="true">/</span>', $links ) . '</nav>';
}
add_shortcode( 'atlas_theme_language', 'atlas_theme_language_switch' );
function atlas_theme_utility_links() {
    $link = function ( $query, $en, $fa ) { return '<a href="' . esc_url( atlas_theme_url( $query ) ) . '">' . esc_html( atlas_theme_text( $en, $fa ) ) . '</a>'; };
    $account = shortcode_exists( 'atlas_account' ) ? $link( array( 'atlas_area' => 'account' ), 'My account', 'حساب من' ) : '';
    if ( $account === '' && function_exists( 'wc_get_page_permalink' ) ) {
        $account_url = wc_get_page_permalink( 'myaccount', '' );
        if ( $account_url ) $account = '<a href="' . esc_url( atlas_theme_localized_url( $account_url ) ) . '">' . esc_html( atlas_theme_text( 'My account', 'حساب من' ) ) . '</a>';
    }
    $portal = shortcode_exists( 'atlas_portal' ) ? $link( array( 'atlas_area' => 'portal' ), 'Partner portal', 'پنل همکاران' ) : '';
    $cart = function_exists( 'wc_get_cart_url' ) ? '<a href="' . esc_url( add_query_arg( 'lang', atlas_theme_requested_language(), wc_get_cart_url() ) ) . '">' . esc_html( atlas_theme_text( 'Cart', 'سبد خرید' ) ) . '</a>' : '';
    if ( $account === '' && $portal === '' && $cart === '' ) return '';
    return '<nav class="atlas-utility" aria-label="' . esc_attr( atlas_theme_text( 'Account and shopping', 'حساب کاربری و خرید' ) ) . '">' . $account . $portal . $cart . '</nav>';
}
add_shortcode( 'atlas_theme_utility', 'atlas_theme_utility_links' );
function atlas_theme_home() {
    $area = isset( $_GET['atlas_area'] ) && is_string( $_GET['atlas_area'] ) ? sanitize_key( wp_unslash( $_GET['atlas_area'] ) ) : '';
    $has_offer = isset( $_GET['offer_id'] );
    $offer = $has_offer && is_string( $_GET['offer_id'] ) ? filter_var( $_GET['offer_id'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) : false;
    $has_catalog = shortcode_exists( 'atlas_catalog' );
    $allowed = array( 'tour', 'event', 'cohort', 'stay', 'rental', 'transfer', 'flight', 'rail', 'bus' );
    $kind = isset( $_GET['atlas_kind'] ) && is_string( $_GET['atlas_kind'] ) ? sanitize_key( wp_unslash( $_GET['atlas_kind'] ) ) : '';
    if ( ! in_array( $kind, $allowed, true ) ) { $kind = ''; }
    ob_start();
    if ( in_array( $area, array( 'account', 'portal' ), true ) ) {
        echo '<div class="atlas-shell atlas-app"><a class="atlas-back" href="' . esc_url( atlas_theme_url() ) . '">' . esc_html( atlas_theme_text( '← Back to discovery', 'بازگشت به گردش ←' ) ) . '</a><h1>' . esc_html( 'account' === $area ? atlas_theme_text( 'My account', 'حساب من' ) : atlas_theme_text( 'Partner portal', 'پنل همکاران' ) ) . '</h1>';
        echo atlas_theme_companion( 'account' === $area ? 'atlas_account' : 'atlas_portal' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode owns escaping.
        echo '</div>';
        return ob_get_clean();
    }
    if ( $has_offer ) {
        echo '<div class="atlas-shell atlas-app"><a class="atlas-back" href="' . esc_url( atlas_theme_url( $kind ? array( 'atlas_kind' => $kind ) : array() ) . '#atlas-offers' ) . '">' . esc_html( atlas_theme_text( 'Back to offers', 'بازگشت به پیشنهادها' ) ) . '</a>';
        if ( $offer === false ) {
            echo '<h1>' . esc_html( atlas_theme_text( 'Offer unavailable', 'پیشنهاد در دسترس نیست' ) ) . '</h1><p>' . esc_html( atlas_theme_text( 'Use the catalog to find an available offer.', 'برای یافتن پیشنهادهای در دسترس به فهرست مراجعه کنید.' ) ) . '</p>';
        } else {
            echo atlas_theme_companion( 'atlas_offer', array( 'id' => $offer ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode owns escaping.
        }
        echo '</div>';
        return ob_get_clean();
    }
    ?>
    <section class="atlas-hero" aria-labelledby="atlas-home-title">
      <div class="atlas-shell atlas-hero__grid">
        <div class="atlas-hero__copy">
          <h1 id="atlas-home-title"><?php echo esc_html( atlas_theme_text( 'Where would you like to go?', 'دوست دارید کجا بروید؟' ) ); ?></h1>
          <p class="atlas-hero__lead"><?php echo esc_html( atlas_theme_text( 'Find a tour, a place to stay, or something to learn.', 'تور، اقامتگاه یا دورهٔ آموزشی دلخواهتان را پیدا کنید.' ) ); ?></p>
          <?php if ( $has_catalog ) : ?>
          <form class="atlas-hero-search" role="search" aria-label="<?php echo esc_attr( atlas_theme_text( 'Find experiences', 'جست‌وجوی تجربه‌ها' ) ); ?>" method="get" action="<?php echo esc_url( home_url( '/' ) . '#atlas-offers' ); ?>">
            <input type="hidden" name="lang" value="<?php echo esc_attr( atlas_theme_requested_language() ); ?>">
            <label for="atlas-home-destination"><?php echo esc_html( atlas_theme_text( 'Destination', 'مقصد' ) ); ?></label>
            <div><input type="search" id="atlas-home-destination" name="cat_destination" maxlength="100" placeholder="<?php echo esc_attr( atlas_theme_text( 'City or place', 'نام شهر یا مکان' ) ); ?>">
            <button class="atlas-button" type="submit"><?php echo esc_html( atlas_theme_text( 'Search offers', 'جست‌وجوی پیشنهادها' ) ); ?></button></div>
          </form>
          <a class="atlas-text-link" href="<?php echo esc_url( atlas_theme_url() . '#atlas-offers' ); ?>"><?php echo esc_html( atlas_theme_text( 'Browse all experiences', 'مشاهدهٔ همهٔ تجربه‌ها' ) ); ?></a>
          <?php endif; ?>
        </div>
        <div class="atlas-hero__art" aria-hidden="true"><img src="<?php echo esc_url( get_theme_file_uri( 'assets/svg/paper-landscape.svg' ) ); ?>" alt="" width="900" height="760" fetchpriority="high"></div>
      </div>
    </section>
    <?php if ( $has_catalog ) : ?>
    <section class="atlas-explore atlas-shell" id="atlas-discover" aria-labelledby="atlas-explore-title">
      <h2 id="atlas-explore-title"><?php echo esc_html( atlas_theme_text( 'Browse by category', 'مرور بر اساس دسته‌بندی' ) ); ?></h2>
      <nav class="atlas-category-grid" aria-labelledby="atlas-explore-title">
      <?php
      $categories = array(
          'tour' => atlas_theme_text( 'Tours', 'تورها' ),
          'event' => atlas_theme_text( 'Events', 'رویدادها' ),
          'cohort' => atlas_theme_text( 'Academy', 'آموزشگاه' ),
          'stay' => atlas_theme_text( 'Stays', 'اقامتگاه‌ها' ),
          'rental' => atlas_theme_text( 'Rentals', 'اجارهٔ خودرو' ),
          'transfer' => atlas_theme_text( 'Transfers', 'ترانسفر' ),
      );
      foreach ( $categories as $key => $label ) {
          echo '<a class="atlas-category" href="' . esc_url( atlas_theme_url( array( 'atlas_kind' => $key ) ) . '#atlas-offers' ) . '">' . esc_html( $label ) . '</a>';
      }
      ?>
      </nav>
    </section>
    <?php endif; ?>
    <section class="atlas-listings" id="atlas-offers" aria-labelledby="atlas-offers-title"><div class="atlas-shell"><div class="atlas-section-head"><h2 id="atlas-offers-title"><?php echo esc_html( atlas_theme_text( 'Explore offers', 'مشاهدهٔ پیشنهادها' ) ); ?></h2></div>
      <?php echo atlas_theme_companion( 'atlas_catalog', $kind ? array( 'kind' => $kind ) : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode owns escaping. ?>
    </div></section>
    <section class="atlas-how atlas-shell" id="atlas-how" aria-labelledby="atlas-how-title"><div class="atlas-how__intro"><p class="atlas-eyebrow"><?php echo esc_html( atlas_theme_text( 'The journey, made clear', 'مسیر روشن‌تر' ) ); ?></p><h2 id="atlas-how-title"><?php echo esc_html( atlas_theme_text( 'Good plans start with good details.', 'برنامه خوب با جزئیات روشن شروع می‌شود.' ) ); ?></h2><p><?php echo esc_html( atlas_theme_text( 'Explore at your own pace, check the details and reach out when you need a hand.', 'با فرصت کافی بگردید، جزئیات را بررسی کنید و هر زمان به کمک نیاز داشتید با ما در تماس باشید.' ) ); ?></p></div><ol class="atlas-steps"><li><span>01</span><div><h3><?php echo esc_html( atlas_theme_text( 'Find your experience', 'تجربه خود را پیدا کنید' ) ); ?></h3><p><?php echo esc_html( atlas_theme_text( 'Browse tours, events, learning and more.', 'تور، رویداد، آموزش و پیشنهادهای دیگر را ببینید.' ) ); ?></p></div></li><li><span>02</span><div><h3><?php echo esc_html( atlas_theme_text( 'Check the details', 'جزئیات را بررسی کنید' ) ); ?></h3><p><?php echo esc_html( atlas_theme_text( 'Review timing, availability, inclusions and terms.', 'زمان، وضعیت، خدمات و شرایط را بخوانید.' ) ); ?></p></div></li><li><span>03</span><div><h3><?php echo esc_html( atlas_theme_text( 'Continue with confidence', 'با آگاهی ادامه دهید' ) ); ?></h3><p><?php echo esc_html( atlas_theme_text( 'Register or send a request where available.', 'در صورت امکان ثبت‌نام کنید یا درخواست بفرستید.' ) ); ?></p></div></li></ol></section>
    <?php
    return ob_get_clean();
}
add_shortcode( 'atlas_theme_home', 'atlas_theme_home' );

function atlas_theme_not_found() {
    return '<h1>' . esc_html( atlas_theme_text( 'We could not find that page.', 'این صفحه پیدا نشد.' ) ) . '</h1><p>' . esc_html( atlas_theme_text( 'Try a search or return to the homepage.', 'جست‌وجو کنید یا به صفحه اصلی برگردید.' ) ) . '</p><p><a href="' . esc_url( atlas_theme_url() ) . '">' . esc_html( atlas_theme_text( 'Go to home', 'رفتن به صفحه اصلی' ) ) . '</a></p>';
}
add_shortcode( 'atlas_theme_404', 'atlas_theme_not_found' );

function atlas_theme_skip_link() {
    return '<a class="atlas-skip" href="#atlas-main-content">' . esc_html( atlas_theme_text( 'Skip to content', 'رفتن به محتوای اصلی' ) ) . '</a>';
}
add_shortcode( 'atlas_theme_skip', 'atlas_theme_skip_link' );

function atlas_theme_empty() {
    return '<p>' . esc_html( atlas_theme_text( 'No results found. Try another search.', 'نتیجه‌ای پیدا نشد. جست‌وجوی دیگری را امتحان کنید.' ) ) . '</p>';
}
add_shortcode( 'atlas_theme_empty', 'atlas_theme_empty' );
function atlas_theme_search_form() {
    $id = wp_unique_id( 'atlas-search-' );
    return '<form role="search" method="get" action="' . esc_url( home_url( '/' ) ) . '"><label for="' . esc_attr( $id ) . '">' . esc_html( atlas_theme_text( 'Search', 'جست‌وجو' ) ) . '</label> <input id="' . esc_attr( $id ) . '" type="search" name="s" value="' . esc_attr( get_search_query() ) . '"><input type="hidden" name="lang" value="' . esc_attr( atlas_theme_requested_language() ) . '"> <button type="submit">' . esc_html( atlas_theme_text( 'Search', 'جست‌وجو' ) ) . '</button></form>';
}
add_shortcode( 'atlas_theme_search', 'atlas_theme_search_form' );

/** Resolve origin without copying caller query data into another URL. */
function atlas_theme_same_origin_url( $href ) {
    if ( ! is_string( $href ) || '' === $href || str_contains( $href, '\\' ) ) { return false; }
    $parts = wp_parse_url( $href );
    $home = wp_parse_url( home_url( '/' ) );
    if ( false === $parts || false === $home ) { return false; }
    if ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) { return false; }
    if ( isset( $parts['host'] ) ) {
        $home_scheme = strtolower( $home['scheme'] ?? 'http' );
        $scheme = strtolower( $parts['scheme'] ?? $home_scheme );
        $home_port = (int) ( $home['port'] ?? ( 'https' === $home_scheme ? 443 : 80 ) );
        $port = (int) ( $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );
        if ( ! isset( $home['host'] ) || strtolower( $parts['host'] ) !== strtolower( $home['host'] ) || $scheme !== $home_scheme || $port !== $home_port ) { return false; }
    } elseif ( isset( $parts['scheme'] ) ) { return false; }
    return true;
}
/** Preserve the validated locale only on same-origin or relative navigation. */
function atlas_theme_localized_url( $href, $language = null ) {
    if ( ! atlas_theme_same_origin_url( $href ) || '#' === $href[0] ) { return $href; }
    $language = in_array( $language, array( 'en', 'fa' ), true ) ? $language : atlas_theme_requested_language();
    return add_query_arg( 'lang', $language, $href );
}
/** Core navigation blocks and configured classic menu locations share one URL policy. */
function atlas_theme_navigation_locale( $block_content ) {
    if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) { return $block_content; }
    $processor = new WP_HTML_Tag_Processor( $block_content );
    if ( ! $processor->next_tag( 'a' ) ) { return $block_content; }
    $href = $processor->get_attribute( 'href' );
    if ( ! is_string( $href ) ) { return $block_content; }
    $processor->set_attribute( 'href', atlas_theme_localized_url( $href ) );
    return $processor->get_updated_html();
}
add_filter( 'render_block_core/navigation-link', 'atlas_theme_navigation_locale' );
function atlas_theme_menu_link_attributes( $attributes, $item, $args, $depth ) {
    if ( ! isset( $args->theme_location ) || ! in_array( $args->theme_location, array( 'primary', 'footer' ), true ) ) { return $attributes; }
    if ( isset( $attributes['href'] ) ) { $attributes['href'] = atlas_theme_localized_url( $attributes['href'] ); }
    return $attributes;
}
add_filter( 'nav_menu_link_attributes', 'atlas_theme_menu_link_attributes', 10, 4 );

/** Woo may build receipt URLs in a Store API request with no locale query. */
function atlas_theme_woocommerce_url( $url ) {
    if ( is_admin() || ! atlas_theme_same_origin_url( $url ) ) { return $url; }
    $language = isset( $_GET['lang'] ) && is_string( $_GET['lang'] ) && in_array( $_GET['lang'], array( 'en', 'fa' ), true ) ? $_GET['lang'] : null;
    if ( null === $language ) {
        $query = array(); parse_str( wp_parse_url( $url, PHP_URL_QUERY ) ?? '', $query );
        if ( isset( $query['lang'] ) && is_string( $query['lang'] ) && in_array( $query['lang'], array( 'en', 'fa' ), true ) ) { $language = $query['lang']; }
    }
    if ( null === $language && isset( $_SERVER['HTTP_REFERER'] ) && is_string( $_SERVER['HTTP_REFERER'] ) && atlas_theme_same_origin_url( $_SERVER['HTTP_REFERER'] ) ) {
        $query = array(); parse_str( wp_parse_url( $_SERVER['HTTP_REFERER'], PHP_URL_QUERY ) ?? '', $query );
        if ( isset( $query['lang'] ) && is_string( $query['lang'] ) && in_array( $query['lang'], array( 'en', 'fa' ), true ) ) { $language = $query['lang']; }
    }
    // Only the validated language is transferred; order keys and other source
    // query values are never copied, logged or sent to a different origin.
    return atlas_theme_localized_url( $url, $language );
}
foreach ( array( 'woocommerce_get_cart_url', 'woocommerce_get_checkout_url', 'woocommerce_get_checkout_payment_url', 'woocommerce_get_checkout_order_received_url' ) as $atlas_woo_url_filter ) {
    add_filter( $atlas_woo_url_filter, 'atlas_theme_woocommerce_url' );
}
/** Translate only default English Woo system-page headings at presentation time. */
function atlas_theme_woocommerce_page_title( $title, $post_id ) {
    if ( is_admin() || 'fa' !== atlas_theme_requested_language() || ! function_exists( 'wc_get_page_id' ) ) { return $title; }
    $defaults = array(
        'cart' => array( array( 'Cart' ), 'سبد خرید' ),
        'checkout' => array( array( 'Checkout' ), 'تسویه حساب' ),
        'myaccount' => array( array( 'My account', 'My Account', 'My experiences', 'My Experiences' ), 'حساب من' ),
        'shop' => array( array( 'Shop' ), 'فروشگاه' ),
    );
    foreach ( $defaults as $page => $copy ) {
        if ( (int) $post_id > 0 && (int) $post_id === wc_get_page_id( $page ) && in_array( $title, $copy[0], true ) ) { return $copy[1]; }
    }
    return $title;
}
add_filter( 'the_title', 'atlas_theme_woocommerce_page_title', 10, 2 );

/** Emit one skip link before every theme template. */
function atlas_theme_print_skip() { echo atlas_theme_skip_link(); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes content.
add_action( 'wp_body_open', 'atlas_theme_print_skip', 1 );
/** Real list markup; editors may configure standard WordPress menus for each location. */
function atlas_theme_navigation( $attributes = array() ) {
    $attributes = shortcode_atts( array( 'location' => 'primary' ), $attributes, 'atlas_theme_navigation' );
    $location = in_array( $attributes['location'], array( 'primary', 'footer' ), true ) ? $attributes['location'] : 'primary';
    $label = 'footer' === $location ? atlas_theme_text( 'Footer navigation', 'پیوندهای پایین صفحه' ) : atlas_theme_text( 'Main navigation', 'ناوبری اصلی' );
    if ( has_nav_menu( $location ) ) {
        $list = wp_nav_menu( array( 'theme_location' => $location, 'container' => false, 'echo' => false, 'fallback_cb' => false, 'menu_class' => 'atlas-nav-list', 'depth' => 2 ) );
    } else {
        $items = shortcode_exists( 'atlas_catalog' ) ? array(
            array( array(), 'Explore', 'کاوش', '#atlas-discover' ),
            array( array( 'atlas_kind' => 'tour' ), 'Tours', 'تورها', '#atlas-offers' ),
            array( array( 'atlas_kind' => 'event' ), 'Events', 'رویدادها', '#atlas-offers' ),
            array( array( 'atlas_kind' => 'cohort' ), 'Academy', 'آموزشگاه', '#atlas-offers' ),
        ) : array( array( array(), 'Home', 'صفحهٔ اصلی', '' ) );
        $list = '<ul class="atlas-nav-list">';
        foreach ( $items as $item ) {
            $list .= '<li><a href="' . esc_url( atlas_theme_url( $item[0] ) . $item[3] ) . '">' . esc_html( atlas_theme_text( $item[1], $item[2] ) ) . '</a></li>';
        }
        $list .= '</ul>';
    }
    if ( $location === 'footer' && shortcode_exists( 'atlas_account' ) ) {
        $support = atlas_theme_url( array( 'atlas_area' => 'account', 'atlas_task' => 'support_case' ) ) . '#atlas-task-support_case';
        $list .= '<a href="' . esc_url( $support ) . '">' . esc_html( atlas_theme_text( 'Customer support', 'پشتیبانی مشتریان' ) ) . '</a>';
    }
    return '<nav class="atlas-main-navigation" aria-label="' . esc_attr( $label ) . '">' . $list . '</nav>'; // WP menu output escapes its fields.
}
add_shortcode( 'atlas_theme_navigation', 'atlas_theme_navigation' );
