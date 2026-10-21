<?php
/**
 * TB Tracker — declarative segment rules engine + bounded recalc.
 *
 * Works on existing page_view / lead events. Rules for scroll / form_start /
 * cta_click / engage / quality are ready but match nothing until P0 data exists.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bot UA pattern shared with dashboard "humains uniquement" filter.
 */
function tb_tracker_bot_ua_regexp(): string
{
    // Keep `/` out of the pattern body — callers wrap with `/.../i`.
    return '(tb-liveaudit|curl|facebookexternalhit|googlebot|bingbot|duckduckbot|yandexbot|baiduspider|'
        . 'bot([\\/ ;_-]|$)|spider|crawler|slurp|headlesschrome|lighthouse|pagespeed|uptimerobot|pingdom)';
}

function tb_tracker_is_bot_ua(?string $ua): bool
{
    $ua = strtolower(trim((string) $ua));
    if ($ua === '') {
        return false;
    }
    return (bool) preg_match('/' . tb_tracker_bot_ua_regexp() . '/i', $ua);
}

function tb_tracker_segments_config_path(): string
{
    return __DIR__ . '/config-v1.json';
}

/**
 * @return array{version?:int,segments:array<int,array<string,mixed>>}
 */
function tb_tracker_segments_load_config(): array
{
    $path = tb_tracker_segments_config_path();
    if (!is_readable($path)) {
        return ['segments' => []];
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return ['segments' => []];
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['segments']) || !is_array($data['segments'])) {
        return ['segments' => []];
    }
    return $data;
}

/**
 * Seed / refresh segment definitions from config JSON (idempotent upsert by slug).
 *
 * @return int Number of segments upserted
 */
function tb_tracker_segments_seed_from_config(): int
{
    if (!tb_tracker_segments_tables_ready()) {
        tb_tracker_segments_install_tables();
    }
    if (!tb_tracker_segments_tables_ready()) {
        return 0;
    }

    global $wpdb;
    $table = tb_tracker_segments_table('segments');
    $config = tb_tracker_segments_load_config();
    $now = current_time('mysql');
    $n = 0;

    foreach ($config['segments'] as $seg) {
        if (!is_array($seg)) {
            continue;
        }
        $slug = isset($seg['slug']) ? sanitize_key((string) $seg['slug']) : '';
        if ($slug === '' || strlen($slug) > 64) {
            continue;
        }
        $name = isset($seg['name']) ? sanitize_text_field((string) $seg['name']) : $slug;
        $desc = isset($seg['description']) ? sanitize_textarea_field((string) $seg['description']) : '';
        $priority = isset($seg['priority']) ? (int) $seg['priority'] : 100;
        $active = !empty($seg['active']) ? 1 : 0;
        $calcMode = isset($seg['calc_mode']) ? sanitize_key((string) $seg['calc_mode']) : 'batch';
        if (!in_array($calcMode, ['batch', 'realtime', 'both'], true)) {
            $calcMode = 'batch';
        }
        $rule = isset($seg['rule']) && is_array($seg['rule']) ? $seg['rule'] : [];
        $ruleJson = wp_json_encode($rule, JSON_UNESCAPED_UNICODE);
        if (!is_string($ruleJson) || $ruleJson === '') {
            $ruleJson = '{}';
        }

        $existingId = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE slug = %s LIMIT 1",
            $slug
        ));

        if ($existingId) {
            $wpdb->update(
                $table,
                [
                    'name' => $name,
                    'description' => $desc,
                    'rule_json' => $ruleJson,
                    'priority' => $priority,
                    'active' => $active,
                    'calc_mode' => $calcMode,
                    'updated_at' => $now,
                ],
                ['id' => (int) $existingId],
                ['%s', '%s', '%s', '%d', '%d', '%s', '%s'],
                ['%d']
            );
        } else {
            $wpdb->insert(
                $table,
                [
                    'slug' => $slug,
                    'name' => $name,
                    'description' => $desc,
                    'rule_json' => $ruleJson,
                    'priority' => $priority,
                    'active' => $active,
                    'calc_mode' => $calcMode,
                    'updated_at' => $now,
                ],
                ['%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
            );
        }
        $n++;
    }

    return $n;
}

/**
 * @return list<array<string,mixed>>
 */
function tb_tracker_segments_list_active(): array
{
    global $wpdb;
    if (!tb_tracker_segments_tables_ready()) {
        return [];
    }
    $table = tb_tracker_segments_table('segments');
    $rows = $wpdb->get_results(
        "SELECT id, slug, name, description, rule_json, priority, active, calc_mode, updated_at
         FROM {$table} WHERE active = 1 ORDER BY priority ASC, id ASC",
        ARRAY_A
    );
    return is_array($rows) ? $rows : [];
}

