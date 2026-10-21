<?php
/**
 * TB Tracker admin shell — menu, sub-pages, assets and shared UI primitives.
 *
 * Design system is shared with Leads / Produits: hd-catalog-products/assets/admin.css
 * provides the `hdcp-*` tokens and chrome, tb-tracker/assets/admin.css adds the
 * tracker specific components (tables, charts, Live module).
 *
 * Read-only layer: nothing here touches front collection.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sub-pages of the tracker, in menu order.
 *
 * @return array<string, array{label:string,menu:string,dot:bool}>
 */
function tb_tracker_admin_pages(): array
{
    return [
        'tb-tracker' => ['label' => 'Vue d’ensemble', 'menu' => 'Vue d’ensemble', 'dot' => false],
        'tb-tracker-live' => ['label' => 'Live', 'menu' => 'Live', 'dot' => true],
        'tb-tracker-clicks' => ['label' => 'Clics & sessions', 'menu' => 'Clics & sessions', 'dot' => false],
        'tb-tracker-events' => ['label' => 'Événements', 'menu' => 'Événements', 'dot' => false],
        'tb-tracker-segments' => ['label' => 'Segments', 'menu' => 'Segments', 'dot' => false],
        'tb-tracker-settings' => ['label' => 'Réglages', 'menu' => 'Réglages', 'dot' => false],
    ];
}

function tb_tracker_admin_url(string $slug, array $args = []): string
{
    return add_query_arg(array_merge(['page' => $slug], $args), admin_url('admin.php'));
}

/**
 * Current tracker sub-page slug, or '' when we are elsewhere in wp-admin.
 */
function tb_tracker_admin_current_slug(): string
{
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    return isset(tb_tracker_admin_pages()[$page]) ? $page : '';
}

/* --------------------------------------------------------------------------
 * Menu
 * -------------------------------------------------------------------------- */

add_action('admin_menu', static function (): void {
    add_menu_page(
        'TB Tracker',
        'TB Tracker',
        'manage_options',
        'tb-tracker',
        'tb_tracker_admin_page_overview',
        'dashicons-chart-area',
        58
    );

    $callbacks = [
        'tb-tracker' => 'tb_tracker_admin_page_overview',
        'tb-tracker-live' => 'tb_tracker_admin_page_live',
        'tb-tracker-clicks' => 'tb_tracker_admin_page_clicks',
        'tb-tracker-events' => 'tb_tracker_admin_page_events',
        'tb-tracker-segments' => 'tb_tracker_admin_page_segments',
        'tb-tracker-settings' => 'tb_tracker_admin_page_settings',
    ];

    foreach (tb_tracker_admin_pages() as $slug => $def) {
        add_submenu_page(
            'tb-tracker',
            'TB Tracker — ' . $def['label'],
            $def['menu'],
            'manage_options',
            $slug,
            $callbacks[$slug]
        );
    }
}, 9);

/* --------------------------------------------------------------------------
 * Assets (tracker admin pages only)
 * -------------------------------------------------------------------------- */

function tb_tracker_admin_assets(string $hook): void
{
    unset($hook);
    $slug = tb_tracker_admin_current_slug();
    if ($slug === '') {
        return;
    }

    $catalogCss = (defined('HD_CATALOG_PRODUCTS_DIR')
        ? HD_CATALOG_PRODUCTS_DIR
        : WP_CONTENT_DIR . '/mu-plugins/hd-catalog-products') . '/assets/admin.css';
    $trackerCss = __DIR__ . '/assets/admin.css';

    $css = '';
    if (is_readable($catalogCss)) {
        $css .= (string) file_get_contents($catalogCss);
    }
    if (is_readable($trackerCss)) {
        $css .= "\n" . (string) file_get_contents($trackerCss);
    }

    wp_enqueue_style(
        'tb-tracker-fonts',
        'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Syne:wght@600;700;800&display=swap',
        [],
        null
    );
    wp_register_style('tb-tracker-admin', false, ['tb-tracker-fonts'], TB_TRACKER_VERSION);
    wp_enqueue_style('tb-tracker-admin');
    if ($css !== '') {
        wp_add_inline_style('tb-tracker-admin', $css);
    }

    if ($slug === 'tb-tracker-live') {
        wp_enqueue_script(
            'tb-tracker-admin-live',
            content_url('mu-plugins/tb-tracker/assets/admin-live.js'),
            [],
            TB_TRACKER_VERSION,
            true
        );
        wp_localize_script('tb-tracker-admin-live', 'tbLiveCfg', [
            'endpoint' => esc_url_raw(rest_url('tb/v1/live')),
            'nonce' => wp_create_nonce('wp_rest'),
            'intervalMs' => 6000,
            'pulseEveryMs' => 15000,
            'clicksUrl' => tb_tracker_admin_url('tb-tracker-clicks'),
        ]);
    }
}
add_action('admin_enqueue_scripts', 'tb_tracker_admin_assets');

