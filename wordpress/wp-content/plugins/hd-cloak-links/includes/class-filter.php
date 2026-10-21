<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Local traffic filter: bots, datacenter CIDRs, GeoLite2 country/ASN (optional .mmdb files).
 */
class HD_Cloak_Filter {
    /** @var array<int,string>|null */
    private static $botPatterns = null;

    /** @var list<array{0:int,1:int}>|null */
    private static $cidrs = null;

    /** Cloud ASNs commonly used by scrapers / ad reviewers / hosting */
    private static $cloudAsns = [
        16509, 14618, 15169, 396982,
        8075, 8068,
        13335,
        14061, 393406,
        63949, 54825,
        20473,
        16276,
        24940,
        31898,
        36351,
        45102,
        136907,
        132203,
        60068,
        9009,
        212238,
        62240,
        51167,
        8560,
    ];

    /**
     * @param array<string,mixed> $linkMeta
     * @return array{allow:bool,reason:string,country:string,asn:int}
     */
    public static function decide(array $linkMeta = []) {
        $settings = HD_Cloak_Links::settings();
        $ip = self::client_ip();
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

        $countriesRaw = $linkMeta['allowed_countries'] ?? ($settings['allowed_countries'] ?? '');
        if (trim((string) $countriesRaw) === '') {
            $countriesRaw = $settings['allowed_countries'] ?? '';
        }
        $allowed = self::parse_countries($countriesRaw);

        // Geo/ASN always resolved so logs show country even when blocked (bots, etc.).
        $country = self::country_code($ip, $settings['geolite_country'] ?? '');
        $asn = self::asn_number($ip, $settings['geolite_asn'] ?? '');

        // TN: no Meta reviewers; skip bot UA block to avoid false positives on real mobile traffic.
        $skipBotTn = ($country === 'TN' && in_array('TN', $allowed, true));

        if (!empty($settings['block_bots']) && self::is_bot($ua) && !$skipBotTn) {
            return ['allow' => false, 'reason' => 'bot', 'country' => $country, 'asn' => $asn];
        }

        if (!empty($settings['block_asn_cloud']) && $asn && in_array($asn, self::$cloudAsns, true)) {
            return ['allow' => false, 'reason' => 'asn_cloud', 'country' => $country, 'asn' => $asn];
        }

        $datacenterAllowedGeo = false;
        if (!empty($settings['block_datacenter']) && self::ip_in_datacenter($ip)) {
            if ($country !== '' && in_array($country, $allowed, true)) {
                $datacenterAllowedGeo = true;
            } else {
                return ['allow' => false, 'reason' => 'datacenter', 'country' => $country, 'asn' => $asn];
            }
        }

        // If GeoLite country DB is present and allowlist is set: enforce it.
        // Unknown country with an allowlist → white (safe).
        if ($allowed) {
            $dbPresent = !empty($settings['geolite_country']) && is_file($settings['geolite_country']);
            if ($dbPresent) {
                if ($country === '' || !in_array($country, $allowed, true)) {
                    return [
                        'allow' => false,
                        'reason' => ($country === '' ? 'geo_unknown' : 'geo'),
                        'country' => $country,
                        'asn' => $asn,
                    ];
                }
            }
        }

        if (trim($ua) === '') {
            return ['allow' => false, 'reason' => 'empty_ua', 'country' => $country, 'asn' => $asn];
        }

        return [
            'allow' => true,
            'reason' => ($datacenterAllowedGeo ? 'datacenter_allowed_geo' : 'ok'),
            'country' => $country,
            'asn' => $asn,
        ];
    }

    public static function client_ip() {
        $remote = self::normalize_ip($_SERVER['REMOTE_ADDR'] ?? '');
        if ($remote === '') {
            return '0.0.0.0';
        }

        /*
         * Forwarded headers are attacker-controlled unless the direct peer is
         * known: Cloudflare's published networks, or a non-public local/hosting
         * reverse proxy. Public direct clients can therefore never spoof XFF.
         */
        if (!self::is_trusted_proxy($remote)) {
            return $remote;
        }

        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $forwarded = self::normalize_ip($_SERVER['HTTP_CF_CONNECTING_IP']);
            if ($forwarded !== '') {
                return $forwarded;
            }
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'], 2);
            $forwarded = self::normalize_ip($parts[0]);
            if ($forwarded !== '') {
                return $forwarded;
            }
        }

