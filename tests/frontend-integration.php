<?php
/**
 * Staging-only smoke checks: wp eval-file tests/frontend-integration.php
 * Requires WordPress, WP-CLI and the Atlas Leisure theme installed. Theme
 * switching is exercised only if another theme and the atlas_offer type exist.
 * It creates and removes a uniquely named draft fixture; never run on production.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( "Run this file with WP-CLI in an isolated staging site.\n" ); }
$atlas_failures = array();
$atlas_skipped = array();
$atlas_check = static function ( $condition, $message ) use ( &$atlas_failures ) {
    if ( $condition ) { WP_CLI::log( 'PASS ' . $message ); }
    else { $atlas_failures[] = $message; WP_CLI::warning( 'FAIL ' . $message ); }
};
$theme = wp_get_theme( 'atlas-leisure' );
$atlas_check( $theme->exists(), 'theme is installed' );
$atlas_check( get_stylesheet() === 'atlas-leisure', 'theme is active for render checks' );
if ( ! function_exists( 'atlas_theme_home' ) ) {
    WP_CLI::error( 'Atlas Leisure theme functions are unavailable; activate it first.' );
}
$theme_dir = get_theme_root() . '/atlas-leisure';
$json = file_get_contents( $theme_dir . '/theme.json' );
$theme_json = json_decode( $json, true );
$atlas_check( is_array( $theme_json ) && JSON_ERROR_NONE === json_last_error(), 'theme.json parses as JSON' );
$atlas_check( isset( $theme_json['settings']['color']['palette'], $theme_json['styles'] ), 'theme.json defines palette and global styles' );
if ( class_exists( 'WP_Theme_JSON_Resolver' ) && method_exists( 'WP_Theme_JSON_Resolver', 'get_theme_data' ) ) {
    $resolved = WP_Theme_JSON_Resolver::get_theme_data();
    $settings = $resolved->get_settings();
    $resolved_palette = $settings['color']['palette']['theme'] ?? array();
    $slugs = array_column( is_array( $resolved_palette ) ? $resolved_palette : array(), 'slug' );
    $atlas_check( in_array( 'brand', $slugs, true ) && in_array( 'ink', $slugs, true ), 'WordPress accepts and resolves theme palette settings' );
} else { $atlas_skipped[] = 'WordPress theme.json resolver unavailable'; }
if ( function_exists( 'get_block_template' ) ) {
    $template = get_block_template( 'atlas-leisure//front-page', 'wp_template' );
    $atlas_check( null !== $template && ! is_wp_error( $template ), 'WordPress resolves front-page block template' );
} else { $atlas_skipped[] = 'WordPress block template API unavailable'; }
if ( class_exists( 'WP_Block_Patterns_Registry' ) ) {
    $patterns = WP_Block_Patterns_Registry::get_instance();
    $atlas_check( $patterns->is_registered( 'atlas-leisure/discovery' ), 'discovery pattern is registered' );
} else { $atlas_skipped[] = 'WordPress block pattern registry unavailable'; }
$previous_get = $_GET;
foreach ( array( 'en' => 'ltr', 'fa' => 'rtl' ) as $language => $direction ) {
    $_GET = array( 'lang' => $language );
    $attributes = apply_filters( 'language_attributes', 'lang="en" dir="ltr"' );
    $atlas_check( false !== strpos( $attributes, 'lang="' . $language . '"' ) && false !== strpos( $attributes, 'dir="' . $direction . '"' ), strtoupper( $language ) . ' document language and direction' );
    $html = atlas_theme_home();
    $expected = 'fa' === $language ? 'برای لحظه‌های' : 'Make room for';
    $atlas_check( false !== strpos( $html, $expected ), strtoupper( $language ) . ' home copy rendered' );
}
if ( ! is_admin() ) {
    $_GET = array( 'lang' => 'fa' );
    $atlas_check( 'fa_IR' === apply_filters( 'determine_locale', 'en_US' ), 'Persian query selects fa_IR for gettext' );
    $_GET = array( 'lang' => 'en' );
    $atlas_check( 'en_US' === apply_filters( 'determine_locale', 'fa_IR' ), 'English query selects en_US for gettext' );
} else { $atlas_skipped[] = 'locale query filters intentionally bypass WordPress admin requests'; }
$_GET = array( 'lang' => 'en' );
$navigation = atlas_theme_navigation( array( 'location' => 'primary' ) );
$atlas_check( false !== strpos( $navigation, '<nav' ) && false !== strpos( $navigation, 'aria-label="' ) && false !== strpos( $navigation, '<ul' ) && 0 === preg_match( '/<ul[^>]*>\s*<ul/i', $navigation ), 'navigation renders a named list without a directly nested list' );
$atlas_check( false === strpos( $navigation, 'wc-block-mini-cart__drawer' ), 'default navigation does not inject the mini-cart drawer' );
$skip = atlas_theme_skip_link();
$atlas_check( false !== strpos( $skip, 'href="#atlas-main-content"' ), 'localized skip link targets the main region' );
$unknown = atlas_theme_companion( 'atlas_not_installed_fixture' );
$atlas_check( false === strpos( $unknown, '[atlas_not_installed_fixture]' ) && false !== strpos( $unknown, 'role="status"' ), 'missing dependency produces a clear status, not raw shortcode' );
$_GET = $previous_get;

$prior_theme = get_stylesheet();
$other_themes = array_diff( array_keys( wp_get_themes() ), array( $prior_theme ) );
if ( ! post_type_exists( 'atlas_offer' ) || ! $other_themes ) {
    $atlas_skipped[] = 'theme switch persistence needs the Atlas core plugin and a second installed theme';
} else {
    $fixture = wp_insert_post( array( 'post_type' => 'atlas_offer', 'post_status' => 'draft', 'post_title' => 'Frontend theme switch test fixture' ), true );
    if ( is_wp_error( $fixture ) || ! $fixture ) {
        $atlas_failures[] = 'create isolated switch fixture';
    } else {
        try {
            update_post_meta( $fixture, '_atlas_theme_test_marker', 'unchanged' );
            switch_theme( reset( $other_themes ) );
            $atlas_check( null !== get_post( $fixture ) && 'unchanged' === get_post_meta( $fixture, '_atlas_theme_test_marker', true ), 'theme switch preserves companion draft record and metadata' );
        } finally {
            switch_theme( $prior_theme );
            wp_delete_post( $fixture, true );
        }
    }
}
foreach ( $atlas_skipped as $reason ) { WP_CLI::log( 'SKIP ' . $reason ); }
WP_CLI::log( 'Checks failed: ' . count( $atlas_failures ) . '; skipped: ' . count( $atlas_skipped ) );
if ( $atlas_failures ) { WP_CLI::error( implode( '; ', $atlas_failures ) ); }
WP_CLI::success( 'Frontend smoke checks completed; skipped checks require follow-up.' );
