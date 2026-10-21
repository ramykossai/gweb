<?php
/**
 * Plugin Name: HD Catalog Products
 * Description: Catalogue produits admin (table WP + CRUD) — liens soft vers forms/LP/boutique. Isolé, ne remplace pas HD Leads.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HD_CATALOG_PRODUCTS_VERSION', '1.0.0');
define('HD_CATALOG_PRODUCTS_DB_VERSION', '1.0.0');
define('HD_CATALOG_PRODUCTS_DIR', __DIR__ . '/hd-catalog-products');
define('HD_CATALOG_PRODUCTS_URL', content_url('mu-plugins/hd-catalog-products'));
define('HD_CATALOG_PRODUCTS_OPTION_DB', 'hd_catalog_products_db_version');
define('HD_CATALOG_PRODUCTS_OPTION_SEEDED', 'hd_catalog_products_seeded_v1');

require_once HD_CATALOG_PRODUCTS_DIR . '/schema.php';
require_once HD_CATALOG_PRODUCTS_DIR . '/helpers.php';
require_once HD_CATALOG_PRODUCTS_DIR . '/seed.php';
require_once HD_CATALOG_PRODUCTS_DIR . '/autolink.php';
require_once HD_CATALOG_PRODUCTS_DIR . '/admin.php';

add_action('plugins_loaded', 'hd_catalog_products_bootstrap', 20);

function hd_catalog_products_bootstrap(): void
{
    hd_catalog_products_maybe_upgrade();
    hd_catalog_products_maybe_seed();
}
