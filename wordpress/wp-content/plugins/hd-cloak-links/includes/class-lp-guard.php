<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Strategy A: cloak on the landing page itself (direct ads URL).
 * Opt-in per page via post meta — zero overhead on unprotected pages.
 *
 * Filtered traffic always redirects to the WP boutique page /produit-{slug}/
 * (no site.html inline — that path was for Cloaking.House and caused 403/base bugs).
 */
class HD_Cloak_Lp_Guard {
    public static function init() {
        add_action('template_redirect', [__CLASS__, 'maybe_protect'], 1);
        add_action('add_meta_boxes', [__CLASS__, 'register_meta_box']);
        add_action('save_post_page', [__CLASS__, 'save_meta'], 10, 2);
    }

    public static function register_meta_box() {
        add_meta_box(
            'hd-cloak-lp-guard',
            'Cloak LP (direct URL)',
            [__CLASS__, 'render_meta_box'],
            'page',
            'side',
            'default'
        );
    }

    /**
     * @param WP_Post $post
     */
    public static function render_meta_box($post) {
        wp_nonce_field('hd_cloak_lp_guard', 'hd_cloak_lp_guard_nonce');
        $on = get_post_meta($post->ID, 'hd_cloak_lp_protect', true) === '1';
        $white = (string) get_post_meta($post->ID, 'hd_cloak_lp_white_product', true);
        $countries = (string) get_post_meta($post->ID, 'hd_cloak_lp_allowed_countries', true);
        $src = (int) get_post_meta($post->ID, 'hd_source_page_id', true);
        $ok = (int) get_post_meta($post->ID, 'hd_cloak_lp_hits_ok', true);
        $blocked = (int) get_post_meta($post->ID, 'hd_cloak_lp_hits_filtered', true);
        ?>
        <p>
            <label>
                <input type="checkbox" name="hd_cloak_lp_protect" value="1" <?php checked($on); ?>>
                Protéger cette LP (filtre geo/bot)
            </label>
        </p>
        <p>
            <label for="hd_cloak_lp_white_product"><strong>Boutique (white)</strong></label><br>
            <input type="text" class="widefat" id="hd_cloak_lp_white_product" name="hd_cloak_lp_white_product"
                   value="<?php echo esc_attr($white); ?>" placeholder="burn-fast">
            <span class="description">Page WP <code>/produit-{slug}/</code> — white pour tout trafic filtré (tous pays hors allowlist)</span>
        </p>
        <p>
            <label for="hd_cloak_lp_allowed_countries"><strong>Pays autorisés (LP)</strong></label><br>
            <input type="text" class="widefat" id="hd_cloak_lp_allowed_countries" name="hd_cloak_lp_allowed_countries"
                   value="<?php echo esc_attr($countries); ?>" placeholder="(réglages globaux Smart Links)">
        </p>
        <p class="description">White = boutique en 200 sur l’URL ads (pas de redirect, pas de site.html).</p>
        <p class="description">Preview LP (admin) : ajoute <code>?hd_lp_preview=1</code> — sans ce flag le filtre s’applique aussi aux comptes admin.</p>
        <?php if ($src > 0) : ?>
            <p class="description">URL ads : <code>?page_id=<?php echo (int) $src; ?></code></p>
        <?php endif; ?>
        <?php if ($ok || $blocked) : ?>
            <p class="description">Stats : <?php echo (int) $ok; ?> OK / <?php echo (int) $blocked; ?> filtrés</p>
        <?php endif; ?>
        <?php
    }

