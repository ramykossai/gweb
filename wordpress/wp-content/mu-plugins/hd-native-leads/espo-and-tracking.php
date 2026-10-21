<?php
/**
 * HD Native Leads — Espo LeadCapture + trackingcomment (vendeur FR).
 *
 * Mapping Espo (prod) :
 *   cProjetId        ← form.project_id (IDs CRM inchangés)
 *   phoneNumber      ← téléphone TN 8 digits normalisé
 *   firstName        ← nom (optionnel)
 *   cMarketingsource ← utm_campaign
 *   cLandingpage     ← URL LP (first-touch / landing tracker / current_url)
 *   cFbclid          ← fbclid
 *   cFbpostid        ← utm_content (ID contenu / ad Meta envoyé par défaut FB)
 *   cFbprofile       ← "" (réservé)
 *   cWtid            ← tbclid
 *   cVisualid        ← utm_medium créative (ex. OPTIMAL) ; mediums génériques (paid, referal…) → OPTIMAL
 *   cTrackingcomment ← meta.trackingcomment (généré une fois à la conversion)
 *
 * Boutique ONLY (forms commande white/boutique — pas les LP classiques) :
 *   cAdressRegion    ← gouvernorat (+ adresse si dispo)
 *   cPrixTotal       ← palier catalogue prices_json (number)
 *   cQuantite        ← quantité form (label ou unités)
 *   cDiscount        ← remise % palier catalogue (number)
 *
 * Champ form def `webhook` (n8n) : ignoré pour l’envoi principal.
 * URL API : option `hd_native_espo_leadcapture_url` ou constante HD_NATIVE_ESPO_LEADCAPTURE_URL.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('HD_NATIVE_ESPO_LEADCAPTURE_URL')) {
    define(
        'HD_NATIVE_ESPO_LEADCAPTURE_URL',
        'https://herbalcrm.hvserver.site/api/v1/LeadCapture/7d5c78d55f54e5e25d319b0518eba4e3'
    );
}

define('HD_NATIVE_ESPO_URL_OPTION', 'hd_native_espo_leadcapture_url');

/**
 * Espo LeadCapture endpoint (override via WP option).
 */
function hd_native_espo_leadcapture_url(): string
{
    $opt = trim((string) get_option(HD_NATIVE_ESPO_URL_OPTION, ''));
    if ($opt !== '' && preg_match('#^https?://#i', $opt)) {
        return esc_url_raw($opt);
    }
    return HD_NATIVE_ESPO_LEADCAPTURE_URL;
}

/**
 * ISO 3166-1 alpha-2 → drapeau emoji (regional indicator symbols).
 * Ex. TN → 🇹🇳. Chaîne vide si ISO invalide.
 */
function hd_native_iso2_flag_emoji(string $iso): string
{
    $iso = strtoupper(preg_replace('/[^A-Za-z]/', '', $iso) ?? '');
    if (strlen($iso) !== 2) {
        return '';
    }
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $cp = 0x1F1E6 + (ord($iso[$i]) - ord('A'));
        if (function_exists('mb_chr')) {
            $out .= mb_chr($cp, 'UTF-8');
        } else {
            $out .= iconv('UCS-4LE', 'UTF-8', pack('V', $cp));
        }
    }
    return $out;
}

/**
 * Resolve human product name for vendeur / tracker UI.
 * Order: explicit meta → form title → form_key lookup → offer_slug → page title → path leaf.
 *
 * @param array<string,mixed> $form
 * @param array<string,mixed> $meta
 * @param array<string,mixed>|null $click
 * @param array<string,mixed> $ctx
 */
function hd_native_resolve_product_name(array $form = [], array $meta = [], ?array $click = null, array $ctx = []): string
{
    $candidates = [];
    foreach (['product', 'product_name', 'form_title'] as $k) {
        $v = trim((string) ($meta[$k] ?? ''));
        if ($v !== '') {
            $candidates[] = $v;
        }
    }
    foreach (['title', 'form_title'] as $k) {
        $v = trim((string) ($form[$k] ?? ''));
        if ($v !== '') {
            $candidates[] = $v;
        }
    }

    $formKey = sanitize_key((string) ($form['key'] ?? ($meta['form_key'] ?? '')));
    if ($formKey !== '' && defined('HD_LEADS_OPTION')) {
        $all = get_option(HD_LEADS_OPTION, []);
        if (is_array($all) && isset($all[$formKey]) && is_array($all[$formKey])) {
            $t = trim((string) ($all[$formKey]['title'] ?? ($all[$formKey]['form_title'] ?? '')));
            if ($t !== '') {
                $candidates[] = $t;
            }
        }
    }

    $offer = trim((string) ($click['offer_slug'] ?? ($meta['offer_slug'] ?? '')));
    if ($offer !== '') {
        $candidates[] = str_replace(['-', '_'], ' ', $offer);
    }

    $pageId = (int) ($ctx['page_id'] ?? ($meta['page_id'] ?? 0));
    if ($pageId > 0) {
        $title = get_the_title($pageId);
        if (is_string($title) && trim($title) !== '') {
            $candidates[] = trim($title);
        }
    }

    $landing = (string) ($click['landing_url'] ?? ($ctx['current_url'] ?? ($meta['wp_http_referer'] ?? '')));
    if ($landing !== '') {
        $path = (string) (wp_parse_url($landing, PHP_URL_PATH) ?: '');
        $leaf = trim(basename($path), '/');
        if ($leaf !== '' && !preg_match('/^(c|merci|thank|index\.php)$/i', $leaf)) {
            $candidates[] = str_replace(['-', '_'], ' ', $leaf);
        }
    }

    foreach ($candidates as $c) {
        $c = trim(wp_strip_all_tags($c));
        if ($c === '' || strcasecmp($c, 'lead') === 0) {
            continue;
        }
        return substr($c, 0, 120);
    }
    return $formKey !== '' ? $formKey : '';
}

/**
 * Short referrer host (+ optional path) for vendeur comment — no invented search queries.
 *
 * @return array{host:string,path:string,q:string,label:string}
 */
function hd_native_referrer_bits(?array $click, array $contextMeta = []): array
{
    $raw = trim((string) ($contextMeta['ref'] ?? ($click['referrer'] ?? '')));
    $host = trim((string) ($contextMeta['ref_host'] ?? ''));
    $path = trim((string) ($contextMeta['ref_path'] ?? ''));
    $q = trim((string) ($contextMeta['ref_q'] ?? ''));

    if ($raw !== '' && ($host === '' || $path === '')) {
        $parts = wp_parse_url($raw);
        if (is_array($parts)) {
            if ($host === '' && !empty($parts['host'])) {
                $host = strtolower((string) $parts['host']);
            }
            if ($path === '' && isset($parts['path'])) {
                $path = substr((string) $parts['path'], 0, 80);
            }
            if ($q === '' && !empty($parts['query'])) {
                parse_str((string) $parts['query'], $qs);
                if (!empty($qs['q']) && is_string($qs['q'])) {
                    $q = substr(sanitize_text_field($qs['q']), 0, 80);
                } elseif (!empty($qs['utm_term']) && is_string($qs['utm_term'])) {
                    $q = substr(sanitize_text_field($qs['utm_term']), 0, 80);
                }
            }
        }
    }

    $label = '';
    if ($host !== '') {
        $label = $host;
        if ($path !== '' && $path !== '/') {
            $label .= $path;
        }
    }
    return [
        'host' => substr($host, 0, 120),
        'path' => substr($path, 0, 80),
        'q' => substr($q, 0, 80),
        'label' => substr($label, 0, 160),
    ];
}

/**
 * Fetch click row + events for a tbclid (read-only, conversion-time snapshot).
 *
 * @return array{click:?array<string,mixed>,events:list<array<string,mixed>>}
 */
function hd_native_tracker_snapshot(string $tbclid): array
{
    $out = ['click' => null, 'events' => []];
    $tbclid = trim($tbclid);
    if ($tbclid === '' || !function_exists('tb_tracker_table')) {
        return $out;
    }
    global $wpdb;
    $clicks = tb_tracker_table('clicks');
    $events = tb_tracker_table('events');
    $out['click'] = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$clicks} WHERE click_id = %s LIMIT 1", $tbclid),
        ARRAY_A
    );
    if (!$out['click']) {
        $out['click'] = null;
        return $out;
    }
    // Events up to now (lead event may not exist yet at conversion).
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT event_type, url, meta_json, created_at
             FROM {$events}
             WHERE click_id = %s
             ORDER BY id ASC
             LIMIT 200",
            $tbclid
        ),
        ARRAY_A
    );
    $out['events'] = is_array($rows) ? $rows : [];
    return $out;
}

/**
 * @param array<string,mixed>|null $metaJson
 * @return array<string,mixed>
 */
function hd_native_decode_event_meta($metaJson): array
{
    if (is_array($metaJson)) {
        return $metaJson;
    }
    if (!is_string($metaJson) || $metaJson === '') {
        return [];
    }
    $d = json_decode($metaJson, true);
    return is_array($d) ? $d : [];
}

