<?php
/**
 * TB Tracker admin — Overview / Clics & sessions / Événements / Segments / Réglages.
 *
 * Read-only reporting layer. Collection, ingest and the lead bridge stay in the
 * bootstrap MU plugin; nothing in this file writes tracking data.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolve and strictly bound the reporting period.
 *
 * @return array{start:string,end:string,label:string,preset:string,from:string,to:string,group:string}
 */
function tb_tracker_admin_period(): array
{
    $timezone = wp_timezone();
    $today = current_datetime()->setTime(0, 0, 0);
    $preset = isset($_GET['tb_period']) ? sanitize_key(wp_unslash($_GET['tb_period'])) : '7d';
    $allowed = ['today', 'yesterday', '7d', '30d', 'custom'];
    if (!in_array($preset, $allowed, true)) {
        $preset = '7d';
    }

    if ($preset === 'custom') {
        $from = isset($_GET['tb_from']) ? sanitize_text_field(wp_unslash($_GET['tb_from'])) : '';
        $to = isset($_GET['tb_to']) ? sanitize_text_field(wp_unslash($_GET['tb_to'])) : '';
        $fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $timezone);
        $toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $timezone);
        $validFrom = $fromDate && $fromDate->format('Y-m-d') === $from;
        $validTo = $toDate && $toDate->format('Y-m-d') === $to;

        if ($validFrom && $validTo && $fromDate <= $toDate) {
            $earliest = $today->modify('-365 days');
            if ($fromDate < $earliest) {
                $fromDate = $earliest;
                $from = $fromDate->format('Y-m-d');
            }
            if ($toDate > $today) {
                $toDate = $today;
                $to = $toDate->format('Y-m-d');
            }
            if ($fromDate <= $toDate) {
                return [
                    'start' => $fromDate->format('Y-m-d H:i:s'),
                    'end' => $toDate->modify('+1 day')->format('Y-m-d H:i:s'),
                    'label' => sprintf('Du %s au %s', wp_date('d/m/Y', $fromDate->getTimestamp()), wp_date('d/m/Y', $toDate->getTimestamp())),
                    'preset' => 'custom',
                    'from' => $from,
                    'to' => $to,
                    'group' => $fromDate->diff($toDate)->days <= 2 ? 'hour' : 'day',
                ];
            }
        }
        $preset = '7d';
    }

    if ($preset === 'today') {
        $start = $today;
        $end = $today->modify('+1 day');
        $label = 'Aujourd’hui';
        $group = 'hour';
    } elseif ($preset === 'yesterday') {
        $start = $today->modify('-1 day');
        $end = $today;
        $label = 'Hier';
        $group = 'hour';
    } elseif ($preset === '30d') {
        $start = $today->modify('-29 days');
        $end = $today->modify('+1 day');
        $label = '30 derniers jours';
        $group = 'day';
    } else {
        $preset = '7d';
        $start = $today->modify('-6 days');
        $end = $today->modify('+1 day');
        $label = '7 derniers jours';
        $group = 'day';
    }

    return [
        'start' => $start->format('Y-m-d H:i:s'),
        'end' => $end->format('Y-m-d H:i:s'),
        'label' => $label,
        'preset' => $preset,
        'from' => '',
        'to' => '',
        'group' => $group,
    ];
}

/**
 * Query args carried across links on a reporting page.
 *
 * @return array<string,string>
 */
function tb_tracker_admin_base_args(string $slug, array $period, bool $humansOnly): array
{
    $args = [
        'page' => $slug,
        'tb_period' => $period['preset'],
        'tb_humans' => $humansOnly ? '1' : '0',
    ];
    if ($period['preset'] === 'custom') {
        $args['tb_from'] = $period['from'];
        $args['tb_to'] = $period['to'];
    }
    return $args;
}

/**
 * Shared period + bot filter controls.
 *
 * @param array<int,string> $extraFields Raw HTML for page specific controls.
 */
function tb_tracker_admin_period_form(string $slug, array $period, bool $humansOnly, array $extraFields = []): void
{
    $presets = [
        'today' => 'Aujourd’hui',
        'yesterday' => 'Hier',
        '7d' => '7 jours',
        '30d' => '30 jours',
        'custom' => 'Personnalisée',
    ];
    ?>
    <form method="get" class="hdcp-toolbar tbx-toolbar">
      <input type="hidden" name="page" value="<?php echo esc_attr($slug); ?>">
      <label>Période
        <select name="tb_period" class="hdcp-select">
          <?php foreach ($presets as $value => $label) : ?>
            <option value="<?php echo esc_attr($value); ?>" <?php selected($period['preset'], $value); ?>><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Du <input type="date" name="tb_from" value="<?php echo esc_attr($period['from']); ?>"></label>
      <label>Au <input type="date" name="tb_to" value="<?php echo esc_attr($period['to']); ?>"></label>
      <?php foreach ($extraFields as $field) {
          echo $field; // Already escaped by the caller.
      } ?>
      <label class="hdcp-check"><input type="checkbox" name="tb_humans" value="1" <?php checked($humansOnly); ?>> Humains uniquement</label>
      <button type="submit" class="hdcp-btn hdcp-btn--primary">Filtrer</button>
      <a class="hdcp-btn" href="<?php echo esc_url(tb_tracker_admin_url($slug)); ?>">Réinitialiser</a>
    </form>
    <?php
}

function tb_tracker_admin_humans_only(): bool
{
    return !isset($_GET['tb_humans']) || sanitize_key(wp_unslash($_GET['tb_humans'])) !== '0';
}

/**
 * Dependency-free SVG timeline.
 *
 * @param array<int,array<string,mixed>> $rows
 */
function tb_tracker_admin_chart(array $rows): void
{
    if (!$rows) {
        echo '<p class="tbx-empty">Aucune donnée sur cette période.</p>';
        return;
    }

    $width = 900;
    $height = 250;
    $left = 44;
    $right = 18;
    $top = 18;
    $bottom = 40;
    $plotWidth = $width - $left - $right;
    $plotHeight = $height - $top - $bottom;
    $max = 1;
    foreach ($rows as $row) {
        $max = max($max, (int) $row['visitors'], (int) $row['events'], (int) $row['leads']);
    }

    $series = [
        'visitors' => '#2ec4b6',
        'events' => '#4aa3d9',
        'leads' => '#e8a838',
    ];
    $points = [];
    $areas = [];
    $count = count($rows);
    $baseY = $top + $plotHeight;
    foreach ($series as $key => $color) {
        $line = [];
        foreach ($rows as $index => $row) {
            $x = $left + ($count > 1 ? ($index / ($count - 1)) * $plotWidth : $plotWidth / 2);
            $y = $top + $plotHeight - (((int) $row[$key] / $max) * $plotHeight);
            $line[] = round($x, 1) . ',' . round($y, 1);
        }
        $points[$key] = implode(' ', $line);
        if ($line) {
            $firstX = explode(',', $line[0])[0];
            $lastX = explode(',', $line[count($line) - 1])[0];
            $areas[$key] = $firstX . ',' . $baseY . ' ' . implode(' ', $line) . ' ' . $lastX . ',' . $baseY;
        }
    }
    ?>
    <div class="tbx-chart-scroll">
      <svg class="tbx-chart" viewBox="0 0 <?php echo (int) $width; ?> <?php echo (int) $height; ?>" role="img" aria-label="Évolution des clics, événements et leads">
        <defs>
          <?php foreach ($series as $key => $color) : ?>
            <linearGradient id="tbx-fill-<?php echo esc_attr($key); ?>" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="<?php echo esc_attr($color); ?>" stop-opacity="0.26"/>
              <stop offset="100%" stop-color="<?php echo esc_attr($color); ?>" stop-opacity="0"/>
            </linearGradient>
          <?php endforeach; ?>
        </defs>
        <?php for ($i = 0; $i <= 4; $i++) :
            $y = $top + ($plotHeight * $i / 4);
            $value = (int) round($max * (1 - $i / 4));
            ?>
          <line x1="<?php echo (int) $left; ?>" y1="<?php echo esc_attr((string) $y); ?>" x2="<?php echo (int) ($width - $right); ?>" y2="<?php echo esc_attr((string) $y); ?>" class="tbx-chart__grid"/>
          <text x="<?php echo (int) ($left - 8); ?>" y="<?php echo esc_attr((string) ($y + 4)); ?>" text-anchor="end" class="tbx-chart__axis"><?php echo (int) $value; ?></text>
        <?php endfor; ?>
        <?php foreach ($series as $key => $color) : ?>
          <?php if (!empty($areas[$key])) : ?>
            <polygon points="<?php echo esc_attr($areas[$key]); ?>" fill="url(#tbx-fill-<?php echo esc_attr($key); ?>)"/>
          <?php endif; ?>
          <polyline points="<?php echo esc_attr($points[$key]); ?>" fill="none" stroke="<?php echo esc_attr($color); ?>" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
        <?php endforeach; ?>
        <?php
        $labelIndexes = array_unique([0, (int) floor(($count - 1) / 2), $count - 1]);
        foreach ($labelIndexes as $index) :
            $x = $left + ($count > 1 ? ($index / ($count - 1)) * $plotWidth : $plotWidth / 2);
            ?>
          <text x="<?php echo esc_attr((string) $x); ?>" y="<?php echo (int) ($height - 12); ?>" text-anchor="<?php echo $index === 0 ? 'start' : ($index === $count - 1 ? 'end' : 'middle'); ?>" class="tbx-chart__axis"><?php echo esc_html($rows[$index]['label']); ?></text>
        <?php endforeach; ?>
      </svg>
    </div>
    <div class="tbx-legend">
      <span><i style="--tbx-color:#2ec4b6"></i>Clics créés</span>
      <span><i style="--tbx-color:#4aa3d9"></i>Événements</span>
      <span><i style="--tbx-color:#e8a838"></i>Leads</span>
    </div>
    <?php
}

