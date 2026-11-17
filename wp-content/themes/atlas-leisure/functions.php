<?php
/** Atlas Leisure presentation and optional companion integration. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Explicit locale override; invalid values never change site locale. */
function atlas_theme_requested_language() {
    if ( isset( $_GET['lang'] ) && is_string( $_GET['lang'] ) ) {
        $lang = sanitize_key( wp_unslash( $_GET['lang'] ) );
        if ( in_array( $lang, array( 'en', 'fa' ), true ) ) { return $lang; }
    }
    $locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
    return str_starts_with( $locale, 'fa' ) ? 'fa' : 'en';
}
/** Make WP/Woo gettext use the selected locale for public requests. */
function atlas_theme_locale( $locale ) {
    if ( is_admin() || ! isset( $_GET['lang'] ) || ! is_string( $_GET['lang'] ) ) { return $locale; }
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
    $path = get_theme_file_path( 'assets/css/main.css' );
    wp_enqueue_style( 'atlas-leisure', get_theme_file_uri( 'assets/css/main.css' ), array(), file_exists( $path ) ? (string) filemtime( $path ) : '0.1.0' );
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
    return '<nav class="atlas-language" aria-label="' . esc_attr( atlas_theme_text( 'Language', 'زبان' ) ) . '"><a lang="en" hreflang="en"' . ( 'en' === $current ? ' aria-current="true"' : '' ) . ' href="' . esc_url( add_query_arg( 'lang', 'en', $base ) ) . '">EN</a><span aria-hidden="true">/</span><a lang="fa" hreflang="fa"' . ( 'fa' === $current ? ' aria-current="true"' : '' ) . ' href="' . esc_url( add_query_arg( 'lang', 'fa', $base ) ) . '">فارسی</a></nav>';
}
add_shortcode( 'atlas_theme_language', 'atlas_theme_language_switch' );
function atlas_theme_utility_links() {
    $link = function ( $query, $en, $fa ) { return '<a href="' . esc_url( atlas_theme_url( $query ) ) . '">' . esc_html( atlas_theme_text( $en, $fa ) ) . '</a>'; };
    $cart = function_exists( 'wc_get_cart_url' ) ? '<a href="' . esc_url( add_query_arg( 'lang', atlas_theme_requested_language(), wc_get_cart_url() ) ) . '">' . esc_html( atlas_theme_text( 'Cart', 'سبد خرید' ) ) . '</a>' : '';
    return '<nav class="atlas-utility" aria-label="' . esc_attr( atlas_theme_text( 'Account and partner navigation', 'حساب کاربری و همکاران' ) ) . '">' . $link( array( 'atlas_area' => 'account' ), 'My account', 'حساب من' ) . $link( array( 'atlas_area' => 'portal' ), 'Partner portal', 'پنل همکاران' ) . $cart . '</nav>';
}
add_shortcode( 'atlas_theme_utility', 'atlas_theme_utility_links' );
function atlas_theme_home() {
    $area = isset( $_GET['atlas_area'] ) && is_string( $_GET['atlas_area'] ) ? sanitize_key( wp_unslash( $_GET['atlas_area'] ) ) : '';
    $offer = isset( $_GET['offer_id'] ) ? absint( $_GET['offer_id'] ) : 0;
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
    if ( $offer ) {
        echo '<div class="atlas-shell atlas-app"><a class="atlas-back" href="' . esc_url( atlas_theme_url( array( 'atlas_kind' => $kind ) ) ) . '">' . esc_html( atlas_theme_text( '← Explore experiences', 'بازگشت به تجربه‌ها ←' ) ) . '</a>';
        echo atlas_theme_companion( 'atlas_offer', array( 'id' => $offer ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode owns escaping.
        echo '</div>';
        return ob_get_clean();
    }
    ?>
    <section class="atlas-hero" aria-labelledby="atlas-home-title">
      <div class="atlas-shell atlas-hero__grid">
        <div class="atlas-hero__copy">
          <p class="atlas-eyebrow"><span class="atlas-dot" aria-hidden="true"></span><?php echo esc_html( atlas_theme_text( 'A little further, together', 'با هم، کمی دورتر' ) ); ?></p>
          <h1 id="atlas-home-title"><?php echo esc_html( atlas_theme_text( 'Make room for', 'برای لحظه‌های' ) ); ?><br><em><?php echo esc_html( atlas_theme_text( 'the memorable.', 'ماندگار جا باز کنید.' ) ); ?></em></h1>
          <p class="atlas-hero__lead"><?php echo esc_html( atlas_theme_text( 'Thoughtful journeys, shared experiences and new things to learn — all in one place.', 'سفرهای سنجیده، تجربه‌های مشترک و فرصت‌های تازه برای یادگیری؛ همه در یک جا.' ) ); ?></p>
          <div class="atlas-hero__actions"><a class="atlas-button" href="#atlas-discover"><?php echo esc_html( atlas_theme_text( 'Explore experiences', 'کاوش تجربه‌ها' ) ); ?><span aria-hidden="true"> ↗</span></a><a class="atlas-text-link" href="#atlas-how"><?php echo esc_html( atlas_theme_text( 'How it works', 'چطور کار می‌کند؟' ) ); ?></a></div>
          <p class="atlas-hero__note"><?php echo esc_html( atlas_theme_text( 'Browse at your own pace. Details and availability are shown with each offer.', 'با آسودگی جست‌وجو کنید. جزئیات و وضعیت هر پیشنهاد در صفحه آن نمایش داده می‌شود.' ) ); ?></p>
        </div>
        <div class="atlas-hero__art" aria-hidden="true"><img src="<?php echo esc_url( get_theme_file_uri( 'assets/svg/paper-landscape.svg' ) ); ?>" alt="" width="900" height="760" fetchpriority="high"><span class="atlas-art-label"><?php echo esc_html( atlas_theme_text( 'A world of possibilities', 'دنیایی از امکان‌ها' ) ); ?></span></div>
      </div>
    </section>
    <section class="atlas-explore atlas-shell" id="atlas-discover" aria-labelledby="atlas-explore-title">
      <div class="atlas-section-head"><div><p class="atlas-eyebrow"><?php echo esc_html( atlas_theme_text( 'Find your next story', 'داستان بعدی شما' ) ); ?></p><h2 id="atlas-explore-title"><?php echo esc_html( atlas_theme_text( 'Explore your way', 'به سبک خودتان بگردید' ) ); ?></h2></div><p><?php echo esc_html( atlas_theme_text( 'Choose a starting point, then find the details that matter to you.', 'از یک موضوع شروع کنید و جزئیاتی را ببینید که برایتان مهم است.' ) ); ?></p></div>
      <nav class="atlas-category-grid" aria-label="<?php echo esc_attr( atlas_theme_text( 'Experience categories', 'دسته‌بندی تجربه‌ها' ) ); ?>">
      <?php
      $categories = array(
          array( 'tour', '01', 'Tours', 'تورهای سفر', 'Go somewhere new.', 'به جایی تازه بروید.' ),
          array( 'event', '02', 'Events', 'رویدادها', 'Make time together.', 'برای با هم بودن وقت بگذارید.' ),
          array( 'cohort', '03', 'Academy', 'آموزشگاه', 'Learn by doing.', 'با تجربه یاد بگیرید.' ),
          array( 'stay', '04', 'Stays', 'اقامت', 'Find a place to pause.', 'جایی برای آرام گرفتن بیابید.' ),
      );
      foreach ( $categories as $item ) {
          echo '<a class="atlas-category" href="' . esc_url( atlas_theme_url( array( 'atlas_kind' => $item[0] ) ) . '#atlas-offers' ) . '"><span class="atlas-category__num">' . esc_html( $item[1] ) . '</span><span class="atlas-category__arrow" aria-hidden="true">↗</span><strong>' . esc_html( atlas_theme_text( $item[2], $item[3] ) ) . '</strong><span>' . esc_html( atlas_theme_text( $item[4], $item[5] ) ) . '</span></a>';
      }
      ?>
      </nav>
    </section>
    <section class="atlas-listings" id="atlas-offers" aria-labelledby="atlas-offers-title"><div class="atlas-shell"><div class="atlas-section-head"><div><p class="atlas-eyebrow"><?php echo esc_html( atlas_theme_text( 'Made for curious minds', 'برای ذهن‌های کنجکاو' ) ); ?></p><h2 id="atlas-offers-title"><?php echo esc_html( atlas_theme_text( 'Discover what is on', 'پیشنهادهای پیش رو' ) ); ?></h2></div><a class="atlas-text-link" href="<?php echo esc_url( atlas_theme_url() . '#atlas-offers' ); ?>"><?php echo esc_html( atlas_theme_text( 'View all experiences', 'همه تجربه‌ها' ) ); ?> ↗</a></div>
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

/** Keep the validated language on local navigation links without rewriting external URLs. */
function atlas_theme_navigation_locale( $block_content ) {
    if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) { return $block_content; }
    $processor = new WP_HTML_Tag_Processor( $block_content );
    if ( ! $processor->next_tag( 'a' ) ) { return $block_content; }
    $href = $processor->get_attribute( 'href' );
    if ( ! is_string( $href ) || '' === $href || '#' === $href[0] ) { return $block_content; }
    $parts = wp_parse_url( $href );
    $home = wp_parse_url( home_url( '/' ) );
    if ( false === $parts || false === $home ) { return $block_content; }
    if ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) { return $block_content; }
    if ( isset( $parts['host'] ) && ( ! isset( $home['host'] ) || strtolower( $parts['host'] ) !== strtolower( $home['host'] ) ) ) { return $block_content; }
    if ( ! isset( $parts['host'] ) && '/' !== $href[0] && '?' !== $href[0] ) { return $block_content; }
    $processor->set_attribute( 'href', add_query_arg( 'lang', atlas_theme_requested_language(), $href ) );
    return $processor->get_updated_html();
}
add_filter( 'render_block_core/navigation-link', 'atlas_theme_navigation_locale' );

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
        $items = array(
            array( array(), 'Explore', 'کاوش', '#atlas-discover' ),
            array( array( 'atlas_kind' => 'tour' ), 'Tours', 'تورها', '#atlas-offers' ),
            array( array( 'atlas_kind' => 'event' ), 'Events', 'رویدادها', '#atlas-offers' ),
            array( array( 'atlas_kind' => 'cohort' ), 'Academy', 'آموزشگاه', '#atlas-offers' ),
        );
        $list = '<ul class="atlas-nav-list">';
        foreach ( $items as $item ) {
            $list .= '<li><a href="' . esc_url( atlas_theme_url( $item[0] ) . $item[3] ) . '">' . esc_html( atlas_theme_text( $item[1], $item[2] ) ) . '</a></li>';
        }
        $list .= '</ul>';
    }
    return '<nav class="atlas-main-navigation" aria-label="' . esc_attr( $label ) . '">' . $list . '</nav>'; // WP menu output escapes its fields.
}
add_shortcode( 'atlas_theme_navigation', 'atlas_theme_navigation' );
