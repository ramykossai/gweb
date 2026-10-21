<?php
/**
 * Plugin Name: HD Meta CAPI (Rockabilly test)
 * Description: Conversions API → pixel ROCKABILLY only. Feature-flagged; does not alter other pixels.
 */
if (!defined('ABSPATH')) {
    exit;
}

$hd_meta_capi = __DIR__ . '/hd-meta-capi/capi.php';
if (is_readable($hd_meta_capi)) {
    require_once $hd_meta_capi;
}
unset($hd_meta_capi);
