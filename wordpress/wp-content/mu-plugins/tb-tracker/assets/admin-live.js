/**
 * TB Tracker — Live admin client.
 * Admin-only, never shipped to the front. Polls one bounded REST endpoint and
 * swaps in fragments rendered server-side, so markup stays single-sourced.
 */
(function () {
  'use strict';

  var cfg = window.tbLiveCfg;
  if (!cfg || !cfg.endpoint) return;

  var FEED_MAX = 220;

  var el = {
    state: document.getElementById('tbl-state'),
    pause: document.getElementById('tbl-pause'),
    refresh: document.getElementById('tbl-refresh'),
    serverTime: document.getElementById('tbl-server-time'),
    lastRefresh: document.getElementById('tbl-last-refresh'),
    verdict: document.getElementById('tbl-verdict'),
    verdictLabel: document.getElementById('tbl-verdict-label'),
    verdictMsg: document.getElementById('tbl-verdict-msg'),
    gauge: document.getElementById('tbl-gauge'),
    gaugeVal: document.getElementById('tbl-gauge-val'),
    feed: document.getElementById('tbl-feed'),
    feedCount: document.getElementById('tbl-feed-count'),
    apply: document.getElementById('tbl-apply'),
    reset: document.getElementById('tbl-reset'),
    fTypes: document.getElementById('tbl-f-types'),
    fSku: document.getElementById('tbl-f-sku'),
    fCampaign: document.getElementById('tbl-f-campaign'),
    fPath: document.getElementById('tbl-f-path'),
    fFbp: document.getElementById('tbl-f-fbp')
  };
  if (!el.feed) return;

  var sinceId = 0;
  var paused = false;
  var inFlight = false;
  var lastPulseAt = 0;
  var lastOkAt = Date.now();
  var timer = null;

  var firstRow = el.feed.querySelector('.tbl-ev');
  if (firstRow) sinceId = parseInt(firstRow.getAttribute('data-id'), 10) || 0;

  function selectedTypes() {
    if (!el.fTypes) return '';
    return Array.prototype.filter
      .call(el.fTypes.options, function (o) { return o.selected; })
      .map(function (o) { return o.value; })
      .join(',');
  }

  function buildUrl(withPulse) {
    var params = new URLSearchParams();
    params.set('since_id', String(sinceId));
    params.set('pulse', withPulse ? '1' : '0');
    var types = selectedTypes();
    if (types) params.set('types', types);
    if (el.fSku && el.fSku.value.trim()) params.set('sku', el.fSku.value.trim());
    if (el.fCampaign && el.fCampaign.value.trim()) params.set('campaign', el.fCampaign.value.trim());
    if (el.fPath && el.fPath.value.trim()) params.set('path', el.fPath.value.trim());
    if (el.fFbp && el.fFbp.value) params.set('fbp', el.fFbp.value);
    return cfg.endpoint + (cfg.endpoint.indexOf('?') === -1 ? '?' : '&') + params.toString();
  }

  function setState(mode, label) {
    if (!el.state) return;
    el.state.className = 'tbl-live-dot' + (mode ? ' is-' + mode : '');
    el.state.textContent = label;
  }

  function swap(id, html) {
    var node = document.getElementById(id);
    if (node && typeof html === 'string') node.innerHTML = html;
  }

  function applyPulse(pulse, html) {
    if (html) {
      Object.keys(html).forEach(function (id) { swap(id, html[id]); });
    }
    if (!pulse) return;

    if (pulse.verdict && el.verdict) {
      el.verdict.className = 'tbl-verdict is-' + pulse.verdict.level;
      if (el.verdictLabel) el.verdictLabel.textContent = pulse.verdict.label;
      if (el.verdictMsg) el.verdictMsg.textContent = pulse.verdict.message;
    }
    if (pulse.match && el.gauge) {
      var pct = parseInt(pulse.match.fbp_pct, 10) || 0;
      el.gauge.style.setProperty('--tbx-p', String(pct));
      if (el.gaugeVal) el.gaugeVal.textContent = pct + '%';
    }
    if (pulse.server_time && el.serverTime) {
      el.serverTime.textContent = String(pulse.server_time).slice(11, 19);
    }
  }

  function emptyFeedNotice() {
    if (el.feed.querySelector('.tbl-ev') || el.feed.querySelector('.tbl-feed-empty')) return;
    var li = document.createElement('li');
    li.className = 'tbl-feed-empty';
    li.textContent = 'Aucun événement sur la fenêtre courante avec ces filtres.';
    el.feed.appendChild(li);
    if (el.feedCount) el.feedCount.textContent = '0 événement';
  }

  function prependFeed(rows) {
    if (!rows || !rows.length) return;
    var frag = document.createDocumentFragment();
    // Server sends newest first; insert oldest first so the newest ends up on top.
    for (var i = rows.length - 1; i >= 0; i--) {
      var holder = document.createElement('div');
      holder.innerHTML = rows[i].html || '';
      var node = holder.firstElementChild;
      if (!node) continue;
      node.classList.add('is-new');
      frag.insertBefore(node, frag.firstChild);
    }
    var empty = el.feed.querySelector('.tbl-feed-empty');
    if (empty) empty.remove();
    el.feed.insertBefore(frag, el.feed.firstChild);

    var items = el.feed.querySelectorAll('.tbl-ev');
    for (var j = FEED_MAX; j < items.length; j++) items[j].remove();
    if (el.feedCount) {
      el.feedCount.textContent = el.feed.querySelectorAll('.tbl-ev').length + ' événements';
    }
  }

  function stamp() {
    if (el.lastRefresh) el.lastRefresh.textContent = new Date().toLocaleTimeString();
  }

  function tick(force) {
    if (inFlight) return;
    if (paused && !force) return;
    if (document.hidden && !force) return;

    var withPulse = force || (Date.now() - lastPulseAt) >= (cfg.pulseEveryMs || 15000);
    inFlight = true;

    fetch(buildUrl(withPulse), {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' }
    })
      .then(function (res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
      })
      .then(function (data) {
        if (!data || !data.ok) throw new Error('bad payload');
        if (withPulse) {
          lastPulseAt = Date.now();
          applyPulse(data.pulse, data.pulse_html);
        }
        if (typeof data.last_id === 'number' && data.last_id > sinceId) sinceId = data.last_id;
        prependFeed(data.feed);
        emptyFeedNotice();
        lastOkAt = Date.now();
        stamp();
        if (!paused) setState('', 'En direct');
      })
      .catch(function () {
        if (Date.now() - lastOkAt > 30000) setState('error', 'Hors ligne');
      })
      .then(function () {
        inFlight = false;
      });
  }

  function reload() {
    sinceId = 0;
    el.feed.innerHTML = '';
    lastPulseAt = 0;
    tick(true);
  }

  if (el.pause) {
    el.pause.addEventListener('click', function () {
      paused = !paused;
      el.pause.textContent = paused ? 'Reprendre' : 'Mettre en pause';
      setState(paused ? 'paused' : '', paused ? 'En pause' : 'En direct');
      if (!paused) tick(true);
    });
  }
  if (el.refresh) el.refresh.addEventListener('click', function () { tick(true); });
  if (el.apply) el.apply.addEventListener('click', reload);
  if (el.reset) {
    el.reset.addEventListener('click', function () {
      if (el.fSku) el.fSku.value = '';
      if (el.fCampaign) el.fCampaign.value = '';
      if (el.fPath) el.fPath.value = '';
      if (el.fFbp) el.fFbp.value = '';
      if (el.fTypes) {
        Array.prototype.forEach.call(el.fTypes.options, function (o) {
          o.selected = ['lead', 'form_start', 'form_error', 'cta_click', 'page_view'].indexOf(o.value) !== -1;
        });
      }
      reload();
    });
  }

  ['tbl-f-sku', 'tbl-f-campaign', 'tbl-f-path'].forEach(function (id) {
    var node = document.getElementById(id);
    if (!node) return;
    node.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        reload();
      }
    });
  });

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && !paused) tick(true);
  });

  timer = setInterval(tick, cfg.intervalMs || 6000);
  window.addEventListener('beforeunload', function () { clearInterval(timer); });
  stamp();
})();
/**
 * TB Tracker — Live admin client.
 * Admin-only, never shipped to the front. Polls one bounded REST endpoint and
 * swaps in fragments rendered server-side, so markup stays single-sourced.
 */
