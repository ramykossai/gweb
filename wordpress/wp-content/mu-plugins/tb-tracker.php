<?php
/**
 * Plugin Name: TB Tracker
 * Description: Local click-id attribution + visitor events (feature-flagged). thebigone only.
 * Version: 0.4.11
 * Author: The Big One
 *
 * SAFETY: Front collection gated by tb_tracker_enabled (default off for fresh installs).
 * P0 JS engagement/context gated by tb_js_engagement / tb_js_context (default OFF).
 * When engagement off: no tb.js, zero JS beacon overhead; page_view sync unchanged.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TB_TRACKER_VERSION', '0.4.12');
/** Persistent tbclid: browsers cap ~400d; refreshed on every visit → effectively permanent. */
define('TB_TRACKER_COOKIE_TTL', 400 * DAY_IN_SECONDS);
define('TB_TRACKER_OPT_ENABLED', 'tb_tracker_enabled');
define('TB_TRACKER_OPT_DBVER', 'tb_tracker_db_version');
define('TB_TRACKER_DB_VERSION', '4');
define('TB_TRACKER_OPT_JS_ENGAGEMENT', 'tb_js_engagement');
define('TB_TRACKER_OPT_JS_CONTEXT', 'tb_js_context');
define('TB_TRACKER_OPT_JS_FP', 'tb_js_fp');
define('TB_TRACKER_OPT_BOT_SCORE', 'tb_bot_score');
define('TB_TRACKER_OPT_SAMPLE_RATE', 'tb_tracker_sample_rate');
define('TB_TRACKER_OPT_SEGMENTS_RECALC', 'tb_segments_recalc_enabled');
define('TB_TRACKER_OPT_SEGMENTS_DBVER', 'tb_segments_db_version');
define('TB_TRACKER_SEGMENTS_DB_VERSION', '1');

require_once __DIR__ . '/tb-tracker/admin-ui.php';
require_once __DIR__ . '/tb-tracker/admin-v2.php';
require_once __DIR__ . '/tb-tracker/admin-live.php';
require_once __DIR__ . '/tb-tracker/rest-events.php';
require_once __DIR__ . '/tb-tracker/geo.php';

$tb_segments_boot = __DIR__ . '/tb-tracker/segments/bootstrap.php';
if (is_readable($tb_segments_boot)) {
    require_once $tb_segments_boot;
}
unset($tb_segments_boot);

/**
 * Enabled? (core tracker: cookie + page_view + lead bridge)
 */
function tb_tracker_is_enabled(): bool
{
    return (bool) get_option(TB_TRACKER_OPT_ENABLED, false);
}

/**
 * Feature flag helper — all P0+ flags default OFF.
 */
function tb_tracker_flag_on(string $option): bool
{
    return (bool) get_option($option, false);
}

/**
 * Generate click id: tb_<base36time>_<rand>
 */
function tb_tracker_new_click_id(): string
{
    try {
        $rand = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $rand = substr(md5(uniqid((string) mt_rand(), true)), 0, 8);
    }
    return 'tb_' . base_convert((string) time(), 10, 36) . '_' . $rand;
}

function tb_tracker_sanitize_click_id($raw): string
{
    $raw = is_string($raw) ? trim($raw) : '';
    if ($raw === '' || !preg_match('/^tb_[a-z0-9]+_[a-f0-9]{6,16}$/i', $raw)) {
        return '';
    }
    return substr($raw, 0, 48);
}

function tb_tracker_sanitize_offer_slug($raw): string
{
    $raw = is_string($raw) ? wp_unslash($raw) : '';
    return substr(sanitize_key($raw), 0, 120);
}

function tb_tracker_table(string $which): string
{
    global $wpdb;
    if ($which === 'clicks') {
        return $wpdb->prefix . 'tb_clicks';
    }
    if ($which === 'events') {
        return $wpdb->prefix . 'tb_events';
    }
    return $wpdb->prefix . 'tb_clicks';
}

/**
 * Skip page_view (and heavy touch) for technical / non-HTML paths.
 * Does not touch cloak routes.
 */
function tb_tracker_is_technical_request(): bool
{
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return true;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = wp_parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return false;
    }
    $path = strtolower($path);
    if (preg_match('#/(meta\.json|robots\.txt|favicon\.ico|xmlrpc\.php)(/|$)#', $path)) {
        return true;
    }
    if (strpos($path, '/wp-json/') !== false || substr($path, -8) === '/wp-json') {
        return true;
    }
    if (preg_match('#\.(json|map|xml|txt|ico|png|jpe?g|gif|webp|svg|css|js|woff2?|ttf|eot)$#', $path)) {
        return true;
    }
    return false;
}

