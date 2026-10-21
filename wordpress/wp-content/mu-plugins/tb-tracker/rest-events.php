<?php
/**
 * TB Tracker P0 — REST/AJAX event ingest (feature-flagged).
 *
 * Loaded always; handlers no-op unless tracker + relevant JS flags are ON.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** @return list<string> */
function tb_tracker_js_event_allowlist(): array
{
    return [
        'page_view',
        'context',
        'engage',
        'scroll',
        'cta_click',
        'form_start',
        'form_error',
        'attr_update',
        'popup_show',
        'popup_skip',
    ];
}

/**
 * Meta key whitelist + value bounds (max string 120, depth keys).
 *
 * @return array<string, mixed>
 */
function tb_tracker_sanitize_event_meta(string $type, $meta): array
{
    if (!is_array($meta)) {
        return [];
    }

    $allowedByType = [
        'page_view' => [
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
            'fbclid', 'gclid', 'ref_host', 'fbp', 'fbc',
        ],
        'context' => [
            'lang', 'tz', 'vw', 'vh', 'dpr', 'nav_type',
            'ch_brand', 'ch_mobile', 'ch_platform',
            'net', 'dl', 'scheme', 'touch',
            'ref_host', 'ref_path', 'ref_q',
        ],
        'engage' => ['active_ms', 'visible_ms', 'idle_ms', 'hid', 'max_pct'],
        'scroll' => ['max_pct'],
        'cta_click' => ['cta_id'],
        'form_start' => ['form_key'],
        'form_error' => ['form_key', 'err'],
        'attr_update' => ['fbp', 'fbc', 'ttclid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid'],
        'popup_show' => ['trigger', 'form_key', 'scroll'],
        'popup_skip' => ['reason', 'trigger', 'form_key', 'scroll'],
    ];

    $allowed = $allowedByType[$type] ?? [];
    if ($allowed === []) {
        return [];
    }

    $out = [];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $meta)) {
            continue;
        }
        $val = $meta[$key];
        if (is_bool($val)) {
            $out[$key] = $val ? 1 : 0;
            continue;
        }
        if (is_int($val) || is_float($val)) {
            if ($key === 'dpr' || $key === 'dl') {
                $out[$key] = round((float) $val, 2);
            } elseif (in_array($key, ['vw', 'vh', 'active_ms', 'visible_ms', 'idle_ms', 'max_pct', 'hid', 'touch'], true)) {
                $out[$key] = (int) $val;
            } else {
                $out[$key] = is_float($val) ? round((float) $val, 2) : (int) $val;
            }
            continue;
        }
        if (is_string($val)) {
            $val = trim(wp_strip_all_tags($val));
            if ($val === '') {
                continue;
            }
            $out[$key] = substr($val, 0, 120);
        }
    }

    if ($type === 'scroll' && isset($out['max_pct'])) {
        $out['max_pct'] = max(0, min(100, (int) $out['max_pct']));
    }
    if ($type === 'engage' && isset($out['max_pct'])) {
        $out['max_pct'] = max(0, min(100, (int) $out['max_pct']));
    }
    if ($type === 'context' && isset($out['touch'])) {
        $out['touch'] = (int) $out['touch'] ? 1 : 0;
    }

    return $out;
}

function tb_tracker_event_type_allowed_by_flags(string $type): bool
{
    if (!tb_tracker_is_enabled() || !tb_tracker_flag_on(TB_TRACKER_OPT_JS_ENGAGEMENT)) {
        return false;
    }

    $engagementTypes = ['page_view', 'engage', 'scroll', 'cta_click', 'form_start', 'form_error', 'popup_show', 'popup_skip'];
    $contextTypes = ['context', 'attr_update'];

    if (in_array($type, $engagementTypes, true)) {
        return true;
    }
    if (in_array($type, $contextTypes, true)) {
        // Context/attr: require context flag (engagement already required to load JS).
        return tb_tracker_flag_on(TB_TRACKER_OPT_JS_CONTEXT);
    }
    return false;
}

/**
 * Basic rate limit: max N events / click_id / minute.
 */
function tb_tracker_event_rate_ok(string $clickId): bool
{
    $key = 'tb_rl_' . md5($clickId);
    $count = (int) get_transient($key);
    if ($count >= 30) {
        return false;
    }
    set_transient($key, $count + 1, MINUTE_IN_SECONDS);
    return true;
}

