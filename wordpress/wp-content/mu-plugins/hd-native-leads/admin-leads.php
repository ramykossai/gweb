<?php
/**
 * HD Native Leads — submissions admin (Forminator-like).
 * UI aligned with HD Catalog Products (slate / teal).
 */

if (!defined('ABSPATH')) {
    exit;
}

function hd_native_leads_admin_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'hd_leads';
}

/**
 * @return array<string,array<string,mixed>>
 */
function hd_native_leads_admin_forms(): array
{
    $forms = get_option(HD_LEADS_OPTION, []);
    return is_array($forms) ? $forms : [];
}

function hd_native_leads_admin_form_label(string $key, array $forms): string
{
    $form = $forms[$key] ?? [];
    $title = trim((string) ($form['title'] ?? $form['form_title'] ?? ''));
    return $title !== '' ? $title : $key;
}

/**
 * SQL expression: unquoted JSON string at path (empty string if missing/null).
 */
function hd_native_leads_admin_json_str(string $path): string
{
    $path = preg_replace('/[^a-zA-Z0-9_.]/', '', $path) ?? '';
    return "COALESCE(NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.{$path}')), 'null'), ''), '')";
}

/**
 * SQL boolean: lead has a usable tbclid (top-level or payload).
 */
function hd_native_leads_admin_has_tbclid_sql(): string
{
    $a = hd_native_leads_admin_json_str('tbclid');
    $b = hd_native_leads_admin_json_str('payload.tbclid');
    $c = hd_native_leads_admin_json_str('payload.hidden_7');
    return "({$a} <> '' OR {$b} <> '' OR {$c} <> '')";
}

/**
 * Known Espo statuses for the filter select (open + final + extras seen in CRM).
 *
 * @return list<string>
 */
function hd_native_leads_admin_known_espo_statuses(): array
{
    $known = [];
    if (function_exists('hd_native_espo_open_statuses')) {
        $known = array_merge($known, hd_native_espo_open_statuses());
    }
    if (function_exists('hd_native_espo_final_statuses')) {
        $known = array_merge($known, hd_native_espo_final_statuses());
    }
    $extra = [
        'Delivered',
        'Canceled',
        'Not Validated',
        'Spam',
        'Double',
    ];
    $known = array_merge($known, $extra);
    $out = [];
    foreach ($known as $s) {
        $s = trim((string) $s);
        if ($s !== '') {
            $out[$s] = $s;
        }
    }
    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($out);
}

/**
 * @return array{preset:string,from:string,to:string,start:string,end:string,label:string}
 */
function hd_native_leads_admin_period(): array
{
    $tz = wp_timezone();
    $today = current_datetime()->setTime(0, 0);
    $preset = sanitize_key(wp_unslash($_GET['lead_period'] ?? '30d'));
    if (!in_array($preset, ['today', 'yesterday', '7d', '30d', 'custom', 'all'], true)) {
        $preset = '30d';
    }

    $from = sanitize_text_field(wp_unslash($_GET['lead_from'] ?? ''));
    $to = sanitize_text_field(wp_unslash($_GET['lead_to'] ?? ''));

    if ($preset === 'today') {
        $start = $today;
        $end = $today->modify('+1 day');
        $label = 'Aujourd’hui';
    } elseif ($preset === 'yesterday') {
        $start = $today->modify('-1 day');
        $end = $today;
        $label = 'Hier';
    } elseif ($preset === '7d') {
        $start = $today->modify('-6 days');
        $end = $today->modify('+1 day');
        $label = '7 derniers jours';
    } elseif ($preset === 'custom' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $start = new DateTimeImmutable($from . ' 00:00:00', $tz);
        $end = (new DateTimeImmutable($to . ' 00:00:00', $tz))->modify('+1 day');
        if ($end <= $start || $end->diff($start)->days > 366) {
            $start = $today->modify('-29 days');
            $end = $today->modify('+1 day');
            $preset = '30d';
        }
        $label = $start->format('d/m/Y') . ' – ' . $end->modify('-1 day')->format('d/m/Y');
    } elseif ($preset === 'all') {
        $start = new DateTimeImmutable('2000-01-01 00:00:00', $tz);
        $end = $today->modify('+1 day');
        $label = 'Toutes les données';
    } else {
        $start = $today->modify('-29 days');
        $end = $today->modify('+1 day');
        $preset = '30d';
        $label = '30 derniers jours';
    }

    return [
        'preset' => $preset,
        'from' => $from,
        'to' => $to,
        'start' => $start->format('Y-m-d H:i:s'),
        'end' => $end->format('Y-m-d H:i:s'),
        'label' => $label,
    ];
}

/**
 * @return array{
 *   where:string,
 *   args:array<int,mixed>,
 *   form:string,
 *   search:string,
 *   espo_status:string,
 *   funnel:string,
 *   tbclid:string,
 *   sku:string,
 *   page_id:string,
 *   source:string,
 *   period:array<string,string>
 * }
 */
