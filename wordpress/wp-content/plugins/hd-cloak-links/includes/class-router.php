<?php
if (!defined('ABSPATH')) {
    exit;
}

class HD_Cloak_Router {
    public static function handle($code) {
        $post = HD_Cloak_Links::find_by_code($code);
        if (!$post) {
            status_header(404);
            nocache_headers();
            wp_die('Link not found', 'Not Found', ['response' => 404]);
        }

        $enabled = get_post_meta($post->ID, 'hd_cloak_enabled', true);
        if ($enabled === '0' || $enabled === 0) {
            status_header(404);
            wp_die('Link disabled', 'Not Found', ['response' => 404]);
        }

        $meta = [
            'offer_page_id' => (int) get_post_meta($post->ID, 'hd_cloak_offer_page_id', true),
            'white_product' => (string) get_post_meta($post->ID, 'hd_cloak_white_product', true),
            'allowed_countries' => (string) get_post_meta($post->ID, 'hd_cloak_allowed_countries', true),
            'mode_offer' => (string) (get_post_meta($post->ID, 'hd_cloak_mode_offer', true) ?: 'redirect'),
            'mode_white' => (string) (get_post_meta($post->ID, 'hd_cloak_mode_white', true) ?: 'redirect'),
        ];

        $decision = HD_Cloak_Filter::decide($meta);
        nocache_headers();

        // Logging only — does not alter allow/block decision.
        self::record($post->ID, $decision);

        if (!empty($decision['allow'])) {
            self::bump($post->ID, 'hd_cloak_hits_ok');
            self::serve_offer($meta, $code);
        } else {
            self::bump($post->ID, 'hd_cloak_hits_filtered');
            self::serve_white($meta);
        }
        exit;
    }

    private static function bump($postId, $key) {
        $n = (int) get_post_meta($postId, $key, true);
        update_post_meta($postId, $key, $n + 1);
    }

    /**
     * Per-reason counter + ring buffer of recent hits (option, autoload=false).
     *
     * @param array{allow?:bool,reason?:string,country?:string} $decision
     */
    private static function record($postId, array $decision) {
        $reason = strtolower(preg_replace('/[^a-z0-9_]/', '', (string) ($decision['reason'] ?? 'unknown')));
        if ($reason === '') {
            $reason = 'unknown';
        }
        self::bump($postId, 'hd_cloak_r_' . $reason);

        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        $rf = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        $entry = [
            't' => time(),
            'p' => (int) $postId,
            'a' => !empty($decision['allow']) ? 1 : 0,
            'r' => $reason,
            'c' => (string) ($decision['country'] ?? ''),
            'ip' => self::mask_ip(HD_Cloak_Filter::client_ip()),
            'ua' => substr($ua, 0, 180),
            'rf' => substr($rf, 0, 120),
        ];

        $log = get_option('hd_cloak_recent_log', []);
        if (!is_array($log)) {
            $log = [];
        }
        array_unshift($log, $entry);
        if (count($log) > 300) {
            $log = array_slice($log, 0, 300);
        }
        update_option('hd_cloak_recent_log', $log, false);
    }

