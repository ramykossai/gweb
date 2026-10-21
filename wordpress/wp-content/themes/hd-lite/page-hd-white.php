<?php
/**
 * Template Name: HD White Page
 * Serves white/{slug}/site.html for boutique product pages.
 * Cloak inline continues to readfile() the raw HTML (unchanged).
 * Here we patch links defensively, inject native lead form (same form_key as LP),
 * inject FR/AR i18n switch, and print tracker footer scripts.
 */
if (!defined('ABSPATH')) {
    exit;
}

// Lead forms embed a WP nonce — never serve a shared LiteSpeed HTML cache (stale nonce → "Invalid form").
if (!defined('DONOTCACHEPAGE')) {
    define('DONOTCACHEPAGE', true);
}
do_action('litespeed_control_set_nocache', 'hd white lead form');
if (!headers_sent()) {
    header('X-LiteSpeed-Cache-Control: no-cache', false);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', false);
    nocache_headers();
}

// Standalone white HTML: never call wp_head(), so admin-bar CSS never loads.
// wp_footer() would still inject the bar → giant unstyled link list for admins.
show_admin_bar(false);

$rel = get_post_meta(get_the_ID(), 'hd_white_public_path', true);
$whiteRoot = realpath(ABSPATH . 'white');
$file = $rel ? realpath(ABSPATH . ltrim((string) $rel, '/')) : false;
$insideWhiteRoot = $whiteRoot !== false
    && $file !== false
    && strpos($file, $whiteRoot . DIRECTORY_SEPARATOR) === 0;
if (!$insideWhiteRoot || !is_file($file) || !is_readable($file)) {
    status_header(404);
    echo 'White page missing';
    exit;
}

$html = (string) file_get_contents($file);
$htmlRaw = $html;
$slug = '';
if (preg_match('#white/([^/]+)/#', (string) $rel, $m)) {
    $slug = sanitize_title($m[1]);
}
if ($slug === '' && get_post_field('post_name')) {
    $slug = preg_replace('/^produit-/', '', (string) get_post_field('post_name'));
}

$home = home_url('/');
$produit = $slug ? home_url('/produit-' . $slug . '/') : $home;
$whiteBase = $slug ? trailingslashit(home_url('/white/' . $slug)) : $home;

// Drop <base> and absolutize assets. Relative paths + base=/white/{slug}/ caused 403/KO
// for filtered geos (hash nav, Options -Indexes). Applies to every boutique white.
$html = preg_replace('#<base\s+href=["\'][^"\']*["\']\s*/?>#i', '', (string) $html, 1) ?? $html;
if ($slug !== '') {
    $wb = esc_url($whiteBase);
    $html = preg_replace('#href=(["\'])(?:\./)?(css/[^"\']+)\1#i', 'href=$1' . $wb . '$2$1', $html);
    $html = preg_replace('#src=(["\'])(?:\./)?(js/[^"\']+)\1#i', 'src=$1' . $wb . '$2$1', $html);
    $html = preg_replace('#src=(["\'])(?:\./)?(images/[^"\']+)\1#i', 'src=$1' . $wb . '$2$1', $html);
    $html = preg_replace('#href=(["\'])(?:\./)?(images/[^"\']+)\1#i', 'href=$1' . $wb . '$2$1', $html);
    // Broken icon packs: hide dead SVGs via onerror already; neutralize fatal nextSibling if missing.
    $html = str_replace(
        "onerror=\"this.style.display='none'; this.nextElementSibling.style.display='block'\"",
        "onerror=\"this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='block'\"",
        $html
    );
}

// Defensive link homogenization (files are also patched on disk).
$html = str_replace('logo-vitality">VITALITY</span>', 'logo-vitality">VITALY</span>', $html);
// Whites were built on thebigone — rewrite leftover absolute URLs to current home.
$tbHost = 'https://thebigone.online';
$hdHost = rtrim((string) home_url(''), '/');
if ($hdHost !== '' && strcasecmp($hdHost, $tbHost) !== 0) {
    $html = str_replace([$tbHost, 'http://thebigone.online'], $hdHost, $html);
}
$html = preg_replace('#href="(?:\./)?index\.html([^"]*)"#i', 'href="' . esc_url($home) . '"', $html);
$html = preg_replace('#href="(?:\./)?mentions-legales\.html"#i', 'href="' . esc_url(home_url('/mentions-legales/')) . '"', $html);
$html = preg_replace('#href="(?:\./)?politique-confidentialite\.html"#i', 'href="' . esc_url(home_url('/confidentialite/')) . '"', $html);
$html = preg_replace('#href="(?:\./)?cgv\.html"#i', 'href="' . esc_url(home_url('/cgv/')) . '"', $html);
$html = preg_replace('#href="(?:\./)?faq\.html"#i', 'href="' . esc_url(home_url('/faq/')) . '"', $html);
$html = preg_replace('#href="(?:\./)?commande\.html"#i', 'href="' . esc_url($produit) . '#commander"', $html);
$html = preg_replace('#(<a href=")[^"]+(">\s*Accueil\s*</a>)#iu', '$1' . esc_url($home) . '$2', $html);