    /**
     * @param int     $postId
     * @param WP_Post $post
     */
    public static function save_meta($postId, $post) {
        if (!isset($_POST['hd_cloak_lp_guard_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hd_cloak_lp_guard_nonce'])), 'hd_cloak_lp_guard')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_page', $postId)) {
            return;
        }

        $protect = !empty($_POST['hd_cloak_lp_protect']) ? '1' : '0';
        update_post_meta($postId, 'hd_cloak_lp_protect', $protect);

        $white = sanitize_title(wp_unslash($_POST['hd_cloak_lp_white_product'] ?? ''));
        update_post_meta($postId, 'hd_cloak_lp_white_product', $white);

        $countriesRaw = sanitize_text_field(wp_unslash($_POST['hd_cloak_lp_allowed_countries'] ?? ''));
        $countries = [];
        foreach (preg_split('/[\s,;]+/', $countriesRaw) as $c) {
            $c = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $c));
            if (strlen($c) === 2) {
                $countries[] = $c;
            }
        }
        update_post_meta($postId, 'hd_cloak_lp_allowed_countries', implode(',', array_unique($countries)));

        // LP cloak: boutique redirect only (legacy inline/site.html disabled).
        update_post_meta($postId, 'hd_cloak_lp_mode_white', 'redirect');
    }

    public static function maybe_protect() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        if (!is_singular('page')) {
            return;
        }

        $pageId = (int) get_queried_object_id();
        if ($pageId <= 0 || get_post_meta($pageId, 'hd_cloak_lp_protect', true) !== '1') {
            return;
        }

        if (get_page_template_slug($pageId) !== 'page-hd-landing.php') {
            return;
        }

        // Admins only bypass cloak with explicit preview flag — otherwise logged-in
        // staff testing from SG/JP/VPN always saw the LP and reported "site KO".
        if (
            is_user_logged_in()
            && current_user_can('manage_options')
            && isset($_GET['hd_lp_preview'])
            && (string) $_GET['hd_lp_preview'] === '1'
        ) {
            return;
        }

        self::send_nocache_headers();

        $meta = [
            'white_product' => (string) get_post_meta($pageId, 'hd_cloak_lp_white_product', true),
            'allowed_countries' => (string) get_post_meta($pageId, 'hd_cloak_lp_allowed_countries', true),
            'lp_cloak' => 1,
        ];

        $decision = HD_Cloak_Filter::decide($meta);
        self::record($pageId, $decision);

        if (!empty($decision['allow'])) {
            self::bump($pageId, 'hd_cloak_lp_hits_ok');
            return;
        }

        self::bump($pageId, 'hd_cloak_lp_hits_filtered');
        // Boutique WP at same ads URL (HTTP 200) — no site.html, no 302 (Meta-safe).
        HD_Cloak_Router::serve_lp_boutique($meta);
        exit;
    }

    private static function send_nocache_headers() {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!defined('LSCACHE_NO_CACHE')) {
            define('LSCACHE_NO_CACHE', true);
        }
        nocache_headers();
        if (!headers_sent()) {
            header('X-LiteSpeed-Cache-Control: no-cache');
            header('X-HD-Cloak-LP: active');
            header('X-HD-Cloak-Ver: ' . (defined('HD_CLOAK_VERSION') ? HD_CLOAK_VERSION : ''));
        }
    }

    private static function bump($pageId, $key) {
        $n = (int) get_post_meta($pageId, $key, true);
        update_post_meta($pageId, $key, $n + 1);
    }

    /**
     * @param array{allow?:bool,reason?:string,country?:string} $decision
     */
    private static function record($pageId, array $decision) {
        $reason = strtolower(preg_replace('/[^a-z0-9_]/', '', (string) ($decision['reason'] ?? 'unknown')));
        if ($reason === '') {
            $reason = 'unknown';
        }
        self::bump($pageId, 'hd_cloak_lp_r_' . $reason);

        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        $rf = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        $entry = [
            't' => time(),
            'p' => (int) $pageId,
            'k' => 'lp',
            'a' => !empty($decision['allow']) ? 1 : 0,
            'r' => $reason,
            'c' => (string) ($decision['country'] ?? ''),
            'ip' => self::mask_ip(HD_Cloak_Filter::client_ip()),
            'ua' => substr($ua, 0, 180),
            'rf' => substr($rf, 0, 120),
        ];

        $log = get_option('hd_cloak_recent_log', []);
        if (!is_array($log)) {
            $log = [];
        }
        array_unshift($log, $entry);
        if (count($log) > 300) {
            $log = array_slice($log, 0, 300);
        }
        update_option('hd_cloak_recent_log', $log, false);
    }

    private static function mask_ip($ip) {
        $ip = (string) $ip;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';
            return implode('.', $parts);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = @inet_pton($ip);
            if ($packed === false || strlen($packed) !== 16) {
                return '';
            }
            $packed = substr($packed, 0, 14) . "\0\0";
            $out = @inet_ntop($packed);
            return $out !== false ? $out : '';
        }
        return '';
    }
}
