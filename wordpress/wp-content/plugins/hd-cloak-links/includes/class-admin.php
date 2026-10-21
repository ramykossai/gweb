<?php
if (!defined('ABSPATH')) {
    exit;
}

class HD_Cloak_Admin {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'handle_actions']);
        add_action('admin_notices', [__CLASS__, 'notices']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function assets($hook) {
        if (strpos((string) $hook, 'hd-cloak-links') === false) {
            return;
        }
        wp_enqueue_style(
            'hd-cloak-admin',
            HD_CLOAK_URL . 'assets/admin.css',
            [],
            HD_CLOAK_VERSION
        );
        wp_enqueue_script(
            'hd-cloak-admin',
            HD_CLOAK_URL . 'assets/admin.js',
            [],
            HD_CLOAK_VERSION,
            true
        );
    }

    public static function menu() {
        add_menu_page(
            'Smart Links',
            'Smart Links',
            'manage_options',
            'hd-cloak-links',
            [__CLASS__, 'render_list'],
            'dashicons-shield-alt',
            58
        );
        add_submenu_page('hd-cloak-links', 'All Links', 'Tous les liens', 'manage_options', 'hd-cloak-links', [__CLASS__, 'render_list']);
        add_submenu_page('hd-cloak-links', 'Add New', 'Nouveau lien', 'manage_options', 'hd-cloak-links-new', [__CLASS__, 'render_new']);
        add_submenu_page('hd-cloak-links', 'Recent Logs', 'Logs récents', 'manage_options', 'hd-cloak-links-logs', [__CLASS__, 'render_logs']);
        add_submenu_page('hd-cloak-links', 'Settings', 'Réglages', 'manage_options', 'hd-cloak-links-settings', [__CLASS__, 'render_settings']);
    }

    /** Known filter reasons (filter reason `ok` = allow). */
    public static function reason_keys() {
        return ['ok', 'bot', 'geo', 'geo_unknown', 'datacenter', 'empty_ua', 'asn_cloud'];
    }

    public static function reason_label($reason) {
        $reason = (string) $reason;
        $map = [
            'ok' => 'allow',
            'bot' => 'bot',
            'geo' => 'geo',
            'geo_unknown' => 'geo_unknown',
            'datacenter' => 'datacenter',
            'empty_ua' => 'empty_ua',
            'asn_cloud' => 'asn_cloud',
        ];
        return $map[$reason] ?? $reason;
    }

    /** @return array<string,int> */
    public static function reason_counts_for_post($postId) {
        $out = [];
        foreach (self::reason_keys() as $r) {
            $out[$r] = (int) get_post_meta($postId, 'hd_cloak_r_' . $r, true);
        }
        return $out;
    }

    public static function notices() {
        if (!empty($_GET['hd_cloak_msg']) && current_user_can('manage_options')) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['hd_cloak_msg']))) . '</p></div>';
        }
    }

    /** Country catalog: ISO2 => [flag emoji, name] — lightweight, no external calls */
    public static function countries_catalog() {
        return [
            'TN' => ['🇹🇳', 'Tunisie'],
            'MA' => ['🇲🇦', 'Maroc'],
            'DZ' => ['🇩🇿', 'Algérie'],
            'LY' => ['🇱🇾', 'Libye'],
            'EG' => ['🇪🇬', 'Égypte'],
            'SN' => ['🇸🇳', 'Sénégal'],
            'CI' => ['🇨🇮', 'Côte d’Ivoire'],
            'CM' => ['🇨🇲', 'Cameroun'],
            'ML' => ['🇲🇱', 'Mali'],
            'BF' => ['🇧🇫', 'Burkina Faso'],
            'NE' => ['🇳🇪', 'Niger'],
            'GN' => ['🇬🇳', 'Guinée'],
            'TG' => ['🇹🇬', 'Togo'],
            'BJ' => ['🇧🇯', 'Bénin'],
            'GA' => ['🇬🇦', 'Gabon'],
            'CG' => ['🇨🇬', 'Congo'],
            'CD' => ['🇨🇩', 'RD Congo'],
            'MR' => ['🇲🇷', 'Mauritanie'],
            'FR' => ['🇫🇷', 'France'],
            'BE' => ['🇧🇪', 'Belgique'],
            'CH' => ['🇨🇭', 'Suisse'],
            'CA' => ['🇨🇦', 'Canada'],
            'DE' => ['🇩🇪', 'Allemagne'],
            'ES' => ['🇪🇸', 'Espagne'],
            'IT' => ['🇮🇹', 'Italie'],
            'GB' => ['🇬🇧', 'Royaume-Uni'],
            'NL' => ['🇳🇱', 'Pays-Bas'],
            'SA' => ['🇸🇦', 'Arabie saoudite'],
            'AE' => ['🇦🇪', 'Émirats'],
            'QA' => ['🇶🇦', 'Qatar'],
            'KW' => ['🇰🇼', 'Koweït'],
            'BH' => ['🇧🇭', 'Bahreïn'],
            'OM' => ['🇴🇲', 'Oman'],
            'JO' => ['🇯🇴', 'Jordanie'],
            'LB' => ['🇱🇧', 'Liban'],
            'IQ' => ['🇮🇶', 'Irak'],
            'TR' => ['🇹🇷', 'Turquie'],
            'US' => ['🇺🇸', 'États-Unis'],
        ];
    }

    public static function handle_actions() {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (empty($_POST['hd_cloak_action'])) {
            return;
        }
        check_admin_referer('hd_cloak_admin');

        $action = sanitize_key($_POST['hd_cloak_action']);
        if ($action === 'create') {
            self::create_link();
        } elseif ($action === 'save_settings') {
            self::save_settings();
        } elseif ($action === 'reset_stats' && !empty($_POST['post_id'])) {
            $id = (int) $_POST['post_id'];
            update_post_meta($id, 'hd_cloak_hits_ok', 0);
            update_post_meta($id, 'hd_cloak_hits_filtered', 0);
            foreach (self::reason_keys() as $r) {
                update_post_meta($id, 'hd_cloak_r_' . $r, 0);
            }
            wp_safe_redirect(add_query_arg(['page' => 'hd-cloak-links', 'hd_cloak_msg' => 'Stats remises à zéro'], admin_url('admin.php')));
            exit;
        } elseif ($action === 'clear_logs') {
            update_option('hd_cloak_recent_log', [], false);
            wp_safe_redirect(add_query_arg(['page' => 'hd-cloak-links-logs', 'hd_cloak_msg' => 'Logs effacés'], admin_url('admin.php')));
            exit;
        } elseif ($action === 'toggle' && !empty($_POST['post_id'])) {
            $id = (int) $_POST['post_id'];
            $cur = get_post_meta($id, 'hd_cloak_enabled', true);
            $next = ($cur === '0') ? '1' : '0';
            update_post_meta($id, 'hd_cloak_enabled', $next);
            wp_safe_redirect(add_query_arg(['page' => 'hd-cloak-links', 'hd_cloak_msg' => 'Lien mis à jour'], admin_url('admin.php')));
            exit;
        } elseif ($action === 'delete' && !empty($_POST['post_id'])) {
            wp_trash_post((int) $_POST['post_id']);
            wp_safe_redirect(add_query_arg(['page' => 'hd-cloak-links', 'hd_cloak_msg' => 'Lien mis à la corbeille'], admin_url('admin.php')));
            exit;
        }
    }

    private static function countries_from_post() {
        $raw = [];
        if (!empty($_POST['countries']) && is_array($_POST['countries'])) {
            foreach ($_POST['countries'] as $c) {
                $c = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $c));
                if (strlen($c) === 2) {
                    $raw[] = $c;
                }
            }
        } elseif (!empty($_POST['allowed_countries'])) {
            foreach (preg_split('/[\s,;]+/', (string) wp_unslash($_POST['allowed_countries'])) as $c) {
                $c = strtoupper(preg_replace('/[^A-Za-z]/', '', $c));
                if (strlen($c) === 2) {
                    $raw[] = $c;
                }
            }
        }
        return implode(',', array_values(array_unique($raw)));
    }

    private static function create_link() {
        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        $offer = (int) ($_POST['offer_page_id'] ?? 0);
        if (!empty($_POST['offer_page_id_manual'])) {
            $offer = (int) $_POST['offer_page_id_manual'];
        }
        $white = sanitize_title(wp_unslash($_POST['white_product'] ?? ''));
        $countries = self::countries_from_post();
        $modeWhite = sanitize_key($_POST['mode_white'] ?? 'inline');
        $modeOffer = sanitize_key($_POST['mode_offer'] ?? 'redirect');
        $code = sanitize_title(wp_unslash($_POST['code'] ?? ''));

        if ($code === '') {
            $code = self::generate_code();
        }
        $n = 0;
        $base = $code;
        while (HD_Cloak_Links::find_by_code($code) && $n < 20) {
            $n++;
            $code = $base . $n;
        }

        if ($title === '') {
            $title = 'Link ' . $code;
        }
        if ($offer <= 0 || $white === '') {
            wp_safe_redirect(add_query_arg(['page' => 'hd-cloak-links-new', 'hd_cloak_msg' => 'Offer page_id et white product requis'], admin_url('admin.php')));
            exit;
        }

        $id = wp_insert_post([
            'post_type' => 'hd_cloak_link',
            'post_status' => 'publish',
            'post_title' => $title,
        ], true);
        if (is_wp_error($id)) {
            wp_safe_redirect(add_query_arg(['page' => 'hd-cloak-links-new', 'hd_cloak_msg' => $id->get_error_message()], admin_url('admin.php')));
            exit;
        }
        update_post_meta($id, 'hd_cloak_code', $code);
        update_post_meta($id, 'hd_cloak_offer_page_id', $offer);
        update_post_meta($id, 'hd_cloak_white_product', $white);
        update_post_meta($id, 'hd_cloak_allowed_countries', $countries);
        update_post_meta($id, 'hd_cloak_mode_offer', in_array($modeOffer, ['redirect', 'inline'], true) ? $modeOffer : 'redirect');
        update_post_meta($id, 'hd_cloak_mode_white', in_array($modeWhite, ['redirect', 'inline'], true) ? $modeWhite : 'inline');
        update_post_meta($id, 'hd_cloak_enabled', '1');
        update_post_meta($id, 'hd_cloak_hits_ok', 0);
        update_post_meta($id, 'hd_cloak_hits_filtered', 0);

        $url = HD_Cloak_Links::public_url($code);
        wp_safe_redirect(add_query_arg([
            'page' => 'hd-cloak-links',
            'hd_cloak_msg' => 'Créé : ' . $url,
        ], admin_url('admin.php')));
        exit;
    }

    private static function save_settings() {
        $s = HD_Cloak_Links::settings();
        $s['allowed_countries'] = self::countries_from_post();
        $s['block_datacenter'] = empty($_POST['block_datacenter']) ? 0 : 1;
        $s['block_bots'] = empty($_POST['block_bots']) ? 0 : 1;
        $s['block_asn_cloud'] = empty($_POST['block_asn_cloud']) ? 0 : 1;
        $s['geolite_country'] = sanitize_text_field(wp_unslash($_POST['geolite_country'] ?? ''));
        $s['geolite_asn'] = sanitize_text_field(wp_unslash($_POST['geolite_asn'] ?? ''));
        update_option('hd_cloak_settings', $s, false);
        wp_safe_redirect(add_query_arg(['page' => 'hd-cloak-links-settings', 'hd_cloak_msg' => 'Réglages enregistrés'], admin_url('admin.php')));
        exit;
    }

    private static function generate_code() {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $code;
    }

    /** @return array<int,array{id:int,title:string,src:int,slug:string}> */
    public static function list_offer_lps() {
        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_key' => 'hd_source_page_id',
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        $out = [];
        foreach ($pages as $p) {
            $src = (int) get_post_meta($p->ID, 'hd_source_page_id', true);
            if (stripos($p->post_name, 'merci') !== false || $src <= 0) {
                continue;
            }
            $out[] = [
                'id' => $p->ID,
                'title' => $p->post_title,
                'src' => $src,
                'slug' => $p->post_name,
            ];
        }
        return $out;
    }

    /** @return array<int,array{slug:string,title:string,url:string}> */
    public static function list_whites() {
        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_key' => 'hd_white_public_path',
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        $out = [];
        foreach ($pages as $p) {
            $path = (string) get_post_meta($p->ID, 'hd_white_public_path', true);
            $slug = '';
            if (preg_match('#white/([^/]+)/#', $path, $m)) {
                $slug = $m[1];
            } elseif (strpos($p->post_name, 'produit-') === 0) {
                $slug = substr($p->post_name, strlen('produit-'));
            }
            if ($slug === '') {
                continue;
            }
            $out[] = [
                'slug' => $slug,
                'title' => $p->post_title,
                'url' => get_permalink($p),
            ];
        }
        return $out;
    }

    /** Aggregate counters from existing post meta only (no extra tables / cron). */
    private static function aggregate_stats(array $links) {
        $ok = 0;
        $blocked = 0;
        $active = 0;
        $reasons = array_fill_keys(self::reason_keys(), 0);
        foreach ($links as $p) {
            $ok += (int) get_post_meta($p->ID, 'hd_cloak_hits_ok', true);
            $blocked += (int) get_post_meta($p->ID, 'hd_cloak_hits_filtered', true);
            foreach (self::reason_counts_for_post($p->ID) as $r => $n) {
                $reasons[$r] = ($reasons[$r] ?? 0) + $n;
            }
            if (get_post_meta($p->ID, 'hd_cloak_enabled', true) !== '0') {
                $active++;
            }
        }
        return [
            'links' => count($links),
            'active' => $active,
            'ok' => $ok,
            'blocked' => $blocked,
            'total' => $ok + $blocked,
            'reasons' => $reasons,
        ];
    }

    private static function render_reason_pills(array $counts, $compact = false) {
        $parts = [];
        foreach (self::reason_keys() as $r) {
            $n = (int) ($counts[$r] ?? 0);
            if ($compact && $n <= 0) {
                continue;
            }
            $cls = ($r === 'ok') ? 'hdc-reason-ok' : 'hdc-reason-block';
            $parts[] = '<span class="hdc-reason ' . $cls . '" title="' . esc_attr($r) . '">'
                . esc_html(self::reason_label($r)) . ' <b>' . number_format_i18n($n) . '</b></span>';
        }
        if (!$parts) {
            return '<span class="hdc-muted">—</span>';
        }
        return '<div class="hdc-reasons">' . implode(' ', $parts) . '</div>';
    }

    private static function render_country_picker($selectedCsv, $inputName = 'countries[]') {
        $selected = [];
        foreach (preg_split('/[\s,;]+/', strtoupper((string) $selectedCsv)) as $c) {
            $c = preg_replace('/[^A-Z]/', '', $c);
            if (strlen($c) === 2) {
                $selected[$c] = true;
            }
        }
        $catalog = self::countries_catalog();
        echo '<div class="hdc-countries">';
        echo '<div class="hdc-countries-actions">';
        echo '<button type="button" class="button hdc-select-maghreb">Maghreb (TN/MA/DZ)</button> ';
        echo '<button type="button" class="button hdc-select-none">Tout décocher</button>';
        echo '</div>';
        echo '<div class="hdc-countries-grid">';
        foreach ($catalog as $iso => $info) {
            $checked = isset($selected[$iso]) ? ' checked' : '';
            echo '<label class="hdc-country' . ($checked ? ' is-on' : '') . '">';
            echo '<input type="checkbox" name="' . esc_attr($inputName) . '" value="' . esc_attr($iso) . '"' . $checked . ' />';
            echo '<span class="hdc-flag">' . esc_html($info[0]) . '</span>';
            echo '<span class="hdc-cname">' . esc_html($info[1]) . '</span>';
            echo '<span class="hdc-iso">' . esc_html($iso) . '</span>';
            echo '</label>';
        }
        echo '</div></div>';
    }

    private static function flags_for_csv($csv) {
        $catalog = self::countries_catalog();
        $out = [];
        foreach (preg_split('/[\s,;]+/', strtoupper((string) $csv)) as $c) {
            $c = preg_replace('/[^A-Z]/', '', $c);
            if (isset($catalog[$c])) {
                $out[] = '<span class="hdc-mini-flag" title="' . esc_attr($catalog[$c][1]) . '">' . esc_html($catalog[$c][0]) . '</span>';
            } elseif (strlen($c) === 2) {
                $out[] = '<span class="hdc-mini-flag">' . esc_html($c) . '</span>';
            }
        }
        return $out ? implode(' ', $out) : '<span class="hdc-muted">défaut global</span>';
    }

    public static function render_list() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $links = get_posts([
            'post_type' => 'hd_cloak_link',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        $stats = self::aggregate_stats($links);
        $pctOk = $stats['total'] > 0 ? round(100 * $stats['ok'] / $stats['total']) : 0;
        $pctBlock = $stats['total'] > 0 ? round(100 * $stats['blocked'] / $stats['total']) : 0;

        echo '<div class="wrap hdc-wrap">';
        echo '<div class="hdc-hero">';
        echo '<div><h1>Smart Links</h1><p class="hdc-sub">Liens courts <code>/c/{code}</code> · filtre local · sans Cloaking.House</p></div>';
        echo '<a class="button button-primary button-hero" href="' . esc_url(admin_url('admin.php?page=hd-cloak-links-new')) . '">+ Nouveau lien</a>';
        echo '</div>';

        echo '<div class="hdc-stats">';
        echo '<div class="hdc-stat"><span class="hdc-stat-label">Liens</span><strong>' . (int) $stats['links'] . '</strong><small>' . (int) $stats['active'] . ' actifs</small></div>';
        echo '<div class="hdc-stat hdc-stat-ok"><span class="hdc-stat-label">Allowed (offre)</span><strong>' . number_format_i18n($stats['ok']) . '</strong><small>' . $pctOk . '% du trafic</small></div>';
        echo '<div class="hdc-stat hdc-stat-block"><span class="hdc-stat-label">Blocked (white)</span><strong>' . number_format_i18n($stats['blocked']) . '</strong><small>' . $pctBlock . '% du trafic</small></div>';
        echo '<div class="hdc-stat"><span class="hdc-stat-label">Total hits</span><strong>' . number_format_i18n($stats['total']) . '</strong><small><a href="' . esc_url(admin_url('admin.php?page=hd-cloak-links-logs')) . '">Voir logs récents →</a></small></div>';
        echo '</div>';

        if ($stats['total'] > 0) {
            echo '<div class="hdc-bar" title="Allowed vs blocked"><span class="hdc-bar-ok" style="width:' . $pctOk . '%"></span><span class="hdc-bar-block" style="width:' . $pctBlock . '%"></span></div>';
        }

        $reasonSum = array_sum($stats['reasons'] ?? []);
        if ($reasonSum > 0) {
            echo '<div class="hdc-card hdc-card-pad" style="margin-bottom:12px">';
            echo '<div class="hdc-section-title">Raisons (tous liens)</div>';
            echo self::render_reason_pills($stats['reasons']);
            echo '</div>';
        }

        echo '<div class="hdc-card">';
        echo '<table class="hdc-table"><thead><tr>';
        echo '<th>Lien</th><th>Smart URL</th><th>Offer</th><th>White</th><th>Pays</th><th>Allowed</th><th>Blocked</th><th>Raisons</th><th>Statut</th><th></th>';
        echo '</tr></thead><tbody>';
        if (!$links) {
            echo '<tr><td colspan="10" class="hdc-empty">Aucun lien — crée-en un pour commencer.</td></tr>';
        }
        foreach ($links as $p) {
            $code = get_post_meta($p->ID, 'hd_cloak_code', true);
            $url = HD_Cloak_Links::public_url($code);
            $offer = (int) get_post_meta($p->ID, 'hd_cloak_offer_page_id', true);
            $white = get_post_meta($p->ID, 'hd_cloak_white_product', true);
            $countries = get_post_meta($p->ID, 'hd_cloak_allowed_countries', true);
            $ok = (int) get_post_meta($p->ID, 'hd_cloak_hits_ok', true);
            $bad = (int) get_post_meta($p->ID, 'hd_cloak_hits_filtered', true);
            $en = get_post_meta($p->ID, 'hd_cloak_enabled', true) !== '0';
            $modeW = get_post_meta($p->ID, 'hd_cloak_mode_white', true) ?: 'redirect';
            $modeO = get_post_meta($p->ID, 'hd_cloak_mode_offer', true) ?: 'redirect';
            $hitTotal = max(1, $ok + $bad);
            $rowPct = (int) round(100 * $ok / $hitTotal);
            $reasons = self::reason_counts_for_post($p->ID);
            $logsUrl = add_query_arg(['page' => 'hd-cloak-links-logs', 'link' => (int) $p->ID], admin_url('admin.php'));

            echo '<tr>';
            echo '<td><strong>' . esc_html($p->post_title) . '</strong><div class="hdc-muted">offer: ' . esc_html($modeO) . ' · white: ' . esc_html($modeW) . '</div></td>';
            echo '<td><code class="hdc-url">' . esc_html($url) . '</code> ';
            echo '<button type="button" class="button button-small hdc-copy" data-copy="' . esc_attr($url) . '">Copier</button></td>';
            echo '<td><a href="' . esc_url(home_url('/?page_id=' . $offer)) . '" target="_blank" rel="noopener">?' . (int) $offer . '</a></td>';
            echo '<td><code>' . esc_html($white) . '</code></td>';
            echo '<td class="hdc-flags-cell">' . self::flags_for_csv($countries) . '</td>';
            echo '<td><span class="hdc-pill hdc-pill-ok">' . number_format_i18n($ok) . '</span></td>';
            echo '<td><span class="hdc-pill hdc-pill-block">' . number_format_i18n($bad) . '</span></td>';
            echo '<td class="hdc-reasons-cell">' . self::render_reason_pills($reasons, true);
            echo '<div class="hdc-muted"><a href="' . esc_url($logsUrl) . '">logs</a></div></td>';
            echo '<td>' . ($en ? '<span class="hdc-badge on">ON</span>' : '<span class="hdc-badge off">OFF</span>');
            echo '<div class="hdc-mini-bar"><i style="width:' . $rowPct . '%"></i></div></td>';
            echo '<td class="hdc-actions">';
            echo '<form method="post">' . wp_nonce_field('hd_cloak_admin', '_wpnonce', true, false);
            echo '<input type="hidden" name="hd_cloak_action" value="toggle" /><input type="hidden" name="post_id" value="' . (int) $p->ID . '" />';
            echo '<button class="button">Toggle</button></form>';
            echo '<form method="post">' . wp_nonce_field('hd_cloak_admin', '_wpnonce', true, false);
            echo '<input type="hidden" name="hd_cloak_action" value="reset_stats" /><input type="hidden" name="post_id" value="' . (int) $p->ID . '" />';
            echo '<button class="button" title="Reset stats">↺</button></form>';
            echo '<form method="post" onsubmit="return confirm(\'Supprimer ce lien ?\')">' . wp_nonce_field('hd_cloak_admin', '_wpnonce', true, false);
            echo '<input type="hidden" name="hd_cloak_action" value="delete" /><input type="hidden" name="post_id" value="' . (int) $p->ID . '" />';
            echo '<button class="button">Trash</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    public static function render_new() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $lps = self::list_offer_lps();
        $whites = self::list_whites();
        $settings = HD_Cloak_Links::settings();

        echo '<div class="wrap hdc-wrap">';
        echo '<div class="hdc-hero"><div><h1>Nouveau Smart Link</h1><p class="hdc-sub">Trafic OK → LP ads · filtré → white boutique</p></div></div>';
        echo '<form method="post" class="hdc-card hdc-form">';
        wp_nonce_field('hd_cloak_admin');
        echo '<input type="hidden" name="hd_cloak_action" value="create" />';

        echo '<div class="hdc-grid-2">';
        echo '<label class="hdc-field"><span>Titre</span><input name="title" placeholder="El Toro — Ads TN" /></label>';
        echo '<label class="hdc-field"><span>Code (optionnel)</span><input name="code" placeholder="auto — ex. eltoro1" /></label>';
        echo '</div>';

        echo '<label class="hdc-field"><span>Offer LP (page_id source)</span>';
        echo '<select name="offer_page_id" required><option value="">— choisir —</option>';
        foreach ($lps as $lp) {
            echo '<option value="' . (int) $lp['src'] . '">' . esc_html($lp['title'] . ' — page_id=' . $lp['src']) . '</option>';
        }
        echo '</select>';
        echo '<input type="number" name="offer_page_id_manual" placeholder="ou page_id manuel" class="hdc-manual" onchange="if(this.value){this.form.offer_page_id.value=this.value}" />';
        echo '</label>';

        echo '<label class="hdc-field"><span>White produit (boutique)</span>';
        echo '<select name="white_product" required><option value="">— choisir —</option>';
        foreach ($whites as $w) {
            echo '<option value="' . esc_attr($w['slug']) . '">' . esc_html($w['title'] . ' (' . $w['slug'] . ')') . '</option>';
        }
        echo '</select></label>';

        echo '<div class="hdc-field"><span>Pays autorisés (voient l’offre)</span>';
        self::render_country_picker($settings['allowed_countries']);
        echo '<p class="description">Les autres pays / bots / datacenter voient la white.</p></div>';

        echo '<div class="hdc-field"><span>Offer mode (trafic OK)</span>';
        echo '<div class="hdc-mode-cards">';
        echo '<label class="hdc-mode is-on"><input type="radio" name="mode_offer" value="redirect" checked />';
        echo '<strong>Redirect</strong><em>302 vers <code>/?page_id=…</code><br>URL change → LP ads</em></label>';
        echo '<label class="hdc-mode"><input type="radio" name="mode_offer" value="inline" />';
        echo '<strong>Inline</strong><em>Charge la LP sur <code>/c/{code}</code><br>sans redirection — même URL smart</em></label>';
        echo '</div></div>';

        echo '<div class="hdc-field"><span>White mode (trafic filtré — bots / hors geo)</span>';
        echo '<p class="description">Séparé de la boutique WP : les HTML <code>white/{slug}/site.html</code> servent uniquement au cloak. La boutique <code>/produit-…/</code> reste pour le trafic réel + tracker.</p>';
        echo '<div class="hdc-mode-cards">';
        echo '<label class="hdc-mode is-on"><input type="radio" name="mode_white" value="inline" checked />';
        echo '<strong>Inline (recommandé)</strong><em>Charge <code>white/{slug}/site.html</code> sur <code>/c/{code}</code><br>sans polluer le tracker boutique</em></label>';
        echo '<label class="hdc-mode"><input type="radio" name="mode_white" value="redirect" />';
        echo '<strong>Redirect</strong><em>302 vers <code>/produit-{slug}/</code><br>⚠️ mélange bots/filtre dans la boutique trackée</em></label>';
        echo '</div></div>';

        echo '<p><button class="button button-primary button-hero">Créer le lien</button></p>';
        echo '</form></div>';
    }

    public static function render_logs() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $filterLink = isset($_GET['link']) ? (int) $_GET['link'] : 0;
        $filterReason = isset($_GET['reason']) ? sanitize_key(wp_unslash($_GET['reason'])) : '';
        $limit = 80;

        $links = get_posts([
            'post_type' => 'hd_cloak_link',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        $codeById = [];
        $titleById = [];
        foreach ($links as $p) {
            $codeById[$p->ID] = (string) get_post_meta($p->ID, 'hd_cloak_code', true);
            $titleById[$p->ID] = $p->post_title;
        }

        $log = get_option('hd_cloak_recent_log', []);
        if (!is_array($log)) {
            $log = [];
        }

        $rows = [];
        foreach ($log as $e) {
            if (!is_array($e)) {
                continue;
            }
            $pid = (int) ($e['p'] ?? 0);
            $reason = (string) ($e['r'] ?? '');
            if ($filterLink > 0 && $pid !== $filterLink) {
                continue;
            }
            if ($filterReason !== '' && $reason !== $filterReason) {
                continue;
            }
            $rows[] = $e;
            if (count($rows) >= $limit) {
                break;
            }
        }

        echo '<div class="wrap hdc-wrap">';
        echo '<div class="hdc-hero">';
        echo '<div><h1>Logs récents</h1><p class="hdc-sub">Buffer option <code>hd_cloak_recent_log</code> · max 300 · sans autoload</p></div>';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=hd-cloak-links')) . '">← Tous les liens</a>';
        echo '</div>';

        echo '<div class="hdc-card hdc-card-pad" style="margin-bottom:12px">';
        echo '<form method="get" class="hdc-filters">';
        echo '<input type="hidden" name="page" value="hd-cloak-links-logs" />';
        echo '<label>Lien <select name="link"><option value="0">— tous —</option>';
        foreach ($links as $p) {
            $code = $codeById[$p->ID] ?? '';
            $sel = selected($filterLink, $p->ID, false);
            echo '<option value="' . (int) $p->ID . '"' . $sel . '>' . esc_html($p->post_title . ' (/c/' . $code . ')') . '</option>';
        }
        echo '</select></label>';
        echo '<label>Raison <select name="reason"><option value="">— toutes —</option>';
        foreach (self::reason_keys() as $r) {
            $sel = selected($filterReason, $r, false);
            echo '<option value="' . esc_attr($r) . '"' . $sel . '>' . esc_html(self::reason_label($r)) . '</option>';
        }
        echo '</select></label>';
        echo '<button class="button button-primary">Filtrer</button>';
        if ($filterLink || $filterReason !== '') {
            echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=hd-cloak-links-logs')) . '">Reset</a>';
        }
        echo '</form>';
        echo '<form method="post" style="margin-top:10px" onsubmit="return confirm(\'Effacer tout le buffer de logs ?\')">';
        wp_nonce_field('hd_cloak_admin');
        echo '<input type="hidden" name="hd_cloak_action" value="clear_logs" />';
        echo '<button class="button">Effacer les logs</button>';
        echo ' <span class="hdc-muted">' . count($log) . ' entrées en buffer · affichage ' . count($rows) . '</span>';
        echo '</form></div>';

        echo '<div class="hdc-card">';
        echo '<table class="hdc-table hdc-table-logs"><thead><tr>';
        echo '<th>Heure</th><th>Lien</th><th>Décision</th><th>Raison</th><th>Pays</th><th>IP</th><th>UA</th>';
        echo '</tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="7" class="hdc-empty">Aucun événement' . ($log ? ' pour ce filtre' : ' — les hits /c/{code} apparaîtront ici') . '.</td></tr>';
        }
        foreach ($rows as $e) {
            $pid = (int) ($e['p'] ?? 0);
            $allow = !empty($e['a']);
            $reason = (string) ($e['r'] ?? '');
            $code = $codeById[$pid] ?? '';
            $title = $titleById[$pid] ?? ('#' . $pid);
            $t = (int) ($e['t'] ?? 0);
            $when = $t ? wp_date('Y-m-d H:i:s', $t) : '—';
            $ua = (string) ($e['ua'] ?? '');
            if (strlen($ua) > 70) {
                $uaShort = substr($ua, 0, 67) . '…';
            } else {
                $uaShort = $ua;
            }

            echo '<tr>';
            echo '<td><code>' . esc_html($when) . '</code></td>';
            echo '<td><strong>' . esc_html($title) . '</strong>';
            if ($code !== '') {
                echo '<div class="hdc-muted"><code>/c/' . esc_html($code) . '</code></div>';
            }
            echo '</td>';
            echo '<td>' . ($allow
                ? '<span class="hdc-pill hdc-pill-ok">allow</span>'
                : '<span class="hdc-pill hdc-pill-block">block</span>') . '</td>';
            echo '<td><span class="hdc-reason ' . ($allow ? 'hdc-reason-ok' : 'hdc-reason-block') . '">'
                . esc_html(self::reason_label($reason)) . '</span></td>';
            echo '<td>' . esc_html((string) ($e['c'] ?? '') ?: '—') . '</td>';
            echo '<td><code>' . esc_html((string) ($e['ip'] ?? '') ?: '—') . '</code></td>';
            echo '<td class="hdc-ua" title="' . esc_attr($ua) . '">' . esc_html($uaShort !== '' ? $uaShort : '—') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }

    public static function render_settings() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = HD_Cloak_Links::settings();
        $countryOk = is_file($s['geolite_country']);
        $asnOk = is_file($s['geolite_asn']);

        echo '<div class="wrap hdc-wrap">';
        echo '<div class="hdc-hero"><div><h1>Réglages Smart Links</h1><p class="hdc-sub">Filtres globaux · bases geo locales</p></div></div>';
        echo '<form method="post" class="hdc-card hdc-form">';
        wp_nonce_field('hd_cloak_admin');
        echo '<input type="hidden" name="hd_cloak_action" value="save_settings" />';

        echo '<div class="hdc-field"><span>Pays autorisés par défaut</span>';
        self::render_country_picker($s['allowed_countries']);
        echo '</div>';

        echo '<div class="hdc-toggles">';
        echo '<label class="hdc-toggle"><input type="checkbox" name="block_bots" value="1" ' . checked(1, (int) $s['block_bots'], false) . ' /><span>Bloquer bots / crawlers</span></label>';
        echo '<label class="hdc-toggle"><input type="checkbox" name="block_datacenter" value="1" ' . checked(1, (int) $s['block_datacenter'], false) . ' /><span>Bloquer IP datacenter (CIDR)</span></label>';
        echo '<label class="hdc-toggle"><input type="checkbox" name="block_asn_cloud" value="1" ' . checked(1, (int) $s['block_asn_cloud'], false) . ' /><span>Bloquer ASN cloud (si .mmdb ASN)</span></label>';
        echo '</div>';

        echo '<label class="hdc-field"><span>Fichier Country .mmdb</span><input name="geolite_country" value="' . esc_attr($s['geolite_country']) . '" />';
        echo '<p class="description">' . ($countryOk ? '<span class="hdc-ok">Fichier trouvé</span>' : '<span class="hdc-bad">Fichier manquant — filtre pays inactif</span>') . '</p></label>';
        echo '<label class="hdc-field"><span>Fichier ASN .mmdb</span><input name="geolite_asn" value="' . esc_attr($s['geolite_asn']) . '" />';
        echo '<p class="description">' . ($asnOk ? '<span class="hdc-ok">Fichier trouvé</span>' : '<span class="hdc-muted">Base ASN non installée — filtre ASN volontairement inactif (option facultative, licence MaxMind requise). Les filtres pays, datacenter et bots restent actifs.</span>') . '</p></label>';

        echo '<p><button class="button button-primary">Enregistrer</button></p>';
        echo '</form></div>';
    }
}