// Keep in-page anchors on the boutique WP URL (not /white/{slug}/).
if ($produit !== '') {
    $html = preg_replace(
        '/href=(["\'])#([a-zA-Z0-9_-]+)\1/',
        'href=$1' . esc_url($produit) . '#$2$1',
        $html
    );
}

$formKey = (string) get_post_meta(get_the_ID(), 'hd_boutique_form_key', true);
if ($formKey === '') {
    $formKey = (string) get_post_meta(get_the_ID(), 'hd_form_key', true);
}
$formHtml = '';
if ($formKey && function_exists('hd_boutique_order_form_html')) {
    $formHtml = hd_boutique_order_form_html($formKey);
} elseif ($formKey && function_exists('do_shortcode')) {
    $formHtml = do_shortcode('[hd_lead_form key="' . esc_attr($formKey) . '"]');
}

if ($formHtml !== '') {
    $injected = false;
    if (preg_match('/<section[^>]*id="commander"[^>]*>.*?<\/section>/is', $html, $secMatch)) {
        $section = $secMatch[0];
        $newSection = $section;
        // Replace large CTA that points to commande / #commander
        $replaced = preg_replace(
            '/<a[^>]*class="[^"]*btn-primary[^"]*btn-large[^"]*"[^>]*>.*?<\/a>/is',
            $formHtml,
            $newSection,
            1,
            $count
        );
        if ($count > 0) {
            $newSection = $replaced;
            $injected = true;
        } else {
            // Append form before closing section
            $newSection = preg_replace('/<\/section>\s*$/i', '<div class="hv-order-wrap" style="margin-top:24px">' . $formHtml . '</div></section>', $newSection, 1);
            $injected = true;
        }
        $html = str_replace($section, $newSection, $html);
    }
    if (!$injected) {
        $html = preg_replace('/<\/body>/i', '<div class="hv-order-wrap container" style="padding:40px 20px">' . $formHtml . '</div></body>', $html, 1);
    }
}

// Tag shared chrome for i18n (no disk edits to white/*/site.html).
$html = preg_replace(
    '#(<div class="disclaimer-bar">\s*<p)(>)#i',
    '$1 data-i18n="disc.bar" data-i18n-html="1"$2',
    $html,
    1
);
$html = preg_replace(
    '#(<p class="temo-disclaimer")(>)#i',
    '$1 data-i18n="disc.temo"$2',
    $html,
    1
);

// Trust / FAQ / contact shared blocks (identical on all 70 products).
$i18nBlockReplacements = [
    [
        '#(<article class="hv-trust-card">\s*<h3>\s*)À propos(\s*</h3>\s*<p)(>)#u',
        '$1<span data-i18n="trust.about">À propos</span>$2 data-i18n="trust.about_p" data-i18n-html="1"$3',
    ],
    [
        '#(<article class="hv-trust-card">\s*<h3>\s*)Livraison &amp; retours(\s*</h3>\s*<p)(>)#u',
        '$1<span data-i18n="trust.ship">Livraison &amp; retours</span>$2 data-i18n="trust.ship_p" data-i18n-html="1"$3',
    ],
    [
        '#(<article class="hv-trust-card">\s*<h3>\s*)Confidentialité(\s*</h3>\s*<p)(>)#u',
        '$1<span data-i18n="trust.privacy">Confidentialité</span>$2 data-i18n="trust.privacy_p" data-i18n-html="1"$3',
    ],
    [
        '#(<article class="hv-trust-card">\s*<h3>\s*)Avertissement produit(\s*</h3>\s*<p)(>)#u',
        '$1<span data-i18n="trust.warn">Avertissement produit</span>$2 data-i18n="trust.warn_p" data-i18n-html="1"$3',
    ],
    [
        '#(<div class="hv-trust-faq">\s*<h3>\s*)FAQ rapide(\s*</h3>)#u',
        '$1<span data-i18n="trust.faq">FAQ rapide</span>$2',
    ],
    [
        '#(<div class="hv-trust-faq">[\s\S]*?<ul>\s*<li)(>)([\s\S]*?</li>\s*<li)(>)([\s\S]*?</li>\s*<li)(>)([\s\S]*?</li>)#u',
        '$1 data-i18n="trust.faq_order" data-i18n-html="1"$2$3 data-i18n="trust.faq_pay" data-i18n-html="1"$4$5 data-i18n="trust.faq_help" data-i18n-html="1"$6$7',
    ],
    [
        '#(<span class="section-tag">\s*)Commandez Maintenant(\s*</span>)#u',
        '$1<span data-i18n="prod.order_now">Commandez Maintenant</span>$2',
    ],
    [
        '#(<span class="section-tag">\s*)Transparence(\s*</span>)#u',
        '$1<span data-i18n="trust.tag">Transparence</span>$2',
    ],
    [
        '#(<span class="section-tag">\s*)Contact(\s*</span>)#u',
        '$1<span data-i18n="contact.tag">Contact</span>$2',
    ],
    [
        '#(<span class="section-tag">\s*)Le Produit(\s*</span>)#u',
        '$1<span data-i18n="prod.le_produit">Le Produit</span>$2',
    ],
    [
        '#(<span class="section-tag">\s*)Composition(\s*</span>)#u',
        '$1<span data-i18n="prod.composition">Composition</span>$2',
    ],
    [
        '#(<span class="section-tag">\s*)Bienfaits(\s*</span>)#u',
        '$1<span data-i18n="prod.bienfaits">Bienfaits</span>$2',
    ],
    [
        '#(<h4>\s*)Navigation(\s*</h4>)#u',
        '$1<span data-i18n="nav.navigation">Navigation</span>$2',
    ],
    [
        '#(<h4>\s*)Informations(\s*</h4>)#u',
        '$1<span data-i18n="nav.infos">Informations</span>$2',
    ],
];
foreach ($i18nBlockReplacements as $pair) {
    $html = preg_replace($pair[0], $pair[1], $html, 1);
}