/* --------------------------------------------------------------------------
 * Shell
 * -------------------------------------------------------------------------- */

/**
 * Open the page wrapper: hero + sub-page nav.
 *
 * @param array<int,array{label:string,url:string,primary?:bool}> $actions
 */
function tb_tracker_admin_shell_open(string $slug, string $title, string $sub, string $eyebrow = '', array $actions = []): void
{
    $enabled = tb_tracker_is_enabled();
    $jsEng = tb_tracker_flag_on(TB_TRACKER_OPT_JS_ENGAGEMENT);
    if ($enabled && $jsEng) {
        $stateClass = 'tbx-state--on';
        $stateLabel = 'Collecte + JS actifs';
    } elseif ($enabled) {
        $stateClass = 'tbx-state--warn';
        $stateLabel = 'Collecte active · JS off';
    } else {
        $stateClass = 'tbx-state--off';
        $stateLabel = 'Collecte inactive';
    }
    if ($eyebrow === '') {
        $eyebrow = 'TB Tracker v' . TB_TRACKER_VERSION . ' · first-party tbclid';
    }
    ?>
    <div class="wrap hdcp-wrap tbx-wrap">
      <div class="hdcp-hero tbx-hero">
        <div>
          <p class="hdcp-eyebrow"><?php echo esc_html($eyebrow); ?></p>
          <h1 class="hdcp-title"><?php echo esc_html($title); ?></h1>
          <?php if ($sub !== '') : ?><p class="hdcp-sub"><?php echo esc_html($sub); ?></p><?php endif; ?>
        </div>
        <div class="hdcp-hero-actions tbx-hero__side">
          <span class="tbx-state <?php echo esc_attr($stateClass); ?>"><?php echo esc_html($stateLabel); ?></span>
          <?php if ($actions) : ?>
            <span style="display:flex;flex-wrap:wrap;gap:8px">
            <?php foreach ($actions as $action) : ?>
              <a class="hdcp-btn<?php echo !empty($action['primary']) ? ' hdcp-btn--primary' : ''; ?>" href="<?php echo esc_url($action['url']); ?>"><?php echo esc_html($action['label']); ?></a>
            <?php endforeach; ?>
            </span>
          <?php endif; ?>
        </div>
      </div>

      <nav class="tbx-nav" aria-label="Sections du tracker">
        <?php foreach (tb_tracker_admin_pages() as $pageSlug => $def) : ?>
          <a class="tbx-nav__item<?php echo $pageSlug === $slug ? ' is-active' : ''; ?>"
             href="<?php echo esc_url(tb_tracker_admin_url($pageSlug)); ?>"
             <?php echo $pageSlug === $slug ? 'aria-current="page"' : ''; ?>>
            <?php if (!empty($def['dot'])) : ?><span class="tbx-nav__dot" aria-hidden="true"></span><?php endif; ?>
            <?php echo esc_html($def['label']); ?>
          </a>
        <?php endforeach; ?>
      </nav>
    <?php
}

function tb_tracker_admin_shell_close(string $footNote = ''): void
{
    if ($footNote === '') {
        $footNote = 'Rapports en lecture seule. Aucune donnée personnelle brute n’est affichée : les parcours sont identifiés par click_id opaque.';
    }
    ?>
      <p class="tbx-foot">TB Tracker <?php echo esc_html(TB_TRACKER_VERSION); ?> · <?php echo esc_html($footNote); ?></p>
    </div>
    <?php
}

function tb_tracker_admin_guard(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Vous n’avez pas les droits suffisants.', 'tb-tracker'), 403);
    }
}

