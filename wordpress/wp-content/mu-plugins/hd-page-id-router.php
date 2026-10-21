<?php
/**
 * Plugin Name: HD Page ID Router
 * Description: Maps healthdesire/biomax page_id query to thebigone pages (same ?page_id= URLs).
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Canonical / page_link / shortlink for mapped LPs.
 *
 * false (défaut) = URL publique = ID WP actuel (celui des ads / admin).
 * true          = ancien comportement : réécrire vers hd_source_page_id.
 *
 * Revenir en arrière SANS 301, SANS toucher au cloak :
 *   dans wp-config.php (au-dessus de « stop editing ») :
 *   define('HD_ROUTER_CANONICAL_OLD_SOURCE_ID', true);
 */
if (!defined('HD_ROUTER_CANONICAL_OLD_SOURCE_ID')) {
    define('HD_ROUTER_CANONICAL_OLD_SOURCE_ID', false);
}

/**
 * Public ?page_id= for a mapped page, or null if not mapped (keep WP permalink).
 */
function hd_router_ads_url_for_post(int $postId): ?string
{
    if ($postId <= 0) {
        return null;
    }
    $src = (int) get_post_meta($postId, 'hd_source_page_id', true);
    if ($src <= 0) {
        return null;
    }
    $id = HD_ROUTER_CANONICAL_OLD_SOURCE_ID ? $src : $postId;
    return home_url('/?page_id=' . $id);
}

/**
 * Normalize broken ads URLs like:
 *   /?page_id=15539/?page_id=15579&utm_source=FB
 *   /?page_id=15579/?page_id=15579&utm_...
 * → /?page_id=15539&utm_source=FB  (first digits win; UTM/fbclid kept)
 *
 * Root cause: FB destination URLs concatenated with a second "/?page_id=…".
 */
add_action('init', function () {
    if (is_admin() || (defined('WP_CLI') && WP_CLI) || wp_doing_cron() || wp_doing_ajax()) {
        return;
    }
    if (empty($_GET['page_id']) || is_array($_GET['page_id'])) {
        return;
    }
    $raw = wp_unslash((string) $_GET['page_id']);
    if ($raw === '' || ctype_digit($raw)) {
        return;
    }
    if (!preg_match('/^(\d{1,12})/', $raw, $m)) {
        return;
    }
    $cleanId = (int) $m[1];
    if ($cleanId <= 0) {
        return;
    }

    $args = [];
    foreach ($_GET as $k => $v) {
        if (!is_string($k) || $k === '' || is_array($v)) {
            continue;
        }
        $args[$k] = wp_unslash((string) $v);
    }
    $args['page_id'] = (string) $cleanId;

    $target = add_query_arg($args, home_url('/'));
    if ($target === '' || $target === home_url('/')) {
        return;
    }

    // Avoid loop if somehow identical.
    $req = (isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '');
    $targetPath = wp_parse_url($target, PHP_URL_PATH) . '?' . (string) wp_parse_url($target, PHP_URL_QUERY);
    if ($req !== '' && ltrim($req, '/') === ltrim($targetPath, '/')) {
        return;
    }

    nocache_headers();
    wp_safe_redirect($target, 302);
    exit;
}, 0);

/**
 * option hd_source_page_map: [ source_page_id (int) => tb_post_id (int) ]
 */
add_filter('request', function ($qv) {
    $map = get_option('hd_source_page_map', []);
    if (!is_array($map)) {
        $map = [];
    }

    if (isset($_GET['page_id'])) {
        $raw = is_array($_GET['page_id']) ? '' : (string) $_GET['page_id'];
        if ($raw !== '' && preg_match('/^(\d{1,12})/', $raw, $m)) {
            $src = (int) $m[1];
        } else {
            $src = (int) $_GET['page_id'];
        }
        if ($src > 0 && !empty($map[$src])) {
            $tbId = (int) $map[$src];
            if ($tbId > 0 && get_post_status($tbId) === 'publish') {
                // Force WP to load our mapped page, ignoring local ID collision.
                $qv['page_id'] = $tbId;
                unset($qv['pagename'], $qv['name'], $qv['error'], $qv['p']);
                return $qv;
            }
        }
    }

    // ?p=ID on a PAGE (not a blog post) — load as page without canonical hop to ?page_id=SOURCE.
    if (isset($_GET['p']) && !is_array($_GET['p']) && empty($_GET['page_id'])) {
        $pid = (int) wp_unslash((string) $_GET['p']);
        if ($pid > 0 && get_post_type($pid) === 'page' && get_post_status($pid) === 'publish') {
            $qv['page_id'] = $pid;
            unset($qv['p'], $qv['name'], $qv['pagename'], $qv['error']);
            return $qv;
        }
    }

    return $qv;
}, 1);