function hd_native_leads_admin_filters(): array
{
    global $wpdb;
    $period = hd_native_leads_admin_period();
    $form = sanitize_key(wp_unslash($_GET['lead_form'] ?? ''));
    $search = sanitize_text_field(wp_unslash($_GET['lead_search'] ?? ''));
    $espoStatus = sanitize_text_field(wp_unslash($_GET['lead_espo'] ?? ''));
    $funnel = sanitize_key(wp_unslash($_GET['lead_funnel'] ?? ''));
    $tbclid = sanitize_key(wp_unslash($_GET['lead_tbclid'] ?? ''));
    $sku = sanitize_text_field(wp_unslash($_GET['lead_sku'] ?? ''));
    $pageId = sanitize_text_field(wp_unslash($_GET['lead_page_id'] ?? ''));
    $source = sanitize_text_field(wp_unslash($_GET['lead_source'] ?? ''));

    if (!in_array($funnel, ['', 'confirmed', 'delivered', 'none'], true)) {
        $funnel = '';
    }
    if (!in_array($tbclid, ['', 'yes', 'no'], true)) {
        $tbclid = '';
    }
    if ($espoStatus !== '' && $espoStatus !== '__missing__') {
        $allowed = hd_native_leads_admin_known_espo_statuses();
        if (!in_array($espoStatus, $allowed, true)) {
            // Allow exact match of unexpected CRM labels (length-capped).
            $espoStatus = substr($espoStatus, 0, 64);
        }
    }

    $where = 'created_at >= %s AND created_at < %s';
    $args = [$period['start'], $period['end']];

    if ($form !== '') {
        $where .= ' AND form_key = %s';
        $args[] = $form;
    }
    if ($search !== '') {
        $like = '%' . $wpdb->esc_like($search) . '%';
        if (ctype_digit($search)) {
            $where .= ' AND (id = %d OR name LIKE %s OR phone LIKE %s OR meta LIKE %s)';
            array_push($args, (int) $search, $like, $like, $like);
        } else {
            $where .= ' AND (name LIKE %s OR phone LIKE %s OR meta LIKE %s)';
            array_push($args, $like, $like, $like);
        }
    }
    if ($espoStatus === '__missing__') {
        $where .= ' AND ' . hd_native_leads_admin_json_str('espo_status') . " = ''";
    } elseif ($espoStatus !== '') {
        $where .= ' AND ' . hd_native_leads_admin_json_str('espo_status') . ' = %s';
        $args[] = $espoStatus;
    }
    if ($funnel === 'none') {
        $where .= ' AND ' . hd_native_leads_admin_json_str('funnel_stage') . " = ''";
    } elseif ($funnel !== '') {
        $where .= ' AND ' . hd_native_leads_admin_json_str('funnel_stage') . ' = %s';
        $args[] = $funnel;
    }
    if ($tbclid === 'yes') {
        $where .= ' AND ' . hd_native_leads_admin_has_tbclid_sql();
    } elseif ($tbclid === 'no') {
        $where .= ' AND NOT ' . hd_native_leads_admin_has_tbclid_sql();
    }
    if ($sku !== '') {
        $where .= ' AND ' . hd_native_leads_admin_json_str('product_sku') . ' = %s';
        $args[] = $sku;
    }
    if ($pageId !== '') {
        $where .= ' AND (' . hd_native_leads_admin_json_str('page_id') . ' = %s OR ' . hd_native_leads_admin_json_str('payload.page_id') . ' = %s)';
        $args[] = $pageId;
        $args[] = $pageId;
    }
    if ($source !== '') {
        $where .= ' AND ' . hd_native_leads_admin_json_str('utm_source') . ' = %s';
        $args[] = $source;
    }

    return compact(
        'where',
        'args',
        'form',
        'search',
        'espoStatus',
        'funnel',
        'tbclid',
        'sku',
        'pageId',
        'source',
        'period'
    );
}

/**
 * Query-string args preserved across pagination / export / detail.
 *
 * @param array<string,mixed> $filters
 * @return array<string,string>
 */
function hd_native_leads_admin_base_args(array $filters): array
{
    return array_filter([
        'page' => 'hd-native-leads',
        'lead_period' => (string) ($filters['period']['preset'] ?? '30d'),
        'lead_from' => (string) ($filters['period']['from'] ?? ''),
        'lead_to' => (string) ($filters['period']['to'] ?? ''),
        'lead_form' => (string) ($filters['form'] ?? ''),
        'lead_search' => (string) ($filters['search'] ?? ''),
        'lead_espo' => (string) ($filters['espoStatus'] ?? ''),
        'lead_funnel' => (string) ($filters['funnel'] ?? ''),
        'lead_tbclid' => (string) ($filters['tbclid'] ?? ''),
        'lead_sku' => (string) ($filters['sku'] ?? ''),
        'lead_page_id' => (string) ($filters['pageId'] ?? ''),
        'lead_source' => (string) ($filters['source'] ?? ''),
    ], static function ($v) {
        return $v !== '' && $v !== null;
    });
}

/**
 * Period-scoped KPIs (cached ~45s). Always includes today / yesterday calendar counts.
 *
 * @param array<string,mixed> $filters
 * @return array<string,int|float>
 */