function tb_tracker_install_tables(): void
{
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $clicks = tb_tracker_table('clicks');
    $events = tb_tracker_table('events');

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql1 = "CREATE TABLE {$clicks} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        click_id VARCHAR(48) NOT NULL,
        first_url TEXT NULL,
        landing_url TEXT NULL,
        referrer TEXT NULL,
        utm_source VARCHAR(120) NULL,
        utm_medium VARCHAR(120) NULL,
        utm_campaign VARCHAR(180) NULL,
        utm_content VARCHAR(180) NULL,
        utm_term VARCHAR(180) NULL,
        fbclid VARCHAR(255) NULL,
        gclid VARCHAR(255) NULL,
        ttclid VARCHAR(255) NULL,
        fbp VARCHAR(128) NULL,
        fbc VARCHAR(255) NULL,
        lang VARCHAR(16) NULL,
        tz VARCHAR(64) NULL,
        offer_slug VARCHAR(120) NULL,
        ip_hash CHAR(64) NULL,
        ua_hash CHAR(64) NULL,
        user_agent VARCHAR(255) NULL,
        geo_country VARCHAR(80) NULL,
        geo_region VARCHAR(80) NULL,
        geo_city VARCHAR(80) NULL,
        geo_tz VARCHAR(64) NULL,
        geo_iso VARCHAR(8) NULL,
        product_hint VARCHAR(120) NULL,
        ref_host VARCHAR(120) NULL,
        created_at DATETIME NOT NULL,
        last_seen_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY click_id (click_id),
        KEY created_at (created_at),
        KEY offer_slug (offer_slug),
        KEY geo_iso (geo_iso),
        KEY product_hint (product_hint),
        KEY ref_host (ref_host)
    ) {$charset};";

    $sql2 = "CREATE TABLE {$events} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        click_id VARCHAR(48) NOT NULL,
        event_type VARCHAR(40) NOT NULL,
        page_id BIGINT UNSIGNED NULL,
        url TEXT NULL,
        meta_json LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY click_id (click_id),
        KEY event_type (event_type),
        KEY created_at (created_at),
        KEY click_type_time (click_id, event_type, created_at),
        KEY type_time (event_type, created_at)
    ) {$charset};";

    dbDelta($sql1);
    dbDelta($sql2);
    update_option(TB_TRACKER_OPT_DBVER, TB_TRACKER_DB_VERSION, false);
}

/**
 * Safe schema bump when tracker is enabled (dbDelta only).
 */
function tb_tracker_maybe_upgrade(): void
{
    if (!tb_tracker_is_enabled()) {
        return;
    }
    $ver = (string) get_option(TB_TRACKER_OPT_DBVER, '0');
    if (version_compare($ver, TB_TRACKER_DB_VERSION, '<')) {
        tb_tracker_install_tables();
    }
}
add_action('init', 'tb_tracker_maybe_upgrade', 1);

function tb_tracker_ip_hash(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $salt = defined('AUTH_SALT') ? AUTH_SALT : 'tb';
    return hash('sha256', $salt . '|' . $ip);
}

function tb_tracker_ua_hash(): string
{
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $salt = defined('AUTH_SALT') ? AUTH_SALT : 'tb';
    return hash('sha256', $salt . '|' . $ua);
}

/**
 * Persist tbclid cookie (long-lived + sliding refresh on each call).
 * HttpOnly false so JS forms / tb.js can read it.
 */