/**
 * @return list<array<string,mixed>>
 */
function tb_tracker_segments_list_all(): array
{
    global $wpdb;
    if (!tb_tracker_segments_tables_ready()) {
        return [];
    }
    $table = tb_tracker_segments_table('segments');
    $rows = $wpdb->get_results(
        "SELECT id, slug, name, description, rule_json, priority, active, calc_mode, updated_at
         FROM {$table} ORDER BY priority ASC, id ASC",
        ARRAY_A
    );
    return is_array($rows) ? $rows : [];
}

/**
 * Member counts keyed by segment_id.
 *
 * @return array<int,int>
 */
function tb_tracker_segments_member_counts(): array
{
    global $wpdb;
    if (!tb_tracker_segments_tables_ready()) {
        return [];
    }
    $table = tb_tracker_segments_table('members');
    $rows = $wpdb->get_results(
        "SELECT segment_id, COUNT(*) AS cnt FROM {$table} GROUP BY segment_id",
        ARRAY_A
    );
    $out = [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $out[(int) $row['segment_id']] = (int) $row['cnt'];
        }
    }
    return $out;
}

/**
 * Read meta path from event meta_json (flat key only, e.g. max_pct).
 *
 * @param array<string,mixed>|null $meta
 * @return mixed
 */
function tb_tracker_segments_meta_get(?array $meta, string $key)
{
    if (!is_array($meta) || $key === '') {
        return null;
    }
    // Support "max_pct" or legacy "meta.max_pct" style in JSON configs.
    $key = preg_replace('/^meta\./', '', $key);
    return array_key_exists($key, $meta) ? $meta[$key] : null;
}

/**
 * Compare numeric / bool values for rule predicates.
 *
 * @param mixed $left
 * @param mixed $right
 */
function tb_tracker_segments_compare($left, string $op, $right): bool
{
    if ($op === 'eq') {
        if (is_bool($right)) {
            return (bool) $left === $right;
        }
        // loose equality for JSON bools stored as 1/0/"true"
        if ($right === true || $right === false) {
            return (bool) $left === $right;
        }
        return (string) $left === (string) $right;
    }
    if (!is_numeric($left) || !is_numeric($right)) {
        return false;
    }
    $l = (float) $left;
    $r = (float) $right;
    if ($op === 'gte') {
        return $l >= $r;
    }
    if ($op === 'lte') {
        return $l <= $r;
    }
    if ($op === 'gt') {
        return $l > $r;
    }
    if ($op === 'lt') {
        return $l < $r;
    }
    return false;
}

/**
 * Normalize URL path for distinct_url comparisons.
 */
function tb_tracker_segments_url_path(?string $url): string
{
    $url = is_string($url) ? $url : '';
    if ($url === '') {
        return '';
    }
    $path = wp_parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return '/';
    }
    return '/' . ltrim($path, '/');
}

/**
 * Evaluate a single predicate against prefetched click + events.
 *
 * @param array<string,mixed> $pred
 * @param array{click_id:string,user_agent?:string}|null $click
 * @param list<array{event_type:string,url?:string,meta_json?:string,created_at:string}> $events
 */
