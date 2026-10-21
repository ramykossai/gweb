<?php
/**
 * HD Native Leads — Espace client léger (inscription + connexion + suivi).
 *
 * - Role: hd_customer
 * - Lien leads ↔ user via téléphone TN normalisé (user_meta hd_phone)
 * - Statut CRM: lit meta.espo_status déjà sync (aucune API Espo front)
 * - Shortcode: [hd_customer_orders]
 * - Page: /mon-compte/
 * - Nav boutique / white: Connexion | Mon compte | Déconnexion
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HD_CUSTOMER_ROLE', 'hd_customer');
define('HD_CUSTOMER_COOKIE', 'hd_boutique_lead');
define('HD_CUSTOMER_PAGE_OPTION', 'hd_customer_account_page_id');
define('HD_CUSTOMER_RL_MAX', 8);
define('HD_CUSTOMER_RL_WINDOW', 900); // 15 min

/**
 * Register lightweight customer role (subscriber caps).
 */
function hd_customer_ensure_role(): void
{
    if (get_role(HD_CUSTOMER_ROLE)) {
        return;
    }
    add_role(HD_CUSTOMER_ROLE, 'Client boutique', [
        'read' => true,
    ]);
}
add_action('init', 'hd_customer_ensure_role', 5);

/**
 * Ensure /mon-compte/ page exists with shortcode.
 */
function hd_customer_ensure_account_page(): void
{
    $pageId = (int) get_option(HD_CUSTOMER_PAGE_OPTION, 0);
    if ($pageId > 0 && get_post_status($pageId)) {
        return;
    }
    $existing = get_page_by_path('mon-compte');
    if ($existing instanceof WP_Post) {
        update_option(HD_CUSTOMER_PAGE_OPTION, (int) $existing->ID, false);
        return;
    }
    $id = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => 'Mon compte',
        'post_name' => 'mon-compte',
        'post_content' => "[hd_customer_orders]\n",
    ], true);
    if (!is_wp_error($id) && $id > 0) {
        update_option(HD_CUSTOMER_PAGE_OPTION, (int) $id, false);
    }
}
add_action('init', 'hd_customer_ensure_account_page', 20);

function hd_customer_account_url(string $view = ''): string
{
    $pageId = (int) get_option(HD_CUSTOMER_PAGE_OPTION, 0);
    $url = home_url('/mon-compte/');
    if ($pageId > 0) {
        $permalink = get_permalink($pageId);
        if (is_string($permalink) && $permalink !== '') {
            $url = $permalink;
        }
    }
    if ($view === 'register') {
        return add_query_arg('view', 'register', $url);
    }
    if ($view === 'login') {
        return remove_query_arg('view', $url);
    }
    return $url;
}

function hd_customer_is_account_page(): bool
{
    if (!is_page()) {
        return false;
    }
    $id = (int) get_queried_object_id();
    $opt = (int) get_option(HD_CUSTOMER_PAGE_OPTION, 0);
    if ($opt > 0 && $id === $opt) {
        return true;
    }
    return get_post_field('post_name', $id) === 'mon-compte';
}

/**
 * Boutique-like body class on mon-compte.
 *
 * @param list<string> $classes
 * @return list<string>
 */
function hd_customer_body_class(array $classes): array
{
    if (hd_customer_is_account_page()) {
        $classes[] = 'hd-boutique-body';
        $classes[] = 'hd-customer-page';
    }
    return $classes;
}
add_filter('body_class', 'hd_customer_body_class');

/**
 * Enqueue scoped account CSS (+ i18n on mon-compte).
 */
function hd_customer_enqueue_assets(): void
{
    $cssPath = __DIR__ . '/customer-area.css';
    $ver = is_readable($cssPath) ? (string) filemtime($cssPath) : '1.0';
    $url = content_url('mu-plugins/hd-native-leads/customer-area.css');
    $need = hd_customer_is_account_page();
    if (!$need && is_page()) {
        $tpl = (string) get_page_template_slug(get_queried_object_id());
        $need = ($tpl === 'page-hd-boutique.php');
    }
    if ($need) {
        wp_enqueue_style('hd-customer-area', $url, [], $ver);
    }
    if (hd_customer_is_account_page() && function_exists('hd_hv_i18n_enqueue')) {
        // Ensure FR/TN switch works on account page.
        if (function_exists('hd_hv_i18n_should_load') || true) {
            $base = get_template_directory_uri() . '/assets';
            $iver = '1.2.3';
            wp_enqueue_style('hv-i18n', $base . '/hv-i18n.css', ['hd-lite'], $iver);
            wp_enqueue_script('hv-i18n', $base . '/hv-i18n.js', [], $iver, true);
            if (function_exists('hd_hv_i18n_load_dict')) {
                wp_add_inline_script(
                    'hv-i18n',
                    'window.HV_I18N=window.HV_I18N||{};window.HV_I18N.dict=' . wp_json_encode(hd_hv_i18n_load_dict()) . ';',
                    'before'
                );
            }
        }
    }
}
add_action('wp_enqueue_scripts', 'hd_customer_enqueue_assets', 40);

/** Inline CSS link for white pages (early exit). */
function hd_customer_print_assets(): void
{
    $cssPath = __DIR__ . '/customer-area.css';
    $ver = is_readable($cssPath) ? (string) filemtime($cssPath) : '1.0';
    $url = esc_url(content_url('mu-plugins/hd-native-leads/customer-area.css') . '?v=' . rawurlencode($ver));
    echo '<link rel="stylesheet" href="' . $url . '" id="hd-customer-css"/>' . "\n";
}

/**
 * Normalize phone for matching (same TN rules when available).
 */
function hd_customer_normalize_phone(string $raw): string
{
    if (function_exists('hd_native_normalize_tn_phone')) {
        $n = hd_native_normalize_tn_phone($raw);
        if (!empty($n['ok'])) {
            return (string) $n['phone'];
        }
    }
    $digits = preg_replace('/\D+/', '', $raw);
    return is_string($digits) ? substr($digits, -8) : '';
}

/**
 * Soft rate-limit by IP + action (transient).
 */
function hd_customer_rate_allow(string $action): bool
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0');
    $key = 'hd_cust_rl_' . md5($action . '|' . $ip);
    $n = (int) get_transient($key);
    if ($n >= HD_CUSTOMER_RL_MAX) {
        return false;
    }
    set_transient($key, $n + 1, HD_CUSTOMER_RL_WINDOW);
    return true;
}

/**
 * Signed boutique lead cookie (prevents forging sequential lead IDs).
 */
function hd_customer_lead_cookie_value(int $leadId): string
{
    $leadId = (int) $leadId;
    if ($leadId <= 0) {
        return '';
    }
    $sig = substr(hash_hmac('sha256', (string) $leadId, wp_salt('auth')), 0, 32);
    return $leadId . '.' . $sig;
}

/**
 * Parse & verify signed lead cookie. Unsigned / forged → 0.
 */
function hd_customer_parse_lead_cookie(?string $raw): int
{
    $raw = trim((string) $raw);
    if ($raw === '' || !str_contains($raw, '.')) {
        return 0;
    }
    [$idPart, $sig] = explode('.', $raw, 2);
    if ($idPart === '' || !ctype_digit($idPart) || $sig === '') {
        return 0;
    }
    $leadId = (int) $idPart;
    if ($leadId <= 0) {
        return 0;
    }
    $expected = substr(hash_hmac('sha256', (string) $leadId, wp_salt('auth')), 0, 32);
    if (!hash_equals($expected, $sig)) {
        return 0;
    }
    return $leadId;
}

/**
 * Claimable lead id: signed cookie only (ignore forged POST lead_id).
 */
function hd_customer_claimable_lead_id(int $postedLeadId = 0): int
{
    $cookieLead = hd_customer_parse_lead_cookie($_COOKIE[HD_CUSTOMER_COOKIE] ?? '');
    if ($cookieLead <= 0) {
        return 0;
    }
    // Posted value may only confirm the cookie — never introduce a new id.
    if ($postedLeadId > 0 && $postedLeadId !== $cookieLead) {
        return 0;
    }
    return $cookieLead;
}

