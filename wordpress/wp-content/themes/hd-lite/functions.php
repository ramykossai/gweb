<?php
/**
 * Theme Name: HD Lite
 * Description: Lightweight mobile-first theme for migrated LPs (no Colibri).
 * Version: 1.1.0
 * Text Domain: hd-lite
 */
if (!defined('ABSPATH')) {
    exit;
}

/** All merci pages: never shared HTML cache (ads custom conversions + Lead + eid). */
add_action('template_redirect', static function () {
    if (!is_page()) {
        return;
    }
    $slug = (string) get_post_field('post_name', get_queried_object_id());
    if ($slug === '' || stripos($slug, 'merci') === false) {
        return;
    }
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    do_action('litespeed_control_set_nocache', 'hd merci slug');
    if (!headers_sent()) {
        header('X-LiteSpeed-Cache-Control: no-cache', false);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', false);
        nocache_headers();
    }
}, 0);

/** Lead event on thank-you pages (slug contains merci) — Meta Pixel lives in header.php. */
add_action('wp_footer', function () {
    if (!is_page()) {
        return;
    }
    $slug = get_post_field('post_name', get_queried_object_id());
    if (!$slug || stripos((string) $slug, 'merci') === false) {
        return;
    }
    echo "<script>if(typeof fbq==='function'){fbq('track','Lead');}</script>\n";
}, 99);

/** Boutique template fonts (were previously hard-coded in page-hd-boutique.php <head>). */
add_action('wp_enqueue_scripts', function () {
    if (!is_page()) {
        return;
    }
    $tpl = get_page_template_slug(get_queried_object_id());
    $boutiqueChrome = in_array($tpl, ['page-hd-boutique.php', 'page-hd-account.php'], true);
    if (!$boutiqueChrome && $tpl !== 'page.php') {
        return;
    }
    if ($boutiqueChrome) {
        wp_enqueue_style(
            'hd-boutique-fonts',
            'https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700;900&family=Exo+2:ital,wght@0,300;0,400;0,600;0,700;1,400&display=swap',
            [],
            null
        );
    }
});

add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
});

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'hd-colibri-fonts',
        'https://fonts.bunny.net/css?family=Roboto:400,500,700|Cairo:400,600,700',
        [],
        null
    );
    wp_enqueue_style('hd-lite', get_stylesheet_uri(), ['hd-colibri-fonts'], '1.15.23');
});

/**
 * LP + merci only: drop WP/Reach CSS that sits after Meta pixels in <head>.
 * Does not touch header.php pixel snippets. Revert: HD_LITE_TRIM_WP_HEAD false.
 */
function hd_lite_is_ads_surface(): bool
{
    if (!is_page()) {
        return false;
    }
    $id = (int) get_queried_object_id();
    $tpl = (string) get_page_template_slug($id);
    if ($tpl === 'page-hd-landing.php') {
        return true;
    }
    $slug = (string) get_post_field('post_name', $id);
    return $slug !== '' && stripos($slug, 'merci') !== false;
}

add_action('wp', static function () {
    if (!defined('HD_LITE_TRIM_WP_HEAD') || !HD_LITE_TRIM_WP_HEAD || !hd_lite_is_ads_surface()) {
        return;
    }
    remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
    remove_action('wp_footer', 'wp_enqueue_global_styles', 1);
    remove_action('wp_enqueue_scripts', 'wp_enqueue_classic_theme_styles');
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    add_filter('emoji_svg_url', '__return_false');
}, 1);

add_action('wp_enqueue_scripts', static function () {
    if (!defined('HD_LITE_TRIM_WP_HEAD') || !HD_LITE_TRIM_WP_HEAD || !hd_lite_is_ads_surface()) {
        return;
    }
    foreach ([
        'wp-block-library',
        'wp-block-library-theme',
        'classic-theme-styles',
        'global-styles',
        'core-block-supports',
        'wp-emoji-styles',
        'wp-img-auto-sizes-contain',
        'hostinger-reach-subscription-block',
        'hostinger-reach-styles',
    ] as $handle) {
        wp_dequeue_style($handle);
        wp_deregister_style($handle);
    }
    if (function_exists('wp_styles') && wp_styles()) {
        foreach ((array) wp_styles()->queue as $h) {
            if (is_string($h) && (strpos($h, 'hostinger-reach') === 0 || strpos($h, 'hostinger_reach') === 0)) {
                wp_dequeue_style($h);
            }
        }
    }
}, 999);