/**
 * Humanize Android model → marque seule (pas de codes techniques inutiles pour le vendeur).
 */
function hd_native_humanize_device_model(string $raw): string
{
    $raw = trim(str_replace(['\\"', '\\'], '', $raw));
    $raw = preg_replace('/\s+Build\/.*$/i', '', $raw) ?: $raw;
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^SM-[A-Z0-9]+$/i', $raw) || stripos($raw, 'samsung') !== false) {
        return 'Samsung';
    }
    if (preg_match('/^Infinix/i', $raw)) {
        return 'Infinix';
    }
    if (preg_match('/^(Redmi|POCO|Mi)\b/i', $raw) || preg_match('/^\d{4,5}[A-Z0-9]+$/i', $raw)) {
        return 'Xiaomi';
    }
    if (preg_match('/Huawei|Honor/i', $raw)) {
        return 'Huawei';
    }
    if (preg_match('/OPPO|Realme|OnePlus|vivo|TECNO|itel/i', $raw, $m)) {
        return ucfirst(strtolower($m[0]));
    }
    if (preg_match('/iPhone/i', $raw)) {
        return 'iPhone';
    }
    if (preg_match('/iPad/i', $raw)) {
        return 'iPad';
    }
    // Fallback: drop opaque codes
    if (preg_match('/^[A-Z0-9\-]{6,}$/i', $raw)) {
        return '';
    }
    return $raw;
}

/**
 * Device + traffic channel profile from UA / client hints.
 *
 * @return array{label:string,is_mobile:bool,from_fb_app:bool,from_ig:bool,os:string,model:string,is_botish:bool,channel:string}
 */
function hd_native_device_profile(?array $click, array $contextMeta): array
{
    $ua = (string) ($click['user_agent'] ?? '');
    $platform = (string) ($contextMeta['ch_platform'] ?? '');
    $mobile = isset($contextMeta['ch_mobile']) ? (string) $contextMeta['ch_mobile'] : '';
    $vw = isset($contextMeta['vw']) ? (int) $contextMeta['vw'] : 0;

    $isMobile = false;
    if ($mobile === '?1' || $mobile === '1') {
        $isMobile = true;
    } elseif ($vw > 0 && $vw < 700) {
        $isMobile = true;
    } elseif (preg_match('/Mobile|Android|iPhone|iPad/i', $ua)) {
        $isMobile = true;
    }

    $fromFbApp = (bool) preg_match('/FB_IAB|FBAV|FBAN/i', $ua);
    $fromIg = (bool) preg_match('/Instagram/i', $ua);
    $isBotish = (bool) preg_match(
        '/bot|spider|crawler|curl|wget|headless|lighthouse|facebookexternalhit|slurp/i',
        $ua
    );

    $os = '';
    $plat = trim(str_replace(['\\"', '\\'], '', $platform), " \t\"'");
    if (preg_match('/Android/i', $plat . ' ' . $ua)) {
        $os = 'Android';
    } elseif (preg_match('/iPhone|iPad|iOS/i', $ua)) {
        $os = 'iOS';
    } elseif (preg_match('/Windows/i', $ua)) {
        $os = 'Windows';
    } elseif (preg_match('/Mac OS X/i', $ua)) {
        $os = 'macOS';
    } elseif ($plat !== '' && !preg_match('/^(not.?a.?brand|chromi)/i', $plat)) {
        $os = $plat;
    }

    $model = '';
    if (preg_match('/\((?:Linux; )?Android[^;]*;\s*([^);]+)/i', $ua, $m)) {
        $model = hd_native_humanize_device_model(trim($m[1]));
    } elseif (preg_match('/(iPhone|iPad)/i', $ua, $m)) {
        $model = $m[1];
    }

    $channel = 'web';
    if ($fromIg) {
        $channel = 'app Instagram';
    } elseif ($fromFbApp) {
        $channel = 'app Facebook';
    } elseif ($isMobile) {
        $channel = 'mobile web';
    } else {
        $channel = 'desktop web';
    }

    // Label vendeur : simple (mobile · Android · Samsung) — pas de codes / écran / "via app"
    $parts = [];
    $parts[] = $isMobile ? '📱 mobile' : '💻 desktop';
    if ($os !== '') {
        $parts[] = $os;
    }
    if ($model !== '') {
        $parts[] = $model;
    }

    return [
        'label' => implode(' · ', array_filter($parts)),
        'is_mobile' => $isMobile,
        'from_fb_app' => $fromFbApp,
        'from_ig' => $fromIg,
        'os' => $os,
        'model' => $model,
        'is_botish' => $isBotish,
        'channel' => $channel,
    ];
}

/** @deprecated use hd_native_device_profile */
function hd_native_device_label(?array $click, array $contextMeta): string
{
    return hd_native_device_profile($click, $contextMeta)['label'];
}

/**
 * Geo / locale label for vendeur comment.
 * Prefer long-term IP geo stored on click; fallback timezone/langue navigateur.
 */
function hd_native_geo_label(?array $click, array $contextMeta, array $meta): string
{
    $bits = [];

    // 1) Stored MaxMind geo on click (preferred)
    $city = trim((string) ($click['geo_city'] ?? ''));
    $region = trim((string) ($click['geo_region'] ?? ''));
    $country = trim((string) ($click['geo_country'] ?? ''));
    $iso = trim((string) ($click['geo_iso'] ?? ''));

    // Lazy fill at conversion if click exists but geo empty
    $tbclid = trim((string) ($meta['tbclid'] ?? ($click['click_id'] ?? '')));
    if ($iso === '' && $country === '' && $city === '' && $tbclid !== '' && function_exists('tb_tracker_enrich_click_geo')) {
        tb_tracker_enrich_click_geo($tbclid);
        if (function_exists('tb_tracker_get_click_geo')) {
            $g = tb_tracker_get_click_geo($tbclid);
            if (is_array($g)) {
                $city = trim((string) ($g['city'] ?? ''));
                $region = trim((string) ($g['region'] ?? ''));
                $country = trim((string) ($g['country'] ?? ''));
                $iso = trim((string) ($g['iso'] ?? ''));
                if ($click !== null) {
                    $click['geo_city'] = $city;
                    $click['geo_region'] = $region;
                    $click['geo_country'] = $country;
                    $click['geo_iso'] = $iso;
                    $click['geo_tz'] = (string) ($g['tz'] ?? '');
                }
            }
        }
    }

    $flag = $iso !== '' ? hd_native_iso2_flag_emoji($iso) : '';

    if ($city !== '' || $region !== '' || $country !== '') {
        $place = [];
        if ($city !== '') {
            $place[] = $city;
        }
        if ($region !== '' && strcasecmp($region, $city) !== 0) {
            $place[] = $region;
        }
        if ($country !== '') {
            $place[] = $country;
        } elseif ($iso !== '') {
            $place[] = $iso;
        }
        if ($place) {
            $placeStr = implode(', ', $place);
            $bits[] = $flag !== '' ? ($flag . ' ' . $placeStr) : ('🗺️ ' . $placeStr);
        }
    } else {
        // 2) Fallback: browser timezone guess
        $tz = (string) ($contextMeta['tz'] ?? ($click['tz'] ?? ($click['geo_tz'] ?? '')));
        if ($tz === 'Africa/Tunis' || stripos($tz, 'Tunis') !== false) {
            $bits[] = ($flag !== '' ? $flag . ' ' : '🇹🇳 ') . 'Tunisie (fuseau navigateur)';
        } elseif (stripos($tz, 'Paris') !== false || $tz === 'Europe/Paris') {
            $fr = hd_native_iso2_flag_emoji('FR');
            $bits[] = ($fr !== '' ? $fr . ' ' : '') . 'France (fuseau Paris)';
        } elseif ($tz !== '') {
            $bits[] = ($flag !== '' ? $flag . ' ' : '🌍 ') . 'fuseau ' . $tz;
        } elseif ($flag !== '') {
            $bits[] = $flag . ($iso !== '' ? ' ' . $iso : '');
        }
    }

    $lang = (string) ($contextMeta['lang'] ?? ($click['lang'] ?? ''));
    if ($lang !== '') {
        $langNice = $lang;
        if (stripos($lang, 'fr-TN') === 0) {
            $langNice = 'fr-TN';
        } elseif (stripos($lang, 'ar') === 0) {
            $langNice = $lang . ' (AR)';
        }
        $bits[] = 'langue ' . $langNice;
    }
    return implode(' · ', $bits);
}

/**
 * Path label for narrative (never dump full LP query / offer dump as marketing).
 */
function hd_native_path_role(string $path): string
{
    $path = strtolower(trim($path));
    if ($path === '' || $path === '/') {
        return 'accueil / boutique';
    }
    if (preg_match('#^/c/#', $path)) {
        return 'landing';
    }
    if (preg_match('#merci|thank#', $path)) {
        return 'page merci';
    }
    if (preg_match('#produit|boutique|shop#', $path)) {
        return 'boutique';
    }
    $leaf = trim(basename($path), '/');
    return $leaf !== '' ? ('page « ' . $leaf . ' »') : 'autre page';
}

