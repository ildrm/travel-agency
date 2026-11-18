<?php
if (!defined('ABSPATH')) { exit; }

/** Scoped compatibility repair for the actually tested Woo 11.3 checkout. */
final class Atlas_Checkout_Accessibility {
    public static function contact_help($content) {
        // Woo11.3's email input references this description even when the signed-in
        // branch omits its guest notice. Add meaningful server-rendered guidance
        // outside the React root; do not erase accessibility findings or patch Woo.
        if (!is_user_logged_in() || !defined('WC_VERSION') || WC_VERSION!=='11.3.0'
            || str_contains($content,'id="wc-guest-checkout-notice"')) return $content;
        $fa=(($_GET['lang']??'')==='fa' || str_starts_with(determine_locale(),'fa'));
        $text=$fa?'نشانی ایمیلی را که برای این سفارش استفاده می‌کنید بررسی کنید.':'Check the email address you want to use for this order.';
        return '<p id="wc-guest-checkout-notice" class="atlas-checkout-contact-help">'.esc_html($text).'</p>'.$content;
    }
}
add_filter('render_block_woocommerce/checkout',array('Atlas_Checkout_Accessibility','contact_help'),20,1);
