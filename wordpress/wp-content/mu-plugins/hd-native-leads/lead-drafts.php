<?php
/**
 * Lead drafts (DAF).
 * Soft-save phone/name via beacon BEFORE submit for ALL registered native forms.
 * Auto-promote to wp_hd_leads + Espo for ALL registered native forms — STRICT rules + DAF utm_campaign.
 * Auto-promote after >= 5 minutes of inactivity (last activity = draft + tracker events/click).
 * Cron runs every minute so send usually happens within ~2 min after the 5 min gate (no hard max cutoff).
 * Convert marker also fires when a real lead is saved (organic submit).
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CRM / Espo auto-promote allowlist — all registered native form keys (same scope as collect).
 *
 * @return list<string>
 */
function hd_native_draft_crm_keys(): array
{
    if (!defined('HD_LEADS_OPTION')) {
        return [];
    }
    $all = get_option(HD_LEADS_OPTION, []);
    if (!is_array($all) || $all === []) {
        return [];
    }
    $keys = [];
    foreach (array_keys($all) as $raw) {
        $k = sanitize_key((string) $raw);
        if ($k !== '') {
            $keys[] = $k;
        }
    }
    return array_values(array_unique($keys));
}

/** Alias — same as CRM keys (auto-send / cron promote). */
function hd_native_draft_auto_send_keys(): array
{
    return hd_native_draft_crm_keys();
}

function hd_native_draft_is_crm_key(string $key): bool
{
    return hd_native_draft_is_collect_key($key);
}

/**
 * Collect drafts for any key registered in hd_native_form_defs (via hd_native_get_form).
 * Unknown / non-native keys are refused — never forced.
 */
function hd_native_draft_is_collect_key(string $key): bool
{
    $key = sanitize_key($key);
    if ($key === '') {
        return false;
    }
    if (!function_exists('hd_native_get_form')) {
        return false;
    }
    $form = hd_native_get_form($key);
    return is_array($form) && $form !== [];
}

/** @deprecated Use hd_native_draft_crm_keys — kept for BC. */
function hd_native_draft_pilot_keys(): array
{
    return hd_native_draft_crm_keys();
}

/** @deprecated Use hd_native_draft_is_crm_key — kept for BC. */
function hd_native_draft_is_pilot_key(string $key): bool
{
    return hd_native_draft_is_crm_key($key);
}

function hd_native_drafts_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'hd_lead_drafts';
}

function hd_native_drafts_maybe_install(): void
{
    global $wpdb;
    $table = hd_native_drafts_table();
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists === $table) {
        return;
    }
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        form_key VARCHAR(64) NOT NULL,
        phone VARCHAR(32) NOT NULL,
        name VARCHAR(190) NULL,
        tbclid VARCHAR(64) NULL,
        page_id VARCHAR(32) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        lead_id BIGINT UNSIGNED NULL,
        meta LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY phone_form (phone, form_key),
        KEY status_updated (status, updated_at),
        KEY tbclid (tbclid)
    ) {$charset};");
}
add_action('init', 'hd_native_drafts_maybe_install', 2);

/**
 * Upsert draft. Never calls Espo.
 *
 * @param array<string,mixed> $data
 * @return array{ok:bool,id?:int,error?:string}
 */
function hd_native_draft_upsert(array $data): array
{
    $key = sanitize_key((string) ($data['form_key'] ?? ''));
    if (!hd_native_draft_is_collect_key($key)) {
        return ['ok' => false, 'error' => 'not_collect'];
    }
    if (!function_exists('hd_native_normalize_tn_phone')) {
        return ['ok' => false, 'error' => 'no_norm'];
    }
    $norm = hd_native_normalize_tn_phone((string) ($data['phone'] ?? ''));
    if (empty($norm['ok'])) {
        return ['ok' => false, 'error' => 'bad_phone'];
    }
    $phone = (string) $norm['phone'];
    $name = sanitize_text_field((string) ($data['name'] ?? ''));
    $tbclid = function_exists('tb_tracker_sanitize_click_id')
        ? tb_tracker_sanitize_click_id((string) ($data['tbclid'] ?? ''))
        : substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($data['tbclid'] ?? '')), 0, 64);
    $pageId = substr(sanitize_text_field((string) ($data['page_id'] ?? '')), 0, 32);

    $browserUa = substr(sanitize_text_field(wp_unslash((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))), 0, 512);
    // Never persist cron/CLI UA on draft (promote runs later without the visitor).
    if ($browserUa !== '' && stripos($browserUa, 'WordPress/') === 0) {
        $browserUa = '';
    }

    $meta = [
        'utm_source' => sanitize_text_field((string) ($data['utm_source'] ?? '')),
        'utm_medium' => sanitize_text_field((string) ($data['utm_medium'] ?? '')),
        'utm_campaign' => sanitize_text_field((string) ($data['utm_campaign'] ?? '')),
        'utm_content' => sanitize_text_field((string) ($data['utm_content'] ?? '')),
        'utm_term' => sanitize_text_field((string) ($data['utm_term'] ?? '')),
        'fbclid' => sanitize_text_field((string) ($data['fbclid'] ?? '')),
        'current_url' => esc_url_raw((string) ($data['current_url'] ?? '')),
        'via' => sanitize_key((string) ($data['via'] ?? 'beacon')),
        'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        'client_ua' => $browserUa,
    ];

    global $wpdb;
    $table = hd_native_drafts_table();
    $now = current_time('mysql');
    $existingId = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE phone = %s AND form_key = %s LIMIT 1",
        $phone,
        $key
    ));

    if ($existingId > 0) {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT status, meta FROM {$table} WHERE id = %d",
            $existingId
        ), ARRAY_A);
        $status = (string) ($row['status'] ?? 'open');
        if ($status === 'converted') {
            return ['ok' => true, 'id' => $existingId];
        }
        $prevMeta = [];
        if (!empty($row['meta'])) {
            $decoded = json_decode((string) $row['meta'], true);
            if (is_array($decoded)) {
                $prevMeta = $decoded;
            }
        }
        $merged = array_merge($prevMeta, array_filter($meta, static function ($v) {
            return $v !== '' && $v !== null;
        }));
        // Do not clobber an in-flight promote race with a beacon.
        $fields = [
            'name' => $name !== '' ? $name : null,
            'tbclid' => $tbclid !== '' ? $tbclid : null,
            'page_id' => $pageId !== '' ? $pageId : null,
            'meta' => wp_json_encode($merged, JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ];
        $formats = ['%s', '%s', '%s', '%s', '%s'];
        if ($status !== 'promoting') {
            $fields['status'] = 'open';
            $formats[] = '%s';
        }
        $wpdb->update($table, $fields, ['id' => $existingId], $formats, ['%d']);
        return ['ok' => true, 'id' => $existingId];
    }

    $wpdb->insert(
        $table,
        [
            'form_key' => $key,
            'phone' => $phone,
            'name' => $name !== '' ? $name : null,
            'tbclid' => $tbclid !== '' ? $tbclid : null,
            'page_id' => $pageId !== '' ? $pageId : null,
            'status' => 'open',
            'lead_id' => null,
            'meta' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ],
        ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
    );
    $id = (int) $wpdb->insert_id;
    return $id > 0 ? ['ok' => true, 'id' => $id] : ['ok' => false, 'error' => 'insert_fail'];
}