/* --------------------------------------------------------------------------
 * Shared primitives
 * -------------------------------------------------------------------------- */

function tb_tracker_admin_notice(string $message, string $tone = 'ok'): void
{
    $class = $tone === 'ok' ? 'hdcp-notice hdcp-notice--ok' : 'hdcp-notice';
    echo '<div class="' . esc_attr($class) . '">' . wp_kses_post($message) . '</div>';
}

function tb_tracker_admin_stat($value, string $label, string $hint = '', string $mod = ''): void
{
    $class = 'hdcp-stat' . ($mod !== '' ? ' tbx-stat--' . $mod : '');
    echo '<div class="' . esc_attr($class) . '">';
    echo '<span class="hdcp-stat__n">' . esc_html((string) $value) . '</span>';
    echo '<span class="hdcp-stat__l">' . esc_html($label) . '</span>';
    if ($hint !== '') {
        echo '<span class="tbx-stat__d">' . esc_html($hint) . '</span>';
    }
    echo '</div>';
}

function tb_tracker_admin_path($url): string
{
    $url = is_string($url) ? $url : '';
    if ($url === '') {
        return '—';
    }
    if ($url[0] === '/') {
        return '/' . ltrim(explode('?', $url)[0], '/');
    }
    $path = wp_parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return '/';
    }
    return '/' . ltrim($path, '/');
}

function tb_tracker_admin_not_set($value): string
{
    $value = is_string($value) ? trim($value) : '';
    return $value === '' ? '(not set)' : $value;
}

/**
 * Human readable age, French, compact ("12 s", "4 min", "2 h 10").
 */
function tb_tracker_admin_age(?int $seconds): string
{
    if ($seconds === null || $seconds < 0) {
        return 'n/a';
    }
    if ($seconds < 60) {
        return $seconds . ' s';
    }
    if ($seconds < 3600) {
        return (int) floor($seconds / 60) . ' min';
    }
    if ($seconds < 86400) {
        $h = (int) floor($seconds / 3600);
        $m = (int) floor(($seconds % 3600) / 60);
        return $h . ' h' . ($m > 0 ? ' ' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) : '');
    }
    return (int) floor($seconds / 86400) . ' j';
}

/** Raw UA signatures treated as non-human. Matching is done on the lowercased UA. */
const TB_TRACKER_BOT_UA_REGEXP = '(tb-liveaudit|curl|facebookexternalhit|googlebot|bingbot|duckduckbot|'
    . 'yandexbot|baiduspider|bot([/ ;_-]|$)|spider|crawler|slurp|headlesschrome|lighthouse|pagespeed|'
    . 'uptimerobot|pingdom)';

/** Above this many distinct bot UAs the IN() list stops being worth it. */
const TB_TRACKER_BOT_UA_MAX = 400;

/**
 * Distinct bot user agents actually present in the clicks table.
 *
 * Running the REGEXP on every row costs seconds on a table this size, while
 * resolving it once against the distinct UAs and reusing the resulting list as
 * an IN() set costs milliseconds. Cached 15 min: a brand new bot signature can
 * take that long to show up in the filter.
 *
 * @return array<int,string>|null Null when the list is too large to be usable.
 */
function tb_tracker_admin_bot_agents(): ?array
{
    static $memo = false;
    if ($memo !== false) {
        return $memo;
    }

    $cached = get_transient('tb_tracker_bot_agents');
    if (is_array($cached)) {
        $memo = $cached === ['__overflow__'] ? null : $cached;
        return $memo;
    }

    global $wpdb;
    $ct = tb_tracker_table('clicks');
    $agents = $wpdb->get_col(
        "SELECT ua FROM (SELECT DISTINCT user_agent AS ua FROM {$ct}) t
         WHERE LOWER(COALESCE(ua, '')) REGEXP '" . TB_TRACKER_BOT_UA_REGEXP . "'
         LIMIT " . (TB_TRACKER_BOT_UA_MAX + 1)
    ) ?: [];

    $overflow = count($agents) > TB_TRACKER_BOT_UA_MAX;
    set_transient('tb_tracker_bot_agents', $overflow ? ['__overflow__'] : $agents, 15 * MINUTE_IN_SECONDS);
    $memo = $overflow ? null : $agents;
    return $memo;
}