/**
 * Soft-bootstrap without WP nonce (LiteSpeed-cached HTML + expired nonce).
 * Caps new click mints per IP / minute — does not invent attribution.
 */
function tb_tracker_ip_ingest_rate_ok(): bool
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '') {
        return false;
    }
    $key = 'tb_ip_rl_' . md5($ip);
    $count = (int) get_transient($key);
    if ($count >= 45) {
        return false;
    }
    set_transient($key, $count + 1, MINUTE_IN_SECONDS);
    return true;
}

/**
 * Prefer beacon meta, else real browser cookies — never invent fbp/fbc.
 *
 * @param array<string, mixed> $meta
 * @return array<string, mixed>
 */
function tb_tracker_meta_with_cookie_fbp_fbc(array $meta): array
{
    if (empty($meta['fbp']) && function_exists('tb_tracker_cookie_fbp')) {
        $fbp = tb_tracker_cookie_fbp();
        if ($fbp !== '') {
            $meta['fbp'] = $fbp;
        }
    } elseif (!empty($meta['fbp']) && is_string($meta['fbp'])) {
        $cand = substr(sanitize_text_field($meta['fbp']), 0, 128);
        $meta['fbp'] = preg_match('/^fb\.\d+\.\d+\.\d+$/', $cand) ? $cand : '';
        if ($meta['fbp'] === '') {
            unset($meta['fbp']);
        }
    }
    if (empty($meta['fbc']) && function_exists('tb_tracker_cookie_fbc')) {
        $fbc = tb_tracker_cookie_fbc();
        if ($fbc !== '') {
            $meta['fbc'] = $fbc;
        }
    } elseif (!empty($meta['fbc']) && is_string($meta['fbc'])) {
        $cand = substr(sanitize_text_field($meta['fbc']), 0, 255);
        $meta['fbc'] = preg_match('/^fb\.\d+\.\d+\./', $cand) ? $cand : '';
        if ($meta['fbc'] === '') {
            unset($meta['fbc']);
        }
    }
    return $meta;
}

/**
 * Merge Client Hints from request headers into context meta (no raw UA).
 *
 * @param array<string, mixed> $meta
 * @return array<string, mixed>
 */
function tb_tracker_enrich_client_hints(array $meta): array
{
    $map = [
        'ch_brand' => 'HTTP_SEC_CH_UA',
        'ch_mobile' => 'HTTP_SEC_CH_UA_MOBILE',
        'ch_platform' => 'HTTP_SEC_CH_UA_PLATFORM',
    ];
    foreach ($map as $k => $header) {
        if (!empty($meta[$k])) {
            continue;
        }
        if (empty($_SERVER[$header])) {
            continue;
        }
        $meta[$k] = substr(sanitize_text_field((string) $_SERVER[$header]), 0, 120);
    }
    return $meta;
}

/**
 * Optionally fill empty attribution columns on clicks (never overwrite first-touch).
 *
 * @param array<string, mixed> $meta
 */
function tb_tracker_maybe_update_attr_columns(string $clickId, array $meta): void
{
    if ($clickId === '' || $meta === []) {
        return;
    }
    global $wpdb;
    $table = tb_tracker_table('clicks');
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT ttclid, fbp, fbc, lang, tz, utm_source, utm_medium, utm_campaign, utm_content, utm_term, fbclid, gclid, ref_host
         FROM {$table} WHERE click_id = %s LIMIT 1",
        $clickId
    ), ARRAY_A);
    if (!$row) {
        return;
    }

    $update = [];
    $formats = [];
    $fields = [
        'ttclid' => 255,
        'fbp' => 128,
        'fbc' => 255,
        'lang' => 16,
        'tz' => 64,
        'utm_source' => 191,
        'utm_medium' => 191,
        'utm_campaign' => 191,
        'utm_content' => 191,
        'utm_term' => 191,
        'fbclid' => 255,
        'gclid' => 255,
        'ref_host' => 120,
    ];
    foreach ($fields as $col => $max) {
        if (empty($meta[$col]) || !is_string($meta[$col])) {
            continue;
        }
        if (!empty($row[$col])) {
            continue;
        }
        $val = substr($meta[$col], 0, $max);
        if ($col === 'fbp' && !preg_match('/^fb\.\d+\.\d+\.\d+$/', $val)) {
            continue;
        }
        if ($col === 'fbc' && !preg_match('/^fb\.\d+\.\d+\./', $val)) {
            continue;
        }
        $update[$col] = $val;
        $formats[] = '%s';
    }
    if ($update === []) {
        return;
    }
    $wpdb->update($table, $update, ['click_id' => $clickId], $formats, ['%s']);
}