/**
 * True if another WP user already owns this hd_phone.
 */
function hd_customer_phone_taken(string $phone, int $excludeUserId = 0): bool
{
    $phone = hd_customer_normalize_phone($phone);
    if ($phone === '' || strlen($phone) !== 8) {
        return false;
    }
    $users = get_users([
        'meta_key' => 'hd_phone',
        'meta_value' => $phone,
        'number' => 5,
        'count_total' => false,
        'fields' => 'ID',
    ]);
    foreach ($users as $uid) {
        if ((int) $uid !== $excludeUserId) {
            return true;
        }
    }
    return false;
}

/**
 * Block wp-admin for boutique customers (read-only role).
 */
function hd_customer_block_admin(): void
{
    if (!is_user_logged_in() || wp_doing_ajax() || wp_doing_cron()) {
        return;
    }
    if (!is_admin()) {
        return;
    }
    $user = wp_get_current_user();
    if (!$user instanceof WP_User || $user->ID <= 0) {
        return;
    }
    if (!in_array(HD_CUSTOMER_ROLE, (array) $user->roles, true)) {
        return;
    }
    if (user_can($user, 'edit_posts') || user_can($user, 'manage_options')) {
        return;
    }
    wp_safe_redirect(hd_customer_account_url());
    exit;
}
add_action('admin_init', 'hd_customer_block_admin');

/**
 * @param bool $show
 */
function hd_customer_hide_admin_bar(bool $show): bool
{
    if (!$show || !is_user_logged_in()) {
        return $show;
    }
    $user = wp_get_current_user();
    if (!$user instanceof WP_User) {
        return $show;
    }
    if (in_array(HD_CUSTOMER_ROLE, (array) $user->roles, true)
        && !user_can($user, 'edit_posts')
        && !user_can($user, 'manage_options')) {
        return false;
    }
    return $show;
}
add_filter('show_admin_bar', 'hd_customer_hide_admin_bar');

/**
 * Resolve WP user by email or TN phone (hd_phone meta).
 *
 * @return WP_User|null
 */
function hd_customer_find_user_by_login(string $login)
{
    $login = trim($login);
    if ($login === '') {
        return null;
    }
    if (is_email($login)) {
        $byEmail = get_user_by('email', $login);
        if ($byEmail instanceof WP_User) {
            return $byEmail;
        }
        $byLogin = get_user_by('login', $login);
        return $byLogin instanceof WP_User ? $byLogin : null;
    }
    $phone = hd_customer_normalize_phone($login);
    if ($phone === '' || strlen($phone) !== 8) {
        return null;
    }
    $users = get_users([
        'meta_key' => 'hd_phone',
        'meta_value' => $phone,
        'number' => 1,
        'count_total' => false,
    ]);
    if (!empty($users[0]) && $users[0] instanceof WP_User) {
        return $users[0];
    }
    return null;
}

/**
 * Inline SVG icon for account nav pills.
 */
function hd_customer_nav_icon(string $name): string
{
    $icons = [
        'login' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>',
        'register' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>',
        'account' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'logout' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
    ];
    return $icons[$name] ?? '';
}

/**
 * Nav links HTML for boutique / white / account chrome.
 *
 * @param string $variant boutique|white|account
 */
function hd_customer_nav_links_html(string $variant = 'boutique'): string
{
    $accountUrl = hd_customer_account_url();
    $loginUrl = hd_customer_account_url('login');
    $registerUrl = hd_customer_account_url('register');
    $logoutUrl = wp_logout_url(home_url('/'));
    $loggedIn = is_user_logged_in();

    if ($variant === 'white') {
        if ($loggedIn) {
            return '<a href="' . esc_url($accountUrl) . '" class="nav-account nav-account--cta" data-i18n="nav.compte">'
                . hd_customer_nav_icon('account') . ' <span>Mon compte</span></a>'
                . '<a href="' . esc_url($logoutUrl) . '" class="nav-account nav-account--muted" data-i18n="nav.logout">'
                . hd_customer_nav_icon('logout') . ' <span>Déconnexion</span></a>';
        }
        return '<a href="' . esc_url($loginUrl) . '" class="nav-account nav-account--cta" data-i18n="nav.login">'
            . hd_customer_nav_icon('login') . ' <span>Connexion</span></a>'
            . '<a href="' . esc_url($registerUrl) . '" class="nav-account nav-account--cta" data-i18n="nav.register">'
            . hd_customer_nav_icon('register') . ' <span>Inscription</span></a>';
    }

    if ($variant === 'white-mobile') {
        if ($loggedIn) {
            return '<a href="' . esc_url($accountUrl) . '" data-i18n="nav.compte">Mon compte</a>'
                . '<a href="' . esc_url($logoutUrl) . '" data-i18n="nav.logout">Déconnexion</a>';
        }
        return '<a href="' . esc_url($loginUrl) . '" data-i18n="nav.login">Connexion</a>'
            . '<a href="' . esc_url($registerUrl) . '" data-i18n="nav.register">Inscription</a>';
    }

    // boutique + account — pills avec icônes (Shopify-like)
    ob_start();
    echo '<div class="hv-nav-account" aria-label="Compte client">';
    if ($loggedIn) {
        echo '<a class="hv-nav-account__btn hv-nav-account__btn--solid" href="' . esc_url($accountUrl) . '" data-i18n="nav.compte">'
            . hd_customer_nav_icon('account') . '<span>Mon compte</span></a>';
        echo '<a class="hv-nav-account__btn hv-nav-account__btn--ghost" href="' . esc_url($logoutUrl) . '" data-i18n="nav.logout">'
            . hd_customer_nav_icon('logout') . '<span>Déconnexion</span></a>';
    } else {
        echo '<a class="hv-nav-account__btn hv-nav-account__btn--ghost" href="' . esc_url($loginUrl) . '" data-i18n="nav.login">'
            . hd_customer_nav_icon('login') . '<span>Connexion</span></a>';
        echo '<a class="hv-nav-account__btn hv-nav-account__btn--solid" href="' . esc_url($registerUrl) . '" data-i18n="nav.register">'
            . hd_customer_nav_icon('register') . '<span>Inscription</span></a>';
    }
    echo '</div>';
    return (string) ob_get_clean();
}

/**
 * After boutique lead: cookie + merci query for account CTA.
 *
 * @param array<string,mixed> $form
 * @param array<string,mixed> $meta
 * @param array<string,mixed> $ctx
 */
