<?php
/**
 * TB Tracker — segment tables (idempotent dbDelta).
 */

if (!defined('ABSPATH')) {
    exit;
}

function tb_tracker_segments_table(string $which): string
{
    global $wpdb;
    if ($which === 'segments') {
        return $wpdb->prefix . 'tb_segments';
    }
    if ($which === 'members') {
        return $wpdb->prefix . 'tb_audience_members';
    }
    return $wpdb->prefix . 'tb_segments';
}

/**
 * Create / upgrade segment tables. Safe to call repeatedly.
 */
function tb_tracker_segments_install_tables(): void
{
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $segments = tb_tracker_segments_table('segments');
    $members = tb_tracker_segments_table('members');

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql1 = "CREATE TABLE {$segments} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(64) NOT NULL,
        name VARCHAR(180) NOT NULL,
        description TEXT NULL,
        rule_json LONGTEXT NOT NULL,
        priority INT NOT NULL DEFAULT 100,
        active TINYINT(1) NOT NULL DEFAULT 1,
        calc_mode VARCHAR(20) NOT NULL DEFAULT 'batch',
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY slug (slug),
        KEY active_priority (active, priority)
    ) {$charset};";

    $sql2 = "CREATE TABLE {$members} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        click_id VARCHAR(48) NOT NULL,
        segment_id BIGINT UNSIGNED NOT NULL,
        score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        entered_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        expires_at DATETIME NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY click_segment (click_id, segment_id),
        KEY segment_updated (segment_id, updated_at),
        KEY click_id (click_id)
    ) {$charset};";

    dbDelta($sql1);
    dbDelta($sql2);

    if (defined('TB_TRACKER_SEGMENTS_DB_VERSION')) {
        update_option(TB_TRACKER_OPT_SEGMENTS_DBVER, TB_TRACKER_SEGMENTS_DB_VERSION, false);
    }
}

function tb_tracker_segments_tables_ready(): bool
{
    global $wpdb;
    $s = tb_tracker_segments_table('segments');
    $m = tb_tracker_segments_table('members');
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $s)) === $s
        && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $m)) === $m;
}
