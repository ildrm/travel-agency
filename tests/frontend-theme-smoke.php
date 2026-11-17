<?php
/** Run inside the WordPress PHPUnit suite with atlas-leisure active. */
class Atlas_Theme_Smoke_Test extends WP_UnitTestCase {
    private $original_get;
    public function set_up(): void {
        parent::set_up();
        $this->original_get = $_GET;
        switch_theme( 'atlas-leisure' );
    }
    public function tear_down(): void {
        $_GET = $this->original_get;
        parent::tear_down();
    }
    public function test_supported_language_and_direction_are_explicit() {
        $_GET['lang'] = 'fa';
        $this->assertSame( 'fa', atlas_theme_requested_language() );
        $this->assertStringContainsString( 'dir="rtl"', atlas_theme_language_attributes( '' ) );
        $this->assertStringContainsString( 'حساب من', atlas_theme_utility_links() );
        $_GET['lang'] = 'en';
        $this->assertSame( 'en', atlas_theme_requested_language() );
        $this->assertStringContainsString( 'dir="ltr"', atlas_theme_language_attributes( '' ) );
    }
    public function test_unknown_companion_never_leaks_shortcode_or_claims_inventory() {
        $output = atlas_theme_companion( 'atlas_unknown_fixture' );
        $this->assertStringNotContainsString( '[atlas_unknown_fixture]', $output );
        $this->assertStringContainsString( 'role="status"', $output );
        $this->assertStringNotContainsString( 'confirmed booking', strtolower( $output ) );
    }
    public function test_home_keeps_fictional_catalog_out_when_companion_unavailable() {
        global $shortcode_tags;
        $saved = $shortcode_tags['atlas_catalog'] ?? null;
        remove_shortcode( 'atlas_catalog' );
        $_GET = array( 'lang' => 'en' );
        $output = atlas_theme_home();
        if ( null !== $saved ) { add_shortcode( 'atlas_catalog', $saved ); }
        $this->assertStringContainsString( 'This service is not available yet', $output );
        $this->assertStringNotContainsString( '[atlas_catalog', $output );
    }
    public function test_search_has_label_and_keeps_chosen_locale() {
        $_GET['lang'] = 'fa';
        $html = atlas_theme_search_form();
        $this->assertStringContainsString( '<label', $html );
        $this->assertStringContainsString( 'name="lang" value="fa"', $html );
        $this->assertStringContainsString( 'جست‌وجو', $html );
    }
}
