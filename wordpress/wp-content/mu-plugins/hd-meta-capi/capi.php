<?php
/**
 * Meta Conversions API — ROCKABILLY ROCK (444584882726368) only.
 * Browser pixels / Lead merci fan-out stay untouched except eventID dedupe + content_ids/name.
 */
if (!defined('ABSPATH')) {
    exit;
}

define('HD_META_CAPI_PIXEL_ID', '444584882726368');
define('HD_META_CAPI_OPTION', 'hd_meta_capi_settings');
define('HD_META_CAPI_API_VERSION', 'v21.0');

/**
 * @return array{enabled:bool,pixel_id:string,access_token:string,test_event_code:string,send_view_content:bool,send_purchase:bool,send_delivered:bool}
 */
function hd_meta_capi_settings(): array
{
    $opt = get_option(HD_META_CAPI_OPTION, []);
    if (!is_array($opt)) {
        $opt = [];
    }
    $pixel = preg_replace('/\D+/', '', (string) ($opt['pixel_id'] ?? HD_META_CAPI_PIXEL_ID)) ?: HD_META_CAPI_PIXEL_ID;
    // Hard allowlist — never send to another pixel from this module.
    if ($pixel !== HD_META_CAPI_PIXEL_ID) {
        $pixel = HD_META_CAPI_PIXEL_ID;
    }
    // Default ON when key absent (phase 3 ViewContent); never invent token/enabled.
    $sendVc = array_key_exists('send_view_content', $opt)
        ? !empty($opt['send_view_content'])
        : true;
    // Funnel Purchase / Delivered — OFF by default until smoke OK (dedicated flags, not Lead campaigns).
    $sendPurchase = array_key_exists('send_purchase', $opt)
        ? !empty($opt['send_purchase'])
        : false;
    $sendDelivered = array_key_exists('send_delivered', $opt)
        ? !empty($opt['send_delivered'])
        : false;
    return [
        'enabled' => !empty($opt['enabled']),
        'pixel_id' => $pixel,
        'access_token' => trim((string) ($opt['access_token'] ?? '')),
        'test_event_code' => trim((string) ($opt['test_event_code'] ?? '')),
        'send_view_content' => $sendVc,
        'send_purchase' => $sendPurchase,
        'send_delivered' => $sendDelivered,
    ];
}

/** Feature flag: tracker → CAPI ViewContent (Rockabilly only). */
function hd_meta_capi_send_view_content_enabled(): bool
{
    return hd_meta_capi_is_ready() && !empty(hd_meta_capi_settings()['send_view_content']);
}

/** Feature flag: Espo funnel confirmed → CAPI Purchase. */
function hd_meta_capi_send_purchase_enabled(): bool
{
    return hd_meta_capi_is_ready() && !empty(hd_meta_capi_settings()['send_purchase']);
}

/** Feature flag: Espo funnel delivered → CAPI custom Delivered. */
function hd_meta_capi_send_delivered_enabled(): bool
{
    return hd_meta_capi_is_ready() && !empty(hd_meta_capi_settings()['send_delivered']);
}

function hd_meta_capi_is_ready(): bool
{
    $s = hd_meta_capi_settings();
    return !empty($s['enabled']) && $s['access_token'] !== '' && $s['pixel_id'] === HD_META_CAPI_PIXEL_ID;
}

/** SHA256 hex of normalized string (Meta user_data). */
function hd_meta_capi_hash(string $raw): string
{
    $raw = trim(mb_strtolower($raw, 'UTF-8'));
    $raw = preg_replace('/\s+/u', '', $raw) ?? $raw;
    return hash('sha256', $raw);
}

/** TN phone → E.164 digits then hash. */
function hd_meta_capi_hash_phone(string $phone): string
{
    $d = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($d, '00216')) {
        $d = substr($d, 2);
    }
    if (strlen($d) === 8) {
        $d = '216' . $d;
    } elseif (str_starts_with($d, '0') && strlen($d) === 9) {
        $d = '216' . substr($d, 1);
    }
    return hash('sha256', $d);
}

function hd_meta_capi_event_id_for_lead(int $leadId): string
{
    return 'lead_' . max(0, $leadId);
}

/**
 * Marketing name fallback when catalog row missing (SKU → label).
 *
 * @return array<string,string>
 */
function hd_meta_capi_sku_name_fallback_map(): array
{
    return [
        'YM01' => 'YM01 Maca Stimul Pro',
        'YM02' => 'YM02 clean colon',
        'YM03' => 'YM03 Deep Sleep',
        'YM04' => 'YM04 Full Relax',
        'YM05' => 'YM05 burn fast',
        'YM06' => 'YM06 Prosta Pro',
        'YM07' => 'YM07 Glory Gel',
        'YM08' => 'YM08 Maca Boost Women',
        'YM09' => 'YM09 Hair Revive',
        'YM10' => 'YM10 El Toro',
        'YM11' => 'YM11 Night secret',
        'YM13' => 'YM13 kardiax',
        'YM14' => 'YM14 Collagene',
        'YM14-C' => 'YM14 Collagene CAPSULES',
        'YM14-S' => 'YM14 Collagene SERUM',
        'YM15' => 'YM15 Fire Bowl',
        'YM15-C' => 'YM15 Fire Bowl Capsules',
        'YM15-G' => 'YM15 Fire Bowl Gel',
        'YM16' => 'YM16 24 Carats',
        'YM17' => 'YM17 Varistop',
        'YM18' => 'YM18 Mycose',
        'YM19' => 'YM19 Gel Minceur',
        'YM20' => 'YM20 Gel Cellulite',
        'YM21' => 'YM21 Push Me',
        'YM22' => 'YM22 Mega Mass',
        'YM23' => 'YM23 Virgin',
        'YM24' => 'YM24 Pure Intima',
        'YM25' => 'YM25 Respira',
        'YM26' => 'YM26 Pump Me',
        'YM27' => 'YM27 Acoufin',
        'YM27-G' => 'YM27 Acoufin goutte',
        'YM28' => 'YM28 Stopoux',
        'YM28-2' => 'YM28 "2" Blocpoux',
        'YM29' => 'YM29 THE SHOT',
        'YM29-2' => 'YM29-2 THE SHOT',
        'YM30' => 'YM30 VISIONET',
        'YM31' => 'YM31 ATOPI CARE',
        'YM32' => 'YM32 HEMORRELIEF',
        'YM33' => 'YM33 MAGIC WHITE',
        'YM34' => 'YM34 Brain Up',
        'YM35' => 'YM35 DIABEXA',
        'YM36' => 'YM36 PACK WARRIOR',
        'YM37' => 'YM37 PACK VIRILIZ',
        'YM38' => 'YM38 GROWY',
        'YM39' => 'YM39 MinciMax',
    ];
}

/** Normalize catalog SKU (YM39, YM29-2, YM14-C). */
function hd_meta_capi_normalize_sku(string $sku): string
{
    $sku = strtoupper(trim($sku));
    $sku = preg_replace('/[^A-Z0-9\-]/', '', $sku) ?? '';
    if ($sku === '') {
        return '';
    }
    if (!str_starts_with($sku, 'YM') && preg_match('/^\d/', $sku)) {
        $sku = 'YM' . $sku;
    }
    return $sku;
}

/**
 * Extract YM* SKU from merci / path slug (merci-ym05, ym29-2, …).
 */
