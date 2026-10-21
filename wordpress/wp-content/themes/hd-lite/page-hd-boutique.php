<?php
/**
 * Template Name: HD Boutique
 * Accueil boutique — Herbal Vitaly (catalogue + marque + avis).
 * Bilingue FR + darija tunisienne via hv-i18n.
 */
// Lead forms embed a WP nonce — never serve a shared LiteSpeed HTML cache (stale nonce → "Invalid form").
if (!defined('DONOTCACHEPAGE')) {
    define('DONOTCACHEPAGE', true);
}
do_action('litespeed_control_set_nocache', 'hd boutique lead form');
if (!headers_sent()) {
    header('X-LiteSpeed-Cache-Control: no-cache', false);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', false);
    nocache_headers();
}
get_header(null, ['body_class' => 'hd-boutique-body']);
?>
<div class="hv-particles" id="hvParticles" aria-hidden="true"></div>

<nav class="hv-navbar" id="hvNavbar">
  <div class="hv-nav-inner">
    <a href="<?php echo esc_url(home_url('/')); ?>" class="hv-logo">
      <span class="hv-logo-herbal">HERBAL</span>
      <span class="hv-logo-vitality">VITALY</span>
    </a>
    <div class="hv-nav-links">
      <a href="#marque" data-i18n="nav.marque">La marque</a>
      <a href="#sante" data-i18n="nav.sante">Santé</a>
      <a href="#soin" data-i18n="nav.soin">Soin</a>
      <a href="#masse" data-i18n="nav.masse">Masse</a>
      <a href="#minceur" data-i18n="nav.minceur">Minceur</a>
      <a href="#femme" data-i18n="nav.femme">Femme</a>
      <a href="#homme" data-i18n="nav.homme">Homme</a>
      <a href="#avis" data-i18n="nav.avis">Avis</a>
    </div>
    <?php
    if (function_exists('hd_customer_nav_links_html')) {
        echo hd_customer_nav_links_html('boutique'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    ?>
    <a href="#catalogue" class="hv-nav-cta" data-i18n="nav.produits">Voir les produits</a>
  </div>
</nav>

<header class="hv-hero">
  <div class="hv-hero-bg"></div>
  <div class="hv-hero-glow hv-glow-1"></div>
  <div class="hv-hero-glow hv-glow-2"></div>
  <div class="hv-hero-content">
    <p class="hv-badge" data-i18n="hero.badge">Laboratoire · Plantes · Performance</p>
    <h1 class="hv-hero-title">
      <span class="hv-title-muted">HERBAL</span>
      <span class="hv-title-neon">VITALY</span>
    </h1>
    <p class="hv-hero-tagline" data-i18n="hero.tagline" data-i18n-html="1">La vitalité naturelle,<br>formulée pour durer.</p>
    <p class="hv-hero-sub" data-i18n="hero.sub">Compléments premium à base de plantes — minceur, énergie, équilibre et performance.</p>
    <a href="#catalogue" class="hv-btn-primary">
      <span data-i18n="hero.cta">Explorer le catalogue</span>
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
    </a>
  </div>
</header>

<section class="hv-strip" aria-label="Points forts">
  <div class="hv-strip-inner">
    <div class="hv-strip-item"><strong>60+</strong><span data-i18n="strip.formules">formules actives</span></div>
    <div class="hv-strip-item"><strong>100%</strong><span data-i18n="strip.naturel">approche naturelle</span></div>
    <div class="hv-strip-item"><strong>24/7</strong><span data-i18n="strip.suivi">suivi commande</span></div>
    <div class="hv-strip-item"><strong>★★★★★</strong><span data-i18n="strip.clients">clients satisfaits</span></div>
  </div>
</section>

<section class="hv-block" id="marque">
  <div class="hv-block-inner hv-split">
    <div class="hv-split-copy">
      <p class="hv-eyebrow" data-i18n-skip>Herbal Vitaly</p>
      <h2 data-i18n="marque.title">Des plantes. Une science claire. Des résultats concrets.</h2>
      <p data-i18n="marque.p1">Herbal Vitaly sélectionne des actifs végétaux reconnus, dosés pour la vie réelle : énergie du matin, minceur durable, confort digestif, performance masculine et équilibre au féminin.</p>
      <p data-i18n="marque.p2">Chaque formule est conçue pour être simple à comprendre, simple à prendre, et alignée avec une routine saine — sans promesses creuses.</p>
      <ul class="hv-checklist">
        <li data-i18n="marque.li1">Actifs d’origine végétale, traçables</li>
        <li data-i18n="marque.li2">Dosages pensés pour une prise quotidienne</li>
        <li data-i18n="marque.li3">Packaging clair, informations transparentes</li>
      </ul>
    </div>
    <div class="hv-split-panel">
      <div class="hv-panel-card">
        <h3 data-i18n="marque.promise">Notre promesse</h3>
        <p data-i18n="marque.promise_p">Vous offrir des compléments qui respectent le corps, avec une exigence de qualité digne d’un laboratoire moderne.</p>
      </div>
      <div class="hv-panel-card">
        <h3 data-i18n="marque.who">Pour qui&nbsp;?</h3>
        <p data-i18n="marque.who_p">Hommes et femmes qui veulent progresser sur l’énergie, la silhouette, le confort ou la vitalité — sans compromis sur le naturel.</p>
      </div>
    </div>
  </div>
</section>

<section class="hv-block hv-block-alt" id="collections">
  <div class="hv-block-inner">
    <div class="hv-section-header hv-center">
      <p class="hv-eyebrow" data-i18n="col.eyebrow">Collections</p>
      <h2 data-i18n="col.title">Six univers, un même standard</h2>
      <p class="hv-lead" data-i18n="col.lead">Choisissez l’axe qui compte pour vous — chaque gamme reste fidèle à l’ADN Herbal Vitaly.</p>
    </div>
    <div class="hv-universe">
      <a class="hv-universe-item" href="#sante"><span>01</span><strong data-i18n="col.sante">Santé</strong><em data-i18n="col.sante_sub">Confort &amp; équilibre</em></a>
      <a class="hv-universe-item" href="#soin"><span>02</span><strong data-i18n="col.soin">Soin</strong><em data-i18n="col.soin_sub">Beauté &amp; éclat</em></a>
      <a class="hv-universe-item" href="#masse"><span>03</span><strong data-i18n="col.masse">Prise de masse</strong><em data-i18n="col.masse_sub">Volume &amp; recovery</em></a>
      <a class="hv-universe-item" href="#minceur"><span>04</span><strong data-i18n="col.minceur">Minceur</strong><em data-i18n="col.minceur_sub">Silhouette &amp; métabolisme</em></a>
      <a class="hv-universe-item" href="#femme"><span>05</span><strong data-i18n="col.femme">Femme</strong><em data-i18n="col.femme_sub">Vitalité &amp; équilibre</em></a>
      <a class="hv-universe-item" href="#homme"><span>06</span><strong data-i18n="col.homme">Homme</strong><em data-i18n="col.homme_sub">Force &amp; performance</em></a>
    </div>
  </div>
</section>

<main id="catalogue" class="hv-shop">
  <div class="hv-section-header hv-center hv-catalogue-intro">
    <p class="hv-eyebrow" data-i18n="cat.eyebrow">Catalogue</p>
    <h2 data-i18n="cat.title">Tous les produits Herbal Vitaly</h2>
    <p class="hv-lead" data-i18n="cat.lead">Sélectionnez une formule, découvrez le détail, commandez en toute confiance.</p>
  </div>
<?php
while (have_posts()) :
    the_post();
    the_content();
endwhile;
?>
</main>

<section class="hv-block" id="avis">
  <div class="hv-block-inner">
    <div class="hv-section-header hv-center">
      <p class="hv-eyebrow" data-i18n="avis.eyebrow">Ils nous font confiance</p>
      <h2 data-i18n="avis.title">Avis sur Herbal Vitaly</h2>
      <p class="hv-lead" data-i18n="avis.lead">Des retours authentiques de clients qui ont intégré nos formules dans leur routine.</p>
    </div>
    <div class="hv-reviews">
      <article class="hv-review">
        <div class="hv-stars" aria-label="5 sur 5">★★★★★</div>
        <p data-i18n="avis.r1">«&nbsp;Commande rapide, packaging soigné, et surtout une vraie différence sur mon énergie après deux semaines. Je recommande Herbal Vitaly les yeux fermés.&nbsp;»</p>
        <footer><strong>Sami K.</strong><span data-i18n="avis.r1_meta">Client vérifié · Tunisie</span></footer>
      </article>
      <article class="hv-review">
        <div class="hv-stars" aria-label="5 sur 5">★★★★★</div>
        <p data-i18n="avis.r2">«&nbsp;J’ai testé la gamme minceur : pas d’effet yo-yo, juste un coup de pouce naturel. Le service client a répondu en moins d’une heure.&nbsp;»</p>
        <footer><strong>Inès M.</strong><span data-i18n="avis.r2_meta">Cliente vérifiée · Maroc</span></footer>
      </article>
      <article class="hv-review">
        <div class="hv-stars" aria-label="5 sur 5">★★★★★</div>
        <p data-i18n="avis.r3">«&nbsp;Enfin une marque claire : on comprend ce qu’il y a dans le flacon. Qualité perçue au top, je rachète régulièrement.&nbsp;»</p>
        <footer><strong>Karim B.</strong><span data-i18n="avis.r3_meta">Client vérifié · Algérie</span></footer>
      </article>
    </div>
  </div>
</section>

<section class="hv-block hv-block-alt" id="engagement">
  <div class="hv-block-inner">
    <div class="hv-section-header hv-center">
      <p class="hv-eyebrow" data-i18n="eng.eyebrow">Engagement</p>
      <h2 data-i18n="eng.title">Ce qui nous rend exigents</h2>
    </div>
    <div class="hv-pillars">
      <div class="hv-pillar">
        <strong data-i18n="eng.p1_t">Naturel d’abord</strong>
        <p data-i18n="eng.p1">Des plantes et actifs choisis pour leur intérêt réel — pas pour le marketing.</p>
      </div>
      <div class="hv-pillar">
        <strong data-i18n="eng.p2_t">Transparence</strong>
        <p data-i18n="eng.p2">Composition lisible, usage clair, informations accessibles sur chaque fiche.</p>
      </div>
      <div class="hv-pillar">
        <strong data-i18n="eng.p3_t">Livraison suivie</strong>
        <p data-i18n="eng.p3">Commande simple, confirmation rapide, accompagnement jusqu’à la réception.</p>
      </div>
      <div class="hv-pillar">
        <strong data-i18n="eng.p4_t">Satisfaction</strong>
        <p data-i18n="eng.p4">Une équipe à l’écoute pour vous guider vers la formule adaptée à votre objectif.</p>
      </div>
    </div>
  </div>
</section>

<section class="hv-cta-band">
  <div class="hv-cta-band-inner">
    <h2 data-i18n="cta.title">Prêt à passer à l’action&nbsp;?</h2>
    <p data-i18n="cta.sub">Explorez le catalogue et trouvez la formule Herbal Vitaly faite pour vous.</p>
    <a href="#catalogue" class="hv-btn-primary"><span data-i18n="cta.btn">Retour aux produits</span></a>
  </div>
</section>

<?php if (function_exists('hd_site_legal_footer')) { hd_site_legal_footer(); } else { ?>
<footer class="hv-footer">
  <div class="hv-footer-inner">
    <div class="hv-logo">
      <span class="hv-logo-herbal">HERBAL</span>
      <span class="hv-logo-vitality">VITALY</span>
    </div>
    <p data-i18n="footer.copy">Compléments alimentaires. Ne remplacent pas une alimentation variée et un mode de vie sain. Demandez conseil à un professionnel de santé si besoin.</p>
  </div>
</footer>
<?php } ?>

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
  // Reveal on scroll (pas les cards — transform cassait le carousel)
  var els=document.querySelectorAll('.hv-block, .hv-review, .hv-universe-item, .hv-pillar');
  if('IntersectionObserver' in window){
    var io=new IntersectionObserver(function(entries){
      entries.forEach(function(e){
        if(e.isIntersecting){ e.target.classList.add('hv-in'); io.unobserve(e.target); }
      });
    },{threshold:0.12});
    els.forEach(function(el){ el.classList.add('hv-reveal'); io.observe(el); });
  }

  // Carrousel mobile : défilement natif + scroll-snap. L'index est déduit de la
  // position réelle des slides (getBoundingClientRect), jamais d'un calcul en px
  // sur clientWidth — ce qui le rend correct en LTR comme en RTL (mode arabe).
  (function(){
    var mq=window.matchMedia('(max-width:719px)');
    var reduce=window.matchMedia('(prefers-reduced-motion: reduce)');
    var instances=[];

    function teardown(){
      instances.forEach(function(c){ c.destroy(); });
      instances=[];
      document.querySelectorAll('.hv-grid.hv-carousel').forEach(function(grid){
        var track=grid.querySelector(':scope > .hv-track');
        if(track){
          track.querySelectorAll('.hv-slide > .hv-card').forEach(function(card){
            grid.appendChild(card);
          });
          track.remove();
        }
        var dots=grid.parentNode && grid.parentNode.querySelector('.hv-dots');
        if(dots) dots.remove();
        grid.classList.remove('hv-carousel');
        grid.removeAttribute('data-hv-ready');
        grid.removeAttribute('role');
        grid.removeAttribute('tabindex');
        grid.removeAttribute('aria-label');
        grid.scrollLeft=0;
      });
    }

    function build(grid){
      if(grid.hasAttribute('data-hv-ready')) return null;
      var cards=[].slice.call(grid.querySelectorAll(':scope > .hv-card'));
      if(cards.length < 2) return null;

      var track=document.createElement('div');
      track.className='hv-track';
      cards.forEach(function(card){
        var slide=document.createElement('div');
        slide.className='hv-slide';
        slide.appendChild(card);
        track.appendChild(slide);
      });
      grid.appendChild(track);
      grid.classList.add('hv-carousel');
      grid.setAttribute('data-hv-ready','1');
      grid.setAttribute('role','region');
      grid.setAttribute('tabindex','0');

      var slides=[].slice.call(track.children);
      var dots=document.createElement('div');
      dots.className='hv-dots';
      var buttons=slides.map(function(_, idx){
        var b=document.createElement('button');
        b.type='button';
        b.className='hv-dot'+(idx===0?' is-active':'');
        b.addEventListener('click', function(){ pauseAutoplay(); goTo(idx, true); });
        dots.appendChild(b);
        return b;
      });
      grid.parentNode.insertBefore(dots, grid.nextSibling);

      var index=0, autoTimer=null, idleTimer=null, frame=0;
      var paused=false, inView=true, destroyed=false, observer=null;

      function isArabic(){ return document.documentElement.getAttribute('lang')==='ar'; }

      function labelDots(){
        var ar=isArabic();
        buttons.forEach(function(b, idx){
          b.setAttribute('aria-label', ar
            ? ('منتج ' + (idx+1) + ' من ' + buttons.length)
            : ('Produit ' + (idx+1) + ' sur ' + buttons.length));
        });
        grid.setAttribute('aria-label', ar ? 'كاروسال المنتجات' : 'Carrousel produits');
      }

      function centerOf(el){
        var r=el.getBoundingClientRect();
        return r.left + r.width/2;
      }

      // Slide dont le centre est le plus proche du centre du conteneur.
      function nearestIndex(){
        var mid=centerOf(grid), best=0, bestDist=Infinity;
        for(var k=0;k<slides.length;k++){
          var d=Math.abs(centerOf(slides[k]) - mid);
          if(d < bestDist){ bestDist=d; best=k; }
        }
        return best;
      }

      function syncDots(active){
        for(var k=0;k<buttons.length;k++){
          buttons[k].classList.toggle('is-active', k===active);
        }
      }

      // scrollLeft croît toujours vers la droite de l'écran, quel que soit dir :
      // un delta mesuré en pixels écran est donc valable en LTR et en RTL.
      function goTo(to, smooth){
        var n=slides.length;
        index=((to % n) + n) % n;
        syncDots(index);
        var delta=centerOf(slides[index]) - centerOf(grid);
        if(Math.abs(delta) < 1) return;
        if(smooth === false || reduce.matches){
          grid.scrollLeft += delta;
        } else if(typeof grid.scrollBy === 'function'){
          grid.scrollBy({ left: delta, behavior: 'smooth' });
        } else {
          grid.scrollLeft += delta;
        }
      }

      function onScroll(){
        if(frame) return;
        frame=requestAnimationFrame(function(){
          frame=0;
          var k=nearestIndex();
          if(k !== index){ index=k; syncDots(index); }
        });
      }

      function schedule(){
        clearTimeout(autoTimer);
        if(destroyed || reduce.matches) return;
        autoTimer=setTimeout(tick, 3400);
      }

      function tick(){
        if(destroyed) return;
        if(mq.matches && !paused && !document.hidden && inView){
          var next=index + 1;
          // Retour au début sans balayer les 20+ slides intermédiaires.
          if(next >= slides.length) goTo(0, false);
          else goTo(next, true);
        }
        schedule();
      }

      function pauseAutoplay(){
        paused=true;
        clearTimeout(idleTimer);
        idleTimer=setTimeout(function(){ paused=false; }, 7000);
      }

      function onKey(e){
        if(e.key === 'ArrowRight' || e.key === 'ArrowLeft'){
          var back=(e.key === 'ArrowLeft') !== isArabic();
          pauseAutoplay();
          goTo(index + (back ? -1 : 1), true);
          e.preventDefault();
        }
      }

      var resizeTimer=null;
      function onResize(){
        clearTimeout(resizeTimer);
        resizeTimer=setTimeout(function(){
          if(!destroyed && mq.matches) goTo(index, false);
        }, 150);
      }

      function onLangChange(){
        labelDots();
        // Le passage RTL/LTR remet le conteneur à sa position d'origine.
        requestAnimationFrame(function(){ if(!destroyed) goTo(index, false); });
      }

      grid.addEventListener('scroll', onScroll, {passive:true});
      grid.addEventListener('touchstart', pauseAutoplay, {passive:true});
      grid.addEventListener('pointerdown', pauseAutoplay, {passive:true});
      grid.addEventListener('wheel', pauseAutoplay, {passive:true});
      grid.addEventListener('mouseenter', pauseAutoplay);
      grid.addEventListener('keydown', onKey);
      window.addEventListener('resize', onResize, {passive:true});
      document.addEventListener('hv:langchange', onLangChange);

      if('IntersectionObserver' in window){
        inView=false;
        observer=new IntersectionObserver(function(entries){
          inView=entries[0].isIntersecting;
        }, {threshold:0.35});
        observer.observe(grid);
      }

      function destroy(){
        destroyed=true;
        clearTimeout(autoTimer);
        clearTimeout(idleTimer);
        clearTimeout(resizeTimer);
        if(frame) cancelAnimationFrame(frame);
        grid.removeEventListener('scroll', onScroll);
        grid.removeEventListener('touchstart', pauseAutoplay);
        grid.removeEventListener('pointerdown', pauseAutoplay);
        grid.removeEventListener('wheel', pauseAutoplay);
        grid.removeEventListener('mouseenter', pauseAutoplay);
        grid.removeEventListener('keydown', onKey);
        window.removeEventListener('resize', onResize);
        document.removeEventListener('hv:langchange', onLangChange);
        if(observer) observer.disconnect();
      }

      labelDots();
      goTo(0, false);
      schedule();
      return { destroy: destroy, realign: function(){ goTo(index, false); } };
    }

    function start(){
      teardown();
      if(!mq.matches) return;
      document.querySelectorAll('.hv-cat .hv-grid').forEach(function(grid){
        var c=build(grid);
        if(c) instances.push(c);
      });
    }

    start();
    if(typeof mq.addEventListener === 'function') mq.addEventListener('change', start);
    else if(typeof mq.addListener === 'function') mq.addListener(start);
    // Les images produit arrivent en lazy-load : on recale une fois tout chargé.
    window.addEventListener('load', function(){
      instances.forEach(function(c){ c.realign(); });
    }, {once:true});
  })();
})();
</script>
<?php get_footer(); ?>
