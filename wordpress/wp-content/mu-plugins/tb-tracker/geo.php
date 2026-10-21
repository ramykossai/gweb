<?php
/**
 * TB Tracker — IP geolocation via location.hvserver.site (MaxMind).
 * Stored long-term on clicks: geo_country / geo_region / geo_city / geo_tz / geo_iso.
 * Never stores raw IP; lookup uses request IP then discards it.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('TB_TRACKER_GEO_URL')) {
    define('TB_TRACKER_GEO_URL', 'https://location.hvserver.site/json');
}

/**
 * Best-effort client IP (reuse cloak helper when available).
 */
function tb_tracker_client_ip(): string
{
    if (class_exists('HD_Cloak_Filter') && method_exists('HD_Cloak_Filter', 'client_ip')) {
        $ip = (string) HD_Cloak_Filter::client_ip();
        if ($ip !== '' && $ip !== '0.0.0.0' && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if (filter_var($remote, FILTER_VALIDATE_IP)) {
        // Only trust XFF if peer looks like private proxy
        $public = filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if (!$public) {
            if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                $cf = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);
                if (filter_var($cf, FILTER_VALIDATE_IP)) {
                    return $cf;
                }
            }
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'], 2);
                $xff = trim($parts[0]);
                if (filter_var($xff, FILTER_VALIDATE_IP)) {
                    return $xff;
                }
            }
        }
        return $remote;
    }
    return '';
}

/**
 * @return array{country:string,region:string,city:string,tz:string,iso:string}|null
 */
function tb_tracker_lookup_geo_by_ip(string $ip): ?array
{
    $ip = trim($ip);
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return null;
    }
    // Skip private / reserved — no useful MaxMind hit
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return null;
    }

    $cacheKey = 'tb_geo_' . md5($ip);
    $cached = get_transient($cacheKey);
    if (is_array($cached) && isset($cached['iso'])) {
        return $cached;
    }

    $url = add_query_arg('ip', $ip, TB_TRACKER_GEO_URL);
    $res = wp_remote_get($url, [
        'timeout' => 1.8,
        'redirection' => 0,
        'headers' => [
            'Accept' => 'application/json',
            'User-Agent' => 'TB-Tracker-Geo/1.0',
        ],
    ]);
    if (is_wp_error($res)) {
        return null;
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    $body = (string) wp_remote_retrieve_body($res);
    if ($code !== 200 || $body === '') {
        return null;
    }
    $j = json_decode($body, true);
    if (!is_array($j)) {
        return null;
    }

    $pick = static function ($node, string $prefer = 'fr'): string {
        if (!is_array($node) || empty($node['names']) || !is_array($node['names'])) {
            return '';
        }
        $names = $node['names'];
        if (!empty($names[$prefer])) {
            return (string) $names[$prefer];
        }
        if (!empty($names['en'])) {
            return (string) $names['en'];
        }
        $first = reset($names);
        return is_string($first) ? $first : '';
    };

    $out = [
        'country' => $pick($j['country'] ?? null),
        'region' => '',
        'city' => $pick($j['city'] ?? null),
        'tz' => (string) ($j['location']['time_zone'] ?? ''),
        'iso' => strtoupper((string) ($j['country']['iso_code'] ?? '')),
    ];
    if (!empty($j['subdivisions'][0]) && is_array($j['subdivisions'][0])) {
        $out['region'] = $pick($j['subdivisions'][0]);
    }

    // Cache 24h (geo for an IP is stable enough)
    set_transient($cacheKey, $out, DAY_IN_SECONDS);
    return $out;
}

/**
 * Persist geo on click row if still empty (first-touch wins).
 *
 * @param array{country?:string,region?:string,city?:string,tz?:string,iso?:string}|null $geo
 */
function tb_tracker_store_click_geo(string $clickId, ?array $geo): bool
{
    $clickId = trim($clickId);
    if ($clickId === '' || !is_array($geo) || !tb_tracker_is_enabled()) {
        return false;
    }
    if (($geo['iso'] ?? '') === '' && ($geo['country'] ?? '') === '' && ($geo['city'] ?? '') === '') {
        return false;
    }
    global $wpdb;
    $table = tb_tracker_table('clicks');
    // Only fill empty fields — never overwrite a prior geo
    $n = $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET
            geo_country = CASE WHEN geo_country IS NULL OR geo_country = '' THEN %s ELSE geo_country END,
            geo_region  = CASE WHEN geo_region  IS NULL OR geo_region  = '' THEN %s ELSE geo_region  END,
            geo_city    = CASE WHEN geo_city    IS NULL OR geo_city    = '' THEN %s ELSE geo_city    END,
            geo_tz      = CASE WHEN geo_tz      IS NULL OR geo_tz      = '' THEN %s ELSE geo_tz      END,
            geo_iso     = CASE WHEN geo_iso     IS NULL OR geo_iso     = '' THEN %s ELSE geo_iso     END
         WHERE click_id = %s
           AND (geo_iso IS NULL OR geo_iso = '' OR geo_country IS NULL OR geo_country = '')",
        substr((string) ($geo['country'] ?? ''), 0, 80),
        substr((string) ($geo['region'] ?? ''), 0, 80),
        substr((string) ($geo['city'] ?? ''), 0, 80),
        substr((string) ($geo['tz'] ?? ''), 0, 64),
        substr((string) ($geo['iso'] ?? ''), 0, 8),
        $clickId
    ));
    return $n !== false && (int) $n > 0;
}

/**
 * Lookup current visitor IP and attach geo to click (no-op if already filled).
 */
function tb_tracker_enrich_click_geo(string $clickId): void
{
    $clickId = trim($clickId);
    if ($clickId === '' || !tb_tracker_is_enabled()) {
        return;
    }
    global $wpdb;
    $table = tb_tracker_table('clicks');
    $iso = $wpdb->get_var($wpdb->prepare(
        "SELECT geo_iso FROM {$table} WHERE click_id = %s LIMIT 1",
        $clickId
    ));
    if (is_string($iso) && $iso !== '') {
        return;
    }
    $ip = tb_tracker_client_ip();
    if ($ip === '') {
        return;
    }
    $geo = tb_tracker_lookup_geo_by_ip($ip);
    if ($geo) {
        tb_tracker_store_click_geo($clickId, $geo);
    }
}

/**
 * Read stored geo from click row.
 *
 * @return array{country:string,region:string,city:string,tz:string,iso:string}|null
 */
function tb_tracker_get_click_geo(string $clickId): ?array
{
    $clickId = trim($clickId);
    if ($clickId === '') {
        return null;
    }
    global $wpdb;
    $table = tb_tracker_table('clicks');
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT geo_country, geo_region, geo_city, geo_tz, geo_iso
         FROM {$table} WHERE click_id = %s LIMIT 1",
        $clickId
    ), ARRAY_A);
    if (!$row) {
        return null;
    }
    if (empty($row['geo_iso']) && empty($row['geo_country']) && empty($row['geo_city'])) {
        return null;
    }
    return [
        'country' => (string) ($row['geo_country'] ?? ''),
        'region' => (string) ($row['geo_region'] ?? ''),
        'city' => (string) ($row['geo_city'] ?? ''),
        'tz' => (string) ($row['geo_tz'] ?? ''),
        'iso' => (string) ($row['geo_iso'] ?? ''),
    ];
}
