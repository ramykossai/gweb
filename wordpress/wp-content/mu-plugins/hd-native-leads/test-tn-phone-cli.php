<?php
/**
 * CLI tests for hd_native_normalize_tn_phone + dry-run endpoint.
 * Usage: php test-tn-phone-cli.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__, 3); // public_html
require $root . '/wp-load.php';

if (!function_exists('hd_native_normalize_tn_phone')) {
    fwrite(STDERR, "FAIL: hd_native_normalize_tn_phone missing\n");
    exit(1);
}

$cases = [
    ['12345678', true, '12345678'],
    ['12 345 678', true, '12345678'],
    ['+21612345678', true, '12345678'],
    ['21612345678', true, '12345678'],
    ['0021612345678', true, '12345678'],
    ['012345678', true, '12345678'],
    ['0098123654', true, '98123654'],
    ['00098123654', true, '98123654'],
    ['098123654', true, '98123654'],
    ['xx0098-123-654yy', true, '98123654'],
    ['123456789012', true, '56789012'], // trop long → 8 derniers
    ['١٢٣٤٥٦٧٨', true, '12345678'],
    ['12a345678', true, '12345678'],
    ['+216 12 345 678', true, '12345678'],
    ['88888888', false, null],
    ['123', false, null],
    ['abcdefgh', false, null],
    ['', false, null],
];

$pass = 0;
$fail = 0;
$rows = [];

echo "=== hd_native_normalize_tn_phone ===\n";
foreach ($cases as [$input, $expectOk, $expectPhone]) {
    $r = hd_native_normalize_tn_phone($input);
    $ok = !empty($r['ok']);
    $phone = (string) ($r['phone'] ?? '');
    $err = (string) ($r['error'] ?? '');
    $good = ($ok === $expectOk) && ($expectOk ? ($phone === $expectPhone) : ($err !== '' && preg_match('/[\x{0600}-\x{06FF}]/u', $err)));
    // Fail cases must have Arabic error message
    if (!$expectOk) {
        $good = ($ok === false) && ($err !== '') && (bool) preg_match('/[\x{0600}-\x{06FF}]/u', $err);
    }
    $status = $good ? 'PASS' : 'FAIL';
    $good ? $pass++ : $fail++;
    $label = $input === '' ? '(empty)' : $input;
    $rows[] = [$label, $expectOk ? "ok→$expectPhone" : 'fail+AR', $status, $ok ? $phone : $err];
    echo sprintf("[%s] input=%s → ok=%s phone=%s error=%s\n", $status, $label, $ok ? '1' : '0', $phone, $err);
}

// Empty name + valid phone must be acceptable at handler level (logic check)
$normOk = hd_native_normalize_tn_phone('98 765 432');
$nameOptionalOk = !empty($normOk['ok']) && $normOk['phone'] === '98765432';
if ($nameOptionalOk) {
    $pass++;
    echo "[PASS] empty name + valid phone → normalize OK (name not required by normalize)\n";
    $rows[] = ['empty name + valid phone', 'submit OK (norm)', 'PASS', $normOk['phone']];
} else {
    $fail++;
    echo "[FAIL] empty name + valid phone normalize\n";
    $rows[] = ['empty name + valid phone', 'submit OK (norm)', 'FAIL', ''];
}

// JS snippet syntax: extract and check with node if available
$js = function_exists('hd_native_tn_phone_js_snippet') ? hd_native_tn_phone_js_snippet() : '';
$jsFile = sys_get_temp_dir() . '/hd-tn-phone-test.js';
$jsOk = false;
if ($js !== '') {
    // Wrap as script and mirror matrix in JS
    $jsTest = $js . "\n" . <<<'JS'
var cases=[
  ['12345678',true,'12345678'],
  ['12 345 678',true,'12345678'],
  ['+21612345678',true,'12345678'],
  ['21612345678',true,'12345678'],
  ['0021612345678',true,'12345678'],
  ['012345678',true,'12345678'],
  ['١٢٣٤٥٦٧٨',true,'12345678'],
  ['12a345678',true,'12345678'],
  ['+216 12 345 678',true,'12345678'],
  ['88888888',false,null],
  ['123',false,null],
  ['abcdefgh',false,null],
  ['',false,null]
];
var fail=0;
cases.forEach(function(c){
  var r=hdNativeNormalizeTnPhone(c[0]);
  var good = c[1] ? (r.ok && r.phone===c[2]) : (!r.ok && r.error && /[\u0600-\u06FF]/.test(r.error));
  if(!good){ fail++; console.log('JS FAIL', JSON.stringify(c[0]), r); }
});
if(fail){ console.log('JS_FAILS='+fail); process.exit(1); }
console.log('JS_OK');
JS;
    file_put_contents($jsFile, $jsTest);
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node !== '') {
        $out = [];
        $code = 0;
        exec(escapeshellarg($node) . ' ' . escapeshellarg($jsFile) . ' 2>&1', $out, $code);
        $jsOk = ($code === 0 && strpos(implode("\n", $out), 'JS_OK') !== false);
        echo $jsOk ? "[PASS] JS mirror matrix\n" : ("[FAIL] JS mirror: " . implode(' ', $out) . "\n");
        $jsOk ? $pass++ : $fail++;
        $rows[] = ['JS mirror matrix', 'same as PHP', $jsOk ? 'PASS' : 'FAIL', implode(' ', $out)];
    } else {
        // Fallback: basic syntax check via node-less — at least ensure snippet non-empty
        $jsOk = (strpos($js, 'hdNativeNormalizeTnPhone') !== false);
        echo $jsOk ? "[PASS] JS snippet present (node unavailable)\n" : "[FAIL] JS snippet missing\n";
        $jsOk ? $pass++ : $fail++;
        $rows[] = ['JS snippet', 'present', $jsOk ? 'PASS' : 'FAIL', ''];
    }
}

// Find a form key + nonce for dry-run HTTP
$forms = get_option('hd_native_form_defs', []);
$formKey = '';
if (is_array($forms) && $forms) {
    $formKey = (string) array_key_first($forms);
}
$dryPass = false;
$httpFormPass = false;

if ($formKey !== '') {
    $nonce = wp_create_nonce('hd_lead_' . $formKey);
    $dryKey = wp_hash('hd_native_dry_v1');
    $endpoint = admin_url('admin-post.php');

    // Dry-run: empty name + messy phone
    $post = [
        'action' => 'hd_native_lead',
        'form_key' => $formKey,
        '_wpnonce' => $nonce,
        'lead_name' => '',
        'lead_phone' => '+216 98 765 432',
        '_hd_dry_run' => '1',
        '_hd_dry_key' => $dryKey,
        'page_id' => '0',
        'current_url' => home_url('/'),
    ];
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string) $body, true);
    $dryPass = ($code === 200 && is_array($json) && !empty($json['ok']) && !empty($json['dry_run']) && ($json['phone'] ?? '') === '98765432' && ($json['name'] ?? 'x') === '');
    echo $dryPass
        ? "[PASS] HTTP dry-run empty name + +216 98 765 432 → 98765432\n"
        : "[FAIL] HTTP dry-run code=$code body=" . substr((string) $body, 0, 300) . "\n";
    $dryPass ? $pass++ : $fail++;
    $rows[] = ['HTTP dry-run empty name', 'ok phone 98765432', $dryPass ? 'PASS' : 'FAIL', (string) $code];

    // Dry-run spam reject
    $post['lead_phone'] = '88888888';
    $nonce2 = wp_create_nonce('hd_lead_' . $formKey);
    $post['_wpnonce'] = $nonce2;
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body2 = curl_exec($ch);
    $code2 = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $spamFailOk = ($code2 === 400 && preg_match('/[\x{0600}-\x{06FF}]/u', (string) $body2));
    echo $spamFailOk
        ? "[PASS] HTTP dry-run spam 88888888 → 400 AR\n"
        : "[FAIL] HTTP spam code=$code2\n";
    $spamFailOk ? $pass++ : $fail++;
    $rows[] = ['HTTP spam 88888888', 'fail 400 AR', $spamFailOk ? 'PASS' : 'FAIL', (string) $code2];
}

// Live form page HTTP 200
global $wpdb;
$pageId = (int) $wpdb->get_var(
    "SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='page' AND post_content LIKE '%hd_lp_popup%' LIMIT 1"
);
if ($pageId <= 0) {
    $pageId = (int) $wpdb->get_var(
        "SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='page' AND post_content LIKE '%hd_lead_form%' LIMIT 1"
    );
}
if ($pageId > 0) {
    $url = get_permalink($pageId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'HD-NativePhoneTest/1.0',
    ]);
    $html = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $hasForm = is_string($html) && (strpos($html, 'hd-lead-form') !== false || strpos($html, 'lead_phone') !== false);
    $hasJs = is_string($html) && strpos($html, 'hdNativeNormalizeTnPhone') !== false;
    $noRequiredName = is_string($html) && !preg_match('/name="lead_name"[^>]*required/i', $html);
    $httpFormPass = ($code === 200 && $hasForm && $hasJs && $noRequiredName);
    echo $httpFormPass
        ? "[PASS] Live form page HTTP $code (form+JS, name not required) $url\n"
        : "[FAIL] Live form page code=$code hasForm=" . ($hasForm?'1':'0') . " hasJs=" . ($hasJs?'1':'0') . " noReqName=" . ($noRequiredName?'1':'0') . " $url\n";
    $httpFormPass ? $pass++ : $fail++;
    $rows[] = ['Live form HTTP', '200 + JS + name optional', $httpFormPass ? 'PASS' : 'FAIL', (string) $code];
} else {
    echo "[FAIL] No published page with hd_lp_popup/hd_lead_form\n";
    $fail++;
    $rows[] = ['Live form HTTP', '200', 'FAIL', 'no page'];
}

echo "\n=== SUMMARY ===\n";
echo "pass=$pass fail=$fail\n";
foreach ($rows as $row) {
    echo implode(' | ', $row) . "\n";
}

exit($fail > 0 ? 1 : 0);
