<?php
/**
 * HD Native Leads — Espo CRM status sync (lookup by cWtid = tbclid).
 *
 * Soft waves: cron processes a small batch per tick with API delay + cursor
 * round-robin over still-"open" leads. Manual Sync / WP-CLI use the same engine.
 *
 * Auth: constant HD_NATIVE_ESPO_API_KEY (wp-config) preferred;
 * fallback option hd_native_espo_api_key (autoload=false). Never log the key.
 *
 * Status matching is slug-normalized (case / spaces / underscores ignored).
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('HD_NATIVE_ESPO_API_BASE')) {
    define('HD_NATIVE_ESPO_API_BASE', 'https://herbalcrm.hvserver.site/api/v1/');
}

define('HD_NATIVE_ESPO_API_KEY_OPTION', 'hd_native_espo_api_key');
define('HD_NATIVE_ESPO_STATUS_CRON', 'hd_native_espo_status_sync');

/** Settings (autoload=false). */
define('HD_NATIVE_ESPO_SYNC_OPT_ENABLED', 'hd_native_espo_sync_cron_enabled');
define('HD_NATIVE_ESPO_SYNC_OPT_INTERVAL', 'hd_native_espo_sync_interval');
define('HD_NATIVE_ESPO_SYNC_OPT_BATCH', 'hd_native_espo_sync_batch');
define('HD_NATIVE_ESPO_SYNC_OPT_DELAY', 'hd_native_espo_sync_delay_ms');
define('HD_NATIVE_ESPO_SYNC_OPT_CURSOR', 'hd_native_espo_sync_cursor');
define('HD_NATIVE_ESPO_SYNC_OPT_LAST', 'hd_native_espo_status_last_run');
define('HD_NATIVE_ESPO_SYNC_OPT_LOAD', 'hd_native_espo_sync_load_max');
define('HD_NATIVE_ESPO_SYNC_OPT_FROM_HOUR', 'hd_native_espo_sync_from_hour');
define('HD_NATIVE_ESPO_SYNC_OPT_TO_HOUR', 'hd_native_espo_sync_to_hour');

/**
 * Allowed cron intervals in minutes (short + long).
 *
 * @return list<int>
 */
function hd_native_espo_sync_allowed_intervals(): array
{
    return [5, 10, 15, 30, 60, 120, 360, 720, 1440];
}

/**
 * Human label for interval minutes.
 */
function hd_native_espo_sync_interval_label(int $minutes): string
{
    if ($minutes < 60) {
        return $minutes . ' min';
    }
    $h = (int) round($minutes / 60);
    return $h === 1 ? '1 h' : ($h . ' h');
}

/**
 * Canonical labels still open for Espo polling (keep syncing).
 *
 * OPEN (sync): New, Processing, In Process, Assigned, Sent, Being Validated,
 *              Recall, Preparing, Validated, Reported, Confirmed, Waiting for Return
 * FINAL (stop): Converted, Recycled, Dead, Closed, Lost, Duplicate, Junk, …
 * FUNNEL POLL: keep syncing while funnel_stage=confirmed until delivered, OR while
 *              local status is Confirmed/Delivered but funnel meta not yet caught up
 *              (Espo "Delivered" is not open; dates may land after status change).
 *
 * @return list<string>
 */
function hd_native_espo_open_statuses(): array
{
    return [
        'New',
        'Processing',
        'In Process',
        'Assigned',
        'Sent',
        'Being Validated',
        'Recall',
        'Preparing',
        'Validated',
        'Reported',
        'Confirmed',
        'Waiting for Return',
    ];
}

/**
 * Known final Espo statuses (documentation — stop sync). Whitelist enforces open.
 *
 * @return list<string>
 */
function hd_native_espo_final_statuses(): array
{
    return [
        'Converted',
        'Recycled',
        'Dead',
        'Closed',
        'Lost',
        'Duplicate',
        'Junk',
    ];
}

/**
 * Normalize status for comparison: lowercase, strip spaces / underscores / hyphens.
 */
function hd_native_espo_status_slug(string $status): string
{
    $status = strtolower(trim($status));
    $status = preg_replace('/[\s_\-]+/', '', $status) ?? '';
    return $status;
}

/**
 * Soft-wave sync settings (sanitized defaults).
 *
 * @return array{enabled:bool,interval:int,batch:int,delay_ms:int,load_max:float,cursor:int,from_hour:int,to_hour:int}
 */
function hd_native_espo_sync_settings(): array
{
    $interval = (int) get_option(HD_NATIVE_ESPO_SYNC_OPT_INTERVAL, 10);
    if (!in_array($interval, hd_native_espo_sync_allowed_intervals(), true)) {
        $interval = 10;
    }
    $batch = (int) get_option(HD_NATIVE_ESPO_SYNC_OPT_BATCH, 25);
    $batch = max(5, min(100, $batch));
    $delay = (int) get_option(HD_NATIVE_ESPO_SYNC_OPT_DELAY, 150);
    $delay = max(0, min(2000, $delay));
    $enabled = (int) get_option(HD_NATIVE_ESPO_SYNC_OPT_ENABLED, 1) === 1;
    $loadMax = (float) get_option(HD_NATIVE_ESPO_SYNC_OPT_LOAD, 25);
    if ($loadMax < 5) {
        $loadMax = 5;
    }
    if ($loadMax > 100) {
        $loadMax = 100;
    }
    $cursor = max(0, (int) get_option(HD_NATIVE_ESPO_SYNC_OPT_CURSOR, 0));
    $fromHour = max(0, min(23, (int) get_option(HD_NATIVE_ESPO_SYNC_OPT_FROM_HOUR, 0)));
    $toHour = max(0, min(23, (int) get_option(HD_NATIVE_ESPO_SYNC_OPT_TO_HOUR, 23)));

    return [
        'enabled' => $enabled,
        'interval' => $interval,
        'batch' => $batch,
        'delay_ms' => $delay,
        'load_max' => $loadMax,
        'cursor' => $cursor,
        'from_hour' => $fromHour,
        'to_hour' => $toHour,
    ];
}