function hd_customer_on_lead_saved(int $leadId, array $form, array $ctx, array $meta): void
{
    if ($leadId <= 0) {
        return;
    }
    $isBoutique = function_exists('hd_native_is_boutique_lead')
        ? hd_native_is_boutique_lead($meta, $ctx)
        : (!empty($meta['is_boutique']) || (($meta['hd_channel'] ?? '') === 'boutique'));
    if (!$isBoutique) {
        return;
    }
    $expire = time() + WEEK_IN_SECONDS;
    $cookieVal = hd_customer_lead_cookie_value($leadId);
    if ($cookieVal !== '' && !headers_sent()) {
        setcookie(HD_CUSTOMER_COOKIE, $cookieVal, [
            'expires' => $expire,
            'path' => '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    if ($cookieVal !== '') {
        $_COOKIE[HD_CUSTOMER_COOKIE] = $cookieVal;
    }

    if (is_user_logged_in()) {
        // Trusted: user just submitted this lead in the same request.
        hd_customer_link_lead_to_user($leadId, get_current_user_id(), ['require_phone_match' => false]);
    }
}
add_action('hd_native_lead_saved', 'hd_customer_on_lead_saved', 20, 4);

/**
 * @param array<string,mixed> $form
 * @param array<string,mixed> $meta
 */
function hd_customer_filter_merci_url(string $merci, array $form, array $meta, int $leadId): string
{
    $isBoutique = function_exists('hd_native_is_boutique_lead')
        ? hd_native_is_boutique_lead($meta, [])
        : (!empty($meta['is_boutique']) || (($meta['hd_channel'] ?? '') === 'boutique'));
    if (!$isBoutique || is_user_logged_in()) {
        return $merci;
    }
    return add_query_arg('hd_account', '1', $merci);
}
add_filter('hd_native_merci_url', 'hd_customer_filter_merci_url', 20, 4);

/**
 * @return array<string,mixed>|null
 */
function hd_customer_get_lead(int $leadId): ?array
{
    if ($leadId <= 0) {
        return null;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'hd_leads';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, form_key, name, phone, meta, created_at FROM {$table} WHERE id = %d LIMIT 1",
        $leadId
    ), ARRAY_A);
    return is_array($row) ? $row : null;
}

/**
 * @param array<string,mixed>|string|null $metaRaw
 * @return array<string,mixed>
 */
function hd_customer_decode_meta($metaRaw): array
{
    if (is_array($metaRaw)) {
        return $metaRaw;
    }
    if (!is_string($metaRaw) || $metaRaw === '') {
        return [];
    }
    $d = json_decode($metaRaw, true);
    return is_array($d) ? $d : [];
}

/**
 * @param array{require_phone_match?:bool} $opts
 */
function hd_customer_link_lead_to_user(int $leadId, int $userId, array $opts = []): void
{
    if ($leadId <= 0 || $userId <= 0) {
        return;
    }
    $requirePhoneMatch = !array_key_exists('require_phone_match', $opts) || !empty($opts['require_phone_match']);
    $lead = hd_customer_get_lead($leadId);
    if (!$lead) {
        return;
    }
    $meta = hd_customer_decode_meta($lead['meta'] ?? '');
    // Never steal another user's lead.
    if (!empty($meta['wp_user_id']) && (int) $meta['wp_user_id'] !== $userId) {
        return;
    }

    $leadPhone = hd_customer_normalize_phone((string) ($lead['phone'] ?? ''));
    $userPhone = hd_customer_normalize_phone((string) get_user_meta($userId, 'hd_phone', true));
    if ($requirePhoneMatch) {
        if ($leadPhone === '' || $userPhone === '' || $leadPhone !== $userPhone) {
            return;
        }
    }

    $meta['wp_user_id'] = $userId;
    $meta['is_boutique'] = !empty($meta['is_boutique']) ? $meta['is_boutique'] : 1;

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'hd_leads',
        ['meta' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE)],
        ['id' => $leadId],
        ['%s'],
        ['%d']
    );

    // Only set phone from lead when user has none yet (never overwrite a different number).
    if ($leadPhone !== '' && $userPhone === '') {
        if (!hd_customer_phone_taken($leadPhone, $userId)) {
            update_user_meta($userId, 'hd_phone', $leadPhone);
        }
    }
    $linked = get_user_meta($userId, 'hd_linked_lead_ids', true);
    if (!is_array($linked)) {
        $linked = [];
    }
    $linked[] = $leadId;
    $linked = array_values(array_unique(array_map('intval', $linked)));
    update_user_meta($userId, 'hd_linked_lead_ids', $linked);
}

function hd_customer_attach_leads_by_identity(int $userId, string $phone, string $email = ''): int
{
    if ($userId <= 0) {
        return 0;
    }
    $phone = hd_customer_normalize_phone($phone);
    if ($phone === '') {
        return 0;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'hd_leads';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, phone, meta FROM {$table}
         WHERE phone = %s
         ORDER BY id DESC
         LIMIT 50",
        $phone
    ), ARRAY_A);
    if (!is_array($rows)) {
        return 0;
    }
    $n = 0;
    foreach ($rows as $row) {
        $meta = hd_customer_decode_meta($row['meta'] ?? '');
        $isBoutique = !empty($meta['is_boutique'])
            || (($meta['hd_channel'] ?? '') === 'boutique')
            || !empty($meta['boutique_quantite'])
            || !empty($meta['boutique_gouvernorat']);
        if (!$isBoutique) {
            continue;
        }
        if (!empty($meta['wp_user_id']) && (int) $meta['wp_user_id'] !== $userId) {
            continue;
        }
        hd_customer_link_lead_to_user((int) $row['id'], $userId);
        $n++;
    }
    if ($email !== '') {
        update_user_meta($userId, 'hd_account_email', strtolower(trim($email)));
    }
    return $n;
}

/**
 * Enrich order row with catalog image / pricing display fields.
 *
 * @param array<string,mixed> $meta
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function hd_customer_build_order_card(array $meta, array $row): array
{
    $productName = trim((string) ($meta['product'] ?? ''));
    $formKey = (string) ($row['form_key'] ?? '');
    if ($productName === '' && $formKey !== '' && function_exists('hd_native_get_form')) {
        $form = hd_native_get_form($formKey);
        if (is_array($form)) {
            $productName = (string) ($form['title'] ?? ($form['form_title'] ?? $formKey));
        }
    }

    $sku = trim((string) ($meta['product_sku'] ?? ''));
    $imgId = (int) ($meta['image_attachment_id'] ?? 0);
    $catalogId = (int) ($meta['catalog_product_id'] ?? 0);

    $product = null;
    if (function_exists('hd_catalog_products_get') && $catalogId > 0) {
        $product = hd_catalog_products_get($catalogId);
    }
    if (!$product && $formKey !== '' && function_exists('hd_catalog_products_get_by_form_key')) {
        $projectId = sanitize_text_field((string) ($meta['project_id'] ?? ''));
        $product = hd_catalog_products_get_by_form_key($formKey, $projectId);
    }
    if ($product) {
        if ($productName === '') {
            $productName = (string) ($product->name ?? '');
        }
        if ($sku === '') {
            $sku = (string) ($product->sku ?? '');
        }
        if ($imgId <= 0) {
            $imgId = (int) ($product->image_attachment_id ?? 0);
        }
    }

    $imgUrl = $imgId > 0 ? (string) wp_get_attachment_image_url($imgId, 'medium') : '';
    if ($imgUrl === '' && $imgId > 0) {
        $imgUrl = (string) wp_get_attachment_image_url($imgId, 'full');
    }

    $units = (int) ($meta['boutique_qty_units'] ?? 0);
    $qtyLabel = trim((string) ($meta['boutique_quantite'] ?? ''));
    if ($units <= 0 && preg_match('/(\d+)/', $qtyLabel, $m)) {
        $units = (int) $m[1];
    }
    if ($qtyLabel === '' && $units > 0) {
        $qtyLabel = $units . ' unité' . ($units > 1 ? 's' : '');
    }

    $prix = $meta['boutique_prix_total'] ?? null;
    if ($prix === null || $prix === '') {
        $prix = null;
    } else {
        $prix = round((float) $prix, 2);
    }
    $discount = isset($meta['boutique_discount']) ? (float) $meta['boutique_discount'] : null;

    $gov = trim((string) ($meta['boutique_gouvernorat'] ?? ''));
    $addr = trim((string) ($meta['boutique_adresse'] ?? ''));
    $regionLine = trim((string) ($meta['boutique_adress_region'] ?? ''));
    if ($regionLine === '') {
        $regionLine = trim($gov . ($addr !== '' ? ' — ' . $addr : ''));
    }

    $status = trim((string) ($meta['espo_status'] ?? ''));
    if ($status === '') {
        $status = 'New';
    }

    $tone = hd_customer_status_tone($status);

    return [
        'id' => (int) ($row['id'] ?? 0),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'product' => $productName !== '' ? $productName : ($formKey !== '' ? $formKey : 'Commande'),
        'sku' => $sku,
        'status' => $status,
        'status_tone' => $tone,
        'qty' => $qtyLabel,
        'units' => $units,
        'prix' => $prix,
        'discount' => $discount,
        'region' => $gov,
        'address' => $addr,
        'region_line' => $regionLine,
        'image' => $imgUrl,
        'phone' => (string) ($row['phone'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
        'demo' => !empty($meta['demo']) || (($meta['demo_seed'] ?? '') !== ''),
    ];
}

/**
 * Map CRM status → CSS tone.
 */
function hd_customer_status_tone(string $status): string
{
    $s = strtolower(preg_replace('/[^a-z0-9]+/i', '', $status) ?: '');
    $map = [
        'new' => 'new',
        'processing' => 'progress',
        'inprocess' => 'progress',
        'assigned' => 'progress',
        'sent' => 'ship',
        'beingvalidated' => 'check',
        'recall' => 'warn',
        'preparing' => 'progress',
        'validated' => 'ok',
        'reported' => 'ok',
        'confirmed' => 'ok',
        'waitingforreturn' => 'warn',
        'converted' => 'ok',
        'dead' => 'bad',
        'lost' => 'bad',
        'closed' => 'muted',
    ];
    return $map[$s] ?? 'muted';
}