function tb_tracker_set_click_cookie(string $id): void
{
    $id = tb_tracker_sanitize_click_id($id);
    if ($id === '') {
        return;
    }
    $_COOKIE['tbclid'] = $id;
    if (headers_sent()) {
        return;
    }
    setcookie('tbclid', $id, [
        'expires' => time() + (int) TB_TRACKER_COOKIE_TTL,
        'path' => '/',
        'secure' => is_ssl(),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

/**
 * Resolve or create click id (cookie + request). Lightweight.
 */
function tb_tracker_resolve_click_id(bool $create = true): string
{
    $fromGet = tb_tracker_sanitize_click_id($_GET['tbclid'] ?? '');
    $fromCookie = tb_tracker_sanitize_click_id($_COOKIE['tbclid'] ?? '');
    $id = $fromGet !== '' ? $fromGet : $fromCookie;

    if ($id === '' && $create) {
        $id = tb_tracker_new_click_id();
    }
    if ($id === '') {
        return '';
    }

    tb_tracker_set_click_cookie($id);
    return $id;
}

/**
 * Read real Meta _fbp cookie only (never invent).
 */
function tb_tracker_cookie_fbp(): string
{
    if (empty($_COOKIE['_fbp'])) {
        return '';
    }
    $v = substr(sanitize_text_field(wp_unslash((string) $_COOKIE['_fbp'])), 0, 128);
    // Meta format: fb.{version}.{creation_time}.{random}
    if ($v === '' || !preg_match('/^fb\.\d+\.\d+\.\d+$/', $v)) {
        return '';
    }
    return $v;
}

/**
 * Read real Meta _fbc cookie only (never invent).
 */
function tb_tracker_cookie_fbc(): string
{
    if (empty($_COOKIE['_fbc'])) {
        return '';
    }
    $v = substr(sanitize_text_field(wp_unslash((string) $_COOKIE['_fbc'])), 0, 255);
    if ($v === '' || !preg_match('/^fb\.\d+\.\d+\./', $v)) {
        return '';
    }
    return $v;
}

/**
 * Upsert click row (INSERT IGNORE / update last_seen). Keep cheap.
 */
function tb_tracker_touch_click(string $clickId, array $extra = []): void
{
    if ($clickId === '' || !tb_tracker_is_enabled()) {
        return;
    }
    global $wpdb;
    $table = tb_tracker_table('clicks');
    $now = current_time('mysql');
    $offerSlug = tb_tracker_sanitize_offer_slug($extra['offer_slug'] ?? '');
    $productHint = isset($extra['product_hint']) ? substr(sanitize_text_field((string) $extra['product_hint']), 0, 120) : '';
    $refHost = isset($extra['ref_host']) ? substr(sanitize_text_field((string) $extra['ref_host']), 0, 120) : '';
    if ($refHost === '' && !empty($extra['referrer'])) {
        $rh = wp_parse_url((string) $extra['referrer'], PHP_URL_HOST);
        if (is_string($rh) && $rh !== '') {
            $refHost = substr(strtolower($rh), 0, 120);
        }
    }

    $fbp = '';
    if (!empty($extra['fbp']) && is_string($extra['fbp'])) {
        $cand = substr(sanitize_text_field($extra['fbp']), 0, 128);
        if (preg_match('/^fb\.\d+\.\d+\.\d+$/', $cand)) {
            $fbp = $cand;
        }
    }
    if ($fbp === '') {
        $fbp = tb_tracker_cookie_fbp();
    }
    $fbc = '';
    if (!empty($extra['fbc']) && is_string($extra['fbc'])) {
        $cand = substr(sanitize_text_field($extra['fbc']), 0, 255);
        if (preg_match('/^fb\.\d+\.\d+\./', $cand)) {
            $fbc = $cand;
        }
    }
    if ($fbc === '') {
        $fbc = tb_tracker_cookie_fbc();
    }

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE click_id = %s LIMIT 1",
        $clickId
    ));

    if ($existing) {
        $sets = ['last_seen_at = %s'];
        $args = [$now];
        if ($offerSlug !== '') {
            $sets[] = "offer_slug = CASE WHEN offer_slug IS NULL OR offer_slug = '' THEN %s ELSE offer_slug END";
            $args[] = $offerSlug;
        }
        if ($productHint !== '') {
            $sets[] = "product_hint = CASE WHEN product_hint IS NULL OR product_hint = '' THEN %s ELSE product_hint END";
            $args[] = $productHint;
        }
        if ($refHost !== '') {
            $sets[] = "ref_host = CASE WHEN ref_host IS NULL OR ref_host = '' THEN %s ELSE ref_host END";
            $args[] = $refHost;
        }
        if ($fbp !== '') {
            $sets[] = "fbp = CASE WHEN fbp IS NULL OR fbp = '' THEN %s ELSE fbp END";
            $args[] = $fbp;
        }
        if ($fbc !== '') {
            $sets[] = "fbc = CASE WHEN fbc IS NULL OR fbc = '' THEN %s ELSE fbc END";
            $args[] = $fbc;
        }
        $args[] = $clickId;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET " . implode(', ', $sets) . ' WHERE click_id = %s',
            ...$args
        ));
        return;
    }

    $wpdb->insert(
        $table,
        [
            'click_id' => $clickId,
            'first_url' => isset($extra['first_url']) ? substr((string) $extra['first_url'], 0, 2000) : null,
            'landing_url' => isset($extra['landing_url']) ? substr((string) $extra['landing_url'], 0, 2000) : null,
            'referrer' => isset($extra['referrer']) ? substr((string) $extra['referrer'], 0, 2000) : null,
            'utm_source' => $extra['utm_source'] ?? null,
            'utm_medium' => $extra['utm_medium'] ?? null,
            'utm_campaign' => $extra['utm_campaign'] ?? null,
            'utm_content' => $extra['utm_content'] ?? null,
            'utm_term' => $extra['utm_term'] ?? null,
            'fbclid' => isset($extra['fbclid']) ? substr((string) $extra['fbclid'], 0, 255) : null,
            'gclid' => isset($extra['gclid']) ? substr((string) $extra['gclid'], 0, 255) : null,
            'fbp' => $fbp !== '' ? $fbp : null,
            'fbc' => $fbc !== '' ? $fbc : null,
            'offer_slug' => $offerSlug !== '' ? $offerSlug : null,
            'product_hint' => $productHint !== '' ? $productHint : null,
            'ref_host' => $refHost !== '' ? $refHost : null,
            'ip_hash' => tb_tracker_ip_hash(),
            'ua_hash' => tb_tracker_ua_hash(),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'created_at' => $now,
            'last_seen_at' => $now,
        ],
        [
            '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
        ]
    );

    // First-touch geo (async-ish: short HTTP + IP cache). Never blocks later hits.
    if (function_exists('tb_tracker_enrich_click_geo')) {
        tb_tracker_enrich_click_geo($clickId);
    }
}