function hd_meta_capi_sku_from_slug(string $slug): string
{
    $slug = strtolower(trim($slug));
    if ($slug === '') {
        return '';
    }
    if (preg_match('/(?:^|[^a-z0-9])ym(\d+(?:-[a-z0-9]+)?)/i', $slug, $m)) {
        return hd_meta_capi_normalize_sku('YM' . $m[1]);
    }
    return '';
}

/**
 * Resolve catalog product row from lead meta / form.
 *
 * Priority: form_key (+ project_id variant) → merci/path slug → project_id → existing sku.
 *
 * @param array<string,mixed> $meta
 * @param array<string,mixed>|null $form
 * @return object|null
 */
function hd_meta_capi_lookup_catalog_product(array $meta, $form = null)
{
    if (!function_exists('hd_catalog_products_get_by_form_key')) {
        return null;
    }
    $form = is_array($form) ? $form : [];
    $formKey = sanitize_key((string) ($form['key'] ?? ($meta['form_key'] ?? '')));
    $projectId = sanitize_text_field((string) ($form['project_id'] ?? ($meta['project_id'] ?? '')));

    if ($formKey !== '') {
        $byForm = hd_catalog_products_get_by_form_key($formKey, $projectId);
        if ($byForm) {
            return $byForm;
        }
    }

    $slugCandidates = [
        (string) ($form['merci_slug'] ?? ''),
        (string) ($meta['merci_slug'] ?? ''),
    ];
    $ref = (string) ($meta['wp_http_referer'] ?? ($meta['payload']['_wp_http_referer'] ?? ''));
    if ($ref !== '') {
        $path = (string) (wp_parse_url($ref, PHP_URL_PATH) ?: $ref);
        $slugCandidates[] = trim(basename($path), '/');
        $slugCandidates[] = $path;
    }
    foreach ($slugCandidates as $slug) {
        $sku = hd_meta_capi_sku_from_slug($slug);
        if ($sku !== '' && function_exists('hd_catalog_products_get_by_sku')) {
            $bySku = hd_catalog_products_get_by_sku($sku);
            if ($bySku) {
                return $bySku;
            }
        }
    }

    if ($projectId !== '' && function_exists('hd_catalog_products_get_by_project_id')) {
        $byPid = hd_catalog_products_get_by_project_id($projectId);
        if ($byPid) {
            return $byPid;
        }
    }

    $existingSku = hd_meta_capi_normalize_sku((string) ($meta['product_sku'] ?? ''));
    if ($existingSku !== '' && function_exists('hd_catalog_products_get_by_sku')) {
        return hd_catalog_products_get_by_sku($existingSku);
    }

    return null;
}

/**
 * Resolve content_ids SKU + marketing content_name for Meta Lead.
 *
 * @param array<string,mixed> $meta
 * @param array<string,mixed>|null $form
 * @return array{sku:string,name:string,catalog_product_id:int}
 */
function hd_meta_capi_resolve_product_content(array $meta, $form = null): array
{
    $sku = hd_meta_capi_normalize_sku((string) ($meta['product_sku'] ?? ''));
    $name = trim((string) ($meta['product'] ?? ''));
    $catalogId = (int) ($meta['catalog_product_id'] ?? 0);

    $product = hd_meta_capi_lookup_catalog_product($meta, $form);
    if ($product) {
        $sku = hd_meta_capi_normalize_sku((string) ($product->sku ?? $sku));
        $catName = trim((string) ($product->name ?? ''));
        if ($catName !== '') {
            $name = $catName;
        }
        $catalogId = (int) ($product->id ?? $catalogId);
    }

    if ($sku === '') {
        $form = is_array($form) ? $form : [];
        foreach ([
            (string) ($form['merci_slug'] ?? ''),
            (string) ($meta['merci_slug'] ?? ''),
            (string) ($meta['wp_http_referer'] ?? ''),
        ] as $slug) {
            $sku = hd_meta_capi_sku_from_slug($slug);
            if ($sku !== '') {
                break;
            }
        }
    }

    if ($name === '' && $sku !== '') {
        $map = hd_meta_capi_sku_name_fallback_map();
        $name = $map[$sku] ?? $sku;
    }
    if ($name === '') {
        $name = trim((string) ($meta['form_key'] ?? ''));
    }

    return [
        'sku' => $sku,
        'name' => substr($name, 0, 120),
        'catalog_product_id' => $catalogId,
    ];
}

/**
 * Capture _fbp / _fbc (+ IP/UA) + product_sku into lead meta at submit.
 *
 * @param array<string,mixed> $meta
 * @param array<string,mixed> $form
 * @param array<string,mixed> $ctx
 * @return array<string,mixed>
 */