/**
 * Persist settings (autoload false). Returns sanitized settings.
 *
 * @param array<string,mixed> $input
 * @return array{enabled:bool,interval:int,batch:int,delay_ms:int,load_max:float,cursor:int,from_hour:int,to_hour:int}
 */
function hd_native_espo_sync_save_settings(array $input): array
{
    $enabled = !empty($input['enabled']);
    $interval = (int) ($input['interval'] ?? 10);
    if (!in_array($interval, hd_native_espo_sync_allowed_intervals(), true)) {
        $interval = 10;
    }
    $batch = max(5, min(100, (int) ($input['batch'] ?? 25)));
    $delay = max(0, min(2000, (int) ($input['delay_ms'] ?? 150)));
    $loadMax = (float) ($input['load_max'] ?? 25);
    $loadMax = max(5.0, min(100.0, $loadMax));
    $fromHour = max(0, min(23, (int) ($input['from_hour'] ?? 0)));
    $toHour = max(0, min(23, (int) ($input['to_hour'] ?? 23)));

    update_option(HD_NATIVE_ESPO_SYNC_OPT_ENABLED, $enabled ? 1 : 0, false);
    update_option(HD_NATIVE_ESPO_SYNC_OPT_INTERVAL, $interval, false);
    update_option(HD_NATIVE_ESPO_SYNC_OPT_BATCH, $batch, false);
    update_option(HD_NATIVE_ESPO_SYNC_OPT_DELAY, $delay, false);
    update_option(HD_NATIVE_ESPO_SYNC_OPT_LOAD, $loadMax, false);
    update_option(HD_NATIVE_ESPO_SYNC_OPT_FROM_HOUR, $fromHour, false);
    update_option(HD_NATIVE_ESPO_SYNC_OPT_TO_HOUR, $toHour, false);

    hd_native_espo_status_cron_reschedule(true);

    return hd_native_espo_sync_settings();
}

/**
 * Cron schedule slug for a given interval (minutes).
 */
function hd_native_espo_sync_schedule_slug(int $minutes): string
{
    return 'hd_native_espo_every_' . $minutes . '_minutes';
}

/**
 * Whether "now" (WP timezone) is inside the configured hour window.
 * Supports overnight wrap (e.g. 22 → 6). from=0 to=23 = always.
 */
function hd_native_espo_sync_in_time_window(?DateTimeInterface $now = null): bool
{
    $settings = hd_native_espo_sync_settings();
    $from = (int) $settings['from_hour'];
    $to = (int) $settings['to_hour'];
    if ($from === 0 && $to === 23) {
        return true;
    }
    if ($now === null) {
        $now = current_datetime();
    }
    $hour = (int) $now->format('G');
    if ($from <= $to) {
        return $hour >= $from && $hour <= $to;
    }
    // Overnight wrap: e.g. 22–6 → hour>=22 OR hour<=6.
    return $hour >= $from || $hour <= $to;
}

/**
 * Short UI label for the active window.
 *
 * @param array<string,mixed> $settings
 */
function hd_native_espo_sync_window_label(array $settings = []): string
{
    if ($settings === []) {
        $settings = hd_native_espo_sync_settings();
    }
    $from = (int) $settings['from_hour'];
    $to = (int) $settings['to_hour'];
    $tzName = wp_timezone()->getName();
    if ($from === 0 && $to === 23) {
        return '0h–23h (toujours) · ' . $tzName;
    }
    if ($from <= $to) {
        return sprintf('%02dh–%02dh · %s', $from, $to, $tzName);
    }
    return sprintf('%02dh→%02dh (nuit) · %s', $from, $to, $tzName);
}

/**
 * Soft skip when 1-minute load average is very high.
 */
function hd_native_espo_sync_load_too_high(): bool
{
    if (!function_exists('sys_getloadavg')) {
        return false;
    }
    $load = @sys_getloadavg();
    if (!is_array($load) || !isset($load[0])) {
        return false;
    }
    $max = (float) hd_native_espo_sync_settings()['load_max'];
    return (float) $load[0] > $max;
}

/**
 * API key — constant first, then non-autoloaded option. Empty = sync disabled.
 */
function hd_native_espo_api_key(): string
{
    if (defined('HD_NATIVE_ESPO_API_KEY') && is_string(HD_NATIVE_ESPO_API_KEY)) {
        $k = trim(HD_NATIVE_ESPO_API_KEY);
        if ($k !== '') {
            return $k;
        }
    }
    $opt = get_option(HD_NATIVE_ESPO_API_KEY_OPTION, '');
    return is_string($opt) ? trim($opt) : '';
}

/**
 * Espo REST base URL (trailing slash).
 */
function hd_native_espo_api_base(): string
{
    $base = defined('HD_NATIVE_ESPO_API_BASE') ? (string) HD_NATIVE_ESPO_API_BASE : '';
    $base = rtrim(trim($base), '/') . '/';
    if (!preg_match('#^https?://#i', $base)) {
        return 'https://herbalcrm.hvserver.site/api/v1/';
    }
    return $base;
}

/**
 * Whether a local espo_status still warrants Espo polling.
 */
