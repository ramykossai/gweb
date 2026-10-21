<?php
/**
 * TB Tracker — Live analytics (admin only).
 *
 * Answers one question: "qu'est-ce qui se passe MAINTENANT sur le trafic ads ?"
 * Signals are read straight from wp_tb_clicks / wp_tb_events. Anything the schema
 * cannot prove (spend, ROAS, CRM outcome…) is reported as n/a instead of guessed.
 *
 * Perf contract: every query is bounded by a time window that hits an index
 * (clicks.created_at, events.created_at, events.type_time) plus a hard LIMIT.
 * The REST endpoint is manage_options + wp_rest nonce, never public.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Feed rows returned per tick. */
const TB_LIVE_FEED_LIMIT = 60;

/** Hard floor for the feed scan so a selective filter can never walk the table. */
const TB_LIVE_FEED_WINDOW_MIN = 360;

/**
 * Event types exposed in the live feed, in display order of importance.
 *
 * @return array<string,string>
 */
function tb_live_event_types(): array
{
    return [
        'lead' => 'Lead',
        'form_start' => 'Form start',
        'form_error' => 'Form error',
        'cta_click' => 'CTA',
        'page_view' => 'Page view',
        'scroll' => 'Scroll',
        'engage' => 'Engage',
        'popup_show' => 'Popup show',
        'popup_skip' => 'Popup skip',
        'context' => 'Context',
        'attr_update' => 'Attr update',
    ];
}

/** Types shown by default: the ones a media buyer acts on. */
function tb_live_default_types(): array
{
    return ['lead', 'form_start', 'form_error', 'cta_click', 'page_view'];
}

/**
 * Normalize + bound the live filters coming from the client.
 *
 * @param array<string,mixed> $raw
 * @return array{types:array<int,string>,sku:string,campaign:string,path:string,fbp:string}
 */
function tb_live_sanitize_filters(array $raw): array
{
    $allowed = array_keys(tb_live_event_types());
    $types = [];
    if (isset($raw['types']) && is_string($raw['types']) && $raw['types'] !== '') {
        foreach (explode(',', $raw['types']) as $type) {
            $type = sanitize_key($type);
            if (in_array($type, $allowed, true)) {
                $types[] = $type;
            }
        }
    }
    if (!$types) {
        $types = tb_live_default_types();
    }

    $fbp = isset($raw['fbp']) ? sanitize_key((string) $raw['fbp']) : '';
    if (!in_array($fbp, ['yes', 'no'], true)) {
        $fbp = '';
    }

    return [
        'types' => array_values(array_unique($types)),
        'sku' => substr(sanitize_text_field((string) ($raw['sku'] ?? '')), 0, 60),
        'campaign' => substr(sanitize_text_field((string) ($raw['campaign'] ?? '')), 0, 60),
        'path' => substr(sanitize_text_field((string) ($raw['path'] ?? '')), 0, 60),
        'fbp' => $fbp,
    ];
}

/**
 * Local "now" reference. The tracker writes with current_time('mysql'),
 * so every window must be computed in site time, never gmdate().
 *
 * @return array{now:string,ts:int}
 */
function tb_live_now(): array
{
    $ts = (int) current_time('timestamp');
    return ['now' => date('Y-m-d H:i:s', $ts), 'ts' => $ts];
}

function tb_live_since(int $minutes): string
{
    return date('Y-m-d H:i:s', (int) current_time('timestamp') - ($minutes * 60));
}

/**
 * Age in seconds of a stored (site-local) datetime.
 */
function tb_live_age(?string $mysqlDate): ?int
{
    if (!is_string($mysqlDate) || $mysqlDate === '' || $mysqlDate === '0000-00-00 00:00:00') {
        return null;
    }
    $ts = strtotime($mysqlDate);
    if ($ts === false) {
        return null;
    }
    return max(0, (int) current_time('timestamp') - $ts);
}

/* --------------------------------------------------------------------------
 * Pulse — health, counters, breakdowns
 * -------------------------------------------------------------------------- */

/**
 * Everything the Live header + right column needs, in ~9 bounded queries.
 *
 * @return array<string,mixed>
 */