function hd_meta_capi_enrich_lead_meta(array $meta, $form = null, $ctx = null): array
{
    if (empty($meta['fbp']) && !empty($_COOKIE['_fbp'])) {
        $meta['fbp'] = substr(sanitize_text_field(wp_unslash((string) $_COOKIE['_fbp'])), 0, 128);
    }
    if (empty($meta['fbc']) && !empty($_COOKIE['_fbc'])) {
        $meta['fbc'] = substr(sanitize_text_field(wp_unslash((string) $_COOKIE['_fbc'])), 0, 255);
    }
    if (empty($meta['client_ip'])) {
        $ip = '';
        if (is_array($ctx) && !empty($ctx['ip'])) {
            $ip = (string) $ctx['ip'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = (string) $_SERVER['REMOTE_ADDR'];
        }
        if ($ip !== '') {
            $meta['client_ip'] = substr(sanitize_text_field($ip), 0, 64);
        }
    }
    // Cron / WP-CLI promote sends User-Agent "WordPress/x" — never use that for CAPI matching.
    $reqUa = '';
    if (!empty($_SERVER['HTTP_USER_AGENT'])) {
        $reqUa = substr(sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_USER_AGENT'])), 0, 512);
        if ($reqUa !== '' && stripos($reqUa, 'WordPress/') === 0) {
            $reqUa = '';
        }
    }
    if (empty($meta['client_ua']) && $reqUa !== '') {
        $meta['client_ua'] = $reqUa;
    } elseif (!empty($meta['client_ua']) && stripos((string) $meta['client_ua'], 'WordPress/') === 0) {
        $meta['client_ua'] = $reqUa;
    }

    $tbclid = trim((string) ($meta['tbclid'] ?? ''));
    if ($tbclid !== '' && function_exists('tb_tracker_table')
        && (empty($meta['fbp']) || empty($meta['fbc']) || empty($meta['client_ua']))) {
        global $wpdb;
        $click = $wpdb->get_row($wpdb->prepare(
            'SELECT fbp, fbc, user_agent FROM ' . tb_tracker_table('clicks') . ' WHERE click_id = %s LIMIT 1',
            $tbclid
        ), ARRAY_A);
        if (is_array($click)) {
            if (empty($meta['fbp']) && !empty($click['fbp'])) {
                $meta['fbp'] = substr(sanitize_text_field((string) $click['fbp']), 0, 128);
            }
            if (empty($meta['fbc']) && !empty($click['fbc'])) {
                $meta['fbc'] = substr(sanitize_text_field((string) $click['fbc']), 0, 255);
            }
            if (empty($meta['client_ua']) && !empty($click['user_agent'])) {
                $meta['client_ua'] = substr(sanitize_text_field((string) $click['user_agent']), 0, 512);
            }
        }
    }

    $resolved = hd_meta_capi_resolve_product_content($meta, is_array($form) ? $form : null);
    if ($resolved['sku'] !== '') {
        $meta['product_sku'] = $resolved['sku'];
    }
    if ($resolved['catalog_product_id'] > 0) {
        $meta['catalog_product_id'] = $resolved['catalog_product_id'];
    }
    // Keep existing product label if already set; otherwise use catalog marketing name.
    if (trim((string) ($meta['product'] ?? '')) === '' && $resolved['name'] !== '') {
        $meta['product'] = $resolved['name'];
    }

    return $meta;
}
add_filter('hd_native_lead_meta', 'hd_meta_capi_enrich_lead_meta', 15, 3);

/**
 * Build CAPI Lead payload for one WP lead.
 *
 * @param array<string,mixed> $leadRow
 * @param array<string,mixed> $meta
 * @return array<string,mixed>|null
 */
function hd_meta_capi_build_lead_event(array $leadRow, array $meta): ?array
{
    $leadId = (int) ($leadRow['id'] ?? 0);
    $phone = trim((string) ($leadRow['phone'] ?? ''));
    if ($leadId <= 0 || $phone === '') {
        return null;
    }

    $name = trim((string) ($leadRow['name'] ?? ''));
    $eventId = hd_meta_capi_event_id_for_lead($leadId);
    $created = (string) ($leadRow['created_at'] ?? '');
    $eventTime = $created !== '' ? (int) strtotime($created) : time();
    if ($eventTime <= 0) {
        $eventTime = time();
    }

    $ip = trim((string) ($meta['client_ip'] ?? ''));
    if ($ip === '' && !empty($meta['payload']['hidden_5'])) {
        $ip = trim((string) $meta['payload']['hidden_5']);
    }
    $ua = trim((string) ($meta['client_ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')));

    $userData = [
        'ph' => [hd_meta_capi_hash_phone($phone)],
    ];
    if ($ip !== '') {
        $userData['client_ip_address'] = substr($ip, 0, 64);
    }
    if ($ua !== '') {
        $userData['client_user_agent'] = substr($ua, 0, 512);
    }

    $fnLen = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if ($fnLen >= 3 && strcasecmp($name, 'DAF') !== 0) {
        $userData['fn'] = [hd_meta_capi_hash($name)];
    }

    $fbp = trim((string) ($meta['fbp'] ?? ''));
    $fbc = trim((string) ($meta['fbc'] ?? ''));
    $tbclid = trim((string) ($meta['tbclid'] ?? ''));
    if (($fbp === '' || $fbc === '') && $tbclid !== '' && function_exists('tb_tracker_table')) {
        global $wpdb;
        $ct = tb_tracker_table('clicks');
        $click = $wpdb->get_row($wpdb->prepare(
            "SELECT fbp, fbc, fbclid FROM {$ct} WHERE click_id = %s LIMIT 1",
            $tbclid
        ), ARRAY_A);
        if (is_array($click)) {
            if ($fbp === '') {
                $fbp = trim((string) ($click['fbp'] ?? ''));
            }
            if ($fbc === '') {
                $fbc = trim((string) ($click['fbc'] ?? ''));
            }
        }
    }
    if ($fbp !== '') {
        $userData['fbp'] = $fbp;
    }
    if ($fbc !== '') {
        $userData['fbc'] = $fbc;
    } else {
        $fbclid = trim((string) ($meta['fbclid'] ?? ''));
        if ($fbclid !== '') {
            $userData['fbc'] = 'fb.1.' . $eventTime . '.' . $fbclid;
        }
    }

    $external = $tbclid !== '' ? $tbclid : ('wp_lead_' . $leadId);
    $userData['external_id'] = [hd_meta_capi_hash($external)];

    $sourceUrl = '';
    if (!empty($meta['payload']['_wp_http_referer'])) {
        $path = (string) $meta['payload']['_wp_http_referer'];
        $sourceUrl = (str_starts_with($path, 'http') ? $path : home_url($path));
    } elseif (!empty($meta['wp_http_referer'])) {
        $path = (string) $meta['wp_http_referer'];
        $sourceUrl = (str_starts_with($path, 'http') ? $path : home_url($path));
    } elseif (!empty($meta['payload']['current_url'])) {
        $sourceUrl = (string) $meta['payload']['current_url'];
    } else {
        $sourceUrl = home_url('/');
    }

    $form = null;
    $formKey = sanitize_key((string) ($leadRow['form_key'] ?? ($meta['form_key'] ?? '')));
    if ($formKey !== '' && function_exists('hd_native_get_form')) {
        $form = hd_native_get_form($formKey);
    }
    if (!is_array($form)) {
        $form = ['key' => $formKey, 'project_id' => (string) ($meta['project_id'] ?? '')];
    }
    $content = hd_meta_capi_resolve_product_content($meta, $form);

    $custom = [];
    if ($content['sku'] !== '') {
        $custom['content_ids'] = [$content['sku']];
        $custom['content_type'] = 'product';
    }
    $contentName = $content['name'] !== '' ? $content['name'] : trim((string) ($meta['product'] ?? $formKey));
    if ($contentName !== '') {
        $custom['content_name'] = substr($contentName, 0, 120);
    }

    $event = [
        'event_name' => 'Lead',
        'event_time' => $eventTime,
        'event_id' => $eventId,
        'event_source_url' => $sourceUrl,
        'action_source' => 'website',
        'user_data' => $userData,
    ];
    if ($custom !== []) {
        $event['custom_data'] = $custom;
    }
    return $event;
}

/**
 * POST events to Meta CAPI.
 *
 * @param list<array<string,mixed>> $events
 * @return array{ok:bool,code:int,body:string}
 */
function hd_meta_capi_post_events(array $events, bool $blocking = true): array
{
    $s = hd_meta_capi_settings();
    if ($events === [] || $s['access_token'] === '') {
        return ['ok' => false, 'code' => 0, 'body' => 'not_ready'];
    }

    $payload = ['data' => array_values($events)];
    if ($s['test_event_code'] !== '') {
        $payload['test_event_code'] = $s['test_event_code'];
    }

    $url = sprintf(
        'https://graph.facebook.com/%s/%s/events',
        HD_META_CAPI_API_VERSION,
        rawurlencode($s['pixel_id'])
    );
    $url = add_query_arg('access_token', $s['access_token'], $url);

    $response = wp_remote_post($url, [
        'timeout' => $blocking ? 4 : 2,
        'blocking' => $blocking,
        'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'HD-Meta-CAPI/1.0',
        ],
        'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    if (is_wp_error($response)) {
        return ['ok' => false, 'code' => 0, 'body' => $response->get_error_message()];
    }
    if (!$blocking) {
        // Fire-and-forget: accepted by WP HTTP API (no response body on async).
        return ['ok' => true, 'code' => 0, 'body' => 'async'];
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $body = substr((string) wp_remote_retrieve_body($response), 0, 800);
    return [
        'ok' => $code >= 200 && $code < 300,
        'code' => $code,
        'body' => $body,
    ];
}

/**
 * Persist meta.capi with read-merge (do not wipe concurrent espo / other keys).
 *
 * @param array<string,mixed> $meta
 */
function hd_meta_capi_persist_meta(int $leadId, array $meta): void
{
    if ($leadId <= 0) {
        return;
    }
    global $wpdb;
    $existingRaw = $wpdb->get_var($wpdb->prepare(
        'SELECT meta FROM ' . $wpdb->prefix . 'hd_leads WHERE id = %d',
        $leadId
    ));
    if (is_string($existingRaw) && $existingRaw !== '') {
        $existing = json_decode($existingRaw, true);
        if (is_array($existing)) {
            foreach ($existing as $k => $v) {
                if ($k === 'capi') {
                    // Deep-merge: keep Lead ok / funnel flags from either side (caller wins keys).
                    $incoming = (isset($meta['capi']) && is_array($meta['capi'])) ? $meta['capi'] : [];
                    $base = is_array($v) ? $v : [];
                    $meta['capi'] = array_merge($base, $incoming);
                    continue;
                }
                if (!array_key_exists($k, $meta)) {
                    $meta[$k] = $v;
                }
            }
            // Prefer caller's espo if present; else keep DB espo.
            if (empty($meta['espo']) && !empty($existing['espo'])) {
                $meta['espo'] = $existing['espo'];
            }
        }
    }
    $wpdb->update(
        $wpdb->prefix . 'hd_leads',
        ['meta' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE)],
        ['id' => $leadId],
        ['%s'],
        ['%d']
    );
}

/**
 * Send CAPI Lead for a WP lead id; persist status in meta.capi.
 *
 * @return array<string,mixed>
 */
function hd_meta_capi_send_lead(int $leadId, string $via = 'hook'): array
{
    if (!hd_meta_capi_is_ready() || $leadId <= 0) {
        return ['ok' => false, 'error' => 'disabled'];
    }
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT id, name, phone, meta, created_at, form_key FROM ' . $wpdb->prefix . 'hd_leads WHERE id = %d',
        $leadId
    ), ARRAY_A);
    if (!$row) {
        return ['ok' => false, 'error' => 'not_found'];
    }
    $meta = [];
    if (!empty($row['meta'])) {
        $decoded = json_decode((string) $row['meta'], true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }
    // Skip if already ok (idempotent).
    if (!empty($meta['capi']['ok']) && ($meta['capi']['event_id'] ?? '') === hd_meta_capi_event_id_for_lead($leadId)) {
        return ['ok' => true, 'skipped' => 'already_ok'];
    }

    $event = hd_meta_capi_build_lead_event($row, $meta);
    if ($event === null) {
        return ['ok' => false, 'error' => 'build_fail'];
    }
    $res = hd_meta_capi_post_events([$event]);
    $custom = is_array($event['custom_data'] ?? null) ? $event['custom_data'] : [];
    $meta['capi'] = [
        'ok' => !empty($res['ok']),
        'code' => (int) ($res['code'] ?? 0),
        'at' => current_time('mysql'),
        'via' => $via,
        'event_id' => (string) $event['event_id'],
        'pixel_id' => HD_META_CAPI_PIXEL_ID,
        'test' => hd_meta_capi_settings()['test_event_code'] !== '',
        'content_ids' => $custom['content_ids'] ?? [],
        'content_name' => (string) ($custom['content_name'] ?? ''),
        // Never store raw token; truncate API body (may contain error messages only).
        'err' => !empty($res['ok']) ? '' : substr((string) ($res['body'] ?? ''), 0, 240),
    ];
    hd_meta_capi_persist_meta($leadId, $meta);
    return $res + [
        'event_id' => $event['event_id'],
        'content_ids' => $custom['content_ids'] ?? [],
        'content_name' => (string) ($custom['content_name'] ?? ''),
    ];
}

/** Queue non-blocking send after response when possible. */
function hd_meta_capi_queue_lead(int $leadId): void
{
    if (!hd_meta_capi_is_ready() || $leadId <= 0) {
        return;
    }
    $key = 'hd_capi_q';
    $ids = get_transient($key);
    if (!is_array($ids)) {
        $ids = [];
    }
    $ids[] = $leadId;
    $ids = array_values(array_unique(array_map('intval', $ids)));
    set_transient($key, $ids, 10 * MINUTE_IN_SECONDS);
    if (!wp_next_scheduled('hd_meta_capi_flush_cron')) {
        wp_schedule_single_event(time() + 15, 'hd_meta_capi_flush_cron');
    }
}

add_action('hd_meta_capi_flush_cron', static function () {
    $ids = get_transient('hd_capi_q');
    delete_transient('hd_capi_q');
    if (!is_array($ids)) {
        return;
    }
    foreach (array_slice($ids, 0, 40) as $id) {
        hd_meta_capi_send_lead((int) $id, 'cron');
    }
});

add_action('hd_native_lead_saved', static function ($leadId) {
    $leadId = (int) $leadId;
    if ($leadId <= 0 || !hd_meta_capi_is_ready()) {
        return;
    }
    $res = hd_meta_capi_send_lead($leadId, 'lead_saved');
    if (empty($res['ok'])) {
        hd_meta_capi_queue_lead($leadId);
    }
}, 40, 1);

/* --------------------------------------------------------------------------
 * Espo funnel → CAPI Purchase (confirmed) + custom Delivered
 * Pixel Rockabilly only. Flags send_purchase / send_delivered default OFF.
 * -------------------------------------------------------------------------- */

function hd_meta_capi_event_id_for_purchase(int $leadId): string
{
    return 'purchase_' . max(0, $leadId);
}

function hd_meta_capi_event_id_for_delivered(int $leadId): string
{
    return 'delivered_' . max(0, $leadId);
}

/**
 * Catalog unit-1 price for Purchase value (omit if unknown).
 *
 * @param array<string,mixed> $meta
 * @param array<string,mixed>|null $form
 */
function hd_meta_capi_resolve_unit1_value(array $meta, $form = null): ?float
{
    $product = hd_meta_capi_lookup_catalog_product($meta, is_array($form) ? $form : null);
    if (!$product && !empty($meta['product_sku']) && function_exists('hd_catalog_products_get_by_sku')) {
        $product = hd_catalog_products_get_by_sku((string) $meta['product_sku']);
    }
    if (!$product || empty($product->prices_json) || !function_exists('hd_catalog_products_tier_for_units')) {
        return null;
    }
    $tier = hd_catalog_products_tier_for_units($product->prices_json, 1);
    if (!$tier || !isset($tier['price']) || (float) $tier['price'] <= 0) {
        return null;
    }
    return round((float) $tier['price'], 2);
}

/**
 * Shared user_data + content custom_data for funnel events (same shape as Lead).
 *
 * @param array<string,mixed> $leadRow
 * @param array<string,mixed> $meta
 * @return array{user_data:array<string,mixed>,custom_data:array<string,mixed>,source_url:string,event_time:int}|null
 */
function hd_meta_capi_build_funnel_base(array $leadRow, array $meta): ?array
{
    $leadId = (int) ($leadRow['id'] ?? 0);
    $phone = trim((string) ($leadRow['phone'] ?? ''));
    if ($leadId <= 0 || $phone === '') {
        return null;
    }

    $name = trim((string) ($leadRow['name'] ?? ''));
    $eventTime = time();
    $confirmedAt = trim((string) ($meta['espo_confirmed_at'] ?? ''));
    $deliveredAt = trim((string) ($meta['espo_delivered_at'] ?? ''));
    foreach ([$deliveredAt, $confirmedAt, (string) ($leadRow['created_at'] ?? '')] as $tsRaw) {
        if ($tsRaw === '') {
            continue;
        }
        $ts = (int) strtotime($tsRaw);
        if ($ts > 0) {
            $eventTime = $ts;
            break;
        }
    }

    $ip = trim((string) ($meta['client_ip'] ?? ''));
    if ($ip === '' && !empty($meta['payload']['hidden_5'])) {
        $ip = trim((string) $meta['payload']['hidden_5']);
    }
    $ua = trim((string) ($meta['client_ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')));

    $userData = [
        'ph' => [hd_meta_capi_hash_phone($phone)],
    ];
    if ($ip !== '') {
        $userData['client_ip_address'] = substr($ip, 0, 64);
    }
    if ($ua !== '') {
        $userData['client_user_agent'] = substr($ua, 0, 512);
    }

    $fnLen = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if ($fnLen >= 3 && strcasecmp($name, 'DAF') !== 0) {
        $userData['fn'] = [hd_meta_capi_hash($name)];
    }

    $fbp = trim((string) ($meta['fbp'] ?? ''));
    $fbc = trim((string) ($meta['fbc'] ?? ''));
    $tbclid = trim((string) ($meta['tbclid'] ?? ''));
    if (($fbp === '' || $fbc === '') && $tbclid !== '' && function_exists('tb_tracker_table')) {
        global $wpdb;
        $ct = tb_tracker_table('clicks');
        $click = $wpdb->get_row($wpdb->prepare(
            "SELECT fbp, fbc, fbclid FROM {$ct} WHERE click_id = %s LIMIT 1",
            $tbclid
        ), ARRAY_A);
        if (is_array($click)) {
            if ($fbp === '') {
                $fbp = trim((string) ($click['fbp'] ?? ''));
            }
            if ($fbc === '') {
                $fbc = trim((string) ($click['fbc'] ?? ''));
            }
        }
    }
    if ($fbp !== '') {
        $userData['fbp'] = $fbp;
    }
    if ($fbc !== '') {
        $userData['fbc'] = $fbc;
    } else {
        $fbclid = trim((string) ($meta['fbclid'] ?? ''));
        if ($fbclid !== '') {
            $userData['fbc'] = 'fb.1.' . $eventTime . '.' . $fbclid;
        }
    }

    $external = $tbclid !== '' ? $tbclid : ('wp_lead_' . $leadId);
    $userData['external_id'] = [hd_meta_capi_hash($external)];

    $sourceUrl = '';
    if (!empty($meta['payload']['_wp_http_referer'])) {
        $path = (string) $meta['payload']['_wp_http_referer'];
        $sourceUrl = (str_starts_with($path, 'http') ? $path : home_url($path));
    } elseif (!empty($meta['wp_http_referer'])) {
        $path = (string) $meta['wp_http_referer'];
        $sourceUrl = (str_starts_with($path, 'http') ? $path : home_url($path));
    } elseif (!empty($meta['payload']['current_url'])) {
        $sourceUrl = (string) $meta['payload']['current_url'];
    } else {
        $sourceUrl = home_url('/');
    }

    $form = null;
    $formKey = sanitize_key((string) ($leadRow['form_key'] ?? ($meta['form_key'] ?? '')));
    if ($formKey !== '' && function_exists('hd_native_get_form')) {
        $form = hd_native_get_form($formKey);
    }
    if (!is_array($form)) {
        $form = ['key' => $formKey, 'project_id' => (string) ($meta['project_id'] ?? '')];
    }
    $content = hd_meta_capi_resolve_product_content($meta, $form);

    $custom = [];
    if ($content['sku'] !== '') {
        $custom['content_ids'] = [$content['sku']];
        $custom['content_type'] = 'product';
    }
    $contentName = $content['name'] !== '' ? $content['name'] : trim((string) ($meta['product'] ?? $formKey));
    if ($contentName !== '') {
        $custom['content_name'] = substr($contentName, 0, 120);
    }

    return [
        'user_data' => $userData,
        'custom_data' => $custom,
        'source_url' => $sourceUrl,
        'event_time' => $eventTime,
        'form' => $form,
    ];
}

/**
 * Build Purchase or Delivered CAPI event.
 *
 * @param array<string,mixed> $leadRow
 * @param array<string,mixed> $meta
 * @return array<string,mixed>|null
 */
function hd_meta_capi_build_funnel_event(array $leadRow, array $meta, string $kind): ?array
{
    $kind = strtolower(trim($kind));
    if ($kind !== 'purchase' && $kind !== 'delivered') {
        return null;
    }
    $base = hd_meta_capi_build_funnel_base($leadRow, $meta);
    if ($base === null) {
        return null;
    }
    $leadId = (int) ($leadRow['id'] ?? 0);
    $custom = $base['custom_data'];
    $eventName = $kind === 'purchase' ? 'Purchase' : 'Delivered';
    $eventId = $kind === 'purchase'
        ? hd_meta_capi_event_id_for_purchase($leadId)
        : hd_meta_capi_event_id_for_delivered($leadId);

    if ($kind === 'purchase') {
        $value = hd_meta_capi_resolve_unit1_value($meta, $base['form'] ?? null);
        if ($value !== null) {
            $custom['value'] = $value;
            $custom['currency'] = 'TND';
        }
    }

    $event = [
        'event_name' => $eventName,
        'event_time' => (int) $base['event_time'],
        'event_id' => $eventId,
        'event_source_url' => (string) $base['source_url'],
        'action_source' => 'website',
        'user_data' => $base['user_data'],
    ];
    if ($custom !== []) {
        $event['custom_data'] = $custom;
    }
    return $event;
}

/**
 * Load lead row + meta for funnel CAPI.
 *
 * @return array{row:array<string,mixed>,meta:array<string,mixed>}|null
 */
function hd_meta_capi_load_lead_bundle(int $leadId): ?array
{
    if ($leadId <= 0) {
        return null;
    }
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT id, name, phone, meta, created_at, form_key FROM ' . $wpdb->prefix . 'hd_leads WHERE id = %d',
        $leadId
    ), ARRAY_A);
    if (!$row) {
        return null;
    }
    $meta = [];
    if (!empty($row['meta'])) {
        $decoded = json_decode((string) $row['meta'], true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }
    return ['row' => $row, 'meta' => $meta];
}