/**
 * @param array<string, mixed> $payload
 * @return array{ok:bool,code:int,data:array<string,mixed>}
 */
function tb_tracker_ingest_js_event(array $payload): array
{
    if (!tb_tracker_is_enabled() || !tb_tracker_flag_on(TB_TRACKER_OPT_JS_ENGAGEMENT)) {
        return ['ok' => false, 'code' => 403, 'data' => ['error' => 'disabled']];
    }

    $clickId = tb_tracker_sanitize_click_id($payload['c'] ?? '');
    $type = sanitize_key((string) ($payload['t'] ?? ''));
    $nonce = isset($payload['n']) ? (string) $payload['n'] : '';

    if ($clickId === '' || $type === '') {
        return ['ok' => false, 'code' => 400, 'data' => ['error' => 'bad_request']];
    }

    if (!in_array($type, tb_tracker_js_event_allowlist(), true)) {
        return ['ok' => false, 'code' => 400, 'data' => ['error' => 'bad_type']];
    }

    if (!tb_tracker_event_type_allowed_by_flags($type)) {
        return ['ok' => false, 'code' => 403, 'data' => ['error' => 'flag_off']];
    }

    // Nonce WP (public) OR click_id already exists OR soft-bootstrap page_view/attr_update
    // when LiteSpeed served HTML with an expired localized nonce (cache HIT, no PHP bootstrap).
    $nonceOk = ($nonce !== '' && wp_verify_nonce($nonce, 'tb_event'));
    $exists = false;
    $softBootstrap = false;
    if (!$nonceOk) {
        global $wpdb;
        $exists = (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM ' . tb_tracker_table('clicks') . ' WHERE click_id = %s LIMIT 1',
            $clickId
        ));
        if (!$exists) {
            if (in_array($type, ['page_view', 'attr_update'], true) && tb_tracker_ip_ingest_rate_ok()) {
                $softBootstrap = true;
            } else {
                return ['ok' => false, 'code' => 403, 'data' => ['error' => 'auth']];
            }
        }
    }

    if (!tb_tracker_event_rate_ok($clickId)) {
        return ['ok' => false, 'code' => 429, 'data' => ['error' => 'rate']];
    }

    $path = isset($payload['u']) ? (string) $payload['u'] : '';
    $path = wp_parse_url($path, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = '/';
    }
    $path = substr($path, 0, 500);

    $pageId = isset($payload['p']) ? absint($payload['p']) : 0;
    $meta = tb_tracker_sanitize_event_meta($type, $payload['m'] ?? []);

    if ($type === 'context') {
        $meta = tb_tracker_enrich_client_hints($meta);
        // Persist lang/tz on click if empty (via attr helper keys).
        tb_tracker_maybe_update_attr_columns($clickId, [
            'lang' => isset($meta['lang']) ? (string) $meta['lang'] : '',
            'tz' => isset($meta['tz']) ? (string) $meta['tz'] : '',
        ]);
        if (!empty($meta['ref_host']) && function_exists('tb_tracker_touch_click')) {
            tb_tracker_touch_click($clickId, [
                'ref_host' => (string) $meta['ref_host'],
            ]);
        }
    }

    if ($type === 'attr_update' || $type === 'page_view') {
        $meta = tb_tracker_meta_with_cookie_fbp_fbc($meta);
    }

    if ($type === 'attr_update') {
        tb_tracker_maybe_update_attr_columns($clickId, $meta);
    }

    if ($type === 'page_view' && function_exists('tb_tracker_touch_click')) {
        $uri = (is_ssl() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
        $extra = [
            'first_url' => $uri,
            'landing_url' => $uri,
            'referrer' => isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '',
        ];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid', 'ref_host', 'fbp', 'fbc'] as $k) {
            if (!empty($meta[$k]) && is_string($meta[$k])) {
                $extra[$k] = $meta[$k];
            }
        }
        tb_tracker_touch_click($clickId, $extra);
        tb_tracker_maybe_update_attr_columns($clickId, $meta);
    }

    // Ensure click row exists (last_seen only if present; no clear UA write).
    global $wpdb;
    $table = tb_tracker_table('clicks');
    $has = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE click_id = %s LIMIT 1",
        $clickId
    ));
    if ($has) {
        $wpdb->update(
            $table,
            ['last_seen_at' => current_time('mysql')],
            ['click_id' => $clickId],
            ['%s'],
            ['%s']
        );
    } elseif ($nonceOk || $softBootstrap) {
        // Soft create without clear UA — hashes only.
        $wpdb->insert(
            $table,
            [
                'click_id' => $clickId,
                'ip_hash' => tb_tracker_ip_hash(),
                'ua_hash' => tb_tracker_ua_hash(),
                'user_agent' => null,
                'created_at' => current_time('mysql'),
                'last_seen_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );
        if ($type === 'page_view' && function_exists('tb_tracker_touch_click')) {
            // Re-touch so UTM / fbp columns fill after soft insert race.
            $extra = [];
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid', 'ref_host', 'fbp', 'fbc'] as $k) {
                if (!empty($meta[$k]) && is_string($meta[$k])) {
                    $extra[$k] = $meta[$k];
                }
            }
            if ($extra !== []) {
                tb_tracker_maybe_update_attr_columns($clickId, $extra);
            }
        } elseif ($type === 'attr_update') {
            tb_tracker_maybe_update_attr_columns($clickId, $meta);
        }
    } else {
        return ['ok' => false, 'code' => 404, 'data' => ['error' => 'unknown_click']];
    }

    // Dedup page_view noise (PHP bootstrap + JS beacon). Never drops the click —
    // fbp/utm already applied above. Distinct paths (cloak→LP) still insert.
    if ($type === 'page_view' && tb_tracker_recent_page_view_exists($clickId, $path, $pageId, 60)) {
        return ['ok' => true, 'code' => 204, 'data' => ['deduped' => true]];
    }

    $metaForLog = $meta;
    if ($pageId > 0) {
        $metaForLog['page_id'] = $pageId;
    }
    $metaForLog['url'] = $path;

    $encoded = $meta ? wp_json_encode($meta, JSON_UNESCAPED_UNICODE) : null;
    if (is_string($encoded) && strlen($encoded) > 1800) {
        $encoded = substr($encoded, 0, 1800);
    }

    $wpdb->insert(
        tb_tracker_table('events'),
        [
            'click_id' => $clickId,
            'event_type' => substr($type, 0, 40),
            'page_id' => $pageId > 0 ? $pageId : null,
            'url' => $path,
            'meta_json' => $encoded,
            'created_at' => current_time('mysql'),
        ],
        ['%s', '%s', '%d', '%s', '%s', '%s']
    );

    // Cache HIT: PHP bootstrap may skip — fire ViewContent hook on first JS page_view.
    // Duplicate beacons are filtered above; CAPI also has its own 45 min anti-flood.
    if ($type === 'page_view') {
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $uriFull = (is_ssl() ? 'https://' : 'http://') . $host . $path;
        do_action('tb_tracker_page_view', $clickId, [
            'page_id' => $pageId > 0 ? $pageId : 0,
            'url' => $uriFull,
            'offer_slug' => '',
        ]);
    }

    if ($type === 'attr_update') {
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $uriFull = (is_ssl() ? 'https://' : 'http://') . $host . $path;
        do_action('tb_tracker_attr_update', $clickId, [
            'page_id' => $pageId > 0 ? $pageId : 0,
            'url' => $uriFull,
            'fbp' => (string) ($meta['fbp'] ?? ''),
            'fbc' => (string) ($meta['fbc'] ?? ''),
            'fbclid' => (string) ($meta['fbclid'] ?? ''),
        ]);
    }

    return ['ok' => true, 'code' => 204, 'data' => []];
}