function tb_tracker_segments_eval_pred(array $pred, ?array $click, array $events, ?DateTimeImmutable $now = null): bool
{
    if (isset($pred['all']) && is_array($pred['all'])) {
        foreach ($pred['all'] as $child) {
            if (!is_array($child) || !tb_tracker_segments_eval_pred($child, $click, $events, $now)) {
                return false;
            }
        }
        return true;
    }

    if (isset($pred['any']) && is_array($pred['any'])) {
        if ($pred['any'] === []) {
            return false;
        }
        foreach ($pred['any'] as $child) {
            if (is_array($child) && tb_tracker_segments_eval_pred($child, $click, $events, $now)) {
                return true;
            }
        }
        return false;
    }

    if (isset($pred['not']) && is_array($pred['not'])) {
        return !tb_tracker_segments_eval_pred($pred['not'], $click, $events, $now);
    }

    if (!empty($pred['click_field'])) {
        $field = sanitize_key((string) $pred['click_field']);
        $val = is_array($click) && isset($click[$field]) ? (string) $click[$field] : '';

        if (!empty($pred['is_bot_ua'])) {
            return tb_tracker_is_bot_ua($val);
        }
        if (!empty($pred['not_empty'])) {
            return trim($val) !== '';
        }
        if (!empty($pred['empty'])) {
            return trim($val) === '';
        }
        if (array_key_exists('eq', $pred)) {
            return strcasecmp(trim($val), trim((string) $pred['eq'])) === 0;
        }
        if (array_key_exists('neq', $pred)) {
            return strcasecmp(trim($val), trim((string) $pred['neq'])) !== 0;
        }
        if (!empty($pred['in']) && is_array($pred['in'])) {
            $needle = strtolower(trim($val));
            foreach ($pred['in'] as $cand) {
                if (strcasecmp($needle, strtolower(trim((string) $cand))) === 0) {
                    return true;
                }
            }
            return false;
        }
        if (!empty($pred['contains'])) {
            return $val !== '' && stripos($val, (string) $pred['contains']) !== false;
        }
        // Returning visitor: last_seen significantly after created (hours).
        if (!empty($pred['returning_hours']) && is_array($click)) {
            $hours = max(1, (int) $pred['returning_hours']);
            $created = strtotime((string) ($click['created_at'] ?? ''));
            $seen = strtotime((string) ($click['last_seen_at'] ?? ''));
            if (!$created || !$seen) {
                return false;
            }
            return ($seen - $created) >= ($hours * 3600);
        }
        return false;
    }

    if (empty($pred['event'])) {
        return false;
    }

    $type = sanitize_key((string) $pred['event']);
    $withinDays = isset($pred['within_days']) ? max(1, (int) $pred['within_days']) : 7;
    if ($now === null) {
        $now = new DateTimeImmutable('now', wp_timezone());
    }
    $cutoff = $now->modify('-' . $withinDays . ' days')->format('Y-m-d H:i:s');

    $matched = [];
    foreach ($events as $ev) {
        if (($ev['event_type'] ?? '') !== $type) {
            continue;
        }
        if (($ev['created_at'] ?? '') < $cutoff) {
            continue;
        }
        $matched[] = $ev;
    }

    $countGte = isset($pred['count_gte']) ? (int) $pred['count_gte'] : 0;
    $distinctUrl = !empty($pred['distinct_url']);

    if ($distinctUrl) {
        $paths = [];
        foreach ($matched as $ev) {
            $p = tb_tracker_segments_url_path($ev['url'] ?? null);
            if ($p !== '') {
                $paths[$p] = true;
            }
        }
        $cnt = count($paths);
        if ($countGte > 0) {
            return $cnt >= $countGte;
        }
        return $cnt >= 1;
    }

    if ($countGte > 0) {
        return count($matched) >= $countGte;
    }

    // Meta aggregate: max over matching events for gte/lte/…
    $metaKey = '';
    if (isset($pred['meta']) && is_string($pred['meta'])) {
        $metaKey = $pred['meta'];
    }
    $op = '';
    $threshold = null;
    foreach (['gte', 'lte', 'gt', 'lt', 'eq'] as $candidate) {
        if (array_key_exists($candidate, $pred)) {
            $op = $candidate;
            $threshold = $pred[$candidate];
            break;
        }
    }

    if ($metaKey !== '' && $op !== '') {
        if ($matched === []) {
            return false;
        }
        $best = null;
        foreach ($matched as $ev) {
            $meta = null;
            if (!empty($ev['meta_json'])) {
                $decoded = json_decode((string) $ev['meta_json'], true);
                $meta = is_array($decoded) ? $decoded : null;
            }
            $val = tb_tracker_segments_meta_get($meta, $metaKey);
            if ($val === null) {
                continue;
            }
            if ($op === 'eq') {
                if (tb_tracker_segments_compare($val, 'eq', $threshold)) {
                    return true;
                }
                continue;
            }
            if (!is_numeric($val)) {
                continue;
            }
            $num = (float) $val;
            if ($best === null) {
                $best = $num;
            } elseif (in_array($op, ['gte', 'gt'], true)) {
                $best = max($best, $num);
            } else {
                $best = min($best, $num);
            }
        }
        if ($op === 'eq') {
            return false;
        }
        if ($best === null) {
            return false;
        }
        return tb_tracker_segments_compare($best, $op, $threshold);
    }

    // Existence of event type in window.
    return count($matched) >= 1;
}

/**
 * @param array<string,mixed> $rule
 * @param array{click_id:string,user_agent?:string}|null $click
 * @param list<array{event_type:string,url?:string,meta_json?:string,created_at:string}> $events
 * @return array{match:bool,score:float}
 */