/**
 * SQL clause used by every report when the "Humains uniquement" filter is on.
 * Based only on the stored raw UA — no device or geography inference.
 */
function tb_tracker_admin_human_clause(string $alias = 'c'): string
{
    $alias = preg_replace('/[^a-z0-9_]/i', '', $alias);
    $agents = tb_tracker_admin_bot_agents();

    if ($agents === null) {
        return " AND LOWER(COALESCE({$alias}.user_agent, '')) NOT REGEXP '" . TB_TRACKER_BOT_UA_REGEXP . "'";
    }
    if (!$agents) {
        return '';
    }

    return ' AND (' . $alias . '.user_agent IS NULL OR ' . $alias . '.user_agent NOT IN ('
        . tb_tracker_admin_sql_list($agents) . '))';
}

/**
 * Mirror clause: only the traffic the human filter would drop.
 */
function tb_tracker_admin_bot_clause(string $alias = 'c'): string
{
    $alias = preg_replace('/[^a-z0-9_]/i', '', $alias);
    $agents = tb_tracker_admin_bot_agents();

    if ($agents === null) {
        return " AND LOWER(COALESCE({$alias}.user_agent, '')) REGEXP '" . TB_TRACKER_BOT_UA_REGEXP . "'";
    }
    if (!$agents) {
        return ' AND 1 = 0';
    }

    return ' AND ' . $alias . '.user_agent IN (' . tb_tracker_admin_sql_list($agents) . ')';
}

/**
 * Quoted, comma separated string list. The result is spliced into queries that
 * are themselves passed through $wpdb->prepare(), so percent signs are doubled.
 *
 * @param array<int,string> $values
 */
function tb_tracker_admin_sql_list(array $values): string
{
    global $wpdb;
    $quoted = array_map(static function ($value) use ($wpdb): string {
        return "'" . $wpdb->_real_escape((string) $value) . "'";
    }, $values);
    return str_replace('%', '%%', implode(',', $quoted));
}

/** How long an aggregate block stays warm. The Live page is the real-time view. */
const TB_TRACKER_AGG_TTL = 90;

/**
 * Memoize an expensive aggregate. Period reports scan hundreds of thousands of
 * rows; recomputing them on every page load (or every filter toggle) is what
 * makes the admin feel broken.
 *
 * @param array<string,mixed> $signature Everything the result depends on.
 * @return mixed
 */
function tb_tracker_admin_cached(string $bucket, array $signature, callable $compute)
{
    $key = 'tb_agg_' . md5($bucket . '|' . wp_json_encode($signature));
    $hit = get_transient($key);
    if ($hit !== false) {
        return $hit['v'] ?? null;
    }
    $value = $compute();
    set_transient($key, ['v' => $value], TB_TRACKER_AGG_TTL);
    return $value;
}

/**
 * Both tracker tables present?
 */
function tb_tracker_admin_tables_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    global $wpdb;
    $ct = tb_tracker_table('clicks');
    $et = tb_tracker_table('events');
    $ready = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ct)) === $ct
        && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $et)) === $et;
    return $ready;
}

/**
 * Horizontal rank bars.
 *
 * @param array<int,array{label:string,value:int,hint?:string}> $rows
 */
function tb_tracker_admin_rank(array $rows, string $mod = '', string $emptyLabel = 'Aucune donnée.'): void
{
    if (!$rows) {
        echo '<p class="tbx-empty" style="padding:22px 0!important">' . esc_html($emptyLabel) . '</p>';
        return;
    }
    $max = 1;
    foreach ($rows as $row) {
        $max = max($max, (int) $row['value']);
    }
    echo '<div class="tbx-rank' . ($mod !== '' ? ' tbx-rank--' . esc_attr($mod) : '') . '">';
    foreach ($rows as $row) {
        $value = (int) $row['value'];
        $pct = $max > 0 ? round(($value / $max) * 100) : 0;
        echo '<div class="tbx-rank__row">';
        echo '<span class="tbx-rank__label" title="' . esc_attr((string) $row['label']) . '">' . esc_html((string) $row['label']) . '</span>';
        echo '<span class="tbx-rank__bar"><span style="--tbx-w:' . esc_attr((string) $pct) . '%"></span></span>';
        echo '<span class="tbx-rank__val">' . esc_html(number_format_i18n($value));
        $hint = trim((string) ($row['hint'] ?? ''));
        if ($hint !== '') {
            echo '<em>' . esc_html($hint) . '</em>';
        }
        echo '</span>';
        echo '</div>';
    }
    echo '</div>';
}

