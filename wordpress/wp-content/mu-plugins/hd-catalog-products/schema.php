<?php
/**
 * HD Catalog Products — schema (dbDelta versioned).
 */

if (!defined('ABSPATH')) {
    exit;
}

function hd_catalog_products_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'hd_catalog_products';
}

function hd_catalog_products_maybe_upgrade(): void
{
    $current = (string) get_option(HD_CATALOG_PRODUCTS_OPTION_DB, '');
    if ($current === HD_CATALOG_PRODUCTS_DB_VERSION) {
        return;
    }
    hd_catalog_products_install_schema();
    update_option(HD_CATALOG_PRODUCTS_OPTION_DB, HD_CATALOG_PRODUCTS_DB_VERSION, false);
}

function hd_catalog_products_install_schema(): void
{
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = hd_catalog_products_table();
    $charset = $wpdb->get_charset_collate();

    dbDelta("CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        espo_id VARCHAR(32) NOT NULL,
        sku VARCHAR(32) NOT NULL DEFAULT '',
        name VARCHAR(190) NOT NULL DEFAULT '',
        prices_json LONGTEXT NULL,
        image_attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        boutique_page_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        white_page_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        form_key VARCHAR(64) NOT NULL DEFAULT '',
        project_id VARCHAR(32) NOT NULL DEFAULT '',
        lp_page_ids LONGTEXT NULL,
        merci_page_ids LONGTEXT NULL,
        parent_espo_id VARCHAR(32) NOT NULL DEFAULT '',
        primary_product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        notes TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY espo_id (espo_id),
        KEY sku (sku),
        KEY form_key (form_key),
        KEY status (status),
        KEY parent_espo_id (parent_espo_id),
        KEY primary_product_id (primary_product_id)
    ) {$charset};");
}