    /** Mask last IPv4 octet (.0). IPv6: zero last hextet. */
    private static function mask_ip($ip) {
        $ip = (string) $ip;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';
            return implode('.', $parts);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = @inet_pton($ip);
            if ($packed === false || strlen($packed) !== 16) {
                return '';
            }
            $packed = substr($packed, 0, 14) . "\0\0";
            $out = @inet_ntop($packed);
            return $out !== false ? $out : '';
        }
        return '';
    }

    /**
     * Forward FB/UTM (and other) query params so attribution is not lost on redirect.
     */
    private static function with_tracking_query($url) {
        if (empty($_GET) || !is_array($_GET)) {
            return $url;
        }
        $allowed = [
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term',
            'utm_content', 'utm_id', 'utm_source_platform',
            'utm_creative_format', 'utm_marketing_tactic',
            'fbclid', 'gclid', 'dclid', 'gbraid', 'wbraid', 'msclkid',
            'ttclid', 'tbclid', 'tb_offer', 'fbc', 'fbp',
        ];
        $args = [];
        foreach ($_GET as $k => $v) {
            $k = (string) $k;
            if ($k === '' || !in_array($k, $allowed, true)) {
                continue;
            }
            if (is_array($v)) {
                continue;
            }
            $args[$k] = wp_unslash($v);
        }
        return $args ? add_query_arg($args, $url) : $url;
    }

    /**
     * Optional zero-I/O bridge to TB Tracker. Enable explicitly in wp-config.php:
     * define('HD_CLOAK_TB_OFFER_BRIDGE', true);
     */
    private static function with_offer_query($url, $offerSlug) {
        if (!defined('HD_CLOAK_TB_OFFER_BRIDGE') || HD_CLOAK_TB_OFFER_BRIDGE !== true) {
            return $url;
        }
        $offerSlug = substr(sanitize_key((string) $offerSlug), 0, 120);
        return $offerSlug !== '' ? add_query_arg('tb_offer', $offerSlug, $url) : $url;
    }

    private static function serve_offer(array $meta, $offerSlug = '') {
        $src = (int) ($meta['offer_page_id'] ?? 0);
        if ($src <= 0) {
            self::serve_white($meta);
            return;
        }

        $mode = $meta['mode_offer'] ?? 'redirect';
        if ($mode === 'inline') {
            self::render_offer_inline($src, $offerSlug);
            return;
        }

        $url = self::with_tracking_query(home_url('/?page_id=' . $src));
        $url = self::with_offer_query($url, $offerSlug);
        wp_redirect($url, 302);
        exit;
    }

    /**
     * Serve the ads LP HTML at the current /c/{code} URL (no redirect).
     */
    private static function render_offer_inline($sourcePageId, $offerSlug = '') {
        $map = get_option('hd_source_page_map', []);
        $tbId = 0;
        if (is_array($map) && !empty($map[$sourcePageId])) {
            $tbId = (int) $map[$sourcePageId];
        }
        if ($tbId <= 0) {
            // Fallback: treat source id as local WP id
            $tbId = $sourcePageId;
        }
        $page = get_post($tbId);
        if (!$page || $page->post_type !== 'page' || $page->post_status !== 'publish') {
            $url = self::with_tracking_query(home_url('/?page_id=' . (int) $sourcePageId));
            wp_redirect(self::with_offer_query($url, $offerSlug), 302);
            exit;
        }

        status_header(200);
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');

        global $wp_query, $post;
        $post = $page;
        $wp_query = new WP_Query([
            'page_id' => $tbId,
            'post_type' => 'page',
            'post_status' => 'publish',
        ]);
        if (!$wp_query->have_posts()) {
            $url = self::with_tracking_query(home_url('/?page_id=' . (int) $sourcePageId));
            wp_redirect(self::with_offer_query($url, $offerSlug), 302);
            exit;
        }

        $tpl = get_page_template_slug($tbId);
        if (!$tpl) {
            $tpl = 'page-hd-landing.php';
        }
        $path = locate_template([$tpl, 'page.php', 'index.php']);
        if (!$path) {
            $url = self::with_tracking_query(home_url('/?page_id=' . (int) $sourcePageId));
            wp_redirect(self::with_offer_query($url, $offerSlug), 302);
            exit;
        }

        // Zero-I/O bridge for TB Tracker on inline offers (no redirect = no QS).
        // Inject tb_offer into request + fire action BEFORE template so cookie/page_view/tb.js work.
        if (defined('HD_CLOAK_TB_OFFER_BRIDGE') && HD_CLOAK_TB_OFFER_BRIDGE === true) {
            $slug = substr(sanitize_key((string) $offerSlug), 0, 120);
            if ($slug !== '') {
                $_GET['tb_offer'] = $slug;
            }
        }
        /**
         * @param int    $tbPageId  Local WP page id being rendered.
         * @param string $offerSlug Smart-link code (e.g. anaxne).
         */
        do_action('hd_cloak_serving_inline_offer', (int) $tbId, (string) $offerSlug);

        include $path;
        exit;
    }

    /**
     * LP cloak white: render WP boutique (/produit-{slug}/) at current URL with HTTP 200.
     * No site.html, no 302 — Meta crawlers and filtered geos see the same compliant page.
     */
    public static function serve_lp_boutique(array $meta) {
        $slug = sanitize_title($meta['white_product'] ?? '');
        if ($slug === '') {
            wp_redirect(self::with_tracking_query(home_url('/')), 302);
            exit;
        }

        $page = get_page_by_path('produit-' . $slug);
        if (!$page || $page->post_status !== 'publish') {
            wp_redirect(self::with_tracking_query(home_url('/produit-' . $slug . '/')), 302);
            exit;
        }

        $tpl = get_page_template_slug($page->ID);
        if ($tpl !== 'page-hd-white.php') {
            $tpl = 'page-hd-white.php';
        }
        $path = locate_template([$tpl, 'page.php']);
        if (!$path) {
            wp_redirect(self::with_tracking_query(get_permalink($page)), 302);
            exit;
        }

        status_header(200);
        nocache_headers();
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('X-HD-Cloak-LP-White: boutique');
            header('X-LiteSpeed-Cache-Control: no-cache');
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!defined('LSCACHE_NO_CACHE')) {
            define('LSCACHE_NO_CACHE', true);
        }
        do_action('litespeed_control_set_nocache', 'hd cloak lp boutique');

        global $wp_query, $post;
        $post = $page;
        setup_postdata($post);
        $wp_query = new WP_Query([
            'page_id' => (int) $page->ID,
            'post_type' => 'page',
            'post_status' => 'publish',
        ]);
        if ($wp_query->have_posts()) {
            $wp_query->the_post();
        }

        include $path;
        exit;
    }

    public static function serve_white(array $meta) {
        $slug = sanitize_title($meta['white_product'] ?? '');
        $mode = $meta['mode_white'] ?? 'redirect';

        // Legacy Smart Links may still request site.html inline — LP cloak never uses this path.
        if ($mode === 'inline' && $slug !== '' && empty($meta['lp_cloak'])) {
            $path = ABSPATH . 'white/' . $slug . '/site.html';
            $cssPath = ABSPATH . 'white/' . $slug . '/css/style.css';
            if (is_file($path) && is_file($cssPath)) {
                status_header(200);
                nocache_headers();
                header('Content-Type: text/html; charset=UTF-8');
                $html = file_get_contents($path);
                if ($html !== false && $html !== '') {
                    echo self::rewrite_white_html($html, $slug, $meta);
                    exit;
                }
            }
        }

        // Prefer WP produit page
        if ($slug !== '') {
            $page = get_page_by_path('produit-' . $slug);
            if ($page && $page->post_status === 'publish') {
                wp_redirect(self::with_tracking_query(get_permalink($page)), 302);
                exit;
            }
            $url = self::with_tracking_query(home_url('/produit-' . $slug . '/'));
            wp_redirect($url, 302);
            exit;
        }

        wp_redirect(self::with_tracking_query(home_url('/')), 302);
        exit;
    }

    /**
     * Inline white HTML was authored with thebigone base URLs — rewrite to this site
     * so CSS/JS/images load from local /white/{slug}/ (avoids TB maintenance / cross-domain).
     *
     * Base = directory with trailing slash (assets resolve correctly). Requires DirectoryIndex
     * site.html under white/{slug}/ so /white/{slug}/ is not 403 (Options -Indexes).
     * Optional meta.anchor_base keeps in-page #links on the LP ads URL (Strategy A).
     *
     * @param array{anchor_base?:string} $meta
     */
    public static function rewrite_white_html($html, $slug, array $meta = []) {
        $slug = sanitize_title((string) $slug);
        $dirBase = trailingslashit(home_url('/white/' . $slug));
        $html = preg_replace(
            '#<base\s+href=["\'][^"\']*["\']\s*/?>#i',
            '<base href="' . esc_attr($dirBase) . '"/>',
            (string) $html,
            1
        );
        $html = str_replace(
            'https://thebigone.online/white/' . $slug . '/',
            $dirBase,
            $html
        );
        $hdHome = trailingslashit(home_url('/'));
        if ($hdHome !== 'https://thebigone.online/') {
            $html = str_replace('https://thebigone.online/', $hdHome, $html);
        }
        $anchorBase = isset($meta['anchor_base']) ? (string) $meta['anchor_base'] : '';
        if ($anchorBase !== '') {
            $anchorBase = esc_url($anchorBase);
            $html = preg_replace(
                '/href=(["\'])#([a-zA-Z0-9_-]+)\1/',
                'href=$1' . $anchorBase . '#$2$1',
                $html
            );
        }
        return $html;
    }
}
