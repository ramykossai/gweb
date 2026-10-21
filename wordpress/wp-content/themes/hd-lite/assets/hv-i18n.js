/**
 * Herbal Vitaly — i18n FR / Darija tunisienne (arabe)
 * data-i18n + exact text map + sticky switch 🇫🇷 FR | 🇹🇳 TN
 */
(function () {
  'use strict';

  var STORE = 'hv_lang';
  var dict = (window.HV_I18N && window.HV_I18N.dict) || null;
  var year = String(new Date().getFullYear());

  // Inline SVG so flags render identically on Windows/Android (no emoji flag support).
  var FLAG_FR =
    '<svg class="hv-flag" viewBox="0 0 21 15" aria-hidden="true" focusable="false">' +
    '<rect width="21" height="15" fill="#f5f5f5"/>' +
    '<rect width="7" height="15" fill="#002654"/>' +
    '<rect x="14" width="7" height="15" fill="#ce1126"/>' +
    '</svg>';

  var FLAG_TN =
    '<svg class="hv-flag" viewBox="0 0 21 15" aria-hidden="true" focusable="false">' +
    '<rect width="21" height="15" fill="#e70013"/>' +
    '<circle cx="10.5" cy="7.5" r="4.6" fill="#fff"/>' +
    '<circle cx="10.5" cy="7.5" r="3.4" fill="#e70013"/>' +
    '<circle cx="11.5" cy="7.5" r="2.7" fill="#fff"/>' +
    '<path d="M11.85 5.75 L12.25 6.95 L13.51 6.96 L12.50 7.71 L12.88 8.92 ' +
    'L11.85 8.18 L10.82 8.92 L11.20 7.71 L10.19 6.96 L11.45 6.95 Z" fill="#e70013"/>' +
    '</svg>';

  function detectDefault() {
    try {
      var saved = localStorage.getItem(STORE);
      if (saved === 'fr' || saved === 'ar') return saved;
    } catch (e) {}
    var nav = (navigator.languages && navigator.languages[0]) || navigator.language || 'fr';
    nav = String(nav).toLowerCase();
    if (nav.indexOf('ar') === 0 || nav.indexOf('ar-') === 0) return 'ar';
    return 'fr';
  }

  function t(lang, key) {
    if (!dict || !dict[lang] || !dict[lang][key]) return null;
    return String(dict[lang][key]).replace(/YEAR/g, year);
  }

  function applyAttr(el, lang) {
    var key = el.getAttribute('data-i18n');
    if (!key) return;
    var val = t(lang, key);
    if (val == null) return;
    if (el.getAttribute('data-i18n-html') === '1') {
      el.innerHTML = val;
    } else {
      el.textContent = val;
    }
  }

  function applyPlaceholder(el, lang) {
    var key = el.getAttribute('data-i18n-placeholder');
    if (!key) return;
    var val = t(lang, key);
    if (val != null) el.setAttribute('placeholder', val);
  }

  function applyOption(el, lang) {
    var key = el.getAttribute('data-i18n');
    if (!key) return;
    var val = t(lang, key);
    if (val == null) return;
    // Keep submitted values in FR even when label is Arabic.
    if (!el.hasAttribute('value')) {
      el.setAttribute('value', el.textContent);
    }
    el.textContent = val;
  }

  function applyExactTextNodes(lang) {
    if (!dict || !dict.exact || !dict.fr || !dict.ar) return;
    var exact = dict.exact;
    var walk = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null);
    var nodes = [];
    while (walk.nextNode()) nodes.push(walk.currentNode);
    nodes.forEach(function (node) {
      if (!node.parentElement) return;
      var tag = node.parentElement.tagName;
      if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'NOSCRIPT') return;
      // Les <option> du formulaire (gouvernorats) n'ont pas d'attribut value :
      // leur texte est la valeur soumise. Seul applyOption() peut les traduire,
      // car lui fige la valeur FR avant de changer le libellé.
      if (tag === 'OPTION') return;
      if (node.parentElement.closest && node.parentElement.closest('.hv-lang-switch, [data-i18n], [data-i18n-skip]')) return;
      var raw = node.nodeValue;
      if (!raw) return;
      var trimmed = raw.replace(/\s+/g, ' ').trim();
      if (!trimmed) return;
      // Skip brand / product proper nouns kept as-is
      if (trimmed === 'Herbal Vitaly' || trimmed === 'HERBAL' || trimmed === 'VITALY' || trimmed === 'VITALITY') return;

      var key = exact[trimmed];
      if (!key) {
        // try without leading emoji / checkmarks
        var cleaned = trimmed.replace(/^[✅✔✦⚕️*\s]+/, '').trim();
        key = exact[cleaned];
        if (key) {
          var val = t(lang, key);
          if (val != null) {
            var prefix = trimmed.match(/^[✅✔✦⚕️*\s]+/);
            node.nodeValue = raw.replace(trimmed, (prefix ? prefix[0] : '') + val);
          }
          return;
        }
        return;
      }
      var translated = t(lang, key);
      if (translated != null) {
        node.nodeValue = raw.replace(trimmed, translated);
      }
    });
  }

  // Cache original FR text for exact nodes so we can switch back
  var exactCache = [];

  function snapshotExact() {
    if (exactCache.length) return;
    if (!dict || !dict.exact) return;
    var exact = dict.exact;
    var walk = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null);
    while (walk.nextNode()) {
      var node = walk.currentNode;
      if (!node.parentElement) continue;
      var tag = node.parentElement.tagName;
      if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'NOSCRIPT') continue;
      // Une <option> sans attribut value soumet son texte : la traduire
      // enverrait de l'arabe dans les leads. Seul applyOption y touche.
      if (tag === 'OPTION') continue;
      if (node.parentElement.closest && node.parentElement.closest('.hv-lang-switch, [data-i18n], [data-i18n-skip]')) continue;
      var raw = node.nodeValue;
      if (!raw) continue;
      var trimmed = raw.replace(/\s+/g, ' ').trim();
      if (!trimmed) continue;
      var key = exact[trimmed];
      var prefix = '';
      if (!key) {
        var cleaned = trimmed.replace(/^[✅✔✦⚕️*\s]+/, '').trim();
        key = exact[cleaned];
        if (key) {
          var m = trimmed.match(/^[✅✔✦⚕️*\s]+/);
          prefix = m ? m[0] : '';
        }
      }
      if (!key) continue;
      exactCache.push({
        node: node,
        raw: raw,
        key: key,
        prefix: prefix,
        lead: raw.match(/^\s*/)[0],
        tail: raw.match(/\s*$/)[0]
      });
    }
  }

  function applyExactFromCache(lang) {
    snapshotExact();
    exactCache.forEach(function (item) {
      var val = t(lang, item.key);
      if (val == null) {
        item.node.nodeValue = item.raw;
        return;
      }
      if (lang === 'fr') {
        item.node.nodeValue = item.raw;
      } else {
        // Reconstruit depuis les blancs d'origine : robuste même si le nœud
        // contient des retours à la ligne (où un replace(trimmed) échouerait).
        item.node.nodeValue = item.lead + item.prefix + val + item.tail;
      }
    });
  }

  function applyDataI18n(lang) {
    document.querySelectorAll('[data-i18n]').forEach(function (el) {
      if (el.tagName === 'OPTION') applyOption(el, lang);
      else applyAttr(el, lang);
    });
    document.querySelectorAll('[data-i18n-placeholder]').forEach(function (el) {
      applyPlaceholder(el, lang);
    });
    document.querySelectorAll('[data-i18n-aria]').forEach(function (el) {
      var key = el.getAttribute('data-i18n-aria');
      var val = t(lang, key);
      if (val != null) el.setAttribute('aria-label', val);
    });
  }

  /**
   * Western digits (0-9) are required in Arabic mode too. Nothing in the site
   * emits Arabic-Indic digits today; this only neutralises anything injected
   * later by a plugin or a dictionary update. Font-level substitution is
   * handled by the "locl" opt-out in hv-i18n.css.
   */
  var ARABIC_INDIC = /[\u0660-\u0669\u06F0-\u06F9]/g;

  function toWesternDigits(str) {
    return str.replace(ARABIC_INDIC, function (ch) {
      var code = ch.charCodeAt(0);
      var base = code >= 0x06f0 ? 0x06f0 : 0x0660;
      return String(code - base);
    });
  }

  function normalizeDigits() {
    var walk = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null);
    while (walk.nextNode()) {
      var node = walk.currentNode;
      var parent = node.parentElement;
      if (!parent) continue;
      var tag = parent.tagName;
      if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'NOSCRIPT') continue;
      if (!node.nodeValue || !ARABIC_INDIC.test(node.nodeValue)) continue;
      ARABIC_INDIC.lastIndex = 0;
      node.nodeValue = toWesternDigits(node.nodeValue);
    }
    ARABIC_INDIC.lastIndex = 0;
  }

  function setDocumentLang(lang) {
    var html = document.documentElement;
    html.setAttribute('lang', lang === 'ar' ? 'ar' : 'fr');
    html.setAttribute('dir', lang === 'ar' ? 'rtl' : 'ltr');
    document.body.classList.toggle('hv-lang-ar', lang === 'ar');
    document.body.classList.toggle('hv-lang-fr', lang === 'fr');
  }

  function updateSwitchUI(lang) {
    document.querySelectorAll('.hv-lang-switch [data-hv-lang]').forEach(function (btn) {
      var on = btn.getAttribute('data-hv-lang') === lang;
      btn.classList.toggle('is-active', on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  function setLang(lang, persist) {
    if (lang !== 'fr' && lang !== 'ar') lang = 'fr';
    setDocumentLang(lang);
    applyDataI18n(lang);
    applyExactFromCache(lang);
    normalizeDigits();
    updateSwitchUI(lang);
    if (persist !== false) {
      try { localStorage.setItem(STORE, lang); } catch (e) {}
    }
    document.dispatchEvent(new CustomEvent('hv:langchange', { detail: { lang: lang } }));
  }

  function buildSwitch() {
    if (document.getElementById('hvLangSwitch')) return;
    var wrap = document.createElement('div');
    wrap.id = 'hvLangSwitch';
    wrap.className = 'hv-lang-switch';
    wrap.setAttribute('role', 'group');
    wrap.setAttribute('aria-label', (t('fr', 'aria.switch') || 'Language'));
    wrap.innerHTML =
      '<button type="button" class="hv-lang-btn" data-hv-lang="fr" aria-label="Français">' +
      FLAG_FR + '<span class="hv-lang-code">FR</span></button>' +
      '<button type="button" class="hv-lang-btn" data-hv-lang="ar" aria-label="العربية التونسية">' +
      FLAG_TN + '<span class="hv-lang-code">TN</span></button>';
    wrap.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-hv-lang]');
      if (!btn) return;
      setLang(btn.getAttribute('data-hv-lang'), true);
    });
    document.body.appendChild(wrap);

    // Also try to place a compact copy in navbar if present
    var navInner = document.querySelector('.hv-nav-inner, .nav-inner');
    if (navInner && !navInner.querySelector('.hv-lang-switch--nav')) {
      var navSwitch = wrap.cloneNode(true);
      navSwitch.id = 'hvLangSwitchNav';
      navSwitch.className = 'hv-lang-switch hv-lang-switch--nav';
      navSwitch.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-hv-lang]');
        if (!btn) return;
        setLang(btn.getAttribute('data-hv-lang'), true);
      });
      navInner.appendChild(navSwitch);
    }
  }

  function init() {
    if (!dict) {
      console.warn('[hv-i18n] dictionary missing');
      return;
    }
    buildSwitch();
    setLang(detectDefault(), false);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.HV_I18N_API = { setLang: setLang, detectDefault: detectDefault };
})();