/** Canonical / permalinks: ID WP actuel (réversible via HD_ROUTER_CANONICAL_OLD_SOURCE_ID). Pas de 301. */
add_filter('get_canonical_url', function ($url, $post) {
    if (!$post || $post->post_type !== 'page') {
        return $url;
    }
    $ads = hd_router_ads_url_for_post((int) $post->ID);
    return $ads !== null ? $ads : $url;
}, 10, 2);

add_filter('page_link', function ($link, $postId) {
    $ads = hd_router_ads_url_for_post((int) $postId);
    return $ads !== null ? $ads : $link;
}, 10, 2);

add_filter('pre_get_shortlink', function ($shortlink, $id) {
    $ads = hd_router_ads_url_for_post((int) $id);
    return $ads !== null ? $ads : $shortlink;
}, 10, 2);

/**
 * Cancel redirect_canonical bounce between mapped IDs (TB id / ?p= ↔ source ?page_id=).
 * Same LP content — a 301 makes mobile browsers flash "Redirecting you to…" even with empty body.
 * No HTTP hop between SOURCE id and current WP id (in-app FB). Alias only.
 */
add_filter('redirect_canonical', static function ($redirect_url, $requested_url) {
    if ($redirect_url === false || $redirect_url === null || $redirect_url === '') {
        return $redirect_url;
    }

    $reqId = 0;
    if (isset($_GET['page_id']) && !is_array($_GET['page_id'])) {
        $raw = wp_unslash((string) $_GET['page_id']);
        if (preg_match('/^(\d{1,12})/', $raw, $m)) {
            $reqId = (int) $m[1];
        }
    } elseif (isset($_GET['p']) && !is_array($_GET['p'])) {
        $reqId = (int) wp_unslash((string) $_GET['p']);
    }

    if ($reqId <= 0) {
        return $redirect_url;
    }

    $map = get_option('hd_source_page_map', []);
    if (!is_array($map)) {
        $map = [];
    }

    // Already on SOURCE page_id (ads URL) — never bounce.
    if (!empty($map[$reqId])) {
        return false;
    }

    // On TB post id that maps to a SOURCE id — serve without hopping to ?page_id=SOURCE.
    $src = (int) get_post_meta($reqId, 'hd_source_page_id', true);
    if ($src > 0) {
        return false;
    }

    // Redirect only swaps page_id/p between a mapped SOURCE↔TB pair — cancel.
    $redirQ = [];
    $redirParts = wp_parse_url((string) $redirect_url);
    if (!empty($redirParts['query'])) {
        parse_str((string) $redirParts['query'], $redirQ);
    }
    $redirId = 0;
    if (!empty($redirQ['page_id']) && !is_array($redirQ['page_id'])) {
        if (preg_match('/^(\d{1,12})/', (string) $redirQ['page_id'], $m)) {
            $redirId = (int) $m[1];
        }
    } elseif (!empty($redirQ['p']) && !is_array($redirQ['p'])) {
        $redirId = (int) $redirQ['p'];
    }
    if ($redirId > 0) {
        if (!empty($map[$redirId]) && (int) $map[$redirId] === $reqId) {
            return false;
        }
        if (!empty($map[$reqId]) && (int) $map[$reqId] === $redirId) {
            return false;
        }
        $srcReq = (int) get_post_meta($reqId, 'hd_source_page_id', true);
        if ($srcReq > 0 && $srcReq === $redirId) {
            return false;
        }
    }

    return $redirect_url;
}, 10, 2);