/**
 * Cut events at a specific lead when regenerating (shared tbclid safe).
 * At live conversion (leadId=0) keep all events — the current lead row may not exist yet.
 * Grace +8s after lead: engage flush often arrives just after conversion.
 *
 * @param list<array<string,mixed>> $events
 * @return list<array<string,mixed>>
 */
function hd_native_events_until_lead(array $events, int $leadId = 0): array
{
    if ($leadId <= 0) {
        return $events;
    }
    $cutTs = null;
    foreach ($events as $ev) {
        if (($ev['event_type'] ?? '') !== 'lead') {
            continue;
        }
        $m = hd_native_decode_event_meta($ev['meta_json'] ?? null);
        if ((int) ($m['lead_id'] ?? 0) === $leadId) {
            $cutTs = strtotime((string) ($ev['created_at'] ?? ''));
            break;
        }
    }
    if (!$cutTs) {
        return $events;
    }
    $graceEnd = $cutTs + 8;
    $out = [];
    foreach ($events as $ev) {
        $ts = strtotime((string) ($ev['created_at'] ?? ''));
        if ($ts && $ts > $graceEnd) {
            break;
        }
        // After our lead window, ignore other leads / late page noise beyond grace.
        if ($ts && $ts > $cutTs) {
            $type = (string) ($ev['event_type'] ?? '');
            if (!in_array($type, ['engage', 'scroll', 'cta_click'], true)) {
                continue;
            }
        }
        $out[] = $ev;
    }
    return $out;
}

/**
 * Aggregate engagement signals from events (pre-lead preferred).
 *
 * @param list<array<string,mixed>> $events
 * @return array<string,mixed>
 */
function hd_native_aggregate_tracker_signals(array $events, int $leadId = 0): array
{
    $events = hd_native_events_until_lead($events, $leadId);

    $maxScroll = 0;
    $scrollMarks = [];
    $pageViews = 0;
    $ctaClicks = 0;
    $formStarts = 0;
    $formErrors = 0;
    $formErrorMsgs = [];
    $contextMeta = [];
    $firstAt = null;
    $leadAt = null;
    $lastAt = null;
    $paths = [];
    $pathOrder = [];
    $timeline = [];
    $navTypes = [];
    $screen = ['vw' => 0, 'vh' => 0, 'dpr' => 0];
    $hasFbp = false;
    $hasFbc = false;
    $connType = '';
    $scheme = '';
    $touch = null;
    $refHost = '';
    $refQ = '';
    $productFromEvents = '';

    // Engage: counters reset per page → sum peaks per path segment.
    $engageByPath = [];
    $idleMax = 0;
    $visibleMax = 0;

    $tFormStart = null;
    $tFirstScroll = null;
    $tFirstCta = null;
    $tFirstError = null;

    foreach ($events as $ev) {
        $type = (string) ($ev['event_type'] ?? '');
        $m = hd_native_decode_event_meta($ev['meta_json'] ?? null);
        $ts = (string) ($ev['created_at'] ?? '');
        $tsInt = $ts !== '' ? strtotime($ts) : false;
        if ($ts !== '' && $firstAt === null) {
            $firstAt = $ts;
        }
        if ($ts !== '') {
            $lastAt = $ts;
        }

        $url = (string) ($ev['url'] ?? ($m['url'] ?? ''));
        $path = '';
        if ($url !== '') {
            $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?: $url);
            if ($path !== '') {
                if (!isset($paths[$path])) {
                    $paths[$path] = true;
                    $pathOrder[] = $path;
                }
            }
        }

        $relSec = ($firstAt && $tsInt) ? max(0, $tsInt - (int) strtotime($firstAt)) : 0;

        if ($type === 'page_view') {
            $pageViews++;
            $timeline[] = ['t' => $relSec, 'k' => 'view', 'path' => $path];
        } elseif ($type === 'scroll') {
            $pct = (int) ($m['max_pct'] ?? 0);
            $maxScroll = max($maxScroll, $pct);
            $scrollMarks[$pct] = true;
            if ($tFirstScroll === null) {
                $tFirstScroll = $relSec;
            }
            $timeline[] = ['t' => $relSec, 'k' => 'scroll', 'pct' => $pct];
        } elseif ($type === 'engage') {
            $a = (int) ($m['active_ms'] ?? 0);
            $v = (int) ($m['visible_ms'] ?? 0);
            $i = (int) ($m['idle_ms'] ?? 0);
            $pkey = $path !== '' ? $path : '_';
            if (!isset($engageByPath[$pkey])) {
                $engageByPath[$pkey] = ['active' => 0, 'visible' => 0, 'idle' => 0];
            }
            $engageByPath[$pkey]['active'] = max($engageByPath[$pkey]['active'], $a);
            $engageByPath[$pkey]['visible'] = max($engageByPath[$pkey]['visible'], $v);
            $engageByPath[$pkey]['idle'] = max($engageByPath[$pkey]['idle'], $i);
            $idleMax = max($idleMax, $i);
            $visibleMax = max($visibleMax, $v);
        } elseif ($type === 'cta_click') {
            $ctaClicks++;
            if ($tFirstCta === null) {
                $tFirstCta = $relSec;
            }
            $timeline[] = ['t' => $relSec, 'k' => 'cta', 'id' => (string) ($m['cta_id'] ?? '')];
        } elseif ($type === 'form_start') {
            $formStarts++;
            if ($tFormStart === null) {
                $tFormStart = $relSec;
            }
            $timeline[] = ['t' => $relSec, 'k' => 'form_start'];
        } elseif ($type === 'form_error') {
            $formErrors++;
            $err = trim((string) ($m['err'] ?? ''));
            if ($err !== '' && count($formErrorMsgs) < 3) {
                $formErrorMsgs[] = $err;
            }
            if ($tFirstError === null) {
                $tFirstError = $relSec;
            }
            $timeline[] = ['t' => $relSec, 'k' => 'form_error', 'err' => $err];
        } elseif ($type === 'context') {
            if ($contextMeta === []) {
                $contextMeta = $m;
            } else {
                $contextMeta = array_merge($contextMeta, $m);
            }
            if (!empty($m['nav_type'])) {
                $navTypes[(string) $m['nav_type']] = true;
            }
            if (!empty($m['vw'])) {
                $screen['vw'] = (int) $m['vw'];
            }
            if (!empty($m['vh'])) {
                $screen['vh'] = (int) $m['vh'];
            }
            if (!empty($m['dpr'])) {
                $screen['dpr'] = (float) $m['dpr'];
            }
            if ($connType === '' && !empty($m['net'])) {
                $connType = substr((string) $m['net'], 0, 32);
            }
            if ($scheme === '' && !empty($m['scheme'])) {
                $scheme = substr((string) $m['scheme'], 0, 16);
            }
            if ($touch === null && array_key_exists('touch', $m)) {
                $touch = (int) $m['touch'] ? 1 : 0;
            }
            if ($refHost === '' && !empty($m['ref_host'])) {
                $refHost = substr((string) $m['ref_host'], 0, 120);
            }
            if ($refQ === '' && !empty($m['ref_q'])) {
                $refQ = substr((string) $m['ref_q'], 0, 80);
            }
        } elseif ($type === 'attr_update') {
            if (!empty($m['fbp'])) {
                $hasFbp = true;
            }
            if (!empty($m['fbc'])) {
                $hasFbc = true;
            }
        } elseif ($type === 'lead') {
            $leadAt = $ts;
            if ($productFromEvents === '') {
                $productFromEvents = trim((string) ($m['product'] ?? ($m['product_name'] ?? '')));
            }
            $timeline[] = ['t' => $relSec, 'k' => 'lead', 'product' => $productFromEvents];
        }
    }

    $activeSum = 0;
    $visibleSum = 0;
    $idleSum = 0;
    foreach ($engageByPath as $row) {
        $activeSum += (int) $row['active'];
        $visibleSum += (int) $row['visible'];
        $idleSum += (int) $row['idle'];
    }

    // End of window: matching lead if regenerating; else last event (live conversion).
    $endAt = null;
    if ($leadId > 0 && $leadAt) {
        $endAt = $leadAt;
    } elseif ($lastAt) {
        $endAt = $lastAt;
    } elseif ($leadAt) {
        $endAt = $leadAt;
    }

    $dwellSec = 0;
    if ($firstAt && $endAt) {
        $a = strtotime($firstAt);
        $b = strtotime($endAt);
        if ($a && $b && $b >= $a) {
            $dwellSec = (int) ($b - $a);
        }
    }

    $timeToForm = $tFormStart;
    $timeToLead = null;
    if ($firstAt && ($leadAt || $endAt)) {
        $a = strtotime($firstAt);
        $b = strtotime($leadAt ?: $endAt);
        if ($a && $b && $b >= $a) {
            $timeToLead = (int) ($b - $a);
        }
    }

    // Hesitation: form open → submit gap, or high idle ratio.
    $formFillSec = null;
    if ($tFormStart !== null && $timeToLead !== null && $timeToLead >= $tFormStart) {
        $formFillSec = (int) ($timeToLead - $tFormStart);
    }

    $backForward = isset($navTypes['back_forward']);
    $reload = isset($navTypes['reload']);

    return [
        'max_scroll' => $maxScroll,
        'scroll_marks' => array_keys($scrollMarks),
        'active_ms' => $activeSum > 0 ? $activeSum : 0,
        'visible_ms' => $visibleSum > 0 ? $visibleSum : $visibleMax,
        'idle_ms' => $idleSum > 0 ? $idleSum : $idleMax,
        'idle_max_ms' => $idleMax,
        'page_views' => $pageViews,
        'cta_clicks' => $ctaClicks,
        'form_starts' => $formStarts,
        'form_errors' => $formErrors,
        'form_error_msgs' => $formErrorMsgs,
        'context' => $contextMeta,
        'dwell_sec' => $dwellSec,
        'unique_paths' => count($paths),
        'path_order' => $pathOrder,
        'event_count' => count($events),
        'time_to_form_sec' => $timeToForm,
        'time_to_lead_sec' => $timeToLead,
        'time_to_scroll_sec' => $tFirstScroll,
        'time_to_cta_sec' => $tFirstCta,
        'form_fill_sec' => $formFillSec,
        'back_forward' => $backForward,
        'reload' => $reload,
        'nav_types' => array_keys($navTypes),
        'screen' => $screen,
        'has_fbp' => $hasFbp,
        'has_fbc' => $hasFbc,
        'timeline' => $timeline,
        'engage_paths' => count($engageByPath),
        'net' => $connType,
        'scheme' => $scheme,
        'touch' => $touch,
        'ref_host' => $refHost,
        'ref_q' => $refQ,
        'product' => $productFromEvents,
    ];
}