/**
 * @return list<array<string,mixed>>
 */
function hd_customer_orders_for_user(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    global $wpdb;
    $table = $wpdb->prefix . 'hd_leads';
    $ids = get_user_meta($userId, 'hd_linked_lead_ids', true);
    if (!is_array($ids)) {
        $ids = [];
    }
    $ids = array_values(array_filter(array_map('intval', $ids)));
    $phone = hd_customer_normalize_phone((string) get_user_meta($userId, 'hd_phone', true));

    $rows = [];
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $byId = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, form_key, name, phone, meta, created_at FROM {$table}
                 WHERE id IN ($placeholders) ORDER BY id DESC LIMIT 80",
                ...$ids
            ),
            ARRAY_A
        ) ?: [];
        $rows = array_merge($rows, $byId);
    }
    if ($phone !== '') {
        $byPhone = $wpdb->get_results($wpdb->prepare(
            "SELECT id, form_key, name, phone, meta, created_at FROM {$table}
             WHERE phone = %s ORDER BY id DESC LIMIT 80",
            $phone
        ), ARRAY_A) ?: [];
        $rows = array_merge($rows, $byPhone);
    }

    $seen = [];
    $out = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || isset($seen[$id])) {
            continue;
        }
        $meta = hd_customer_decode_meta($row['meta'] ?? '');
        $owner = (int) ($meta['wp_user_id'] ?? 0);
        if ($owner > 0 && $owner !== $userId) {
            continue;
        }
        $isBoutique = !empty($meta['is_boutique'])
            || (($meta['hd_channel'] ?? '') === 'boutique')
            || !empty($meta['boutique_quantite'])
            || !empty($meta['boutique_gouvernorat'])
            || ($owner === $userId);
        if (!$isBoutique) {
            continue;
        }
        if ($owner === 0) {
            $rowPhone = hd_customer_normalize_phone((string) ($row['phone'] ?? ''));
            if ($phone === '' || $rowPhone !== $phone) {
                continue;
            }
        }
        $seen[$id] = true;
        $out[] = hd_customer_build_order_card($meta, $row);
    }
    usort($out, static function ($a, $b) {
        return ((int) $b['id']) <=> ((int) $a['id']);
    });
    return $out;
}

function hd_customer_error_message(string $code): string
{
    $map = [
        'email' => 'Email invalide.',
        'pass' => 'Mot de passe trop court (8 caractères min.).',
        'exists' => 'Un compte existe déjà avec cet email — connectez-vous.',
        'create' => 'Impossible de créer le compte. Réessayez.',
        'login' => 'Identifiants incorrects.',
        'rate' => 'Trop de tentatives. Réessayez dans quelques minutes.',
        'phone' => 'Numéro de téléphone invalide.',
        'phone_taken' => 'Ce numéro est déjà lié à un autre compte.',
        'name' => 'Nom invalide.',
        'profile' => 'Impossible d’enregistrer le profil.',
        'pass_mismatch' => 'Les mots de passe ne correspondent pas.',
        'pass_current' => 'Mot de passe actuel incorrect.',
    ];
    return $map[$code] ?? '';
}

/**
 * Inline SVG icons for account UI.
 */
function hd_customer_icon(string $name): string
{
    $icons = [
        'user' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'login' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>',
        'register' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>',
        'mail' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
        'phone' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.81.36 1.6.68 2.35a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.75.32 1.54.55 2.35.68A2 2 0 0 1 22 16.92z"/></svg>',
        'lock' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
        'orders' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>',
        'track' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'shop' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M3 9l1-5h16l1 5"/><path d="M3 9v11a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1V9"/><path d="M9 22V12h6v10"/></svg>',
        'logout' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
        'save' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>',
        'check' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>',
    ];
    return $icons[$name] ?? '';
}

/**
 * Clean display name for profile form (never prefill with email/login).
 *
 * @return array{value:string,placeholder:string}
 */
function hd_customer_profile_name_field(WP_User $user): array
{
    $email = strtolower(trim((string) $user->user_email));
    $login = strtolower(trim((string) $user->user_login));
    $isBad = static function (string $v) use ($email, $login): bool {
        $v = strtolower(trim($v));
        if ($v === '' || $v === $email || $v === $login) {
            return true;
        }
        return (bool) is_email($v);
    };

    $dn = trim((string) $user->display_name);
    $first = trim((string) $user->first_name);
    $last = trim((string) $user->last_name);
    $full = trim($first . ($first !== '' && $last !== '' ? ' ' : '') . $last);

    $local = '';
    if (str_contains($email, '@')) {
        $part = (string) strtok($email, '@');
        $part = preg_replace('/[._\-+]+/', ' ', $part) ?: '';
        $local = ucwords(trim($part));
    }
    $placeholder = $local !== '' ? $local : 'Votre nom';

    if (!$isBad($dn)) {
        return ['value' => $dn, 'placeholder' => $placeholder];
    }
    if ($full !== '' && !$isBad($full)) {
        return ['value' => $full, 'placeholder' => $placeholder];
    }

    return ['value' => '', 'placeholder' => $placeholder];
}

/**
 * Update profile (name, phone, optional password).
 */
function hd_customer_handle_profile(): void
{
    if (!is_user_logged_in()) {
        wp_safe_redirect(hd_customer_account_url('login'));
        exit;
    }
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce((string) $_POST['_wpnonce'], 'hd_customer_profile')) {
        wp_die('Sécurité : formulaire invalide.', 400);
    }
    $redirect = add_query_arg('panel', 'profile', hd_customer_account_url());
    if (!hd_customer_rate_allow('profile')) {
        wp_safe_redirect(add_query_arg('hd_err', 'rate', $redirect));
        exit;
    }

    $userId = get_current_user_id();
    $name = sanitize_text_field(wp_unslash((string) ($_POST['display_name'] ?? '')));
    $phoneRaw = sanitize_text_field(wp_unslash((string) ($_POST['phone'] ?? '')));
    $email = sanitize_email(wp_unslash((string) ($_POST['email'] ?? '')));
    $pass = (string) ($_POST['password'] ?? '');
    $pass2 = (string) ($_POST['password2'] ?? '');
    $passCurrent = (string) ($_POST['password_current'] ?? '');

    if ($name === '' || mb_strlen($name) < 2) {
        wp_safe_redirect(add_query_arg('hd_err', 'name', $redirect));
        exit;
    }
    if ($email === '' || !is_email($email)) {
        wp_safe_redirect(add_query_arg('hd_err', 'email', $redirect));
        exit;
    }
    $phone = hd_customer_normalize_phone($phoneRaw);
    if ($phoneRaw !== '' && $phone === '') {
        wp_safe_redirect(add_query_arg('hd_err', 'phone', $redirect));
        exit;
    }
    if ($phone !== '' && hd_customer_phone_taken($phone, $userId)) {
        wp_safe_redirect(add_query_arg('hd_err', 'phone_taken', $redirect));
        exit;
    }

    $emailOwner = email_exists($email);
    if ($emailOwner && (int) $emailOwner !== $userId) {
        wp_safe_redirect(add_query_arg('hd_err', 'exists', $redirect));
        exit;
    }

    $userdata = [
        'ID' => $userId,
        'display_name' => $name,
        'user_email' => $email,
        'nickname' => $name,
    ];

    if ($pass !== '' || $pass2 !== '') {
        if (strlen($pass) < 8) {
            wp_safe_redirect(add_query_arg('hd_err', 'pass', $redirect));
            exit;
        }
        if ($pass !== $pass2) {
            wp_safe_redirect(add_query_arg('hd_err', 'pass_mismatch', $redirect));
            exit;
        }
        $user = get_userdata($userId);
        if (!$user || !wp_check_password($passCurrent, $user->user_pass, $userId)) {
            wp_safe_redirect(add_query_arg('hd_err', 'pass_current', $redirect));
            exit;
        }
        $userdata['user_pass'] = $pass;
    }

    $result = wp_update_user($userdata);
    if (is_wp_error($result)) {
        wp_safe_redirect(add_query_arg('hd_err', 'profile', $redirect));
        exit;
    }

    if ($phone !== '') {
        update_user_meta($userId, 'hd_phone', $phone);
        hd_customer_attach_leads_by_identity($userId, $phone, $email);
    }

    wp_safe_redirect(add_query_arg(['panel' => 'profile', 'hd_ok' => 'profile'], hd_customer_account_url()));
    exit;
}
add_action('admin_post_hd_customer_profile', 'hd_customer_handle_profile');


