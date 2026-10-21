<?php
/**
 * TB Tracker — Segments admin UI (manage_options + nonce).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle POST actions for segments (recalc, seed, flag). Returns notice string.
 */
function tb_tracker_segments_admin_handle_post(): string
{
    if (!current_user_can('manage_options')) {
        return '';
    }

    $notice = '';

    if (isset($_POST['tb_segments_save_flags'])) {
        check_admin_referer('tb_tracker_segments');
        $recalcOn = !empty($_POST['tb_segments_recalc_enabled']);
        update_option(TB_TRACKER_OPT_SEGMENTS_RECALC, $recalcOn ? 1 : 0, false);
        if (function_exists('tb_tracker_segments_sync_cron')) {
            tb_tracker_segments_sync_cron();
        }
        if (!$recalcOn && function_exists('tb_tracker_segments_clear_cron')) {
            tb_tracker_segments_clear_cron();
        }
        $notice = $recalcOn
            ? 'Recalcul segments WP-Cron : activé (toutes les ~30 min, batch borné).'
            : 'Recalcul segments WP-Cron : désactivé (aucun cron agressif).';
    }

    if (isset($_POST['tb_segments_seed'])) {
        check_admin_referer('tb_tracker_segments');
        tb_tracker_segments_install_tables();
        $n = tb_tracker_segments_seed_from_config();
        $notice = sprintf('Tables segments OK · %d définition(s) synchronisée(s) depuis config-v1.json.', $n);
    }

    if (isset($_POST['tb_segments_recalc'])) {
        check_admin_referer('tb_tracker_segments');
        $dry = !empty($_POST['tb_segments_dry_run']);
        $limit = isset($_POST['tb_segments_limit']) ? absint($_POST['tb_segments_limit']) : 50;
        $limit = max(1, min(200, $limit));
        $within = isset($_POST['tb_segments_within_days']) ? absint($_POST['tb_segments_within_days']) : 7;
        $within = max(1, min(30, $within));

        if (!$dry) {
            tb_tracker_segments_install_tables();
            tb_tracker_segments_seed_from_config();
        }

        $res = tb_tracker_segments_recalc([
            'limit' => $limit,
            'within_days' => $within,
            'dry_run' => $dry,
            'source' => 'manual',
        ]);

        if (!$res['ok']) {
            $notice = 'Recalcul interrompu : ' . esc_html((string) ($res['error'] ?: 'erreur inconnue'));
        } else {
            $parts = [];
            foreach ($res['by_segment'] as $slug => $stats) {
                $parts[] = $slug . '=' . (int) $stats['matched'];
            }
            $notice = sprintf(
                '%s · clicks=%d · ajoutés=%d · maj=%d · retirés=%d · %dms · [%s]%s',
                $dry ? 'Dry-run OK (aucune écriture membership)' : 'Recalcul OK',
                (int) $res['clicks_scanned'],
                (int) $res['memberships_added'],
                (int) $res['memberships_updated'],
                (int) $res['memberships_removed'],
                (int) $res['ms'],
                implode(', ', $parts),
                $dry ? ' — décochez Dry-run pour écrire en base.' : ''
            );
        }
    }

    return $notice;
}

/**
 * Render segments section HTML (embedded in admin V2).
 *
 * @param string $extraNotice Notice from parent render if already handled.
 */