/**
 * FR + Darija tunisienne (arabe) — boutique & pages produit.
 */
function hd_hv_i18n_dict_path() {
    return get_template_directory() . '/assets/hv-i18n-fr-ar.json';
}

function hd_hv_i18n_load_dict() {
    static $dict = null;
    if ($dict !== null) {
        return $dict;
    }
    $path = hd_hv_i18n_dict_path();
    if (!is_readable($path)) {
        $dict = [];
        return $dict;
    }
    $raw = file_get_contents($path);
    $decoded = json_decode((string) $raw, true);
    $dict = is_array($decoded) ? $decoded : [];
    return $dict;
}

function hd_hv_i18n_should_load() {
    if (!is_page()) {
        return false;
    }
    $tpl = (string) get_page_template_slug(get_queried_object_id());
    // White pages inject assets manually in page-hd-white.php (early exit).
    if ($tpl === 'page-hd-white.php') {
        return false;
    }
    if ($tpl === 'page-hd-boutique.php') {
        return true;
    }
    $slug = (string) get_post_field('post_name', get_queried_object_id());
    // Pages d'information atteignables depuis la boutique : sans le bundle,
    // un visiteur passé en TN y retombe en français et perd le sélecteur.
    return in_array($slug, ['boutique', 'contact', 'faq', 'cgv', 'mon-compte'], true);
}

function hd_hv_i18n_enqueue() {
    $ver = '1.2.4';
    $base = get_template_directory_uri() . '/assets';
    wp_enqueue_style('hv-i18n', $base . '/hv-i18n.css', ['hd-lite'], $ver);
    wp_enqueue_script('hv-i18n', $base . '/hv-i18n.js', [], $ver, true);
    wp_add_inline_script(
        'hv-i18n',
        'window.HV_I18N=window.HV_I18N||{};window.HV_I18N.dict=' . wp_json_encode(hd_hv_i18n_load_dict()) . ';',
        'before'
    );
}

add_action('wp_enqueue_scripts', function () {
    if (hd_hv_i18n_should_load()) {
        hd_hv_i18n_enqueue();
    }
}, 30);

/** Inline tags for white pages (template exits before normal footer print sometimes). */
function hd_hv_i18n_print_assets() {
    $ver = '1.2.4';
    $base = get_template_directory_uri() . '/assets';
    $dict = hd_hv_i18n_load_dict();
    $css = esc_url($base . '/hv-i18n.css?v=' . $ver);
    $js = esc_url($base . '/hv-i18n.js?v=' . $ver);
    echo '<link rel="stylesheet" href="' . $css . '" id="hv-i18n-css"/>' . "\n";
    echo '<script>window.HV_I18N=window.HV_I18N||{};window.HV_I18N.dict=' . wp_json_encode($dict) . ';</script>' . "\n";
    echo '<script src="' . $js . '" id="hv-i18n-js" defer></script>' . "\n";
}

/** Empêche wpautop d’insérer des <p> vides qui créent une bande noire sous la barre. */
add_action('wp', function () {
    if (!is_page()) {
        return;
    }
    $tpl = get_page_template_slug(get_queried_object_id());
    if ($tpl === 'page-hd-landing.php' || $tpl === 'page-hd-merci.php' || $tpl === 'page-hd-boutique.php' || $tpl === 'page-hd-account.php') {
        remove_filter('the_content', 'wpautop');
        remove_filter('the_content', 'shortcode_unautop');
    }
});

/**
 * Map boutique order fields (prénom/nom/téléphone) → native lead fields.
 */
add_action('admin_post_nopriv_hd_native_lead', 'hd_boutique_remap_lead_fields', 1);
add_action('admin_post_hd_native_lead', 'hd_boutique_remap_lead_fields', 1);
function hd_boutique_remap_lead_fields() {
    if (empty($_POST['lead_name'])) {
        $prenom = isset($_POST['prenom']) ? sanitize_text_field(wp_unslash($_POST['prenom'])) : '';
        $nom = isset($_POST['nom']) ? sanitize_text_field(wp_unslash($_POST['nom'])) : '';
        $combined = trim($prenom . ' ' . $nom);
        if ($combined !== '') {
            $_POST['lead_name'] = $combined;
        }
    }
    if (empty($_POST['lead_phone']) && !empty($_POST['telephone'])) {
        $_POST['lead_phone'] = sanitize_text_field(wp_unslash($_POST['telephone']));
    }
}

