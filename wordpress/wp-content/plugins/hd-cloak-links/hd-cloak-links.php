<?php
/**
 * Plugin Name: HD Cloak Links
 * Description: Smart short links /c/{code} + LP direct cloak (Strategy A). Local filters only (no Cloaking.House).
 * Version: 1.4.8
 * Author: HD
 * Text Domain: hd-cloak-links
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HD_CLOAK_VERSION', '1.4.8');
define('HD_CLOAK_FILE', __FILE__);
define('HD_CLOAK_DIR', plugin_dir_path(__FILE__));
define('HD_CLOAK_URL', plugin_dir_url(__FILE__));

require_once HD_CLOAK_DIR . 'includes/class-filter.php';
require_once HD_CLOAK_DIR . 'includes/class-router.php';
require_once HD_CLOAK_DIR . 'includes/class-lp-guard.php';
require_once HD_CLOAK_DIR . 'includes/class-admin.php';

final class HD_Cloak_Links {
    public static function init() {
        add_action('init', [__CLASS__, 'register_cpt']);
        add_action('init', [__CLASS__, 'register_rewrite']);
        add_filter('query_vars', [__CLASS__, 'query_vars']);
        add_action('template_redirect', [__CLASS__, 'maybe_route'], 0);

        HD_Cloak_Lp_Guard::init();

        if (is_admin()) {
            HD_Cloak_Admin::init();
        }
    }

    public static function activate() {
        self::register_cpt();
        self::register_rewrite();
        flush_rewrite_rules();
        if (!get_option('hd_cloak_settings')) {
            update_option('hd_cloak_settings', [
                'allowed_countries' => 'MA,DZ,TN,FR,BE,CA,SN,CI,CM',
                'block_datacenter' => 1,
                'block_bots' => 1,
                'block_asn_cloud' => 1,
                'geolite_country' => HD_CLOAK_DIR . 'data/GeoLite2-Country.mmdb',
                'geolite_asn' => HD_CLOAK_DIR . 'data/GeoLite2-ASN.mmdb',
            ], false);
        }
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public static function register_cpt() {
        register_post_type('hd_cloak_link', [
            'labels' => [
                'name' => 'Smart Links',
                'singular_name' => 'Smart Link',
                'add_new_item' => 'Add Smart Link',
                'edit_item' => 'Edit Smart Link',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => ['title'],
            'capability_type' => 'page',
        ]);
    }

    public static function register_rewrite() {
        add_rewrite_rule('^c/([a-zA-Z0-9_-]+)/?$', 'index.php?hd_cloak_code=$matches[1]', 'top');
    }

    public static function query_vars($vars) {
        $vars[] = 'hd_cloak_code';
        return $vars;
    }

    public static function maybe_route() {
        $code = get_query_var('hd_cloak_code');
        if (!$code && isset($_GET['hd_c'])) {
            $code = sanitize_title(wp_unslash($_GET['hd_c']));
        }
        if (!$code) {
            return;
        }
        // Never cache cloak decisions (LiteSpeed / CDN)
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!defined('LSCACHE_NO_CACHE')) {
            define('LSCACHE_NO_CACHE', true);
        }
        header('X-LiteSpeed-Cache-Control: no-cache');
        HD_Cloak_Router::handle(sanitize_title($code));
    }

    /** @return array<string,mixed> */
    public static function settings() {
        $defaults = [
            'allowed_countries' => 'MA,DZ,TN,FR,BE,CA,SN,CI,CM',
            'block_datacenter' => 1,
            'block_bots' => 1,
            'block_asn_cloud' => 1,
            'geolite_country' => HD_CLOAK_DIR . 'data/GeoLite2-Country.mmdb',
            'geolite_asn' => HD_CLOAK_DIR . 'data/GeoLite2-ASN.mmdb',
        ];
        $s = get_option('hd_cloak_settings', []);
        return is_array($s) ? array_merge($defaults, $s) : $defaults;
    }

    public static function find_by_code($code) {
        $code = sanitize_title($code);
        if ($code === '') {
            return null;
        }
        $q = get_posts([
            'post_type' => 'hd_cloak_link',
            'post_status' => 'publish',
            'numberposts' => 1,
            'meta_key' => 'hd_cloak_code',
            'meta_value' => $code,
        ]);
        return $q[0] ?? null;
    }

    public static function public_url($code) {
        return home_url('/c/' . ltrim($code, '/'));
    }
}

register_activation_hook(__FILE__, ['HD_Cloak_Links', 'activate']);
register_deactivation_hook(__FILE__, ['HD_Cloak_Links', 'deactivate']);
HD_Cloak_Links::init();