/** Soft rate limit: max 20 draft posts / IP / 10 minutes. */
function hd_native_draft_rate_ok(): bool
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '') {
        return true;
    }
    $k = 'hd_draft_rl_' . md5($ip);
    $n = (int) get_transient($k);
    if ($n >= 20) {
        return false;
    }
    set_transient($k, $n + 1, 10 * MINUTE_IN_SECONDS);
    return true;
}

add_action('rest_api_init', static function () {
    register_rest_route('hd/v1', '/lead-draft', [
        'methods' => ['POST', 'OPTIONS'],
        'permission_callback' => '__return_true',
        'callback' => static function (WP_REST_Request $req) {
            if ($req->get_method() === 'OPTIONS') {
                return new WP_REST_Response(null, 204);
            }
            if (!hd_native_draft_rate_ok()) {
                return new WP_REST_Response(['ok' => false, 'error' => 'rate'], 429);
            }
            $data = $req->get_json_params();
            if (!is_array($data) || $data === []) {
                $raw = $req->get_body();
                if (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    $data = is_array($decoded) ? $decoded : [];
                }
            }
            if (!is_array($data) || $data === []) {
                return new WP_REST_Response(['ok' => false, 'error' => 'empty'], 400);
            }
            // Consent flag required by client contract (pilot).
            if (empty($data['consent'])) {
                return new WP_REST_Response(['ok' => false, 'error' => 'consent'], 400);
            }
            $nonce = (string) ($data['n'] ?? '');
            if ($nonce === '' || !wp_verify_nonce($nonce, 'hd_lead_draft')) {
                return new WP_REST_Response(['ok' => false, 'error' => 'auth'], 403);
            }
            $res = hd_native_draft_upsert($data);
            $code = !empty($res['ok']) ? 200 : 400;
            return new WP_REST_Response($res, $code);
        },
    ]);
});

/** Mark draft converted when real lead lands — still no Espo from this hook. */
add_action('hd_native_lead_saved', static function ($leadId, $form, $ctx, $meta) {
    $key = sanitize_key((string) ($form['key'] ?? ''));
    if (!hd_native_draft_is_collect_key($key)) {
        return;
    }
    $phone = '';
    if (is_array($meta) && !empty($meta['payload']['phone'])) {
        $phone = (string) $meta['payload']['phone'];
    }
    // Prefer normalized phone from DB row.
    global $wpdb;
    $leadPhone = (string) $wpdb->get_var($wpdb->prepare(
        'SELECT phone FROM ' . $wpdb->prefix . 'hd_leads WHERE id = %d',
        (int) $leadId
    ));
    if ($leadPhone !== '') {
        $phone = $leadPhone;
    }
    if ($phone === '') {
        return;
    }
    $table = hd_native_drafts_table();
    $wpdb->update(
        $table,
        [
            'status' => 'converted',
            'lead_id' => (int) $leadId,
            'updated_at' => current_time('mysql'),
        ],
        [
            'phone' => $phone,
            'form_key' => $key,
        ],
        ['%s', '%d', '%s'],
        ['%s', '%s']
    );
}, 20, 4);

/**
 * Prefix utm_campaign with DAF for draft auto-promote only.
 * Empty → "DAF"; already starts with DAF (any case, with/without hyphen) → unchanged; else → "DAF-" + original.
 */
function hd_native_draft_daf_campaign(string $campaign): string
{
    $campaign = trim($campaign);
    if ($campaign === '') {
        return 'DAF';
    }
    if (stripos($campaign, 'DAF') === 0) {
        return $campaign;
    }
    return 'DAF-' . $campaign;
}

/**
 * Lead name for draft promote: keep only if >= 3 UTF-8 chars.
 * Empty/short names are NOT eligible (DAF prefix is for utm_campaign only — never used as name).
 *
 * @return array{ok:bool,name?:string,reason?:string}
 */