function hd_native_leads_admin_stats(array $filters): array
{
    global $wpdb;
    $table = hd_native_leads_admin_table();
    $cacheKey = 'hdnl_admin_stats_v2_' . md5(wp_json_encode([
        $filters['where'] ?? '',
        $filters['args'] ?? [],
        wp_timezone_string(),
        current_datetime()->format('Y-m-d'),
    ]));
    $cached = get_transient($cacheKey);
    if (is_array($cached)) {
        return $cached;
    }

    $tz = wp_timezone();
    $todayStart = current_datetime()->setTime(0, 0);
    $tomorrow = $todayStart->modify('+1 day');
    $yesterdayStart = $todayStart->modify('-1 day');

    $hasTb = hd_native_leads_admin_has_tbclid_sql();
    $espo = hd_native_leads_admin_json_str('espo_status');
    $funnel = hd_native_leads_admin_json_str('funnel_stage');
    $campaign = hd_native_leads_admin_json_str('utm_campaign');

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN {$hasTb} THEN 1 ELSE 0 END) AS with_tbclid,
            SUM(CASE WHEN NOT {$hasTb} THEN 1 ELSE 0 END) AS without_tbclid,
            SUM(CASE WHEN {$espo} <> '' THEN 1 ELSE 0 END) AS espo_synced,
            SUM(CASE WHEN {$espo} = '' THEN 1 ELSE 0 END) AS espo_missing,
            SUM(CASE WHEN {$funnel} = 'confirmed' THEN 1 ELSE 0 END) AS funnel_confirmed,
            SUM(CASE WHEN {$funnel} = 'delivered' THEN 1 ELSE 0 END) AS funnel_delivered,
            SUM(CASE WHEN {$campaign} LIKE 'DAF-%%' OR {$campaign} LIKE 'daf-%%' THEN 1 ELSE 0 END) AS daf_count,
            COUNT(DISTINCT NULLIF(phone,'')) AS unique_phones,
            COUNT(DISTINCT form_key) AS active_forms
         FROM {$table}
         WHERE {$filters['where']}",
        $filters['args']
    ));

    $todayCount = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND created_at < %s",
        $todayStart->format('Y-m-d H:i:s'),
        $tomorrow->format('Y-m-d H:i:s')
    ));
    $yesterdayCount = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND created_at < %s",
        $yesterdayStart->format('Y-m-d H:i:s'),
        $todayStart->format('Y-m-d H:i:s')
    ));
    $allCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

    $total = (int) ($row->total ?? 0);
    $daf = (int) ($row->daf_count ?? 0);
    $stats = [
        'total' => $total,
        'today' => $todayCount,
        'yesterday' => $yesterdayCount,
        'all' => $allCount,
        'with_tbclid' => (int) ($row->with_tbclid ?? 0),
        'without_tbclid' => (int) ($row->without_tbclid ?? 0),
        'espo_synced' => (int) ($row->espo_synced ?? 0),
        'espo_missing' => (int) ($row->espo_missing ?? 0),
        'funnel_confirmed' => (int) ($row->funnel_confirmed ?? 0),
        'funnel_delivered' => (int) ($row->funnel_delivered ?? 0),
        'daf_count' => $daf,
        'daf_pct' => $total > 0 ? round(100 * $daf / $total, 1) : 0.0,
        'unique_phones' => (int) ($row->unique_phones ?? 0),
        'active_forms' => (int) ($row->active_forms ?? 0),
    ];

    set_transient($cacheKey, $stats, 45);
    return $stats;
}

function hd_native_leads_admin_csv_cell($value): string
{
    $value = (string) $value;
    if (preg_match('/^[=+\-@]/', $value)) {
        $value = "'" . $value;
    }
    return $value;
}

function hd_native_leads_admin_export(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Accès refusé.', 403);
    }
    check_admin_referer('hd_native_leads_export');

    global $wpdb;
    $table = hd_native_leads_admin_table();
    $filters = hd_native_leads_admin_filters();
    $sql = $wpdb->prepare(
        "SELECT id, form_key, name, phone, meta, created_at
         FROM {$table} WHERE {$filters['where']}
         ORDER BY id DESC LIMIT 10000",
        $filters['args']
    );
    $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
    $forms = hd_native_leads_admin_forms();

    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="leads-' . gmdate('Y-m-d-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'wb');
    fputcsv($out, [
        'ID', 'Date', 'Form key', 'Produit / Formulaire', 'Nom', 'Téléphone',
        'TB Click ID', 'UTM source', 'UTM medium', 'UTM campaign', 'FBCLID',
        'SKU', 'Page ID', 'Espo status', 'Funnel', 'URL',
    ]);

    foreach ($rows as $row) {
        $meta = json_decode((string) $row['meta'], true);
        $meta = is_array($meta) ? $meta : [];
        $payload = isset($meta['payload']) && is_array($meta['payload']) ? $meta['payload'] : [];
        fputcsv($out, array_map('hd_native_leads_admin_csv_cell', [
            $row['id'],
            $row['created_at'],
            $row['form_key'],
            hd_native_leads_admin_form_label((string) $row['form_key'], $forms),
            $row['name'],
            $row['phone'],
            $meta['tbclid'] ?? $payload['tbclid'] ?? $payload['hidden_7'] ?? '',
            $meta['utm_source'] ?? '',
            $meta['utm_medium'] ?? '',
            $meta['utm_campaign'] ?? '',
            $meta['fbclid'] ?? '',
            $meta['product_sku'] ?? '',
            $meta['page_id'] ?? ($payload['page_id'] ?? ''),
            $meta['espo_status'] ?? '',
            $meta['funnel_stage'] ?? '',
            $payload['current_url'] ?? $meta['wp_http_referer'] ?? '',
        ]));
    }
    fclose($out);
    exit;
}
add_action('admin_post_hd_native_leads_export', 'hd_native_leads_admin_export');

