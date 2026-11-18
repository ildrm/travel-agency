<?php
/** Local runtime guard, not part of a distributed product plugin. */
if (!defined('ABSPATH')) { exit; }
add_filter('pre_wp_mail', static function ($preempt) {
    if (wp_get_environment_type() === 'local' && defined('ATLAS_DEMO_MODE') && ATLAS_DEMO_MODE === true) {
        // Fail honestly instead of sending simulated orders or password emails.
        return false;
    }
    return $preempt;
}, PHP_INT_MAX);
