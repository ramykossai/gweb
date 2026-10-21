<?php
/**
 * Preflight LP cloak — run before enabling hd_cloak_lp_protect on a page.
 *
 * Usage (SSH):
 *   php wp-content/plugins/hd-cloak-links/bin/preflight-lp-cloak.php 257 burn-fast
 *   php wp-content/plugins/hd-cloak-links/bin/preflight-lp-cloak.php --all-protected
 */
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__, 4);
if (!is_file($root . '/wp-load.php')) {
    $root = dirname(__DIR__, 3);
}
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-content/plugins/hd-cloak-links/includes/class-filter.php';
require_once ABSPATH . 'wp-content/plugins/hd-cloak-links/includes/class-router.php';

function preflight_fail($msg) {
    echo "FAIL: $msg\n";
    return false;
}

function preflight_ok($msg) {
    echo "OK:   $msg\n";
    return true;
}

function check_lp($pageId, $whiteSlug = '') {
    $page = get_post((int) $pageId);
    if (!$page || $page->post_type !== 'page') {
        return preflight_fail("page $pageId introuvable");
    }
    if (get_page_template_slug($pageId) !== 'page-hd-landing.php') {
        return preflight_fail("page {$pageId} n'utilise pas page-hd-landing.php");
    }
    $whiteSlug = sanitize_title($whiteSlug ?: (string) get_post_meta($pageId, 'hd_cloak_lp_white_product', true));
    if ($whiteSlug === '') {
        return preflight_fail("white slug manquant pour page {$pageId}");
    }

    // LP cloak white = boutique WP only (no site.html inline).
    $produit = get_page_by_path('produit-' . $whiteSlug);
    if ($produit && $produit->post_status === 'publish') {
        preflight_ok("boutique produit-$whiteSlug page id={$produit->ID} (white redirect)");
        $tpl = get_page_template_slug($produit->ID);
        if ($tpl === 'page-hd-white.php') {
            preflight_ok("template page-hd-white.php");
        } else {
            echo "WARN: produit-$whiteSlug template=$tpl (attendu page-hd-white.php)\n";
        }
    } else {
        preflight_fail("boutique produit-$whiteSlug absente ou non publiee — obligatoire pour LP cloak");
    }

    $mode = (string) get_post_meta($pageId, 'hd_cloak_lp_mode_white', true);
    if ($mode !== '' && $mode !== 'redirect') {
        echo "WARN: hd_cloak_lp_mode_white=$mode (legacy) — LP cloak sert boutique en 200, ignore inline\n";
    } else {
        preflight_ok("LP white = boutique WP en 200 (pas site.html, pas 302)");
    }

    $meta = [
        'white_product' => $whiteSlug,
        'allowed_countries' => '',
        'mode_white' => 'inline',
    ];
    $_SERVER['HTTP_USER_AGENT'] = 'facebookexternalhit/1.1';
    $_SERVER['REMOTE_ADDR'] = '126.0.0.1';
    $bot = HD_Cloak_Filter::decide($meta);
    if (!empty($bot['allow'])) {
        preflight_fail("facebookexternalhit depuis JP devrait etre bloque");
    } else {
        preflight_ok("bot Meta hors TN bloque (reason={$bot['reason']})");
    }

    $_SERVER['HTTP_USER_AGENT'] = 'facebookexternalhit/1.1';
    $_SERVER['REMOTE_ADDR'] = '197.11.50.10';
    $botTn = HD_Cloak_Filter::decide($meta);
    if (empty($botTn['allow'])) {
        preflight_fail("facebookexternalhit depuis TN doit passer (skip bot TN)");
    } else {
        preflight_ok("bot UA autorise si geo TN (skip bot TN)");
    }

    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 13) Mobile';
    $_SERVER['REMOTE_ADDR'] = '197.11.50.10';
    $tn = HD_Cloak_Filter::decide($meta);
    if (empty($tn['allow'])) {
        preflight_fail("TN mobile devrait passer (reason={$tn['reason']})");
    } else {
        preflight_ok("TN mobile autorise");
    }

    echo "PASS page {$pageId} ({$page->post_name}) white=$whiteSlug\n\n";
    return true;
}

$allProtected = in_array('--all-protected', $argv, true);
if ($allProtected) {
    $q = new WP_Query([
        'post_type' => 'page',
        'post_status' => 'publish',
        'posts_per_page' => 500,
        'meta_key' => 'hd_cloak_lp_protect',
        'meta_value' => '1',
    ]);
    $ok = true;
    foreach ($q->posts as $p) {
        if (!check_lp($p->ID)) {
            $ok = false;
        }
    }
    exit($ok ? 0 : 1);
}

$pageId = isset($argv[1]) ? (int) $argv[1] : 0;
$slug = isset($argv[2]) ? (string) $argv[2] : '';
if ($pageId <= 0) {
    fwrite(STDERR, "Usage: php preflight-lp-cloak.php PAGE_ID [white-slug]\n");
    exit(1);
}
exit(check_lp($pageId, $slug) ? 0 : 1);