function hd_native_espo_status_is_open(?string $status): bool
{
    $status = trim((string) $status);
    if ($status === '') {
        return true;
    }
    $slug = hd_native_espo_status_slug($status);
    if ($slug === '') {
        return true;
    }
    static $openSlugs = null;
    if ($openSlugs === null) {
        $openSlugs = [];
        foreach (hd_native_espo_open_statuses() as $open) {
            $openSlugs[hd_native_espo_status_slug($open)] = true;
        }
        foreach (['BeingValidated', 'WaitingForReturn', 'InProcess', 'waitingforreturn', 'beingvalidated'] as $alias) {
            $openSlugs[hd_native_espo_status_slug($alias)] = true;
        }
    }
    return isset($openSlugs[$slug]);
}

/**
 * Fetch Espo Lead(s) by cWtid (= tbclid).
 *
 * @return array<string,mixed>|null
 */
function hd_native_espo_fetch_lead_by_wtid(string $tbclid): ?array
{
    $tbclid = trim($tbclid);
    if ($tbclid === '') {
        return null;
    }
    $key = hd_native_espo_api_key();
    if ($key === '') {
        return null;
    }

    $url = add_query_arg(
        [
            'where[0][type]' => 'equals',
            'where[0][attribute]' => 'cWtid',
            'where[0][value]' => $tbclid,
            // Funnel dates: cDateConfirmation → confirmed; cDeliveredDate → delivered.
            'select' => 'id,cLeadid,status,cWtid,cFbclid,cVisualid,cFbpostid,cTrackingcomment,cFbprofile,cDateConfirmation,cDeliveredDate,createdAt,modifiedAt',
            'maxSize' => 5,
            'orderBy' => 'createdAt',
            'order' => 'desc',
        ],
        hd_native_espo_api_base() . 'Lead'
    );

    $response = wp_remote_get($url, [
        'timeout' => 12,
        'headers' => [
            'Accept' => 'application/json',
            'X-Api-Key' => $key,
            'User-Agent' => 'HD-Native-Leads/EspoStatusSync',
        ],
    ]);

    if (is_wp_error($response)) {
        error_log('[hd-native-espo-status] fetch error wtid=' . $tbclid . ' msg=' . $response->get_error_message());
        return null;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    if ($code < 200 || $code >= 300) {
        error_log('[hd-native-espo-status] HTTP ' . $code . ' wtid=' . $tbclid);
        return null;
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return null;
    }
    $total = (int) ($data['total'] ?? 0);
    $list = isset($data['list']) && is_array($data['list']) ? $data['list'] : [];
    if ($total <= 0 || $list === []) {
        return null;
    }
    if (count($list) > 1) {
        error_log('[hd-native-espo-status] duplicate leads for wtid=' . $tbclid . ' count=' . count($list) . ' — using most recent');
    }
    $lead = $list[0];
    return is_array($lead) ? $lead : null;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function hd_native_espo_decode_lead_meta(array $row): array
{
    $meta = json_decode((string) ($row['meta'] ?? ''), true);
    return is_array($meta) ? $meta : [];
}

/**
 * Normalize Espo datetime / date string to ISO-ish (trim) or empty.
 */
function hd_native_espo_normalize_date_value($raw): string
{
    if ($raw === null || $raw === false) {
        return '';
    }
    if (is_array($raw)) {
        // Espo sometimes returns date objects — ignore non-scalars.
        return '';
    }
    $s = trim((string) $raw);
    if ($s === '' || strcasecmp($s, 'null') === 0) {
        return '';
    }
    return $s;
}

/**
 * Funnel stage from Espo date fields (delivered > confirmed). Dates are source of truth.
 *
 * @return ''|'confirmed'|'delivered'
 */
function hd_native_espo_funnel_stage_from_dates(string $confirmedAt, string $deliveredAt): string
{
    if ($deliveredAt !== '') {
        return 'delivered';
    }
    if ($confirmedAt !== '') {
        return 'confirmed';
    }
    return '';
}

/**
 * Fallback funnel stage from Espo status slug when dates are empty
 * (call center may set Confirmed/Delivered before filling date fields).
 *
 * @return ''|'confirmed'|'delivered'
 */
function hd_native_espo_funnel_stage_from_status(string $status): string
{
    $slug = hd_native_espo_status_slug($status);
    if ($slug === 'delivered') {
        return 'delivered';
    }
    if ($slug === 'confirmed') {
        return 'confirmed';
    }
    return '';
}

/**
 * Rank for stage upgrades (null/'' < confirmed < delivered).
 */
function hd_native_espo_funnel_stage_rank(string $stage): int
{
    $stage = strtolower(trim($stage));
    if ($stage === 'delivered') {
        return 2;
    }
    if ($stage === 'confirmed') {
        return 1;
    }
    return 0;
}

/**
 * Keep polling after CRM status is final until funnel_stage=delivered
 * (Delivered date may land after status leaves the open whitelist).
 *
 * @param array<string,mixed> $meta
 */
function hd_native_espo_needs_funnel_poll(array $meta): bool
{
    $stage = strtolower(trim((string) ($meta['funnel_stage'] ?? '')));
    if ($stage === 'delivered') {
        return false;
    }
    // Awaiting delivered date after confirmed (or confirmed date stored).
    if ($stage === 'confirmed') {
        return true;
    }
    if (hd_native_espo_normalize_date_value($meta['espo_confirmed_at'] ?? '') !== ''
        && hd_native_espo_normalize_date_value($meta['espo_delivered_at'] ?? '') === '') {
        return true;
    }
    // Confirmed/Delivered already on local status but funnel meta never applied
    // (synced before funnel deploy, or Delivered left the open whitelist).
    $statusStage = hd_native_espo_funnel_stage_from_status((string) ($meta['espo_status'] ?? ''));
    if ($statusStage !== ''
        && hd_native_espo_funnel_stage_rank($statusStage) > hd_native_espo_funnel_stage_rank($stage)) {
        return true;
    }
    return false;
}

/**
 * @param array<string,mixed> $espoLead
 * @return array{ok:bool,status:string,espo_lead_id:string,funnel_stage:string,funnel_upgraded:bool}
 */
function hd_native_espo_apply_status_to_lead(int $leadId, array $espoLead): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'hd_leads';
    $row = $wpdb->get_row($wpdb->prepare("SELECT id, meta FROM {$table} WHERE id = %d", $leadId), ARRAY_A);
    if (!$row) {
        return ['ok' => false, 'status' => '', 'espo_lead_id' => '', 'funnel_stage' => '', 'funnel_upgraded' => false];
    }
    $meta = hd_native_espo_decode_lead_meta($row);
    $prevStage = strtolower(trim((string) ($meta['funnel_stage'] ?? '')));
    $prevConfirmed = hd_native_espo_normalize_date_value($meta['espo_confirmed_at'] ?? '');
    $prevDelivered = hd_native_espo_normalize_date_value($meta['espo_delivered_at'] ?? '');
    $status = trim((string) ($espoLead['status'] ?? ''));
    $espoId = trim((string) ($espoLead['id'] ?? ''));
    $cLeadid = trim((string) ($espoLead['cLeadid'] ?? ''));

    $confirmedAt = hd_native_espo_normalize_date_value($espoLead['cDateConfirmation'] ?? '');
    $deliveredAt = hd_native_espo_normalize_date_value($espoLead['cDeliveredDate'] ?? '');
    // Do not erase known dates when a later poll omits/nulls them.
    if ($confirmedAt === '' && $prevConfirmed !== '') {
        $confirmedAt = $prevConfirmed;
    }
    if ($deliveredAt === '' && $prevDelivered !== '') {
        $deliveredAt = $prevDelivered;
    }

    // Dates first; status slug only if dates still empty (Confirmed sans date → confirmed).
    $newStage = hd_native_espo_funnel_stage_from_dates($confirmedAt, $deliveredAt);
    if ($newStage === '') {
        $newStage = hd_native_espo_funnel_stage_from_status($status);
    }
    // Never downgrade funnel_stage.
    if (hd_native_espo_funnel_stage_rank($newStage) < hd_native_espo_funnel_stage_rank($prevStage)) {
        $newStage = $prevStage;
    }
    $upgraded = hd_native_espo_funnel_stage_rank($newStage) > hd_native_espo_funnel_stage_rank($prevStage);

    $meta['espo_status'] = $status;
    $meta['espo_lead_id'] = $espoId;
    $meta['espo_cleadid'] = $cLeadid;
    $meta['espo_synced_at'] = current_time('mysql');
    // Separate Meta funnel meta — do not overwrite / confuse with espo_status.
    $meta['funnel_stage'] = $newStage;
    $meta['espo_confirmed_at'] = $confirmedAt;
    $meta['espo_delivered_at'] = $deliveredAt;
    if (!empty($espoLead['cTrackingcomment']) && empty($meta['espo_trackingcomment_ro'])) {
        $meta['espo_trackingcomment_ro'] = (string) $espoLead['cTrackingcomment'];
    }

    // Merge-read persist so concurrent CAPI writers keep meta.capi / other keys.
    if (function_exists('hd_native_espo_persist_meta')) {
        hd_native_espo_persist_meta($leadId, $meta);
    } else {
        $wpdb->update(
            $table,
            ['meta' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE)],
            ['id' => $leadId],
            ['%s'],
            ['%d']
        );
    }

    if ($upgraded && function_exists('hd_meta_capi_queue_funnel_events')) {
        hd_meta_capi_queue_funnel_events($leadId, $prevStage, $newStage);
    }

    return [
        'ok' => true,
        'status' => $status,
        'espo_lead_id' => $espoId,
        'funnel_stage' => $newStage,
        'funnel_upgraded' => $upgraded,
    ];
}