/**
 * Merge funnel send flags into meta.capi without wiping Lead status.
 *
 * @param array<string,mixed> $meta
 * @param array<string,mixed> $patch
 * @return array<string,mixed>
 */
function hd_meta_capi_merge_capi_flags(array $meta, array $patch): array
{
    $capi = (isset($meta['capi']) && is_array($meta['capi'])) ? $meta['capi'] : [];
    foreach ($patch as $k => $v) {
        $capi[$k] = $v;
    }
    $meta['capi'] = $capi;
    return $meta;
}

/**
 * Send one funnel event (Purchase|Delivered); idempotent via meta.capi.*_sent.
 *
 * @return array<string,mixed>
 */
function hd_meta_capi_send_funnel_event(int $leadId, string $kind, string $via = 'espo_sync'): array
{
    $kind = strtolower(trim($kind));
    if ($kind === 'purchase') {
        if (!hd_meta_capi_send_purchase_enabled()) {
            return ['ok' => false, 'error' => 'flag_off', 'kind' => $kind];
        }
        $sentKey = 'purchase_sent';
    } elseif ($kind === 'delivered') {
        if (!hd_meta_capi_send_delivered_enabled()) {
            return ['ok' => false, 'error' => 'flag_off', 'kind' => $kind];
        }
        $sentKey = 'delivered_sent';
    } else {
        return ['ok' => false, 'error' => 'bad_kind'];
    }

    $bundle = hd_meta_capi_load_lead_bundle($leadId);
    if ($bundle === null) {
        return ['ok' => false, 'error' => 'not_found', 'kind' => $kind];
    }
    $meta = $bundle['meta'];
    $capi = (isset($meta['capi']) && is_array($meta['capi'])) ? $meta['capi'] : [];
    if (!empty($capi[$sentKey])) {
        return ['ok' => true, 'skipped' => 'already_sent', 'kind' => $kind];
    }

    $event = hd_meta_capi_build_funnel_event($bundle['row'], $meta, $kind);
    if ($event === null) {
        return ['ok' => false, 'error' => 'build_fail', 'kind' => $kind];
    }

    // Non-blocking so Espo cron waves stay light (short timeout).
    $res = hd_meta_capi_post_events([$event], false);
    $custom = is_array($event['custom_data'] ?? null) ? $event['custom_data'] : [];
    $patch = [
        $sentKey => !empty($res['ok']),
        $kind . '_at' => current_time('mysql'),
        $kind . '_event_id' => (string) $event['event_id'],
        $kind . '_via' => $via,
        $kind . '_code' => (int) ($res['code'] ?? 0),
        $kind . '_err' => !empty($res['ok']) ? '' : substr((string) ($res['body'] ?? ''), 0, 240),
        $kind . '_content_ids' => $custom['content_ids'] ?? [],
        $kind . '_content_name' => (string) ($custom['content_name'] ?? ''),
        $kind . '_test' => hd_meta_capi_settings()['test_event_code'] !== '',
    ];
    // Async accept → mark sent to avoid duplicate floods; retry only if build fails.
    if (!empty($res['ok'])) {
        $patch[$sentKey] = true;
    }
    $meta = hd_meta_capi_merge_capi_flags($meta, $patch);
    hd_meta_capi_persist_meta($leadId, $meta);

    return $res + [
        'kind' => $kind,
        'event_id' => $event['event_id'],
        'event_name' => $event['event_name'],
        'content_ids' => $custom['content_ids'] ?? [],
        'content_name' => (string) ($custom['content_name'] ?? ''),
    ];
}