add_filter('hd_native_lead_meta', function ($meta, $form, $ctx) {
    foreach (['prenom', 'nom', 'gouvernorat', 'adresse', 'quantite', 'paiement', 'notes', 'telephone'] as $k) {
        if (!empty($_POST[$k])) {
            $meta['boutique_' . $k] = sanitize_text_field(wp_unslash($_POST[$k]));
        }
    }
    if (!empty($_POST['hd_channel']) && sanitize_key((string) $_POST['hd_channel']) === 'boutique') {
        $meta['hd_channel'] = 'boutique';
        $meta['is_boutique'] = 1;
    } elseif (!empty($meta['boutique_quantite']) || !empty($meta['boutique_gouvernorat'])) {
        $meta['hd_channel'] = 'boutique';
        $meta['is_boutique'] = 1;
    }
    return $meta;
}, 20, 3);

/**
 * Visual boutique order form (same look as commande.html) wired to hd_native form_key.
 */
function hd_boutique_order_form_html($form_key) {
    if (!function_exists('hd_native_get_form')) {
        return '';
    }
    $form = hd_native_get_form($form_key);
    if (!$form) {
        return '<!-- hd boutique form: unknown key ' . esc_html($form_key) . ' -->';
    }
    $action = esc_url(admin_url('admin-post.php'));
    $nonce = wp_create_nonce('hd_lead_' . $form['key']);
    $page_id = (int) get_queried_object_id();
    $submit = 'Confirmer ma commande';

    ob_start();
    ?>
    <div class="order-form-wrap hv-boutique-form" data-form-key="<?php echo esc_attr($form['key']); ?>" data-hd-form-key="<?php echo esc_attr($form['key']); ?>" data-hd-draft="1">
      <h2 data-i18n="form.title">Vos informations de livraison</h2>
      <form class="order-form hd-lead-form" method="post" action="<?php echo $action; ?>" data-hd-form-key="<?php echo esc_attr($form['key']); ?>" data-hd-draft="1">
        <input type="hidden" name="action" value="hd_native_lead" />
        <input type="hidden" name="form_key" value="<?php echo esc_attr($form['key']); ?>" />
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
        <input type="hidden" name="hd_channel" value="boutique" />
        <input type="hidden" name="lead_name" value="" />
        <input type="hidden" name="lead_phone" value="" />
        <div class="form-row">
          <div class="form-group"><label data-i18n="form.prenom">Prénom</label><input type="text" name="prenom" placeholder="Votre prénom" data-i18n-placeholder="form.prenom_ph" required autocomplete="given-name"/></div>
          <div class="form-group"><label data-i18n="form.nom">Nom</label><input type="text" name="nom" placeholder="Votre nom" data-i18n-placeholder="form.nom_ph" required autocomplete="family-name"/></div>
        </div>
        <div class="form-group"><label data-i18n="form.tel">Numéro de téléphone</label><input type="tel" name="telephone" placeholder="+216 XX XXX XXX" data-i18n-placeholder="form.tel_ph" required autocomplete="tel" inputmode="tel" dir="ltr"/><span class="hd-phone-error" hidden dir="rtl" aria-live="polite"></span></div>
        <div class="form-group">
          <label data-i18n="form.gov">Gouvernorat</label>
          <select name="gouvernorat">
            <option value="" data-i18n="form.gov_ph">Sélectionnez votre gouvernorat</option>
            <option>Tunis</option><option>Ariana</option><option>Ben Arous</option><option>Manouba</option>
            <option>Nabeul</option><option>Zaghouan</option><option>Bizerte</option><option>Béja</option>
            <option>Jendouba</option><option>Kef</option><option>Siliana</option><option>Sousse</option>
            <option>Monastir</option><option>Mahdia</option><option>Sfax</option><option>Kairouan</option>
            <option>Kasserine</option><option>Sidi Bouzid</option><option>Gabès</option><option>Médenine</option>
            <option>Tataouine</option><option>Gafsa</option><option>Tozeur</option><option>Kébili</option>
          </select>
        </div>
        <div class="form-group"><label data-i18n="form.adresse">Adresse de livraison</label><input type="text" name="adresse" placeholder="Rue, numéro, ville..." data-i18n-placeholder="form.adresse_ph" required/></div>
        <div class="form-group">
          <label data-i18n="form.qty">Quantité</label>
          <select name="quantite">
            <option value="1 unité" data-i18n="form.qty1">1 unité</option>
            <option value="2 unités" data-i18n="form.qty2">2 unités</option>
            <option value="3 unités" data-i18n="form.qty3">3 unités</option>
          </select>
        </div>
        <div class="form-group">
          <label data-i18n="form.pay">Mode de paiement</label>
          <select name="paiement">
            <option value="Paiement à la livraison" data-i18n="form.pay_cod">Paiement à la livraison</option>
            <option value="Virement bancaire" data-i18n="form.pay_bank">Virement bancaire</option>
          </select>
        </div>
        <div class="form-group"><label data-i18n="form.notes">Notes (optionnel)</label><textarea name="notes" rows="3" placeholder="Informations complémentaires..." data-i18n-placeholder="form.notes_ph"></textarea></div>
        <button type="submit" class="btn-primary">
          <span data-i18n="form.submit"><?php echo esc_html($submit); ?></span>
        </button>
        <p style="font-size:12px;color:var(--text-muted, #9aa);text-align:center;margin-top:10px" data-i18n="form.confirm">Confirmation par téléphone dans les 24h.</p>
      </form>
    </div>
    <style>
      .hv-boutique-form{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:28px;max-width:520px}
      .hv-boutique-form h2{font-size:1.15rem;margin:0 0 18px;color:#fff}
      .hv-boutique-form .form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
      .hv-boutique-form .form-group{display:flex;flex-direction:column;gap:6px;margin-bottom:12px}
      .hv-boutique-form label{font-size:13px;opacity:.85}
      .hv-boutique-form input,.hv-boutique-form select,.hv-boutique-form textarea{
        background:rgba(0,0,0,.35);border:1px solid rgba(255,255,255,.14);border-radius:10px;
        padding:12px 14px;color:#fff;font:inherit
      }
      .hv-boutique-form .btn-primary{width:100%;margin-top:8px;display:inline-flex;justify-content:center;align-items:center;gap:8px}
      @media(max-width:640px){.hv-boutique-form .form-row{grid-template-columns:1fr}}
    </style>
    <script>
    <?php
    if (function_exists('hd_native_tn_phone_js_snippet')) {
        echo hd_native_tn_phone_js_snippet(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    ?>
    (function(){
      var STORE='hd_first_touch_path';
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
      document.querySelectorAll('.hv-boutique-form .hd-lead-form').forEach(function(f){
        var cu=f.querySelector('[name="current_url"]'); if(cu) cu.value=embedUrl();
        var ru=f.querySelector('[name="refer_url"]'); if(ru) ru.value=embedUrl();
        var wr=f.querySelector('[name="wp_http_referer"]'); if(wr) wr.value=firstTouch();
        ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','fbclid'].forEach(function(k){
          var el=f.querySelector('[name="'+k+'"]'); if(el) el.value=q(k);
        });
        var tb=f.querySelector('[name="tbclid"]');
        if(tb){ tb.value=q('tbclid') || cookieGet('tbclid') || ''; }
        var tel=f.querySelector('[name="telephone"]');
        var errEl=f.querySelector('.hd-phone-error');
        var msgEmpty='الرجاء إدخال رقم هاتف تونسي صحيح (8 أرقام)';
        function showErr(msg){
          if(errEl){ errEl.hidden=!msg; errEl.textContent=msg||''; }
          if(tel){ try{ tel.setCustomValidity(''); }catch(e){} }
        }
        if(tel){
          tel.addEventListener('blur', function(){
            if(!tel.value.trim() || !window.hdNativeNormalizeTnPhone) return;
            var r=window.hdNativeNormalizeTnPhone(tel.value);
            if(r.ok){ tel.value=r.phone; showErr(''); }
            // sinon: pas de message tant que pas de submit
          });
          tel.addEventListener('input', function(){ showErr(''); });
          tel.addEventListener('invalid', function(ev){
            ev.preventDefault();
            if(!tel.value.trim()) showErr(msgEmpty);
          });
        }
        f.addEventListener('submit', function(ev){
          var ln=f.querySelector('[name="lead_name"]');
          var lp=f.querySelector('[name="lead_phone"]');
          var pre=f.querySelector('[name="prenom"]');
          var nom=f.querySelector('[name="nom"]');
          if(ln) ln.value=((((pre&&pre.value)||'')+' '+((nom&&nom.value)||'')).trim());
          if(tel && window.hdNativeNormalizeTnPhone){
            if(!tel.value.trim()){
              ev.preventDefault();
              showErr(msgEmpty);
              tel.focus();
              return;
            }
            var r=window.hdNativeNormalizeTnPhone(tel.value);
            if(!r.ok){
              ev.preventDefault();
              showErr(r.error||msgEmpty);
              tel.focus();
              return;
            }
            tel.value=r.phone;
            if(lp) lp.value=r.phone;
            showErr('');
          } else if(lp && tel){
            lp.value=tel.value;
          }
        });
      });
    })();
    </script>
    <style>.hv-boutique-form .hd-phone-error{display:block;color:#7f1d1d;background:#fee2e2;font-size:13px;font-weight:700;margin-top:.45rem;padding:.4rem .55rem;border-radius:6px}.hv-boutique-form .hd-phone-error[hidden]{display:none!important}</style>
    <?php
    return ob_get_clean();
}

/** Shared legal footer links (boutique + default pages). */
function hd_site_legal_footer() {
    $links = [
        home_url('/') => ['Boutique', 'nav.boutique'],
        home_url('/confidentialite/') => ['Confidentialité', 'footer.privacy'],
        home_url('/cgv/') => ['CGV', 'footer.cgv'],
        home_url('/mentions-legales/') => ['Mentions légales', 'footer.mentions'],
        home_url('/faq/') => ['FAQ', 'footer.faq'],
        home_url('/contact/') => ['Contact', 'footer.contact'],
    ];
    echo '<footer class="hv-site-footer" role="contentinfo"><div class="hv-site-footer-inner">';
    echo '<div class="hv-logo"><span class="hv-logo-herbal">HERBAL</span> <span class="hv-logo-vitality">VITALY</span></div>';
    echo '<nav class="hv-site-footer-nav" aria-label="Informations légales"><ul>';
    foreach ($links as $url => $pair) {
        echo '<li><a href="' . esc_url($url) . '" data-i18n="' . esc_attr($pair[1]) . '">' . esc_html($pair[0]) . '</a></li>';
    }
    echo '</ul></nav>';
    echo '<p class="hv-site-footer-copy" data-i18n="footer.copy">© ' . esc_html(gmdate('Y')) . ' Herbal Vitaly — Compléments alimentaires. Ne remplacent pas une alimentation variée et un mode de vie sain.</p>';
    echo '</div></footer>';
}

add_action('wp_head', function () {
    if (!is_page()) {
        return;
    }
    $tpl = (string) get_page_template_slug(get_queried_object_id());
    // Default template (''), page.php, or boutique — legal pages use default.
    $ok = ($tpl === '' || $tpl === 'default' || $tpl === 'page.php' || $tpl === 'page-hd-boutique.php');
    if (!$ok) {
        return;
    }
    echo '<style id="hv-site-footer-css">
    .hv-site-footer{margin-top:48px;padding:40px 20px 56px;background:linear-gradient(180deg,rgba(0,0,0,.15),rgba(0,0,0,.45));border-top:1px solid rgba(255,255,255,.08)}
    .hv-site-footer-inner{max-width:1100px;margin:0 auto;text-align:center}
    .hv-site-footer .hv-logo{justify-content:center;margin-bottom:16px}
    .hv-site-footer-nav ul{display:flex;flex-wrap:wrap;gap:12px 22px;justify-content:center;list-style:none;margin:0 0 18px;padding:0}
    .hv-site-footer-nav a{color:rgba(255,255,255,.78);text-decoration:none;font-size:14px}
    .hv-site-footer-nav a:hover{color:#00ff88}
    .hv-site-footer-copy{font-size:12px;color:rgba(255,255,255,.55);max-width:640px;margin:0 auto;line-height:1.5}
    body.page:not(.hd-boutique-body) .hv-site-footer{background:#0b0f14;color:#fff}
    body.page:not(.hd-boutique-body) main.hd-shop{max-width:800px;margin:0 auto;padding:48px 20px 24px}
    body.page:not(.hd-boutique-body) main.hd-shop h1{font-size:clamp(1.6rem,3vw,2.2rem);margin-bottom:1rem}
    body.page:not(.hd-boutique-body) .hv-legal-article h2{margin:1.6em 0 .55em;font-size:1.15rem}
    body.page:not(.hd-boutique-body) .hv-legal-article p,body.page:not(.hd-boutique-body) .hv-legal-article li{line-height:1.65;color:rgba(255,255,255,.82)}
    body.page:not(.hd-boutique-body) .hv-legal-article ul{padding-left:1.2em;margin:0 0 1em}
    body.page:not(.hd-boutique-body) .hv-legal-article a{color:#00ff88}
    </style>';
}, 40);