function hd_native_draft_resolve_name(string $name): array
{
    $name = trim(sanitize_text_field($name));
    $len = function_exists('mb_strlen') ? (int) mb_strlen($name, 'UTF-8') : strlen($name);
    if ($len >= 3) {
        return ['ok' => true, 'name' => $name];
    }
    return ['ok' => false, 'reason' => 'name_short'];
}

/**
 * Tracker engagement gate for draft promote (tbclid required by caller).
 * True if any of: form_start/popup_show, event_type contains engage|scroll,
 * session span >= 30s, or event count >= 3.
 */
function hd_native_draft_tracker_engaged(string $tbclid): bool
{
    $tbclid = trim($tbclid);
    if ($tbclid === '') {
        return false;
    }

    $events = [];
    if (function_exists('hd_native_tracker_snapshot')) {
        $snap = hd_native_tracker_snapshot($tbclid);
        $events = is_array($snap['events'] ?? null) ? $snap['events'] : [];
    } elseif (function_exists('tb_tracker_table')) {
        global $wpdb;
        $et = tb_tracker_table('events');
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT event_type, created_at FROM {$et} WHERE click_id = %s ORDER BY id ASC LIMIT 200",
                $tbclid
            ),
            ARRAY_A
        );
        $events = is_array($rows) ? $rows : [];
    }

    if ($events === []) {
        return false;
    }
    if (count($events) >= 3) {
        return true;
    }

    $firstTs = null;
    $lastTs = null;
    foreach ($events as $ev) {
        $type = strtolower((string) ($ev['event_type'] ?? ''));
        if ($type === 'form_start' || $type === 'popup_show') {
            return true;
        }
        if (strpos($type, 'engage') !== false || strpos($type, 'scroll') !== false) {
            return true;
        }
        $created = (string) ($ev['created_at'] ?? '');
        if ($created === '') {
            continue;
        }
        $ts = strtotime($created);
        if ($ts === false) {
            continue;
        }
        if ($firstTs === null || $ts < $firstTs) {
            $firstTs = $ts;
        }
        if ($lastTs === null || $ts > $lastTs) {
            $lastTs = $ts;
        }
    }

    return $firstTs !== null && $lastTs !== null && ($lastTs - $firstTs) >= 30;
}

/**
 * Most recent activity timestamp (WP local) for a draft row.
 * Base = max(draft updated_at, created_at); with tbclid also MAX(events.created_at)
 * and click created_at / last_seen_at.
 *
 * @param array<string,mixed> $row Draft DB row.
 */
function hd_native_draft_last_activity_ts(array $row): int
{
    $candidates = [];
    foreach (['updated_at', 'created_at'] as $col) {
        $v = (string) ($row[$col] ?? '');
        if ($v === '') {
            continue;
        }
        $ts = strtotime($v);
        if ($ts !== false) {
            $candidates[] = (int) $ts;
        }
    }

    $tbclid = trim((string) ($row['tbclid'] ?? ''));
    if ($tbclid !== '' && function_exists('tb_tracker_table')) {
        global $wpdb;
        $et = tb_tracker_table('events');
        $maxEv = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(created_at) FROM {$et} WHERE click_id = %s",
            $tbclid
        ));
        if (is_string($maxEv) && $maxEv !== '') {
            $ts = strtotime($maxEv);
            if ($ts !== false) {
                $candidates[] = (int) $ts;
            }
        }

        $ct = tb_tracker_table('clicks');
        $click = $wpdb->get_row($wpdb->prepare(
            "SELECT created_at, last_seen_at FROM {$ct} WHERE click_id = %s LIMIT 1",
            $tbclid
        ), ARRAY_A);
        if (is_array($click)) {
            foreach (['created_at', 'last_seen_at'] as $col) {
                $v = (string) ($click[$col] ?? '');
                if ($v === '') {
                    continue;
                }
                $ts = strtotime($v);
                if ($ts !== false) {
                    $candidates[] = (int) $ts;
                }
            }
        }
    }

    return $candidates === [] ? 0 : max($candidates);
}

/**
 * STRICT eligibility for auto-promote.
 * Idle: last activity stopped for >= 5 min (no upper bound — cron aims to send within ~2 min after gate).
 *
 * @param array<string,mixed> $row Draft DB row.
 * @return array{ok:bool,reason:string,idle_sec?:int}
 */
function hd_native_draft_is_eligible(array $row): array
{
    $status = (string) ($row['status'] ?? '');
    if ($status === 'converted') {
        return ['ok' => false, 'reason' => 'already_converted'];
    }
    if ($status !== 'open') {
        return ['ok' => false, 'reason' => 'not_open'];
    }

    $key = sanitize_key((string) ($row['form_key'] ?? ''));
    if (!hd_native_draft_is_crm_key($key)) {
        return ['ok' => false, 'reason' => 'not_crm_key'];
    }

    if (!function_exists('hd_native_normalize_tn_phone')) {
        return ['ok' => false, 'reason' => 'no_norm'];
    }
    $norm = hd_native_normalize_tn_phone((string) ($row['phone'] ?? ''));
    if (empty($norm['ok'])) {
        return ['ok' => false, 'reason' => 'bad_phone'];
    }

    $nameCheck = hd_native_draft_resolve_name((string) ($row['name'] ?? ''));
    if (empty($nameCheck['ok'])) {
        return ['ok' => false, 'reason' => 'name_short'];
    }

    $lastAct = hd_native_draft_last_activity_ts($row);
    if ($lastAct <= 0) {
        return ['ok' => false, 'reason' => 'no_activity'];
    }
    $idle = (int) current_time('timestamp') - $lastAct;
    if ($idle < 5 * MINUTE_IN_SECONDS) {
        return ['ok' => false, 'reason' => 'too_fresh', 'idle_sec' => $idle];
    }

    $tbclid = trim((string) ($row['tbclid'] ?? ''));
    if ($tbclid === '') {
        return ['ok' => false, 'reason' => 'no_tbclid'];
    }
    if (!hd_native_draft_tracker_engaged($tbclid)) {
        return ['ok' => false, 'reason' => 'no_engagement'];
    }

    global $wpdb;
    $phone = (string) $norm['phone'];
    $exists = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . $wpdb->prefix . 'hd_leads WHERE phone = %s LIMIT 1',
        $phone
    ));
    if ($exists > 0) {
        return ['ok' => false, 'reason' => 'phone_exists'];
    }

    return ['ok' => true, 'reason' => 'ok', 'idle_sec' => $idle];
}