/**
 * True if a page_view for this click + same landing was logged recently.
 */
function tb_tracker_recent_page_view_exists(string $clickId, string $path, int $pageId, int $withinSeconds = 60): bool
{
    global $wpdb;
    $clickId = tb_tracker_sanitize_click_id($clickId);
    if ($clickId === '') {
        return false;
    }
    $withinSeconds = max(5, min(300, $withinSeconds));
    $pathNorm = $path !== '' ? $path : '/';
    $sinceLocal = date('Y-m-d H:i:s', (int) current_time('timestamp') - $withinSeconds);
    $et = tb_tracker_table('events');

    if ($pageId > 0) {
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$et}
             WHERE click_id = %s AND event_type = 'page_view' AND created_at >= %s
               AND (page_id = %d OR url = %s OR url LIKE %s)
             LIMIT 1",
            $clickId,
            $sinceLocal,
            $pageId,
            $pathNorm,
            '%' . $wpdb->esc_like($pathNorm)
        ));
        return (bool) $found;
    }

    $found = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$et}
         WHERE click_id = %s AND event_type = 'page_view' AND created_at >= %s
           AND (url = %s OR url LIKE %s OR RIGHT(url, %d) = %s)
         LIMIT 1",
        $clickId,
        $sinceLocal,
        $pathNorm,
        $wpdb->esc_like($pathNorm) . '%',
        strlen($pathNorm),
        $pathNorm
    ));
    return (bool) $found;
}