/**
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function tb_tracker_admin_fill_timeline(array $rows, array $period): array
{
    $indexed = [];
    foreach ($rows as $row) {
        $indexed[$row['bucket']] = $row;
    }

    $result = [];
    $cursor = new DateTimeImmutable($period['start'], wp_timezone());
    $end = new DateTimeImmutable($period['end'], wp_timezone());
    $step = $period['group'] === 'hour' ? '+1 hour' : '+1 day';
    $format = $period['group'] === 'hour' ? 'Y-m-d H:00:00' : 'Y-m-d';
    $labelFormat = $period['group'] === 'hour' ? 'H\\h' : 'd/m';

    while ($cursor < $end && count($result) < 745) {
        $key = $cursor->format($format);
        $row = $indexed[$key] ?? ['visitors' => 0, 'events' => 0, 'leads' => 0];
        $row['label'] = wp_date($labelFormat, $cursor->getTimestamp());
        $result[] = $row;
        $cursor = $cursor->modify($step);
    }
    return $result;
}

/* ==========================================================================
 * Overview
 * ========================================================================== */

function tb_tracker_admin_page_overview(): void
{
    tb_tracker_admin_guard();

    $period = tb_tracker_admin_period();
    $humansOnly = tb_tracker_admin_humans_only();
    $ready = tb_tracker_admin_tables_ready();

    $data = tb_tracker_admin_cached('overview', [$period['start'], $period['end'], $period['group'], $humansOnly], static function () use ($period, $humansOnly): array {
        return tb_tracker_admin_overview_data($period, $humansOnly);
    });
    extract($data, EXTR_OVERWRITE);

    $cvr = $metrics['clicks'] > 0 ? ($metrics['lead_visitors'] / $metrics['clicks']) * 100 : 0.0;
    $fbpPct = $match['total'] > 0 ? (100 * $match['fbp']) / $match['total'] : 0.0;
    $dafPct = $match['total'] > 0 ? (100 * $match['daf']) / $match['total'] : 0.0;

    tb_tracker_admin_shell_open(
        'tb-tracker',
        'Vue d’ensemble',
        'Volume, qualité de matching Meta et tunnel d’intention sur la période choisie.',
        'Période · ' . $period['label'],
        [['label' => 'Ouvrir le Live', 'url' => tb_tracker_admin_url('tb-tracker-live'), 'primary' => true]]
    );

    tb_tracker_admin_page_overview_body($period, $humansOnly, $ready, $data, $cvr, $fbpPct, $dafPct);
}

/**
 * Every aggregate of the overview, in one cacheable payload.
 *
 * @param array<string,string> $period
 * @return array<string,mixed>
 */