function tb_tracker_log_event(string $clickId, string $type, array $meta = []): void
{
    if ($clickId === '' || !tb_tracker_is_enabled()) {
        return;
    }
    $type = sanitize_key($type);
    if ($type === '') {
        return;
    }
    global $wpdb;
    $wpdb->insert(
        tb_tracker_table('events'),
        [
            'click_id' => $clickId,
            'event_type' => substr($type, 0, 40),
            'page_id' => isset($meta['page_id']) ? (int) $meta['page_id'] : null,
            'url' => isset($meta['url']) ? substr((string) $meta['url'], 0, 2000) : null,
            'meta_json' => $meta ? wp_json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            'created_at' => current_time('mysql'),
        ],
        ['%s', '%s', '%d', '%s', '%s', '%s']
    );
}

/* --------------------------------------------------------------------------
 * Front (only if enabled)
 * -------------------------------------------------------------------------- */

add_action('init', function () {
    if (!tb_tracker_is_enabled()) {
        return;
    }
    if (is_admin() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'hd_native_lead') {
        return;
    }
}, 5);

/**
 * Front hit bootstrap: click_id + attribution + page_view.
 * Used by normal pages (template_redirect) AND cloak inline offer
 * (hd_cloak_serving_inline_offer) because cloak exits at priority 0
 * before the normal tracker hook (priority 20).
 */
function tb_tracker_bootstrap_front_hit(?int $pageId = null, string $offerOverride = ''): void
{
    static $done = false;
    if ($done) {
        return;
    }
    if (!tb_tracker_is_enabled() || is_admin()) {
        return;
    }
    if (function_exists('tb_tracker_is_technical_request') && tb_tracker_is_technical_request()) {
        return;
    }

    $clickId = tb_tracker_resolve_click_id(true);
    if ($clickId === '') {
        return;
    }
    $done = true;

    $qs = [];
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid'] as $k) {
        if (!empty($_GET[$k])) {
            $qs[$k] = sanitize_text_field(wp_unslash((string) $_GET[$k]));
        }
    }

    $offerSlug = tb_tracker_sanitize_offer_slug($offerOverride !== '' ? $offerOverride : ($_GET['tb_offer'] ?? ''));
    if ($offerSlug !== '') {
        $qs['offer_slug'] = $offerSlug;
        // Keep QS coherent for any later reader in the same request.
        $_GET['tb_offer'] = $offerSlug;
    }

    $uri = (is_ssl() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
    tb_tracker_touch_click($clickId, array_merge($qs, [
        'first_url' => $uri,
        'landing_url' => $uri,
        'referrer' => isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '',
    ]));

    if (!wp_doing_ajax()) {
        $pid = $pageId !== null ? (int) $pageId : (int) get_queried_object_id();
        $pvArgs = [
            'page_id' => $pid > 0 ? $pid : 0,
            'url' => $uri,
            'offer_slug' => $offerSlug,
        ];
        tb_tracker_log_event($clickId, 'page_view', [
            'page_id' => $pid > 0 ? $pid : null,
            'url' => $uri,
        ]);
        /**
         * After page_view is persisted. Consumers (e.g. Meta CAPI ViewContent) may hook here.
         *
         * @param string               $clickId
         * @param array{page_id:int,url:string,offer_slug:string} $pvArgs
         */
        do_action('tb_tracker_page_view', $clickId, $pvArgs);
    }
}