function hd_native_fmt_duration(int $sec): string
{
    if ($sec < 0) {
        $sec = 0;
    }
    if ($sec < 8) {
        return 'quelques secondes';
    }
    if ($sec < 60) {
        return $sec . ' s';
    }
    $m = (int) floor($sec / 60);
    $r = $sec % 60;
    if ($m < 15) {
        return $r > 0 ? "{$m} min {$r} s" : "{$m} min";
    }
    return $m . ' min';
}

function hd_native_fmt_active_ms(int $ms): string
{
    if ($ms <= 0) {
        return '';
    }
    return hd_native_fmt_duration((int) round($ms / 1000));
}

/**
 * Heat score → priority label + tone + why.
 *
 * @param array<string,mixed> $sig
 * @return array{level:string,label:string,score:int,why:string}
 */
function hd_native_heat_from_signals(array $sig): array
{
    $score = 0;
    $whyBits = [];
    $activeSec = (int) round(((int) ($sig['active_ms'] ?? 0)) / 1000);
    $dwell = (int) ($sig['dwell_sec'] ?? 0);
    $scroll = (int) ($sig['max_scroll'] ?? 0);
    $cta = (int) ($sig['cta_clicks'] ?? 0);
    $fs = (int) ($sig['form_starts'] ?? 0);
    $fe = (int) ($sig['form_errors'] ?? 0);
    $pv = (int) ($sig['page_views'] ?? 0);
    $paths = (int) ($sig['unique_paths'] ?? 0);
    $idleSec = (int) round(((int) ($sig['idle_ms'] ?? 0)) / 1000);
    $fill = $sig['form_fill_sec'];

    if ($activeSec >= 45 || $dwell >= 90) {
        $score += 3;
        $whyBits[] = 'long engagement';
    } elseif ($activeSec >= 20 || $dwell >= 40) {
        $score += 2;
        $whyBits[] = 'bon temps sur page';
    } elseif ($activeSec >= 8 || $dwell >= 15) {
        $score += 1;
    }
    if ($scroll >= 75) {
        $score += 2;
        $whyBits[] = 'scroll profond';
    } elseif ($scroll >= 40) {
        $score += 1;
        $whyBits[] = 'a parcouru une bonne partie';
    }
    if ($cta >= 3) {
        $score += 2;
        $whyBits[] = 'a martelé le CTA';
    } elseif ($cta >= 2) {
        $score += 2;
        $whyBits[] = 'plusieurs clics CTA';
    } elseif ($cta >= 1) {
        $score += 1;
    }
    if ($fs >= 1) {
        $score += 1;
        $whyBits[] = 'a touché le formulaire';
    }
    if ($fe >= 1) {
        $score += 2; // friction then convert = strong intent
        $whyBits[] = 'friction saisie puis conversion';
    }
    if ($paths >= 2 || $pv >= 3) {
        $score += 1;
        $whyBits[] = 'parcours multi-pages';
    }
    if ($fill !== null && (int) $fill >= 90) {
        $score += 1;
        $whyBits[] = 'a pris son temps sur le form';
    }
    // High idle without other signals → slightly colder
    if ($idleSec >= 120 && $activeSec < 25 && $cta <= 1 && $fe < 1) {
        $score = max(0, $score - 1);
    }

    $why = $whyBits !== [] ? implode(', ', array_slice($whyBits, 0, 3)) : 'signaux limités';

    if ($score >= 7) {
        return ['level' => 'hot', 'label' => 'CHAUD', 'score' => $score, 'why' => $why];
    }
    if ($score >= 4) {
        return ['level' => 'warm', 'label' => 'TIÈDE', 'score' => $score, 'why' => $why];
    }
    // Ne pas forcer FROID si le temps sur page (dwell) est déjà significatif
    // (ex. JS engage pas encore landé au moment de la conversion).
    if ((int) ($sig['event_count'] ?? 0) <= 2 && $activeSec < 5 && $scroll < 10 && $dwell < 20) {
        return ['level' => 'cold', 'label' => 'FROID', 'score' => $score, 'why' => 'parcours très court / peu de data'];
    }
    if ($score >= 2 || $dwell >= 20 || $activeSec >= 8) {
        return ['level' => 'warm', 'label' => 'TIÈDE', 'score' => max($score, 2), 'why' => ($why !== 'signaux limités' ? $why : 'temps sur page avant envoi')];
    }
    if ($score <= 2) {
        return ['level' => 'cold', 'label' => 'FROID', 'score' => $score, 'why' => $why];
    }
    return ['level' => 'warm', 'label' => 'TIÈDE', 'score' => $score, 'why' => $why];
}

/**
 * Behavioral pattern for template branching.
 *
 * @param array<string,mixed> $sig
 * @return string
 */
function hd_native_behavior_pattern(array $sig): string
{
    $dwell = (int) ($sig['dwell_sec'] ?? 0);
    $scroll = (int) ($sig['max_scroll'] ?? 0);
    $cta = (int) ($sig['cta_clicks'] ?? 0);
    $fe = (int) ($sig['form_errors'] ?? 0);
    $fs = (int) ($sig['form_starts'] ?? 0);
    $paths = (int) ($sig['unique_paths'] ?? 0);
    $pv = (int) ($sig['page_views'] ?? 0);
    $activeSec = (int) round(((int) ($sig['active_ms'] ?? 0)) / 1000);
    $idleSec = (int) round(((int) ($sig['idle_ms'] ?? 0)) / 1000);
    $fill = $sig['form_fill_sec'];
    $ttf = $sig['time_to_form_sec'];
    $back = !empty($sig['back_forward']);

    if ($fe >= 1 && ($fill === null || (int) $fill >= 30)) {
        return 'form_friction';
    }
    if ($paths >= 2 || ($pv >= 3 && $back)) {
        return 'multipage';
    }
    if ($cta >= 3) {
        return 'cta_hammer';
    }
    if ($scroll >= 75 && $dwell < 45) {
        return 'fast_deep_scroll';
    }
    if ($dwell >= 90 && $scroll > 0 && $scroll < 40) {
        return 'long_dwell_shallow';
    }
    if ($idleSec >= 90 && $activeSec > 0 && $idleSec > $activeSec * 1.5) {
        return 'hesitant_idle';
    }
    if ($ttf !== null && (int) $ttf <= 20 && $dwell > 0 && $dwell < 50 && $fs >= 1) {
        return 'impulse_fast';
    }
    if ($fill !== null && (int) $fill >= 120) {
        return 'slow_form';
    }
    if ($scroll >= 50 && $activeSec >= 30) {
        return 'engaged_reader';
    }
    if ($dwell < 25 && $cta <= 1 && $scroll < 30) {
        return 'quick_thin';
    }
    return 'standard';
}

/**
 * Narrative journey in FR prose (ordered story, not stats dump).
 *
 * @param array<string,mixed> $sig
 */