function tb_live_pulse(): array
{
    global $wpdb;

    $out = [
        'ready' => false,
        'enabled' => tb_tracker_is_enabled(),
        'js_engagement' => tb_tracker_flag_on(TB_TRACKER_OPT_JS_ENGAGEMENT),
        'js_context' => tb_tracker_flag_on(TB_TRACKER_OPT_JS_CONTEXT),
        'server_time' => '',
        'windows' => [],
        'sessions_5m' => 0,
        'ages' => [],
        'funnel' => [],
        'match' => [],
        'landings' => [],
        'campaigns' => [],
        'offers' => [],
        'sources' => [],
        'ref_hosts' => [],
        'geo' => [],
        'minutes' => [],
        'daf' => [
            'leads_m5' => 0,
            'leads_m15' => 0,
            'leads_m60' => 0,
            'leads_total_m60' => 0,
            'share_pct' => 0,
            'drafts_open' => 0,
            'drafts_open_ready' => 0,
        ],
        'verdict' => ['level' => 'na', 'label' => 'En attente', 'message' => ''],
    ];

    if (!tb_tracker_admin_tables_ready()) {
        return $out;
    }

    $out['ready'] = true;
    $clock = tb_live_now();
    $out['server_time'] = $clock['now'];

    $ct = tb_tracker_table('clicks');
    $et = tb_tracker_table('events');
    $m5 = tb_live_since(5);
    $m15 = tb_live_since(15);
    $m60 = tb_live_since(60);

    // 1. Event counters per type over the last hour (index: events.created_at).
    $eventRows = $wpdb->get_results($wpdb->prepare(
        "SELECT event_type,
                SUM(created_at >= %s) AS m5,
                SUM(created_at >= %s) AS m15,
                COUNT(*) AS m60
         FROM {$et}
         WHERE created_at >= %s
         GROUP BY event_type",
        $m5,
        $m15,
        $m60
    ), ARRAY_A) ?: [];

    $windows = [];
    foreach ($eventRows as $row) {
        $windows[(string) $row['event_type']] = [
            'm5' => (int) $row['m5'],
            'm15' => (int) $row['m15'],
            'm60' => (int) $row['m60'],
        ];
    }

    // 2. New clicks / sessions (index: clicks.created_at).
    $clickRow = $wpdb->get_row($wpdb->prepare(
        "SELECT SUM(created_at >= %s) AS m5,
                SUM(created_at >= %s) AS m15,
                COUNT(*) AS m60
         FROM {$ct}
         WHERE created_at >= %s",
        $m5,
        $m15,
        $m60
    ), ARRAY_A) ?: [];
    $windows['new_click'] = [
        'm5' => (int) ($clickRow['m5'] ?? 0),
        'm15' => (int) ($clickRow['m15'] ?? 0),
        'm60' => (int) ($clickRow['m60'] ?? 0),
    ];
    $out['windows'] = $windows;

    // 3. Distinct visitors currently active.
    $out['sessions_5m'] = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT click_id) FROM {$et} WHERE created_at >= %s",
        $m5
    ));

    // 4. Freshness of each critical signal (loose index scan on type_time).
    $lastByType = $wpdb->get_results(
        "SELECT event_type, MAX(created_at) AS last_at
         FROM {$et}
         WHERE event_type IN ('page_view','form_start','lead','cta_click')
         GROUP BY event_type",
        ARRAY_A
    ) ?: [];
    $ages = ['click' => null, 'page_view' => null, 'form_start' => null, 'lead' => null, 'cta_click' => null];
    foreach ($lastByType as $row) {
        $ages[(string) $row['event_type']] = tb_live_age((string) $row['last_at']);
    }
    $ages['click'] = tb_live_age((string) $wpdb->get_var("SELECT MAX(created_at) FROM {$ct}"));

    // Native leads table is the source of truth for the CRM bridge — PK read only.
    $ages['native_lead'] = null;
    $ages['daf_lead'] = null;
    $hdTable = $wpdb->prefix . 'hd_leads';
    $draftTable = $wpdb->prefix . 'hd_lead_drafts';
    $daf = [
        'leads_m5' => 0,
        'leads_m15' => 0,
        'leads_m60' => 0,
        'leads_total_m60' => 0,
        'share_pct' => 0,
        'drafts_open' => 0,
        'drafts_open_ready' => 0,
    ];
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hdTable)) === $hdTable) {
        $ages['native_lead'] = tb_live_age((string) $wpdb->get_var(
            "SELECT created_at FROM {$hdTable} ORDER BY id DESC LIMIT 1"
        ));
        // DAF = utm_campaign prefixed DAF- (draft auto-promote), not lead name.
        $camp = "JSON_UNQUOTE(JSON_EXTRACT(meta, '$.utm_campaign'))";
        $dafRow = $wpdb->get_row($wpdb->prepare(
            "SELECT
                SUM(created_at >= %s AND ({$camp} LIKE 'DAF-%%' OR {$camp} LIKE 'daf-%%')) AS m5,
                SUM(created_at >= %s AND ({$camp} LIKE 'DAF-%%' OR {$camp} LIKE 'daf-%%')) AS m15,
                SUM({$camp} LIKE 'DAF-%%' OR {$camp} LIKE 'daf-%%') AS m60,
                COUNT(*) AS total60,
                MAX(CASE WHEN {$camp} LIKE 'DAF-%%' OR {$camp} LIKE 'daf-%%' THEN created_at END) AS last_daf
             FROM {$hdTable}
             WHERE created_at >= %s",
            $m5,
            $m15,
            $m60
        ), ARRAY_A) ?: [];
        $daf['leads_m5'] = (int) ($dafRow['m5'] ?? 0);
        $daf['leads_m15'] = (int) ($dafRow['m15'] ?? 0);
        $daf['leads_m60'] = (int) ($dafRow['m60'] ?? 0);
        $daf['leads_total_m60'] = (int) ($dafRow['total60'] ?? 0);
        if ($daf['leads_total_m60'] > 0) {
            $daf['share_pct'] = (int) round((100 * $daf['leads_m60']) / $daf['leads_total_m60']);
        }
        $ages['daf_lead'] = tb_live_age(isset($dafRow['last_daf']) ? (string) $dafRow['last_daf'] : null);
    }
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $draftTable)) === $draftTable) {
        $daf['drafts_open'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$draftTable} WHERE status = 'open'"
        );
        // Phone already captured, waiting inactivity / promote window.
        $daf['drafts_open_ready'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$draftTable}
             WHERE status = 'open' AND phone IS NOT NULL AND phone <> ''
               AND updated_at <= %s",
            date('Y-m-d H:i:s', (int) current_time('timestamp') - 300)
        ));
    }
    $out['ages'] = $ages;
    $out['daf'] = $daf;
    // Surface in the windows table alongside tracker events.
    $windows['daf_lead'] = [
        'm5' => $daf['leads_m5'],
        'm15' => $daf['leads_m15'],
        'm60' => $daf['leads_m60'],
    ];
    $windows['draft_open'] = [
        'm5' => $daf['drafts_open'],
        'm15' => $daf['drafts_open'],
        'm60' => $daf['drafts_open'],
    ];
    $out['windows'] = $windows;

    // 5. Funnel over the last hour, unique visitors per stage. Starting at
    // page_view keeps it monotonic: returning visitors have no new click row.
    $funnel = $wpdb->get_row($wpdb->prepare(
        "SELECT
            COUNT(DISTINCT CASE WHEN event_type = 'page_view' THEN click_id END) AS pv,
            COUNT(DISTINCT CASE WHEN event_type = 'cta_click' THEN click_id END) AS cta,
            COUNT(DISTINCT CASE WHEN event_type = 'form_start' THEN click_id END) AS form_start,
            COUNT(DISTINCT CASE WHEN event_type = 'lead' THEN click_id END) AS lead
         FROM {$et}
         WHERE created_at >= %s
           AND event_type IN ('page_view','cta_click','form_start','lead')",
        $m60
    ), ARRAY_A) ?: [];
    $out['funnel'] = [
        'clicks' => (int) ($windows['new_click']['m60'] ?? 0),
        'pv' => (int) ($funnel['pv'] ?? 0),
        'cta' => (int) ($funnel['cta'] ?? 0),
        'form_start' => (int) ($funnel['form_start'] ?? 0),
        'lead' => (int) ($funnel['lead'] ?? 0),
    ];

    // 6. Meta match quality on the clicks created in the last hour.
    $match = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) AS total,
                SUM(fbp IS NOT NULL AND fbp <> '') AS fbp,
                SUM(fbc IS NOT NULL AND fbc <> '') AS fbc,
                SUM(fbclid IS NOT NULL AND fbclid <> '') AS fbclid,
                SUM(geo_iso IS NOT NULL AND geo_iso <> '') AS geo
         FROM {$ct}
         WHERE created_at >= %s",
        $m60
    ), ARRAY_A) ?: [];
    $matchTotal = (int) ($match['total'] ?? 0);
    $out['match'] = [
        'total' => $matchTotal,
        'fbp' => (int) ($match['fbp'] ?? 0),
        'fbc' => (int) ($match['fbc'] ?? 0),
        'fbclid' => (int) ($match['fbclid'] ?? 0),
        'geo' => (int) ($match['geo'] ?? 0),
        'fbp_pct' => $matchTotal > 0 ? round((100 * (int) $match['fbp']) / $matchTotal) : 0,
    ];

    // 7. Landings of the last hour. Ads land on /?page_id=N, so the page id is
    // the only usable content dimension — every URL path would collapse to "/".
    $landingRows = $wpdb->get_results($wpdb->prepare(
        "SELECT page_id,
                SUM(event_type = 'page_view') AS pv,
                COUNT(DISTINCT CASE WHEN event_type = 'page_view' THEN click_id END) AS visitors,
                COUNT(DISTINCT CASE WHEN event_type = 'form_start' THEN click_id END) AS form_start,
                SUM(event_type = 'lead') AS leads
         FROM {$et}
         WHERE created_at >= %s
           AND event_type IN ('page_view','form_start','lead')
         GROUP BY page_id
         ORDER BY pv DESC
         LIMIT 12",
        $m60
    ), ARRAY_A) ?: [];
    $titles = tb_tracker_admin_page_titles(array_column($landingRows, 'page_id'));
    $out['landings'] = array_map(
        static function (array $row) use ($titles): array {
            $visitors = (int) $row['visitors'];
            $leads = (int) $row['leads'];
            return [
                'page_id' => (int) $row['page_id'],
                'label' => tb_tracker_admin_landing_label((int) $row['page_id'], $titles),
                'value' => (int) $row['pv'],
                'visitors' => $visitors,
                'form_start' => (int) $row['form_start'],
                'leads' => $leads,
                'cvr' => $visitors > 0 ? round((100 * $leads) / $visitors, 1) : 0.0,
            ];
        },
        $landingRows
    );

    // 8. Acquisition breakdowns from the clicks created in the last hour.
    $out['campaigns'] = array_map(
        static function (array $row): array {
            return ['label' => (string) $row['c'], 'value' => (int) $row['n']];
        },
        $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(NULLIF(utm_campaign, ''), '(sans campagne)') AS c, COUNT(*) AS n
             FROM {$ct}
             WHERE created_at >= %s
             GROUP BY c
             ORDER BY n DESC
             LIMIT 8",
            $m60
        ), ARRAY_A) ?: []
    );

    $out['sources'] = array_map(
        static function (array $row): array {
            return ['label' => (string) $row['s'], 'value' => (int) $row['n']];
        },
        $wpdb->get_results($wpdb->prepare(
            "SELECT CONCAT(COALESCE(NULLIF(utm_source, ''), '(direct)'), ' / ',
                           COALESCE(NULLIF(utm_medium, ''), '—')) AS s,
                    COUNT(*) AS n
             FROM {$ct}
             WHERE created_at >= %s
             GROUP BY s
             ORDER BY n DESC
             LIMIT 6",
            $m60
        ), ARRAY_A) ?: []
    );

    // 9. Offer lens. The campaign naming convention is <OFFRE>-<ANGLE>-<LP>-<VARIANTE>,
    // so the first token is the offer code actually pushed by the media buyer.
    $out['offers'] = array_map(
        static function (array $row): array {
            $clicks = (int) $row['n'];
            $fbp = (int) $row['fbp'];
            return [
                'label' => (string) $row['o'],
                'value' => $clicks,
                'fbp_pct' => $clicks > 0 ? round((100 * $fbp) / $clicks) : 0,
            ];
        },
        $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(NULLIF(SUBSTRING_INDEX(utm_campaign, '-', 1), ''), '(sans campagne)') AS o,
                    COUNT(*) AS n,
                    SUM(fbp IS NOT NULL AND fbp <> '') AS fbp
             FROM {$ct}
             WHERE created_at >= %s
             GROUP BY o
             ORDER BY n DESC
             LIMIT 10",
            $m60
        ), ARRAY_A) ?: []
    );

    // 10. Cloak / referrer hops + geo of the current hour.
    $out['ref_hosts'] = array_map(
        static function (array $row): array {
            return ['label' => (string) $row['h'], 'value' => (int) $row['n']];
        },
        $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(NULLIF(ref_host, ''), '(direct / app)') AS h, COUNT(*) AS n
             FROM {$ct}
             WHERE created_at >= %s
             GROUP BY h
             ORDER BY n DESC
             LIMIT 6",
            $m60
        ), ARRAY_A) ?: []
    );

    $out['geo'] = array_map(
        static function (array $row): array {
            return [
                'iso' => (string) $row['iso'],
                'label' => (string) $row['country'],
                'value' => (int) $row['n'],
            ];
        },
        $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(NULLIF(geo_iso, ''), '??') AS iso,
                    COALESCE(NULLIF(geo_country, ''), 'n/a') AS country,
                    COUNT(*) AS n
             FROM {$ct}
             WHERE created_at >= %s
             GROUP BY iso, country
             ORDER BY n DESC
             LIMIT 6",
            $m60
        ), ARRAY_A) ?: []
    );

    // 11. Per-minute sparkline over the last hour (≤ 60 rows).
    $minuteRows = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE_FORMAT(created_at, '%%Y-%%m-%%d %%H:%%i') AS b,
                COUNT(*) AS n,
                SUM(event_type = 'page_view') AS pv,
                SUM(event_type = 'lead') AS lead
         FROM {$et}
         WHERE created_at >= %s
         GROUP BY b",
        $m60
    ), ARRAY_A) ?: [];
    $byMinute = [];
    foreach ($minuteRows as $row) {
        $byMinute[(string) $row['b']] = [
            'n' => (int) $row['n'],
            'pv' => (int) $row['pv'],
            'lead' => (int) $row['lead'],
        ];
    }
    $minutes = [];
    for ($i = 59; $i >= 0; $i--) {
        $key = date('Y-m-d H:i', $clock['ts'] - ($i * 60));
        $slot = $byMinute[$key] ?? ['n' => 0, 'pv' => 0, 'lead' => 0];
        $slot['label'] = substr($key, 11);
        $minutes[] = $slot;
    }
    $out['minutes'] = $minutes;

    $out['verdict'] = tb_live_verdict($out);

    return $out;
}