/**
 * Ads landings must not be served from a shared HTML cache that bakes tbclid.
 * LiteSpeed drops utm/fbclid from the cache key → one visitor's tbclid poisons everyone.
 */
add_action('template_redirect', static function () {
    $adsQs = false;
    foreach (['fbclid', 'gclid', 'utm_campaign', 'utm_source', 'utm_id', 'tbclid', 'eid', 'sku'] as $k) {
        if (!empty($_GET[$k])) {
            $adsQs = true;
            break;
        }
    }
    if (!$adsQs) {
        return;
    }
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    do_action('litespeed_control_set_nocache', 'tb tracker ads qs');
    if (!headers_sent()) {
        header('X-LiteSpeed-Cache-Control: no-cache', false);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', false);
    }
}, 1);

add_action('template_redirect', static function () {
    tb_tracker_bootstrap_front_hit();
}, 20);

/** Cloak inline offer: runs inside /c/{code} before LP template include. */
add_action('hd_cloak_serving_inline_offer', static function ($pageId, $offerSlug = '') {
    tb_tracker_bootstrap_front_hit(
        (int) $pageId,
        is_string($offerSlug) ? $offerSlug : ''
    );
}, 10, 2);

/**
 * Enqueue tb.js only when tracker + engagement flag ON.
 * Stub remains on disk; zero network cost when flag OFF.
 */
add_action('wp_enqueue_scripts', function () {
    if (!tb_tracker_is_enabled() || is_admin()) {
        return;
    }
    if (!tb_tracker_flag_on(TB_TRACKER_OPT_JS_ENGAGEMENT)) {
        return;
    }
    if (function_exists('tb_tracker_is_technical_request') && tb_tracker_is_technical_request()) {
        return;
    }

    // Prefer cookie (set in template_redirect); resolve once if first hit same request.
    $clickId = tb_tracker_sanitize_click_id($_COOKIE['tbclid'] ?? ($_GET['tbclid'] ?? ''));
    if ($clickId === '') {
        $clickId = tb_tracker_resolve_click_id(true);
    }
    if ($clickId === '') {
        return;
    }

    $src = content_url('mu-plugins/tb-tracker/assets/tb.js');
    wp_enqueue_script(
        'tb-tracker',
        $src,
        [],
        TB_TRACKER_VERSION,
        [
            'in_footer' => true,
            'strategy' => 'defer',
        ]
    );

    $sample = (int) get_option(TB_TRACKER_OPT_SAMPLE_RATE, 100);
    if ($sample < 1) {
        $sample = 1;
    }
    if ($sample > 100) {
        $sample = 100;
    }

    // Never bake a concrete clickId into HTML — LiteSpeed may cache the page and
    // recycle one tbclid across all visitors. Client resolves cookie / generates.
    wp_localize_script('tb-tracker', 'tbTrackerCfg', [
        'clickId' => '',
        'endpoint' => esc_url_raw(rest_url('tb/v1/e')),
        'ajaxUrl' => esc_url_raw(admin_url('admin-ajax.php?action=tb_event')),
        'nonce' => wp_create_nonce('tb_event'),
        'pageId' => (int) get_queried_object_id(),
        'sampleRate' => $sample,
        'cookieTtl' => (int) TB_TRACKER_COOKIE_TTL,
        'flags' => [
            'engagement' => true,
            'context' => tb_tracker_flag_on(TB_TRACKER_OPT_JS_CONTEXT),
        ],
    ]);
}, 50);