function hd_native_narrative_journey(array $sig, string $pattern): string
{
    $bits = [];
    $dwell = (int) ($sig['dwell_sec'] ?? 0);
    $scroll = (int) ($sig['max_scroll'] ?? 0);
    $cta = (int) ($sig['cta_clicks'] ?? 0);
    $fs = (int) ($sig['form_starts'] ?? 0);
    $fe = (int) ($sig['form_errors'] ?? 0);
    $ttf = $sig['time_to_form_sec'];
    $tts = $sig['time_to_scroll_sec'];
    $fill = $sig['form_fill_sec'];
    $paths = $sig['path_order'] ?? [];
    $idleSec = (int) round(((int) ($sig['idle_ms'] ?? 0)) / 1000);
    $activeFmt = hd_native_fmt_active_ms((int) ($sig['active_ms'] ?? 0));
    $errs = $sig['form_error_msgs'] ?? [];

    $bits[] = 'Arrivé sur la landing';

    if ($tts !== null && (int) $tts <= 15 && $scroll >= 50) {
        $bits[] = 'a scrollé vite (jusqu’à ' . $scroll . ' %) — lit en diagonale / cherche preuves';
    } elseif ($tts !== null && $scroll >= 75) {
        $bits[] = 'est descendu profondément (scroll ' . $scroll . ' %)';
    } elseif ($scroll >= 25 && $scroll < 50) {
        $bits[] = 'a scrollé modérément (~' . $scroll . ' %, zone milieu de page)';
    } elseif ($scroll > 0 && $scroll < 25) {
        $bits[] = 'est resté surtout en haut de page (scroll ~' . $scroll . ' %)';
    } elseif ($scroll === 0 && $dwell >= 20) {
        $bits[] = 'peu ou pas de scroll tracké — a pu lire le above-the-fold ou le form déjà visible';
    }

    if ($pattern === 'multipage' && is_array($paths) && count($paths) >= 2) {
        $roles = [];
        foreach (array_slice($paths, 0, 4) as $p) {
            $roles[] = hd_native_path_role((string) $p);
        }
        $bits[] = 'a navigué : ' . implode(' → ', $roles);
        if (!empty($sig['back_forward'])) {
            $bits[] = 'retour navigateur détecté (est revenu sur la landing)';
        }
    }

    if ($ttf !== null) {
        if ((int) $ttf <= 15) {
            $bits[] = 'a mis le focus sur le formulaire très tôt (~' . hd_native_fmt_duration((int) $ttf) . ')';
        } elseif ((int) $ttf >= 60) {
            $bits[] = 'a d’abord consulté avant d’ouvrir le form (~' . hd_native_fmt_duration((int) $ttf) . ')';
        } else {
            $bits[] = 'a commencé le formulaire après ~' . hd_native_fmt_duration((int) $ttf);
        }
    } elseif ($fs >= 1) {
        $bits[] = 'a ouvert / touché le formulaire';
    }

    if ($fe >= 1) {
        $msg = $errs[0] ?? '';
        if ($msg !== '' && stripos($msg, 'renseigner') !== false) {
            $bits[] = 'blocage saisie (champ obligatoire) — a corrigé puis validé';
        } elseif ($msg !== '') {
            $bits[] = 'erreur form (« ' . mb_substr($msg, 0, 60) . ' ») avant succès';
        } else {
            $bits[] = 'a buté sur une erreur de formulaire puis a finalisé';
        }
    }

    if ($fill !== null && (int) $fill >= 60) {
        $bits[] = 'hésitation sur le form (~' . hd_native_fmt_duration((int) $fill) . ' entre ouverture et envoi)';
    } elseif ($fill !== null && (int) $fill <= 20 && $fs >= 1) {
        $bits[] = 'remplissage rapide avant envoi';
    }

    if ($cta >= 3) {
        $bits[] = 'a cliqué ' . $cta . '× sur le CTA (insistance / double-tap / doute technique)';
    } elseif ($cta === 2) {
        $bits[] = '2 clics CTA avant validation';
    } elseif ($cta === 1) {
        $bits[] = '1 clic CTA pour envoyer';
    }

    if ($idleSec >= 60 && $pattern === 'hesitant_idle') {
        $bits[] = 'beaucoup d’inactivité (~' . hd_native_fmt_duration($idleSec) . ') — a pu quitter l’écran / réfléchir / lire ailleurs';
    }

    $intensity = [];
    if ($dwell > 0) {
        $intensity[] = 'durée totale ~' . hd_native_fmt_duration($dwell);
    }
    if ($activeFmt !== '') {
        $intensity[] = 'actif ~' . $activeFmt;
    }
    if ($idleSec >= 20) {
        $intensity[] = 'idle ~' . hd_native_fmt_duration($idleSec);
    }
    if ((int) ($sig['page_views'] ?? 0) > 1) {
        $intensity[] = (int) $sig['page_views'] . ' vues';
    }
    if ($intensity) {
        $bits[] = 'intensité : ' . implode(', ', $intensity);
    }

    return implode(' ; ', $bits) . '.';
}

/**
 * Personalized call angle + optional micro-tip (varied by pattern × heat).
 *
 * @param array<string,mixed> $sig
 * @param array{level:string,label:string} $heat
 * @return array{0:string,1:string}
 */