(function () {
  'use strict';

  var cfg = window.tbLiveCfg;
  if (!cfg || !cfg.endpoint) return;

  var FEED_MAX = 220;

  var el = {
    state: document.getElementById('tbl-state'),
    pause: document.getElementById('tbl-pause'),
    refresh: document.getElementById('tbl-refresh'),
    serverTime: document.getElementById('tbl-server-time'),
    lastRefresh: document.getElementById('tbl-last-refresh'),
    verdict: document.getElementById('tbl-verdict'),
    verdictLabel: document.getElementById('tbl-verdict-label'),
    verdictMsg: document.getElementById('tbl-verdict-msg'),
    gauge: document.getElementById('tbl-gauge'),
    gaugeVal: document.getElementById('tbl-gauge-val'),
    feed: document.getElementById('tbl-feed'),
    feedCount: document.getElementById('tbl-feed-count'),
    apply: document.getElementById('tbl-apply'),
    reset: document.getElementById('tbl-reset'),
    fTypes: document.getElementById('tbl-f-types'),
    fSku: document.getElementById('tbl-f-sku'),
    fCampaign: document.getElementById('tbl-f-campaign'),
    fPath: document.getElementById('tbl-f-path'),
    fFbp: document.getElementById('tbl-f-fbp')
  };
  if (!el.feed) return;

  var sinceId = 0;
  var paused = false;
  var inFlight = false;
  var lastPulseAt = 0;
  var lastOkAt = Date.now();
  var timer = null;

  var firstRow = el.feed.querySelector('.tbl-ev');
  if (firstRow) sinceId = parseInt(firstRow.getAttribute('data-id'), 10) || 0;

  function selectedTypes() {
    if (!el.fTypes) return '';
    return Array.prototype.filter
      .call(el.fTypes.options, function (o) { return o.selected; })
      .map(function (o) { return o.value; })
      .join(',');
  }

  function buildUrl(withPulse) {
    var params = new URLSearchParams();
    params.set('since_id', String(sinceId));
    params.set('pulse', withPulse ? '1' : '0');
    var types = selectedTypes();
    if (types) params.set('types', types);
    if (el.fSku && el.fSku.value.trim()) params.set('sku', el.fSku.value.trim());
    if (el.fCampaign && el.fCampaign.value.trim()) params.set('campaign', el.fCampaign.value.trim());
    if (el.fPath && el.fPath.value.trim()) params.set('path', el.fPath.value.trim());
    if (el.fFbp && el.fFbp.value) params.set('fbp', el.fFbp.value);
    return cfg.endpoint + (cfg.endpoint.indexOf('?') === -1 ? '?' : '&') + params.toString();
  }

  function setState(mode, label) {
    if (!el.state) return;
    el.state.className = 'tbl-live-dot' + (mode ? ' is-' + mode : '');
    el.state.textContent = label;
  }

  function swap(id, html) {
    var node = document.getElementById(id);
    if (node && typeof html === 'string') node.innerHTML = html;
  }

  function applyPulse(pulse, html) {
    if (html) {
      Object.keys(html).forEach(function (id) { swap(id, html[id]); });
    }
    if (!pulse) return;

    if (pulse.verdict && el.verdict) {
      el.verdict.className = 'tbl-verdict is-' + pulse.verdict.level;
      if (el.verdictLabel) el.verdictLabel.textContent = pulse.verdict.label;
      if (el.verdictMsg) el.verdictMsg.textContent = pulse.verdict.message;
    }
    if (pulse.match && el.gauge) {
      var pct = parseInt(pulse.match.fbp_pct, 10) || 0;
      el.gauge.style.setProperty('--tbx-p', String(pct));
      if (el.gaugeVal) el.gaugeVal.textContent = pct + '%';
    }
    if (pulse.server_time && el.serverTime) {
      el.serverTime.textContent = String(pulse.server_time).slice(11, 19);
    }
  }

  function emptyFeedNotice() {
    if (el.feed.querySelector('.tbl-ev') || el.feed.querySelector('.tbl-feed-empty')) return;
    var li = document.createElement('li');
    li.className = 'tbl-feed-empty';
    li.textContent = 'Aucun événement sur la fenêtre courante avec ces filtres.';
    el.feed.appendChild(li);
    if (el.feedCount) el.feedCount.textContent = '0 événement';
  }

  function prependFeed(rows) {
    if (!rows || !rows.length) return;
    var frag = document.createDocumentFragment();
    // Server sends newest first; insert oldest first so the newest ends up on top.
    for (var i = rows.length - 1; i >= 0; i--) {
      var holder = document.createElement('div');
      holder.innerHTML = rows[i].html || '';
      var node = holder.firstElementChild;
      if (!node) continue;
      node.classList.add('is-new');
      frag.insertBefore(node, frag.firstChild);
    }
    var empty = el.feed.querySelector('.tbl-feed-empty');
    if (empty) empty.remove();
    el.feed.insertBefore(frag, el.feed.firstChild);

    var items = el.feed.querySelectorAll('.tbl-ev');
    for (var j = FEED_MAX; j < items.length; j++) items[j].remove();
    if (el.feedCount) {
      el.feedCount.textContent = el.feed.querySelectorAll('.tbl-ev').length + ' événements';
    }
  }

  function stamp() {
    if (el.lastRefresh) el.lastRefresh.textContent = new Date().toLocaleTimeString();
  }

  function tick(force) {
    if (inFlight) return;
    if (paused && !force) return;
    if (document.hidden && !force) return;

    var withPulse = force || (Date.now() - lastPulseAt) >= (cfg.pulseEveryMs || 15000);
    inFlight = true;

    fetch(buildUrl(withPulse), {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' }
    })
      .then(function (res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
      })
      .then(function (data) {
        if (!data || !data.ok) throw new Error('bad payload');
        if (withPulse) {
          lastPulseAt = Date.now();
          applyPulse(data.pulse, data.pulse_html);
        }
        if (typeof data.last_id === 'number' && data.last_id > sinceId) sinceId = data.last_id;
        prependFeed(data.feed);
        emptyFeedNotice();
        lastOkAt = Date.now();
        stamp();
        if (!paused) setState('', 'En direct');
      })
      .catch(function () {
        if (Date.now() - lastOkAt > 30000) setState('error', 'Hors ligne');
      })
      .then(function () {
        inFlight = false;
      });
  }

  function reload() {
    sinceId = 0;
    el.feed.innerHTML = '';
    lastPulseAt = 0;
    tick(true);
  }

  if (el.pause) {
    el.pause.addEventListener('click', function () {
      paused = !paused;
      el.pause.textContent = paused ? 'Reprendre' : 'Mettre en pause';
      setState(paused ? 'paused' : '', paused ? 'En pause' : 'En direct');
      if (!paused) tick(true);
    });
  }
  if (el.refresh) el.refresh.addEventListener('click', function () { tick(true); });
  if (el.apply) el.apply.addEventListener('click', reload);
  if (el.reset) {
    el.reset.addEventListener('click', function () {
      if (el.fSku) el.fSku.value = '';
      if (el.fCampaign) el.fCampaign.value = '';
      if (el.fPath) el.fPath.value = '';
      if (el.fFbp) el.fFbp.value = '';
      if (el.fTypes) {
        Array.prototype.forEach.call(el.fTypes.options, function (o) {
          o.selected = ['lead', 'form_start', 'form_error', 'cta_click', 'page_view'].indexOf(o.value) !== -1;
        });
      }
      reload();
    });
  }

  ['tbl-f-sku', 'tbl-f-campaign', 'tbl-f-path'].forEach(function (id) {
    var node = document.getElementById(id);
    if (!node) return;
    node.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        reload();
      }
    });
  });

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && !paused) tick(true);
  });

  timer = setInterval(tick, cfg.intervalMs || 6000);
  window.addEventListener('beforeunload', function () { clearInterval(timer); });
  stamp();
})();
