<?php
/**
 * CLI: generate trackingcomment + Espo payload preview for recent real FB leads.
 *
 * Usage:
 *   php wp-content/mu-plugins/hd-native-leads/cli-trackingcomment-examples.php
 *   php ... --post-test   # optional 1 smoke POST clearly marked TEST (creates a CRM lead!)
 */
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require dirname(__DIR__, 3) . '/wp-load.php';

$doPost = in_array('--post-test', $argv ?? [], true);

global $wpdb;
$leads = $wpdb->get_results(
    "SELECT id, form_key, name, phone, meta, created_at
     FROM {$wpdb->prefix}hd_leads
     ORDER BY id DESC
     LIMIT 400",
    ARRAY_A
);

$picked = [];
foreach ($leads as $row) {
    $meta = json_decode((string) $row['meta'], true);
    if (!is_array($meta)) {
        continue;
    }
    $camp = (string) ($meta['utm_campaign'] ?? '');
    $src = strtolower((string) ($meta['utm_source'] ?? ''));
    if ($camp === 'form_tn_phone_test' || strpos($src, 'ramy_test') !== false) {
        continue;
    }
    $tb = trim((string) ($meta['tbclid'] ?? ''));
    $fb = trim((string) ($meta['fbclid'] ?? ''));
    if ($tb === '' || ($fb === '' && $src !== 'fb' && $src !== 'facebook')) {
        continue;
    }
    // Prefer unique tbclids
    foreach ($picked as $p) {
        if (($p['_tb'] ?? '') === $tb) {
            continue 2;
        }
    }
    $meta['_lead_id'] = (int) $row['id'];
    $meta['lead_id'] = (int) $row['id'];
    $row['_meta'] = $meta;
    $row['_tb'] = $tb;
    $picked[] = $row;
    if (count($picked) >= 5) {
        break;
    }
}

if ($picked === []) {
    echo "Aucun lead FB avec tbclid trouvé.\n";
    exit(1);
}

$forms = get_option(HD_LEADS_OPTION, []);
if (!is_array($forms)) {
    $forms = [];
}

echo "=== Exemples trackingcomment (vrais leads FB) ===\n";
echo 'Espo URL: ' . hd_native_espo_leadcapture_url() . "\n\n";

foreach ($picked as $i => $row) {
    $meta = $row['_meta'];
    $form = $forms[$row['form_key']] ?? ['key' => $row['form_key'], 'project_id' => $meta['project_id'] ?? ''];
    $ctx = [
        'current_url' => $meta['payload']['current_url'] ?? home_url('/'),
        'wp_http_referer' => $meta['wp_http_referer'] ?? '',
        'page_id' => $meta['payload']['page_id'] ?? '',
    ];
    $phoneNorm = hd_native_normalize_tn_phone($row['phone']);
    $phone = !empty($phoneNorm['ok']) ? $phoneNorm['phone'] : preg_replace('/\D+/', '', (string) $row['phone']);

    $comment = hd_native_build_tracking_comment($row['_tb'], $ctx, $meta);
    $meta['trackingcomment'] = $comment;
    $espo = hd_native_build_espo_payload((string) $row['name'], (string) $phone, $form, $ctx, $meta);

    $n = $i + 1;
    echo "---------- EXEMPLE {$n} ----------\n";
    echo "Lead #{$row['id']} | {$row['created_at']} | form={$row['form_key']}\n";
    echo "Nom: {$row['name']}\n";
    echo "Tél (DB): {$row['phone']} → normalisé: {$phone}\n";
    echo "tbclid: {$row['_tb']}\n";
    echo "utm_campaign: " . ($meta['utm_campaign'] ?? '') . "\n";
    echo "utm_medium: " . ($meta['utm_medium'] ?? '') . "\n";
    echo "utm_content: " . ($meta['utm_content'] ?? '') . "\n";
    echo "project_id: " . ($espo['cProjetId'] ?? '') . "\n";
    echo "\n--- cTrackingcomment ---\n{$comment}\n";
    echo "\n--- Payload Espo (preview) ---\n";
    echo wp_json_encode($espo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
}

if ($doPost) {
    echo "=== SMOKE POST Espo (TEST — 1 seul lead artificiel) ===\n";
    $testBody = [
        'cProjetId' => (string) (($forms['f2307']['project_id'] ?? '6788d3529d0999868')),
        'phoneNumber' => '98123654',
        'firstName' => 'TEST Espo WP ' . gmdate('Y-m-d H:i'),
        'cMarketingsource' => 'TEST-ESPO-DIRECT-WP',
        'cLandingpage' => home_url('/?hd_espo_smoke=1'),
        'cFbclid' => '',
        'cFbpostid' => 'TEST',
        'cFbprofile' => '',
        'cWtid' => 'tb_test_smoke_' . substr(md5((string) microtime(true)), 0, 8),
        'cVisualid' => 'TEST-VISUAL',
        'cTrackingcomment' => "TEST automatique WP — ne pas appeler.\nPriorité FROID · smoke test LeadCapture.",
    ];
    echo wp_json_encode($testBody, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    $res = hd_native_send_espo_lead($testBody);
    echo 'Result: ' . wp_json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "(Pas de POST CRM — relancer avec --post-test pour 1 smoke test marqué TEST)\n";
}