/**
 * Build one candidate from a DB row, or null if not syncable.
 *
 * @param array<string,mixed> $row
 * @return array{id:int,tbclid:string,meta:array<string,mixed>}|null
 */
function hd_native_espo_row_to_candidate(array $row): ?array
{
    $meta = hd_native_espo_decode_lead_meta($row);
    $status = isset($meta['espo_status']) ? (string) $meta['espo_status'] : '';
    $open = hd_native_espo_status_is_open($status);
    $funnelPoll = hd_native_espo_needs_funnel_poll($meta);
    if (!$open && !$funnelPoll) {
        return null;
    }
    $tbclid = trim((string) ($meta['tbclid'] ?? ($meta['payload']['tbclid'] ?? $meta['payload']['hidden_7'] ?? '')));
    if ($tbclid === '') {
        return null;
    }
    return [
        'id' => (int) $row['id'],
        'tbclid' => $tbclid,
        'meta' => $meta,
    ];
}

/**
 * Select open leads after cursor (id ASC), with wrap-around for round-robin.
 *
 * @return array{candidates:list<array{id:int,tbclid:string,meta:array<string,mixed>}>,next_cursor:int,wrapped:bool,scanned:int}
 */
function hd_native_espo_status_candidates_wave(int $batch, int $cursor = 0): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'hd_leads';
    $batch = max(1, min(200, $batch));
    $cursor = max(0, $cursor);
    $chunk = max($batch * 6, 40);
    $out = [];
    $wrapped = false;
    $scanned = 0;
    $lastId = $cursor;

    $passes = [
        ['after' => $cursor, 'wrap' => false],
    ];
    // Round-robin wrap only if we started mid-table and still need more.
    if ($cursor > 0) {
        $passes[] = ['after' => 0, 'wrap' => true];
    }

    foreach ($passes as $pass) {
        if ($pass['wrap'] && count($out) >= $batch) {
            break;
        }
        if ($pass['wrap']) {
            $wrapped = true;
        }
        $after = (int) $pass['after'];
        // Cap pages so a huge table never blocks a cron tick.
        for ($page = 0; $page < 8 && count($out) < $batch; $page++) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, meta FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
                    $after,
                    $chunk
                ),
                ARRAY_A
            ) ?: [];
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $scanned++;
                $after = (int) $row['id'];
                $lastId = $after;
                $cand = hd_native_espo_row_to_candidate($row);
                if ($cand === null) {
                    continue;
                }
                // On wrap pass, only ids up to the original cursor (already covered above).
                if ($pass['wrap'] && $cand['id'] > $cursor) {
                    continue;
                }
                $out[] = $cand;
                if (count($out) >= $batch) {
                    break;
                }
            }
            if (count($rows) < $chunk) {
                break;
            }
        }
        if (!$pass['wrap'] && count($out) >= $batch) {
            break;
        }
    }

    $nextCursor = $out !== [] ? (int) $out[count($out) - 1]['id'] : $lastId;

    return [
        'candidates' => $out,
        'next_cursor' => $nextCursor,
        'wrapped' => $wrapped,
        'scanned' => $scanned,
    ];
}