/**
 * Traffic-light summary. Deliberately conservative: outside ad hours a quiet
 * tracker is normal, so silence only escalates to red during the day.
 *
 * @param array<string,mixed> $pulse
 * @return array{level:string,label:string,message:string}
 */
function tb_live_verdict(array $pulse): array
{
    if (!$pulse['enabled']) {
        return [
            'level' => 'bad',
            'label' => 'Collecte OFF',
            'message' => 'Le tracker est désactivé : aucun cookie tbclid, aucun page_view, aucun bridge lead. Rien ne remonte tant que la collecte n’est pas réactivée dans Réglages.',
        ];
    }

    $ages = $pulse['ages'];
    $clickAge = $ages['click'];
    $pvAge = $ages['page_view'];
    $hour = (int) wp_date('G');
    $adHours = $hour >= 8 && $hour < 23;
    $quietLimit = $adHours ? 900 : 3600;

    if ($clickAge === null && $pvAge === null) {
        return [
            'level' => 'warn',
            'label' => 'Aucune donnée',
            'message' => 'Les tables sont prêtes mais aucun clic ni page vue n’a encore été enregistré.',
        ];
    }

    $freshest = min(
        $clickAge === null ? PHP_INT_MAX : $clickAge,
        $pvAge === null ? PHP_INT_MAX : $pvAge
    );

    if ($freshest > $quietLimit * 2) {
        return [
            'level' => 'bad',
            'label' => 'Trafic à l’arrêt',
            'message' => sprintf(
                'Rien depuis %s. Vérifier la diffusion des campagnes, le cloaker et la résolution DNS de la landing avant de suspecter le tracker.',
                tb_tracker_admin_age($freshest)
            ),
        ];
    }

    if ($freshest > $quietLimit) {
        return [
            'level' => 'warn',
            'label' => 'Trafic calme',
            'message' => sprintf(
                'Dernier signal il y a %s. Normal hors diffusion, à surveiller si les campagnes tournent.',
                tb_tracker_admin_age($freshest)
            ),
        ];
    }

    if (!$pulse['js_engagement']) {
        return [
            'level' => 'warn',
            'label' => 'JS engagement OFF',
            'message' => 'Le trafic arrive mais tb.js n’est pas chargé : ni scroll, ni CTA, ni form_start, et fbp/fbc ne sont récupérés que via cookie serveur.',
        ];
    }

    $fbpPct = (int) ($pulse['match']['fbp_pct'] ?? 0);
    if ($pulse['match']['total'] >= 20 && $fbpPct < 40) {
        return [
            'level' => 'warn',
            'label' => 'Match Meta faible',
            'message' => sprintf(
                'Seulement %d %% des clics de la dernière heure portent un _fbp. Le pixel se charge tard ou pas du tout sur la landing : la qualité de matching CAPI en souffre.',
                $fbpPct
            ),
        ];
    }

    return [
        'level' => 'ok',
        'label' => 'Tout est vert',
        'message' => sprintf(
            '%d visiteurs actifs sur 5 min, dernier signal il y a %s, %d %% des clics avec _fbp.',
            (int) $pulse['sessions_5m'],
            tb_tracker_admin_age($freshest),
            $fbpPct
        ),
    ];
}