/**
 * Close open drafts whose phone already exists in hd_leads (organic win).
 * These are not DAF-eligible; leaving them 'open' starved the newest queue.
 *
 * @return int Closed rows.
 */
function hd_native_draft_close_duplicate_phones(int $limit = 400): int
{
    global $wpdb;
    $drafts = hd_native_drafts_table();
    $leads = $wpdb->prefix . 'hd_leads';
    $limit = max(1, min(800, $limit));
    $now = current_time('mysql');
    $rows = $wpdb->get_results(
        "SELECT d.id AS draft_id, MIN(l.id) AS lead_id
         FROM {$drafts} d
         INNER JOIN {$leads} l ON l.phone = d.phone AND d.phone <> ''
         WHERE d.status = 'open'
         GROUP BY d.id
         ORDER BY d.updated_at ASC
         LIMIT {$limit}",
        ARRAY_A
    );
    if (!is_array($rows) || $rows === []) {
        return 0;
    }
    $n = 0;
    foreach ($rows as $row) {
        $draftId = (int) ($row['draft_id'] ?? 0);
        $leadId = (int) ($row['lead_id'] ?? 0);
        if ($draftId <= 0 || $leadId <= 0) {
            continue;
        }
        $ok = $wpdb->update(
            $drafts,
            [
                'status' => 'converted',
                'lead_id' => $leadId,
                'updated_at' => $now,
            ],
            [
                'id' => $draftId,
                'status' => 'open',
            ],
            ['%s', '%d', '%s'],
            ['%d', '%s']
        );
        if ($ok === 1) {
            $n++;
        }
    }
    return $n;
}

/** Reset drafts stuck in status=promoting longer than 2 minutes. */
function hd_native_draft_reset_stuck_promoting(): int
{
    global $wpdb;
    $table = hd_native_drafts_table();
    $now = current_time('mysql');
    $cutoff = date('Y-m-d H:i:s', current_time('timestamp') - 2 * MINUTE_IN_SECONDS);
    $n = $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET status = 'open', updated_at = %s
         WHERE status = 'promoting' AND updated_at < %s",
        $now,
        $cutoff
    ));
    return is_int($n) ? $n : 0;
}

/**
 * Promote one draft → wp_hd_leads + Espo (no redirect). DAF-prefixes utm_campaign.
 *
 * @param array<string,mixed> $opts Optional: force (skip eligibility), skip_espo.
 * @return array{ok:bool,lead_id?:int,error?:string,espo_ok?:bool}
 */