/**
 * Approximate count of open syncable leads (bounded scan — UI hint only).
 */
function hd_native_espo_open_leads_estimate(int $scanLimit = 500): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'hd_leads';
    $scanLimit = max(50, min(2000, $scanLimit));
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, meta FROM {$table} ORDER BY id DESC LIMIT %d",
            $scanLimit
        ),
        ARRAY_A
    ) ?: [];
    $open = 0;
    foreach ($rows as $row) {
        if (hd_native_espo_row_to_candidate($row) !== null) {
            $open++;
        }
    }
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    return [
        'open_in_scan' => $open,
        'scanned' => count($rows),
        'table_total' => $total,
        'truncated' => $total > $scanLimit,
    ];
}

/**
 * Batch sync Espo statuses (soft wave).
 *
 * @param array{limit?:int,batch?:int,delay_ms?:int,advance_cursor?:bool,respect_load?:bool,source?:string} $args
 * @return array{checked:int,updated:int,missing:int,skipped:int,errors:int,wrapped:bool,cursor:int,skipped_load:bool,details:list<array<string,mixed>>}
 */
function hd_native_espo_sync_statuses($limit = 50, array $args = []): array
{
    // BC: hd_native_espo_sync_statuses(50) or hd_native_espo_sync_statuses(['batch'=>25]).
    if (is_array($limit)) {
        $args = $limit;
        $limit = null;
    }

    $settings = hd_native_espo_sync_settings();
    $batch = isset($args['batch']) ? (int) $args['batch'] : (isset($args['limit']) ? (int) $args['limit'] : ($limit !== null ? (int) $limit : $settings['batch']));
    $batch = max(1, min(200, $batch));
    $delayMs = array_key_exists('delay_ms', $args) ? (int) $args['delay_ms'] : $settings['delay_ms'];
    $delayMs = max(0, min(2000, $delayMs));
    $advanceCursor = array_key_exists('advance_cursor', $args) ? (bool) $args['advance_cursor'] : true;
    $respectLoad = !empty($args['respect_load']);
    $respectWindow = !empty($args['respect_window']);
    $source = (string) ($args['source'] ?? 'manual');

    $result = [
        'checked' => 0,
        'updated' => 0,
        'missing' => 0,
        'skipped' => 0,
        'errors' => 0,
        'wrapped' => false,
        'cursor' => $settings['cursor'],
        'skipped_load' => false,
        'skipped_window' => false,
        'details' => [],
    ];

    if (hd_native_espo_api_key() === '') {
        $result['errors'] = 1;
        $result['details'][] = ['error' => 'api_key_missing'];
        return $result;
    }

    if ($respectWindow && !hd_native_espo_sync_in_time_window()) {
        $result['skipped_window'] = true;
        $result['skipped'] = 1;
        update_option(HD_NATIVE_ESPO_SYNC_OPT_LAST, [
            'at' => current_time('mysql'),
            'checked' => 0,
            'updated' => 0,
            'missing' => 0,
            'source' => $source,
            'skipped_window' => true,
            'cursor' => $settings['cursor'],
        ], false);
        return $result;
    }

    if ($respectLoad && hd_native_espo_sync_load_too_high()) {
        $result['skipped_load'] = true;
        $result['skipped'] = 1;
        update_option(HD_NATIVE_ESPO_SYNC_OPT_LAST, [
            'at' => current_time('mysql'),
            'checked' => 0,
            'updated' => 0,
            'missing' => 0,
            'source' => $source,
            'skipped_load' => true,
            'cursor' => $settings['cursor'],
        ], false);
        return $result;
    }

    $wave = hd_native_espo_status_candidates_wave($batch, $settings['cursor']);
    $result['wrapped'] = !empty($wave['wrapped']);
    $candidates = $wave['candidates'];

    foreach ($candidates as $cand) {
        $result['checked']++;
        $lead = hd_native_espo_fetch_lead_by_wtid($cand['tbclid']);
        if ($lead === null) {
            $result['missing']++;
            $result['details'][] = [
                'lead_id' => $cand['id'],
                'tbclid' => $cand['tbclid'],
                'result' => 'missing',
            ];
            global $wpdb;
            $meta = $cand['meta'];
            $meta['espo_synced_at'] = current_time('mysql');
            if (!isset($meta['espo_status']) || $meta['espo_status'] === '') {
                $meta['espo_status'] = '';
            }
            $wpdb->update(
                $wpdb->prefix . 'hd_leads',
                ['meta' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE)],
                ['id' => $cand['id']],
                ['%s'],
                ['%d']
            );
        } else {
            $applied = hd_native_espo_apply_status_to_lead($cand['id'], $lead);
            if ($applied['ok']) {
                $result['updated']++;
                $result['details'][] = [
                    'lead_id' => $cand['id'],
                    'tbclid' => $cand['tbclid'],
                    'result' => 'updated',
                    'status' => $applied['status'],
                    'espo_lead_id' => $applied['espo_lead_id'],
                    'funnel_stage' => (string) ($applied['funnel_stage'] ?? ''),
                    'funnel_upgraded' => !empty($applied['funnel_upgraded']),
                ];
            } else {
                $result['errors']++;
            }
        }
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    if ($advanceCursor) {
        $next = (int) $wave['next_cursor'];
        update_option(HD_NATIVE_ESPO_SYNC_OPT_CURSOR, $next, false);
        $result['cursor'] = $next;
    }

    update_option(HD_NATIVE_ESPO_SYNC_OPT_LAST, [
        'at' => current_time('mysql'),
        'checked' => $result['checked'],
        'updated' => $result['updated'],
        'missing' => $result['missing'],
        'source' => $source,
        'wrapped' => $result['wrapped'],
        'cursor' => $result['cursor'],
        'batch' => $batch,
    ], false);

    return $result;
}