/* --------------------------------------------------------------------------
 * Feed
 * -------------------------------------------------------------------------- */

/**
 * Recent events enriched with their click context.
 *
 * @param array<string,mixed> $filters
 * @return array{rows:array<int,array<string,mixed>>,last_id:int}
 */
function tb_live_feed(array $filters, int $sinceId = 0): array
{
    global $wpdb;

    if (!tb_tracker_admin_tables_ready()) {
        return ['rows' => [], 'last_id' => 0];
    }

    $ct = tb_tracker_table('clicks');
    $et = tb_tracker_table('events');

    // Hard floor: never let a selective filter walk past the scan window.
    $floorId = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT MIN(id) FROM {$et} WHERE created_at >= %s",
        tb_live_since(TB_LIVE_FEED_WINDOW_MIN)
    ));
    if ($floorId <= 0) {
        $floorId = 1;
    }

    $where = ['e.id >= %d'];
    $args = [$floorId];

    if ($sinceId > 0) {
        $where[] = 'e.id > %d';
        $args[] = $sinceId;
    }

    $types = $filters['types'];
    $where[] = 'e.event_type IN (' . implode(',', array_fill(0, count($types), '%s')) . ')';
    foreach ($types as $type) {
        $args[] = $type;
    }

    if ($filters['sku'] !== '') {
        // product_hint / offer_slug only fill in at conversion, so the campaign
        // prefix (YM35-…) is the offer signal available on raw traffic.
        $like = '%' . $wpdb->esc_like($filters['sku']) . '%';
        $where[] = '(c.product_hint LIKE %s OR c.offer_slug LIKE %s OR c.utm_campaign LIKE %s)';
        $args[] = $like;
        $args[] = $like;
        $args[] = $wpdb->esc_like($filters['sku']) . '%';
    }
    if ($filters['campaign'] !== '') {
        $where[] = 'c.utm_campaign LIKE %s';
        $args[] = '%' . $wpdb->esc_like($filters['campaign']) . '%';
    }
    if ($filters['path'] !== '') {
        $where[] = 'e.url LIKE %s';
        $args[] = '%' . $wpdb->esc_like($filters['path']) . '%';
    }
    if ($filters['fbp'] === 'yes') {
        $where[] = "(c.fbp IS NOT NULL AND c.fbp <> '')";
    } elseif ($filters['fbp'] === 'no') {
        $where[] = "(c.fbp IS NULL OR c.fbp = '')";
    }

    $args[] = TB_LIVE_FEED_LIMIT;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT e.id, e.click_id, e.event_type, e.page_id, e.url, e.meta_json, e.created_at,
                c.utm_source, c.utm_medium, c.utm_campaign, c.offer_slug, c.product_hint,
                c.fbp, c.fbc, c.fbclid, c.geo_iso, c.geo_city, c.ref_host
         FROM {$et} e
         LEFT JOIN {$ct} c ON c.click_id = e.click_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY e.id DESC
         LIMIT %d",
        ...$args
    ), ARRAY_A) ?: [];

    $titles = tb_tracker_admin_page_titles(array_column($rows, 'page_id'));

    $out = [];
    $lastId = $sinceId;
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $lastId = max($lastId, $id);
        $meta = json_decode((string) ($row['meta_json'] ?? ''), true);
        $meta = is_array($meta) ? $meta : [];

        $product = trim((string) ($row['product_hint'] ?? ''));
        if ($product === '') {
            $product = trim((string) ($row['offer_slug'] ?? ''));
        }
        if ($product === '' && isset($meta['product'])) {
            $product = trim((string) $meta['product']);
        }

        $out[] = [
            'id' => $id,
            'click_id' => (string) $row['click_id'],
            'click_short' => substr((string) $row['click_id'], 0, 14),
            'type' => (string) $row['event_type'],
            'type_label' => tb_live_event_types()[(string) $row['event_type']] ?? (string) $row['event_type'],
            'at' => (string) $row['created_at'],
            'age' => tb_live_age((string) $row['created_at']),
            'time' => mysql2date('H:i:s', (string) $row['created_at']),
            'path' => tb_tracker_admin_path((string) $row['url']),
            'page_id' => (int) $row['page_id'],
            'landing' => tb_tracker_admin_landing_label((int) $row['page_id'], $titles),
            'product' => $product,
            'campaign' => trim((string) ($row['utm_campaign'] ?? '')),
            'source' => trim((string) ($row['utm_source'] ?? '')),
            'medium' => trim((string) ($row['utm_medium'] ?? '')),
            'ref_host' => trim((string) ($row['ref_host'] ?? '')),
            'geo_iso' => strtoupper(trim((string) ($row['geo_iso'] ?? ''))),
            'geo_flag' => tb_tracker_admin_flag((string) ($row['geo_iso'] ?? '')),
            'geo_city' => trim((string) ($row['geo_city'] ?? '')),
            'has_fbp' => trim((string) ($row['fbp'] ?? '')) !== '',
            'has_fbc' => trim((string) ($row['fbc'] ?? '')) !== '',
            'has_fbclid' => trim((string) ($row['fbclid'] ?? '')) !== '',
            'detail' => tb_live_event_detail((string) $row['event_type'], $meta),
        ];
    }

    return ['rows' => $out, 'last_id' => $lastId];
}

/**
 * One-line, business readable summary of an event payload.
 *
 * @param array<string,mixed> $meta
 */