// Shared lead paragraphs under trust / contact headers.
$html = preg_replace(
    '#(Compléments alimentaires et soins formulés avec exigence — sans promesses médicales irréalistes\.)#u',
    '<span data-i18n="trust.lead">$1</span>',
    $html,
    1
);
$html = preg_replace(
    '#(Disponible en Tunisie\. Livraison rapide et sécurisée partout sur le territoire\.)#u',
    '<span data-i18n="prod.available">$1</span>',
    $html,
    1
);

// Account links in white navbar (desktop + mobile) — auth-aware.
if (function_exists('hd_customer_nav_links_html')) {
    $acctDesk = hd_customer_nav_links_html('white');
    $acctMob = hd_customer_nav_links_html('white-mobile');
    // Delimiter is ~ : the pattern contains an unescaped # (href="#commander").
    // Callback replacement keeps $ / \ inside the account markup literal.
    $html = preg_replace_callback(
        '~(<a href="#commander" class="nav-cta">)([^<]*)(</a>)~i',
        static function ($m) use ($acctDesk) {
            return $acctDesk . $m[0];
        },
        $html,
        1
    ) ?? $html;
    $html = preg_replace_callback(
        '~(<a href="#commander" class="mobile-cta">)([^<]*)(</a>)~i',
        static function ($m) use ($acctMob) {
            return $acctMob . $m[0];
        },
        $html,
        1
    ) ?? $html;
}
// Last-resort guard: if the rewrites above lost the document, serve the raw white page
// rather than a body-less shell of head/footer assets.
if (!is_string($html) || stripos($html, '</body>') === false) {
    $html = $htmlRaw;
}

if (function_exists('hd_customer_print_assets')) {
    ob_start();
    hd_customer_print_assets();
    $custCss = ob_get_clean();
    if ($custCss !== '' && stripos($html, '</head>') !== false) {
        $html = preg_replace('/<\/head>/i', $custCss . '</head>', $html, 1);
    }
}

// Cairo font for Arabic UI (product whites already load Orbitron/Exo).
$cairo = '<link rel="stylesheet" href="https://fonts.bunny.net/css?family=Cairo:400,600,700" id="hv-cairo"/>';
if (stripos($html, '</head>') !== false) {
    $html = preg_replace('/<\/head>/i', $cairo . '</head>', $html, 1);
}

// i18n assets (switch FR|ع + dictionary) — no edits to white/*/site.html on disk.
ob_start();
if (function_exists('hd_hv_i18n_print_assets')) {
    hd_hv_i18n_print_assets();
}
$i18nAssets = ob_get_clean();
if ($i18nAssets !== '') {
    if (stripos($html, '</body>') !== false) {
        $html = preg_replace('/<\/body>/i', $i18nAssets . '</body>', $html, 1);
    } else {
        $html .= $i18nAssets;
    }
}

// Tracker + lead helpers: enqueue then print into HTML (template exits early).
do_action('wp_enqueue_scripts');
ob_start();
wp_print_footer_scripts();
do_action('wp_footer');
$foot = ob_get_clean();
if ($foot !== '') {
    if (stripos($html, '</body>') !== false) {
        $html = preg_replace('/<\/body>/i', $foot . '</body>', $html, 1);
    } else {
        $html .= $foot;
    }
}

status_header(200);
header('Content-Type: text/html; charset=UTF-8');
// Final domain rewrite (covers i18n JSON injected above + escaped slashes).
$hdHost = rtrim((string) home_url(''), '/');
if ($hdHost !== '' && stripos($hdHost, 'thebigone.online') === false) {
    $html = str_replace(
        [
            'https://thebigone.online',
            'http://thebigone.online',
            'https:\\/\\/thebigone.online',
            'http:\\/\\/thebigone.online',
        ],
        [
            $hdHost,
            $hdHost,
            str_replace('/', '\\/', $hdHost),
            str_replace('/', '\\/', $hdHost),
        ],
        $html
    );
}
echo $html;
exit;