add_action('wp_footer', function () {
    if (!tb_tracker_is_enabled() || is_admin()) {
        return;
    }
    if (!tb_tracker_flag_on(TB_TRACKER_OPT_JS_ENGAGEMENT)) {
        return;
    }
    if (function_exists('tb_tracker_is_technical_request') && tb_tracker_is_technical_request()) {
        return;
    }
    // Client-only: read existing cookie or mint id — never embed a server id (cache-safe).
    $maxAge = (int) TB_TRACKER_COOKIE_TTL;
    echo "<script>(function(){try{"
        . "function g(n){var m=document.cookie.match(new RegExp('(?:^|; )'+n+'=([^;]*)'));return m?decodeURIComponent(m[1]):'';}"
        . "function mint(){return 'tb_'+Date.now().toString(36)+'_'+Math.random().toString(36).slice(2,10);}"
        . "var id=g('tbclid');"
        . "if(!id){try{id=localStorage.getItem('tbclid')||'';}catch(e){}}"
        . "if(!id){id=mint();}"
        . "var parts=['tbclid='+encodeURIComponent(id),'path=/','max-age={$maxAge}','SameSite=Lax'];"
        . "if(location.protocol==='https:')parts.push('Secure');"
        . "document.cookie=parts.join(';');"
        . "try{localStorage.setItem('tbclid',id);}catch(e){}"
        . "document.querySelectorAll('.hd-lead-form [name=\"tbclid\"]').forEach(function(el){el.value=id;});"
        . "}catch(e){}})();</script>\n";
}, 99);

/* --------------------------------------------------------------------------
 * Lead bridge filters (works with hd-native-leads hooks)
 * -------------------------------------------------------------------------- */

add_filter('hd_native_lead_meta', function ($meta, $form, $ctx) {
    if (!is_array($meta)) {
        $meta = [];
    }
    // Draft auto-promote already has the visitor tbclid. Never replace it with
    // an empty/cron/admin cookie (that minted fake WordPress+BR clicks).
    if ((string) ($meta['via'] ?? '') === 'draft_auto' || !empty($meta['draft_promoted'])) {
        return $meta;
    }
    $meta['tbclid'] = tb_tracker_sanitize_click_id($_POST['tbclid'] ?? ($_COOKIE['tbclid'] ?? ''));
    return $meta;
}, 10, 3);

add_filter('hd_native_lead_payload', function ($payload, $form, $ctx) {
    if (!is_array($payload)) {
        $payload = [];
    }
    if (function_exists('wp_doing_cron') && wp_doing_cron()) {
        return $payload;
    }
    $tbclid = tb_tracker_sanitize_click_id($_POST['tbclid'] ?? ($_COOKIE['tbclid'] ?? ''));
    $payload['tbclid'] = $tbclid;
    $payload['hidden_7'] = $tbclid;
    return $payload;
}, 10, 3);

add_action('hd_native_lead_saved', function ($leadId, $form, $ctx, $meta) {
    if (!tb_tracker_is_enabled()) {
        return;
    }
    if (!is_array($meta)) {
        $meta = [];
    }
    $tbclid = tb_tracker_sanitize_click_id($meta['tbclid'] ?? ($_POST['tbclid'] ?? ''));
    $serverSide = (function_exists('wp_doing_cron') && wp_doing_cron())
        || (string) ($meta['via'] ?? '') === 'draft_auto'
        || !empty($meta['draft_promoted']);

    if ($tbclid === '') {
        if ($serverSide) {
            return;
        }
        $tbclid = tb_tracker_resolve_click_id(true);
    } elseif (!$serverSide) {
        tb_tracker_set_click_cookie($tbclid);
        tb_tracker_touch_click($tbclid, [
            'landing_url' => $ctx['current_url'] ?? '',
            'utm_source' => $meta['utm_source'] ?? '',
            'utm_medium' => $meta['utm_medium'] ?? '',
            'utm_campaign' => $meta['utm_campaign'] ?? '',
            'fbclid' => $meta['fbclid'] ?? '',
            'product_hint' => $meta['product'] ?? '',
        ]);
    }
    if ($tbclid === '') {
        return;
    }
    tb_tracker_log_event($tbclid, 'lead', [
        'page_id' => isset($ctx['page_id']) ? (int) $ctx['page_id'] : 0,
        'url' => $ctx['current_url'] ?? '',
        'lead_id' => (int) $leadId,
        'form_key' => $form['key'] ?? '',
        'product' => (string) ($meta['product'] ?? ''),
        'project_id' => (string) ($form['project_id'] ?? ($meta['project_id'] ?? '')),
    ]);
}, 10, 4);