function tb_tracker_segments_eval_rule(array $rule, ?array $click, array $events, ?DateTimeImmutable $now = null): array
{
    $body = $rule;
    unset($body['score']);

    // Rule root may be all/any/not or a single predicate; wrap bare keys.
    $match = false;
    if (isset($rule['all']) || isset($rule['any']) || isset($rule['not']) || isset($rule['event']) || isset($rule['click_field'])) {
        $match = tb_tracker_segments_eval_pred($body, $click, $events, $now);
    }

    $score = 0.0;
    if ($match) {
        $scoreCfg = isset($rule['score']) && is_array($rule['score']) ? $rule['score'] : [];
        $score = isset($scoreCfg['base']) ? (float) $scoreCfg['base'] : 50.0;
        $cap = isset($scoreCfg['cap']) ? (float) $scoreCfg['cap'] : 100.0;
        $boostEvent = isset($scoreCfg['boost_event']) ? sanitize_key((string) $scoreCfg['boost_event']) : '';
        $boost = isset($scoreCfg['boost']) ? (float) $scoreCfg['boost'] : 0.0;
        if ($boostEvent !== '' && $boost > 0) {
            $n = 0;
            foreach ($events as $ev) {
                if (($ev['event_type'] ?? '') === $boostEvent) {
                    $n++;
                }
            }
            $score += min(5, $n) * $boost;
        }
        if ($score > $cap) {
            $score = $cap;
        }
        if ($score < 0) {
            $score = 0;
        }
    }

    return ['match' => $match, 'score' => round($score, 2)];
}

/**
 * Bounded recalculation.
 *
 * @param array{limit?:int,within_days?:int,dry_run?:bool,offset?:int,segment_slug?:string} $args
 * @return array<string,mixed>
 */