function tb_live_event_detail(string $type, array $meta): string
{
    switch ($type) {
        case 'lead':
            $bits = array_filter([
                isset($meta['lead_id']) ? '#' . (int) $meta['lead_id'] : '',
                trim((string) ($meta['form_key'] ?? '')),
            ]);
            return implode(' · ', $bits);
        case 'form_start':
            return trim((string) ($meta['form_key'] ?? ''));
        case 'form_error':
            return trim(trim((string) ($meta['form_key'] ?? '')) . ' — ' . trim((string) ($meta['err'] ?? '')), ' —');
        case 'cta_click':
            return trim((string) ($meta['cta_id'] ?? ''));
        case 'scroll':
            return isset($meta['max_pct']) ? ((int) $meta['max_pct']) . ' %' : '';
        case 'engage':
            $active = isset($meta['active_ms']) ? round(((int) $meta['active_ms']) / 1000) . ' s actif' : '';
            $pct = isset($meta['max_pct']) ? ((int) $meta['max_pct']) . ' % scroll' : '';
            return trim($active . ($active !== '' && $pct !== '' ? ' · ' : '') . $pct);
        case 'popup_show':
            return trim((string) ($meta['trigger'] ?? ''));
        case 'popup_skip':
            return trim((string) ($meta['reason'] ?? ''));
        case 'context':
            $bits = array_filter([
                trim((string) ($meta['lang'] ?? '')),
                trim((string) ($meta['net'] ?? '')),
                isset($meta['vw']) ? ((int) $meta['vw']) . 'px' : '',
            ]);
            return implode(' · ', $bits);
        case 'attr_update':
            $bits = [];
            if (!empty($meta['fbp'])) {
                $bits[] = 'fbp';
            }
            if (!empty($meta['fbc'])) {
                $bits[] = 'fbc';
            }
            if (!empty($meta['ttclid'])) {
                $bits[] = 'ttclid';
            }
            return $bits ? implode(' + ', $bits) . ' capté' : '';
        default:
            return '';
    }
}

/**
 * Pre-rendered fragments keyed by the DOM node the client swaps them into.
 * Markup stays single-sourced in PHP; the client only moves strings around.
 *
 * @param array<string,mixed> $pulse
 * @return array<string,string>
 */
function tb_live_pulse_html(array $pulse): array
{
    $capture = static function (callable $render): string {
        ob_start();
        $render();
        return (string) ob_get_clean();
    };

    return [
        'tbl-signals' => $capture(static function () use ($pulse): void {
            tb_live_render_signals($pulse);
        }),
        'tbl-windows' => $capture(static function () use ($pulse): void {
            tb_live_render_windows($pulse['windows']);
        }),
        'tbl-spark' => $capture(static function () use ($pulse): void {
            tb_live_render_spark($pulse['minutes']);
        }),
        'tbl-funnel' => $capture(static function () use ($pulse): void {
            tb_live_render_funnel($pulse['funnel']);
        }),
        'tbl-landings' => $capture(static function () use ($pulse): void {
            tb_live_render_landings($pulse['landings']);
        }),
        'tbl-offers' => $capture(static function () use ($pulse): void {
            tb_live_render_offers($pulse['offers']);
        }),
        'tbl-campaigns' => $capture(static function () use ($pulse): void {
            tb_tracker_admin_rank($pulse['campaigns'], 'amber', 'Aucun nouveau clic sur la dernière heure.');
        }),
        'tbl-sources' => $capture(static function () use ($pulse): void {
            tb_tracker_admin_rank($pulse['sources'], '', 'Aucune source sur la dernière heure.');
        }),
        'tbl-refhosts' => $capture(static function () use ($pulse): void {
            tb_tracker_admin_rank($pulse['ref_hosts'], '', 'Aucun referrer capté.');
        }),
        'tbl-geo' => $capture(static function () use ($pulse): void {
            tb_live_render_geo($pulse['geo']);
        }),
        'tbl-gauge-txt' => tb_live_match_text($pulse['match']),
    ];
}

/* --------------------------------------------------------------------------
 * REST
 * -------------------------------------------------------------------------- */

add_action('rest_api_init', static function (): void {
    register_rest_route('tb/v1', '/live', [
        'methods' => 'GET',
        'permission_callback' => static function (): bool {
            return current_user_can('manage_options');
        },
        'callback' => static function (WP_REST_Request $request) {
            $filters = tb_live_sanitize_filters([
                'types' => $request->get_param('types'),
                'sku' => $request->get_param('sku'),
                'campaign' => $request->get_param('campaign'),
                'path' => $request->get_param('path'),
                'fbp' => $request->get_param('fbp'),
            ]);
            $sinceId = absint($request->get_param('since_id'));
            $wantPulse = $request->get_param('pulse') !== '0';

            $started = microtime(true);
            $feed = tb_live_feed($filters, $sinceId);
            // The client injects the rendered row as-is; shipping the raw fields
            // too would double the payload on every tick for nothing.
            $rows = array_map(static function (array $row): array {
                return ['id' => $row['id'], 'html' => tb_live_feed_row_html($row)];
            }, $feed['rows']);

            $payload = [
                'ok' => true,
                'server_time' => tb_live_now()['now'],
                'feed' => $rows,
                'last_id' => $feed['last_id'],
            ];
            if ($wantPulse) {
                $pulse = tb_live_pulse();
                // Blocks travel as rendered HTML; only what the client computes
                // client-side (verdict tone, gauge angle) needs the raw values.
                $payload['pulse'] = [
                    'ready' => $pulse['ready'],
                    'server_time' => $pulse['server_time'],
                    'verdict' => $pulse['verdict'],
                    'match' => $pulse['match'],
                    'daf' => $pulse['daf'] ?? [],
                ];
                $payload['pulse_html'] = tb_live_pulse_html($pulse);
            }
            $payload['took_ms'] = (int) round((microtime(true) - $started) * 1000);

            $response = new WP_REST_Response($payload, 200);
            $response->header('Cache-Control', 'no-store, max-age=0');
            return $response;
        },
    ]);
});

/* --------------------------------------------------------------------------
 * Page
 * -------------------------------------------------------------------------- */