/**
 * Legacy helper kept for callers expecting recent-first without cursor.
 *
 * @return list<array{id:int,tbclid:string,meta:array<string,mixed>}>
 */
function hd_native_espo_status_candidates(int $limit = 80): array
{
    $wave = hd_native_espo_status_candidates_wave($limit, 0);
    return $wave['candidates'];
}

/**
 * Cron callback — soft full pass over open (temporary) statuses within the hour window.
 */
function hd_native_espo_status_sync_cron(): void
{
    $settings = hd_native_espo_sync_settings();
    if (!$settings['enabled']) {
        return;
    }
    if (hd_native_espo_api_key() === '') {
        return;
    }
    hd_native_espo_sync_full_pass([
        'reset_cursor' => true,
        'respect_load' => true,
        'respect_window' => true,
        'max_seconds' => 45,
        'schedule_continue' => true,
        'source' => 'cron',
    ]);
}
add_action(HD_NATIVE_ESPO_STATUS_CRON, 'hd_native_espo_status_sync_cron');

/**
 * Continue a soft full pass after time budget (same rules as cron).
 */
function hd_native_espo_status_sync_continue(): void
{
    $settings = hd_native_espo_sync_settings();
    if (!$settings['enabled']) {
        return;
    }
    if (hd_native_espo_api_key() === '') {
        return;
    }
    hd_native_espo_sync_full_pass([
        'reset_cursor' => false,
        'respect_load' => true,
        'respect_window' => true,
        'max_seconds' => 45,
        'schedule_continue' => true,
        'source' => 'cron_continue',
    ]);
}
add_action('hd_native_espo_status_sync_continue', 'hd_native_espo_status_sync_continue');

/**
 * Soft full pass: several small waves until all open leads are checked once (wrap),
 * or time/load budget exhausted. Never one huge blocking request.
 *
 * @param array{
 *   reset_cursor?:bool,
 *   respect_load?:bool,
 *   respect_window?:bool,
 *   max_seconds?:int,
 *   schedule_continue?:bool,
 *   source?:string,
 *   batch?:int,
 *   delay_ms?:int
 * } $args
 * @return array<string,mixed>
 */