function hd_native_call_angle_and_tip(array $sig, array $heat, string $pattern, array $device): array
{
    $level = $heat['level'];
    $seed = abs(crc32(
        $pattern . '|' . $level . '|' . ((int) ($sig['cta_clicks'] ?? 0)) . '|'
        . ((int) ($sig['max_scroll'] ?? 0)) . '|' . ((int) ($sig['dwell_sec'] ?? 0)) . '|'
        . ($device['model'] ?? '')
    ));

    $angles = [
        'form_friction' => [
            'hot' => [
                'Il a galéré sur le formulaire puis a insisté : besoin réel. Ouvre sur « vous aviez un souci pour envoyer la demande ? » puis enchaîne pack + délai de livraison.',
                'Friction saisie = intention. Appelle chaud : confirme le numéro, reformule le besoin en 1 phrase, close sur paiement à la réception.',
            ],
            'warm' => [
                'A buté sur un champ puis a finalisé — un peu stressé / pressé. Soft : « je vous aide à finaliser », clarifie quantité / zone de livraison.',
                'Erreur de saisie avant envoi : rassure d’abord (pas un robot), puis pose 2 questions max (symptôme + urgence).',
            ],
            'cold' => [
                'Peu de signaux hors friction form — vérifie que le lead est joignable, demande ce qui l’a fait cliquer, sans pitch long.',
            ],
        ],
        'multipage' => [
            'hot' => [
                'Curieux multi-pages puis conversion : compare / rassure-toi. Demande ce qu’il a vu sur le site et ce qui l’a convaincu — tu closes plus facile.',
                'Est allé voir ailleurs puis est revenu : bon signe. Ancre sur la preuve qu’il a cherchée (avis / composition) et propose le pack.',
            ],
            'warm' => [
                'Parcours explorateur : il veut comprendre avant d’acheter. Écoute 30 s, réponds à SA question, puis offre.',
                'Multi-pages = pas impulsif. Qualifie le besoin, cite 1 preuve sociale, propose sans forcer le double pack tout de suite.',
            ],
            'cold' => [
                'A surfé un peu mais engagement faible — sonde curiosité vs besoin urgent, propose un rappel si pas dispo.',
            ],
        ],
        'cta_hammer' => [
            'hot' => [
                'Plusieurs clics CTA : impatient ou bug ressenti. Rappelle sous 5–10 min, ton énergique, « je finalise votre commande ».',
                'Il a martelé le bouton — motivation haute. Va droit au but : dispo, délai, paiement à la réception.',
            ],
            'warm' => [
                'Double/triple clic CTA : soit hâte, soit doute que ça a marché. Confirme la réception de la demande en premier message.',
            ],
            'cold' => [
                'Clics CTA sans gros engagement : confirme l’intérêt réel en 2 questions courtes.',
            ],
        ],
        'fast_deep_scroll' => [
            'hot' => [
                'Scroll profond + conversion rapide : a scavé les preuves puis a décidé. Appelle vite, valide l’objection prix/délai.',
                'A balayé la page en diagonale jusqu’en bas — cherche crédibilité. Ouvre avec rassurance livraison TN + résultats.',
            ],
            'warm' => [
                'Lecture rapide en profondeur : pose « qu’est-ce qui vous a le plus parlé sur la page ? » pour customiser le pitch.',
            ],
            'cold' => [
                'Scroll profond mais peu d’autres signaux — peut être curieux. Qualifie avant de pitcher le pack.',
            ],
        ],
        'long_dwell_shallow' => [
            'hot' => [
                'Long séjour surtout en haut de page : a relu l’offre / le form. Close en clarifiant le dessus-de-pli (bénéfice + garantie).',
            ],
            'warm' => [
                'Reste longtemps sans beaucoup scroller — hésite sur le premier écran. Découpe : besoin → preuve → offre simple.',
                'Temps long + scroll faible : possible distraction. Reprend depuis zéro avec une question ouverte sur le symptôme.',
            ],
            'cold' => [
                'Page ouverte longtemps sans profondeur — peut avoir laissé l’onglet. Vérifie disponibilité avant pitch.',
            ],
        ],
        'hesitant_idle' => [
            'hot' => [
                'Gros idle puis conversion : a réfléchi / demandé à quelqu’un. Ton coach rassurant, laisse parler, puis close doux.',
            ],
            'warm' => [
                'Beaucoup d’inactivité avant l’envoi — pas impulsif. Évite le rush commercial ; pose le cadre confiance.',
                'Idle élevé : il a pu comparer ailleurs. Demande s’il a déjà testé un produit similaire (sans inventer).',
            ],
            'cold' => [
                'Profil hésitant / peu actif — rappel court, propose un créneau, ne force pas le closing au 1er appel.',
            ],
        ],
        'impulse_fast' => [
            'hot' => [
                'Décision rapide (form tôt + envoi). Rappelle ASAP (5–10 min), énergie positive, sécurise le stock / la tournée.',
                'Lead impulsif engagé : confirme la commande mentale (« on vous livre… »), détails adresse ensuite.',
            ],
            'warm' => [
                'Parcours court et décidé — ne le ralentis pas avec un interrogatoire. 1 question besoin + proposition claire.',
            ],
            'cold' => [
                'Conversion rapide mais data légère : confirme que c’est bien lui / le bon numéro avant d’argumenter.',
            ],
        ],
        'slow_form' => [
            'hot' => [
                'Longtemps sur le formulaire : doute sur téléphone / engagement. Rassure confidentialité + paiement à la réception.',
            ],
            'warm' => [
                'A mis du temps à remplir — aide-le à verbaliser l’hésitation (prix, efficacité, livraison).',
                'Hésitation form : commence par « je vois que vous avez pris le temps — je peux vous guider ».',
            ],
            'cold' => [
                'Remplissage lent + peu d’autres signaux : qualifie sans pression, OK pour un 2e rappel.',
            ],
        ],
        'engaged_reader' => [
            'hot' => [
                'Bon lecteur engagé : il a digéré le message. Tu peux être précis (protocole, durée, résultats attendus) puis close.',
            ],
            'warm' => [
                'Lecture + engagement solides. Mirror son niveau : pas trop vendeur forain, plus conseiller.',
            ],
            'cold' => [
                'A lu un peu — sonde ce qui reste flou avant de pousser l’offre.',
            ],
        ],
        'quick_thin' => [
            'hot' => [
                'Conversion courte mais score haut sur d’autres signaux — traite en priorité, confirme le besoin en 20 s.',
            ],
            'warm' => [
                'Parcours court : pas la peine d’un roman. Qualification express + proposition simple.',
            ],
            'cold' => [
                'Peu de data avant envoi — sois honnête : qualification courte, vérifie intérêt réel / bon contact.',
            ],
        ],
        'standard' => [
            'hot' => [
                'Lead engagé classique : rappel rapide, validation besoin, pack, rassurance livraison / paiement à réception.',
                'Bon potentiel : structure ton appel besoin → preuve → offre → close.',
            ],
            'warm' => [
                'Profil standard tiède : soft open sur le symptôme / objectif, 1–2 objections, puis offre si ça tient.',
                'Démarrage coach : écoute, reformule, propose le prochain pas concret (commande / rappel).',
            ],
            'cold' => [
                'Parcours léger — qualifier sans forcer, vérifier joignabilité, sonder urgence vs curiosité.',
            ],
        ],
    ];

    $bucket = $angles[$pattern] ?? $angles['standard'];
    $pool = $bucket[$level] ?? $bucket['warm'] ?? $angles['standard']['warm'];
    $angle = $pool[$seed % count($pool)];

    $tips = [];
    if ($level === 'hot') {
        $tips[] = '⏱ Rappeler sous 5–10 min si possible.';
        $tips[] = '📦 Sécurise dispo + délai avant qu’il refroidisse.';
        $tips[] = '💳 Mentionne paiement à la réception tôt.';
    } elseif ($level === 'warm') {
        $tips[] = '🎯 2 questions max avant le pitch.';
        $tips[] = '🔁 Si non joignable : SMS court + rappel J+0 soir.';
        $tips[] = '🧪 Demande s’il a déjà testé un soin / produit proche.';
    } else {
        $tips[] = '📞 Vérifier le numéro / créneau avant argumentaire long.';
        $tips[] = '🧊 Ton bas : curiosité d’abord, offre ensuite.';
        $tips[] = '📝 Note le motif réel du clic pour le prochain call.';
    }
    if ($pattern === 'form_friction') {
        $tips[] = '✍️ Il a pu se tromper de chiffre : confirme le téléphone dès l’ouverture.';
    }
    if ($pattern === 'multipage') {
        $tips[] = '🔍 Demande ce qu’il cherchait en naviguant (avis, prix, composition).';
    }
    if ($pattern === 'hesitant_idle' || $pattern === 'slow_form') {
        $tips[] = '🕊️ Laisse une pause ; les hésitants closent mieux sans rush.';
    }
    if (!empty($device['from_fb_app'])) {
        $tips[] = '📱 Trafic in-app FB : souvent encore sur le feed — appelle tant que le contexte pub est chaud.';
    }

    $tip = $tips[$seed % count($tips)];
    return [$angle, $tip];
}

/**
 * Traffic quality line (FB app / organic-ish / trust).
 *
 * @param array<string,mixed> $sig
 * @param array<string,mixed> $device
 */
function hd_native_traffic_quality_line(
    ?array $click,
    array $sig,
    array $device,
    string $utmSource,
    string $utmMedium,
    bool $hasFb
): string {
    $bits = [];
    if ($hasFb || strtolower($utmSource) === 'fb' || strtolower($utmSource) === 'facebook') {
        $bits[] = '📢 Facebook Ads';
        if ($device['from_fb_app']) {
            $bits[] = '📲 in-app FB';
        } elseif ($device['from_ig']) {
            $bits[] = '📸 in-app Instagram';
        } else {
            $bits[] = $device['is_mobile'] ? '📱 mobile web' : '💻 desktop';
        }
    } elseif ($utmSource !== '') {
        $bits[] = '🏷️ source ' . $utmSource;
    } else {
        $bits[] = '🏷️ source non taguée';
    }
    // utm_medium (ex. referal) omis — bruit pour le vendeur.

    $extra = [];
    if ($device['is_botish']) {
        $extra[] = '⚠️ UA suspect bot — vérifier humain';
    }
    // Referrer host / q volontairement omis (bruit pour le vendeur).

    $line = '🚦 Trafic : ' . implode(' · ', $bits);
    if ($extra) {
        $line .= ' — ' . implode(' · ', $extra);
    }
    return $line . '.';
}

/**
 * Build seller-facing FR tracking comment at conversion (frozen).
 *
 * @param array<string,mixed> $ctx
 * @param array<string,mixed> $meta
 */