function tb_tracker_admin_overview_data(array $period, bool $humansOnly): array
{
    global $wpdb;
    $humanClause = $humansOnly ? tb_tracker_admin_human_clause('c') : '';
    $ready = tb_tracker_admin_tables_ready();
    $ct = tb_tracker_table('clicks');
    $et = tb_tracker_table('events');

    $metrics = [
        'clicks' => 0, 'events' => 0, 'pageviews' => 0, 'pv_visitors' => 0, 'leads' => 0,
        'lead_visitors' => 0, 'form_start' => 0, 'cta' => 0, 'scroll50' => 0,
    ];
    $match = ['total' => 0, 'fbp' => 0, 'fbc' => 0, 'fbclid' => 0, 'daf' => 0];
    $timeline = [];
    $acquisition = [];
    $content = [];
    $products = [];
    $geo = [];
    $popup = ['shows' => 0, 'skip_only' => 0, 'show_leads' => 0, 'skip_only_leads' => 0, 'by_reason' => []];
    $botClicks = 0;
    $hdLeads = null;

    if ($ready) {
        $metrics['clicks'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$ct} c WHERE c.created_at >= %s AND c.created_at < %s {$humanClause}",
            $period['start'],
            $period['end']
        ));

        $eventRow = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS events,
                    SUM(e.event_type = 'page_view') AS pageviews,
                    COUNT(DISTINCT CASE WHEN e.event_type = 'page_view' THEN e.click_id END) AS pv_visitors,
                    SUM(e.event_type = 'lead') AS leads,
                    COUNT(DISTINCT CASE WHEN e.event_type = 'lead' THEN e.click_id END) AS lead_visitors,
                    COUNT(DISTINCT CASE WHEN e.event_type = 'form_start' THEN e.click_id END) AS form_start,
                    COUNT(DISTINCT CASE WHEN e.event_type = 'cta_click' THEN e.click_id END) AS cta,
                    COUNT(DISTINCT CASE WHEN e.event_type = 'scroll'
                          AND (e.meta_json LIKE '%%\"max_pct\":50%%'
                            OR e.meta_json LIKE '%%\"max_pct\":75%%'
                            OR e.meta_json LIKE '%%\"max_pct\":100%%')
                          THEN e.click_id END) AS scroll50
             FROM {$et} e
             INNER JOIN {$ct} c ON c.click_id = e.click_id
             WHERE e.created_at >= %s AND e.created_at < %s {$humanClause}",
            $period['start'],
            $period['end']
        ), ARRAY_A);
        foreach (['events', 'pageviews', 'pv_visitors', 'leads', 'lead_visitors', 'form_start', 'cta', 'scroll50'] as $key) {
            $metrics[$key] = (int) ($eventRow[$key] ?? 0);
        }

        $matchRow = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(c.fbp IS NOT NULL AND c.fbp <> '') AS fbp,
                    SUM(c.fbc IS NOT NULL AND c.fbc <> '') AS fbc,
                    SUM(c.fbclid IS NOT NULL AND c.fbclid <> '') AS fbclid,
                    SUM(c.utm_campaign LIKE 'DAF%%') AS daf
             FROM {$ct} c
             WHERE c.created_at >= %s AND c.created_at < %s {$humanClause}",
            $period['start'],
            $period['end']
        ), ARRAY_A);
        foreach (['total', 'fbp', 'fbc', 'fbclid', 'daf'] as $key) {
            $match[$key] = (int) ($matchRow[$key] ?? 0);
        }

        $eventBucket = $period['group'] === 'hour'
            ? "DATE_FORMAT(e.created_at, '%%Y-%%m-%%d %%H:00:00')"
            : 'DATE(e.created_at)';
        $eventsTimeline = $wpdb->get_results($wpdb->prepare(
            "SELECT {$eventBucket} AS bucket, COUNT(*) AS events, SUM(e.event_type = 'lead') AS leads
             FROM {$et} e
             INNER JOIN {$ct} c ON c.click_id = e.click_id
             WHERE e.created_at >= %s AND e.created_at < %s {$humanClause}
             GROUP BY bucket ORDER BY bucket ASC",
            $period['start'],
            $period['end']
        ), ARRAY_A) ?: [];
        $clickBucket = $period['group'] === 'hour'
            ? "DATE_FORMAT(c.created_at, '%%Y-%%m-%%d %%H:00:00')"
            : 'DATE(c.created_at)';
        $clicksTimeline = $wpdb->get_results($wpdb->prepare(
            "SELECT {$clickBucket} AS bucket, COUNT(*) AS visitors
             FROM {$ct} c
             WHERE c.created_at >= %s AND c.created_at < %s {$humanClause}
             GROUP BY bucket ORDER BY bucket ASC",
            $period['start'],
            $period['end']
        ), ARRAY_A) ?: [];
        $byBucket = [];
        foreach ($eventsTimeline as $row) {
            $byBucket[$row['bucket']] = array_merge(['visitors' => 0], $row);
        }
        foreach ($clicksTimeline as $row) {
            if (!isset($byBucket[$row['bucket']])) {
                $byBucket[$row['bucket']] = ['bucket' => $row['bucket'], 'events' => 0, 'leads' => 0];
            }
            $byBucket[$row['bucket']]['visitors'] = (int) $row['visitors'];
        }
        ksort($byBucket);
        $timeline = tb_tracker_admin_fill_timeline(array_values($byBucket), $period);

        $acquisition = $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(NULLIF(c.utm_source, ''), '(direct)') AS source,
                    COALESCE(NULLIF(c.utm_medium, ''), '—') AS medium,
                    COALESCE(NULLIF(c.utm_campaign, ''), '(sans campagne)') AS campaign,
                    COUNT(*) AS visitors,
                    SUM(c.fbp IS NOT NULL AND c.fbp <> '') AS fbp
             FROM {$ct} c
             WHERE c.created_at >= %s AND c.created_at < %s {$humanClause}
             GROUP BY source, medium, campaign
             ORDER BY visitors DESC
             LIMIT 12",
            $period['start'],
            $period['end']
        ), ARRAY_A) ?: [];

        // Ads land on /?page_id=N, so the page id is the content dimension:
        // grouping on the URL path would collapse almost everything to "/".
        $content = $wpdb->get_results($wpdb->prepare(
            "SELECT e.page_id,
                    COUNT(*) AS views,
                    COUNT(DISTINCT e.click_id) AS visitors
             FROM {$et} e
             INNER JOIN {$ct} c ON c.click_id = e.click_id
             WHERE e.created_at >= %s AND e.created_at < %s
               AND e.event_type = 'page_view' {$humanClause}
             GROUP BY e.page_id
             ORDER BY views DESC
             LIMIT 12",
            $period['start'],
            $period['end']
        ), ARRAY_A) ?: [];

        // Offer = first token of utm_campaign (YM35-DOL-LP1-V057). product_hint
        // is only written when a lead lands, so it cannot rank raw traffic.
        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(NULLIF(SUBSTRING_INDEX(c.utm_campaign, '-', 1), ''), '(sans campagne)') AS product,
                    COUNT(DISTINCT c.click_id) AS clicks,
                    COUNT(DISTINCT CASE WHEN e.event_type = 'lead' THEN e.click_id END) AS leads
             FROM {$ct} c
             LEFT JOIN {$et} e
                    ON e.click_id = c.click_id
                   AND e.event_type = 'lead'
                   AND e.created_at >= %s AND e.created_at < %s
             WHERE c.created_at >= %s AND c.created_at < %s {$humanClause}
             GROUP BY product
             ORDER BY clicks DESC
             LIMIT 12",
            $period['start'],
            $period['end'],
            $period['start'],
            $period['end']
        ), ARRAY_A) ?: [];

        $geo = $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(NULLIF(c.geo_iso, ''), '??') AS iso,
                    COALESCE(NULLIF(c.geo_country, ''), 'n/a') AS country,
                    COUNT(*) AS visitors
             FROM {$ct} c
             WHERE c.created_at >= %s AND c.created_at < %s {$humanClause}
             GROUP BY iso, country
             ORDER BY visitors DESC
             LIMIT 10",
            $period['start'],
            $period['end']
        ), ARRAY_A) ?: [];

        $popupRow = $wpdb->get_row($wpdb->prepare(
            "SELECT SUM(has_show) AS shows,
                    SUM(CASE WHEN has_skip = 1 AND has_show = 0 THEN 1 ELSE 0 END) AS skip_only,
                    SUM(CASE WHEN has_show = 1 AND has_lead = 1 THEN 1 ELSE 0 END) AS show_leads,
                    SUM(CASE WHEN has_skip = 1 AND has_show = 0 AND has_lead = 1 THEN 1 ELSE 0 END) AS skip_only_leads
             FROM (
                SELECT e.click_id,
                       MAX(e.event_type = 'popup_show') AS has_show,
                       MAX(e.event_type = 'popup_skip') AS has_skip,
                       MAX(e.event_type = 'lead') AS has_lead
                FROM {$et} e
                INNER JOIN {$ct} c ON c.click_id = e.click_id
                WHERE e.created_at >= %s AND e.created_at < %s
                  AND e.event_type IN ('popup_show','popup_skip','lead') {$humanClause}
                GROUP BY e.click_id
             ) t",
            $period['start'],
            $period['end']
        ), ARRAY_A) ?: [];
        foreach (['shows', 'skip_only', 'show_leads', 'skip_only_leads'] as $key) {
            $popup[$key] = (int) ($popupRow[$key] ?? 0);
        }
        $popup['by_reason'] = $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.meta_json, '$.reason')), '(n/a)') AS reason,
                    COUNT(DISTINCT e.click_id) AS n
             FROM {$et} e
             INNER JOIN {$ct} c ON c.click_id = e.click_id
             WHERE e.created_at >= %s AND e.created_at < %s
               AND e.event_type = 'popup_skip' {$humanClause}
             GROUP BY reason ORDER BY n DESC LIMIT 8",
            $period['start'],
            $period['end']
        ), ARRAY_A) ?: [];

        $botClause = tb_tracker_admin_bot_clause('c');
        $botClicks = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$ct} c
             WHERE c.created_at >= %s AND c.created_at < %s {$botClause}",
            $period['start'],
            $period['end']
        ));

        $hdTable = $wpdb->prefix . 'hd_leads';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hdTable)) === $hdTable) {
            $hdLeads = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$hdTable} WHERE created_at >= %s AND created_at < %s",
                $period['start'],
                $period['end']
            ));
        }
    }

    return compact('metrics', 'match', 'timeline', 'acquisition', 'content', 'products', 'geo', 'popup', 'botClicks', 'hdLeads');
}

/**
 * @param array<string,string> $period
 * @param array<string,mixed> $data
 */