function tb_tracker_segments_admin_render_section(string $extraNotice = ''): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $notice = $extraNotice;
    if ($notice === '') {
        $notice = tb_tracker_segments_admin_handle_post();
    }

    $ready = function_exists('tb_tracker_segments_tables_ready') && tb_tracker_segments_tables_ready();
    $recalcEnabled = function_exists('tb_tracker_segments_recalc_enabled') && tb_tracker_segments_recalc_enabled();
    $segments = $ready ? tb_tracker_segments_list_all() : [];
    $counts = $ready ? tb_tracker_segments_member_counts() : [];
    $last = get_option('tb_segments_last_recalc', null);
    $nextCron = wp_next_scheduled('tb_tracker_segments_recalc_cron');
    $configPath = 'mu-plugins/tb-tracker/segments/config-v1.json';

    if (!$ready && function_exists('tb_tracker_segments_load_config')) {
        $cfg = tb_tracker_segments_load_config();
        foreach ($cfg['segments'] as $seg) {
            if (!is_array($seg)) {
                continue;
            }
            $segments[] = [
                'id' => 0,
                'slug' => (string) ($seg['slug'] ?? ''),
                'name' => (string) ($seg['name'] ?? ''),
                'description' => (string) ($seg['description'] ?? ''),
                'priority' => (int) ($seg['priority'] ?? 100),
                'active' => !empty($seg['active']) ? 1 : 0,
                'calc_mode' => (string) ($seg['calc_mode'] ?? 'batch'),
                'updated_at' => '',
            ];
        }
    }
    $totalMembers = 0;
    foreach ($counts as $count) {
        $totalMembers += (int) $count;
    }
    $activeCount = 0;
    foreach ($segments as $segment) {
        if (!empty($segment['active'])) {
            $activeCount++;
        }
    }
    ?>
    <?php if ($notice !== '') : ?>
        <div class="hdcp-notice hdcp-notice--ok"><?php echo esc_html($notice); ?></div>
    <?php endif; ?>

    <div class="hdcp-stats tbx-stats">
        <?php
        tb_tracker_admin_stat(number_format_i18n(count($segments)), 'Définitions', 'config-v1.json');
        tb_tracker_admin_stat(number_format_i18n($activeCount), 'Segments actifs', 'évalués au recalcul');
        tb_tracker_admin_stat($ready ? number_format_i18n($totalMembers) : '—', 'Memberships', 'click_id uniquement');
        tb_tracker_admin_stat($recalcEnabled ? 'ON' : 'OFF', 'Cron recalcul', $nextCron ? 'prochain ' . gmdate('H:i', (int) $nextCron) . ' UTC' : 'non planifié', $recalcEnabled ? '' : 'mute');
        ?>
    </div>

    <div class="tbx-table-wrap">
        <table class="tbx-table">
            <thead>
                <tr><th>Slug</th><th>Nom</th><th>Mode</th><th>Actif</th><th class="tbx-num">Membres</th><th class="tbx-num">Priorité</th></tr>
            </thead>
            <tbody>
            <?php if (!$segments) : ?>
                <tr><td colspan="6" class="tbx-empty">Aucune définition. « Synchroniser config » crée les tables et les segments v1.</td></tr>
            <?php else : foreach ($segments as $row) :
                $sid = (int) ($row['id'] ?? 0);
                $cnt = $sid > 0 ? (int) ($counts[$sid] ?? 0) : 0;
                ?>
                <tr>
                    <td><code><?php echo esc_html((string) $row['slug']); ?></code></td>
                    <td>
                        <?php echo esc_html((string) $row['name']); ?>
                        <?php if (!empty($row['description'])) : ?>
                            <span class="tbx-sub"><?php echo esc_html((string) $row['description']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><span class="tbx-pill"><?php echo esc_html((string) $row['calc_mode']); ?></span></td>
                    <td><span class="tbx-pill <?php echo !empty($row['active']) ? 'tbx-pill--teal' : 'tbx-pill--off'; ?>"><?php echo !empty($row['active']) ? 'oui' : 'non'; ?></span></td>
                    <td class="tbx-num"><?php echo $ready ? esc_html(number_format_i18n($cnt)) : '—'; ?></td>
                    <td class="tbx-num"><?php echo (int) $row['priority']; ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div class="tbx-grid tbx-grid--2">
        <div class="hdcp-panel">
            <h2>Recalcul manuel</h2>
            <p class="hdcp-help" style="margin-top:0">
                Moteur local JSON + MySQL. Les memberships ne contiennent que des <code>click_id</code> opaques.
                Config : <code><?php echo esc_html($configPath); ?></code>.
            </p>
            <form method="post" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
                <?php wp_nonce_field('tb_tracker_segments'); ?>
                <label style="display:flex;flex-direction:column;gap:4px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--hdcp-muted)">
                    Limit <input type="number" name="tb_segments_limit" value="50" min="1" max="200" style="width:86px">
                </label>
                <label style="display:flex;flex-direction:column;gap:4px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--hdcp-muted)">
                    Fenêtre (j) <input type="number" name="tb_segments_within_days" value="7" min="1" max="30" style="width:86px">
                </label>
                <label class="hdcp-check" style="display:inline-flex;align-items:center;gap:7px;padding:9px 12px;border-radius:10px;border:1px solid var(--hdcp-line);background:rgba(232,168,56,.1);color:#f0d08a;font-size:12px">
                    <input type="checkbox" name="tb_segments_dry_run" value="1"> Dry-run
                </label>
                <button type="submit" name="tb_segments_seed" class="hdcp-btn">Synchroniser config</button>
                <button type="submit" name="tb_segments_recalc" class="hdcp-btn hdcp-btn--primary">Recalculer</button>
            </form>
            <?php if (is_array($last) && !empty($last['at'])) : ?>
                <p class="hdcp-help" style="margin:14px 0 0">
                    Dernier recalcul écrit : <strong><?php echo esc_html((string) $last['at']); ?></strong>
                    · source <code><?php echo esc_html((string) ($last['source'] ?? 'manual')); ?></code>
                    · scannés <?php echo (int) ($last['clicks_scanned'] ?? 0); ?>
                    · +<?php echo (int) ($last['added'] ?? 0); ?>
                    / ~<?php echo (int) ($last['updated'] ?? 0); ?>
                    / −<?php echo (int) ($last['removed'] ?? 0); ?>
                    · prochain curseur <?php echo (int) get_option('tb_segments_recalc_cursor', 0); ?>
                </p>
            <?php endif; ?>
        </div>

        <div class="hdcp-panel">
            <h2>Recalcul automatique</h2>
            <form method="post">
                <?php wp_nonce_field('tb_tracker_segments'); ?>
                <label style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                    <input type="checkbox" name="tb_segments_recalc_enabled" value="1" <?php checked($recalcEnabled); ?>>
                    <span>Activer le cron (~30 min)</span>
                </label>
                <p class="hdcp-help">Batch LIMIT 150, fenêtre 7 jours, curseur rotatif pour ne pas repasser sur les mêmes clics, abandon si la charge à 1 min dépasse 25.</p>
                <button type="submit" name="tb_segments_save_flags" class="hdcp-btn">Enregistrer</button>
            </form>
            <p class="tbx-note" style="margin:16px 0 0">
                <strong>Export Meta Custom Audience :</strong> pas d’upload de PII hashée dans cette phase.
                Les memberships locales restent exportables en CSV plus tard.
            </p>
        </div>
    </div>
    <?php
}