function hd_customer_handle_register(): void
{
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce((string) $_POST['_wpnonce'], 'hd_customer_register')) {
        wp_die('Sécurité : formulaire invalide.', 400);
    }
    $redirectBase = wp_get_referer() ?: hd_customer_account_url('register');
    if (!hd_customer_rate_allow('register')) {
        wp_safe_redirect(add_query_arg(['hd_err' => 'rate', 'view' => 'register'], $redirectBase));
        exit;
    }
    if (is_user_logged_in()) {
        wp_safe_redirect(hd_customer_account_url());
        exit;
    }

    $email = sanitize_email(wp_unslash((string) ($_POST['email'] ?? '')));
    $pass = (string) wp_unslash($_POST['password'] ?? '');
    $postedLead = (int) ($_POST['lead_id'] ?? 0);
    $leadId = hd_customer_claimable_lead_id($postedLead);

    if (!is_email($email)) {
        wp_safe_redirect(add_query_arg(['hd_err' => 'email', 'view' => 'register'], $redirectBase));
        exit;
    }
    if (strlen($pass) < 8) {
        wp_safe_redirect(add_query_arg(['hd_err' => 'pass', 'view' => 'register'], $redirectBase));
        exit;
    }
    if (email_exists($email)) {
        wp_safe_redirect(add_query_arg(['hd_err' => 'exists', 'view' => 'login'], hd_customer_account_url()));
        exit;
    }

    $phone = '';
    if (!empty($_POST['phone'])) {
        $phone = hd_customer_normalize_phone(sanitize_text_field(wp_unslash((string) $_POST['phone'])));
    }

    $name = '';
    if ($leadId > 0) {
        $lead = hd_customer_get_lead($leadId);
        if ($lead) {
            $leadPhone = hd_customer_normalize_phone((string) ($lead['phone'] ?? ''));
            $name = sanitize_text_field((string) ($lead['name'] ?? ''));
            // Prefill phone from signed-cookie lead only when form omitted it.
            if ($phone === '' && $leadPhone !== '') {
                $phone = $leadPhone;
            } elseif ($phone !== '' && $leadPhone !== '' && $phone !== $leadPhone) {
                // Mismatch → do not claim this lead.
                $leadId = 0;
            }
        } else {
            $leadId = 0;
        }
    }

    if ($phone !== '' && strlen($phone) !== 8) {
        wp_safe_redirect(add_query_arg(['hd_err' => 'phone', 'view' => 'register'], $redirectBase));
        exit;
    }
    if ($phone !== '' && hd_customer_phone_taken($phone)) {
        wp_safe_redirect(add_query_arg(['hd_err' => 'phone_taken', 'view' => 'register'], $redirectBase));
        exit;
    }

    if ($name === '') {
        $local = (string) strtok(strtolower($email), '@');
        $local = preg_replace('/[._\-+]+/', ' ', $local) ?: '';
        $name = ucwords(trim($local));
    }
    $userId = wp_insert_user([
        'user_login' => $email,
        'user_email' => $email,
        'user_pass' => $pass,
        'display_name' => $name !== '' ? $name : $email,
        'role' => HD_CUSTOMER_ROLE,
        'first_name' => $name,
    ]);
    if (is_wp_error($userId)) {
        wp_safe_redirect(add_query_arg(['hd_err' => 'create', 'view' => 'register'], $redirectBase));
        exit;
    }

    if ($phone !== '') {
        update_user_meta((int) $userId, 'hd_phone', $phone);
        hd_customer_attach_leads_by_identity((int) $userId, $phone, $email);
    }
    if ($leadId > 0) {
        hd_customer_link_lead_to_user($leadId, (int) $userId);
    }

    wp_set_current_user((int) $userId);
    wp_set_auth_cookie((int) $userId, true);

    wp_safe_redirect(add_query_arg('hd_ok', '1', hd_customer_account_url()));
    exit;
}
add_action('admin_post_nopriv_hd_customer_register', 'hd_customer_handle_register');
add_action('admin_post_hd_customer_register', 'hd_customer_handle_register');

/**
 * Login: email OU téléphone TN + mot de passe.
 */
function hd_customer_handle_login(): void
{
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce((string) $_POST['_wpnonce'], 'hd_customer_login')) {
        wp_die('Sécurité : formulaire invalide.', 400);
    }
    $redirectBase = wp_get_referer() ?: hd_customer_account_url();
    if (!hd_customer_rate_allow('login')) {
        wp_safe_redirect(add_query_arg('hd_err', 'rate', $redirectBase));
        exit;
    }

    $login = sanitize_text_field(wp_unslash((string) ($_POST['login'] ?? ($_POST['email'] ?? ''))));
    $pass = (string) wp_unslash($_POST['password'] ?? '');
    $user = hd_customer_find_user_by_login($login);
    if (!$user || !wp_check_password($pass, $user->user_pass, $user->ID)) {
        wp_safe_redirect(add_query_arg('hd_err', 'login', $redirectBase));
        exit;
    }

    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true);

    $phone = (string) get_user_meta($user->ID, 'hd_phone', true);
    if ($phone !== '') {
        hd_customer_attach_leads_by_identity((int) $user->ID, $phone, $user->user_email);
    }
    $cookieLead = hd_customer_parse_lead_cookie($_COOKIE[HD_CUSTOMER_COOKIE] ?? '');
    if ($cookieLead > 0) {
        hd_customer_link_lead_to_user($cookieLead, (int) $user->ID);
    }

    wp_safe_redirect(hd_customer_account_url());
    exit;
}
add_action('admin_post_nopriv_hd_customer_login', 'hd_customer_handle_login');
add_action('admin_post_hd_customer_login', 'hd_customer_handle_login');

/**
 * Shared auth card (login or register).
 */
