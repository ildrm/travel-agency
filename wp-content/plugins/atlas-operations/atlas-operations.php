<?php
/**
 * Plugin Name: Atlas Operations
 * Description: Scoped travel, partner and support workflows for Atlas.
 * Version: 0.1.0
 * Text Domain: atlas-operations
 * Requires Plugins: atlas-core
 */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/schemas.php';
require_once __DIR__ . '/workflows.php';
require_once __DIR__ . '/checkins.php';
require_once __DIR__ . '/support.php';
require_once __DIR__ . '/partners.php';
require_once __DIR__ . '/booking-queue.php';
require_once __DIR__ . '/reservation-status.php';
require_once __DIR__ . '/finance-ui.php';
register_activation_hook(__FILE__, array('Atlas_Event_Checkins','install'));
require_once __DIR__ . '/operations.php';
add_action('atlas_core_ready', array('Atlas_Operations', 'boot'));
