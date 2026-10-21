<?php
/**
 * Plugin Name: HD Native Leads
 * Description: Native lead forms — local store + Espo LeadCapture (trackingcomment).
 */
if (!defined('ABSPATH')) {
    exit;
}

define('HD_LEADS_OPTION', 'hd_native_form_defs');

$hd_native_leads_espo = __DIR__ . '/hd-native-leads/espo-and-tracking.php';
if (is_readable($hd_native_leads_espo)) {
    require_once $hd_native_leads_espo;
}
unset($hd_native_leads_espo);

$hd_native_leads_espo_status = __DIR__ . '/hd-native-leads/espo-status-sync.php';
if (is_readable($hd_native_leads_espo_status)) {
    require_once $hd_native_leads_espo_status;
}
unset($hd_native_leads_espo_status);

$hd_native_leads_admin = __DIR__ . '/hd-native-leads/admin-leads.php';
if (is_readable($hd_native_leads_admin)) {
    require_once $hd_native_leads_admin;
}
unset($hd_native_leads_admin);

$hd_native_customer = __DIR__ . '/hd-native-leads/customer-area.php';
if (is_readable($hd_native_customer)) {
    require_once $hd_native_customer;
}
unset($hd_native_customer);

$hd_native_drafts = __DIR__ . '/hd-native-leads/lead-drafts.php';
if (is_readable($hd_native_drafts)) {
    require_once $hd_native_drafts;
}
unset($hd_native_drafts);

$hd_native_espo_retry = __DIR__ . '/hd-native-leads/espo-retry.php';
if (is_readable($hd_native_espo_retry)) {
    require_once $hd_native_espo_retry;
}
unset($hd_native_espo_retry);

add_action('init', function () {
    global $wpdb;
    $table = $wpdb->prefix . 'hd_leads';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists === $table) {
        return;
    }
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        form_key VARCHAR(64) NOT NULL,
        name VARCHAR(190) NULL,
        phone VARCHAR(64) NULL,
        meta LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY form_key (form_key)
    ) $charset;");
});

/**
 * Register / update a form definition.
 * def: key, merci_slug, webhook (legacy n8n — ignored for primary Espo send),
 * project_id, form_title, forminator_form_id, labels...
 */
function hd_native_upsert_form(array $def) {
    $all = get_option(HD_LEADS_OPTION, []);
    if (!is_array($all)) {
        $all = [];
    }
    $key = sanitize_key($def['key'] ?? '');
    if ($key === '') {
        return false;
    }
    $prev = $all[$key] ?? [];
    $merciSlug = $def['merci_slug'] ?? ($prev['merci_slug'] ?? '');
    // Preserve underscores in merci slugs (sanitize_title would turn them into hyphens).
    $merciSlug = preg_replace('/[^A-Za-z0-9_\-]+/', '-', (string) $merciSlug);
    $merciSlug = trim($merciSlug, '-');
    $all[$key] = [
        'key' => $key,
        'title' => sanitize_text_field($def['title'] ?? ($prev['title'] ?? $key)),
        'form_title' => sanitize_text_field($def['form_title'] ?? ($prev['form_title'] ?? ($def['title'] ?? $key))),
        'merci_slug' => $merciSlug,
        'merci_page_id' => (int) ($def['merci_page_id'] ?? ($prev['merci_page_id'] ?? 0)),
        'merci_tb_id' => (int) ($def['merci_tb_id'] ?? ($prev['merci_tb_id'] ?? 0)),
        'merci_domain' => sanitize_key($def['merci_domain'] ?? ($prev['merci_domain'] ?? '')),
        'webhook' => esc_url_raw($def['webhook'] ?? ($prev['webhook'] ?? '')),
        'project_id' => sanitize_text_field($def['project_id'] ?? ($prev['project_id'] ?? '')),
        'forminator_form_id' => (int) ($def['forminator_form_id'] ?? ($prev['forminator_form_id'] ?? 0)),
        'name_label' => $def['name_label'] ?? ($prev['name_label'] ?? 'الإسم'),
        'phone_label' => $def['phone_label'] ?? ($prev['phone_label'] ?? 'رقم الهاتف'),
        'submit_label' => $def['submit_label'] ?? ($prev['submit_label'] ?? 'اطلب الآن'),
        'cta_label' => $def['cta_label'] ?? ($prev['cta_label'] ?? ''),
        'popup_warning' => $def['popup_warning'] ?? ($prev['popup_warning'] ?? 'الكمية المتبقية:'),
        'countdown_start' => (int) ($def['countdown_start'] ?? ($prev['countdown_start'] ?? 356)),
        'popup_title' => $def['popup_title'] ?? ($prev['popup_title'] ?? 'أطلب الآن واربح 50%'),
        'theme_accent' => sanitize_hex_color($def['theme_accent'] ?? ($prev['theme_accent'] ?? '')) ?: '#1a7f37',
        'theme_bg' => sanitize_hex_color($def['theme_bg'] ?? ($prev['theme_bg'] ?? '')) ?: '#0b0f14',
        'theme_btn_fg' => sanitize_hex_color($def['theme_btn_fg'] ?? ($prev['theme_btn_fg'] ?? '')) ?: '#ffffff',
    ];
    update_option(HD_LEADS_OPTION, $all, false);
    return true;
}

function hd_native_get_form($key) {
    $all = get_option(HD_LEADS_OPTION, []);
    $key = sanitize_key($key);
    return is_array($all) && isset($all[$key]) ? $all[$key] : null;
}

/**
 * Animated submit finger — injected on every native lead form (popup + inline).
 * Markup is only output inside hd_native_render_form(); CSS scoped under .hd-submit-wrap.
 */