function tb_tracker_parse_event_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    if (strlen($raw) > 2400) {
        return ['_too_large' => true];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

add_action('rest_api_init', function () {
    register_rest_route('tb/v1', '/e', [
        'methods' => ['POST', 'OPTIONS'],
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $request) {
            if ($request->get_method() === 'OPTIONS') {
                return new WP_REST_Response(null, 204);
            }

            if (!tb_tracker_is_enabled() || !tb_tracker_flag_on(TB_TRACKER_OPT_JS_ENGAGEMENT)) {
                return new WP_REST_Response(['error' => 'disabled'], 403);
            }

            $data = $request->get_json_params();
            if (!is_array($data) || $data === []) {
                // sendBeacon / text/plain / already-consumed php://input
                $raw = $request->get_body();
                if (is_string($raw) && $raw !== '') {
                    if (strlen($raw) > 2400) {
                        return new WP_REST_Response(['error' => 'too_large'], 413);
                    }
                    $decoded = json_decode($raw, true);
                    $data = is_array($decoded) ? $decoded : [];
                }
            }
            if (!is_array($data) || $data === []) {
                $data = tb_tracker_parse_event_json_body();
            }
            if (!empty($data['_too_large'])) {
                return new WP_REST_Response(['error' => 'too_large'], 413);
            }

            $result = tb_tracker_ingest_js_event(is_array($data) ? $data : []);
            if ($result['ok']) {
                return new WP_REST_Response(null, 204);
            }
            return new WP_REST_Response($result['data'], $result['code']);
        },
    ]);
});

add_action('wp_ajax_tb_event', 'tb_tracker_ajax_event');
add_action('wp_ajax_nopriv_tb_event', 'tb_tracker_ajax_event');

function tb_tracker_ajax_event(): void
{
    if (!tb_tracker_is_enabled() || !tb_tracker_flag_on(TB_TRACKER_OPT_JS_ENGAGEMENT)) {
        status_header(403);
        wp_send_json(['error' => 'disabled'], 403);
    }

    $data = tb_tracker_parse_event_json_body();
    if (!empty($data['_too_large'])) {
        status_header(413);
        wp_send_json(['error' => 'too_large'], 413);
    }
    if ($data === []) {
        // Fallback form-encoded
        $data = [
            'c' => $_POST['c'] ?? '',
            't' => $_POST['t'] ?? '',
            'u' => $_POST['u'] ?? '',
            'p' => $_POST['p'] ?? 0,
            'n' => $_POST['n'] ?? '',
            'm' => isset($_POST['m']) ? json_decode(wp_unslash((string) $_POST['m']), true) : [],
        ];
    }

    $result = tb_tracker_ingest_js_event($data);
    if ($result['ok']) {
        status_header(204);
        exit;
    }
    status_header($result['code']);
    wp_send_json($result['data'], $result['code']);
}
