<?php
/**
 * TB Tracker — WP-Cron for segment recalc (OFF by default).
 */

if (!defined('ABSPATH')) {
    exit;
}

function tb_tracker_segments_recalc_enabled(): bool
{
    return (bool) get_option(TB_TRACKER_OPT_SEGMENTS_RECALC, false);
}

/**
 * Register custom interval once.
 */
add_filter('cron_schedules', static function ($schedules) {
    if (!is_array($schedules)) {
        $schedules = [];
    }
    if (!isset($schedules['tb_segments_30min'])) {
        $schedules['tb_segments_30min'] = [
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display' => 'TB Segments every 30 minutes',
        ];
    }
    return $schedules;
});

/**
 * Keep schedule in sync with flag. Never schedule when OFF.
 */
function tb_tracker_segments_sync_cron(): void
{
    $hook = 'tb_tracker_segments_recalc_cron';
    $enabled = tb_tracker_segments_recalc_enabled();

    if (!$enabled) {
        $ts = wp_next_scheduled($hook);
        while ($ts) {
            wp_unschedule_event($ts, $hook);
            $ts = wp_next_scheduled($hook);
        }
        return;
    }

    if (!wp_next_scheduled($hook)) {
        wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'tb_segments_30min', $hook);
    }
}

add_action('init', static function () {
    // Cheap: only touch cron state in admin or when doing cron.
    if (is_admin() || wp_doing_cron()) {
        tb_tracker_segments_sync_cron();
    }
}, 20);

add_action('tb_tracker_segments_recalc_cron', static function () {
    if (!tb_tracker_segments_recalc_enabled()) {
        return;
    }
    if (function_exists('sys_getloadavg')) {
        $load = @sys_getloadavg();
        if (is_array($load) && (float) $load[0] > 25) {
            return;
        }
    }
    if (!function_exists('tb_tracker_segments_recalc')) {
        return;
    }

    $limit = 150;
    $withinDays = 7;
    $offset = (int) get_option('tb_segments_recalc_cursor', 0);
    if ($offset < 0) {
        $offset = 0;
    }

    $res = tb_tracker_segments_recalc([
        'limit' => $limit,
        'within_days' => $withinDays,
        'dry_run' => false,
        'offset' => $offset,
        'source' => 'cron',
    ]);

    if (empty($res['ok'])) {
        return;
    }

    $batch = isset($res['candidates_batch']) ? (int) $res['candidates_batch'] : (int) ($res['clicks_scanned'] ?? 0);
    // Advance cursor; wrap when batch short (end of window) or huge offset.
    if ($batch < $limit) {
        $next = 0;
    } else {
        $next = $offset + $limit;
        // Safety wrap: avoid unbounded offset growth.
        if ($next > 50000) {
            $next = 0;
        }
    }
    update_option('tb_segments_recalc_cursor', $next, false);
});

/**
 * Clear schedule on deactivation-like flag flip (called from admin save).
 */
function tb_tracker_segments_clear_cron(): void
{
    $hook = 'tb_tracker_segments_recalc_cron';
    $ts = wp_next_scheduled($hook);
    while ($ts) {
        wp_unschedule_event($ts, $hook);
        $ts = wp_next_scheduled($hook);
    }
}