function tb_tracker_admin_page_overview_body(array $period, bool $humansOnly, bool $ready, array $data, float $cvr, float $fbpPct, float $dafPct): void
{
    extract($data, EXTR_OVERWRITE);

    if (!$ready) {
        tb_tracker_admin_notice('Les tables du tracker ne sont pas encore créées. Activez la collecte dans <strong>Réglages</strong>.');
    }

    tb_tracker_admin_period_form('tb-tracker', $period, $humansOnly);
    ?>

    <div class="hdcp-stats tbx-stats">
      <?php
      tb_tracker_admin_stat(number_format_i18n($metrics['clicks']), 'Clics (tbclid)', 'sessions créées');
      tb_tracker_admin_stat(number_format_i18n($metrics['pv_visitors']), 'Visiteurs vus', number_format_i18n($metrics['pageviews']) . ' pages vues');
      tb_tracker_admin_stat(number_format_i18n($metrics['form_start']), 'Form start', 'visiteurs uniques');
      tb_tracker_admin_stat(number_format_i18n($metrics['lead_visitors']), 'Leads', number_format_i18n($metrics['leads']) . ' events lead');
      tb_tracker_admin_stat(number_format_i18n($cvr, 2) . ' %', 'CVR', 'leads uniques / clics');
      tb_tracker_admin_stat(number_format_i18n($fbpPct, 0) . ' %', 'Clics avec _fbp', number_format_i18n($match['fbp']) . ' / ' . number_format_i18n($match['total']), $fbpPct < 40 ? 'amber' : '');
      tb_tracker_admin_stat(number_format_i18n($dafPct, 0) . ' %', 'Campagnes DAF', number_format_i18n($match['daf']) . ' clics', 'amber');
      tb_tracker_admin_stat(number_format_i18n($botClicks), 'Clics bots (UA)', 'hors filtre humains', 'mute');
      ?>
    </div>

    <?php if ($hdLeads !== null && $hdLeads !== $metrics['leads']) : ?>
      <p class="tbx-note tbx-note--amber">
        <strong>Écart leads :</strong> <?php echo number_format_i18n($metrics['leads']); ?> côté tracker contre
        <?php echo number_format_i18n($hdLeads); ?> dans <code>hd_leads</code> sur la période.
        Le filtre humain ne s’applique pas à <code>hd_leads</code> (UA non stocké) et un lead sans tbclid n’a pas d’event tracker.
      </p>
    <?php endif; ?>

    <div class="tbx-grid tbx-grid--wide">
      <div class="hdcp-panel">
        <div class="tbx-panel-head"><h2>Activité</h2><span><?php echo esc_html($period['label']); ?></span></div>
        <?php tb_tracker_admin_chart($timeline); ?>
      </div>
      <div class="hdcp-panel">
        <div class="tbx-panel-head"><h2>Tunnel d’intention</h2><span>visiteurs uniques</span></div>
        <?php
        tb_tracker_admin_funnel([
            ['label' => 'Visiteurs (page vue)', 'value' => $metrics['pv_visitors']],
            ['label' => 'Scroll ≥ 50 %', 'value' => $metrics['scroll50']],
            ['label' => 'CTA', 'value' => $metrics['cta']],
            ['label' => 'Form start', 'value' => $metrics['form_start']],
            ['label' => 'Leads', 'value' => $metrics['lead_visitors']],
        ]);
        ?>
        <p class="hdcp-help" style="margin:14px 0 0">
          Toutes les étapes comptent des visiteurs uniques (<code>click_id</code> distincts) pour rester comparables.
          <?php echo esc_html(number_format_i18n($metrics['clicks'])); ?> clics ont été créés sur la période : un visiteur qui revient n’en génère pas de nouveau.
          Scroll et CTA n’existent que si <code>tb.js</code> est actif.
        </p>
      </div>
    </div>

    <div class="tbx-grid tbx-grid--2">
      <div class="hdcp-panel">
        <div class="tbx-panel-head"><h2>Offres</h2><span>préfixe de campagne</span></div>
        <div class="tbx-table-wrap">
          <table class="tbx-table">
            <thead><tr><th>Offre</th><th class="tbx-num">Clics</th><th class="tbx-num">Leads</th><th class="tbx-num">CVR</th></tr></thead>
            <tbody>
            <?php if (!$products) : ?>
              <tr><td colspan="4" class="tbx-empty">Aucun clic sur la période.</td></tr>
            <?php else : foreach ($products as $row) :
                $clicks = (int) $row['clicks'];
                $leads = (int) $row['leads'];
                ?>
              <tr>
                <td><span class="tbx-pill tbx-pill--product"><?php echo esc_html((string) $row['product']); ?></span></td>
                <td class="tbx-num"><?php echo esc_html(number_format_i18n($clicks)); ?></td>
                <td class="tbx-num"><?php echo esc_html(number_format_i18n($leads)); ?></td>
                <td class="tbx-num"><?php echo esc_html($clicks > 0 ? number_format_i18n((100 * $leads) / $clicks, 2) . ' %' : '—'); ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <p class="hdcp-help" style="margin:12px 0 0">Lu depuis le premier segment d’<code>utm_campaign</code> (ex. <code>YM35</code> dans <code>YM35-DOL-LP1-V057</code>) : le champ produit du clic ne se remplit qu’à la conversion.</p>
      </div>

      <div class="hdcp-panel">
        <div class="tbx-panel-head"><h2>Acquisition</h2><span>source / medium / campagne</span></div>
        <div class="tbx-table-wrap">
          <table class="tbx-table">
            <thead><tr><th>Source</th><th>Campagne</th><th class="tbx-num">Clics</th><th class="tbx-num">fbp</th></tr></thead>
            <tbody>
            <?php if (!$acquisition) : ?>
              <tr><td colspan="4" class="tbx-empty">Aucune donnée d’acquisition.</td></tr>
            <?php else : foreach ($acquisition as $row) :
                $clicks = (int) $row['visitors'];
                $fbp = (int) $row['fbp'];
                ?>
              <tr>
                <td><?php echo esc_html($row['source']); ?><span class="tbx-sub"><?php echo esc_html($row['medium']); ?></span></td>
                <td><?php echo esc_html($row['campaign']); ?></td>
                <td class="tbx-num"><?php echo esc_html(number_format_i18n($clicks)); ?></td>
                <td class="tbx-num"><?php echo esc_html($clicks > 0 ? number_format_i18n((100 * $fbp) / $clicks, 0) . ' %' : '—'); ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="tbx-grid tbx-grid--3">
      <div class="hdcp-panel">
        <div class="tbx-panel-head"><h2>Landing pages</h2><span>pages vues</span></div>
        <?php
        $contentTitles = tb_tracker_admin_page_titles(array_column($content, 'page_id'));
        tb_tracker_admin_rank(array_map(static function (array $row) use ($contentTitles): array {
            return [
                'label' => tb_tracker_admin_landing_label((int) $row['page_id'], $contentTitles),
                'value' => (int) $row['views'],
                'hint' => number_format_i18n((int) $row['visitors']) . ' visiteurs',
            ];
        }, $content), '', 'Aucune page vue sur la période.');
        ?>
      </div>
      <div class="hdcp-panel">
        <div class="tbx-panel-head"><h2>Géo</h2><span>clics</span></div>
        <div class="tbx-table-wrap">
          <table class="tbx-table">
            <thead><tr><th>Pays</th><th class="tbx-num">Clics</th><th class="tbx-num">Part</th></tr></thead>
            <tbody>
            <?php if (!$geo) : ?>
              <tr><td colspan="3" class="tbx-empty">Pas de géo enrichie.</td></tr>
            <?php else : foreach ($geo as $row) :
                $flag = tb_tracker_admin_flag((string) $row['iso']);
                $share = $metrics['clicks'] > 0 ? (100 * (int) $row['visitors']) / $metrics['clicks'] : 0;
                ?>
              <tr>
                <td><?php if ($flag !== '') : ?><span class="tbx-flag"><?php echo esc_html($flag); ?></span><?php endif; ?><?php echo esc_html($row['country']); ?></td>
                <td class="tbx-num"><?php echo esc_html(number_format_i18n((int) $row['visitors'])); ?></td>
                <td class="tbx-num"><?php echo esc_html(number_format_i18n($share, 0)); ?> %</td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="hdcp-panel">
        <div class="tbx-panel-head"><h2>Popup</h2><span>show vs skip intelligent</span></div>
        <?php
        $showDen = max(1, $popup['shows']);
        $skipDen = max(1, $popup['skip_only']);
        ?>
        <div class="hdcp-stats" style="grid-template-columns:1fr 1fr;margin:0 0 12px">
          <?php
          tb_tracker_admin_stat(number_format_i18n((100 * $popup['show_leads']) / $showDen, 2) . ' %', 'CVR après show', number_format_i18n($popup['shows']) . ' popups');
          tb_tracker_admin_stat(number_format_i18n((100 * $popup['skip_only_leads']) / $skipDen, 2) . ' %', 'CVR après skip', number_format_i18n($popup['skip_only']) . ' skips');
          ?>
        </div>
        <?php
        tb_tracker_admin_rank(array_map(static function (array $row): array {
            return ['label' => (string) $row['reason'], 'value' => (int) $row['n']];
        }, $popup['by_reason']), 'amber', 'Aucun popup_skip sur la période.');
        ?>
      </div>
    </div>

    <p class="tbx-note">
      Un « clic » est un <code>tbclid</code> first-party (cookie ~400 j, rafraîchi à chaque visite) : ni un visiteur unique certain,
      ni une session GA. Les recherches Google ne sont visibles que si le referrer expose <code>q=</code>, ce qui est rare en HTTPS.
    </p>
    <?php
    tb_tracker_admin_shell_close();
}

/** Back-compat: the bootstrap plugin historically pointed the menu here. */
function tb_tracker_admin_v2_render(): void
{
    tb_tracker_admin_page_overview();
}

