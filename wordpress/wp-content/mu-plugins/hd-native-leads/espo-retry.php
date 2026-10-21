<?php
/**
 * Espo send reliability — never block merci redirect.
 * Strategy: short attempt on request → always persist status → async retries (shutdown + cron).
 */
if (!defined('ABSPATH')) {
    exit;
}

/** Max async attempts per lead (after the first request attempt). */
function hd_native_espo_max_attempts(): int
{
    return 5;
}

/**
 * Persist lead meta with read-merge so concurrent writers (e.g. Meta CAPI meta.capi)
 * are not wiped by an in-memory $meta snapshot from before that write.
 *
 * @param array<string,mixed> $meta
 */
function hd_native_espo_persist_meta(int $leadId, array $meta): void
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
                if (!array_key_exists($k, $meta)) {
                    $meta[$k] = $v;
                }
            }
            // Always keep a successful/fresher CAPI status if the caller snapshot lacks it.
            if (!empty($existing['capi']) && is_array($existing['capi'])) {
                $incoming = (isset($meta['capi']) && is_array($meta['capi'])) ? $meta['capi'] : [];
                if ($incoming === [] || (empty($incoming['ok']) && !empty($existing['capi']['ok']))) {
                    $meta['capi'] = $existing['capi'];
                }
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
 * Load lead row + decoded meta.
 *
 * @return array{lead:array<string,mixed>,meta:array<string,mixed>}|null
 */
function hd_native_espo_load_lead(int $leadId): ?array
{
    if ($leadId <= 0) {
        return null;
    }
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT id, form_key, name, phone, meta FROM ' . $wpdb->prefix . 'hd_leads WHERE id = %d',
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
    return ['lead' => $row, 'meta' => $meta];
}

/**
 * Build Espo body from stored lead (for retries).
 *
 * @param array<string,mixed> $lead
 * @param array<string,mixed> $meta
 * @return array<string,mixed>|null
 */
function hd_native_espo_body_from_lead(array $lead, array $meta): ?array
{
    if (!empty($meta['espo_body']) && is_array($meta['espo_body'])) {
        return $meta['espo_body'];
    }
    if (!function_exists('hd_native_build_espo_payload') || !function_exists('hd_native_get_form')) {
        return null;
    }
    $form = hd_native_get_form((string) ($lead['form_key'] ?? ''));
    if (!$form) {
        $form = ['key' => (string) ($lead['form_key'] ?? ''), 'project_id' => (string) ($meta['project_id'] ?? '')];
    }
    $ctx = [
        'current_url' => (string) ($meta['payload']['hidden_3'] ?? $meta['wp_http_referer'] ?? ''),
        'refer_url' => (string) ($meta['payload']['hidden_4'] ?? ''),
        'ip' => (string) ($meta['payload']['hidden_5'] ?? ''),
        'page_id' => (string) ($meta['page_id'] ?? ''),
        'wp_http_referer' => (string) ($meta['wp_http_referer'] ?? ''),
    ];
    $body = hd_native_build_espo_payload(
        (string) ($lead['name'] ?? ''),
        (string) ($lead['phone'] ?? ''),
        $form,
        $ctx,
        $meta
    );
    return apply_filters('hd_native_espo_payload', $body, $form, $ctx, $meta, (int) ($lead['id'] ?? 0));
}

/**
 * One Espo POST with short timeout (does not stall merci when used after flush).
 *
 * @param array<string,mixed> $body
 * @return array{ok:bool,code:int,body:string,url:string}
 */
function hd_native_send_espo_lead_quick(array $body, int $timeout = 4): array
{
    $url = function_exists('hd_native_espo_leadcapture_url')
        ? hd_native_espo_leadcapture_url()
        : '';
    if ($url === '') {
        return ['ok' => false, 'code' => 0, 'body' => 'no_url', 'url' => ''];
    }
    $timeout = max(2, min(8, $timeout));
    $response = wp_remote_post($url, [
        'timeout' => $timeout,
        'blocking' => true,
        'headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'HD-Native-Leads/Espo',
        ],
        'body' => wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    if (is_wp_error($response)) {
        return [
            'ok' => false,
            'code' => 0,
            'body' => $response->get_error_message(),
            'url' => $url,
        ];
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    return [
        'ok' => $code >= 200 && $code < 300,
        'code' => $code,
        'body' => substr((string) wp_remote_retrieve_body($response), 0, 500),
        'url' => $url,
    ];
}

/**
 * Attempt send + update meta. Safe to call from cron / shutdown.
 */
function hd_native_espo_attempt_lead(int $leadId, string $via = 'retry'): bool
{
    $pack = hd_native_espo_load_lead($leadId);
    if (!$pack) {
        return false;
    }
    $meta = $pack['meta'];
    if (!empty($meta['espo']['ok'])) {
        return true;
    }
    $body = hd_native_espo_body_from_lead($pack['lead'], $meta);
    if (!$body) {
        $meta['espo'] = [
            'ok' => false,
            'pending' => true,
            'code' => 0,
            'at' => current_time('mysql'),
            'err' => 'no_body',
            'via' => $via,
        ];
        hd_native_espo_persist_meta($leadId, $meta);
        return false;
    }
    $meta['espo_body'] = $body;
    $attempts = (int) ($meta['espo']['attempts'] ?? 0);
    $result = hd_native_send_espo_lead_quick($body, $via === 'request' ? 4 : 6);
    $attempts++;
    $meta['espo'] = [
        'ok' => !empty($result['ok']),
        'pending' => empty($result['ok']),
        'code' => (int) ($result['code'] ?? 0),
        'at' => current_time('mysql'),
        'attempts' => $attempts,
        'via' => $via,
        'err' => !empty($result['ok']) ? '' : substr((string) ($result['body'] ?? ''), 0, 180),
    ];
    if (!empty($result['ok'])) {
        // Drop stored body after success (keep meta light).
        unset($meta['espo_body']);
    }
    hd_native_espo_persist_meta($leadId, $meta);
    do_action('hd_native_espo_sent', $leadId, $result, $body, []);
    return !empty($result['ok']);
}

/** Queue lead id for post-response retry (shutdown + cron). */
function hd_native_espo_queue_retry(int $leadId): void
{
    if ($leadId <= 0) {
        return;
    }
    $q = get_option('hd_native_espo_retry_q', []);
    if (!is_array($q)) {
        $q = [];
    }
    $q[(string) $leadId] = time();
    // Cap queue size
    if (count($q) > 200) {
        $q = array_slice($q, -200, null, true);
    }
    update_option('hd_native_espo_retry_q', $q, false);

    if (!wp_next_scheduled('hd_native_espo_retry_cron')) {
        wp_schedule_event(time() + 60, 'hourly', 'hd_native_espo_retry_cron');
    }
    // Near-term single events (lightweight)
    if (!wp_next_scheduled('hd_native_espo_retry_once', [$leadId])) {
        wp_schedule_single_event(time() + 45, 'hd_native_espo_retry_once', [$leadId]);
    }
}

/**
 * Finish HTTP response early so retries never delay merci.
 */
function hd_native_espo_finish_request(): void
{
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
        return;
    }
    if (function_exists('litespeed_finish_request')) {
        @litespeed_finish_request();
        return;
    }
    // Best-effort flush
    if (function_exists('session_write_close')) {
        @session_write_close();
    }
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
}

/**
 * After redirect headers: close connection then retry pending Espo.
 *
 * @param list<int> $leadIds
 */
function hd_native_espo_after_response_retry(array $leadIds): void
{
    ignore_user_abort(true);
    if (function_exists('set_time_limit')) {
        @set_time_limit(60);
    }
    hd_native_espo_finish_request();
    foreach ($leadIds as $id) {
        $id = (int) $id;
        if ($id <= 0) {
            continue;
        }
        $ok = hd_native_espo_attempt_lead($id, 'shutdown');
        if (!$ok) {
            // One more quick try
            hd_native_espo_attempt_lead($id, 'shutdown2');
        }
    }
}

add_action('hd_native_espo_retry_once', static function ($leadId) {
    $leadId = (int) $leadId;
    if ($leadId <= 0) {
        return;
    }
    if (!hd_native_espo_attempt_lead($leadId, 'cron_once')) {
        $pack = hd_native_espo_load_lead($leadId);
        $attempts = (int) ($pack['meta']['espo']['attempts'] ?? 0);
        if ($attempts < hd_native_espo_max_attempts() && !wp_next_scheduled('hd_native_espo_retry_once', [$leadId])) {
            wp_schedule_single_event(time() + 120, 'hd_native_espo_retry_once', [$leadId]);
        }
    }
}, 10, 1);

add_action('hd_native_espo_retry_cron', static function () {
    global $wpdb;
    $table = $wpdb->prefix . 'hd_leads';
    // Recent leads missing espo.ok (last 48h)
    $rows = $wpdb->get_results(
        "SELECT id, meta FROM {$table}
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
         ORDER BY id DESC LIMIT 80",
        ARRAY_A
    );
    if (!is_array($rows)) {
        return;
    }
    $n = 0;
    foreach ($rows as $row) {
        $meta = json_decode((string) ($row['meta'] ?? ''), true);
        if (!is_array($meta)) {
            $meta = [];
        }
        if (!empty($meta['espo']['ok'])) {
            continue;
        }
        // Skip if never attempted and very fresh (< 20s) — request path still running
        $id = (int) $row['id'];
        $attempts = (int) ($meta['espo']['attempts'] ?? 0);
        if ($attempts >= hd_native_espo_max_attempts()) {
            continue;
        }
        hd_native_espo_attempt_lead($id, 'cron_scan');
        $n++;
        if ($n >= 15) {
            break;
        }
    }
    // Drain explicit queue
    $q = get_option('hd_native_espo_retry_q', []);
    if (is_array($q)) {
        foreach (array_keys($q) as $idStr) {
            $id = (int) $idStr;
            if ($id > 0) {
                hd_native_espo_attempt_lead($id, 'queue');
            }
            unset($q[$idStr]);
        }
        update_option('hd_native_espo_retry_q', $q, false);
    }
});

// Ensure cron registered (every 5 min via single-event chain — no custom schedule needed)
add_action('init', static function () {
    if (!wp_next_scheduled('hd_native_espo_retry_cron')) {
        wp_schedule_event(time() + 90, 'hourly', 'hd_native_espo_retry_cron');
    }
    // Frequent sweep: self-rescheduling single event every 3 minutes
    if (!wp_next_scheduled('hd_native_espo_retry_tick')) {
        wp_schedule_single_event(time() + 90, 'hd_native_espo_retry_tick');
    }
}, 20);

add_action('hd_native_espo_retry_tick', static function () {
    do_action('hd_native_espo_retry_cron');
    if (!wp_next_scheduled('hd_native_espo_retry_tick')) {
        wp_schedule_single_event(time() + 180, 'hd_native_espo_retry_tick');
    }
});