function hd_native_build_tracking_comment(string $tbclid, array $ctx = [], array $meta = []): string
{
    $snap = hd_native_tracker_snapshot($tbclid);
    $click = $snap['click'];
    $events = $snap['events'];
    $leadId = (int) ($meta['lead_id'] ?? ($meta['_lead_id'] ?? 0));
    $sig = hd_native_aggregate_tracker_signals($events, $leadId);
    // Si JS engage n'a pas landé : estimer le temps via created_at du click → maintenant.
    if (is_array($click) && !empty($click['created_at'])) {
        $createdTs = strtotime((string) $click['created_at']);
        $nowTs = strtotime(current_time('mysql'));
        if ($createdTs && $nowTs && $nowTs >= $createdTs) {
            $clickDwell = (int) ($nowTs - $createdTs);
            if ($clickDwell > (int) ($sig['dwell_sec'] ?? 0)) {
                $sig['dwell_sec'] = $clickDwell;
            }
        }
    }
    $contextMeta = is_array($sig['context'] ?? null) ? $sig['context'] : [];

    $utmSource = trim((string) ($meta['utm_source'] ?? ($click['utm_source'] ?? '')));
    $utmMedium = trim((string) ($meta['utm_medium'] ?? ($click['utm_medium'] ?? '')));
    $hasFb = trim((string) ($meta['fbclid'] ?? ($click['fbclid'] ?? ''))) !== '';
    $device = hd_native_device_profile($click, $contextMeta);
    $geo = hd_native_geo_label($click, $contextMeta, $meta);
    $heat = hd_native_heat_from_signals($sig);
    $pattern = hd_native_behavior_pattern($sig);

    // « Thin » seulement si vraiment aucune donnée utile (pas de click + dwell court).
    // Avec page_view serveur + dwell click→conversion, on construit un commentaire normal
    // même si les beacons JS (engage/scroll) n’ont pas landé.
    $thin = ((int) ($sig['event_count'] ?? 0) < 2)
        && ((int) ($sig['active_ms'] ?? 0) < 3000)
        && ((int) ($sig['max_scroll'] ?? 0) < 10)
        && ((int) ($sig['dwell_sec'] ?? 0) < 20);

    if ($thin && !$click) {
        return "🧊 FROID — parcours tracker limité (peu de signaux avant conversion).\n"
            . ($hasFb ? "🚦 Trafic : 📢 Facebook (fbclid) mais sans engagement tracké.\n" : '')
            . "🎯 Angle : confirmer le besoin rapidement, ton rassurant, pas trop commercial au 1er échange.\n"
            . '💡 Tip : vérifier le numéro / disponibilité avant un pitch long.';
    }

    $lines = [];

    // 1) Verdict (produit / referrer volontairement omis — déjà ailleurs ou bruit)
    $emoji = $heat['level'] === 'hot' ? '🔥' : ($heat['level'] === 'warm' ? '🌤️' : '🧊');
    $lines[] = "{$emoji} {$heat['label']} — {$heat['why']}.";

    // 2) Traffic quality (sans referrer host)
    $lines[] = hd_native_traffic_quality_line($click, $sig, $device, $utmSource, $utmMedium, $hasFb);

    // 3) Narrative journey
    $lines[] = '🧭 Parcours : ' . hd_native_narrative_journey($sig, $pattern);

    // 4) Friction / intent
    $frictionBits = [];
    if ((int) ($sig['form_errors'] ?? 0) >= 1) {
        $frictionBits[] = '⚠️ friction formulaire surmontée (= intention)';
    }
    if ((int) ($sig['form_starts'] ?? 0) >= 2) {
        $frictionBits[] = '🔁 plusieurs ouvertures / reprises form';
    }
    if (!empty($sig['back_forward'])) {
        $frictionBits[] = '↩️ retour page (back/forward)';
    }
    if ($frictionBits) {
        $lines[] = '💥 Friction / intention : ' . implode(' · ', $frictionBits) . '.';
    }

    // 5) Human profile — sans codes device / résolution écran
    $ctxBits = [];
    if ($device['label'] !== '') {
        $ctxBits[] = $device['label'];
    }
    if ($geo !== '') {
        $ctxBits[] = $geo;
    }
    if (!empty($sig['touch'])) {
        $ctxBits[] = '👆 tactile';
    }
    if (!empty($sig['net'])) {
        $ctxBits[] = '📶 ' . $sig['net'];
    }
    if (!empty($sig['scheme'])) {
        $ctxBits[] = ($sig['scheme'] === 'dark' ? '🌙' : '☀️') . ' ' . $sig['scheme'];
    }
    if ($ctxBits) {
        $lines[] = '👤 Profil : ' . implode(' · ', $ctxBits) . '.';
    }

    // 6–7) Angle + tip
    [$angle, $tip] = hd_native_call_angle_and_tip($sig, $heat, $pattern, $device);
    $lines[] = '🎯 Angle d’appel : ' . $angle;
    $lines[] = '💡 Tip vendeur : ' . $tip;

    $text = implode("\n", $lines);
    if (function_exists('mb_strlen') && mb_strlen($text) > 2200) {
        $text = mb_substr($text, 0, 2197) . '…';
    } elseif (strlen($text) > 2200) {
        $text = substr($text, 0, 2197) . '…';
    }
    return $text;
}

/**
 * Resolve landing page URL for Espo cLandingpage ONLY.
 *
 * Keeps page_id / path; strips utm_*, fbclid, tbclid, etc. from THIS URL.
 * Does NOT touch other Espo fields (cMarketingsource, cFbclid, cWtid, …).
 *
 * Espo field cLandingpage is varchar(100) — keep under that limit.
 *
 * @param array<string,mixed> $ctx
 * @param array<string,mixed> $meta
 * @param array<string,mixed>|null $click
 */
function hd_native_resolve_landing_url(array $ctx, array $meta, ?array $click): string
{
    $candidates = [];
    if (!empty($click['landing_url'])) {
        $candidates[] = (string) $click['landing_url'];
    }
    if (!empty($click['first_url'])) {
        $candidates[] = (string) $click['first_url'];
    }
    $ref = (string) ($meta['wp_http_referer'] ?? ($ctx['wp_http_referer'] ?? ''));
    if ($ref !== '') {
        $candidates[] = preg_match('#^https?://#i', $ref) ? $ref : home_url($ref);
    }
    if (!empty($ctx['current_url'])) {
        $candidates[] = (string) $ctx['current_url'];
    }
    $payload = isset($meta['payload']) && is_array($meta['payload']) ? $meta['payload'] : [];
    if (!empty($payload['current_url'])) {
        $candidates[] = (string) $payload['current_url'];
    }

    // Prefer page_id extracted from LP URLs / referer (not form embed page_id).
    $pageId = 0;
    foreach ($candidates as $u) {
        if (preg_match('/[?&]page_id=(\d+)/', (string) $u, $m)) {
            $pageId = (int) $m[1];
            break;
        }
    }
    if ($pageId <= 0) {
        // Last resort: explicit page_id only if it looks like an LP id from URL candidates failed.
        $pageId = (int) ($meta['page_id'] ?? ($ctx['page_id'] ?? ($payload['page_id'] ?? 0)));
    }
    if ($pageId > 0) {
        $candidates[] = home_url('/?page_id=' . $pageId);
    }

    $strip = static function (string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (function_exists('hd_native_strip_tracking_url')) {
            $clean = hd_native_strip_tracking_url($url);
            return $clean !== '' ? $clean : $url;
        }
        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }
        $path = $parts['path'] ?? '/';
        $qKeep = [];
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $q);
            foreach (['page_id', 'p', 'pagename'] as $k) {
                if (!empty($q[$k])) {
                    $qKeep[$k] = $q[$k];
                }
            }
        }
        $out = $parts['scheme'] . '://' . $parts['host'] . $path;
        if ($qKeep) {
            $out .= '?' . http_build_query($qKeep);
        }
        return $out;
    };

    $cleaned = [];
    foreach ($candidates as $u) {
        $c = $strip((string) $u);
        if ($c !== '') {
            $cleaned[] = $c;
        }
    }

    // Prefer a cleaned URL that still identifies the LP (page_id or non-root path).
    $raw = '';
    foreach ($cleaned as $u) {
        if (preg_match('/[?&]page_id=\d+/', $u)) {
            $raw = $u;
            break;
        }
    }
    if ($raw === '') {
        foreach ($cleaned as $u) {
            $path = (string) (wp_parse_url($u, PHP_URL_PATH) ?: '/');
            if ($path !== '/' && $path !== '') {
                $raw = $u;
                break;
            }
        }
    }
    if ($raw === '' && $cleaned) {
        $raw = $cleaned[0];
    }
    if ($raw === '') {
        $raw = home_url('/');
    }

    $raw = esc_url_raw($raw);
    // Espo cLandingpage maxLength=100
    if (function_exists('mb_strlen') && mb_strlen($raw) > 100) {
        $raw = mb_substr($raw, 0, 100);
    } elseif (strlen($raw) > 100) {
        $raw = substr($raw, 0, 100);
    }
    return $raw !== '' ? $raw : home_url('/');
}

/**
 * cFbpostid: utm_content Meta (fallback utm_term).
 *
 * @param array<string,mixed> $meta
 * @param array<string,mixed>|null $click
 */
function hd_native_resolve_fbpost_id(array $meta, ?array $click): string
{
    foreach (['utm_content', 'utm_term'] as $k) {
        $v = trim((string) ($meta[$k] ?? ($click[$k] ?? '')));
        if ($v !== '') {
            return $v;
        }
    }
    return '';
}

/**
 * cVisualid: utm_medium = nom interne créative (ex. OPTIMAL, carousel, reels).
 * Mediums UTM génériques Meta/ads (paid, referal, cpc…) ne sont pas des créatives
 * → défaut métier OPTIMAL (convention campagnes HD).
 *
 * @param array<string,mixed> $meta
 * @param array<string,mixed>|null $click
 */
function hd_native_resolve_visual_id(array $meta, ?array $click): string
{
    $v = trim((string) ($meta['utm_medium'] ?? ($click['utm_medium'] ?? '')));
    if ($v === '') {
        return 'OPTIMAL';
    }
    // paid / referal / cpc = bruit template UTM, pas l’ID créative interne.
    static $generic = [
        'paid' => true,
        'cpc' => true,
        'ppc' => true,
        'cpm' => true,
        'cpa' => true,
        'cpl' => true,
        'referral' => true,
        'referal' => true,
        'social' => true,
        'organic' => true,
        'email' => true,
        'display' => true,
        'affiliate' => true,
        'qa' => true,
        'test' => true,
        'smoke' => true,
    ];
    if (isset($generic[strtolower($v)])) {
        return 'OPTIMAL';
    }
    return $v;
}

/**
 * Boutique order form? (not classic LP name+phone popup).
 * Signals: explicit channel flag, boutique_* meta, or boutique POST fields.
 *
 * @param array<string,mixed> $meta
 * @param array<string,mixed> $ctx
 */