function tb_tracker_admin_page_live(): void
{
    tb_tracker_admin_guard();

    $pulse = tb_live_pulse();
    $filters = tb_live_sanitize_filters([]);
    $feed = tb_live_feed($filters, 0);

    tb_tracker_admin_shell_open(
        'tb-tracker-live',
        'Live',
        'Ce qui se passe maintenant sur le trafic ads : santé du tracking, flux d’événements, qualité du matching Meta et lecture produit.',
        'Temps réel · fenêtre glissante 5 / 15 / 60 min · heure du site'
    );

    if (!$pulse['ready']) {
        tb_tracker_admin_notice('Les tables du tracker ne sont pas encore créées. Activez la collecte dans <strong>Réglages</strong> pour les initialiser.', 'warn');
        tb_tracker_admin_shell_close();
        return;
    }
    ?>

    <div class="tbl-bar">
      <span class="tbl-live-dot" id="tbl-state">En direct</span>
      <span class="tbl-clock">Rafraîchi <strong id="tbl-last-refresh">à l’instant</strong> · serveur <strong id="tbl-server-time"><?php echo esc_html(mysql2date('H:i:s', $pulse['server_time'])); ?></strong></span>
      <span class="tbl-bar__spacer"></span>
      <button type="button" class="hdcp-btn" id="tbl-pause">Mettre en pause</button>
      <button type="button" class="hdcp-btn hdcp-btn--primary" id="tbl-refresh">Rafraîchir</button>
    </div>

    <div class="tbl-health">
      <div class="tbl-verdict is-<?php echo esc_attr($pulse['verdict']['level']); ?>" id="tbl-verdict">
        <span class="tbl-verdict__badge" id="tbl-verdict-label"><?php echo esc_html($pulse['verdict']['label']); ?></span>
        <span class="tbl-verdict__msg" id="tbl-verdict-msg"><?php echo esc_html($pulse['verdict']['message']); ?></span>
      </div>
      <div class="tbl-signals" id="tbl-signals"><?php tb_live_render_signals($pulse); ?></div>
    </div>

    <div class="tbl-windows">
      <table>
        <thead>
          <tr><th scope="col">Signal</th><th scope="col">5 min</th><th scope="col">15 min</th><th scope="col">60 min</th></tr>
        </thead>
        <tbody id="tbl-windows"><?php tb_live_render_windows($pulse['windows']); ?></tbody>
      </table>
    </div>

    <div class="tbx-grid tbx-grid--wide">
      <div class="hdcp-panel">
        <div class="tbx-panel-head">
          <h2>Flux d’événements</h2>
          <span id="tbl-feed-count"><?php echo count($feed['rows']); ?> événements</span>
        </div>
        <form class="hdcp-toolbar tbx-toolbar" id="tbl-filters" style="margin:0 0 12px" onsubmit="return false">
          <label>Types
            <select class="hdcp-select" id="tbl-f-types" multiple size="4" style="min-width:170px">
              <?php foreach (tb_live_event_types() as $type => $label) : ?>
                <option value="<?php echo esc_attr($type); ?>" <?php selected(in_array($type, $filters['types'], true)); ?>><?php echo esc_html($label); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Offre <input type="text" class="hdcp-search" id="tbl-f-sku" placeholder="YM35" style="max-width:120px"></label>
          <label>Campagne <input type="text" class="hdcp-search" id="tbl-f-campaign" placeholder="DAF-" style="max-width:130px"></label>
          <label>Chemin <input type="text" class="hdcp-search" id="tbl-f-path" placeholder="/lp-" style="max-width:120px"></label>
          <label>_fbp
            <select class="hdcp-select" id="tbl-f-fbp" style="min-width:110px">
              <option value="">Tous</option>
              <option value="yes">Avec fbp</option>
              <option value="no">Sans fbp</option>
            </select>
          </label>
          <button type="button" class="hdcp-btn hdcp-btn--primary" id="tbl-apply">Appliquer</button>
          <button type="button" class="hdcp-btn hdcp-btn--ghost" id="tbl-reset">Réinitialiser</button>
        </form>
        <div class="tbl-feed-wrap">
          <ul class="tbl-feed" id="tbl-feed"><?php tb_live_render_feed($feed['rows']); ?></ul>
        </div>
      </div>

      <div style="display:grid;gap:12px;align-content:start">
        <div class="hdcp-panel">
          <div class="tbx-panel-head"><h2>Rythme</h2><span>événements / minute · 60 min</span></div>
          <div class="tbl-spark" id="tbl-spark"><?php tb_live_render_spark($pulse['minutes']); ?></div>
          <div class="tbl-spark__axis"><span>−60 min</span><span>maintenant</span></div>
        </div>

        <div class="hdcp-panel">
          <div class="tbx-panel-head"><h2>Qualité match Meta</h2><span>clics 60 min</span></div>
          <div class="tbl-gauge">
            <div class="tbl-gauge__ring" id="tbl-gauge" style="--tbx-p:<?php echo (int) $pulse['match']['fbp_pct']; ?>">
              <span id="tbl-gauge-val"><?php echo (int) $pulse['match']['fbp_pct']; ?>%</span>
            </div>
            <p class="tbl-gauge__txt" id="tbl-gauge-txt"><?php echo wp_kses_post(tb_live_match_text($pulse['match'])); ?></p>
          </div>
          <p class="hdcp-help" style="margin:0">Un <code>_fbp</code> présent à l’arrivée du clic = event CAPI matchable. Sans <code>_fbp</code> ni <code>fbc</code>, Meta retombe sur l’IP/UA et la déduplication se dégrade.</p>
        </div>

        <div class="hdcp-panel">
          <div class="tbx-panel-head"><h2>Tunnel 60 min</h2><span>visiteurs uniques</span></div>
          <div id="tbl-funnel"><?php tb_live_render_funnel($pulse['funnel']); ?></div>
        </div>
      </div>
    </div>

    <div class="tbx-grid tbx-grid--wide">
      <div class="hdcp-panel">
        <div class="tbx-panel-head"><h2>Landing pages en vie</h2><span>vues · visiteurs · form · leads · 60 min</span></div>
        <div class="tbx-table-wrap">
          <table class="tbx-table">
            <thead><tr><th>Page</th><th class="tbx-num">Vues</th><th class="tbx-num">Visiteurs</th><th class="tbx-num">Form</th><th class="tbx-num">Leads</th><th class="tbx-num">CVR</th></tr></thead>
            <tbody id="tbl-landings"><?php tb_live_render_landings($pulse['landings']); ?></tbody>
          </table>
        </div>
        <p class="hdcp-help" style="margin:12px 0 0">Le trafic ads arrive sur <code>/?page_id=N</code> : la page est la vraie dimension contenu, le chemin d’URL se réduirait à <code>/</code> pour presque tout le monde.</p>
      </div>
      <div style="display:grid;gap:12px;align-content:start">
        <div class="hdcp-panel">
          <div class="tbx-panel-head"><h2>Offres poussées</h2><span>préfixe de campagne · 60 min</span></div>
          <div class="tbx-table-wrap">
            <table class="tbx-table">
              <thead><tr><th>Offre</th><th class="tbx-num">Clics</th><th class="tbx-num">fbp</th></tr></thead>
              <tbody id="tbl-offers"><?php tb_live_render_offers($pulse['offers']); ?></tbody>
            </table>
          </div>
          <p class="hdcp-help" style="margin:12px 0 0">Lu depuis le premier segment d’<code>utm_campaign</code> (ex. <code>YM35</code> dans <code>YM35-DOL-LP1-V057</code>). Le champ produit du clic ne se remplit qu’à la conversion : inutilisable en direct.</p>
        </div>
        <div class="hdcp-panel">
          <div class="tbx-panel-head"><h2>Géo</h2><span>clics · 60 min</span></div>
          <div class="tbx-table-wrap">
            <table class="tbx-table">
              <thead><tr><th>Pays</th><th class="tbx-num">Clics</th></tr></thead>
              <tbody id="tbl-geo"><?php tb_live_render_geo($pulse['geo']); ?></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="tbx-grid tbx-grid--3">
      <div class="hdcp-panel">
        <div class="tbx-panel-head"><h2>Campagnes</h2><span>nouveaux clics · 60 min</span></div>
        <div id="tbl-campaigns"><?php tb_tracker_admin_rank($pulse['campaigns'], 'amber', 'Aucun nouveau clic sur la dernière heure.'); ?></div>
      </div>
      <div class="hdcp-panel">
        <div class="tbx-panel-head"><h2>Sources</h2><span>utm_source / medium</span></div>
        <div id="tbl-sources"><?php tb_tracker_admin_rank($pulse['sources'], '', 'Aucune source sur la dernière heure.'); ?></div>
      </div>
      <div class="hdcp-panel">
        <div class="tbx-panel-head"><h2>Hop / referrer</h2><span>ref_host des clics</span></div>
        <div id="tbl-refhosts"><?php tb_tracker_admin_rank($pulse['ref_hosts'], '', 'Aucun referrer capté.'); ?></div>
        <p class="hdcp-help" style="margin:12px 0 0">Un referrer vide domine sur du trafic in-app Meta : c’est normal. Un domaine de cloak visible ici signifie que le hop est traçable côté landing.</p>
      </div>
    </div>

    <p class="tbx-note">
      Fenêtres calculées sur l’heure du site (<?php echo esc_html(wp_timezone_string()); ?>). Aucune métrique n’est extrapolée :
      dépense, ROAS et statut CRM ne sont pas dans le schéma du tracker et n’apparaissent donc pas ici.
    </p>
    <?php
    tb_tracker_admin_shell_close('Live en lecture seule · polling 6 s · requêtes bornées (fenêtre + LIMIT)');
}