/**
 * Guarantee a funnel flush is pending. A single event left in the past (missed
 * cron wave, or unscheduled while a flush was already running) used to block
 * every later enqueue, orphaning the queue until the transient expired.
 */
function hd_meta_capi_ensure_funnel_flush(int $delay = 10): void
{
    $hook = 'hd_meta_capi_funnel_flush_cron';
    $next = wp_next_scheduled($hook);
    if ($next !== false && $next > time()) {
        return;
    }
    if ($next) {
        wp_unschedule_event($next, $hook);
    }
    wp_schedule_single_event(time() + max(1, $delay), $hook);
}

/**
 * Queue funnel CAPI after Espo stage upgrade (async cron, does not block sync).
 */
function hd_meta_capi_queue_funnel_events(int $leadId, string $prevStage, string $newStage): void
{
    if ($leadId <= 0 || !hd_meta_capi_is_ready()) {
        return;
    }
    $prev = strtolower(trim($prevStage));
    $new = strtolower(trim($newStage));
    $prevRank = ($prev === 'delivered') ? 2 : (($prev === 'confirmed') ? 1 : 0);
    $newRank = ($new === 'delivered') ? 2 : (($new === 'confirmed') ? 1 : 0);
    if ($newRank <= $prevRank) {
        return;
    }

    $jobs = [];
    // Backfill Purchase when jumping null→delivered or entering confirmed.
    if ($newRank >= 1 && hd_meta_capi_send_purchase_enabled()) {
        $jobs[] = 'purchase';
    }
    if ($newRank >= 2 && hd_meta_capi_send_delivered_enabled()) {
        $jobs[] = 'delivered';
    }
    if ($jobs === []) {
        return;
    }

    $key = 'hd_capi_funnel_q';
    $q = get_transient($key);
    if (!is_array($q)) {
        $q = [];
    }
    foreach ($jobs as $kind) {
        $q[] = ['lead_id' => $leadId, 'kind' => $kind];
    }
    // Dedupe by lead+kind keeping last.
    $seen = [];
    $out = [];
    foreach (array_reverse($q) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $lid = (int) ($item['lead_id'] ?? 0);
        $k = (string) ($item['kind'] ?? '');
        if ($lid <= 0 || ($k !== 'purchase' && $k !== 'delivered')) {
            continue;
        }
        $sig = $lid . ':' . $k;
        if (isset($seen[$sig])) {
            continue;
        }
        $seen[$sig] = true;
        $out[] = ['lead_id' => $lid, 'kind' => $k];
    }
    $out = array_reverse($out);
    set_transient($key, $out, 15 * MINUTE_IN_SECONDS);
    hd_meta_capi_ensure_funnel_flush(10);
}