/* ==========================================================================
 * Clics & sessions
 * ========================================================================== */

function tb_tracker_admin_page_clicks(): void
{
    tb_tracker_admin_guard();

    global $wpdb;
    $period = tb_tracker_admin_period();
    $humansOnly = tb_tracker_admin_humans_only();
    $humanClause = $humansOnly ? tb_tracker_admin_human_clause('c') : '';
    $ready = tb_tracker_admin_tables_ready();
    $ct = tb_tracker_table('clicks');
    $et = tb_tracker_table('events');

    $search = substr(sanitize_text_field(wp_unslash($_GET['tb_search'] ?? '')), 0, 48);
    $sku = substr(sanitize_text_field(wp_unslash($_GET['tb_sku'] ?? '')), 0, 60);
    $campaign = substr(sanitize_text_field(wp_unslash($_GET['tb_campaign'] ?? '')), 0, 60);
    $fbpFilter = sanitize_key(wp_unslash($_GET['tb_fbp'] ?? ''));
    if (!in_array($fbpFilter, ['yes', 'no'], true)) {
        $fbpFilter = '';
    }
    $paged = max(1, absint($_GET['tb_paged'] ?? 1));
    $perPage = 30;

    $rows = [];
    $total = 0;
    $stats = ['clicks' => 0, 'fbp' => 0, 'fbclid' => 0, 'geo' => 0];
    $detail = null;
    $detailEvents = [];

    if ($ready) {
        $where = 'c.created_at >= %s AND c.created_at < %s' . $humanClause;
        $args = [$period['start'], $period['end']];
        if ($search !== '') {
            $where .= ' AND c.click_id LIKE %s';
            $args[] = '%' . $wpdb->esc_like($search) . '%';
        }
        if ($sku !== '') {
            $like = '%' . $wpdb->esc_like($sku) . '%';
            $where .= ' AND (c.product_hint LIKE %s OR c.offer_slug LIKE %s)';
            $args[] = $like;
            $args[] = $like;
        }
        if ($campaign !== '') {
            $where .= ' AND c.utm_campaign LIKE %s';
            $args[] = '%' . $wpdb->esc_like($campaign) . '%';
        }
        if ($fbpFilter === 'yes') {
            $where .= " AND c.fbp IS NOT NULL AND c.fbp <> ''";
        } elseif ($fbpFilter === 'no') {
            $where .= " AND (c.fbp IS NULL OR c.fbp = '')";
        }

        $statRow = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS clicks,
                    SUM(c.fbp IS NOT NULL AND c.fbp <> '') AS fbp,
                    SUM(c.fbclid IS NOT NULL AND c.fbclid <> '') AS fbclid,
                    SUM(c.geo_iso IS NOT NULL AND c.geo_iso <> '') AS geo
             FROM {$ct} c WHERE {$where}",
            ...$args
        ), ARRAY_A) ?: [];
        foreach (['clicks', 'fbp', 'fbclid', 'geo'] as $key) {
            $stats[$key] = (int) ($statRow[$key] ?? 0);
        }
        $total = $stats['clicks'];

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.click_id, c.utm_source, c.utm_medium, c.utm_campaign, c.landing_url,
                    c.offer_slug, c.product_hint, c.ref_host, c.fbp, c.fbc, c.fbclid,
                    c.geo_iso, c.geo_country, c.geo_city, c.created_at, c.last_seen_at
             FROM {$ct} c
             WHERE {$where}
             ORDER BY c.created_at DESC
             LIMIT %d OFFSET %d",
            ...array_merge($args, [$perPage, ($paged - 1) * $perPage])
        ), ARRAY_A) ?: [];

        if ($rows) {
            $ids = array_column($rows, 'click_id');
            $placeholders = implode(',', array_fill(0, count($ids), '%s'));
            $counts = $wpdb->get_results($wpdb->prepare(
                "SELECT click_id, COUNT(*) AS events,
                        SUM(event_type = 'lead') AS leads,
                        SUM(event_type = 'page_view') AS pageviews,
                        MAX(created_at) AS last_at
                 FROM {$et}
                 WHERE click_id IN ({$placeholders})
                 GROUP BY click_id",
                ...$ids
            ), OBJECT_K) ?: [];
            foreach ($rows as &$row) {
                $hit = $counts[$row['click_id']] ?? null;
                $row['events'] = $hit ? (int) $hit->events : 0;
                $row['leads'] = $hit ? (int) $hit->leads : 0;
                $row['pageviews'] = $hit ? (int) $hit->pageviews : 0;
                $row['last_at'] = $hit ? (string) $hit->last_at : (string) $row['last_seen_at'];
            }
            unset($row);
        }

        $detailId = tb_tracker_sanitize_click_id(wp_unslash($_GET['tb_detail'] ?? ''));
        if ($detailId !== '') {
            $detail = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$ct} WHERE click_id = %s LIMIT 1",
                $detailId
            ), ARRAY_A);
            if ($detail) {
                $detailEvents = $wpdb->get_results($wpdb->prepare(
                    "SELECT event_type, url, meta_json, created_at
                     FROM {$et} WHERE click_id = %s
                     ORDER BY id ASC LIMIT 200",
                    $detailId
                ), ARRAY_A) ?: [];
            }
        }
    }

    $baseArgs = tb_tracker_admin_base_args('tb-tracker-clicks', $period, $humansOnly);
    foreach (['tb_search' => $search, 'tb_sku' => $sku, 'tb_campaign' => $campaign, 'tb_fbp' => $fbpFilter] as $key => $value) {
        if ($value !== '') {
            $baseArgs[$key] = $value;
        }
    }

    tb_tracker_admin_shell_open(
        'tb-tracker-clicks',
        'Clics & sessions',
        'Chaque ligne est un tbclid : son acquisition, sa qualité de matching Meta et son parcours complet.',
        'Période · ' . $period['label']
    );

    if (!$ready) {
        tb_tracker_admin_notice('Les tables du tracker ne sont pas encore créées. Activez la collecte dans <strong>Réglages</strong>.');
    }

    tb_tracker_admin_period_form('tb-tracker-clicks', $period, $humansOnly, [
        '<label>Click ID <input type="search" name="tb_search" class="hdcp-search" value="' . esc_attr($search) . '" placeholder="tb_…" style="max-width:150px"></label>',
        '<label>SKU / offre <input type="text" name="tb_sku" class="hdcp-search" value="' . esc_attr($sku) . '" placeholder="YM30" style="max-width:110px"></label>',
        '<label>Campagne <input type="text" name="tb_campaign" class="hdcp-search" value="' . esc_attr($campaign) . '" placeholder="DAF-" style="max-width:130px"></label>',
        '<label>_fbp <select name="tb_fbp" class="hdcp-select" style="min-width:110px">'
            . '<option value="">Tous</option>'
            . '<option value="yes"' . selected($fbpFilter, 'yes', false) . '>Avec fbp</option>'
            . '<option value="no"' . selected($fbpFilter, 'no', false) . '>Sans fbp</option>'
            . '</select></label>',
    ]);
    ?>

    <div class="hdcp-stats tbx-stats">
      <?php
      tb_tracker_admin_stat(number_format_i18n($stats['clicks']), 'Clics filtrés', $period['label']);
      tb_tracker_admin_stat(number_format_i18n($stats['fbp']), 'Avec _fbp', $stats['clicks'] > 0 ? number_format_i18n((100 * $stats['fbp']) / $stats['clicks'], 0) . ' %' : '—');
      tb_tracker_admin_stat(number_format_i18n($stats['fbclid']), 'Avec fbclid', 'clic Meta identifié');
      tb_tracker_admin_stat(number_format_i18n($stats['geo']), 'Géo résolue', 'MaxMind');
      ?>
    </div>

    <?php if ($detail) : ?>
      <div class="hdcp-panel" style="margin:0 12px 14px" id="journey">
        <div class="tbx-panel-head">
          <h2>Parcours <?php echo esc_html($detail['click_id']); ?></h2>
          <a class="hdcp-btn hdcp-btn--ghost" href="<?php echo esc_url(add_query_arg($baseArgs, admin_url('admin.php'))); ?>">Fermer</a>
        </div>
        <dl class="tbx-dl">
          <div><dt>Acquisition</dt><dd><?php echo esc_html(tb_tracker_admin_not_set($detail['utm_source']) . ' / ' . tb_tracker_admin_not_set($detail['utm_medium'])); ?></dd></div>
          <div><dt>Campagne</dt><dd><?php echo esc_html(tb_tracker_admin_not_set($detail['utm_campaign'])); ?></dd></div>
          <div><dt>Produit</dt><dd><span class="tbx-pill tbx-pill--product"><?php echo esc_html(tb_tracker_admin_product_label('', (string) ($detail['product_hint'] ?? ''), (string) ($detail['offer_slug'] ?? ''))); ?></span></dd></div>
          <div><dt>Landing</dt><dd><code><?php echo esc_html(tb_tracker_admin_path($detail['landing_url'])); ?></code></dd></div>
          <div><dt>Referrer</dt><dd><?php echo esc_html(tb_tracker_admin_not_set($detail['ref_host'] ?? '')); ?></dd></div>
          <div><dt>Signaux Meta</dt><dd>
            <span class="tbx-pill <?php echo !empty($detail['fbp']) ? 'tbx-pill--teal' : 'tbx-pill--rose'; ?>"><?php echo !empty($detail['fbp']) ? 'fbp' : 'no fbp'; ?></span>
            <span class="tbx-pill <?php echo !empty($detail['fbc']) ? 'tbx-pill--blue' : 'tbx-pill--off'; ?>">fbc</span>
            <span class="tbx-pill <?php echo !empty($detail['fbclid']) ? 'tbx-pill--blue' : 'tbx-pill--off'; ?>">fbclid</span>
          </dd></div>
          <div><dt>Géo</dt><dd><?php
            $flag = tb_tracker_admin_flag((string) ($detail['geo_iso'] ?? ''));
            $place = trim(((string) ($detail['geo_city'] ?? '')) . ' ' . ((string) ($detail['geo_country'] ?? '')));
            echo esc_html(trim($flag . ' ' . ($place !== '' ? $place : 'n/a')));
          ?></dd></div>
          <div><dt>Langue / TZ</dt><dd><?php echo esc_html(tb_tracker_admin_not_set($detail['lang'] ?? '') . ' · ' . tb_tracker_admin_not_set($detail['tz'] ?? '')); ?></dd></div>
          <div><dt>Créé</dt><dd><?php echo esc_html(mysql2date('d/m/Y H:i:s', $detail['created_at'])); ?></dd></div>
          <div><dt>Dernier signal</dt><dd><?php echo esc_html(mysql2date('d/m/Y H:i:s', $detail['last_seen_at'])); ?></dd></div>
        </dl>
        <?php if ((string) ($detail['user_agent'] ?? '') !== '') : ?>
          <details style="margin-bottom:12px;color:var(--hdcp-muted);font-size:12px">
            <summary style="cursor:pointer">Révéler l’User-Agent brut</summary>
            <code style="display:block;max-width:100%;white-space:normal;margin-top:6px"><?php echo esc_html($detail['user_agent']); ?></code>
          </details>
        <?php endif; ?>
        <ol class="tbx-journey">
          <?php if (!$detailEvents) : ?>
            <li>Aucun événement associé.</li>
          <?php else : foreach ($detailEvents as $event) :
              $meta = json_decode((string) ($event['meta_json'] ?? ''), true);
              $meta = is_array($meta) ? $meta : [];
              $detailText = tb_live_event_detail((string) $event['event_type'], $meta);
              ?>
            <li>
              <time><?php echo esc_html(mysql2date('d/m H:i:s', $event['created_at'])); ?></time>
              <span class="tbl-ev__type" data-t="<?php echo esc_attr($event['event_type']); ?>"><?php echo esc_html($event['event_type']); ?></span>
              <span><code><?php echo esc_html(tb_tracker_admin_path($event['url'])); ?></code>
                <?php if ($detailText !== '') : ?><span class="tbx-sub"><?php echo esc_html($detailText); ?></span><?php endif; ?>
              </span>
            </li>
          <?php endforeach; endif; ?>
        </ol>
        <?php if (count($detailEvents) >= 200) : ?>
          <p class="hdcp-help">Parcours limité aux 200 premiers événements.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="tbx-table-wrap">
      <table class="tbx-table">
        <thead>
          <tr>
            <th>Click ID</th><th>Produit</th><th>Acquisition</th><th>Meta</th><th>Géo</th>
            <th>Landing</th><th class="tbx-num">PV</th><th class="tbx-num">Events</th><th class="tbx-num">Leads</th>
            <th>Créé</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows) : ?>
          <tr><td colspan="11" class="tbx-empty">Aucun clic ne correspond aux filtres.</td></tr>
        <?php else : foreach ($rows as $row) :
            $flag = tb_tracker_admin_flag((string) ($row['geo_iso'] ?? ''));
            $place = trim(((string) ($row['geo_city'] ?? '')) . ' ' . ((string) ($row['geo_country'] ?? '')));
            $detailUrl = add_query_arg(array_merge($baseArgs, ['tb_detail' => $row['click_id'], 'tb_paged' => $paged]), admin_url('admin.php')) . '#journey';
            ?>
          <tr>
            <td><code><?php echo esc_html($row['click_id']); ?></code></td>
            <td><span class="tbx-pill tbx-pill--product"><?php echo esc_html(tb_tracker_admin_product_label('', (string) ($row['product_hint'] ?? ''), (string) ($row['offer_slug'] ?? ''))); ?></span></td>
            <td><?php echo esc_html(tb_tracker_admin_not_set($row['utm_source'])); ?><span class="tbx-sub"><?php echo esc_html(tb_tracker_admin_not_set($row['utm_campaign'])); ?></span></td>
            <td>
              <span class="tbx-pill <?php echo !empty($row['fbp']) ? 'tbx-pill--teal' : 'tbx-pill--rose'; ?>"><?php echo !empty($row['fbp']) ? 'fbp' : 'no fbp'; ?></span>
              <?php if (!empty($row['fbclid'])) : ?><span class="tbx-pill tbx-pill--blue">fbclid</span><?php endif; ?>
            </td>
            <td><?php if ($flag !== '') : ?><span class="tbx-flag"><?php echo esc_html($flag); ?></span><?php endif; ?><?php echo esc_html($place !== '' ? $place : 'n/a'); ?></td>
            <td><code><?php echo esc_html(tb_tracker_admin_path($row['landing_url'])); ?></code></td>
            <td class="tbx-num"><?php echo esc_html(number_format_i18n((int) $row['pageviews'])); ?></td>
            <td class="tbx-num"><?php echo esc_html(number_format_i18n((int) $row['events'])); ?></td>
            <td class="tbx-num"><?php echo esc_html(number_format_i18n((int) $row['leads'])); ?></td>
            <td><?php echo esc_html(mysql2date('d/m H:i', $row['created_at'])); ?><span class="tbx-sub">vu <?php echo esc_html(mysql2date('H:i', $row['last_at'])); ?></span></td>
            <td><a href="<?php echo esc_url($detailUrl); ?>">Parcours</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php
    $totalPages = (int) ceil($total / $perPage);
    if ($totalPages > 1) {
        $paginationArgs = array_merge($baseArgs, ['tb_paged' => '%#%']);
        echo '<div class="tbx-pager">';
        echo wp_kses_post((string) paginate_links([
            'base' => str_replace('%25%23%25', '%#%', esc_url(add_query_arg($paginationArgs, admin_url('admin.php')))),
            'format' => '',
            'current' => $paged,
            'total' => $totalPages,
            'prev_text' => '‹',
            'next_text' => '›',
        ]));
        echo '</div>';
    }

    tb_tracker_admin_shell_close();
}