/* --------------------------------------------------------------------------
 * Server-side render of the blocks the client re-renders on each tick
 * -------------------------------------------------------------------------- */

/**
 * @param array<string,mixed> $pulse
 */
function tb_live_render_signals(array $pulse): void
{
    $ages = $pulse['ages'];
    $signals = [
        ['label' => 'Visiteurs actifs', 'value' => number_format_i18n((int) $pulse['sessions_5m']), 'hint' => '5 dernières minutes', 'level' => $pulse['sessions_5m'] > 0 ? 'ok' : 'warn'],
        ['label' => 'Dernier clic', 'value' => tb_tracker_admin_age($ages['click']), 'hint' => 'nouvelle session tbclid', 'level' => tb_live_age_level($ages['click'], 600, 1800)],
        ['label' => 'Dernière page vue', 'value' => tb_tracker_admin_age($ages['page_view']), 'hint' => 'event page_view', 'level' => tb_live_age_level($ages['page_view'], 600, 1800)],
        ['label' => 'Dernier form start', 'value' => tb_tracker_admin_age($ages['form_start']), 'hint' => 'intention formulaire', 'level' => tb_live_age_level($ages['form_start'], 3600, 10800)],
        ['label' => 'Dernier lead', 'value' => tb_tracker_admin_age($ages['lead']), 'hint' => 'event lead tracker', 'level' => tb_live_age_level($ages['lead'], 7200, 21600)],
        ['label' => 'Dernier lead natif', 'value' => tb_tracker_admin_age($ages['native_lead'] ?? null), 'hint' => 'table hd_leads', 'level' => tb_live_age_level($ages['native_lead'] ?? null, 7200, 21600)],
        [
            'label' => 'DAF share 1h',
            'value' => isset($pulse['daf']['share_pct']) ? ((int) $pulse['daf']['share_pct'] . '%') : 'n/a',
            'hint' => isset($pulse['daf'])
                ? (number_format_i18n((int) $pulse['daf']['leads_m60']) . ' / ' . number_format_i18n((int) $pulse['daf']['leads_total_m60']) . ' leads')
                : 'utm_campaign DAF-',
            'level' => !empty($pulse['daf']['leads_m60']) ? 'ok' : 'warn',
        ],
        [
            'label' => 'Dernier DAF',
            'value' => tb_tracker_admin_age($ages['daf_lead'] ?? null),
            'hint' => 'lead auto-promote draft',
            'level' => tb_live_age_level($ages['daf_lead'] ?? null, 7200, 21600),
        ],
        [
            'label' => 'Drafts ouverts',
            'value' => number_format_i18n((int) ($pulse['daf']['drafts_open'] ?? 0)),
            'hint' => number_format_i18n((int) ($pulse['daf']['drafts_open_ready'] ?? 0)) . ' prêts ≥5 min',
            'level' => ((int) ($pulse['daf']['drafts_open'] ?? 0)) > 0 ? 'ok' : 'na',
        ],
        ['label' => 'JS engagement', 'value' => $pulse['js_engagement'] ? 'ON' : 'OFF', 'hint' => 'tb.js sur les LP', 'level' => $pulse['js_engagement'] ? 'ok' : 'warn'],
        ['label' => 'JS context', 'value' => $pulse['js_context'] ? 'ON' : 'OFF', 'hint' => 'fbp / fbc / lang', 'level' => $pulse['js_context'] ? 'ok' : 'warn'],
    ];

    foreach ($signals as $signal) {
        echo '<div class="tbl-signal is-' . esc_attr($signal['level']) . '">';
        echo '<span class="tbl-signal__l">' . esc_html($signal['label']) . '</span>';
        echo '<span class="tbl-signal__v">' . esc_html($signal['value']) . '</span>';
        echo '<span class="tbl-signal__h">' . esc_html($signal['hint']) . '</span>';
        echo '</div>';
    }
}

function tb_live_age_level(?int $age, int $warnAfter, int $badAfter): string
{
    if ($age === null) {
        return 'na';
    }
    if ($age >= $badAfter) {
        return 'bad';
    }
    if ($age >= $warnAfter) {
        return 'warn';
    }
    return 'ok';
}

/**
 * @param array<string,array<string,int>> $windows
 */
function tb_live_render_windows(array $windows): void
{
    $labels = [
        'new_click' => 'Nouveaux clics',
        'page_view' => 'Pages vues',
        'cta_click' => 'CTA',
        'form_start' => 'Form start',
        'form_error' => 'Form error',
        'popup_show' => 'Popup show',
        'lead' => 'Leads (tracker)',
        'daf_lead' => 'Leads DAF',
        'draft_open' => 'Drafts ouverts',
        'scroll' => 'Scroll',
        'engage' => 'Engage',
    ];

    foreach ($labels as $key => $label) {
        $row = $windows[$key] ?? ['m5' => 0, 'm15' => 0, 'm60' => 0];
        $cls = '';
        if ($key === 'lead' || $key === 'daf_lead') {
            $cls = ' class="tbl-w-lead"';
        } elseif ($key === 'draft_open') {
            $cls = ' class="tbl-w-draft"';
        }
        echo '<tr' . $cls . '>';
        echo '<th scope="row">' . esc_html($label) . '</th>';
        foreach (['m5', 'm15', 'm60'] as $window) {
            $value = (int) $row[$window];
            // draft_open is a stock (same across windows) — still show it.
            echo '<td class="' . ($value === 0 ? 'is-zero' : '') . '">' . esc_html(number_format_i18n($value)) . '</td>';
        }
        echo '</tr>';
    }
}

/**
 * @param array<int,array<string,mixed>> $rows
 */
function tb_live_render_feed(array $rows): void
{
    if (!$rows) {
        echo '<li class="tbl-feed-empty">Aucun événement sur les 6 dernières heures pour ces filtres.</li>';
        return;
    }
    foreach ($rows as $row) {
        echo tb_live_feed_row_html($row);
    }
}

/**
 * Feed row markup, shared by the PHP first paint and the JS refresh
 * (the client rebuilds the same structure — keep both in sync).
 *
 * @param array<string,mixed> $row
 */