function hd_native_draft_promote(int $draftId, array $opts = []): array
{
    global $wpdb;
    $table = hd_native_drafts_table();
    $draftId = (int) $draftId;
    if ($draftId <= 0) {
        return ['ok' => false, 'error' => 'bad_id'];
    }

    $lockKey = 'hd_draft_promote_' . $draftId;
    if (get_transient($lockKey)) {
        return ['ok' => false, 'error' => 'locked'];
    }
    set_transient($lockKey, 1, 55);

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $draftId), ARRAY_A);
    if (!$row) {
        delete_transient($lockKey);
        return ['ok' => false, 'error' => 'not_found'];
    }

    if (empty($opts['force'])) {
        $elig = hd_native_draft_is_eligible($row);
        if (empty($elig['ok'])) {
            delete_transient($lockKey);
            return ['ok' => false, 'error' => (string) ($elig['reason'] ?? 'not_eligible')];
        }
    } elseif ((string) ($row['status'] ?? '') === 'converted') {
        delete_transient($lockKey);
        return ['ok' => false, 'error' => 'already_converted'];
    }

    $key = sanitize_key((string) ($row['form_key'] ?? ''));
    // Hard gate: never promote non-CRM keys (even with force) — YM35 only.
    if (!hd_native_draft_is_crm_key($key)) {
        delete_transient($lockKey);
        return ['ok' => false, 'error' => 'not_crm_key'];
    }
    if (!function_exists('hd_native_get_form')) {
        delete_transient($lockKey);
        return ['ok' => false, 'error' => 'no_get_form'];
    }
    $form = hd_native_get_form($key);
    if (!$form || !is_array($form)) {
        delete_transient($lockKey);
        return ['ok' => false, 'error' => 'no_form'];
    }
    $form['key'] = $key;

    $norm = hd_native_normalize_tn_phone((string) ($row['phone'] ?? ''));
    if (empty($norm['ok'])) {
        delete_transient($lockKey);
        return ['ok' => false, 'error' => 'bad_phone'];
    }
    $phone = (string) $norm['phone'];
    $nameCheck = hd_native_draft_resolve_name((string) ($row['name'] ?? ''));
    if (empty($nameCheck['ok'])) {
        delete_transient($lockKey);
        return ['ok' => false, 'error' => 'name_short'];
    }
    $name = (string) $nameCheck['name'];

    // Race: phone may have landed as a real lead since eligibility check.
    $existingLead = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . $wpdb->prefix . 'hd_leads WHERE phone = %s LIMIT 1',
        $phone
    ));
    if ($existingLead > 0) {
        delete_transient($lockKey);
        return ['ok' => false, 'error' => 'phone_exists', 'lead_id' => $existingLead];
    }

    // Re-fetch: abort if no longer open (organic convert / concurrent promote).
    $freshStatus = (string) $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM {$table} WHERE id = %d",
        $draftId
    ));
    if ($freshStatus !== 'open') {
        delete_transient($lockKey);
        return ['ok' => false, 'error' => $freshStatus === 'converted' ? 'already_converted' : 'not_open'];
    }

    // Atomic claim: only one worker may promote an open draft.
    $claimed = $wpdb->update(
        $table,
        [
            'status' => 'promoting',
            'updated_at' => current_time('mysql'),
        ],
        [
            'id' => $draftId,
            'status' => 'open',
        ],
        ['%s', '%s'],
        ['%d', '%s']
    );
    if ($claimed !== 1) {
        delete_transient($lockKey);
        return ['ok' => false, 'error' => 'claim_failed'];
    }

    $draftMeta = [];
    if (!empty($row['meta'])) {
        $decoded = json_decode((string) $row['meta'], true);
        if (is_array($decoded)) {
            $draftMeta = $decoded;
        }
    }

    $pageId = substr(sanitize_text_field((string) ($row['page_id'] ?? '')), 0, 32);
    $tbclid = trim((string) ($row['tbclid'] ?? ''));
    $currentUrl = esc_url_raw((string) ($draftMeta['current_url'] ?? ''));
    if ($currentUrl === '') {
        $currentUrl = home_url('/');
    }
    $ip = substr((string) ($draftMeta['ip'] ?? ''), 0, 64);

    $wpHttpReferer = '';
    $p = wp_parse_url($currentUrl);
    if (!empty($p['path'])) {
        $wpHttpReferer = $p['path'] . (!empty($p['query']) ? '?' . $p['query'] : '');
    }

    $ctx = [
        'current_url' => $currentUrl,
        'refer_url' => '',
        'ip' => $ip,
        'page_id' => $pageId,
        'wp_http_referer' => $wpHttpReferer,
    ];

    $payload = hd_native_build_forminator_payload($form, $name, $phone, $ctx);
    $payload = apply_filters('hd_native_lead_payload', $payload, $form, $ctx);

    $campaign = hd_native_draft_daf_campaign((string) ($draftMeta['utm_campaign'] ?? ''));
    $draftUa = substr(sanitize_text_field((string) ($draftMeta['client_ua'] ?? '')), 0, 512);
    if ($draftUa !== '' && stripos($draftUa, 'WordPress/') === 0) {
        $draftUa = '';
    }
    $meta = [
        'utm_source' => sanitize_text_field((string) ($draftMeta['utm_source'] ?? '')),
        'utm_medium' => sanitize_text_field((string) ($draftMeta['utm_medium'] ?? '')),
        'utm_campaign' => $campaign,
        'utm_content' => sanitize_text_field((string) ($draftMeta['utm_content'] ?? '')),
        'utm_term' => sanitize_text_field((string) ($draftMeta['utm_term'] ?? '')),
        'fbclid' => sanitize_text_field((string) ($draftMeta['fbclid'] ?? '')),
        'tbclid' => sanitize_text_field($tbclid),
        'wp_http_referer' => $wpHttpReferer,
        'project_id' => $form['project_id'] ?? '',
        'form_key' => $key,
        'payload' => $payload,
        'page_id' => $pageId,
        'draft_promoted' => 1,
        'draft_id' => $draftId,
        'via' => 'draft_auto',
        // Prefer visitor UA saved at draft beacon time — never cron WordPress UA.
        'client_ip' => substr(sanitize_text_field((string) ($draftMeta['ip'] ?? $ip)), 0, 64),
        'client_ua' => $draftUa,
    ];
    if ($tbclid !== '' && function_exists('tb_tracker_table')) {
        global $wpdb;
        $clickRow = $wpdb->get_row($wpdb->prepare(
            'SELECT fbp, fbc, user_agent FROM ' . tb_tracker_table('clicks') . ' WHERE click_id = %s LIMIT 1',
            $tbclid
        ), ARRAY_A);
        if (is_array($clickRow)) {
            if (empty($meta['fbp']) && !empty($clickRow['fbp'])) {
                $meta['fbp'] = substr(sanitize_text_field((string) $clickRow['fbp']), 0, 128);
            }
            if (empty($meta['fbc']) && !empty($clickRow['fbc'])) {
                $meta['fbc'] = substr(sanitize_text_field((string) $clickRow['fbc']), 0, 255);
            }
            if ($meta['client_ua'] === '' && !empty($clickRow['user_agent'])) {
                $meta['client_ua'] = substr(sanitize_text_field((string) $clickRow['user_agent']), 0, 512);
            }
        }
    }
    $meta = apply_filters('hd_native_lead_meta', $meta, $form, $ctx);
    // Cron enrich must not leave WordPress/* as client_ua for CAPI.
    if (!empty($meta['client_ua']) && stripos((string) $meta['client_ua'], 'WordPress/') === 0) {
        $meta['client_ua'] = $draftUa;
        if ($meta['client_ua'] === '' && $tbclid !== '' && function_exists('tb_tracker_table')) {
            global $wpdb;
            $uaClick = (string) $wpdb->get_var($wpdb->prepare(
                'SELECT user_agent FROM ' . tb_tracker_table('clicks') . ' WHERE click_id = %s LIMIT 1',
                $tbclid
            ));
            $meta['client_ua'] = substr(sanitize_text_field($uaClick), 0, 512);
        }
    }
    if (isset($meta['payload']) && is_array($meta['payload'])) {
        $payload = $meta['payload'];
    } else {
        $meta['payload'] = $payload;
    }
    // Keep DAF + draft provenance even if filters strip them.
    $meta['utm_campaign'] = $campaign;
    $meta['draft_promoted'] = 1;
    $meta['draft_id'] = $draftId;
    $meta['via'] = 'draft_auto';
    $meta['tbclid'] = $tbclid;
    if (!isset($meta['payload']) || !is_array($meta['payload'])) {
        $meta['payload'] = is_array($payload) ? $payload : [];
    }
    $meta['payload']['tbclid'] = $tbclid;
    $meta['payload']['hidden_7'] = $tbclid;

    if (function_exists('hd_native_resolve_product_name')) {
        $clickForProduct = null;
        if ($tbclid !== '' && function_exists('hd_native_tracker_snapshot')) {
            $snapTmp = hd_native_tracker_snapshot($tbclid);
            $clickForProduct = is_array($snapTmp['click'] ?? null) ? $snapTmp['click'] : null;
        }
        $meta['product'] = hd_native_resolve_product_name($form, $meta, $clickForProduct, $ctx);
    }

    if (function_exists('hd_native_build_tracking_comment')) {
        $meta['trackingcomment'] = hd_native_build_tracking_comment($tbclid, $ctx, $meta);
    } else {
        $meta['trackingcomment'] = '';
    }
    if ($tbclid !== '' && function_exists('tb_tracker_get_click_geo')) {
        $geoSnap = tb_tracker_get_click_geo($tbclid);
        if (is_array($geoSnap)) {
            $meta['geo'] = $geoSnap;
        }
    }

    // Final race check before insert (organic submit may have won).
    $existingLead2 = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . $wpdb->prefix . 'hd_leads WHERE phone = %s LIMIT 1',
        $phone
    ));
    if ($existingLead2 > 0) {
        $cur = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$table} WHERE id = %d",
            $draftId
        ));
        // Hook may already have converted; if still promoting, attach existing lead.
        if ($cur === 'promoting') {
            $wpdb->update(
                $table,
                [
                    'status' => 'converted',
                    'lead_id' => $existingLead2,
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => $draftId],
                ['%s', '%d', '%s'],
                ['%d']
            );
        }
        delete_transient($lockKey);
        return ['ok' => false, 'error' => 'phone_exists', 'lead_id' => $existingLead2];
    }

    $stillPromoting = (string) $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM {$table} WHERE id = %d",
        $draftId
    ));
    if ($stillPromoting !== 'promoting') {
        delete_transient($lockKey);
        return ['ok' => false, 'error' => $stillPromoting === 'converted' ? 'already_converted' : 'not_open'];
    }

    $ins = $wpdb->insert(
        $wpdb->prefix . 'hd_leads',
        [
            'form_key' => $key,
            'name' => $name,
            'phone' => $phone,
            'meta' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
            'created_at' => current_time('mysql'),
        ],
        ['%s', '%s', '%s', '%s', '%s']
    );
    $leadId = (int) $wpdb->insert_id;
    if (!$ins || $leadId <= 0) {
        $curFail = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$table} WHERE id = %d",
            $draftId
        ));
        if ($curFail === 'promoting') {
            $wpdb->update(
                $table,
                ['status' => 'open', 'updated_at' => current_time('mysql')],
                ['id' => $draftId],
                ['%s', '%s'],
                ['%d']
            );
        }
        delete_transient($lockKey);
        return ['ok' => false, 'error' => 'insert_fail'];
    }

    do_action('hd_native_lead_saved', $leadId, $form, $ctx, $meta);

    // Force converted if hook missed (e.g. phone mismatch).
    $draftStatus = (string) $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM {$table} WHERE id = %d",
        $draftId
    ));
    if ($draftStatus !== 'converted') {
        $wpdb->update(
            $table,
            [
                'status' => 'converted',
                'lead_id' => $leadId,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $draftId],
            ['%s', '%d', '%s'],
            ['%d']
        );
    }

    $espoOk = false;
    if (empty($opts['skip_espo']) && function_exists('hd_native_build_espo_payload') && function_exists('hd_native_espo_attempt_lead')) {
        try {
            $espoBody = hd_native_build_espo_payload($name, $phone, $form, $ctx, $meta);
            $espoBody = apply_filters('hd_native_espo_payload', $espoBody, $form, $ctx, $meta, $leadId);
            $meta['espo_body'] = $espoBody;
            $meta['espo'] = [
                'ok' => false,
                'pending' => true,
                'code' => 0,
                'at' => current_time('mysql'),
                'attempts' => 0,
                'via' => 'draft_auto',
            ];
            if (function_exists('hd_native_espo_persist_meta')) {
                hd_native_espo_persist_meta($leadId, $meta);
            } else {
                $wpdb->update(
                    $wpdb->prefix . 'hd_leads',
                    ['meta' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE)],
                    ['id' => $leadId],
                    ['%s'],
                    ['%d']
                );
            }
            $espoOk = (bool) hd_native_espo_attempt_lead($leadId, 'draft_auto');
            if (!$espoOk && function_exists('hd_native_espo_queue_retry')) {
                hd_native_espo_queue_retry($leadId);
            }
        } catch (Throwable $e) {
            $meta['espo'] = [
                'ok' => false,
                'pending' => true,
                'code' => 0,
                'at' => current_time('mysql'),
                'attempts' => 0,
                'via' => 'draft_auto_exception',
                'err' => substr($e->getMessage(), 0, 180),
            ];
            if (function_exists('hd_native_espo_persist_meta')) {
                hd_native_espo_persist_meta($leadId, $meta);
            }
            if (function_exists('hd_native_espo_queue_retry')) {
                hd_native_espo_queue_retry($leadId);
            }
        }
    }

    delete_transient($lockKey);
    return ['ok' => true, 'lead_id' => $leadId, 'espo_ok' => $espoOk];
}

