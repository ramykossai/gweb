<?php
/**
 * Template Name: HD Compte client
 * Chrome identique boutique (nav neon + Orbitron/Exo + particles).
 */
// Critical: wpautop turns dashboard cards into bullet lists.
remove_filter('the_content', 'wpautop');
remove_filter('the_content', 'shortcode_unautop');

get_header(null, ['body_class' => 'hd-boutique-body hd-customer-page']);

$logged = is_user_logged_in();
$heroTitle = $logged ? 'Espace client' : 'Mon compte';
$heroSub = $logged
    ? 'Suivez vos commandes, votre profil et le statut livraison.'
    : 'Connexion ou inscription — email ou téléphone.';

$cssPath = WP_CONTENT_DIR . '/mu-plugins/hd-native-leads/customer-area.css';
$cssVer = is_readable($cssPath) ? (string) filemtime($cssPath) : (string) time();
$cssUrl = content_url('mu-plugins/hd-native-leads/customer-area.css') . '?v=' . rawurlencode($cssVer);
?>
<link rel="stylesheet" href="<?php echo esc_url($cssUrl); ?>" id="hd-customer-css-fallback"/>
<style id="hd-cust-dash-critical">
/* Critical dashboard layout — survives cache/RTL breakage */
body.hd-customer-page{background:#050f08!important}
.hd-account-main,.hd-cust-shell{direction:ltr!important;unicode-bidi:isolate;max-width:1180px;margin:0 auto;width:100%;box-sizing:border-box}
.hd-cust-shell{padding:12px 20px 64px;font-family:"Exo 2",system-ui,sans-serif;color:#f0fff6;color-scheme:dark}
.hd-cust-layout{display:grid!important;grid-template-columns:240px minmax(0,1fr)!important;gap:22px!important;align-items:start;width:100%}
.hd-cust-menu{position:sticky;top:88px;display:flex;flex-direction:column;gap:6px;padding:16px 12px;border-radius:18px;border:1px solid rgba(0,255,136,.2);background:linear-gradient(165deg,rgba(14,36,24,.98),rgba(6,14,10,.96));box-shadow:0 12px 32px rgba(0,0,0,.35)}
.hd-cust-menu__label{margin:0 4px 10px;font-family:Orbitron,monospace;font-size:10px;letter-spacing:1.6px;text-transform:uppercase;color:#00ff88}
.hd-cust-menu__item{display:inline-flex;align-items:center;gap:9px;padding:12px 14px;border-radius:12px;font-size:13px;font-weight:600;color:#c9f5db;text-decoration:none;border:1px solid transparent}
.hd-cust-menu__item svg{flex-shrink:0;opacity:.85}
.hd-cust-menu__item.is-active,.hd-cust-menu__item:hover{background:rgba(0,255,136,.14);border-color:rgba(0,255,136,.35);color:#fff}
.hd-cust-menu__item--cta{margin-top:10px;justify-content:center;text-align:center;background:#00ff88;color:#04150c!important;font-family:Orbitron,monospace;font-size:11px;letter-spacing:.8px;text-transform:uppercase}
.hd-cust-main{min-width:0;width:100%}
.hd-cust-form{display:flex;flex-direction:column;gap:16px}
.hd-cust-form__section{display:flex;flex-direction:column;gap:14px}
.hd-cust-label{display:flex;flex-direction:column;gap:8px;font-size:13px;font-weight:600;color:rgba(240,255,246,.9)}
.hd-cust-field{position:relative;display:block}
.hd-cust-field__ico{position:absolute;left:14px;top:50%;transform:translateY(-50%);display:inline-flex;color:#7ab893;pointer-events:none;z-index:1}
.hd-cust-field .hd-cust-input{padding-left:44px!important}
.hd-cust-shell .hd-cust-input,.hd-account-main .hd-cust-input{appearance:none!important;-webkit-appearance:none!important;width:100%;box-sizing:border-box;padding:13px 14px;border-radius:12px!important;border:1px solid rgba(0,255,136,.18)!important;background:#07140c!important;background-color:#07140c!important;color:#f0fff6!important;font:inherit;font-size:15px;direction:ltr;text-align:left;caret-color:#00ff88}
.hd-cust-shell .hd-cust-input::placeholder{color:rgba(122,184,147,.7)!important;opacity:1}
.hd-cust-shell .hd-cust-input:focus{outline:none!important;border-color:rgba(0,255,136,.65)!important;box-shadow:0 0 0 3px rgba(0,255,136,.16)!important;background:#0a1a10!important}
.hd-cust-field:focus-within .hd-cust-field__ico{color:#00ff88}
.hd-cust-shell .hd-cust-input:-webkit-autofill{-webkit-text-fill-color:#f0fff6!important;-webkit-box-shadow:0 0 0 1000px #07140c inset!important;box-shadow:0 0 0 1000px #07140c inset!important}
.hd-cust-tab{display:inline-flex;align-items:center;justify-content:center;gap:7px}
.hd-cust-shell .hd-cust-btn,.hd-account-main .hd-cust-btn{display:inline-flex!important;align-items:center;justify-content:center;gap:8px;margin-top:6px;padding:.9rem 1.25rem!important;border:1px solid rgba(0,255,136,.7)!important;border-radius:999px!important;background:#00ff88!important;background-color:#00ff88!important;color:#04150c!important;font-family:Orbitron,monospace!important;font-size:12px!important;font-weight:700!important;letter-spacing:1px;text-transform:uppercase;text-decoration:none!important;cursor:pointer;box-shadow:0 0 24px rgba(0,255,136,.28)!important;appearance:none!important;-webkit-appearance:none!important}
.hd-cust-shell .hd-cust-btn:hover{background:#fff!important;color:#04150c!important}
.hd-cust-btn--block{width:100%}
.hd-cust-pass-block{margin:4px 0 0;padding:16px;border-radius:16px;border:1px solid rgba(0,255,136,.14)!important;background:linear-gradient(165deg,rgba(8,22,14,.95),rgba(4,12,8,.9))!important;display:flex;flex-direction:column;gap:14px}
.hd-cust-pass-block legend{padding:0 8px;font-size:11px;font-weight:700;letter-spacing:.9px;text-transform:uppercase;color:#00ff88;font-family:Orbitron,monospace}
.hd-cust-pass-block__hint{margin:-4px 0 0;font-size:12px;color:#7ab893}
.hd-cust-profile-form{max-width:520px;margin-top:8px}
.hd-cust-card--profile{max-width:520px}
.hd-cust-hero-panel{display:grid!important;grid-template-columns:1.2fr .9fr;gap:18px;margin:0 0 22px;padding:22px;border-radius:22px;border:1px solid rgba(0,255,136,.18);background:radial-gradient(700px 220px at 0% 0%,rgba(0,255,136,.16),transparent 55%),linear-gradient(165deg,rgba(18,42,28,.98),rgba(8,18,12,.95));box-shadow:0 20px 50px rgba(0,0,0,.35)}
.hd-cust-stats{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.hd-cust-stat{padding:14px 12px;border-radius:14px;background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.06);text-align:center}
.hd-cust-stat strong{display:block;font-family:Orbitron,monospace;font-size:1.4rem;color:#fff}
.hd-cust-stat span{font-size:11px;letter-spacing:.6px;text-transform:uppercase;color:#7ab893}
.hd-cust-orders-grid{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:16px!important;list-style:none!important;margin:0!important;padding:0!important}
.hd-order-card{display:grid!important;grid-template-columns:132px minmax(0,1fr)!important;gap:0!important;overflow:hidden;border-radius:20px;border:1px solid rgba(0,255,136,.16);background:linear-gradient(160deg,rgba(16,38,26,.98),rgba(7,16,11,.96));box-shadow:0 16px 40px rgba(0,0,0,.3);list-style:none}
.hd-order-card__media{position:relative;background:#07140c;min-height:150px}
.hd-order-card__media img{width:100%;height:100%;object-fit:cover;display:block}
.hd-order-card__badge{position:absolute;left:8px;right:8px;bottom:8px;text-align:center;font-size:10px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;padding:6px 8px;border-radius:999px;background:rgba(0,255,136,.9);color:#04150c}
.hd-order-card__body{padding:14px;display:flex;flex-direction:column;gap:10px;min-width:0}
.hd-order-card__head{display:flex;justify-content:space-between;gap:10px}
.hd-order-card__sku{margin:0 0 2px;font-size:10px;letter-spacing:1.4px;text-transform:uppercase;color:#7ab893;font-family:Orbitron,monospace}
.hd-order-card__title{margin:0;font-size:1rem;line-height:1.25;color:#f5fff9}
.hd-order-card__price{margin:0;font-family:Orbitron,monospace;white-space:nowrap}
.hd-order-card__price strong{font-size:1.25rem;color:#00ff88}
.hd-order-card__facts{list-style:none!important;margin:0!important;padding:0!important;display:grid!important;grid-template-columns:1fr 1fr;gap:6px 10px}
.hd-order-card__facts li{list-style:none!important;display:flex;flex-direction:column;gap:2px;padding:8px;border-radius:10px;background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.05)}
.hd-order-card__facts li::marker{content:''}
.hd-order-card__facts span{font-size:10px;letter-spacing:.5px;text-transform:uppercase;color:#7ab893}
.hd-order-card__facts strong{font-size:12px;color:#e8fff2}
.hd-order-card__ship{display:flex;gap:8px;margin:0;padding:8px 10px;border-radius:12px;background:rgba(0,255,136,.06);border:1px dashed rgba(0,255,136,.25);font-size:12px;color:#c9f5db}
.hd-order-card__track{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-top:auto}
.hd-order-card__step{height:4px;border-radius:999px;background:rgba(255,255,255,.08)}
.hd-order-card__step.is-on{background:linear-gradient(90deg,#1fa89b,#00ff88)}
.hd-cust-slides{margin:0 0 20px;padding:16px;border-radius:20px;border:1px solid rgba(0,255,136,.14);background:rgba(0,0,0,.22);overflow:hidden}
.hd-cust-slides__viewport{overflow:hidden}
.hd-cust-slides__track{display:flex;transition:transform .45s ease}
.hd-cust-slides__slide{flex:0 0 100%;padding:4px}
.hd-cust-slides__dots{display:flex;gap:8px;justify-content:center;margin-top:12px}
.hd-cust-slides__dot{width:8px;height:8px;border-radius:50%;border:0;background:rgba(255,255,255,.2);cursor:pointer}
.hd-cust-slides__dot.is-active{background:#00ff88}
@media (max-width:900px){
  .hd-cust-layout{grid-template-columns:1fr!important}
  .hd-cust-menu{position:relative;top:auto;flex-direction:row;flex-wrap:wrap}
  .hd-cust-hero-panel,.hd-cust-orders-grid{grid-template-columns:1fr!important}
}
@media (max-width:560px){
  .hd-order-card{grid-template-columns:1fr!important}
}
</style>
<div class="hv-particles" id="hvParticles" aria-hidden="true"></div>

<nav class="hv-navbar scrolled" id="hvNavbar">
  <div class="hv-nav-inner">
    <a href="<?php echo esc_url(home_url('/')); ?>" class="hv-logo">
      <span class="hv-logo-herbal">HERBAL</span>
      <span class="hv-logo-vitality">VITALY</span>
    </a>
    <div class="hv-nav-links hv-nav-links--account">
      <a href="<?php echo esc_url(home_url('/#catalogue')); ?>" data-i18n="nav.produits">Produits</a>
      <a href="<?php echo esc_url(home_url('/#marque')); ?>" data-i18n="nav.marque">La marque</a>
      <a href="<?php echo esc_url(home_url('/#avis')); ?>" data-i18n="nav.avis">Avis</a>
      <a href="<?php echo esc_url(home_url('/#minceur')); ?>" data-i18n="nav.minceur">Minceur</a>
    </div>
    <?php
    if (function_exists('hd_customer_nav_links_html')) {
        echo hd_customer_nav_links_html('boutique'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    ?>
    <a href="<?php echo esc_url(home_url('/#catalogue')); ?>" class="hv-nav-cta" data-i18n="nav.boutique">Boutique</a>
  </div>
</nav>

<header class="hv-hero hv-hero--account">
  <div class="hv-hero-bg"></div>
  <div class="hv-hero-glow hv-glow-1"></div>
  <div class="hv-hero-glow hv-glow-2"></div>
  <div class="hv-hero-content">
    <p class="hv-badge" data-i18n="account.hero_badge">Herbal Vitaly · Compte</p>
    <h1 class="hv-hero-title">
      <span class="hv-title-muted">HERBAL</span>
      <span class="hv-title-neon">VITALY</span>
    </h1>
    <p class="hv-hero-tagline" data-i18n="<?php echo $logged ? 'account.hero_logged' : 'account.hero_guest'; ?>"><?php echo esc_html($heroTitle); ?></p>
    <p class="hv-hero-sub" data-i18n="<?php echo $logged ? 'account.hero_logged_sub' : 'account.hero_guest_sub'; ?>"><?php echo esc_html($heroSub); ?></p>
  </div>
</header>

<main class="hd-account-main" dir="ltr">
<?php
while (have_posts()) :
    the_post();
    // Bypass fragile the_content filters — render shortcode directly.
    if (function_exists('hd_customer_orders_shortcode')) {
        echo hd_customer_orders_shortcode(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    } else {
        the_content();
    }
endwhile;
?>
</main>

<?php
if (function_exists('hd_site_legal_footer')) {
    hd_site_legal_footer();
}
?>
<script>
(function(){
  var nav=document.getElementById('hvNavbar');
  if(nav){
    window.addEventListener('scroll', function(){
      nav.classList.toggle('scrolled', window.scrollY>24);
    }, {passive:true});
  }
  var wrap=document.getElementById('hvParticles');
  if(wrap){
    for(var i=0;i<28;i++){
      var p=document.createElement('span');
      p.className='hv-particle';
      p.style.left=(Math.random()*100)+'%';
      p.style.animationDuration=(8+Math.random()*14)+'s';
      p.style.animationDelay=(Math.random()*10)+'s';
      wrap.appendChild(p);
    }
  }
  document.querySelectorAll('[data-hd-cust-carousel]').forEach(function(root){
    var track=root.querySelector('.hd-cust-slides__track');
    var slides=track ? [].slice.call(track.children) : [];
    var dotsWrap=root.querySelector('.hd-cust-slides__dots');
    if(slides.length<2 || !track) return;
    var idx=0, timer=null;
    var dots=slides.map(function(_,i){
      var b=document.createElement('button');
      b.type='button';
      b.className='hd-cust-slides__dot'+(i===0?' is-active':'');
      b.setAttribute('aria-label','Slide '+(i+1));
      b.addEventListener('click', function(){ go(i,true); });
      if(dotsWrap) dotsWrap.appendChild(b);
      return b;
    });
    function go(i, manual){
      idx=(i+slides.length)%slides.length;
      track.style.transform='translateX(' + (-idx*100) + '%)';
      dots.forEach(function(d,j){ d.classList.toggle('is-active', j===idx); });
      if(manual){ clearInterval(timer); timer=setInterval(function(){ go(idx+1,false); }, 5200); }
    }
    timer=setInterval(function(){ go(idx+1,false); }, 5200);
  });
})();
</script>
<?php
get_footer();