        return $remote;
    }

    private static function normalize_ip($value) {
        $ip = trim((string) $value);
        if (preg_match('/^\[([0-9a-f:]+)\](?::\d+)?$/i', $ip, $m)) {
            $ip = $m[1];
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $ip, $m)) {
            $ip = $m[1];
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    private static function is_trusted_proxy($ip) {
        // Private/loopback peers are only reachable inside the hosting network.
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }

        // Cloudflare IP ranges: https://www.cloudflare.com/ips/
        $cloudflareCidrs = [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22',
            '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
            '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22',
            '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32',
            '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29',
            '2c0f:f248::/32',
        ];
        foreach ($cloudflareCidrs as $cidr) {
            if (self::ip_in_cidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    private static function ip_in_cidr($ip, $cidr) {
        list($network, $bits) = explode('/', $cidr, 2);
        $packedIp = @inet_pton($ip);
        $packedNetwork = @inet_pton($network);
        if ($packedIp === false || $packedNetwork === false || strlen($packedIp) !== strlen($packedNetwork)) {
            return false;
        }

        $bits = (int) $bits;
        $maxBits = strlen($packedIp) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($bytes > 0 && substr($packedIp, 0, $bytes) !== substr($packedNetwork, 0, $bytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;
        return (ord($packedIp[$bytes]) & $mask) === (ord($packedNetwork[$bytes]) & $mask);
    }

    /** @return list<string> */
    private static function parse_countries($raw) {
        $out = [];
        foreach (preg_split('/[\s,;]+/', strtoupper((string) $raw)) as $c) {
            $c = preg_replace('/[^A-Z]/', '', $c);
            if (strlen($c) === 2) {
                $out[] = $c;
            }
        }
        return array_values(array_unique($out));
    }

    private static function is_bot($ua) {
        if (self::$botPatterns === null) {
            $file = HD_CLOAK_DIR . 'data/bots.php';
            $list = is_file($file) ? include $file : [];
            self::$botPatterns = is_array($list) ? $list : [];
        }
        $uaL = strtolower($ua);
        foreach (self::$botPatterns as $p) {
            if ($p !== '' && strpos($uaL, strtolower((string) $p)) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function ip_in_datacenter($ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        self::load_cidrs();
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        $long = (int) sprintf('%u', $long);
        foreach (self::$cidrs as $row) {
            list($network, $mask) = $row;
            if (($long & $mask) === ($network & $mask)) {
                return true;
            }
        }
        return false;
    }

    private static function load_cidrs() {
        if (self::$cidrs !== null) {
            return;
        }
        self::$cidrs = [];
        $file = HD_CLOAK_DIR . 'data/datacenter-cidrs.txt';
        if (!is_file($file)) {
            return;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (!preg_match('#^(\d+\.\d+\.\d+\.\d+)/(\d{1,2})$#', $line, $m)) {
                continue;
            }
            $ipLong = ip2long($m[1]);
            $bits = (int) $m[2];
            if ($ipLong === false || $bits < 0 || $bits > 32) {
                continue;
            }
            $mask = $bits === 0 ? 0 : (int) sprintf('%u', (-1 << (32 - $bits)) & 0xFFFFFFFF);
            self::$cidrs[] = [(int) sprintf('%u', $ipLong), $mask];
        }
    }

    private static function country_code($ip, $mmdbPath) {
        $rec = HD_Cloak_Geo::lookup($mmdbPath, $ip);
        if (!is_array($rec)) {
            return '';
        }
        $code = $rec['country']['iso_code'] ?? ($rec['registered_country']['iso_code'] ?? '');
        return strtoupper((string) $code);
    }

    private static function asn_number($ip, $mmdbPath) {
        $rec = HD_Cloak_Geo::lookup($mmdbPath, $ip);
        if (!is_array($rec)) {
            return 0;
        }
        return (int) ($rec['autonomous_system_number'] ?? 0);
    }
}

/** Thin wrapper around vendored MaxMind DB Reader. */
class HD_Cloak_Geo {
    private static $autoload = false;

    /** @var array<string,\MaxMind\Db\Reader|false> */
    private static $readers = [];

    private static function boot() {
        if (self::$autoload) {
            return;
        }
        self::$autoload = true;
        spl_autoload_register(function ($class) {
            if (strpos($class, 'MaxMind\\Db\\') !== 0) {
                return;
            }
            $rel = str_replace('\\', '/', $class) . '.php';
            $file = HD_CLOAK_DIR . 'includes/maxmind-db/' . $rel;
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    public static function lookup($path, $ip) {
        $path = (string) $path;
        if ($path === '' || !is_file($path) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        self::boot();
        if (!isset(self::$readers[$path])) {
            try {
                self::$readers[$path] = new \MaxMind\Db\Reader($path);
            } catch (Exception $e) {
                self::$readers[$path] = false;
            }
        }
        $reader = self::$readers[$path];
        if (!$reader) {
            return null;
        }
        try {
            return $reader->get($ip);
        } catch (Exception $e) {
            return null;
        }
    }
}