function hd_customer_auth_card_html(string $mode = 'login', array $opts = []): string
{
    $mode = $mode === 'register' ? 'register' : 'login';
    $action = esc_url(admin_url('admin-post.php'));
    $err = sanitize_key((string) ($_GET['hd_err'] ?? ($opts['err'] ?? '')));
    $errMsg = $err !== '' ? hd_customer_error_message($err) : '';
    $leadId = (int) ($opts['lead_id'] ?? 0);
    if ($leadId <= 0) {
        $leadId = hd_customer_parse_lead_cookie($_COOKIE[HD_CUSTOMER_COOKIE] ?? '');
    }
    $phone = (string) ($opts['phone'] ?? '');
    $title = $mode === 'register'
        ? ((string) ($opts['title'] ?? 'Créer mon compte'))
        : 'Connexion';
    $sub = $mode === 'register'
        ? ((string) ($opts['sub'] ?? 'Suivez le statut de vos commandes boutique.'))
        : 'Email ou téléphone + mot de passe.';

    ob_start();
    ?>
    <div class="hd-cust-card">
      <div class="hd-cust-tabs" role="tablist">
        <a class="hd-cust-tab<?php echo $mode === 'login' ? ' is-active' : ''; ?>" href="<?php echo esc_url(hd_customer_account_url('login')); ?>" data-i18n="account.tab_login"><?php echo hd_customer_icon('login'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span>Connexion</span></a>
        <a class="hd-cust-tab<?php echo $mode === 'register' ? ' is-active' : ''; ?>" href="<?php echo esc_url(hd_customer_account_url('register')); ?>" data-i18n="account.tab_register"><?php echo hd_customer_icon('register'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span>Inscription</span></a>
      </div>
      <h2 class="hd-cust-title" data-i18n="<?php echo $mode === 'register' ? 'account.register_title' : 'account.login_title'; ?>"><?php echo esc_html($title); ?></h2>
      <p class="hd-cust-sub" data-i18n="<?php echo $mode === 'register' ? 'account.register_sub' : 'account.login_sub'; ?>"><?php echo esc_html($sub); ?></p>
      <?php if ($errMsg !== '') : ?>
        <p class="hd-cust-alert" role="alert"><?php echo esc_html($errMsg); ?></p>
      <?php endif; ?>

      <?php if ($mode === 'login') : ?>
        <form class="hd-cust-form" method="post" action="<?php echo $action; ?>">
          <input type="hidden" name="action" value="hd_customer_login" />
          <?php wp_nonce_field('hd_customer_login'); ?>
          <label class="hd-cust-label">
            <span data-i18n="account.login_label">Email ou téléphone</span>
            <span class="hd-cust-field">
              <span class="hd-cust-field__ico" aria-hidden="true"><?php echo hd_customer_icon('mail'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
              <input class="hd-cust-input" type="text" name="login" required autocomplete="username" inputmode="email" dir="ltr" placeholder="email@exemple.com ou 20 XXX XXX" data-i18n-placeholder="account.login_ph" />
            </span>
          </label>
          <label class="hd-cust-label">
            <span data-i18n="account.password">Mot de passe</span>
            <span class="hd-cust-field">
              <span class="hd-cust-field__ico" aria-hidden="true"><?php echo hd_customer_icon('lock'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
              <input class="hd-cust-input" type="password" name="password" required autocomplete="current-password" minlength="8" />
            </span>
          </label>
          <button type="submit" class="hd-cust-btn hd-cust-btn--icon hd-cust-btn--block" data-i18n="account.login_btn"><?php echo hd_customer_icon('login'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span>Se connecter</span></button>
        </form>
      <?php else : ?>
        <form class="hd-cust-form" method="post" action="<?php echo $action; ?>">
          <input type="hidden" name="action" value="hd_customer_register" />
          <?php wp_nonce_field('hd_customer_register'); ?>
          <input type="hidden" name="lead_id" value="<?php echo esc_attr((string) $leadId); ?>" />
          <?php if ($phone !== '') : ?>
            <input type="hidden" name="phone" value="<?php echo esc_attr($phone); ?>" />
          <?php else : ?>
            <label class="hd-cust-label">
              <span data-i18n="account.phone">Téléphone (Tunisie)</span>
              <span class="hd-cust-field">
                <span class="hd-cust-field__ico" aria-hidden="true"><?php echo hd_customer_icon('phone'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <input class="hd-cust-input" type="tel" name="phone" autocomplete="tel" inputmode="tel" dir="ltr" placeholder="20 XXX XXX" data-i18n-placeholder="account.phone_ph" />
              </span>
            </label>
          <?php endif; ?>
          <label class="hd-cust-label">
            <span data-i18n="account.email">Email</span>
            <span class="hd-cust-field">
              <span class="hd-cust-field__ico" aria-hidden="true"><?php echo hd_customer_icon('mail'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
              <input class="hd-cust-input" type="email" name="email" required autocomplete="email" dir="ltr" />
            </span>
          </label>
          <label class="hd-cust-label">
            <span data-i18n="account.password_new">Mot de passe (8+ caractères)</span>
            <span class="hd-cust-field">
              <span class="hd-cust-field__ico" aria-hidden="true"><?php echo hd_customer_icon('lock'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
              <input class="hd-cust-input" type="password" name="password" required autocomplete="new-password" minlength="8" />
            </span>
          </label>
          <button type="submit" class="hd-cust-btn hd-cust-btn--icon hd-cust-btn--block" data-i18n="account.register_btn"><?php echo hd_customer_icon('register'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span>Créer mon compte</span></button>
        </form>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

function hd_customer_merci_cta_html(): string
{
    // Ensure styles on merci (often no boutique enqueue).
    $cssPath = __DIR__ . '/customer-area.css';
    $ver = is_readable($cssPath) ? (string) filemtime($cssPath) : '1.0';
    $cssUrl = esc_url(content_url('mu-plugins/hd-native-leads/customer-area.css') . '?v=' . rawurlencode($ver));

    if (is_user_logged_in()) {
        return '<link rel="stylesheet" href="' . $cssUrl . '" />'
            . '<div class="hd-cust-shell hd-cust-shell--compact">'
            . '<div class="hd-cust-card hd-cust-card--center">'
            . '<p class="hd-cust-sub" data-i18n="account.merci_logged">Suivez vos commandes dans votre espace client.</p>'
            . '<a class="hd-cust-btn" href="' . esc_url(hd_customer_account_url()) . '" data-i18n="account.see_orders">Voir mes commandes</a>'
            . '</div></div>';
    }

    $leadId = hd_customer_parse_lead_cookie($_COOKIE[HD_CUSTOMER_COOKIE] ?? '');
    $phone = '';
    if ($leadId > 0) {
        $lead = hd_customer_get_lead($leadId);
        if ($lead) {
            $phone = hd_customer_normalize_phone((string) ($lead['phone'] ?? ''));
        }
    }

    return '<link rel="stylesheet" href="' . $cssUrl . '" />'
        . '<div class="hd-cust-shell hd-cust-shell--compact">'
        . hd_customer_auth_card_html('register', [
            'title' => 'Créer mon compte pour suivre ma commande',
            'sub' => 'Statut livraison (confirmé, préparation, envoyé…) dans votre espace.',
            'lead_id' => $leadId,
            'phone' => $phone,
        ])
        . '</div>';
}

function hd_customer_inject_merci_cta(): void
{
    if (is_admin()) {
        return;
    }
    $want = isset($_GET['hd_account']) || !empty($_COOKIE[HD_CUSTOMER_COOKIE]);
    if (!$want) {
        return;
    }
    $isMerci = false;
    if (is_page()) {
        $slug = (string) get_post_field('post_name', get_queried_object_id());
        $tpl = (string) get_page_template_slug(get_queried_object_id());
        if ($tpl === 'page-hd-merci.php' || stripos($slug, 'merci') !== false) {
            $isMerci = true;
        }
    }
    if (!$isMerci) {
        return;
    }
    echo hd_customer_merci_cta_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action('wp_footer', 'hd_customer_inject_merci_cta', 30);

/**
 * Client area panel slug from query.
 */
function hd_customer_current_panel(): string
{
    $panel = sanitize_key((string) ($_GET['panel'] ?? 'orders'));
    $allowed = ['orders', 'profile', 'track'];
    return in_array($panel, $allowed, true) ? $panel : 'orders';
}

/**
 * Progress index 0–3 for tracking bar.
 */
function hd_customer_order_step_index(string $status): int
{
    $cur = strtolower(preg_replace('/[^a-z0-9]+/i', '', $status) ?: '');
    $idx = 0;
    if (str_contains($cur, 'prepar') || str_contains($cur, 'process') || str_contains($cur, 'assign') || str_contains($cur, 'valid')) {
        $idx = 1;
    }
    if (str_contains($cur, 'sent') || str_contains($cur, 'ship') || str_contains($cur, 'report')) {
        $idx = 2;
    }
    if (str_contains($cur, 'confirm') || str_contains($cur, 'convert')) {
        $idx = 3;
    }
    if (str_contains($cur, 'recall') || str_contains($cur, 'waiting')) {
        $idx = 1;
    }
    return $idx;
}

/**
 * Render one order card.
 *
 * @param array<string,mixed> $o
 */
function hd_customer_render_order_card(array $o): void
{
    $tone = esc_attr((string) ($o['status_tone'] ?? 'muted'));
    $when = '';
    if (!empty($o['created_at'])) {
        $ts = strtotime((string) $o['created_at'] . ' UTC');
        $when = $ts ? wp_date('d M Y · H:i', $ts) : (string) $o['created_at'];
    }
    $idx = hd_customer_order_step_index((string) ($o['status'] ?? ''));
    ?>
  <article class="hd-order-card" data-tone="<?php echo $tone; ?>">
    <div class="hd-order-card__media">
      <?php if (!empty($o['image'])) : ?>
        <img src="<?php echo esc_url((string) $o['image']); ?>" alt="<?php echo esc_attr((string) $o['product']); ?>" loading="lazy" width="160" height="160" />
      <?php else : ?>
        <div class="hd-order-card__ph" aria-hidden="true"><?php echo esc_html(mb_substr((string) $o['product'], 0, 1)); ?></div>
      <?php endif; ?>
      <span class="hd-order-card__badge hd-order-card__badge--<?php echo $tone; ?>"><?php echo esc_html((string) $o['status']); ?></span>
    </div>
    <div class="hd-order-card__body">
      <header class="hd-order-card__head">
        <div>
          <?php if (!empty($o['sku'])) : ?><p class="hd-order-card__sku"><?php echo esc_html((string) $o['sku']); ?></p><?php endif; ?>
          <h3 class="hd-order-card__title"><?php echo esc_html((string) $o['product']); ?></h3>
        </div>
        <?php if ($o['prix'] !== null) : ?>
          <p class="hd-order-card__price"><strong><?php echo esc_html(rtrim(rtrim(number_format((float) $o['prix'], 2, '.', ''), '0'), '.')); ?></strong> <span>DT</span></p>
        <?php endif; ?>
      </header>
      <div class="hd-order-card__facts">
        <?php if (!empty($o['qty'])) : ?>
          <div class="hd-order-card__fact"><span data-i18n="account.qty_short">Qté</span><strong><?php echo esc_html((string) $o['qty']); ?></strong></div>
        <?php endif; ?>
        <?php if ($o['discount'] !== null && (float) $o['discount'] > 0) : ?>
          <div class="hd-order-card__fact"><span data-i18n="account.discount">Remise</span><strong>−<?php echo esc_html(rtrim(rtrim(number_format((float) $o['discount'], 1, '.', ''), '0'), '.')); ?>%</strong></div>
        <?php endif; ?>
        <?php if ($when !== '') : ?>
          <div class="hd-order-card__fact"><span data-i18n="account.date">Date</span><strong><?php echo esc_html($when); ?></strong></div>
        <?php endif; ?>
        <div class="hd-order-card__fact"><span data-i18n="account.ref">Réf.</span><strong>#<?php echo (int) $o['id']; ?></strong></div>
      </div>
      <?php if (!empty($o['region_line'])) : ?>
        <p class="hd-order-card__ship">
          <span class="hd-order-card__ship-ico" aria-hidden="true">📍</span>
          <span><?php echo esc_html((string) $o['region_line']); ?></span>
        </p>
      <?php endif; ?>
      <div class="hd-order-card__track" aria-hidden="true">
        <?php for ($si = 0; $si < 4; $si++) : ?>
          <span class="hd-order-card__step<?php echo $si <= $idx ? ' is-on' : ''; ?>"></span>
        <?php endfor; ?>
      </div>
    </div>
  </article>
    <?php
}

/**
 * Shortcode: auth + orders + client menu.
 */
function hd_customer_orders_shortcode(): string
{
    $view = sanitize_key((string) ($_GET['view'] ?? 'login'));
    if ($view !== 'register') {
        $view = 'login';
    }

    $inBoutiqueChrome = is_page_template('page-hd-account.php') || is_page_template('page-hd-boutique.php');

    ob_start();
    // Force LTR layout shell (i18n labels stay translated via data-i18n).
    $shellClass = 'hd-cust-shell' . ($inBoutiqueChrome ? ' hd-cust-shell--under-hero' : '');
    echo '<div class="' . esc_attr($shellClass) . '" dir="ltr">';
    if (!$inBoutiqueChrome) {
        echo '<header class="hd-cust-top">';
        echo '<a class="hd-cust-brand" href="' . esc_url(home_url('/')) . '"><span class="hv-logo-herbal">HERBAL</span> <span class="hv-logo-vitality">VITALY</span></a>';
        echo hd_customer_nav_links_html('boutique');
        echo '</header>';
    }

    if (!is_user_logged_in()) {
        echo '<div class="hd-cust-auth-wrap">';
        echo hd_customer_auth_card_html($view);
        echo '</div>';
        echo '</div>';
        return (string) ob_get_clean();
    }

    $userId = get_current_user_id();
    $phone = (string) get_user_meta($userId, 'hd_phone', true);
    if ($phone !== '') {
        hd_customer_attach_leads_by_identity($userId, $phone);
    }
    $cookieLead = hd_customer_parse_lead_cookie($_COOKIE[HD_CUSTOMER_COOKIE] ?? '');
    if ($cookieLead > 0) {
        hd_customer_link_lead_to_user($cookieLead, $userId);
    }

    $orders = hd_customer_orders_for_user($userId);
    $user = wp_get_current_user();
    $okCode = sanitize_key((string) ($_GET['hd_ok'] ?? ''));
    $ok = $okCode !== '';
    $errCode = sanitize_key((string) ($_GET['hd_err'] ?? ''));
    $errMsg = $errCode !== '' ? hd_customer_error_message($errCode) : '';
    $panel = hd_customer_current_panel();
    $base = hd_customer_account_url();
    $logoutUrl = wp_logout_url(home_url('/'));
    $uphone = (string) get_user_meta($userId, 'hd_phone', true);
    $profileAction = esc_url(admin_url('admin-post.php'));

    $stats = [
        'total' => count($orders),
        'open' => 0,
        'ship' => 0,
        'done' => 0,
    ];
    foreach ($orders as $o) {
        $t = (string) ($o['status_tone'] ?? '');
        if (in_array($t, ['ok'], true)) {
            $stats['done']++;
        } elseif ($t === 'ship') {
            $stats['ship']++;
        } else {
            $stats['open']++;
        }
    }
    $highlights = array_slice($orders, 0, 6);
    ?>
    <div class="hd-cust-layout">
      <aside class="hd-cust-menu" aria-label="Menu client">
        <p class="hd-cust-menu__label" data-i18n="account.menu_label">Mon espace</p>
        <a class="hd-cust-menu__item<?php echo $panel === 'orders' ? ' is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('panel', 'orders', $base)); ?>" data-i18n="account.menu_orders"><?php echo hd_customer_icon('orders'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span>Mes commandes</span></a>
        <a class="hd-cust-menu__item<?php echo $panel === 'profile' ? ' is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('panel', 'profile', $base)); ?>" data-i18n="account.menu_profile"><?php echo hd_customer_icon('user'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span>Mon profil</span></a>
        <a class="hd-cust-menu__item<?php echo $panel === 'track' ? ' is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('panel', 'track', $base)); ?>" data-i18n="account.menu_track"><?php echo hd_customer_icon('track'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span>Suivi</span></a>
        <a class="hd-cust-menu__item hd-cust-menu__item--muted" href="<?php echo esc_url($logoutUrl); ?>" data-i18n="nav.logout"><?php echo hd_customer_icon('logout'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span>Déconnexion</span></a>
        <a class="hd-cust-menu__item hd-cust-menu__item--cta" href="<?php echo esc_url(home_url('/#catalogue')); ?>" data-i18n="account.back_shop"><?php echo hd_customer_icon('shop'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span>Retour boutique</span></a>
      </aside>

      <div class="hd-cust-main">
        <section class="hd-cust-hero-panel">
          <div class="hd-cust-hero-panel__copy">
            <p class="hd-cust-eyebrow" data-i18n="account.welcome">Espace client</p>
            <h2 class="hd-cust-title">
              <?php
                if ($panel === 'profile') {
                    echo '<span data-i18n="account.menu_profile">Mon profil</span>';
                } elseif ($panel === 'track') {
                    echo '<span data-i18n="account.menu_track">Suivi</span>';
                } else {
                    echo '<span data-i18n="account.orders_title">Mes commandes</span>';
                }
                ?>
            </h2>
            <p class="hd-cust-sub"><?php echo esc_html($user->user_email); ?>
              <?php
                if ($uphone !== '') {
                    echo ' · <span dir="ltr">' . esc_html($uphone) . '</span>';
                }
                ?>
            </p>
          </div>
          <div class="hd-cust-stats" aria-label="Résumé">
            <div class="hd-cust-stat"><strong><?php echo (int) $stats['total']; ?></strong><span data-i18n="account.stat_total">Commandes</span></div>
            <div class="hd-cust-stat"><strong><?php echo (int) $stats['open']; ?></strong><span data-i18n="account.stat_open">En cours</span></div>
            <div class="hd-cust-stat"><strong><?php echo (int) $stats['ship']; ?></strong><span data-i18n="account.stat_ship">Envoyées</span></div>
            <div class="hd-cust-stat"><strong><?php echo (int) $stats['done']; ?></strong><span data-i18n="account.stat_done">Confirmées</span></div>
          </div>
        </section>

        <?php if ($ok && $okCode === 'profile') : ?>
          <p class="hd-cust-ok" role="status"><?php echo hd_customer_icon('check'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <span data-i18n="account.profile_ok">Profil enregistré.</span></p>
        <?php elseif ($ok) : ?>
          <p class="hd-cust-ok" data-i18n="account.created_ok">Compte créé — vos commandes liées apparaissent ci-dessous.</p>
        <?php endif; ?>
        <?php if ($errMsg !== '' && $panel === 'profile') : ?>
          <p class="hd-cust-alert" role="alert"><?php echo esc_html($errMsg); ?></p>
        <?php endif; ?>

        <?php if ($panel === 'profile') :
            $nameField = hd_customer_profile_name_field($user);
            ?>
          <div class="hd-cust-card hd-cust-card--wide hd-cust-card--profile">
            <h3 class="hd-cust-title hd-cust-title--sm" data-i18n="account.menu_profile">Mon profil</h3>
            <p class="hd-cust-sub" data-i18n="account.profile_sub">Mettez à jour vos informations. Le mot de passe est optionnel.</p>
            <form class="hd-cust-form hd-cust-profile-form" method="post" action="<?php echo $profileAction; ?>">
              <input type="hidden" name="action" value="hd_customer_profile" />
              <?php wp_nonce_field('hd_customer_profile'); ?>
              <div class="hd-cust-form__section">
                <label class="hd-cust-label">
                  <span data-i18n="account.profile_name">Nom</span>
                  <span class="hd-cust-field">
                    <span class="hd-cust-field__ico" aria-hidden="true"><?php echo hd_customer_icon('user'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                    <input class="hd-cust-input" type="text" name="display_name" required minlength="2" autocomplete="name" placeholder="<?php echo esc_attr($nameField['placeholder']); ?>" value="<?php echo esc_attr($nameField['value']); ?>" />
                  </span>
                </label>
                <label class="hd-cust-label">
                  <span data-i18n="account.email">Email</span>
                  <span class="hd-cust-field">
                    <span class="hd-cust-field__ico" aria-hidden="true"><?php echo hd_customer_icon('mail'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                    <input class="hd-cust-input" type="email" name="email" required autocomplete="email" dir="ltr" value="<?php echo esc_attr($user->user_email); ?>" />
                  </span>
                </label>
                <label class="hd-cust-label">
                  <span data-i18n="account.phone">Téléphone (Tunisie)</span>
                  <span class="hd-cust-field">
                    <span class="hd-cust-field__ico" aria-hidden="true"><?php echo hd_customer_icon('phone'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                    <input class="hd-cust-input" type="tel" name="phone" autocomplete="tel" inputmode="tel" dir="ltr" placeholder="20 XXX XXX" value="<?php echo esc_attr($uphone); ?>" data-i18n-placeholder="account.phone_ph" />
                  </span>
                </label>
              </div>
              <fieldset class="hd-cust-pass-block">
                <legend data-i18n="account.pass_change">Changer le mot de passe (optionnel)</legend>
                <p class="hd-cust-pass-block__hint" data-i18n="account.pass_hint">Laissez vide pour conserver votre mot de passe actuel.</p>
                <label class="hd-cust-label">
                  <span data-i18n="account.pass_current">Mot de passe actuel</span>
                  <span class="hd-cust-field">
                    <span class="hd-cust-field__ico" aria-hidden="true"><?php echo hd_customer_icon('lock'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                    <input class="hd-cust-input" type="password" name="password_current" autocomplete="current-password" />
                  </span>
                </label>
                <label class="hd-cust-label">
                  <span data-i18n="account.password_new">Nouveau mot de passe (8+)</span>
                  <span class="hd-cust-field">
                    <span class="hd-cust-field__ico" aria-hidden="true"><?php echo hd_customer_icon('lock'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                    <input class="hd-cust-input" type="password" name="password" autocomplete="new-password" minlength="8" />
                  </span>
                </label>
                <label class="hd-cust-label">
                  <span data-i18n="account.pass_confirm">Confirmer le mot de passe</span>
                  <span class="hd-cust-field">
                    <span class="hd-cust-field__ico" aria-hidden="true"><?php echo hd_customer_icon('lock'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                    <input class="hd-cust-input" type="password" name="password2" autocomplete="new-password" minlength="8" />
                  </span>
                </label>
              </fieldset>
              <p class="hd-cust-profile-meta"><span data-i18n="account.stat_total">Commandes</span> · <strong><?php echo (int) $stats['total']; ?></strong></p>
              <button type="submit" class="hd-cust-btn hd-cust-btn--icon hd-cust-btn--block" data-i18n="account.profile_save"><?php echo hd_customer_icon('save'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span>Enregistrer</span></button>
            </form>
          </div>
        <?php elseif ($panel === 'track') : ?>
          <?php if (!$orders) : ?>
            <div class="hd-cust-card hd-cust-card--wide hd-cust-card--center">
              <p class="hd-cust-empty" data-i18n="account.orders_empty">Aucune commande boutique pour le moment.</p>
            </div>
          <?php else : ?>
            <div class="hd-cust-track-list">
              <?php foreach (array_slice($orders, 0, 12) as $o) :
                    $idx = hd_customer_order_step_index((string) ($o['status'] ?? ''));
                    $labels = ['Reçue', 'Préparation', 'Envoi', 'Confirmée'];
                    ?>
                <div class="hd-cust-track-row">
                  <div class="hd-cust-track-row__meta">
                    <strong>#<?php echo (int) $o['id']; ?> · <?php echo esc_html((string) $o['product']); ?></strong>
                    <span class="hd-order-card__badge hd-order-card__badge--<?php echo esc_attr((string) ($o['status_tone'] ?? 'muted')); ?>" style="position:static;display:inline-block;width:auto"><?php echo esc_html((string) $o['status']); ?></span>
                  </div>
                  <div class="hd-cust-track-steps">
                    <?php foreach ($labels as $si => $lab) : ?>
                      <div class="hd-cust-track-step<?php echo $si <= $idx ? ' is-on' : ''; ?>">
                        <i></i><span><?php echo esc_html($lab); ?></span>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php else : ?>
          <?php if ($highlights) : ?>
            <div class="hd-cust-slides" data-hd-cust-carousel>
              <p class="hd-cust-eyebrow" data-i18n="account.slides_title">À la une</p>
              <div class="hd-cust-slides__viewport">
                <div class="hd-cust-slides__track">
                  <?php foreach ($highlights as $o) : ?>
                    <div class="hd-cust-slides__slide">
                      <?php hd_customer_render_order_card($o); ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="hd-cust-slides__dots" role="tablist" aria-label="Slides"></div>
            </div>
          <?php endif; ?>

          <?php if (!$orders) : ?>
            <div class="hd-cust-card hd-cust-card--wide hd-cust-card--center">
              <p class="hd-cust-empty" data-i18n="account.orders_empty">Aucune commande boutique pour le moment. Dès qu’une commande est passée avec votre numéro, elle apparaîtra ici avec le statut CRM.</p>
              <p class="hd-cust-actions"><a class="hd-cust-btn" href="<?php echo esc_url(home_url('/#catalogue')); ?>" data-i18n="account.back_shop">Retour boutique</a></p>
            </div>
          <?php else : ?>
            <div class="hd-cust-orders-grid">
              <?php foreach ($orders as $o) {
                    hd_customer_render_order_card($o);
              } ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php
    echo '</div>';
    return (string) ob_get_clean();
}
add_shortcode('hd_customer_orders', 'hd_customer_orders_shortcode');