/**
 * Scan open drafts and promote eligible ones.
 *
 * @param array{limit?:int,dry_run?:bool} $opts
 * @return array<string,mixed>
 */
function hd_native_draft_promote_eligible(array $opts = []): array
{
    global $wpdb;
    $limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 50;
    $dryRun = !empty($opts['dry_run']);
    $table = hd_native_drafts_table();

    hd_native_draft_reset_stuck_promoting();
    hd_native_draft_close_duplicate_phones(400);

    $crmKeys = hd_native_draft_crm_keys();
    if ($crmKeys === []) {
        return [
            'ok' => true,
            'dry_run' => $dryRun,
            'scanned' => 0,
            'eligible' => 0,
            'promoted' => 0,
            'leads' => [],
            'skipped' => [],
            'errors' => [],
        ];
    }

    $inList = implode(',', array_fill(0, count($crmKeys), '%s'));
    $leadsTable = $wpdb->prefix . 'hd_leads';
    // Idle prefilter (updated_at older than 5 min). Eligibility still re-checks idle/engagement.
    // Skip phones already in hd_leads — that pile (phone_exists) starved newer eligible drafts (DAF=0).
    // Newest first so a leftover skip cannot block fresh DAF again.
    $fetch = min(500, max($limit * 10, 200));
    $idleFloor = date('Y-m-d H:i:s', current_time('timestamp') - 5 * MINUTE_IN_SECONDS);
    $sql = $wpdb->prepare(
        "SELECT d.* FROM {$table} d
         WHERE d.status = 'open' AND d.form_key IN ({$inList}) AND d.updated_at < %s
           AND CHAR_LENGTH(TRIM(d.name)) >= 3
           AND NOT EXISTS (
             SELECT 1 FROM {$leadsTable} l
             WHERE l.phone = d.phone AND d.phone <> ''
           )
         ORDER BY d.updated_at DESC
         LIMIT %d",
        ...array_merge($crmKeys, [$idleFloor, $fetch])
    );
    $rows = $wpdb->get_results($sql, ARRAY_A);
    if (!is_array($rows)) {
        $rows = [];
    }

    $out = [
        'ok' => true,
        'dry_run' => $dryRun,
        'scanned' => count($rows),
        'eligible' => 0,
        'promoted' => 0,
        'leads' => [],
        'skipped' => [],
        'errors' => [],
    ];

    foreach ($rows as $row) {
        if ($out['eligible'] >= $limit && !$dryRun) {
            break;
        }
        if ($dryRun && $out['eligible'] >= $limit) {
            break;
        }
        $id = (int) ($row['id'] ?? 0);
        $elig = hd_native_draft_is_eligible($row);
        if (empty($elig['ok'])) {
            $reason = (string) ($elig['reason'] ?? 'skip');
            if ($reason === 'phone_exists' && $id > 0) {
                $phone = (string) ($row['phone'] ?? '');
                $existingLead = $phone !== '' ? (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$leadsTable} WHERE phone = %s LIMIT 1",
                    $phone
                )) : 0;
                if ($existingLead > 0) {
                    $wpdb->update(
                        $table,
                        [
                            'status' => 'converted',
                            'lead_id' => $existingLead,
                            'updated_at' => current_time('mysql'),
                        ],
                        ['id' => $id, 'status' => 'open'],
                        ['%s', '%d', '%s'],
                        ['%d', '%s']
                    );
                }
            }
            $out['skipped'][] = [
                'draft_id' => $id,
                'reason' => $reason,
                'idle_sec' => isset($elig['idle_sec']) ? (int) $elig['idle_sec'] : null,
            ];
            continue;
        }
        $out['eligible']++;
        if ($dryRun) {
            $out['leads'][] = [
                'draft_id' => $id,
                'phone' => (string) ($row['phone'] ?? ''),
                'form_key' => (string) ($row['form_key'] ?? ''),
                'idle_sec' => isset($elig['idle_sec']) ? (int) $elig['idle_sec'] : null,
                'dry_run' => true,
            ];
            continue;
        }
        $res = hd_native_draft_promote($id);
        if (!empty($res['ok'])) {
            $out['promoted']++;
            $out['leads'][] = [
                'draft_id' => $id,
                'lead_id' => (int) ($res['lead_id'] ?? 0),
                'espo_ok' => !empty($res['espo_ok']),
            ];
        } else {
            $out['errors'][] = [
                'draft_id' => $id,
                'error' => (string) ($res['error'] ?? 'promote_fail'),
                'lead_id' => isset($res['lead_id']) ? (int) $res['lead_id'] : null,
            ];
        }
    }

    return $out;
}