function hd_native_espo_sync_full_pass(array $args = []): array
{
    $settings = hd_native_espo_sync_settings();
    $source = (string) ($args['source'] ?? 'manual');
    $respectLoad = !empty($args['respect_load']);
    $respectWindow = !empty($args['respect_window']);
    $maxSeconds = isset($args['max_seconds']) ? max(10, min(120, (int) $args['max_seconds'])) : 45;
    $scheduleContinue = !empty($args['schedule_continue']);
    $batch = isset($args['batch']) ? (int) $args['batch'] : (int) $settings['batch'];
    $batch = max(5, min(100, $batch));
    $delayMs = array_key_exists('delay_ms', $args) ? (int) $args['delay_ms'] : (int) $settings['delay_ms'];

    $agg = [
        'ok' => true,
        'checked' => 0,
        'updated' => 0,
        'missing' => 0,
        'errors' => 0,
        'waves' => 0,
        'wrapped' => false,
        'timed_out' => false,
        'skipped_window' => false,
        'skipped_load' => false,
        'continued' => false,
        'cursor' => (int) $settings['cursor'],
        'source' => $source,
    ];

    if ($respectWindow && !hd_native_espo_sync_in_time_window()) {
        $agg['skipped_window'] = true;
        update_option(HD_NATIVE_ESPO_SYNC_OPT_LAST, [
            'at' => current_time('mysql'),
            'checked' => 0,
            'updated' => 0,
            'missing' => 0,
            'source' => $source,
            'skipped_window' => true,
            'cursor' => $settings['cursor'],
            'waves' => 0,
            'mode' => 'full_pass',
        ], false);
        return $agg;
    }

    if (!empty($args['reset_cursor'])) {
        update_option(HD_NATIVE_ESPO_SYNC_OPT_CURSOR, 0, false);
    }

    $started = microtime(true);
    $maxWaves = 80;
    $lastWaveChecked = 0;

    for ($i = 0; $i < $maxWaves; $i++) {
        if ((microtime(true) - $started) >= $maxSeconds) {
            $agg['timed_out'] = true;
            break;
        }
        if ($respectLoad && hd_native_espo_sync_load_too_high()) {
            $agg['skipped_load'] = true;
            break;
        }

        $wave = hd_native_espo_sync_statuses([
            'batch' => $batch,
            'delay_ms' => $delayMs,
            'advance_cursor' => true,
            'respect_load' => false,
            'respect_window' => false,
            'source' => $source,
        ]);

        $agg['waves']++;
        $lastWaveChecked = (int) ($wave['checked'] ?? 0);
        $agg['checked'] += $lastWaveChecked;
        $agg['updated'] += (int) ($wave['updated'] ?? 0);
        $agg['missing'] += (int) ($wave['missing'] ?? 0);
        $agg['errors'] += (int) ($wave['errors'] ?? 0);
        $agg['cursor'] = (int) ($wave['cursor'] ?? $agg['cursor']);
        $agg['wrapped'] = !empty($wave['wrapped']);

        if (!empty($wave['wrapped']) || $lastWaveChecked === 0) {
            break;
        }
    }

    if (
        $scheduleContinue
        && !$agg['wrapped']
        && !$agg['skipped_window']
        && (int) $agg['errors'] === 0
        && $lastWaveChecked > 0
    ) {
        $hook = 'hd_native_espo_status_sync_continue';
        if (!wp_next_scheduled($hook)) {
            wp_schedule_single_event(time() + 60, $hook);
            $agg['continued'] = true;
        }
    }

    update_option(HD_NATIVE_ESPO_SYNC_OPT_LAST, [
        'at' => current_time('mysql'),
        'checked' => $agg['checked'],
        'updated' => $agg['updated'],
        'missing' => $agg['missing'],
        'source' => $source,
        'wrapped' => $agg['wrapped'],
        'timed_out' => $agg['timed_out'],
        'continued' => $agg['continued'],
        'waves' => $agg['waves'],
        'cursor' => $agg['cursor'],
        'batch' => $batch,
        'mode' => 'full_pass',
        'skipped_load' => $agg['skipped_load'],
        'skipped_window' => $agg['skipped_window'],
    ], false);

    return $agg;
}

/**
 * @param array<string,array{interval:int,display:string}> $schedules
 * @return array<string,array{interval:int,display:string}>
 */
function hd_native_espo_status_cron_schedules(array $schedules): array
{
    foreach (hd_native_espo_sync_allowed_intervals() as $min) {
        $slug = hd_native_espo_sync_schedule_slug($min);
        if (!isset($schedules[$slug])) {
            $schedules[$slug] = [
                'interval' => $min * MINUTE_IN_SECONDS,
                'display' => 'Every ' . hd_native_espo_sync_interval_label($min) . ' (HD Espo status sync)',
            ];
        }
    }
    // Legacy slug from first version.
    if (!isset($schedules['hd_native_every_10_minutes'])) {
        $schedules['hd_native_every_10_minutes'] = [
            'interval' => 10 * MINUTE_IN_SECONDS,
            'display' => 'Every 10 minutes (HD Espo status sync legacy)',
        ];
    }
    return $schedules;
}
add_filter('cron_schedules', 'hd_native_espo_status_cron_schedules');

/**
 * Unschedule all Espo status sync events.
 */
function hd_native_espo_status_cron_clear(): void
{
    $hook = HD_NATIVE_ESPO_STATUS_CRON;
    $ts = wp_next_scheduled($hook);
    while ($ts) {
        wp_unschedule_event($ts, $hook);
        $ts = wp_next_scheduled($hook);
    }
}

/**
 * Arm / clear / reschedule cron from current settings.
 *
 * @param bool $forceClear Always clear before (re)arming.
 */
function hd_native_espo_status_cron_reschedule(bool $forceClear = false): void
{
    $settings = hd_native_espo_sync_settings();
    $wantSlug = hd_native_espo_sync_schedule_slug($settings['interval']);
    $next = wp_next_scheduled(HD_NATIVE_ESPO_STATUS_CRON);

    if (!$settings['enabled'] || hd_native_espo_api_key() === '') {
        if ($next) {
            hd_native_espo_status_cron_clear();
        }
        return;
    }

    $needReschedule = $forceClear || !$next;
    if ($next && !$forceClear) {
        // Recurrence stored on cron array — if mismatch, reschedule.
        $crons = _get_cron_array();
        if (is_array($crons) && isset($crons[$next][HD_NATIVE_ESPO_STATUS_CRON])) {
            foreach ($crons[$next][HD_NATIVE_ESPO_STATUS_CRON] as $job) {
                $sched = (string) ($job['schedule'] ?? '');
                if ($sched !== '' && $sched !== $wantSlug) {
                    $needReschedule = true;
                }
            }
        }
    }

    if ($needReschedule) {
        hd_native_espo_status_cron_clear();
        wp_schedule_event(time() + 60, $wantSlug, HD_NATIVE_ESPO_STATUS_CRON);
    }
}

/**
 * Ensure cron matches settings (idempotent).
 */