add_action('hd_meta_capi_funnel_flush_cron', static function () {
    $key = 'hd_capi_funnel_q';
    $q = get_transient($key);
    delete_transient($key);
    if (!is_array($q)) {
        return;
    }
    $batch = array_slice($q, 0, 40);
    $rest = array_slice($q, 40);
    foreach ($batch as $item) {
        if (!is_array($item)) {
            continue;
        }
        $lid = (int) ($item['lead_id'] ?? 0);
        $kind = (string) ($item['kind'] ?? '');
        if ($lid <= 0) {
            continue;
        }
        hd_meta_capi_send_funnel_event($lid, $kind, 'funnel_cron');
    }
    // Overflow, plus anything enqueued while this batch was in flight.
    $pending = get_transient($key);
    $pending = is_array($pending) ? $pending : [];
    $pending = array_merge($rest, $pending);
    if ($pending !== []) {
        set_transient($key, $pending, 15 * MINUTE_IN_SECONDS);
        hd_meta_capi_ensure_funnel_flush(10);
    }
});

/**
 * Append event_id + sku + pn to merci URL (browser dedupe + Lead custom_data).
 * No PII — sku / product name only.
 */
add_filter('hd_native_merci_url', static function ($merci, $form, $meta, $leadId) {
    $leadId = (int) $leadId;
    if ($leadId <= 0 || !is_string($merci) || $merci === '' || !hd_meta_capi_is_ready()) {
        return $merci;
    }
    $args = ['eid' => hd_meta_capi_event_id_for_lead($leadId)];
    $meta = is_array($meta) ? $meta : [];
    $form = is_array($form) ? $form : null;
    $content = hd_meta_capi_resolve_product_content($meta, $form);
    if ($content['sku'] !== '') {
        $args['sku'] = $content['sku'];
    }
    if ($content['name'] !== '') {
        // URL-safe marketing label (no PII); browser URLSearchParams decodes it.
        $args['pn'] = substr($content['name'], 0, 80);
    }
    return add_query_arg($args, $merci);
}, 20, 4);

/**
 * Merci browser Lead: wrap fbq before theme (prio 99) so first Lead uses eventID + content_ids.
 * Preserves fan-out to all pixels; enrich custom_data for all inits (compatible, not trackSingle).
 */