/** Custom WP-Cron interval: every minute (aim: send within ~2 min after 5 min idle gate). */
add_filter('cron_schedules', static function ($schedules) {
    if (!is_array($schedules)) {
        $schedules = [];
    }
    if (!isset($schedules['hd_every_minute'])) {
        $schedules['hd_every_minute'] = [
            'interval' => MINUTE_IN_SECONDS,
            'display' => 'Every minute (HD draft auto-promote)',
        ];
    }
    return $schedules;
});

add_action('init', static function () {
    $hook = 'hd_native_draft_promote_cron';
    $event = function_exists('wp_get_scheduled_event') ? wp_get_scheduled_event($hook) : false;
    $needsReschedule = true;
    if (is_object($event) && isset($event->schedule) && $event->schedule === 'hd_every_minute') {
        $needsReschedule = false;
    }
    if ($needsReschedule) {
        while ($ts = wp_next_scheduled($hook)) {
            wp_unschedule_event($ts, $hook);
        }
        wp_schedule_event(time() + 30, 'hd_every_minute', $hook);
    }
}, 25);

add_action('hd_native_draft_promote_cron', static function () {
    if (get_transient('hd_draft_promote_lock')) {
        return;
    }
    set_transient('hd_draft_promote_lock', 1, 5 * MINUTE_IN_SECONDS);
    try {
        hd_native_draft_promote_eligible(['limit' => 40]);
    } finally {
        delete_transient('hd_draft_promote_lock');
    }
});