function hd_native_is_boutique_lead(array $meta = [], array $ctx = []): bool
{
    $channel = strtolower(trim((string) ($meta['hd_channel'] ?? ($meta['channel'] ?? ''))));
    if ($channel === 'boutique') {
        return true;
    }
    if (!empty($meta['is_boutique'])) {
        return true;
    }
    foreach (['boutique_gouvernorat', 'boutique_quantite', 'boutique_adresse', 'boutique_prenom'] as $k) {
        if (!empty($meta[$k])) {
            return true;
        }
    }
    // POST fallback (same request as lead save).
    $postChannel = strtolower(trim((string) ($_POST['hd_channel'] ?? '')));
    if ($postChannel === 'boutique') {
        return true;
    }
    if (!empty($_POST['gouvernorat']) || !empty($_POST['quantite']) || !empty($_POST['adresse'])) {
        // Avoid false positives on LP: require at least one boutique-only field name present.
        if (isset($_POST['gouvernorat']) || isset($_POST['quantite']) || isset($_POST['prenom'])) {
            return true;
        }
    }
    $pageId = (int) ($ctx['page_id'] ?? ($meta['page_id'] ?? 0));
    if ($pageId > 0) {
        $tpl = (string) get_page_template_slug($pageId);
        if ($tpl === 'page-hd-white.php' || $tpl === 'page-hd-boutique.php') {
            return true;
        }
        $fk = (string) get_post_meta($pageId, 'hd_boutique_form_key', true);
        if ($fk !== '') {
            return true;
        }
    }
    return false;
}

/**
 * Parse "1 unité" / "2 unités" / "3" → unit count (0 if unknown).
 */
function hd_native_parse_boutique_qty($raw): int
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return 0;
    }
    if (preg_match('/(\d+)/', $raw, $m)) {
        $n = (int) $m[1];
        return $n > 0 ? min($n, 99) : 0;
    }
    return 0;
}

/**
 * Resolve boutique pricing from catalog prices_json (best-effort).
 *
 * @param array<string,mixed> $meta
 * @param array<string,mixed> $form
 * @return array{units:int,qty_label:string,prix_total:?float,discount:?float,region:string}
 */
function hd_native_boutique_order_bits(array $meta, array $form = []): array
{
    $qtyLabel = trim((string) ($meta['boutique_quantite'] ?? ''));
    if ($qtyLabel === '' && !empty($_POST['quantite'])) {
        $qtyLabel = sanitize_text_field(wp_unslash((string) $_POST['quantite']));
    }
    $units = hd_native_parse_boutique_qty($qtyLabel);
    if ($units < 1 && isset($meta['boutique_qty_units'])) {
        $units = (int) $meta['boutique_qty_units'];
    }

    $region = trim((string) ($meta['boutique_gouvernorat'] ?? ''));
    if ($region === '' && !empty($_POST['gouvernorat'])) {
        $region = sanitize_text_field(wp_unslash((string) $_POST['gouvernorat']));
    }
    // Prefer gouvernorat; optionally append street address for richer CRM context.
    $adresse = trim((string) ($meta['boutique_adresse'] ?? ''));
    if ($adresse === '' && !empty($_POST['adresse'])) {
        $adresse = sanitize_text_field(wp_unslash((string) $_POST['adresse']));
    }
    $adressRegion = $region;
    if ($adressRegion === '' && $adresse !== '') {
        $adressRegion = $adresse;
    } elseif ($adressRegion !== '' && $adresse !== '') {
        $adressRegion = $region . ' — ' . $adresse;
    }

    $prix = null;
    $discount = null;
    if (isset($meta['boutique_prix_total']) && $meta['boutique_prix_total'] !== '' && is_numeric($meta['boutique_prix_total'])) {
        $prix = (float) $meta['boutique_prix_total'];
    }
    if (isset($meta['boutique_discount']) && $meta['boutique_discount'] !== '' && is_numeric($meta['boutique_discount'])) {
        $discount = (float) $meta['boutique_discount'];
    }

    if (($prix === null || $discount === null) && $units > 0 && function_exists('hd_catalog_products_get_by_form_key')) {
        $formKey = sanitize_key((string) ($form['key'] ?? ($meta['form_key'] ?? '')));
        $projectId = sanitize_text_field((string) ($form['project_id'] ?? ($meta['project_id'] ?? '')));
        $product = $formKey !== '' ? hd_catalog_products_get_by_form_key($formKey, $projectId) : null;
        if ($product && function_exists('hd_catalog_products_tier_for_units')) {
            $tier = hd_catalog_products_tier_for_units($product->prices_json ?? '[]', $units);
            if (is_array($tier)) {
                if ($prix === null) {
                    $prix = (float) ($tier['price'] ?? 0);
                }
                if ($discount === null) {
                    $discount = (float) ($tier['discount'] ?? 0);
                }
            }
        }
    }

    return [
        'units' => $units,
        'qty_label' => $qtyLabel !== '' ? $qtyLabel : ($units > 0 ? (string) $units : ''),
        'prix_total' => $prix,
        'discount' => $discount,
        'region' => $adressRegion,
    ];
}

/**
 * Enrich lead meta with boutique order bits (local store; used by Espo + compte client).
 *
 * @param array<string,mixed> $meta
 * @param array<string,mixed> $form
 * @param array<string,mixed> $ctx
 * @return array<string,mixed>
 */
function hd_native_enrich_boutique_meta(array $meta, array $form = [], array $ctx = []): array
{
    if (!hd_native_is_boutique_lead($meta, $ctx)) {
        return $meta;
    }
    $meta['is_boutique'] = 1;
    $meta['hd_channel'] = 'boutique';
    $bits = hd_native_boutique_order_bits($meta, $form);
    if ($bits['units'] > 0) {
        $meta['boutique_qty_units'] = $bits['units'];
    }
    if ($bits['qty_label'] !== '') {
        $meta['boutique_quantite'] = $bits['qty_label'];
    }
    if ($bits['region'] !== '') {
        $meta['boutique_adress_region'] = $bits['region'];
    }
    if ($bits['prix_total'] !== null) {
        $meta['boutique_prix_total'] = $bits['prix_total'];
    }
    if ($bits['discount'] !== null) {
        $meta['boutique_discount'] = $bits['discount'];
    }
    return $meta;
}

/**
 * Build Espo LeadCapture JSON body.
 *
 * @param array<string,mixed> $form
 * @param array<string,mixed> $ctx
 * @param array<string,mixed> $meta
 * @return array<string,mixed>
 */
function hd_native_build_espo_payload(string $name, string $phone, array $form, array $ctx, array $meta): array
{
    $tbclid = trim((string) ($meta['tbclid'] ?? ''));
    $snap = $tbclid !== '' ? hd_native_tracker_snapshot($tbclid) : ['click' => null, 'events' => []];
    $click = $snap['click'];

    $comment = (string) ($meta['trackingcomment'] ?? '');
    if ($comment === '') {
        $comment = hd_native_build_tracking_comment($tbclid, $ctx, $meta);
    }

    // Attribution fields stay FULL (utm/fbclid/tbclid). Only cLandingpage is URL-stripped.
    $body = [
        'cProjetId' => (string) ($form['project_id'] ?? ($meta['project_id'] ?? '')),
        'phoneNumber' => $phone,
        'firstName' => $name,
        'cMarketingsource' => (string) ($meta['utm_campaign'] ?? ($click['utm_campaign'] ?? '')),
        'cLandingpage' => hd_native_resolve_landing_url($ctx, $meta, $click),
        'cFbclid' => (string) ($meta['fbclid'] ?? ($click['fbclid'] ?? '')),
        'cFbpostid' => hd_native_resolve_fbpost_id($meta, $click),
        'cFbprofile' => '',
        'cWtid' => $tbclid,
        'cVisualid' => hd_native_resolve_visual_id($meta, $click),
        'cTrackingcomment' => $comment,
    ];

    // Boutique-only Espo custom fields (never sent for classic LP leads).
    if (hd_native_is_boutique_lead($meta, $ctx)) {
        $bits = hd_native_boutique_order_bits($meta, $form);
        if ($bits['region'] !== '') {
            $body['cAdressRegion'] = substr($bits['region'], 0, 255);
        }
        if ($bits['prix_total'] !== null) {
            $body['cPrixTotal'] = round((float) $bits['prix_total'], 2);
        }
        $qty = $bits['qty_label'] !== '' ? $bits['qty_label'] : ($bits['units'] > 0 ? (string) $bits['units'] : '');
        if ($qty !== '') {
            $body['cQuantite'] = $qty;
        }
        if ($bits['discount'] !== null) {
            $body['cDiscount'] = round((float) $bits['discount'], 2);
        }
    }

    return $body;
}

/**
 * POST lead to Espo LeadCapture.
 *
 * @param array<string,string> $body
 * @return array{ok:bool,code:int,body:string,url:string}
 */
function hd_native_send_espo_lead(array $body): array
{
    $url = hd_native_espo_leadcapture_url();
    $response = wp_remote_post($url, [
        'timeout' => 5,
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
    $respBody = (string) wp_remote_retrieve_body($response);
    return [
        'ok' => $code >= 200 && $code < 300,
        'code' => $code,
        'body' => substr($respBody, 0, 500),
        'url' => $url,
    ];
}