add_action('wp_footer', static function () {
    if (!is_page()) {
        return;
    }
    $slug = get_post_field('post_name', get_queried_object_id());
    if (!$slug || stripos((string) $slug, 'merci') === false) {
        return;
    }
    echo "<script>(function(){\n";
    echo "try{\n";
    echo "var q=new URLSearchParams(location.search);\n";
    echo "var eid=q.get('eid')||'';\n";
    echo "var sku=q.get('sku')||'';\n";
    echo "var pn=q.get('pn')||'';\n";
    echo "var orig=window.fbq; if(typeof orig!=='function') return;\n";
    echo "window.fbq=function(){\n";
    echo "  var a=arguments;\n";
    echo "  if(a[0]==='track' && a[1]==='Lead'){\n";
    echo "    if(window.__hdLeadFired){ return; }\n";
    echo "    window.__hdLeadFired=1;\n";
    echo "    var cd=(a[2]&&typeof a[2]==='object')?Object.assign({},a[2]):{};\n";
    echo "    if(sku){ cd.content_ids=[String(sku)]; cd.content_type='product'; }\n";
    echo "    if(pn){ cd.content_name=String(pn); }\n";
    echo "    if(eid){ return orig.call(this,'track','Lead',cd,{eventID:String(eid)}); }\n";
    echo "    return orig.call(this,'track','Lead',cd);\n";
    echo "  }\n";
    echo "  return orig.apply(this,a);\n";
    echo "};\n";
    echo "for(var k in orig){ if(Object.prototype.hasOwnProperty.call(orig,k)) window.fbq[k]=orig[k]; }\n";
    echo "}catch(e){}\n";
    echo "})();</script>\n";
}, 98);

/* --------------------------------------------------------------------------
 * Phase 3 — Tracker page_view → CAPI ViewContent (Rockabilly only)
 * -------------------------------------------------------------------------- */

/**
 * Catalog row by WP page_id (boutique / white / lp_page_ids).
 *
 * @return object|null
 */
function hd_meta_capi_catalog_by_page_id(int $pageId)
{
    if ($pageId <= 0 || !function_exists('hd_catalog_products_table')) {
        return null;
    }
    global $wpdb;
    $table = hd_catalog_products_table();
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table}
         WHERE boutique_page_id = %d OR white_page_id = %d
         ORDER BY id ASC LIMIT 1",
        $pageId,
        $pageId
    ));
    if ($row) {
        return $row;
    }

    $needle = (string) $pageId;
    $candidates = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table}
         WHERE lp_page_ids LIKE %s
         LIMIT 30",
        '%' . $wpdb->esc_like($needle) . '%'
    ));
    if (!is_array($candidates)) {
        return null;
    }
    foreach ($candidates as $c) {
        $ids = function_exists('hd_catalog_products_decode_json')
            ? hd_catalog_products_decode_json($c->lp_page_ids ?? '[]')
            : (json_decode((string) ($c->lp_page_ids ?? '[]'), true) ?: []);
        $ids = array_map('intval', (array) $ids);
        if (in_array($pageId, $ids, true)) {
            return $c;
        }
    }
    return null;
}

/**
 * Resolve SKU + name for a tracker page_view (skip if unresolved).
 *
 * @param array<string,mixed> $args page_id, url, offer_slug
 * @param array<string,mixed>|null $clickRow
 * @return array{sku:string,name:string}
 */
function hd_meta_capi_resolve_view_content_product(array $args, $clickRow = null): array
{
    $pageId = (int) ($args['page_id'] ?? 0);
    $url = (string) ($args['url'] ?? '');
    $offerSlug = (string) ($args['offer_slug'] ?? '');
    $clickRow = is_array($clickRow) ? $clickRow : [];
    $productHint = (string) ($clickRow['product_hint'] ?? '');
    if ($offerSlug === '') {
        $offerSlug = (string) ($clickRow['offer_slug'] ?? '');
    }

    $sku = '';
    $name = '';

    // 1) Catalog by page_id (LP / boutique / white).
    $product = hd_meta_capi_catalog_by_page_id($pageId);
    if ($product) {
        $sku = hd_meta_capi_normalize_sku((string) ($product->sku ?? ''));
        $name = trim((string) ($product->name ?? ''));
    }

    // 2) Page form_key meta → catalog.
    if ($sku === '' && $pageId > 0 && function_exists('hd_catalog_products_get_by_form_key')) {
        foreach (['hd_form_key', 'hd_boutique_form_key'] as $mk) {
            $fk = sanitize_key((string) get_post_meta($pageId, $mk, true));
            if ($fk === '') {
                continue;
            }
            $byForm = hd_catalog_products_get_by_form_key($fk);
            if ($byForm) {
                $sku = hd_meta_capi_normalize_sku((string) ($byForm->sku ?? ''));
                $name = trim((string) ($byForm->name ?? ''));
                break;
            }
        }
    }

    // 3) SKU from URL path / page slug / offer / product_hint.
    if ($sku === '') {
        $slugCandidates = [];
        if ($pageId > 0) {
            $slugCandidates[] = (string) get_post_field('post_name', $pageId);
        }
        if ($url !== '') {
            $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?: '');
            $slugCandidates[] = trim(basename($path), '/');
            $slugCandidates[] = $path;
        }
        $slugCandidates[] = $offerSlug;
        $slugCandidates[] = $productHint;
        foreach ($slugCandidates as $slug) {
            $fromSlug = hd_meta_capi_sku_from_slug((string) $slug);
            if ($fromSlug === '') {
                $fromSlug = hd_meta_capi_normalize_sku((string) $slug);
                if ($fromSlug !== '' && !str_starts_with($fromSlug, 'YM')) {
                    $fromSlug = '';
                }
            }
            if ($fromSlug !== '') {
                $sku = $fromSlug;
                break;
            }
        }
        if ($sku !== '' && function_exists('hd_catalog_products_get_by_sku')) {
            $bySku = hd_catalog_products_get_by_sku($sku);
            if ($bySku) {
                $name = trim((string) ($bySku->name ?? $name));
                $sku = hd_meta_capi_normalize_sku((string) ($bySku->sku ?? $sku));
            }
        }
    }

    if ($name === '' && $sku !== '') {
        $map = hd_meta_capi_sku_name_fallback_map();
        $name = $map[$sku] ?? $sku;
    }

    return [
        'sku' => $sku,
        'name' => substr($name, 0, 120),
    ];
}

/** Stable event_id for ViewContent (browser dedupe-ready). No minute bucket — same click+page = same id. */
function hd_meta_capi_event_id_for_view_content(string $clickId, int $pageId): string
{
    $raw = 'vc_' . $clickId . '_' . max(0, $pageId);
    if (strlen($raw) <= 64) {
        return $raw;
    }
    return 'vc_' . substr(hash('sha256', $raw), 0, 40);
}

/** Anti-flood key: 1 ViewContent / (click_id + page_id) / 45 min. */
function hd_meta_capi_view_content_dedupe_key(string $clickId, int $pageId): string
{
    return 'hd_capi_vc_' . md5($clickId . '|' . max(0, $pageId));
}

/**
 * Prefer canonical ?page_id= URL for WP pages (ads / catalog consistency).
 */