function hd_native_espo_status_cron_ensure(): void
{
    // Seed defaults once (autoload false).
    if (get_option(HD_NATIVE_ESPO_SYNC_OPT_ENABLED, null) === null) {
        update_option(HD_NATIVE_ESPO_SYNC_OPT_ENABLED, 1, false);
    }
    if (get_option(HD_NATIVE_ESPO_SYNC_OPT_INTERVAL, null) === null) {
        update_option(HD_NATIVE_ESPO_SYNC_OPT_INTERVAL, 120, false);
    }
    if (get_option(HD_NATIVE_ESPO_SYNC_OPT_BATCH, null) === null) {
        update_option(HD_NATIVE_ESPO_SYNC_OPT_BATCH, 25, false);
    }
    if (get_option(HD_NATIVE_ESPO_SYNC_OPT_DELAY, null) === null) {
        update_option(HD_NATIVE_ESPO_SYNC_OPT_DELAY, 150, false);
    }
    if (get_option(HD_NATIVE_ESPO_SYNC_OPT_FROM_HOUR, null) === null) {
        update_option(HD_NATIVE_ESPO_SYNC_OPT_FROM_HOUR, 9, false);
    }
    if (get_option(HD_NATIVE_ESPO_SYNC_OPT_TO_HOUR, null) === null) {
        update_option(HD_NATIVE_ESPO_SYNC_OPT_TO_HOUR, 19, false);
    }
    hd_native_espo_status_cron_reschedule(false);
}
add_action('init', 'hd_native_espo_status_cron_ensure', 20);

/**
 * Admin: Sync all open (temporary) statuses now — soft full pass.
 */
function hd_native_espo_status_sync_admin_post(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Accès refusé.', 403);
    }
    check_admin_referer('hd_native_espo_status_sync');
    // Manual: ignore hour window, soft waves until wrap / 90s.
    $result = hd_native_espo_sync_full_pass([
        'reset_cursor' => true,
        'respect_load' => true,
        'respect_window' => false,
        'max_seconds' => 90,
        'schedule_continue' => true,
        'source' => 'manual',
    ]);
    $redirect = add_query_arg(
        [
            'page' => 'hd-native-leads',
            'espo_sync' => 1,
            'checked' => (int) $result['checked'],
            'updated' => (int) $result['updated'],
            'missing' => (int) $result['missing'],
            'cursor' => (int) $result['cursor'],
            'waves' => (int) $result['waves'],
            'wrapped' => !empty($result['wrapped']) ? 1 : 0,
            'continued' => !empty($result['continued']) ? 1 : 0,
        ],
        admin_url('admin.php')
    );
    wp_safe_redirect($redirect);
    exit;
}
add_action('admin_post_hd_native_espo_status_sync', 'hd_native_espo_status_sync_admin_post');

/**
 * Admin: save wave / cron settings.
 */
function hd_native_espo_sync_settings_admin_post(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Accès refusé.', 403);
    }
    check_admin_referer('hd_native_espo_sync_settings');
    hd_native_espo_sync_save_settings([
        'enabled' => !empty($_POST['espo_sync_enabled']),
        'interval' => (int) ($_POST['espo_sync_interval'] ?? 10),
        'batch' => (int) ($_POST['espo_sync_batch'] ?? 25),
        'delay_ms' => (int) ($_POST['espo_sync_delay_ms'] ?? 150),
        'load_max' => (float) ($_POST['espo_sync_load_max'] ?? 25),
        'from_hour' => (int) ($_POST['espo_sync_from_hour'] ?? 0),
        'to_hour' => (int) ($_POST['espo_sync_to_hour'] ?? 23),
    ]);
    if (!empty($_POST['espo_sync_reset_cursor'])) {
        update_option(HD_NATIVE_ESPO_SYNC_OPT_CURSOR, 0, false);
    }
    wp_safe_redirect(add_query_arg([
        'page' => 'hd-native-leads',
        'espo_settings_saved' => 1,
    ], admin_url('admin.php')));
    exit;
}
add_action('admin_post_hd_native_espo_sync_settings', 'hd_native_espo_sync_settings_admin_post');

/**
 * WP-CLI: wp hd-native espo-sync-status [--batch=25] [--limit=50] [--delay=150] [--no-cursor]
 */
function hd_native_espo_status_register_cli(): void
{
    if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')) {
        return;
    }
    WP_CLI::add_command('hd-native espo-sync-status', function ($args, $assoc) {
        $settings = hd_native_espo_sync_settings();
        $batch = isset($assoc['batch'])
            ? (int) $assoc['batch']
            : (isset($assoc['limit']) ? (int) $assoc['limit'] : $settings['batch']);
        $delay = isset($assoc['delay']) ? (int) $assoc['delay'] : $settings['delay_ms'];
        $advance = empty($assoc['no-cursor']);
        $result = hd_native_espo_sync_statuses([
            'batch' => $batch,
            'delay_ms' => $delay,
            'advance_cursor' => $advance,
            'respect_load' => !empty($assoc['respect-load']),
            'source' => 'cli',
        ]);
        WP_CLI::log(sprintf(
            'checked=%d updated=%d missing=%d errors=%d cursor=%d wrapped=%s skipped_load=%s',
            $result['checked'],
            $result['updated'],
            $result['missing'],
            $result['errors'],
            $result['cursor'],
            $result['wrapped'] ? '1' : '0',
            $result['skipped_load'] ? '1' : '0'
        ));
        foreach ($result['details'] as $d) {
            WP_CLI::log(wp_json_encode($d));
        }
        if ($result['errors'] > 0 && $result['updated'] === 0 && $result['checked'] === 0) {
            WP_CLI::error('Sync failed (check API key constant HD_NATIVE_ESPO_API_KEY).');
        }
        WP_CLI::success('Espo status sync done.');
    });
}
add_action('cli_init', 'hd_native_espo_status_register_cli');
