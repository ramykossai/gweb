<?php
/**
 * Plugin Name: HD Security Hardening
 * Description: Blocks anonymous WordPress user enumeration through REST.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('rest_authentication_errors', static function ($result) {
    if ($result !== null || is_user_logged_in()) {
        return $result;
    }

    $route = '';
    if (isset($_GET['rest_route'])) {
        $route = wp_unslash((string) $_GET['rest_route']);
    } elseif (isset($GLOBALS['wp']->query_vars['rest_route'])) {
        $route = (string) $GLOBALS['wp']->query_vars['rest_route'];
    } elseif (isset($_SERVER['REQUEST_URI'])) {
        $path = wp_parse_url(wp_unslash((string) $_SERVER['REQUEST_URI']), PHP_URL_PATH);
        if (is_string($path)) {
            $prefix = '/' . rest_get_url_prefix();
            if (strpos($path, $prefix) === 0) {
                $route = substr($path, strlen($prefix));
            }
        }
    }

    if (preg_match('#^/wp/v2/users(?:/|$)#', $route)) {
        return new WP_Error(
            'rest_forbidden',
            'Authentication required.',
            ['status' => 403]
        );
    }

    return $result;
}, 20);