function hd_meta_capi_view_content_source_url(int $pageId, string $fallbackUrl): string
{
    if ($pageId > 0) {
        return home_url('/?page_id=' . $pageId);
    }
    $fallbackUrl = trim($fallbackUrl);
    if ($fallbackUrl !== '' && (str_starts_with($fallbackUrl, 'http://') || str_starts_with($fallbackUrl, 'https://'))) {
        return substr($fallbackUrl, 0, 2000);
    }
    return home_url('/');
}

/**
 * True when page looks like thank-you (never fire ViewContent).
 */
function hd_meta_capi_is_merci_page(int $pageId, string $url): bool
{
    if ($pageId > 0) {
        $slug = (string) get_post_field('post_name', $pageId);
        if ($slug !== '' && stripos($slug, 'merci') !== false) {
            return true;
        }
    }
    $path = strtolower((string) (wp_parse_url($url, PHP_URL_PATH) ?: $url));
    return $path !== '' && strpos($path, 'merci') !== false;
}

/**
 * Send one CAPI ViewContent from tracker page_view (async, no PII phone/name).
 *
 * @param array<string,mixed> $args
 * @return array<string,mixed>
 */
function hd_meta_capi_send_view_content(string $clickId, array $args): array
{
    static $sentInRequest = [];

    if (!hd_meta_capi_send_view_content_enabled()) {
        return ['ok' => false, 'skipped' => 'disabled'];
    }
    $clickId = is_string($clickId) ? trim($clickId) : '';
    if ($clickId === '') {
        return ['ok' => false, 'skipped' => 'no_click'];
    }

    $pageId = (int) ($args['page_id'] ?? 0);
    $url = (string) ($args['url'] ?? '');
    if (hd_meta_capi_is_merci_page($pageId, $url)) {
        return ['ok' => false, 'skipped' => 'merci'];
    }

    $guardKey = $clickId . '|' . $pageId;
    if (isset($sentInRequest[$guardKey])) {
        return ['ok' => false, 'skipped' => 'in_request'];
    }

    $dedupeKey = hd_meta_capi_view_content_dedupe_key($clickId, $pageId);
    if (get_transient($dedupeKey)) {
        return ['ok' => false, 'skipped' => 'dedupe'];
    }

    $clickRow = null;
    if (function_exists('tb_tracker_table')) {
        global $wpdb;
        $ct = tb_tracker_table('clicks');
        $clickRow = $wpdb->get_row($wpdb->prepare(
            "SELECT fbp, fbc, fbclid, product_hint, offer_slug, landing_url
             FROM {$ct} WHERE click_id = %s LIMIT 1",
            $clickId
        ), ARRAY_A);
    }

    $content = hd_meta_capi_resolve_view_content_product($args, $clickRow);
    if ($content['sku'] === '') {
        // Prefer catalog quality: no ViewContent without content_ids.
        return ['ok' => false, 'skipped' => 'no_sku'];
    }

    // Claim dedupe window before POST (avoid double-send on parallel hits).
    $sentInRequest[$guardKey] = 1;
    set_transient($dedupeKey, 1, 45 * MINUTE_IN_SECONDS);

    $eventTime = time();
    $userData = [];
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip !== '') {
        $userData['client_ip_address'] = substr(sanitize_text_field($ip), 0, 64);
    }
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua !== '') {
        $userData['client_user_agent'] = substr(sanitize_text_field(wp_unslash($ua)), 0, 512);
    }

    $fbp = '';
    $fbc = '';
    if (is_array($clickRow)) {
        $fbp = trim((string) ($clickRow['fbp'] ?? ''));
        $fbc = trim((string) ($clickRow['fbc'] ?? ''));
    }
    if ($fbp === '' && !empty($_COOKIE['_fbp'])) {
        $fbp = substr(sanitize_text_field(wp_unslash((string) $_COOKIE['_fbp'])), 0, 128);
    }
    if ($fbc === '' && !empty($_COOKIE['_fbc'])) {
        $fbc = substr(sanitize_text_field(wp_unslash((string) $_COOKIE['_fbc'])), 0, 255);
    }
    // Weak ViewContent (IP/UA only, no browser _fbp) confuses Meta after high FB
    // in-app volume. Wait for attr_update with fbp instead of firing on bare page_view.
    if ($fbp === '') {
        return ['ok' => false, 'skipped' => 'no_fbp_yet'];
    }
    if ($fbp !== '') {
        $userData['fbp'] = $fbp;
    }
    if ($fbc !== '') {
        $userData['fbc'] = $fbc;
    } elseif (is_array($clickRow) && !empty($clickRow['fbclid'])) {
        $userData['fbc'] = 'fb.1.' . $eventTime . '.' . substr((string) $clickRow['fbclid'], 0, 200);
    }

    $userData['external_id'] = [hd_meta_capi_hash($clickId)];

    $eventId = hd_meta_capi_event_id_for_view_content($clickId, $pageId);
    $sourceUrl = hd_meta_capi_view_content_source_url($pageId, $url !== '' ? $url : (string) ($clickRow['landing_url'] ?? ''));

    $event = [
        'event_name' => 'ViewContent',
        'event_time' => $eventTime,
        'event_id' => $eventId,
        'event_source_url' => $sourceUrl,
        'action_source' => 'website',
        'user_data' => $userData,
        'custom_data' => [
            'content_ids' => [$content['sku']],
            'content_type' => 'product',
            'content_name' => $content['name'] !== '' ? $content['name'] : $content['sku'],
        ],
    ];

    // Non-blocking on front: do not add latency to LP TTFB.
    $res = hd_meta_capi_post_events([$event], false);
    return $res + [
        'event_id' => $eventId,
        'sku' => $content['sku'],
        'content_name' => $content['name'],
        'page_id' => $pageId,
    ];
}

add_action('tb_tracker_page_view', static function ($clickId, $args = []) {
    static $hookOnce = [];
    if (!is_string($clickId) || $clickId === '' || !is_array($args)) {
        return;
    }
    if (!hd_meta_capi_send_view_content_enabled()) {
        return;
    }
    // Never run from admin / cron / REST collectors.
    if (is_admin() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    $pageId = (int) ($args['page_id'] ?? 0);
    $hk = $clickId . '|' . $pageId;
    if (isset($hookOnce[$hk])) {
        return;
    }
    $hookOnce[$hk] = 1;
    hd_meta_capi_send_view_content($clickId, $args);
}, 20, 2);

/**
 * When browser finally reports _fbp, send ViewContent (skipped earlier if no_fbp_yet).
 */
add_action('tb_tracker_attr_update', static function ($clickId, $args = []) {
    if (!is_string($clickId) || $clickId === '' || !is_array($args)) {
        return;
    }
    if (!hd_meta_capi_send_view_content_enabled()) {
        return;
    }
    $fbp = trim((string) ($args['fbp'] ?? ''));
    if ($fbp === '' || !preg_match('/^fb\.\d+\.\d+\.\d+$/', $fbp)) {
        return;
    }
    hd_meta_capi_send_view_content($clickId, $args);
}, 20, 2);

/**
 * Ensure send_view_content flag exists in options without touching token/enabled.
 */
add_action('init', static function () {
    $opt = get_option(HD_META_CAPI_OPTION, []);
    if (!is_array($opt)) {
        $opt = [];
    }
    if (array_key_exists('send_view_content', $opt)) {
        return;
    }
    $opt['send_view_content'] = 1;
    update_option(HD_META_CAPI_OPTION, $opt, false);
}, 30);
