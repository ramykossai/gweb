<?php
/**
 * Template Name: HD Landing
 * Mobile-first LP: vertical slides + sticky CTA + glowing popup form + counter.
 */
// Lead forms embed a WP nonce — never serve a shared LiteSpeed HTML cache (stale nonce → "Invalid form").
if (!defined('DONOTCACHEPAGE')) {
    define('DONOTCACHEPAGE', true);
}
do_action('litespeed_control_set_nocache', 'hd landing lead form');
if (!headers_sent()) {
    header('X-LiteSpeed-Cache-Control: no-cache', false);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', false);
    nocache_headers();
}
remove_filter('the_content', 'wpautop');
remove_filter('the_content', 'shortcode_unautop');
get_header(null, ['body_class' => 'hd-landing']);
while (have_posts()) :
    the_post();
    the_content();
endwhile;
?>
<script>
(function(){
  function pad(n){ return (n<10?'0':'')+n; }
  function rand(min,max){ return min+Math.floor(Math.random()*(max-min+1)); }

  // Sync page background from LP vars (body cannot inherit from child)
  var lp=document.querySelector('.hd-lp');
  if(lp){
    try{
      var cs=getComputedStyle(lp);
      var bg=(cs.getPropertyValue('--hd-bg')||'').trim();
      var fg=(cs.getPropertyValue('--hd-fg')||'').trim();
      if(bg){
        document.documentElement.style.setProperty('--hd-bg', bg);
        document.body.style.setProperty('--hd-bg', bg);
        document.documentElement.style.background=bg;
        document.body.style.background=bg;
      }
      if(fg){
        document.documentElement.style.setProperty('--hd-fg', fg);
        document.body.style.setProperty('--hd-fg', fg);
        document.body.style.color=fg;
      }
    }catch(e){}
  }

  // Top offer bar: sticky in-flow (no dark gap). Ensure it sits above slides.
  var bar=document.querySelector('[data-hd-offer-bar]');
  if(bar && lp){
    try{
      // Remove wpautop empty <p> that create a dark band
      Array.prototype.slice.call(lp.children).forEach(function(ch){
        if(ch.tagName==='P' && !ch.textContent.replace(/\u00a0/g,'').trim() && !ch.querySelector('img,input,button,iframe')){
          ch.parentNode.removeChild(ch);
        }
      });
      if(bar.parentElement !== lp || lp.firstElementChild !== bar){
        lp.insertBefore(bar, lp.firstChild);
      }
      lp.style.paddingTop='0px';
      lp.style.removeProperty('--hd-offer-h');
      document.documentElement.style.removeProperty('--hd-offer-h');
      Array.prototype.slice.call(lp.querySelectorAll('.hd-offer-spacer')).forEach(function(ch){
        if(ch.parentNode) ch.parentNode.removeChild(ch);
      });
    }catch(e){}
    var key=bar.getAttribute('data-hd-offer-key')||'lp';
    var seedSec=parseInt(bar.getAttribute('data-hd-offer-secs')||'10800',10);
    var storageKey='hd_offer_end_'+key;
    var now=Date.now();
    var end=0;
    try{ end=parseInt(localStorage.getItem(storageKey)||'0',10)||0; }catch(e){}
    if(!end || end<now){
      end=now+seedSec*1000;
      try{ localStorage.setItem(storageKey,String(end)); }catch(e){}
    }
    var hEl=bar.querySelector('[data-hd-offer-h]');
    var mEl=bar.querySelector('[data-hd-offer-m]');
    var sEl=bar.querySelector('[data-hd-offer-s]');
    function tickOffer(){
      var left=Math.max(0,Math.floor((end-Date.now())/1000));
      var h=Math.floor(left/3600);
      var m=Math.floor((left%3600)/60);
      var s=left%60;
      if(hEl) hEl.textContent=pad(h);
      if(mEl) mEl.textContent=pad(m);
      if(sEl) sEl.textContent=pad(s);
      if(left<=0) bar.classList.add('hd-offer-ended');
    }
    tickOffer();
    setInterval(tickOffer,1000);
  }

  var popup=document.querySelector('.hd-popup');

  // Colibri-like sliders: 1 slide plein largeur, autoplay ~4.5s, swipe
  // IMPORTANT: translate en px (pas %) — le % se base sur la largeur du track.
  document.querySelectorAll('[data-hd-colibri-slider]').forEach(function(root){
    var track=root.querySelector('.hd-colibri-slider__track');
    var slides=root.querySelectorAll('.hd-colibri-slider__slide');
    var dotsWrap=root.querySelector('.hd-colibri-slider__dots');
    if(!track || slides.length < 2) return;
    var i=0;
    var delay=parseInt(root.getAttribute('data-autoplay')||'4500',10);
    var timer=null;
    var startX=0, dx=0, dragging=false;

    function slideW(){ return root.clientWidth || track.clientWidth || 0; }
    function go(n){
      i=(n+slides.length)%slides.length;
      var w=slideW();
      track.style.transform='translate3d(' + (-i*w) + 'px,0,0)';
      if(dotsWrap){
        Array.prototype.forEach.call(dotsWrap.children, function(d, idx){
          d.classList.toggle('is-active', idx===i);
        });
      }
    }
    function play(){
      stop();
      timer=setInterval(function(){ go(i+1); }, delay);
    }
    function stop(){ if(timer){ clearInterval(timer); timer=null; } }

    if(dotsWrap && !dotsWrap.children.length){
      for(var d=0; d<slides.length; d++){
        (function(idx){
          var b=document.createElement('button');
          b.type='button';
          b.className='hd-colibri-slider__dot'+(idx===0?' is-active':'');
          b.setAttribute('aria-label','Slide '+(idx+1));
          b.addEventListener('click', function(){ go(idx); play(); });
          dotsWrap.appendChild(b);
        })(d);
      }
    }

    root.addEventListener('touchstart', function(e){
      if(!e.touches||!e.touches[0]) return;
      dragging=true; startX=e.touches[0].clientX; dx=0; stop();
    }, {passive:true});
    root.addEventListener('touchmove', function(e){
      if(!dragging||!e.touches||!e.touches[0]) return;
      dx=e.touches[0].clientX-startX;
    }, {passive:true});
    root.addEventListener('touchend', function(){
      if(!dragging) return;
      dragging=false;
      if(Math.abs(dx)>40) go(i+(dx<0?1:-1));
      play();
    });

    go(0);
    play();
    window.addEventListener('resize', function(){ go(i); });
    document.addEventListener('visibilitychange', function(){
      if(document.hidden) stop(); else play();
    });
  });

  // FAQ accordion
  document.querySelectorAll('[data-hd-faq]').forEach(function(faq){
    faq.querySelectorAll('.hd-faq__item').forEach(function(item){
      var btn=item.querySelector('.hd-faq__q');
      if(!btn) return;
      btn.addEventListener('click', function(){
        var open=item.classList.contains('is-open');
        faq.querySelectorAll('.hd-faq__item.is-open').forEach(function(x){ x.classList.remove('is-open'); });
        if(!open) item.classList.add('is-open');
      });
    });
  });

  if(!popup) return;

  var formKey=popup.getAttribute('data-hd-popup-key')||'';
  var DONE_KEY='hd_lead_done_v1';
  // TEMP OFF — same anti-doublon as mu-plugin (was blocking popup / forms).
  var DONE_TTL=0;
  var ANTI_DUP_ENABLED=false;
  var SHOWN_KEY='hd_popup_shown_'+formKey;

  function hdTrack(type, meta){
    try{
      var cfg=window.tbTrackerCfg;
      if(!cfg||!cfg.endpoint) return;
      // cfg.clickId is intentionally empty (LiteSpeed cache) — resolve like tb.js.
      var cid='';
      try{
        var m=document.cookie.match(/(?:^|; )tbclid=([^;]*)/);
        if(m) cid=decodeURIComponent(m[1]||'');
      }catch(e1){}
      if(!cid){ try{ cid=String(localStorage.getItem('tbclid')||''); }catch(e2){} }
      if(!cid){ try{ cid=String(new URLSearchParams(location.search).get('tbclid')||''); }catch(e3){} }
      if(!cid && cfg.clickId) cid=String(cfg.clickId);
      if(!cid) return;
      var uPath='/';
      try{
        var params=new URLSearchParams(location.search||'');
        var keep=[];
        ['page_id','p','pagename'].forEach(function(k){ var v=params.get(k); if(v) keep.push(encodeURIComponent(k)+'='+encodeURIComponent(v)); });
        uPath=(location.pathname||'/')+(keep.length?('?'+keep.join('&')):'');
      }catch(e0){ uPath=location.pathname||'/'; }
      var body=JSON.stringify({
        c:String(cid),
        t:String(type),
        u:uPath,
        p:cfg.pageId||undefined,
        m:meta||{},
        n:cfg.nonce||undefined,
        ts:Math.floor(Date.now()/1000)
      });
      fetch(String(cfg.endpoint),{method:'POST',headers:{'Content-Type':'application/json'},body:body,keepalive:true,credentials:'same-origin'}).catch(function(){});
    }catch(e){}
  }

  function isFormDone(key){
    if(!ANTI_DUP_ENABLED || !key) return false;
    try{
      var map=JSON.parse(localStorage.getItem(DONE_KEY)||'{}')||{};
      var ts=parseInt(map[key],10)||0;
      return !!(ts && DONE_TTL>0 && (Date.now()-ts)<DONE_TTL);
    }catch(e){ return false; }
  }
  function scrollPct(){
    var h=document.documentElement.scrollHeight-window.innerHeight;
    if(h<=0) return 0;
    return Math.round(100*(window.scrollY||document.documentElement.scrollTop||0)/h);
  }
  function isOnForm(){
    var ae=document.activeElement;
    return !!(ae && ae.closest && ae.closest('.hd-lead-form'));
  }
  // Soft skips delay the ~10s open only — hard deadline at 20s still opens (except fatal).
  function softSkipReason(){
    try{ if(sessionStorage.getItem(SHOWN_KEY)) return 'session'; }catch(e){}
    if(isOnForm()) return 'on_form';
    return '';
  }
  function isFatalSkip(){
    return isFormDone(formKey);
  }

  // Clone jauge+visiteurs under every LP inline form (non-sticky).
  var socialSrc=popup.querySelector('[data-hd-social]');
  if(socialSrc){
    document.querySelectorAll('.hd-inline-form').forEach(function(box){
      if(box.querySelector('[data-hd-social], .hd-lp-social')) return;
      var clone=socialSrc.cloneNode(true);
      clone.classList.remove('hd-lp-social--in-popup');
      clone.classList.add('hd-lp-social--inline');
      box.appendChild(clone);
    });
  }

  function syncStockBars(count,start){
    if(!start) return;
    var pct=Math.max(8, Math.round((count/start)*100));
    document.querySelectorAll('[data-hd-stock-bar]').forEach(function(bar){
      bar.style.width=pct+'%';
      bar.style.animation='none';
    });
  }
  function startStock(){
    var stockEl=document.querySelector('[data-hd-stock]');
    if(!stockEl || stockEl.getAttribute('data-hd-stock-run')) return;
    stockEl.setAttribute('data-hd-stock-run','1');
    var key=stockEl.getAttribute('data-hd-stock-key')||'lp';
    var start=parseInt(stockEl.getAttribute('data-hd-stock-start')||'30',10);
    var floor=parseInt(stockEl.getAttribute('data-hd-stock-floor')||'5',10);
    var sk='hd_stock_'+key;
    var count=start;
    try{
      var saved=parseInt(sessionStorage.getItem(sk)||'',10);
      if(saved && saved>=floor && saved<=start) count=saved;
    }catch(e){}
    function paint(){
      document.querySelectorAll('[data-hd-stock]').forEach(function(el){ el.textContent=String(count); });
      syncStockBars(count,start);
    }
    paint();
    function scheduleDrop(){
      setTimeout(function(){
        if(count>floor){
          var drop=(count-floor>8 && Math.random()<0.18)?2:1;
          count=Math.max(floor, count-drop);
          paint();
          try{ sessionStorage.setItem(sk,String(count)); }catch(e){}
        }
        scheduleDrop();
      }, rand(4000,14000));
    }
    scheduleDrop();
  }

  function startVisitors(){
    var visWrap=document.querySelector('[data-hd-visitors]');
    if(!visWrap || visWrap.getAttribute('data-hd-visitors-run')) return;
    visWrap.setAttribute('data-hd-visitors-run','1');
    var base=parseInt(visWrap.getAttribute('data-hd-visitors-base')||'200',10);
    var key=visWrap.getAttribute('data-hd-visitors-key')||'lp';
    var vk='hd_visitors_'+key;
    var n=base;
    try{
      var saved=parseInt(sessionStorage.getItem(vk)||'',10);
      if(saved && saved>50) n=saved;
    }catch(e){}
    function paint(){
      document.querySelectorAll('[data-hd-visitors-n]').forEach(function(el){ el.textContent=String(n); });
    }
    paint();
    function tickVis(){
      var delta=rand(-3,5);
      n=Math.max(Math.floor(base*0.55), Math.min(base+90, n+delta));
      paint();
      try{ sessionStorage.setItem(vk,String(n)); }catch(e){}
      setTimeout(tickVis, rand(3500,9000));
    }
    setTimeout(tickVis, rand(2500,6000));
  }

  startStock();
  startVisitors();

  var openedOnce=false;
  var closedThisView=false;
  function syncPopupChrome(){
    if(popup.classList.contains('open')){
      document.body.classList.add('hd-popup-is-open');
    }else{
      document.body.classList.remove('hd-popup-is-open');
    }
  }
  function open(trigger){
    trigger=trigger||'cta';
    if((trigger==='auto' || trigger==='auto_force') && isFatalSkip()){
      hdTrack('popup_skip',{reason:'done_4h',trigger:trigger,form_key:formKey});
      return;
    }
    popup.classList.add('open');
    openedOnce=true;
    try{ sessionStorage.setItem(SHOWN_KEY,'1'); }catch(e){}
    hdTrack('popup_show',{trigger:String(trigger).slice(0,20),form_key:String(formKey).slice(0,80),scroll:scrollPct()});
    syncPopupChrome();
    setTimeout(function(){
      var done=popup.querySelector('.hd-lead-done');
      if(done && !done.hidden) return;
      var phone=popup.querySelector('.hd-lead-form [name="lead_phone"]');
      if(phone){ try{ phone.focus(); }catch(e){} }
    }, 60);
  }
  function close(){
    if(popup.classList.contains('open')){
      closedThisView=true;
      hdTrack('popup_skip',{reason:'closed',trigger:'user',form_key:formKey,scroll:scrollPct()});
    }
    popup.classList.remove('open');
    syncPopupChrome();
  }
  document.querySelectorAll('[data-hd-open-form]').forEach(function(el){
    el.addEventListener('click', function(e){
      e.preventDefault();
      open(isFormDone(formKey)?'cta_done':'cta');
    });
  });
  var c=popup.querySelector('[data-hd-close]');
  if(c) c.addEventListener('click', close);
  popup.addEventListener('click', function(e){ if(e.target===popup) close(); });

  // ~10s soft auto-open; soft skips only delay. By 20s hard-force once (unless lead done / already seen&closed this view).
  function tryAutoOpen(force){
    if(openedOnce || closedThisView || popup.classList.contains('open')) return;
    if(isFatalSkip()){
      hdTrack('popup_skip',{reason:'done_4h',trigger:force?'auto_force':'auto',form_key:formKey,scroll:scrollPct()});
      return;
    }
    if(!force){
      var reason=softSkipReason();
      if(reason){
        hdTrack('popup_skip',{reason:String(reason).slice(0,40),trigger:'auto',form_key:formKey,scroll:scrollPct()});
        return;
      }
      open('auto');
      return;
    }
    open('auto_force');
  }
  setTimeout(function(){ tryAutoOpen(false); }, 10000);
  setTimeout(function(){ tryAutoOpen(true); }, 20000);
})();
</script>

<?php get_footer(); ?>