/**
 * Funnel bars with step-to-step conversion.
 *
 * @param array<int,array{label:string,value:int}> $steps
 */
function tb_tracker_admin_funnel(array $steps): void
{
    $max = 1;
    foreach ($steps as $step) {
        $max = max($max, (int) $step['value']);
    }
    $previous = null;
    echo '<div class="tbx-funnel">';
    foreach ($steps as $step) {
        $value = (int) $step['value'];
        $pct = $max > 0 ? round(($value / $max) * 100) : 0;
        $rate = '';
        if ($previous !== null) {
            $rate = $previous > 0 ? number_format_i18n(($value / $previous) * 100, 1) . ' %' : '—';
        }
        echo '<div class="tbx-funnel__row">';
        echo '<span class="tbx-funnel__label">' . esc_html($step['label']) . '</span>';
        echo '<span class="tbx-funnel__bar"><span style="--tbx-w:' . esc_attr((string) $pct) . '%"></span></span>';
        echo '<span class="tbx-funnel__val">' . esc_html(number_format_i18n($value));
        if ($rate !== '') {
            echo '<em>' . esc_html($rate) . '</em>';
        }
        echo '</span></div>';
        $previous = $value;
    }
    echo '</div>';
}

/**
 * Resolve a human product label (form defs → product_hint → offer_slug).
 */
function tb_tracker_admin_product_label(string $formKey = '', string $hint = '', string $offerSlug = ''): string
{
    if ($hint !== '') {
        return $hint;
    }
    if ($formKey !== '' && function_exists('hd_native_resolve_product_name')) {
        $label = hd_native_resolve_product_name(['key' => $formKey], ['form_key' => $formKey], null, []);
        if ($label !== '') {
            return $label;
        }
    }
    if ($formKey !== '' && defined('HD_LEADS_OPTION')) {
        $all = get_option(HD_LEADS_OPTION, []);
        if (is_array($all) && isset($all[$formKey])) {
            $title = trim((string) ($all[$formKey]['title'] ?? ($all[$formKey]['form_title'] ?? '')));
            if ($title !== '') {
                return $title;
            }
        }
    }
    if ($offerSlug !== '') {
        return str_replace(['-', '_'], ' ', $offerSlug);
    }
    return $formKey !== '' ? $formKey : '—';
}

/**
 * Resolve landing page titles in one query. Ads land on /?page_id=N, so the
 * page id — not the URL path — is the real content dimension here.
 *
 * @param array<int,int|string> $ids
 * @return array<int,string>
 */
function tb_tracker_admin_page_titles(array $ids): array
{
    static $memo = [];
    global $wpdb;

    $wanted = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0 && !isset($memo[$id])) {
            $wanted[$id] = true;
        }
    }
    if ($wanted) {
        $list = array_keys($wanted);
        $placeholders = implode(',', array_fill(0, count($list), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ({$placeholders})",
            ...$list
        ), ARRAY_A) ?: [];
        foreach ($rows as $row) {
            $memo[(int) $row['ID']] = (string) $row['post_title'];
        }
        foreach ($list as $id) {
            if (!isset($memo[$id])) {
                $memo[$id] = '';
            }
        }
    }

    $out = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $out[$id] = $memo[$id] ?? '';
        }
    }
    return $out;
}

/**
 * Human label for a landing: post title, then page id, then "hors page".
 *
 * @param array<int,string> $titles
 */
function tb_tracker_admin_landing_label(int $pageId, array $titles): string
{
    if ($pageId <= 0) {
        return 'Hors page identifiée';
    }
    $title = trim((string) ($titles[$pageId] ?? ''));
    return $title !== '' ? $title : 'page ' . $pageId;
}

function tb_tracker_admin_flag(string $iso): string
{
    $iso = strtoupper(trim($iso));
    if ($iso === '' || $iso === '(N/A)' || !function_exists('hd_native_iso2_flag_emoji')) {
        return '';
    }
    return (string) hd_native_iso2_flag_emoji($iso);
}