function hd_native_submit_finger_markup() {
    // Flat solid pressing hand (white fill + black outline). pointer-events via CSS.
    return '<span class="hd-submit-finger" aria-hidden="true">'
        . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="44" height="44" focusable="false">'
        . '<g fill="#fff" stroke="#000" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" paint-order="stroke fill">'
        // Index (extended, pressing)
        . '<path d="M28 6c-2.6 0-4.7 2.1-4.7 4.7v26.5c0 1.7 1.4 3.1 3.1 3.1h3.2c1.7 0 3.1-1.4 3.1-3.1V10.7C32.7 8.1 30.6 6 28 6z"/>'
        // Middle
        . '<path d="M36.2 15.5c-2.1 0-3.8 1.7-3.8 3.8v15.2c0 1.4 1.1 2.5 2.5 2.5h2.6c1.4 0 2.5-1.1 2.5-2.5V19.3c0-2.1-1.7-3.8-3.8-3.8z"/>'
        // Ring
        . '<path d="M43.4 18.8c-1.9 0-3.4 1.5-3.4 3.4v11.5c0 1.3 1 2.3 2.3 2.3h2.2c1.3 0 2.3-1 2.3-2.3V22.2c0-1.9-1.5-3.4-3.4-3.4z"/>'
        // Pinky
        . '<path d="M49.8 21.5c-1.6 0-2.9 1.3-2.9 2.9v8.2c0 1.1.9 2 2 2h1.8c1.1 0 2-.9 2-2v-8.2c0-1.6-1.3-2.9-2.9-2.9z"/>'
        // Thumb + palm
        . '<path d="M23.5 32.2c-3.4-2.2-8-1.2-9.6 2.6-1.4 3.3.1 7.1 3.4 8.4l12.2 5c1.7.7 3.5 1.1 5.4 1.1h9.8c4 0 7.2-3.2 7.2-7.2v-2.8c0-2.1-1.7-3.8-3.8-3.8-1.1 0-2.1.5-2.8 1.2-.7-.5-1.6-.8-2.6-.8-1.2 0-2.3.5-3.1 1.4-.8-.7-1.9-1.1-3-1.1-1.3 0-2.5.6-3.3 1.5v-1.6c0-1.2-1-2.2-2.2-2.2h-4.6c-1.2 0-2.3.5-3.1 1.3z"/>'
        . '</g></svg></span>';
}

/**
 * Extract Tunisian local phone = exactly 8 western digits when possible.
 * Philosophy: if 8 usable digits can be recovered from messy input → accept.
 * Handles +216 / 216 / 00216 / 00 / leading 0s / spaces / letters / Arabic digits;
 * if still too long after cleanup → take the last 8 digits.
 *
 * @param mixed $raw
 * @return array{ok:bool,phone:string,error:string}
 */
function hd_native_normalize_tn_phone($raw) {
    $err = 'الرجاء إدخال رقم هاتف تونسي صحيح (8 أرقام)';

    $raw = trim((string) $raw);
    if ($raw === '') {
        return ['ok' => false, 'phone' => '', 'error' => $err];
    }

    // Arabic-Indic ٠-٩ + Extended Eastern Arabic-Indic ۰-۹ → 0-9
    $western = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $eastern = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $s = str_replace($arabic, $western, $raw);
    $s = str_replace($eastern, $western, $s);

    $digits = preg_replace('/\D+/', '', $s);
    if (!is_string($digits) || $digits === '') {
        return ['ok' => false, 'phone' => '', 'error' => $err];
    }

    // Drop country code if present (00216… / 216…)
    if (strpos($digits, '00216') === 0) {
        $digits = substr($digits, 5);
    } elseif (strpos($digits, '216') === 0 && strlen($digits) > 8) {
        $digits = substr($digits, 3);
    }

    // Drop leading zeros (00… / 0…) while longer than 8
    while (strlen($digits) > 8 && isset($digits[0]) && $digits[0] === '0') {
        $digits = substr($digits, 1);
    }

    // Still too long (extra digits typed) → keep the last 8
    if (strlen($digits) > 8) {
        $digits = substr($digits, -8);
    }

    if (strlen($digits) !== 8 || !ctype_digit($digits)) {
        return ['ok' => false, 'phone' => '', 'error' => $err];
    }

    // Obvious spam only (00000000, 88888888, …) — same single message
    if (preg_match('/^(\d)\1{7}$/', $digits)) {
        return ['ok' => false, 'phone' => '', 'error' => $err];
    }

    return ['ok' => true, 'phone' => $digits, 'error' => ''];
}

/**
 * Inline JS mirror of hd_native_normalize_tn_phone (same rules / same AR message).
 */
function hd_native_tn_phone_js_snippet() {
    return <<<'JS'
window.hdNativeNormalizeTnPhone=function(raw){
  var err='الرجاء إدخال رقم هاتف تونسي صحيح (8 أرقام)';
  raw=(raw==null?'':String(raw)).trim();
  if(!raw) return {ok:false,phone:'',error:err};
  var map={'٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9','۰':'0','۱':'1','۲':'2','۳':'3','۴':'4','۵':'5','۶':'6','۷':'7','۸':'8','۹':'9'};
  var s=raw.replace(/[٠-٩۰-۹]/g,function(ch){return map[ch]||ch;});
  var digits=s.replace(/\D+/g,'');
  if(!digits) return {ok:false,phone:'',error:err};
  if(digits.indexOf('00216')===0) digits=digits.slice(5);
  else if(digits.indexOf('216')===0 && digits.length>8) digits=digits.slice(3);
  while(digits.length>8 && digits.charAt(0)==='0') digits=digits.slice(1);
  if(digits.length>8) digits=digits.slice(-8);
  if(!/^\d{8}$/.test(digits)) return {ok:false,phone:'',error:err};
  if(/^(\d)\1{7}$/.test(digits)) return {ok:false,phone:'',error:err};
  return {ok:true,phone:digits,error:''};
};
JS;
}

/**
 * Build Forminator-compatible webhook JSON (dashes → underscores like Forminator for n8n).
 */
/**
 * Strip tracking params from a URL (Forminator embed_url style).
 */
function hd_native_strip_tracking_url($url) {
    $url = (string) $url;
    if ($url === '') {
        return $url;
    }
    $parts = wp_parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return $url;
    }
    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'];
    $path = $parts['path'] ?? '/';
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }
    foreach (array_keys($query) as $k) {
        if (preg_match('/^(utm_|fbclid|gclid|ttclid|msclkid|wbraid|gbraid|tbclid)/i', (string) $k)) {
            unset($query[$k]);
        }
    }
    $out = $scheme . '://' . $host . $path;
    if ($query) {
        $out .= '?' . http_build_query($query);
    }
    return $out;
}