/* ==========================================================================
 * Événements
 * ========================================================================== */

function tb_tracker_admin_page_events(): void
{
    tb_tracker_admin_guard();

    global $wpdb;
    $period = tb_tracker_admin_period();
    $humansOnly = tb_tracker_admin_humans_only();
    $humanClause = $humansOnly ? tb_tracker_admin_human_clause('c') : '';
    $ready = tb_tracker_admin_tables_ready();
    $ct = tb_tracker_table('clicks');
    $et = tb_tracker_table('events');

    $type = sanitize_key(wp_unslash($_GET['tb_type'] ?? ''));
    $allowedTypes = array_keys(tb_live_event_types());
    if (!in_array($type, $allowedTypes, true)) {
        $type = '';
    }
    $search = substr(sanitize_text_field(wp_unslash($_GET['tb_search'] ?? '')), 0, 48);

    $byType = [];
    $recent = [];
    $totalEvents = 0;

    if ($ready) {
        $byType = tb_tracker_admin_cached('events_by_type', [$period['start'], $period['end'], $humansOnly], static function () use ($wpdb, $et, $ct, $humanClause, $period): array {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT e.event_type,
                        COUNT(*) AS n,
                        COUNT(DISTINCT e.click_id) AS visitors,
                        MAX(e.created_at) AS last_at
                 FROM {$et} e
                 INNER JOIN {$ct} c ON c.click_id = e.click_id
                 WHERE e.created_at >= %s AND e.created_at < %s {$humanClause}
                 GROUP BY e.event_type
                 ORDER BY n DESC",
                $period['start'],
                $period['end']
            ), ARRAY_A) ?: [];
        });
        foreach ($byType as $row) {
            $totalEvents += (int) $row['n'];
        }

        $where = 'e.created_at >= %s AND e.created_at < %s' . $humanClause;
        $args = [$period['start'], $period['end']];
        if ($type !== '') {
            $where .= ' AND e.event_type = %s';
            $args[] = $type;
        }
        if ($search !== '') {
            $where .= ' AND e.click_id LIKE %s';
            $args[] = '%' . $wpdb->esc_like($search) . '%';
        }
        $args[] = 120;

        // Pick the ids first: dragging meta_json (longtext) through the filtered
        // scan costs seconds, while the same scan on ids alone is sub-second.
        // STRAIGHT_JOIN: left to itself the optimizer scans the whole clicks
        // table and filesorts, instead of walking events backwards on the PK.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT STRAIGHT_JOIN e.id
             FROM {$et} e
             INNER JOIN {$ct} c ON c.click_id = e.click_id
             WHERE {$where}
             ORDER BY e.id DESC
             LIMIT %d",
            ...$args
        )) ?: [];

        if ($ids) {
            $ids = array_map('intval', $ids);
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $recent = $wpdb->get_results($wpdb->prepare(
                "SELECT e.id, e.click_id, e.event_type, e.page_id, e.url, e.meta_json, e.created_at,
                        c.utm_campaign, c.utm_source, c.offer_slug, c.product_hint, c.fbp, c.fbc,
                        c.geo_iso, c.geo_city, c.ref_host
                 FROM {$et} e
                 LEFT JOIN {$ct} c ON c.click_id = e.click_id
                 WHERE e.id IN ({$placeholders})
                 ORDER BY e.id DESC",
                ...$ids
            ), ARRAY_A) ?: [];
        }
    }

    tb_tracker_admin_shell_open(
        'tb-tracker-events',
        'Événements',
        'Volumétrie par type et derniers événements bruts, pour vérifier qu’un signal remonte bien avant d’accuser une campagne.',
        'Période · ' . $period['label']
    );

    if (!$ready) {
        tb_tracker_admin_notice('Les tables du tracker ne sont pas encore créées. Activez la collecte dans <strong>Réglages</strong>.');
    }

    $typeOptions = '<label>Type <select name="tb_type" class="hdcp-select" style="min-width:140px"><option value="">Tous</option>';
    foreach (tb_live_event_types() as $value => $label) {
        $typeOptions .= '<option value="' . esc_attr($value) . '"' . selected($type, $value, false) . '>' . esc_html($label) . '</option>';
    }
    $typeOptions .= '</select></label>';

    tb_tracker_admin_period_form('tb-tracker-events', $period, $humansOnly, [
        $typeOptions,
        '<label>Click ID <input type="search" name="tb_search" class="hdcp-search" value="' . esc_attr($search) . '" placeholder="tb_…" style="max-width:150px"></label>',
    ]);
    ?>

    <div class="tbx-grid tbx-grid--2">
      <div class="hdcp-panel">
        <div class="tbx-panel-head"><h2>Répartition par type</h2><span><?php echo esc_html(number_format_i18n($totalEvents)); ?> événements</span></div>
        <div class="tbx-table-wrap">
          <table class="tbx-table">
            <thead><tr><th>Type</th><th class="tbx-num">Volume</th><th class="tbx-num">Visiteurs</th><th class="tbx-num">Part</th><th>Dernier</th></tr></thead>
            <tbody>
            <?php if (!$byType) : ?>
              <tr><td colspan="5" class="tbx-empty">Aucun événement sur la période.</td></tr>
            <?php else : foreach ($byType as $row) :
                $n = (int) $row['n'];
                $filterUrl = add_query_arg(array_merge(
                    tb_tracker_admin_base_args('tb-tracker-events', $period, $humansOnly),
                    ['tb_type' => $row['event_type']]
                ), admin_url('admin.php'));
                ?>
              <tr>
                <td><a href="<?php echo esc_url($filterUrl); ?>"><?php echo esc_html($row['event_type']); ?></a></td>
                <td class="tbx-num"><?php echo esc_html(number_format_i18n($n)); ?></td>
                <td class="tbx-num"><?php echo esc_html(number_format_i18n((int) $row['visitors'])); ?></td>
                <td class="tbx-num"><?php echo esc_html($totalEvents > 0 ? number_format_i18n((100 * $n) / $totalEvents, 1) . ' %' : '—'); ?></td>
                <td><?php echo esc_html(mysql2date('d/m H:i', $row['last_at'])); ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="hdcp-panel">
        <div class="tbx-panel-head"><h2>Volume relatif</h2><span>période filtrée</span></div>
        <?php
        tb_tracker_admin_rank(array_map(static function (array $row): array {
            return ['label' => (string) $row['event_type'], 'value' => (int) $row['n']];
        }, array_slice($byType, 0, 10)), '', 'Aucun événement sur la période.');
        ?>
        <p class="hdcp-help" style="margin:14px 0 0">
          <code>engage</code> et <code>context</code> sont volumineux par nature : ce sont des battements de <code>tb.js</code>, pas des actions utilisateur.
        </p>
      </div>
    </div>

    <div class="tbx-head"><h2>Derniers événements</h2><span>120 max · les plus récents en premier</span></div>
    <div class="tbx-table-wrap">
      <table class="tbx-table">
        <thead><tr><th>Heure</th><th>Type</th><th>Chemin</th><th>Détail</th><th>Produit</th><th>Campagne</th><th>Meta</th><th>Click ID</th></tr></thead>
        <tbody>
        <?php if (!$recent) : ?>
          <tr><td colspan="8" class="tbx-empty">Aucun événement pour ces filtres.</td></tr>
        <?php else : foreach ($recent as $row) :
            $meta = json_decode((string) ($row['meta_json'] ?? ''), true);
            $meta = is_array($meta) ? $meta : [];
            $product = trim((string) ($row['product_hint'] ?? ''));
            if ($product === '') {
                $product = trim((string) ($row['offer_slug'] ?? ''));
            }
            $clickUrl = add_query_arg([
                'page' => 'tb-tracker-clicks',
                'tb_period' => '30d',
                'tb_search' => $row['click_id'],
                'tb_detail' => $row['click_id'],
            ], admin_url('admin.php')) . '#journey';
            ?>
          <tr>
            <td><?php echo esc_html(mysql2date('d/m H:i:s', $row['created_at'])); ?></td>
            <td><span class="tbl-ev__type" data-t="<?php echo esc_attr($row['event_type']); ?>"><?php echo esc_html($row['event_type']); ?></span></td>
            <td><code><?php echo esc_html(tb_tracker_admin_path($row['url'])); ?></code></td>
            <td><?php echo esc_html(tb_live_event_detail((string) $row['event_type'], $meta)); ?></td>
            <td><?php echo $product !== '' ? '<span class="tbx-pill tbx-pill--product">' . esc_html($product) . '</span>' : '—'; ?></td>
            <td><?php echo esc_html(tb_tracker_admin_not_set($row['utm_campaign'])); ?></td>
            <td><span class="tbx-pill <?php echo !empty($row['fbp']) ? 'tbx-pill--teal' : 'tbx-pill--rose'; ?>"><?php echo !empty($row['fbp']) ? 'fbp' : 'no fbp'; ?></span></td>
            <td><a href="<?php echo esc_url($clickUrl); ?>"><code><?php echo esc_html(substr((string) $row['click_id'], 0, 14)); ?></code></a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php
    tb_tracker_admin_shell_close();
}