function hd_native_leads_admin_assets(string $hook): void
{
    if ($hook !== 'toplevel_page_hd-native-leads') {
        return;
    }

    $catalogCss = (defined('HD_CATALOG_PRODUCTS_DIR')
        ? HD_CATALOG_PRODUCTS_DIR
        : WP_CONTENT_DIR . '/mu-plugins/hd-catalog-products') . '/assets/admin.css';
    $extraCss = __DIR__ . '/assets/admin.css';
    $css = '';
    if (is_readable($catalogCss)) {
        $css .= (string) file_get_contents($catalogCss);
    }
    if (is_readable($extraCss)) {
        $css .= "\n" . (string) file_get_contents($extraCss);
    }

    wp_register_style('hd-native-leads-admin', false, [], '2.0.0');
    wp_enqueue_style('hd-native-leads-admin');
    if ($css !== '') {
        wp_add_inline_style('hd-native-leads-admin', $css);
    }
    wp_enqueue_style(
        'hd-catalog-products-fonts',
        'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&family=Syne:wght@600;700&display=swap',
        [],
        null
    );
}

/**
 * CSS class for Espo status pill.
 */
function hd_native_leads_admin_status_class(string $status): string
{
    $slug = sanitize_title($status);
    if ($slug === '') {
        return 'hdnl-status';
    }
    return 'hdnl-status hdnl-status--' . $slug;
}
add_action('admin_enqueue_scripts', 'hd_native_leads_admin_assets');

function hd_native_leads_admin_menu(): void
{
    add_menu_page(
        'Leads',
        'Leads',
        'manage_options',
        'hd-native-leads',
        'hd_native_leads_admin_render',
        'dashicons-groups',
        59
    );
    // Without a self-referencing first submenu, WordPress repoints the "Leads"
    // top-level link to whatever submenu another plugin attaches first.
    add_submenu_page(
        'hd-native-leads',
        'Leads',
        'Tous les leads',
        'manage_options',
        'hd-native-leads',
        'hd_native_leads_admin_render'
    );
}
// Priority 9: must claim submenu slot 0 before hd-catalog-products (priority 10).
add_action('admin_menu', 'hd_native_leads_admin_menu', 9);