function hd_native_build_forminator_payload(array $form, $name, $phone, array $ctx = []) {
    $now = time();
    $submission_time = gmdate('g:i:s a, \G\M\T+0000', $now);
    $date_dmy = gmdate('d/m/Y', $now);
    $entry_time = current_time('Y-m-d H:i:s');
    $current_url_raw = $ctx['current_url'] ?? home_url('/');
    // Forminator: hidden_3 / current_url = embed URL without UTM/fbclid
    $current_url = hd_native_strip_tracking_url($current_url_raw);
    $refer_url = $ctx['refer_url'] ?? '';
    if ($refer_url === '') {
        $refer_url = $current_url;
    } else {
        $refer_url = hd_native_strip_tracking_url($refer_url);
    }
    $ip = $ctx['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    $project = $form['project_id'] ?? '';
    $form_title = $form['form_title'] ?? ($form['title'] ?? $form['key']);

    // Forminator puts the FULL landing path+query (with UTM/fbclid) in _wp_http_referer
    $wp_http_referer = (string) ($ctx['wp_http_referer'] ?? '');
    if ($wp_http_referer === '' && $current_url_raw !== '') {
        $p = wp_parse_url($current_url_raw);
        if (!empty($p['path'])) {
            $wp_http_referer = $p['path'] . (!empty($p['query']) ? '?' . $p['query'] : '');
        }
    }

    // Same shape Forminator Webhook addon sends after replace_dashes_in_keys()
    return [
        'name_1' => $name,
        'phone_1' => $phone,
        'hidden_1' => $submission_time,
        'hidden_2' => $date_dmy,
        'hidden_3' => $current_url,
        'hidden_4' => $refer_url !== '' ? $refer_url : $current_url,
        'hidden_5' => $ip,
        'hidden_6' => $project,
        'referer_url' => '',
        '_wp_http_referer' => $wp_http_referer,
        'page_id' => (string) ($ctx['page_id'] ?? ''),
        'form_type' => 'default',
        'current_url' => $current_url,
        'render_id' => '0',
        'form_uid' => substr(md5($form['key'] . $now), 0, 13),
        '_forminator_user_ip' => $ip,
        'form_title' => $form_title,
        'entry_time' => $entry_time,
    ];
}

add_shortcode('hd_lead_form', function ($atts) {
    $atts = shortcode_atts(['key' => ''], $atts);
    $form = hd_native_get_form($atts['key']);
    if (!$form) {
        return '<!-- hd_lead_form: unknown key -->';
    }
    $action = esc_url(admin_url('admin-post.php'));
    $nonce = wp_create_nonce('hd_lead_' . $form['key']);
    $page_id = get_queried_object_id();
    ob_start();
    $formKeyEsc = esc_attr($form['key']);
    $hintId = 'hd-phone-hint-' . $formKeyEsc . '-' . wp_unique_id();
    // Collect drafts on all native lead forms (CRM auto-promote remains YM35-only in lead-drafts.php).
    $draftAttr = ' data-hd-draft="1"';
    ?>
    <div class="hd-lead-wrap" data-hd-form-key="<?php echo $formKeyEsc; ?>"<?php echo $draftAttr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
    <form class="hd-lead-form" method="post" action="<?php echo $action; ?>" dir="rtl" novalidate data-hd-form-key="<?php echo $formKeyEsc; ?>"<?php echo $draftAttr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
      <input type="hidden" name="action" value="hd_native_lead" />
      <input type="hidden" name="form_key" value="<?php echo $formKeyEsc; ?>" />
      <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>" />
      <input type="hidden" name="page_id" value="<?php echo esc_attr((string) $page_id); ?>" />
      <input type="hidden" name="current_url" value="" />
      <input type="hidden" name="refer_url" value="" />
      <input type="hidden" name="wp_http_referer" value="" />
      <input type="hidden" name="utm_source" value="" />
      <input type="hidden" name="utm_medium" value="" />
      <input type="hidden" name="utm_campaign" value="" />
      <input type="hidden" name="utm_content" value="" />
      <input type="hidden" name="utm_term" value="" />
      <input type="hidden" name="fbclid" value="" />
      <input type="hidden" name="tbclid" value="" />
      <p class="hd-field hd-field--name">
        <label><?php echo esc_html($form['name_label']); ?></label>
        <input name="lead_name" type="text" autocomplete="name" />
      </p>
      <p class="hd-field hd-field--phone">
        <label><?php echo esc_html($form['phone_label']); ?> <span class="hd-req" aria-hidden="true">*</span></label>
        <input name="lead_phone" type="tel" inputmode="numeric" autocomplete="tel" dir="ltr"
          placeholder="20 123 456" maxlength="14" aria-describedby="<?php echo esc_attr($hintId); ?>" />
        <small class="hd-phone-hint" id="<?php echo esc_attr($hintId); ?>">للإرسال إكتب رقم الهاتف ، 8 أرقام تونسية</small>
        <span class="hd-phone-error" hidden dir="rtl" aria-live="polite"></span>
      </p>
      <p class="hd-field hd-submit-wrap">
        <button type="submit" class="hd-submit-btn" disabled><?php echo esc_html($form['submit_label']); ?></button>
        <?php echo hd_native_submit_finger_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup ?>
      </p>
    </form>
    <div class="hd-lead-done" hidden dir="rtl" role="status">
      <p class="hd-lead-done__title">تم إرسال طلبك بنجاح</p>
      <p class="hd-lead-done__sub">سيتصل بك الأخصائي في أقرب الآجال.</p>
    </div>
    </div>
    <script>
    <?php echo hd_native_tn_phone_js_snippet(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    (function(){
      var STORE='hd_first_touch_path';
      var DONE_KEY='hd_lead_done_v1';
      // TEMP OFF (2026-08-02): anti-doublon 4h bloquait des LP / vérificateurs à tort
      // (markFormDone avant succès serveur + localStorage partagé). Réactiver après fix.
      var DONE_TTL=0;
      var ANTI_DUP_ENABLED=false;
      try{
        if(location.search && /utm_|fbclid|gclid|utm_id/i.test(location.search)){
          sessionStorage.setItem(STORE, location.pathname + location.search + (location.hash||''));
        }
      }catch(e){}
      function q(n){var m=location.search.match(new RegExp('[?&]'+n+'=([^&]*)'));return m?decodeURIComponent(m[1].replace(/\+/g,' ')):'';}
      function cookieGet(n){
        try{
          var m=document.cookie.match(new RegExp('(?:^|; )'+n.replace(/[$()*+.?[\\\]^{|}]/g,'\\$&')+'=([^;]*)'));
          return m?decodeURIComponent(m[1]):'';
        }catch(e){ return ''; }
      }
      function firstTouch(){
        try{ var s=sessionStorage.getItem(STORE); if(s) return s; }catch(e){}
        return location.pathname + location.search + (location.hash||'');
      }
      function embedUrl(){
        try{
          var u=new URL(location.href);
          ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','utm_id','fbclid','gclid','ttclid','msclkid','wbraid','gbraid','tbclid'].forEach(function(k){ u.searchParams.delete(k); });
          return u.origin + u.pathname + (u.searchParams.toString()?('?'+u.searchParams.toString()):'');
        }catch(e){ return location.href; }
      }
      function readDoneMap(){
        try{ return JSON.parse(localStorage.getItem(DONE_KEY)||'{}')||{}; }catch(e){ return {}; }
      }
      function pruneDone(map){
        var now=Date.now(), out={}, k, ts;
        for(k in map){
          if(!Object.prototype.hasOwnProperty.call(map,k)) continue;
          ts=parseInt(map[k],10)||0;
          if(ts && (now-ts)<DONE_TTL) out[k]=ts;
        }
        return out;
      }
      function saveDone(map){
        try{ localStorage.setItem(DONE_KEY, JSON.stringify(pruneDone(map))); }catch(e){}
      }
      function isFormDone(key){
        if(!ANTI_DUP_ENABLED) return false;
        var m=pruneDone(readDoneMap());
        saveDone(m);
        return !!(key && m[key]);
      }
      function markFormDone(key){
        if(!ANTI_DUP_ENABLED || !key) return;
        var m=pruneDone(readDoneMap());
        m[key]=Date.now();
        saveDone(m);
      }
      function formatPhoneDisplay(digits){
        digits=String(digits||'').replace(/\D+/g,'').slice(0,8);
        if(digits.length<=2) return digits;
        if(digits.length<=5) return digits.slice(0,2)+' '+digits.slice(2);
        return digits.slice(0,2)+' '+digits.slice(2,5)+' '+digits.slice(5);
      }
      function showDoneUI(wrap){
        if(!wrap) return;
        var form=wrap.querySelector('.hd-lead-form');
        var done=wrap.querySelector('.hd-lead-done');
        if(form) form.hidden=true;
        if(done) done.hidden=false;
      }
      function bindPhone(f){
        var phone=f.querySelector('[name="lead_phone"]');
        if(!phone || phone.getAttribute('data-hd-tn-bound')) return;
        phone.setAttribute('data-hd-tn-bound','1');
        var errEl=f.querySelector('.hd-phone-error');
        var btn=f.querySelector('.hd-submit-btn');
        var formKey=(f.getAttribute('data-hd-form-key')|| (f.querySelector('[name="form_key"]')||{}).value || '').toString();
        var msgEmpty='إكتب رقم الهاتف';
        var msgInvalid='الرجاء إدخال رقم هاتف تونسي صحيح (8 أرقام)';
        var submitting=false;
        var toastTimer=null;
        function ensureToast(){
          var t=document.getElementById('hd-phone-toast');
          if(t) return t;
          t=document.createElement('div');
          t.id='hd-phone-toast';
          t.className='hd-phone-toast';
          t.setAttribute('role','alert');
          t.setAttribute('aria-live','assertive');
          t.hidden=true;
          t.innerHTML='<span class="hd-phone-toast__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" focusable="false"><path fill="currentColor" d="M12 2L1 21h22L12 2zm0 4.5l7.5 13h-15L12 6.5zM11 10v5h2v-5h-2zm0 6v2h2v-2h-2z"/></svg></span><span class="hd-phone-toast__msg"></span>';
          document.body.appendChild(t);
          return t;
        }
        function showToast(msg){
          var t=ensureToast();
          var msgEl=t.querySelector('.hd-phone-toast__msg');
          if(msgEl) msgEl.textContent=msg||'';
          t.hidden=!msg;
          if(toastTimer){ clearTimeout(toastTimer); toastTimer=null; }
          if(msg){
            toastTimer=setTimeout(function(){ t.hidden=true; }, 3200);
          }
        }
        function showErr(msg){
          // Toast only — never HTML5 setCustomValidity while typing (blocks caret / native bubbles)
          if(errEl){ errEl.hidden=true; errEl.textContent=''; }
          showToast(msg||'');
        }
        function syncBtn(){
          if(!btn || submitting) return;
          var r=window.hdNativeNormalizeTnPhone(phone.value);
          btn.disabled=!r.ok;
        }
        function applyNorm(write, showError){
          var r=window.hdNativeNormalizeTnPhone(phone.value);
          if(r.ok){
            if(write) phone.value=formatPhoneDisplay(r.phone);
            showErr('');
            syncBtn();
            return true;
          }
          if(showError) showErr(r.error||msgInvalid);
          else showErr('');
          syncBtn();
          return false;
        }
        phone.addEventListener('input', function(){
          showErr('');
          // Soft while typing: Arabic/Persian digits → Latin only. No forced spaces (caret-friendly).
          var map={'٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9','۰':'0','۱':'1','۲':'2','۳':'3','۴':'4','۵':'5','۶':'6','۷':'7','۸':'8','۹':'9'};
          var s=String(phone.value||'').replace(/[٠-٩۰-۹]/g,function(ch){return map[ch]||ch;});
          if(s!==phone.value){
            var pos=typeof phone.selectionStart==='number'?phone.selectionStart:s.length;
            phone.value=s;
            try{ phone.setSelectionRange(pos,pos); }catch(e){}
          }
          syncBtn();
        });
        phone.addEventListener('blur', function(){
          // Format spaces only on blur when already valid — never toast on incomplete blur
          if(phone.value.trim()) applyNorm(true, false);
        });
        // Bouton grisé : disabled ne fire pas click → on capte le tap sur le wrap
        var submitWrap=f.querySelector('.hd-submit-wrap')||(btn&&btn.parentElement);
        if(submitWrap && !submitWrap.getAttribute('data-hd-disabled-tap')){
          submitWrap.setAttribute('data-hd-disabled-tap','1');
          submitWrap.addEventListener('click', function(ev){
            if(!btn || !btn.disabled || submitting) return;
            ev.preventDefault();
            ev.stopPropagation();
            showErr(msgEmpty);
            try{ phone.focus(); }catch(e){}
          });
        }
        f.addEventListener('submit', function(ev){
          if(submitting){ ev.preventDefault(); return; }
          if(isFormDone(formKey)){
            ev.preventDefault();
            showDoneUI(f.closest('.hd-lead-wrap'));
            return;
          }
          if(!phone.value.trim()){
            ev.preventDefault();
            showErr(msgEmpty);
            phone.focus();
            syncBtn();
            return;
          }
          var r=window.hdNativeNormalizeTnPhone(phone.value);
          if(!r.ok){
            ev.preventDefault();
            showErr(r.error||msgInvalid);
            phone.focus();
            syncBtn();
            return;
          }
          // Anti double-clic rapide (même form) — autres form_key OK
          submitting=true;
          phone.value=r.phone; // POST = 8 digits nets
          markFormDone(formKey);
          if(btn){ btn.disabled=true; btn.setAttribute('aria-busy','true'); }
        });
        phone.addEventListener('invalid', function(ev){
          ev.preventDefault();
          // Only on native invalid (rare with novalidate) — toast, no stuck validity
          showErr(phone.value.trim()?msgInvalid:msgEmpty);
        });
        syncBtn();
      }
      document.querySelectorAll('.hd-lead-wrap').forEach(function(wrap){
        var key=wrap.getAttribute('data-hd-form-key')||'';
        if(key && isFormDone(key)){
          showDoneUI(wrap);
          return;
        }
        var f=wrap.querySelector('.hd-lead-form');
        if(!f || f.getAttribute('data-hd-bound')) return;
        f.setAttribute('data-hd-bound','1');
        var cu=f.querySelector('[name="current_url"]'); if(cu) cu.value=embedUrl();
        var ru=f.querySelector('[name="refer_url"]'); if(ru) ru.value=embedUrl();
        var wr=f.querySelector('[name="wp_http_referer"]'); if(wr) wr.value=firstTouch();
        ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','fbclid'].forEach(function(k){
          var el=f.querySelector('[name="'+k+'"]'); if(el) el.value=q(k);
        });
        var tb=f.querySelector('[name="tbclid"]');
        if(tb){ tb.value=q('tbclid') || cookieGet('tbclid') || ''; }
        bindPhone(f);
      });
      // Auto-focus téléphone à l’ouverture popup (pas au chargement page)
      document.querySelectorAll('[data-hd-open-form]').forEach(function(el){
        if(el.getAttribute('data-hd-focus-bound')) return;
        el.setAttribute('data-hd-focus-bound','1');
        el.addEventListener('click', function(){
          setTimeout(function(){
            var openPopup=document.querySelector('.hd-popup.open');
            if(!openPopup) openPopup=document.querySelector('.hd-popup');
            if(!openPopup) return;
            var done=openPopup.querySelector('.hd-lead-done');
            if(done && !done.hidden) return;
            var phone=openPopup.querySelector('.hd-lead-form [name="lead_phone"]');
            if(phone){ try{ phone.focus(); }catch(e){} }
          }, 80);
        });
      });
    })();
    </script>    <style>
    .hd-phone-error{display:none!important}
    .hd-phone-error[hidden]{display:none!important}
    .hd-phone-toast{
      position:fixed;left:50%;bottom:calc(72px + env(safe-area-inset-bottom,0px));
      transform:translateX(-50%);
      z-index:10050;
      display:flex;align-items:center;gap:10px;
      max-width:min(92vw,360px);
      padding:.7rem .95rem;
      border-radius:12px;
      background:#facc15;
      color:#1a1a1a;
      font-family:Cairo,Roboto,"Noto Sans Arabic",Tahoma,sans-serif;
      font-size:15px;font-weight:800;line-height:1.35;
      direction:rtl;text-align:right;
      box-shadow:0 8px 28px rgba(0,0,0,.35),0 0 0 1px rgba(161,98,7,.35);
      animation:hd-toast-in .22s ease-out;
    }
    .hd-phone-toast[hidden]{display:none!important}
    .hd-phone-toast__icon{flex-shrink:0;color:#a16207;display:flex;align-items:center}
    .hd-phone-toast__msg{flex:1}
    @keyframes hd-toast-in{from{opacity:0;transform:translateX(-50%) translateY(10px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
    .hd-phone-hint{display:block;margin-top:.45rem;padding:.4rem .55rem;font-size:12px;font-weight:650;line-height:1.45;text-align:center;border-radius:8px;opacity:1;color:#fff!important;background:rgba(0,0,0,.42);text-shadow:0 1px 1px rgba(0,0,0,.35)}
    .hd-inline-form:not(.hd-inline-form--colibri) .hd-phone-hint{color:#111!important;background:rgba(0,0,0,.07);text-shadow:none}
    .hd-field--name label{opacity:.72}
    .hd-field--name input{opacity:.92}
    .hd-field--phone label{font-weight:700}
    .hd-field label .hd-req{color:inherit;font-weight:inherit}
    .hd-submit-btn:disabled{opacity:.82;cursor:not-allowed;filter:none;pointer-events:none}
    .hd-popup-form-card .hd-submit-btn:disabled,
    .hd-inline-form.hd-inline-form--colibri .hd-submit-btn:disabled{background:#fff!important;color:#64748b!important;box-shadow:none!important}
    .hd-submit-wrap{cursor:pointer}
    .hd-lead-done{text-align:center;padding:1.1rem .75rem;border-radius:12px;background:rgba(0,0,0,.28)}
    .hd-lead-done__title{margin:0 0 .4rem;font-size:1.15rem;font-weight:800}
    .hd-lead-done__sub{margin:0;font-size:.95rem;opacity:.9;line-height:1.45}
    .hd-lead-form[hidden]{display:none!important}
    .hd-lead-done[hidden]{display:none!important}
    </style>
    <?php
    return ob_get_clean();
});

/**
 * Shortcode: full LP shell (slides + sticky CTA + glowing popup with counter).
 * [hd_lp key="hemorrelief"] ...slide imgs in content or use slides meta...
 * Prefer building via rebuild script into post_content.
 */
add_shortcode('hd_lp_popup', function ($atts) {
    $atts = shortcode_atts(['key' => '', 'accent' => '', 'bg' => '', 'btn_fg' => '', 'cta' => '', 'title' => ''], $atts);
    $form = hd_native_get_form($atts['key']);
    if (!$form) {
        return '';
    }
    $warning = $form['popup_warning'] ?? 'الكمية المتبقية:';
    // Page-level title= overrides shared form popup_title (e.g. TORO LP1 vs LP3 on f172).
    $title = trim((string) ($atts['title'] ?? ''));
    if ($title === '') {
        $title = $form['popup_title'] ?? 'أطلب الآن واربح 50%';
    }
    $cta = trim((string) ($atts['cta'] ?: ($form['cta_label'] ?? '') ?: ($form['submit_label'] ?? 'إشتري من هنا')));
    if ($cta === '') {
        $cta = 'إشتري من هنا';
    }
    $accent = sanitize_hex_color($atts['accent'] ?: ($form['theme_accent'] ?? '')) ?: '#1a7f37';
    $bg = sanitize_hex_color($atts['bg'] ?: ($form['theme_bg'] ?? '')) ?: '#0b0f14';
    $btnFg = sanitize_hex_color($atts['btn_fg'] ?: ($form['theme_btn_fg'] ?? '')) ?: '#ffffff';
    $style = '--hd-accent:' . $accent . ';--hd-bg:' . $bg . ';--hd-btn-fg:' . $btnFg . ';--hd-glow:' . $accent . ';';

    // Durée d'offre stable mais différente par LP (hash de la clé formulaire).
    $seed = abs(crc32($form['key'] . '|offer'));
    $offerH = 2 + ($seed % 6);          // 2–7 heures
    $offerM = (int) (($seed >> 8) % 60);
    $offerS = (int) (($seed >> 16) % 60);
    $offerSecs = ($offerH * 3600) + ($offerM * 60) + $offerS;

    // Stock restant: valeur de départ réaliste & différente par LP (pas un compte à rebours 1/s).
    $stockSeed = abs(crc32($form['key'] . '|stock'));
    $stockStart = 17 + (int) ($stockSeed % 31); // 17–47
    $stockFloor = 3 + (int) (($stockSeed >> 5) % 5); // 3–7
    // Visiteurs fictifs sur le produit (base différente par LP).
    $visitSeed = abs(crc32($form['key'] . '|visitors'));
    $visitorsBase = 140 + (int) ($visitSeed % 420); // 140–559

    ob_start();
    $fk = esc_attr($form['key']);
    ?>
    <div class="hd-offer-bar hd-offer-glow hd-colibri-ui" style="<?php echo esc_attr($style); ?>" data-hd-offer-bar data-hd-offer-key="<?php echo $fk; ?>" data-hd-offer-secs="<?php echo esc_attr((string) $offerSecs); ?>">
      <div class="hd-offer-bar-shine" aria-hidden="true"></div>
      <div class="hd-offer-bar-inner">
        <span class="hd-offer-pulse" aria-hidden="true"></span>
        <p class="hd-offer-label">العرض ينتهي خلال</p>
        <div class="hd-offer-timer" dir="ltr">
          <div class="hd-offer-unit"><b data-hd-offer-h><?php echo esc_html(sprintf('%02d', $offerH)); ?></b><small>ساعة</small></div>
          <span class="hd-offer-sep">:</span>
          <div class="hd-offer-unit"><b data-hd-offer-m><?php echo esc_html(sprintf('%02d', $offerM)); ?></b><small>دقيقة</small></div>
          <span class="hd-offer-sep">:</span>
          <div class="hd-offer-unit"><b data-hd-offer-s><?php echo esc_html(sprintf('%02d', $offerS)); ?></b><small>ثانية</small></div>
        </div>
      </div>
    </div>

    <?php /* Sticky dock: CTA only — jauge/visiteurs dans popup + sous formulaires LP */ ?>
    <div class="hd-lp-dock hd-colibri-ui" style="<?php echo esc_attr($style); ?>" data-hd-dock data-hd-dock-key="<?php echo $fk; ?>">
      <div class="hd-lp-cta hd-lp-cta--dock">
        <button type="button" data-hd-open-form class="hd-cta-glow"><?php echo esc_html($cta); ?></button>
      </div>
    </div>

    <div class="hd-popup hd-colibri-ui" role="dialog" aria-modal="true" style="<?php echo esc_attr($style); ?>" data-hd-popup-key="<?php echo $fk; ?>">
      <div class="hd-popup-inner hd-popup-glow">
        <button type="button" class="hd-popup-close" data-hd-close aria-label="إغلاق">×</button>
        <div class="hd-popup-head">
          <p class="hd-popup-title"><?php echo esc_html($title); ?></p>
        </div>
        <div class="hd-popup-form-card">
          <?php echo do_shortcode('[hd_lead_form key="' . esc_attr($form['key']) . '"]'); ?>
        </div>
        <div class="hd-lp-social hd-lp-social--in-popup" data-hd-social>
          <div class="hd-lp-social__stock">
            <p class="hd-lp-social__warn"><?php echo esc_html($warning); ?> <span class="hd-countdown" data-hd-stock data-hd-stock-start="<?php echo esc_attr((string) $stockStart); ?>" data-hd-stock-floor="<?php echo esc_attr((string) $stockFloor); ?>" data-hd-stock-key="<?php echo $fk; ?>"><?php echo esc_html((string) $stockStart); ?></span></p>
            <div class="hd-countdown-bar hd-countdown-bar--dock"><span data-hd-stock-bar style="width:100%"></span></div>
          </div>
          <div class="hd-lp-social__visitors" data-hd-visitors data-hd-visitors-base="<?php echo esc_attr((string) $visitorsBase); ?>" data-hd-visitors-key="<?php echo $fk; ?>">
            <span class="hd-visitors-dot" aria-hidden="true"></span>
            <p class="hd-visitors-text">يشاهد هذا المنتج الآن <strong data-hd-visitors-n><?php echo esc_html((string) $visitorsBase); ?></strong> زائر</p>
          </div>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
});

add_action('admin_post_nopriv_hd_native_lead', 'hd_native_handle_lead');
add_action('admin_post_hd_native_lead', 'hd_native_handle_lead');

function hd_native_handle_lead() {
    $key = sanitize_key($_POST['form_key'] ?? '');
    $form = hd_native_get_form($key);
    if (!$form || !wp_verify_nonce($_POST['_wpnonce'] ?? '', 'hd_lead_' . $key)) {
        wp_die('Invalid form', 400);
    }
    // Name is optional (LP Tunisie) — phone is required and always normalized.
    $name = sanitize_text_field(wp_unslash($_POST['lead_name'] ?? ''));
    $norm = hd_native_normalize_tn_phone(wp_unslash($_POST['lead_phone'] ?? ''));
    if (empty($norm['ok'])) {
        $msg = (string) ($norm['error'] ?? 'الرجاء إدخال رقم هاتف تونسي صحيح (8 أرقام)');
        status_header(400);
        nocache_headers();
        wp_die(
            '<p dir="rtl" lang="ar" style="font-family:Tahoma,Arial,sans-serif;font-size:1.15rem;text-align:center;padding:2rem">' . esc_html($msg) . '</p>',
            $msg,
            ['response' => 400, 'back_link' => true]
        );
    }
    $phone = (string) $norm['phone'];

    // Dry-run for automated tests: no DB insert, no Espo POST, no redirect.
    $dryKey = (string) ($_POST['_hd_dry_key'] ?? '');
    $expectedDry = function_exists('wp_hash') ? wp_hash('hd_native_dry_v1') : '';
    if (!empty($_POST['_hd_dry_run']) && $expectedDry !== '' && hash_equals($expectedDry, $dryKey)) {
        nocache_headers();
        wp_send_json([
            'ok' => true,
            'dry_run' => true,
            'form_key' => $key,
            'name' => $name,
            'phone' => $phone,
        ]);
    }

    $current_url = esc_url_raw($_POST['current_url'] ?? '');
    if ($current_url === '') {
        $current_url = esc_url_raw($_SERVER['HTTP_REFERER'] ?? home_url('/'));
    }
    $refer_url = esc_url_raw($_POST['refer_url'] ?? '');
    $wp_http_referer = wp_unslash($_POST['wp_http_referer'] ?? '');
    // Fallback: derive path+query from current_url if JS missed it
    if ($wp_http_referer === '' && $current_url !== '') {
        $p = wp_parse_url($current_url);
        if (!empty($p['path'])) {
            $wp_http_referer = $p['path'] . (!empty($p['query']) ? '?' . $p['query'] : '');
        }
    }
    // Never send admin-post.php as referer
    if ($wp_http_referer !== '' && stripos($wp_http_referer, 'admin-post.php') !== false) {
        $wp_http_referer = '';
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    $ctx = [
        'current_url' => $current_url,
        'refer_url' => $refer_url,
        'ip' => $ip,
        'page_id' => sanitize_text_field($_POST['page_id'] ?? ''),
        'wp_http_referer' => $wp_http_referer,
    ];

    $payload = hd_native_build_forminator_payload($form, $name, $phone, $ctx);
    $payload = apply_filters('hd_native_lead_payload', $payload, $form, $ctx);

    $meta = [
        'utm_source' => sanitize_text_field($_POST['utm_source'] ?? ''),
        'utm_medium' => sanitize_text_field($_POST['utm_medium'] ?? ''),
        'utm_campaign' => sanitize_text_field($_POST['utm_campaign'] ?? ''),
        'utm_content' => sanitize_text_field($_POST['utm_content'] ?? ''),
        'utm_term' => sanitize_text_field($_POST['utm_term'] ?? ''),
        'fbclid' => sanitize_text_field($_POST['fbclid'] ?? ''),
        'tbclid' => sanitize_text_field($_POST['tbclid'] ?? ''),
        'wp_http_referer' => $wp_http_referer,
        'project_id' => $form['project_id'] ?? '',
        'form_key' => $key,
        'payload' => $payload,
    ];
    $meta = apply_filters('hd_native_lead_meta', $meta, $form, $ctx);
    // Explicit test marker (CLI / QA) — never set from public LP forms by default.
    if (!empty($_POST['_hd_test']) && (string) $_POST['_hd_test'] === '1') {
        $meta['test'] = 1;
        $meta['test_tag'] = 'ramy test';
    }
    // Boutique channel flag from form (white/commande) — never set on classic LP.
    if (!empty($_POST['hd_channel']) && sanitize_key((string) $_POST['hd_channel']) === 'boutique') {
        $meta['hd_channel'] = 'boutique';
        $meta['is_boutique'] = 1;
    }
    // Enrich qty / prix / remise from catalogue (best-effort, boutique only).
    if (function_exists('hd_native_enrich_boutique_meta')) {
        $meta = hd_native_enrich_boutique_meta($meta, $form, $ctx);
    }
    // Keep payload inside meta in sync after filters
    if (isset($meta['payload']) && is_array($meta['payload'])) {
        $payload = $meta['payload'];
    } else {
        $meta['payload'] = $payload;
    }

    // Product label for tracking comment + tracker lead event (local only; not a new Espo field).
    if (function_exists('hd_native_resolve_product_name')) {
        $clickForProduct = null;
        $tbTmp = trim((string) ($meta['tbclid'] ?? ''));
        if ($tbTmp !== '' && function_exists('hd_native_tracker_snapshot')) {
            $snapTmp = hd_native_tracker_snapshot($tbTmp);
            $clickForProduct = is_array($snapTmp['click'] ?? null) ? $snapTmp['click'] : null;
        }
        $meta['product'] = hd_native_resolve_product_name($form, $meta, $clickForProduct, $ctx);
    }

    // Tracking comment: once at conversion, frozen in meta (and Espo).
    $tbclidForComment = (string) ($meta['tbclid'] ?? '');
    if (function_exists('hd_native_build_tracking_comment')) {
        $meta['trackingcomment'] = hd_native_build_tracking_comment($tbclidForComment, $ctx, $meta);
    } else {
        $meta['trackingcomment'] = '';
    }
    // Persist IP geo snapshot on the lead (from tracker click, long-term).
    if ($tbclidForComment !== '' && function_exists('tb_tracker_get_click_geo')) {
        $geoSnap = tb_tracker_get_click_geo($tbclidForComment);
        if (is_array($geoSnap)) {
            $meta['geo'] = $geoSnap;
        }
    }

    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'hd_leads', [
        'form_key' => $key,
        'name' => $name,
        'phone' => $phone,
        'meta' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
        'created_at' => current_time('mysql'),
    ]);
    $leadId = (int) $wpdb->insert_id;
    do_action('hd_native_lead_saved', $leadId, $form, $ctx, $meta);

    // Espo: never lose the lead — short attempt, then async retries AFTER merci redirect.
    $espoNeedsAsync = false;
    if ($leadId > 0 && function_exists('hd_native_build_espo_payload') && function_exists('hd_native_espo_attempt_lead')) {
        try {
            $espoBody = hd_native_build_espo_payload($name, $phone, $form, $ctx, $meta);
            $espoBody = apply_filters('hd_native_espo_payload', $espoBody, $form, $ctx, $meta, $leadId);
            $meta['espo_body'] = $espoBody;
            $meta['espo'] = [
                'ok' => false,
                'pending' => true,
                'code' => 0,
                'at' => current_time('mysql'),
                'attempts' => 0,
                'via' => 'request',
            ];
            hd_native_espo_persist_meta($leadId, $meta);

            // Fast first try (4s) — if host kills the request, pending+cron still covers it.
            $ok = hd_native_espo_attempt_lead($leadId, 'request');
            if (!$ok) {
                $espoNeedsAsync = true;
                if (function_exists('hd_native_espo_queue_retry')) {
                    hd_native_espo_queue_retry($leadId);
                }
            }
            // Refresh meta for merci filters
            $pack = hd_native_espo_load_lead($leadId);
            if ($pack) {
                $meta = $pack['meta'];
            }
        } catch (Throwable $e) {
            $meta['espo'] = [
                'ok' => false,
                'pending' => true,
                'code' => 0,
                'at' => current_time('mysql'),
                'attempts' => (int) ($meta['espo']['attempts'] ?? 0),
                'via' => 'request_exception',
                'err' => substr($e->getMessage(), 0, 180),
            ];
            hd_native_espo_persist_meta($leadId, $meta);
            $espoNeedsAsync = true;
            if (function_exists('hd_native_espo_queue_retry')) {
                hd_native_espo_queue_retry($leadId);
            }
        }
    } elseif ($leadId > 0 && function_exists('hd_native_build_espo_payload') && function_exists('hd_native_send_espo_lead')) {
        // Fallback if retry module missing
        $espoBody = hd_native_build_espo_payload($name, $phone, $form, $ctx, $meta);
        $espoBody = apply_filters('hd_native_espo_payload', $espoBody, $form, $ctx, $meta, $leadId);
        $espoResult = hd_native_send_espo_lead($espoBody);
        $meta['espo'] = [
            'ok' => !empty($espoResult['ok']),
            'pending' => empty($espoResult['ok']),
            'code' => (int) ($espoResult['code'] ?? 0),
            'at' => current_time('mysql'),
        ];
        $wpdb->update(
            $wpdb->prefix . 'hd_leads',
            ['meta' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE)],
            ['id' => $leadId],
            ['%s'],
            ['%d']
        );
    }

    // Prefer source ?page_id= (same as HD/Biomax — FB custom conversions use these page_id URLs).
    // Fallback: TB page permalink / slug.
    $merci = home_url('/');
    $srcMerci = (int) ($form['merci_page_id'] ?? 0);
    $tbMerci = (int) ($form['merci_tb_id'] ?? 0);
    if ($srcMerci > 0) {
        $map = get_option('hd_source_page_map', []);
        if (is_array($map) && !empty($map[$srcMerci])) {
            $mappedTb = (int) $map[$srcMerci];
            // If this form's merci page owns the mapped source id, use ?page_id=
            if ($tbMerci <= 0 || $mappedTb === $tbMerci) {
                $merci = home_url('/?page_id=' . $srcMerci);
            } elseif ($tbMerci > 0) {
                // Collision: another page owns this source id — use this form's merci permalink/slug
                $merci = get_permalink($tbMerci) ?: home_url('/' . trim((string) ($form['merci_slug'] ?? ''), '/') . '/');
            }
        } elseif ($tbMerci > 0) {
            $merci = get_permalink($tbMerci) ?: home_url('/');
        } elseif (!empty($form['merci_slug'])) {
            $merci = home_url('/' . trim($form['merci_slug'], '/') . '/');
        }
    } elseif ($tbMerci > 0) {
        $merci = get_permalink($tbMerci) ?: home_url('/');
    } elseif (!empty($form['merci_slug'])) {
        $merci = home_url('/' . trim($form['merci_slug'], '/') . '/');
    }
    $merci = apply_filters('hd_native_merci_url', $merci, $form, $meta, $leadId);

    // Redirect user ASAP; Espo retries happen after connection close when needed.
    nocache_headers();
    wp_safe_redirect($merci);
    if ($espoNeedsAsync && $leadId > 0 && function_exists('hd_native_espo_after_response_retry')) {
        // Flush merci to browser, then retry Espo without blocking UX.
        if (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')) {
            hd_native_espo_after_response_retry([$leadId]);
            exit;
        }
        // No finish_request: schedule only (already queued) — do not block.
    }
    exit;
}
