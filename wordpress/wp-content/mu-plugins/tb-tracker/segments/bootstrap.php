<?php
/**
 * TB Tracker — Segments bootstrap (P1).
 *
 * Loaded conditionally from the MU plugin. Safe when P0 events schema is absent:
 * rules for scroll/form_start/cta_click are no-op until data exists.
 */

if (!defined('ABSPATH')) {
    exit;
}

$dir = __DIR__;

if (is_readable($dir . '/schema.php')) {
    require_once $dir . '/schema.php';
}
if (is_readable($dir . '/engine.php')) {
    require_once $dir . '/engine.php';
}
if (is_readable($dir . '/cron.php')) {
    require_once $dir . '/cron.php';
}
if (is_admin() && is_readable($dir . '/admin.php')) {
    require_once $dir . '/admin.php';
}

/**
 * Ensure tables exist when an admin with manage_options hits TB Tracker.
 * Idempotent; no front-end cost.
 */
add_action('admin_init', static function () {
    if (!current_user_can('manage_options')) {
        return;
    }
    // Any tracker sub-page, not just the overview: segments now has its own.
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if (strpos($page, 'tb-tracker') !== 0) {
        return;
    }
    if (!function_exists('tb_tracker_segments_install_tables')) {
        return;
    }
    // Only create if missing — avoid dbDelta on every dashboard paint once ready.
    if (!tb_tracker_segments_tables_ready()) {
        tb_tracker_segments_install_tables();
        if (function_exists('tb_tracker_segments_seed_from_config')) {
            tb_tracker_segments_seed_from_config();
        }
    }
}, 30);
