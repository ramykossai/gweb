<?php
/**
 * Template Name: HD Merci
 * Thank-you pages: neon message + full-width slides (no Colibri, no page title).
 *
 * Never HTML-cache merci: custom conversions / Lead / eid query must stay fresh
 * (same rule as LP — Hostinger/LiteSpeed must not serve a shared merci shell).
 */
if (!defined('DONOTCACHEPAGE')) {
    define('DONOTCACHEPAGE', true);
}
do_action('litespeed_control_set_nocache', 'hd merci thank-you');
if (!headers_sent()) {
    header('X-LiteSpeed-Cache-Control: no-cache', false);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', false);
    nocache_headers();
}
remove_filter('the_content', 'wpautop');
remove_filter('the_content', 'shortcode_unautop');
get_header(null, ['body_class' => 'hd-landing hd-merci-page']);
while (have_posts()) :
    the_post();
    the_content();
endwhile;
?>
<script>
(function(){
  var root=document.querySelector('.hd-merci');
  if(root){
    try{
      var cs=getComputedStyle(root);
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

  // Same slider as LP: 1 slide full width, autoplay, swipe (px translate)
  document.querySelectorAll('[data-hd-colibri-slider]').forEach(function(el){
    var track=el.querySelector('.hd-colibri-slider__track');
    var slides=el.querySelectorAll('.hd-colibri-slider__slide');
    var dotsWrap=el.querySelector('.hd-colibri-slider__dots');
    if(!track || slides.length < 2) return;
    var i=0;
    var delay=parseInt(el.getAttribute('data-autoplay')||'4500',10);
    var timer=null;
    var startX=0, dx=0, dragging=false;

    function slideW(){ return el.clientWidth || track.clientWidth || 0; }
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

    el.addEventListener('touchstart', function(e){
      if(!e.touches||!e.touches[0]) return;
      dragging=true; startX=e.touches[0].clientX; dx=0; stop();
    }, {passive:true});
    el.addEventListener('touchmove', function(e){
      if(!dragging||!e.touches||!e.touches[0]) return;
      dx=e.touches[0].clientX-startX;
    }, {passive:true});
    el.addEventListener('touchend', function(){
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
})();
</script>
<?php get_footer(); ?>
