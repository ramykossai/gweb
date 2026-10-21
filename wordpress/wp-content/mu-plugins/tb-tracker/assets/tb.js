/**
 * TB client (P0) — vanilla, defer, beacon/fetch keepalive.
 * Loaded only when tb_tracker_enabled + tb_js_engagement are ON.
 * Discrete first-party beacons; no Canvas/WebGL fingerprinting.
 *
 * clickId must NOT be trusted from cached HTML — resolve cookie / mint locally.
 */
(function () {
  'use strict';
  var cfg = window.tbTrackerCfg;
  if (!cfg || !cfg.endpoint) return;
  if (!cfg.flags || !cfg.flags.engagement) return;

  function cookieGet(name) {
    try {
      var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
      return m ? decodeURIComponent(m[1]) : '';
    } catch (e) {
      return '';
    }
  }

  function qsGet(name) {
    try {
      return new URLSearchParams(window.location.search).get(name) || '';
    } catch (e) {
      return '';
    }
  }

  function mintId() {
    return 'tb_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
  }

  function setTbclid(id) {
    try {
      var ttl = (typeof cfg.cookieTtl === 'number' && cfg.cookieTtl > 0) ? cfg.cookieTtl : 34560000;
      var parts = ['tbclid=' + encodeURIComponent(id), 'path=/', 'max-age=' + ttl, 'SameSite=Lax'];
      if (location.protocol === 'https:') parts.push('Secure');
      document.cookie = parts.join(';');
    } catch (e) {}
    try {
      if (window.localStorage) localStorage.setItem('tbclid', id);
    } catch (e2) {}
    try {
      document.querySelectorAll('.hd-lead-form [name="tbclid"]').forEach(function (el) {
        el.value = id;
      });
    } catch (e3) {}
  }

  function resolveClickId() {
    var id = String(qsGet('tbclid') || cookieGet('tbclid') || '').trim();
    if (!id) {
      try { id = String(localStorage.getItem('tbclid') || '').trim(); } catch (e) {}
    }
    // Never prefer a baked cfg.clickId from HTML (LiteSpeed cache poison).
    if (!id) id = mintId();
    setTbclid(id);
    return id;
  }

  var clickId = resolveClickId();
  var endpoint = String(cfg.endpoint);
  var ajaxUrl = cfg.ajaxUrl || '';
  var nonce = cfg.nonce || '';
  var pageId = cfg.pageId || 0;
  var flags = cfg.flags || {};
  var sample = typeof cfg.sampleRate === 'number' ? cfg.sampleRate : 100;
  if (sample < 100 && Math.random() * 100 >= sample) return;

  function pathOnly() {
    try {
      var p = location.pathname || '/';
      var params = new URLSearchParams(location.search || '');
      var keep = [];
      ['page_id', 'p', 'pagename'].forEach(function (k) {
        var v = params.get(k);
        if (v) keep.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
      });
      return keep.length ? (p + '?' + keep.join('&')) : p;
    } catch (e) {
      return '/';
    }
  }

  function send(type, meta) {
    var body = JSON.stringify({
      c: clickId,
      t: type,
      u: pathOnly(),
      p: pageId || undefined,
      m: meta || {},
      n: nonce || undefined,
      ts: Math.floor(Date.now() / 1000)
    });
    if (body.length > 2400) return;

    var sent = false;
    try {
      fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: body,
        keepalive: true,
        credentials: 'same-origin'
      }).then(function (res) {
        if (res && res.ok) return;
        postAjax(body);
      }).catch(function () {
        postAjax(body);
      });
      sent = true;
    } catch (e) {}

    if (!sent) {
      try {
        if (navigator.sendBeacon) {
          sent = navigator.sendBeacon(endpoint, body);
        }
      } catch (e2) {}
    }
    if (!sent) postAjax(body);
  }

  function postAjax(body) {
    if (!ajaxUrl) return;
    try {
      fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: body,
        keepalive: true,
        credentials: 'same-origin'
      }).catch(function () {});
    } catch (e) {}
  }

  function schedule(fn) {
    if (typeof requestIdleCallback === 'function') {
      requestIdleCallback(function () { fn(); }, { timeout: 2500 });
    } else {
      setTimeout(fn, 1200);
    }
  }

  function referrerBits() {
    var out = {};
    try {
      var r = document.referrer || '';
      if (!r) return out;
      var u = new URL(r);
      out.ref_host = String(u.hostname || '').slice(0, 80);
      out.ref_path = String(u.pathname || '').slice(0, 80);
      var q = u.searchParams.get('q') || u.searchParams.get('utm_term') || '';
      if (q) out.ref_q = String(q).slice(0, 80);
    } catch (e) {}
    return out;
  }

  /** Cache hits still create a click row + page_view. Include fbp early when Meta already set cookies. */
  function sendLandingBeacon() {
    var meta = {};
    ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid'].forEach(function (k) {
      var v = qsGet(k);
      if (v) meta[k] = String(v).slice(0, 120);
    });
    var ref = referrerBits();
    if (ref.ref_host) meta.ref_host = ref.ref_host;
    var fbp = cookieGet('_fbp');
    var fbc = cookieGet('_fbc');
    if (fbp && /^fb\.\d+\.\d+\.\d+$/.test(fbp)) meta.fbp = fbp.slice(0, 120);
    if (fbc && /^fb\.\d+\.\d+\./.test(fbc)) meta.fbc = fbc.slice(0, 120);
    send('page_view', meta);
  }

  function collectContext() {
    if (!flags.context) return;
    var meta = {};
    try {
      meta.lang = (navigator.language || '').slice(0, 16);
      meta.tz = (Intl.DateTimeFormat().resolvedOptions().timeZone || '').slice(0, 64);
      meta.vw = window.innerWidth | 0;
      meta.vh = window.innerHeight | 0;
      meta.dpr = window.devicePixelRatio || 1;
      var nav = performance.getEntriesByType && performance.getEntriesByType('navigation');
      if (nav && nav[0] && nav[0].type) meta.nav_type = String(nav[0].type).slice(0, 32);
    } catch (e) {}

    try {
      var c = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
      if (c) {
        if (c.effectiveType) meta.net = String(c.effectiveType).slice(0, 16);
        if (typeof c.downlink === 'number' && isFinite(c.downlink)) {
          meta.dl = Math.round(c.downlink * 10) / 10;
        }
      }
    } catch (e) {}

    try {
      if (window.matchMedia) {
        if (window.matchMedia('(prefers-color-scheme: dark)').matches) meta.scheme = 'dark';
        else if (window.matchMedia('(prefers-color-scheme: light)').matches) meta.scheme = 'light';
      }
    } catch (e) {}

    try {
      meta.touch = (('ontouchstart' in window) || (navigator.maxTouchPoints > 0)) ? 1 : 0;
    } catch (e) {}

    var ref = referrerBits();
    if (ref.ref_host) meta.ref_host = ref.ref_host;
    if (ref.ref_path) meta.ref_path = ref.ref_path;
    if (ref.ref_q) meta.ref_q = ref.ref_q;

    send('context', meta);
    sendAttrUpdate();
  }

  /** Push _fbp/_fbc (+ UTM) when present — safe to call multiple times (server merges). */
  function sendAttrUpdate() {
    var attr = {};
    var fbp = cookieGet('_fbp');
    var fbc = cookieGet('_fbc');
    var tt = qsGet('ttclid') || cookieGet('ttclid');
    if (fbp && /^fb\.\d+\.\d+\.\d+$/.test(fbp)) attr.fbp = fbp.slice(0, 120);
    if (fbc && /^fb\.\d+\.\d+\./.test(fbc)) attr.fbc = fbc.slice(0, 120);
    if (tt) attr.ttclid = tt.slice(0, 120);
    ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid'].forEach(function (k) {
      var v = qsGet(k);
      if (v) attr[k] = v.slice(0, 120);
    });
    if (Object.keys(attr).length) send('attr_update', attr);
    return !!(attr.fbp || attr.fbc);
  }

  /**
   * Meta often sets _fbp after first paint (slow FB in-app). Retry until fbp/fbc
   * seen or deadline — no extra page_view. Helps ALL pixels (one domain cookie).
   */
  function scheduleFbpRetries() {
    var delays = [800, 2000, 4000, 8000, 15000, 25000, 40000];
    var done = false;
    function tick() {
      if (done) return;
      try {
        if (sendAttrUpdate()) {
          var fbp = cookieGet('_fbp');
          if (fbp && /^fb\.\d+\.\d+\.\d+$/.test(fbp)) done = true;
        }
      } catch (e) {}
    }
    delays.forEach(function (ms) {
      setTimeout(tick, ms);
    });
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible') tick();
    }, { passive: true });
  }

  var activeMs = 0;
  var visibleMs = 0;
  var idleMs = 0;
  var lastTick = Date.now();
  var lastInput = Date.now();
  var maxScroll = 0;
  var scrollSent = {};
  var engaged = flags.engagement !== false;
  var IDLE_GAP = 5000;
  var hiddenCount = 0;
  var engagePulse = 0;

  function onInput() {
    lastInput = Date.now();
  }

  function tick() {
    var now = Date.now();
    var dt = now - lastTick;
    lastTick = now;
    if (document.visibilityState === 'visible') {
      visibleMs += dt;
      if (now - lastInput < IDLE_GAP) activeMs += dt;
      else idleMs += dt;
    }
  }

  function scrollPct() {
    var el = document.documentElement;
    var body = document.body;
    var scrollTop = window.pageYOffset || el.scrollTop || body.scrollTop || 0;
    var height = Math.max(el.scrollHeight, body.scrollHeight) - (window.innerHeight || 0);
    if (height <= 0) return 100;
    return Math.min(100, Math.round((scrollTop / height) * 100));
  }

  var scrollRaf = 0;
  function onScroll() {
    if (scrollRaf) return;
    scrollRaf = requestAnimationFrame(function () {
      scrollRaf = 0;
      var pct = scrollPct();
      if (pct > maxScroll) maxScroll = pct;
      [25, 50, 75, 100].forEach(function (mark) {
        if (maxScroll >= mark && !scrollSent[mark]) {
          scrollSent[mark] = 1;
          send('scroll', { max_pct: mark });
        }
      });
    });
  }

  function flushEngage() {
    if (!engaged) return;
    tick();
    if (activeMs < 500 && visibleMs < 500) return;
    send('engage', {
      active_ms: Math.round(activeMs),
      visible_ms: Math.round(visibleMs),
      idle_ms: Math.round(idleMs),
      hid: hiddenCount || undefined,
      max_pct: maxScroll || undefined
    });
  }

  function onVisibility() {
    tick();
    if (document.visibilityState === 'hidden') {
      hiddenCount++;
      flushEngage();
    }
  }

  function onClick(ev) {
    var t = ev.target;
    if (!t || !t.closest) return;
    var cta = t.closest('[data-tb-cta], .hd-lead-form button[type="submit"], a[href="#form"], a[href*="#form"]');
    if (!cta) return;
    var id = cta.getAttribute('data-tb-cta') || cta.getAttribute('name') || cta.id || cta.tagName.toLowerCase();
    send('cta_click', { cta_id: String(id).slice(0, 80) });
  }

  var formStarted = {};
  function formKey(form) {
    var key = form.getAttribute('data-form') || form.getAttribute('data-tb-form') || form.id || '';
    if (!key) {
      try {
        var hid = form.querySelector('input[name="form_key"]');
        if (hid && hid.value) key = hid.value;
      } catch (e) {}
    }
    return key || 'lead';
  }
  function onFocusIn(ev) {
    var t = ev.target;
    if (!t || !t.closest) return;
    if (!t.matches('input,select,textarea')) return;
    var form = t.closest('form.hd-lead-form, form[data-tb-form], .hd-lead-form');
    if (!form) return;
    var key = formKey(form);
    if (formStarted[key]) return;
    formStarted[key] = 1;
    send('form_start', { form_key: String(key).slice(0, 80) });
  }

  function onInvalid(ev) {
    var t = ev.target;
    if (!t || !t.closest) return;
    var form = t.closest('form.hd-lead-form, form[data-tb-form], .hd-lead-form');
    if (!form) return;
    var key = formKey(form);
    var err = (t.validationMessage || t.type || 'invalid').toString().slice(0, 80);
    send('form_error', { form_key: String(key).slice(0, 80), err: err });
  }

  function onSubmitCapture(ev) {
    var form = ev.target;
    if (!form || !form.matches) return;
    if (!form.matches('form.hd-lead-form, form[data-tb-form]')) return;
    var key = formKey(form);
    if (!formStarted[key]) {
      formStarted[key] = 1;
      send('form_start', { form_key: String(key).slice(0, 80) });
    }
    try {
      if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
        var bad = form.querySelector(':invalid');
        var err = (bad && (bad.validationMessage || bad.type)) || 'invalid';
        send('form_error', { form_key: String(key).slice(0, 80), err: String(err).slice(0, 80) });
      }
    } catch (e) {}
    try { flushEngage(); } catch (e2) {}
  }

  function boot() {
    sendLandingBeacon();
    schedule(collectContext);
    scheduleFbpRetries();

    if (!engaged) return;

    ['pointerdown', 'keydown', 'touchstart', 'mousemove'].forEach(function (evt) {
      document.addEventListener(evt, onInput, { passive: true });
    });
    document.addEventListener('visibilitychange', onVisibility, { passive: true });
    window.addEventListener('scroll', onScroll, { passive: true });
    document.addEventListener('click', onClick, true);
    document.addEventListener('focusin', onFocusIn, true);
    document.addEventListener('invalid', onInvalid, true);
    document.addEventListener('submit', onSubmitCapture, true);

    setInterval(tick, 2000);
    setInterval(function () {
      engagePulse++;
      if (engagePulse % 2 === 0) flushEngage();
    }, 45000);
    window.addEventListener('pagehide', flushEngage);
    window.addEventListener('beforeunload', flushEngage);
  }

  // Boot on DOMContentLoaded (not window.load) — full load drops short Meta-ad visits
  // while browser pixels in <head> already fired. page_view stays one shot per boot.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      setTimeout(boot, 50);
    });
  } else {
    setTimeout(boot, 50);
  }
})();