/** Client beacon — only binds forms with data-hd-draft="1". */
add_action('wp_footer', static function () {
    if (is_admin()) {
        return;
    }
    static $done = false;
    if ($done) {
        return;
    }
    // Only print if a pilot form is on the page (cheap DOM flag set in markup).
    // Always print small script; it no-ops if no [data-hd-draft] nodes.
    $done = true;
    $endpoint = esc_url_raw(rest_url('hd/v1/lead-draft'));
    $nonce = wp_create_nonce('hd_lead_draft');
    ?>
<script>
(function(){
  var ENDPOINT=<?php echo wp_json_encode($endpoint); ?>;
  var NONCE=<?php echo wp_json_encode($nonce); ?>;
  var lastSent={};
  function cookieGet(n){
    try{var m=document.cookie.match(new RegExp('(?:^|; )'+n+'=([^;]*)'));return m?decodeURIComponent(m[1]):'';}catch(e){return '';}
  }
  function qs(n){
    try{return new URLSearchParams(location.search).get(n)||'';}catch(e){return '';}
  }
  function normPhone(raw){
    if(window.hdNativeNormalizeTnPhone) return window.hdNativeNormalizeTnPhone(raw);
    var d=String(raw||'').replace(/\D+/g,'');
    if(d.indexOf('00216')===0) d=d.slice(5);
    else if(d.indexOf('216')===0 && d.length>8) d=d.slice(3);
    while(d.length>8 && d.charAt(0)==='0') d=d.slice(1);
    if(d.length>8) d=d.slice(-8);
    if(!/^\d{8}$/.test(d)||/^(\d)\1{7}$/.test(d)) return {ok:false,phone:''};
    return {ok:true,phone:d};
  }
  function sendDraft(form, via){
    try{
      if(!form || form.getAttribute('data-hd-draft')!=='1') return;
      var phoneEl=form.querySelector('[name="lead_phone"]');
      if(!phoneEl) return;
      var r=normPhone(phoneEl.value);
      if(!r.ok) return;
      var key=(form.getAttribute('data-hd-form-key')||(form.querySelector('[name="form_key"]')||{}).value||'').toString();
      var sig=key+'|'+r.phone;
      var now=Date.now();
      if(lastSent[sig] && (now-lastSent[sig])<4000) return;
      lastSent[sig]=now;
      var nameEl=form.querySelector('[name="lead_name"]');
      var body=JSON.stringify({
        consent:1,
        n:NONCE,
        form_key:key,
        phone:r.phone,
        name:nameEl?String(nameEl.value||'').trim():'',
        tbclid:(form.querySelector('[name="tbclid"]')||{}).value||cookieGet('tbclid')||qs('tbclid')||'',
        page_id:(form.querySelector('[name="page_id"]')||{}).value||'',
        utm_source:(form.querySelector('[name="utm_source"]')||{}).value||qs('utm_source')||'',
        utm_medium:(form.querySelector('[name="utm_medium"]')||{}).value||qs('utm_medium')||'',
        utm_campaign:(form.querySelector('[name="utm_campaign"]')||{}).value||qs('utm_campaign')||'',
        utm_content:(form.querySelector('[name="utm_content"]')||{}).value||qs('utm_content')||'',
        utm_term:(form.querySelector('[name="utm_term"]')||{}).value||qs('utm_term')||'',
        fbclid:(form.querySelector('[name="fbclid"]')||{}).value||qs('fbclid')||'',
        current_url:location.href,
        via:via||'beacon'
      });
      if(navigator.sendBeacon){
        try{
          var ok=navigator.sendBeacon(ENDPOINT, new Blob([body],{type:'application/json'}));
          if(ok) return;
        }catch(e0){}
      }
      fetch(ENDPOINT,{method:'POST',headers:{'Content-Type':'application/json'},body:body,keepalive:true,credentials:'same-origin'}).catch(function(){});
    }catch(e){}
  }
  function bind(form){
    if(!form || form.getAttribute('data-hd-draft-bound')) return;
    form.setAttribute('data-hd-draft-bound','1');
    var phone=form.querySelector('[name="lead_phone"]');
    if(!phone) return;
    var t=null;
    phone.addEventListener('blur', function(){ sendDraft(form,'blur'); });
    phone.addEventListener('input', function(){
      if(t) clearTimeout(t);
      t=setTimeout(function(){ sendDraft(form,'debounce'); }, 800);
    });
    document.addEventListener('visibilitychange', function(){
      if(document.visibilityState==='hidden') sendDraft(form,'hide');
    });
    window.addEventListener('pagehide', function(){ sendDraft(form,'pagehide'); });
  }
  function scan(){
    document.querySelectorAll('form.hd-lead-form[data-hd-draft="1"]').forEach(bind);
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', scan);
  else scan();
  setTimeout(scan, 1500);
})();
</script>
    <?php
}, 98);