function hd_native_leads_admin_render(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Accès refusé.', 403);
    }

    global $wpdb;
    $table = hd_native_leads_admin_table();
    $filters = hd_native_leads_admin_filters();
    $forms = hd_native_leads_admin_forms();
    $page = max(1, absint($_GET['lead_paged'] ?? 1));
    $perPage = 30;
    $offset = ($page - 1) * $perPage;

    $stats = hd_native_leads_admin_stats($filters);
    $total = (int) $stats['total'];

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, form_key, name, phone, meta, created_at
         FROM {$table} WHERE {$filters['where']}
         ORDER BY id DESC LIMIT %d OFFSET %d",
        array_merge($filters['args'], [$perPage, $offset])
    ), ARRAY_A) ?: [];

    $baseArgs = hd_native_leads_admin_base_args($filters);
    $exportUrl = wp_nonce_url(
        add_query_arg(array_merge($baseArgs, ['action' => 'hd_native_leads_export']), admin_url('admin-post.php')),
        'hd_native_leads_export'
    );

    if (!empty($_GET['espo_sync'])) {
        $msg = 'Sync Espo (vagues douces) — vérifiés&nbsp;: '
            . (int) ($_GET['checked'] ?? 0)
            . ' · mis à jour&nbsp;: ' . (int) ($_GET['updated'] ?? 0)
            . ' · introuvables&nbsp;: ' . (int) ($_GET['missing'] ?? 0)
            . ' · vagues&nbsp;: ' . (int) ($_GET['waves'] ?? 0);
        if (!empty($_GET['wrapped'])) {
            $msg .= ' · <strong>tour complet</strong>';
        } elseif (!empty($_GET['continued'])) {
            $msg .= ' · suite planifiée dans ~1 min (budget temps)';
        }
        if (!empty($_GET['cursor'])) {
            $msg .= ' · curseur&nbsp;#' . (int) $_GET['cursor'];
        }
        echo '<div class="notice notice-success is-dismissible hdnl-notice"><p>' . $msg . '</p></div>';
    }
    if (!empty($_GET['espo_settings_saved'])) {
        echo '<div class="notice notice-success is-dismissible hdnl-notice"><p>Paramètres sync Espo enregistrés (cron réarmé si besoin).</p></div>';
    }

    $syncUrl = wp_nonce_url(
        admin_url('admin-post.php?action=hd_native_espo_status_sync'),
        'hd_native_espo_status_sync'
    );
    $settings = function_exists('hd_native_espo_sync_settings')
        ? hd_native_espo_sync_settings()
        : ['enabled' => true, 'interval' => 120, 'batch' => 25, 'delay_ms' => 150, 'load_max' => 25, 'cursor' => 0, 'from_hour' => 9, 'to_hour' => 19];
    $lastRun = get_option('hd_native_espo_status_last_run', null);
    $cronNext = function_exists('wp_next_scheduled') ? wp_next_scheduled('hd_native_espo_status_sync') : false;
    $openHint = function_exists('hd_native_espo_open_leads_estimate')
        ? hd_native_espo_open_leads_estimate(400)
        : ['open_in_scan' => 0, 'scanned' => 0, 'table_total' => 0, 'truncated' => false];
    $inWindow = function_exists('hd_native_espo_sync_in_time_window') ? hd_native_espo_sync_in_time_window() : true;
    $windowLabel = function_exists('hd_native_espo_sync_window_label')
        ? hd_native_espo_sync_window_label($settings)
        : '09h–19h';
    $intervalLabel = function_exists('hd_native_espo_sync_interval_label')
        ? hd_native_espo_sync_interval_label((int) $settings['interval'])
        : ((int) $settings['interval'] . ' min');
    $hourOptions = range(0, 23);
    $openStatuses = function_exists('hd_native_espo_open_statuses') ? hd_native_espo_open_statuses() : [];
    $knownStatuses = hd_native_leads_admin_known_espo_statuses();
    $resetUrl = admin_url('admin.php?page=hd-native-leads');
    ?>
    <div class="wrap hdcp-wrap hdnl-wrap">
      <div class="hdcp-hero">
        <div>
          <p class="hdcp-eyebrow">Native leads · Espo</p>
          <h1 class="hdcp-title">Leads</h1>
          <p class="hdcp-sub">Soumissions locales avant Espo LeadCapture. Données personnelles — accès administrateur uniquement.</p>
        </div>
        <div class="hdcp-hero-actions">
          <a class="hdcp-btn hdcp-btn--primary" href="<?php echo esc_url($exportUrl); ?>">Exporter CSV</a>
          <a class="hdcp-btn" href="<?php echo esc_url($syncUrl); ?>">Sync ouverts</a>
        </div>
      </div>

      <div class="hdcp-stats hdnl-stats">
        <div class="hdcp-stat"><span class="hdcp-stat__n"><?php echo number_format_i18n($stats['total']); ?></span><span class="hdcp-stat__l">Période filtrée</span></div>
        <div class="hdcp-stat"><span class="hdcp-stat__n"><?php echo number_format_i18n($stats['today']); ?></span><span class="hdcp-stat__l">Aujourd’hui</span></div>
        <div class="hdcp-stat"><span class="hdcp-stat__n"><?php echo number_format_i18n($stats['yesterday']); ?></span><span class="hdcp-stat__l">Hier</span></div>
        <div class="hdcp-stat"><span class="hdcp-stat__n"><?php echo number_format_i18n($stats['with_tbclid']); ?></span><span class="hdcp-stat__l">Avec tbclid</span></div>
        <div class="hdcp-stat"><span class="hdcp-stat__n"><?php echo number_format_i18n($stats['without_tbclid']); ?></span><span class="hdcp-stat__l">Sans tbclid</span></div>
        <div class="hdcp-stat"><span class="hdcp-stat__n"><?php echo number_format_i18n($stats['espo_synced']); ?></span><span class="hdcp-stat__l">Espo synced</span></div>
        <div class="hdcp-stat"><span class="hdcp-stat__n"><?php echo number_format_i18n($stats['espo_missing']); ?></span><span class="hdcp-stat__l">Espo missing</span></div>
        <div class="hdcp-stat"><span class="hdcp-stat__n"><?php echo number_format_i18n($stats['funnel_confirmed']); ?></span><span class="hdcp-stat__l">Funnel confirmed</span></div>
        <div class="hdcp-stat"><span class="hdcp-stat__n"><?php echo number_format_i18n($stats['funnel_delivered']); ?></span><span class="hdcp-stat__l">Funnel delivered</span></div>
        <div class="hdcp-stat"><span class="hdcp-stat__n"><?php echo esc_html((string) $stats['daf_pct']); ?>%</span><span class="hdcp-stat__l">DAF · <?php echo number_format_i18n($stats['daf_count']); ?></span></div>
      </div>

      <form method="get" class="hdcp-toolbar hdnl-toolbar">
        <input type="hidden" name="page" value="hd-native-leads">
        <label>Période
          <select name="lead_period" class="hdcp-select">
            <?php foreach ([
                'today' => 'Aujourd’hui',
                'yesterday' => 'Hier',
                '7d' => '7 jours',
                '30d' => '30 jours',
                'custom' => 'Personnalisée',
                'all' => 'Tout',
            ] as $value => $label) : ?>
              <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['period']['preset'], $value); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Du <input type="date" name="lead_from" value="<?php echo esc_attr($filters['period']['from']); ?>"></label>
        <label>Au <input type="date" name="lead_to" value="<?php echo esc_attr($filters['period']['to']); ?>"></label>
        <label>Produit / Form
          <select name="lead_form" class="hdcp-select">
            <option value="">Tous</option>
            <?php foreach ($forms as $key => $form) : ?>
              <option value="<?php echo esc_attr($key); ?>" <?php selected($filters['form'], $key); ?>><?php echo esc_html(hd_native_leads_admin_form_label((string) $key, $forms) . ' · ' . $key); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Statut Espo
          <select name="lead_espo" class="hdcp-select">
            <option value="">Tous</option>
            <option value="__missing__" <?php selected($filters['espoStatus'], '__missing__'); ?>>Pas encore sync</option>
            <?php foreach ($knownStatuses as $st) : ?>
              <option value="<?php echo esc_attr($st); ?>" <?php selected($filters['espoStatus'], $st); ?>><?php echo esc_html($st); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Funnel
          <select name="lead_funnel" class="hdcp-select">
            <option value="">Tous</option>
            <option value="confirmed" <?php selected($filters['funnel'], 'confirmed'); ?>>confirmed</option>
            <option value="delivered" <?php selected($filters['funnel'], 'delivered'); ?>>delivered</option>
            <option value="none" <?php selected($filters['funnel'], 'none'); ?>>aucun</option>
          </select>
        </label>
        <label>tbclid
          <select name="lead_tbclid" class="hdcp-select">
            <option value="">Tous</option>
            <option value="yes" <?php selected($filters['tbclid'], 'yes'); ?>>Avec</option>
            <option value="no" <?php selected($filters['tbclid'], 'no'); ?>>Sans</option>
          </select>
        </label>
        <label>SKU <input type="text" name="lead_sku" class="hdcp-search" value="<?php echo esc_attr($filters['sku']); ?>" placeholder="YM30" style="max-width:110px"></label>
        <label>page_id <input type="text" name="lead_page_id" class="hdcp-search" value="<?php echo esc_attr($filters['pageId']); ?>" placeholder="5716" style="max-width:100px"></label>
        <label>Source <input type="text" name="lead_source" class="hdcp-search" value="<?php echo esc_attr($filters['source']); ?>" placeholder="FB" style="max-width:90px"></label>
        <label>Recherche <input type="search" name="lead_search" class="hdcp-search" value="<?php echo esc_attr($filters['search']); ?>" placeholder="Nom, tél, id, tbclid…"></label>
        <button type="submit" class="hdcp-btn hdcp-btn--primary">Filtrer</button>
        <a class="hdcp-btn" href="<?php echo esc_url($resetUrl); ?>">Réinitialiser</a>
      </form>

      <p class="hdnl-meta-line">
        <strong><?php echo esc_html($filters['period']['label']); ?></strong>
        · <?php echo number_format_i18n($total); ?> résultat(s)
        · <?php echo number_format_i18n($stats['all']); ?> total historique
        · <?php echo number_format_i18n($stats['unique_phones']); ?> tél. uniques
        · <?php echo number_format_i18n($stats['active_forms']); ?> forms actifs
      </p>

      <div class="hdnl-espo-panel">
        <div class="hdcp-panel">
          <h2>Sync Espo — leads encore « temporaires »</h2>
          <p class="hdcp-help" style="margin-top:0">
            Relire dans Espo le statut des leads non finaux, puis mettre à jour l’espace client.
            Intervalle = passage sur tous les ouverts en vagues douces. Plage = heures actives (fuseau WP).
          </p>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="hd_native_espo_sync_settings">
            <?php wp_nonce_field('hd_native_espo_sync_settings'); ?>
            <div class="hdnl-espo-grid">
              <label>Cron automatique
                <select name="espo_sync_enabled">
                  <option value="1" <?php selected(!empty($settings['enabled'])); ?>>Activé</option>
                  <option value="0" <?php selected(empty($settings['enabled'])); ?>>Désactivé</option>
                </select>
              </label>
              <label>Toutes les…
                <select name="espo_sync_interval">
                  <?php foreach (function_exists('hd_native_espo_sync_allowed_intervals') ? hd_native_espo_sync_allowed_intervals() : [60, 120] as $min) : ?>
                    <option value="<?php echo (int) $min; ?>" <?php selected((int) $settings['interval'], $min); ?>><?php
                      echo esc_html(function_exists('hd_native_espo_sync_interval_label') ? hd_native_espo_sync_interval_label((int) $min) : ($min . ' min'));
                    ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>Heures actives — de
                <select name="espo_sync_from_hour">
                  <?php foreach ($hourOptions as $h) : ?>
                    <option value="<?php echo (int) $h; ?>" <?php selected((int) ($settings['from_hour'] ?? 9), $h); ?>><?php echo esc_html(sprintf('%02dh', $h)); ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>Heures actives — jusqu’à
                <select name="espo_sync_to_hour">
                  <?php foreach ($hourOptions as $h) : ?>
                    <option value="<?php echo (int) $h; ?>" <?php selected((int) ($settings['to_hour'] ?? 19), $h); ?>><?php echo esc_html(sprintf('%02dh', $h)); ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>Taille d’une vague
                <input type="number" name="espo_sync_batch" min="5" max="100" step="1" value="<?php echo (int) $settings['batch']; ?>">
              </label>
              <label>Pause API (ms)
                <input type="number" name="espo_sync_delay_ms" min="0" max="2000" step="10" value="<?php echo (int) $settings['delay_ms']; ?>">
              </label>
              <label>Pause si charge &gt;
                <input type="number" name="espo_sync_load_max" min="5" max="100" step="1" value="<?php echo (int) $settings['load_max']; ?>">
              </label>
              <label style="justify-content:end">
                <span style="display:flex;align-items:center;gap:8px;margin-top:18px;text-transform:none;letter-spacing:0;font-weight:400">
                  <input type="checkbox" name="espo_sync_reset_cursor" value="1"> Remettre le curseur à 0
                </span>
              </label>
            </div>
            <p class="hdcp-help" style="margin:8px 0 0">
              Statuts temporaires&nbsp;: <code><?php echo esc_html(implode(', ', $openStatuses)); ?></code>
            </p>
            <p style="margin:12px 0 0;display:flex;flex-wrap:wrap;gap:8px">
              <button type="submit" class="hdcp-btn hdcp-btn--primary">Enregistrer</button>
              <a class="hdcp-btn" href="<?php echo esc_url($syncUrl); ?>">Sync maintenant</a>
            </p>
          </form>
        </div>
        <div class="hdcp-panel">
          <h2>État en direct</h2>
          <p class="hdnl-espo-meta">
            Cron&nbsp;: <?php echo !empty($settings['enabled']) ? '<strong>ON</strong>' : '<strong>OFF</strong>'; ?>
            · toutes les <strong><?php echo esc_html($intervalLabel); ?></strong>
            · vague <?php echo (int) $settings['batch']; ?> leads
            · pause <?php echo (int) $settings['delay_ms']; ?>&nbsp;ms<br>
            Plage&nbsp;: <?php echo esc_html($windowLabel); ?>
            · maintenant <?php echo $inWindow ? '<strong style="color:#9aebc8">dans la fenêtre</strong>' : '<strong style="color:#f0d08a">hors fenêtre</strong>'; ?><br>
            Prochain passage auto&nbsp;: <?php
              echo $cronNext
                  ? esc_html(wp_date('d/m/Y H:i', (int) $cronNext))
                  : (!empty($settings['enabled']) ? 'non armé (Enregistrer pour réarmer)' : '—');
            ?><br>
            Curseur&nbsp;: <code>#<?php echo (int) $settings['cursor']; ?></code>
            · ouverts (scan <?php echo (int) $openHint['scanned']; ?><?php echo !empty($openHint['truncated']) ? '+' : ''; ?>)&nbsp;:
            <strong><?php echo (int) $openHint['open_in_scan']; ?></strong>
            / <?php echo (int) $openHint['table_total']; ?> total<br>
            <?php
            if (is_array($lastRun) && !empty($lastRun['at'])) {
                echo 'Dernier run&nbsp;: ' . esc_html((string) $lastRun['at'])
                    . ' · ' . esc_html((string) ($lastRun['source'] ?? '?'))
                    . ' · ' . (int) ($lastRun['waves'] ?? 1) . ' vague(s)'
                    . ' · upd ' . (int) ($lastRun['updated'] ?? 0)
                    . ' / chk ' . (int) ($lastRun['checked'] ?? 0);
                if (!empty($lastRun['wrapped'])) {
                    echo ' · <strong>tour OK</strong>';
                }
                if (!empty($lastRun['continued'])) {
                    echo ' · suite en cours';
                }
                if (!empty($lastRun['skipped_load'])) {
                    echo ' · <em>skippée (charge)</em>';
                }
                if (!empty($lastRun['skipped_window'])) {
                    echo ' · <em>skippée (plage)</em>';
                }
            } else {
                echo 'Dernier run&nbsp;: —';
            }
            ?>
          </p>
          <p class="hdnl-espo-meta">
            Chaque passage repart du début et enchaîne des vagues jusqu’à avoir vu tous les leads encore temporaires.
            Au-delà de ~45–90&nbsp;s, une suite douce est planifiée 1&nbsp;min plus tard.
          </p>
        </div>
      </div>

      <div class="hdnl-table-wrap">
        <table class="hdnl-table">
          <thead>
            <tr>
              <th style="width:70px">ID</th>
              <th style="width:130px">Date</th>
              <th>Produit / Form</th>
              <th>Client</th>
              <th>Téléphone</th>
              <th>Statut CRM</th>
              <th>Attribution</th>
              <th style="width:70px"></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows) : ?>
            <tr><td colspan="8" class="hdnl-empty">Aucun lead pour ces filtres.</td></tr>
          <?php else : foreach ($rows as $row) :
            $meta = json_decode((string) $row['meta'], true);
            $meta = is_array($meta) ? $meta : [];
            $payload = isset($meta['payload']) && is_array($meta['payload']) ? $meta['payload'] : [];
            $tbclidVal = (string) ($meta['tbclid'] ?? ($payload['tbclid'] ?? $payload['hidden_7'] ?? ''));
            $espoStatus = trim((string) ($meta['espo_status'] ?? ''));
            $funnelStage = trim((string) ($meta['funnel_stage'] ?? ''));
            $sku = trim((string) ($meta['product_sku'] ?? ''));
            $detailUrl = add_query_arg(array_merge($baseArgs, ['lead_id' => (int) $row['id'], 'lead_paged' => $page]), admin_url('admin.php'));
          ?>
            <tr>
              <td>#<?php echo (int) $row['id']; ?></td>
              <td><?php echo esc_html(mysql2date('d/m/Y H:i', $row['created_at'])); ?></td>
              <td>
                <strong><?php echo esc_html(hd_native_leads_admin_form_label((string) $row['form_key'], $forms)); ?></strong>
                <span class="hdnl-sub"><?php echo esc_html($row['form_key']); ?><?php echo $sku !== '' ? ' · ' . esc_html($sku) : ''; ?></span>
              </td>
              <td><?php echo esc_html($row['name']); ?></td>
              <td><a href="tel:<?php echo esc_attr(preg_replace('/[^\d+]/', '', (string) $row['phone'])); ?>"><?php echo esc_html($row['phone']); ?></a></td>
              <td><?php
                if ($espoStatus !== '') {
                    echo '<span class="' . esc_attr(hd_native_leads_admin_status_class($espoStatus)) . '">' . esc_html($espoStatus) . '</span>';
                    if (!empty($meta['espo_synced_at'])) {
                        echo '<span class="hdnl-sub">' . esc_html(mysql2date('d/m H:i', (string) $meta['espo_synced_at'])) . '</span>';
                    }
                } else {
                    echo '<span class="hdnl-sub">(pas encore sync)</span>';
                }
                if ($funnelStage !== '') {
                    echo '<span class="hdnl-funnel">funnel · ' . esc_html($funnelStage) . '</span>';
                }
              ?></td>
              <td>
                <?php echo $tbclidVal !== '' ? '<span class="hdnl-pill">tbclid</span>' : '<span class="hdnl-sub">(non attribué)</span>'; ?>
                <span class="hdnl-sub"><?php echo esc_html($meta['utm_campaign'] ?? $meta['utm_source'] ?? ''); ?></span>
              </td>
              <td><a href="<?php echo esc_url($detailUrl); ?>">Voir</a></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <?php
      $pages = max(1, (int) ceil($total / $perPage));
      if ($pages > 1) {
          echo '<div class="hdnl-pager">';
          echo wp_kses_post(paginate_links([
              'base' => add_query_arg(array_merge($baseArgs, ['lead_paged' => '%#%']), admin_url('admin.php')),
              'current' => $page,
              'total' => $pages,
          ]));
          echo '</div>';
      }

      $leadId = absint($_GET['lead_id'] ?? 0);
      if ($leadId > 0) {
          $lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $leadId), ARRAY_A);
          if ($lead) {
              $meta = json_decode((string) $lead['meta'], true);
              $meta = is_array($meta) ? $meta : [];
              $payload = isset($meta['payload']) && is_array($meta['payload']) ? $meta['payload'] : [];
              $fields = [
                  'ID' => '#' . $lead['id'],
                  'Date' => mysql2date('d/m/Y H:i:s', $lead['created_at']),
                  'Produit / Formulaire' => hd_native_leads_admin_form_label((string) $lead['form_key'], $forms) . ' (' . $lead['form_key'] . ')',
                  'Nom' => $lead['name'],
                  'Téléphone' => $lead['phone'],
                  'SKU' => $meta['product_sku'] ?? '',
                  'Page ID' => (string) ($meta['page_id'] ?? ($payload['page_id'] ?? '')),
                  'TB Click ID' => $meta['tbclid'] ?? $payload['tbclid'] ?? $payload['hidden_7'] ?? '(non attribué)',
                  'UTM source' => $meta['utm_source'] ?? '',
                  'UTM medium' => $meta['utm_medium'] ?? '',
                  'UTM campaign' => $meta['utm_campaign'] ?? '',
                  'UTM content' => $meta['utm_content'] ?? '',
                  'FBCLID' => $meta['fbclid'] ?? '',
                  'Page / URL' => $payload['current_url'] ?? $meta['wp_http_referer'] ?? '',
                  'Project ID' => $meta['project_id'] ?? '',
                  'Espo envoi' => !empty($meta['espo']['ok'])
                      ? ('OK · HTTP ' . (int) ($meta['espo']['code'] ?? 0) . ' · ' . (string) ($meta['espo']['at'] ?? ''))
                      : (isset($meta['espo']) ? ('échec · HTTP ' . (int) ($meta['espo']['code'] ?? 0)) : '(non envoyé / avant Espo)'),
                  'Statut CRM (Espo)' => trim((string) ($meta['espo_status'] ?? '')) !== ''
                      ? ((string) $meta['espo_status'] . (!empty($meta['espo_cleadid']) ? ' · ' . $meta['espo_cleadid'] : ''))
                      : '(pas encore sync)',
                  'Funnel stage' => (string) ($meta['funnel_stage'] ?? ''),
                  'Confirmé le' => (string) ($meta['espo_confirmed_at'] ?? ''),
                  'Livré le' => (string) ($meta['espo_delivered_at'] ?? ''),
                  'Espo Lead ID' => (string) ($meta['espo_lead_id'] ?? ''),
                  'Dernière sync statut' => (string) ($meta['espo_synced_at'] ?? ''),
              ];
              $trackingComment = trim((string) ($meta['trackingcomment'] ?? ''));
              ?>
              <div class="hdcp-panel hdnl-detail">
                <h2>Détail du lead #<?php echo (int) $lead['id']; ?></h2>
                <dl class="hdnl-meta">
                  <?php foreach ($fields as $label => $value) : ?>
                    <dt><?php echo esc_html($label); ?></dt><dd><?php echo esc_html((string) $value); ?></dd>
                  <?php endforeach; ?>
                  <dt>Commentaire tracking</dt>
                  <dd style="white-space:pre-wrap;max-width:52rem"><?php
                    if ($trackingComment !== '') {
                        echo esc_html($trackingComment);
                    } else {
                        echo '<span class="hdnl-sub">(absent — leads antérieurs à la génération)</span>';
                    }
                  ?></dd>
                </dl>
                <p style="margin:16px 0 0">
                  <a class="hdcp-btn" href="<?php echo esc_url(add_query_arg($baseArgs, admin_url('admin.php'))); ?>">Fermer le détail</a>
                </p>
              </div>
              <?php
          }
      }
      ?>
    </div>
    <?php
}