function tb_tracker_segments_recalc(array $args = []): array
{
    $limit = isset($args['limit']) ? max(1, min(500, (int) $args['limit'])) : 100;
    $withinDays = isset($args['within_days']) ? max(1, min(90, (int) $args['within_days'])) : 7;
    $dryRun = !empty($args['dry_run']);
    $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
    $onlySlug = isset($args['segment_slug']) ? sanitize_key((string) $args['segment_slug']) : '';

    $started = microtime(true);
    $result = [
        'ok' => false,
        'dry_run' => $dryRun,
        'limit' => $limit,
        'within_days' => $withinDays,
        'offset' => $offset,
        'clicks_scanned' => 0,
        'memberships_added' => 0,
        'memberships_updated' => 0,
        'memberships_removed' => 0,
        'would_add' => 0,
        'would_remove' => 0,
        'by_segment' => [],
        'error' => '',
        'ms' => 0,
    ];

    // Pause if load critical.
    if (function_exists('sys_getloadavg')) {
        $load = @sys_getloadavg();
        if (is_array($load) && (float) $load[0] > 25) {
            $result['error'] = 'load1>25';
            return $result;
        }
    }

    if (!function_exists('tb_tracker_table')) {
        $result['error'] = 'tb_tracker_table missing';
        return $result;
    }

    global $wpdb;
    $ct = tb_tracker_table('clicks');
    $et = tb_tracker_table('events');
    $clicksOk = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ct)) === $ct;
    $eventsOk = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $et)) === $et;
    if (!$clicksOk || !$eventsOk) {
        $result['error'] = 'clicks/events tables missing';
        return $result;
    }

    if (!$dryRun) {
        tb_tracker_segments_install_tables();
        tb_tracker_segments_seed_from_config();
    } elseif (!tb_tracker_segments_tables_ready()) {
        // Dry run can still evaluate from config JSON without writing tables.
        $configSegs = tb_tracker_segments_load_config()['segments'];
        $segments = [];
        foreach ($configSegs as $i => $seg) {
            if (!is_array($seg) || empty($seg['active'])) {
                continue;
            }
            $slug = sanitize_key((string) ($seg['slug'] ?? ''));
            if ($onlySlug !== '' && $slug !== $onlySlug) {
                continue;
            }
            $segments[] = [
                'id' => $i + 1,
                'slug' => $slug,
                'rule_json' => wp_json_encode($seg['rule'] ?? [], JSON_UNESCAPED_UNICODE),
            ];
        }
    }

    if (!isset($segments)) {
        $segments = tb_tracker_segments_list_active();
        if ($onlySlug !== '') {
            $segments = array_values(array_filter($segments, static function ($s) use ($onlySlug) {
                return ($s['slug'] ?? '') === $onlySlug;
            }));
        }
    }

    if ($segments === []) {
        // Attempt seed then reload (non-dry).
        if (!$dryRun) {
            tb_tracker_segments_seed_from_config();
            $segments = tb_tracker_segments_list_active();
        }
        if ($segments === []) {
            $result['error'] = 'no active segments';
            $result['ms'] = (int) round((microtime(true) - $started) * 1000);
            return $result;
        }
    }

    foreach ($segments as $seg) {
        $result['by_segment'][$seg['slug']] = ['matched' => 0, 'scanned' => 0];
    }

    $now = new DateTimeImmutable('now', wp_timezone());
    $cutoff = $now->modify('-' . $withinDays . ' days')->format('Y-m-d H:i:s');

    // Recent clicks only, newest first (light: clicks table — tracker always upserts a click row).
    // Avoid UNION … ORDER BY click_id ASC which always re-scanned the same alphabetical batch.
    $clickIds = $wpdb->get_col($wpdb->prepare(
        "SELECT c.click_id
         FROM {$ct} c
         WHERE c.last_seen_at >= %s OR c.created_at >= %s
         ORDER BY COALESCE(c.last_seen_at, c.created_at) DESC, c.id DESC
         LIMIT %d OFFSET %d",
        $cutoff,
        $cutoff,
        $limit,
        $offset
    ));

    if (!is_array($clickIds)) {
        $clickIds = [];
    }
    $result['candidates_batch'] = count($clickIds);

    $membersTable = tb_tracker_segments_tables_ready() ? tb_tracker_segments_table('members') : '';
    $nowMysql = current_time('mysql');

    foreach ($clickIds as $clickId) {
        $clickId = tb_tracker_sanitize_click_id($clickId);
        if ($clickId === '') {
            continue;
        }
        $result['clicks_scanned']++;

        $click = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$ct} WHERE click_id = %s LIMIT 1",
            $clickId
        ), ARRAY_A);

        // Fetch events with a slightly wider window (max rule window 30d in v1).
        $eventCutoff = $now->modify('-30 days')->format('Y-m-d H:i:s');
        $events = $wpdb->get_results($wpdb->prepare(
            "SELECT event_type, url, meta_json, created_at
             FROM {$et}
             WHERE click_id = %s AND created_at >= %s
             ORDER BY id ASC
             LIMIT 500",
            $clickId,
            $eventCutoff
        ), ARRAY_A);
        if (!is_array($events)) {
            $events = [];
        }

        foreach ($segments as $seg) {
            $slug = (string) $seg['slug'];
            $result['by_segment'][$slug]['scanned']++;
            $rule = json_decode((string) ($seg['rule_json'] ?? '{}'), true);
            if (!is_array($rule)) {
                $rule = [];
            }
            $eval = tb_tracker_segments_eval_rule($rule, $click, $events, $now);
            if ($eval['match']) {
                $result['by_segment'][$slug]['matched']++;
            }

            $segId = (int) $seg['id'];
            if ($dryRun || $membersTable === '' || $segId <= 0) {
                if ($eval['match']) {
                    $result['would_add']++;
                }
                continue;
            }

            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$membersTable} WHERE click_id = %s AND segment_id = %d LIMIT 1",
                $clickId,
                $segId
            ), ARRAY_A);

            if ($eval['match']) {
                if ($existing) {
                    $wpdb->update(
                        $membersTable,
                        [
                            'score' => $eval['score'],
                            'updated_at' => $nowMysql,
                        ],
                        ['id' => (int) $existing['id']],
                        ['%f', '%s'],
                        ['%d']
                    );
                    $result['memberships_updated']++;
                } else {
                    $wpdb->insert(
                        $membersTable,
                        [
                            'click_id' => $clickId,
                            'segment_id' => $segId,
                            'score' => $eval['score'],
                            'entered_at' => $nowMysql,
                            'updated_at' => $nowMysql,
                            'expires_at' => null,
                        ],
                        ['%s', '%d', '%f', '%s', '%s', '%s']
                    );
                    $result['memberships_added']++;
                }
            } elseif ($existing) {
                $wpdb->delete($membersTable, ['id' => (int) $existing['id']], ['%d']);
                $result['memberships_removed']++;
                $result['would_remove']++;
            }
        }
    }

    if (!$dryRun) {
        $source = isset($args['source']) ? sanitize_key((string) $args['source']) : 'manual';
        update_option('tb_segments_last_recalc', [
            'at' => $nowMysql,
            'source' => $source,
            'clicks_scanned' => $result['clicks_scanned'],
            'added' => $result['memberships_added'],
            'updated' => $result['memberships_updated'],
            'removed' => $result['memberships_removed'],
            'by_segment' => $result['by_segment'],
            'limit' => $limit,
            'within_days' => $withinDays,
            'offset' => $offset,
            'batch' => count($clickIds),
        ], false);
    }

    $result['ok'] = true;
    $result['ms'] = (int) round((microtime(true) - $started) * 1000);
    return $result;
}