function tb_live_feed_row_html(array $row): string
{
    $tags = [];
    if ($row['product'] !== '') {
        $tags[] = '<span class="tbl-tag tbl-tag--sku">' . esc_html($row['product']) . '</span>';
    }
    if ($row['campaign'] !== '') {
        $tags[] = '<span class="tbl-tag">' . esc_html($row['campaign']) . '</span>';
    } elseif ($row['source'] !== '') {
        $tags[] = '<span class="tbl-tag">' . esc_html($row['source']) . '</span>';
    }
    $tags[] = $row['has_fbp']
        ? '<span class="tbl-tag tbl-tag--fbp">fbp</span>'
        : '<span class="tbl-tag tbl-tag--nofbp">no fbp</span>';
    if ($row['has_fbc']) {
        $tags[] = '<span class="tbl-tag tbl-tag--fbc">fbc</span>';
    }
    if ($row['geo_flag'] !== '' || $row['geo_iso'] !== '') {
        $tags[] = '<span class="tbl-tag">' . esc_html(trim($row['geo_flag'] . ' ' . $row['geo_iso'])) . '</span>';
    }
    if ($row['ref_host'] !== '') {
        $tags[] = '<span class="tbl-tag">' . esc_html($row['ref_host']) . '</span>';
    }
    $tags[] = '<span class="tbl-tag tbl-tag--id">' . esc_html($row['click_short']) . '</span>';

    $detail = $row['detail'] !== ''
        ? '<span class="tbl-tag">' . esc_html($row['detail']) . '</span>'
        : '';

    return '<li class="tbl-ev" data-id="' . (int) $row['id'] . '">'
        . '<span class="tbl-ev__t">' . esc_html($row['time']) . '</span>'
        . '<span class="tbl-ev__type" data-t="' . esc_attr($row['type']) . '">' . esc_html($row['type_label']) . '</span>'
        . '<span class="tbl-ev__body">'
        . '<span class="tbl-ev__line1"><span class="tbl-ev__path">' . esc_html($row['landing'] ?? $row['path']) . '</span>' . $detail . '</span>'
        . '<span class="tbl-ev__line2">' . implode('', $tags) . '</span>'
        . '</span></li>';
}

/**
 * @param array<int,array<string,mixed>> $minutes
 */
function tb_live_render_spark(array $minutes): void
{
    $max = 1;
    foreach ($minutes as $minute) {
        $max = max($max, (int) $minute['n']);
    }
    foreach ($minutes as $minute) {
        $height = max(2, round((100 * (int) $minute['n']) / $max));
        $class = 'tbl-spark__b' . (((int) $minute['lead']) > 0 ? ' has-lead' : '');
        echo '<span class="' . esc_attr($class) . '" style="--tbx-h:' . esc_attr((string) $height) . '%" title="'
            . esc_attr($minute['label'] . ' — ' . (int) $minute['n'] . ' événements, ' . (int) $minute['lead'] . ' lead(s)')
            . '"></span>';
    }
}

/**
 * @param array<string,int> $funnel
 */
function tb_live_render_funnel(array $funnel): void
{
    // Visitors, not raw events: a returning visitor has no new click row, so a
    // clicks-first funnel would show more page views than clicks.
    tb_tracker_admin_funnel([
        ['label' => 'Visiteurs (page vue)', 'value' => (int) $funnel['pv']],
        ['label' => 'CTA', 'value' => (int) $funnel['cta']],
        ['label' => 'Form start', 'value' => (int) $funnel['form_start']],
        ['label' => 'Leads', 'value' => (int) $funnel['lead']],
    ]);
    printf(
        '<p class="hdcp-help" style="margin:10px 0 0">Dont <strong>%s</strong> nouveaux clics attribués sur la même heure ; le reste est du visiteur déjà connu.</p>',
        esc_html(number_format_i18n((int) $funnel['clicks']))
    );
}

/**
 * @param array<int,array<string,mixed>> $landings
 */
function tb_live_render_landings(array $landings): void
{
    if (!$landings) {
        echo '<tr><td colspan="6" class="tbx-empty">Aucune page vue sur la dernière heure.</td></tr>';
        return;
    }
    foreach ($landings as $row) {
        $pageId = (int) $row['page_id'];
        echo '<tr>';
        echo '<td><span class="tbx-pill tbx-pill--product">' . esc_html($row['label']) . '</span>';
        if ($pageId > 0) {
            echo '<span class="tbx-sub">page_id ' . $pageId . '</span>';
        }
        echo '</td>';
        echo '<td class="tbx-num">' . esc_html(number_format_i18n((int) $row['value'])) . '</td>';
        echo '<td class="tbx-num">' . esc_html(number_format_i18n((int) $row['visitors'])) . '</td>';
        echo '<td class="tbx-num">' . esc_html(number_format_i18n((int) $row['form_start'])) . '</td>';
        echo '<td class="tbx-num">' . esc_html(number_format_i18n((int) $row['leads'])) . '</td>';
        echo '<td class="tbx-num">' . esc_html(number_format_i18n((float) $row['cvr'], 1)) . ' %</td>';
        echo '</tr>';
    }
}

/**
 * @param array<int,array<string,mixed>> $offers
 */
function tb_live_render_offers(array $offers): void
{
    if (!$offers) {
        echo '<tr><td colspan="3" class="tbx-empty">Aucun clic sur la dernière heure.</td></tr>';
        return;
    }
    foreach ($offers as $offer) {
        echo '<tr>';
        echo '<td><span class="tbx-pill tbx-pill--product">' . esc_html($offer['label']) . '</span></td>';
        echo '<td class="tbx-num">' . esc_html(number_format_i18n((int) $offer['value'])) . '</td>';
        echo '<td class="tbx-num">' . esc_html((string) (int) $offer['fbp_pct']) . ' %</td>';
        echo '</tr>';
    }
}

/**
 * @param array<int,array<string,mixed>> $geo
 */
function tb_live_render_geo(array $geo): void
{
    if (!$geo) {
        echo '<tr><td colspan="2" class="tbx-empty">Pas de géo enrichie sur la dernière heure.</td></tr>';
        return;
    }
    foreach ($geo as $row) {
        $flag = tb_tracker_admin_flag((string) $row['iso']);
        echo '<tr><td>';
        if ($flag !== '') {
            echo '<span class="tbx-flag">' . esc_html($flag) . '</span>';
        }
        echo esc_html($row['label']);
        echo '</td><td class="tbx-num">' . esc_html(number_format_i18n((int) $row['value'])) . '</td></tr>';
    }
}

/**
 * @param array<string,int> $match
 */
function tb_live_match_text(array $match): string
{
    $total = (int) $match['total'];
    if ($total === 0) {
        return 'Aucun nouveau clic sur la dernière heure : rien à évaluer.';
    }
    return sprintf(
        '<strong>%s</strong> clics · <strong>%s</strong> avec _fbp · <strong>%s</strong> avec _fbc · <strong>%s</strong> avec fbclid.',
        number_format_i18n($total),
        number_format_i18n((int) $match['fbp']),
        number_format_i18n((int) $match['fbc']),
        number_format_i18n((int) $match['fbclid'])
    );
}