/* ==========================================================================
 * Segments
 * ========================================================================== */

function tb_tracker_admin_page_segments(): void
{
    tb_tracker_admin_guard();

    tb_tracker_admin_shell_open(
        'tb-tracker-segments',
        'Segments',
        'Audiences locales calculées depuis les clics et événements. Les memberships ne contiennent que des click_id opaques.',
        'Moteur JSON + MySQL · recalcul borné'
    );

    if (function_exists('tb_tracker_segments_admin_render_section')) {
        tb_tracker_segments_admin_render_section();
    } else {
        tb_tracker_admin_notice('Le module segments n’est pas chargé sur cette installation.');
    }

    tb_tracker_admin_shell_close();
}

/* ==========================================================================
 * Réglages
 * ========================================================================== */

function tb_tracker_admin_page_settings(): void
{
    tb_tracker_admin_guard();

    global $wpdb;
    $notice = '';
    if (isset($_POST['tb_tracker_save'])) {
        check_admin_referer('tb_tracker_settings');
        $enabled = !empty($_POST['tb_tracker_enabled']);
        if ($enabled) {
            tb_tracker_install_tables();
        }
        update_option(TB_TRACKER_OPT_ENABLED, $enabled ? 1 : 0, false);
        update_option(TB_TRACKER_OPT_JS_ENGAGEMENT, !empty($_POST['tb_js_engagement']) ? 1 : 0, false);
        update_option(TB_TRACKER_OPT_JS_CONTEXT, !empty($_POST['tb_js_context']) ? 1 : 0, false);
        $sample = isset($_POST['tb_sample_rate']) ? absint($_POST['tb_sample_rate']) : 100;
        update_option(TB_TRACKER_OPT_SAMPLE_RATE, max(1, min(100, $sample)), false);
        $notice = $enabled
            ? 'Réglages enregistrés — collecte front active.'
            : 'Réglages enregistrés — collecte front désactivée, le reporting reste consultable.';
    }

    $enabled = tb_tracker_is_enabled();
    $jsEng = tb_tracker_flag_on(TB_TRACKER_OPT_JS_ENGAGEMENT);
    $jsCtx = tb_tracker_flag_on(TB_TRACKER_OPT_JS_CONTEXT);
    $sample = (int) get_option(TB_TRACKER_OPT_SAMPLE_RATE, 100);
    $ready = tb_tracker_admin_tables_ready();
    $ct = tb_tracker_table('clicks');
    $et = tb_tracker_table('events');
    $clicksTotal = $ready ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ct}") : 0;
    $eventsTotal = $ready ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$et}") : 0;
    $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;

    tb_tracker_admin_shell_open(
        'tb-tracker-settings',
        'Réglages',
        'Interrupteurs de collecte et état technique. Tout est OFF par défaut : rien ne part du front tant qu’un flag n’est pas activé.',
        'Feature flags · schéma v' . TB_TRACKER_DB_VERSION
    );

    if ($notice !== '') {
        tb_tracker_admin_notice($notice);
    }
    ?>

    <div class="hdcp-stats tbx-stats">
      <?php
      tb_tracker_admin_stat($enabled ? 'ON' : 'OFF', 'Collecte front', 'cookie + page_view + leads', $enabled ? '' : 'rose');
      tb_tracker_admin_stat($jsEng ? 'ON' : 'OFF', 'JS engagement', 'tb.js sur les LP', $jsEng ? '' : 'amber');
      tb_tracker_admin_stat($jsCtx ? 'ON' : 'OFF', 'JS context', 'fbp / fbc / lang', $jsCtx ? '' : 'amber');
      tb_tracker_admin_stat($sample . ' %', 'Échantillonnage JS', 'part des visiteurs instrumentés');
      tb_tracker_admin_stat(number_format_i18n($clicksTotal), 'Clics en base', 'table ' . $ct, 'mute');
      tb_tracker_admin_stat(number_format_i18n($eventsTotal), 'Événements en base', 'table ' . $et, 'mute');
      ?>
    </div>

    <div class="tbx-grid tbx-grid--2">
      <div class="hdcp-panel">
        <h2>Collecte</h2>
        <form method="post">
          <?php wp_nonce_field('tb_tracker_settings'); ?>
          <p class="hdcp-help" style="margin-top:0">Activer la collecte crée les tables si nécessaire, puis pose le cookie <code>tbclid</code> et enregistre les <code>page_view</code> côté PHP.</p>
          <label class="hdcp-field" style="display:flex;align-items:center;gap:8px">
            <input type="checkbox" name="tb_tracker_enabled" value="1" <?php checked($enabled); ?>>
            <span style="margin:0;text-transform:none;font-size:14px;color:var(--hdcp-text)">Activer la collecte front</span>
          </label>
          <label class="hdcp-field" style="display:flex;align-items:center;gap:8px">
            <input type="checkbox" name="tb_js_engagement" value="1" <?php checked($jsEng); ?>>
            <span style="margin:0;text-transform:none;font-size:14px;color:var(--hdcp-text)">JS engagement — <code>tb.js</code> : scroll, CTA, form_start, engage</span>
          </label>
          <label class="hdcp-field" style="display:flex;align-items:center;gap:8px">
            <input type="checkbox" name="tb_js_context" value="1" <?php checked($jsCtx); ?>>
            <span style="margin:0;text-transform:none;font-size:14px;color:var(--hdcp-text)">JS context / attribution — lang, timezone, <code>_fbp</code>, <code>_fbc</code></span>
          </label>
          <label class="hdcp-field">
            <span>Échantillonnage JS (%)</span>
            <input type="number" name="tb_sample_rate" min="1" max="100" step="1" value="<?php echo (int) $sample; ?>" style="max-width:120px">
          </label>
          <p class="hdcp-help">Sans « JS engagement », <code>tb.js</code> n’est pas chargé du tout : zéro requête réseau supplémentaire sur les landings.</p>
          <button type="submit" name="tb_tracker_save" class="hdcp-btn hdcp-btn--primary">Enregistrer</button>
        </form>
      </div>

      <div class="hdcp-panel">
        <h2>État technique</h2>
        <dl class="tbx-dl" style="border:0;padding:0;margin-bottom:0">
          <div><dt>Version</dt><dd><?php echo esc_html(TB_TRACKER_VERSION); ?></dd></div>
          <div><dt>Schéma</dt><dd>v<?php echo esc_html((string) get_option(TB_TRACKER_OPT_DBVER, '0')); ?> (cible v<?php echo esc_html(TB_TRACKER_DB_VERSION); ?>)</dd></div>
          <div><dt>Tables</dt><dd><?php echo $ready ? 'présentes' : 'absentes'; ?></dd></div>
          <div><dt>Fuseau du site</dt><dd><?php echo esc_html(wp_timezone_string()); ?></dd></div>
          <div><dt>Heure serveur</dt><dd><?php echo esc_html(current_time('mysql')); ?></dd></div>
          <div><dt>Charge 1/5/15</dt><dd><?php echo is_array($load) ? esc_html(implode(' / ', array_map(static function ($v) {
              return number_format((float) $v, 2);
          }, $load))) : 'n/a'; ?></dd></div>
          <div><dt>Endpoint ingest</dt><dd><code><?php echo esc_html(rest_url('tb/v1/e')); ?></code></dd></div>
          <div><dt>Endpoint live</dt><dd><code><?php echo esc_html(rest_url('tb/v1/live')); ?></code></dd></div>
        </dl>
        <?php if (get_option('timezone_string', '') === '' && (float) get_option('gmt_offset', 0) === 0.0) : ?>
          <p class="tbx-note tbx-note--amber" style="margin:14px 0 0">
            Le fuseau WordPress est un offset nu : toutes les dates sont libellées UTC. Définir <code>Africa/Tunis</code>
            dans Réglages → Général aligne le reporting sur les heures de diffusion réelles.
          </p>
        <?php endif; ?>
      </div>
    </div>

    <p class="tbx-note">
      Ces interrupteurs ne pilotent que la collecte. Le bridge lead (<code>hd_native_lead_saved</code>), la CAPI Meta et Espo
      restent gérés par leurs propres plugins et ne sont pas modifiés depuis cet écran.
    </p>
    <?php
    tb_tracker_admin_shell_close();
}
