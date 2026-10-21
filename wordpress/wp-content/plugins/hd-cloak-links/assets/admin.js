(function () {
  function closestLabel(el) {
    while (el && el !== document) {
      if (el.classList && el.classList.contains('hdc-country')) return el;
      el = el.parentNode;
    }
    return null;
  }

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t || t.type !== 'checkbox') return;
    var label = closestLabel(t);
    if (label) label.classList.toggle('is-on', t.checked);
    if (t.name === 'mode_white' || t.name === 'mode_offer') {
      var group = t.name;
      document.querySelectorAll('.hdc-mode').forEach(function (m) {
        var inp = m.querySelector('input[type="radio"]');
        if (!inp || inp.name !== group) return;
        m.classList.toggle('is-on', !!inp.checked);
      });
    }
  });

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.hdc-copy');
    if (btn) {
      var v = btn.getAttribute('data-copy') || '';
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(v).then(function () {
          var old = btn.textContent;
          btn.textContent = 'Copié';
          setTimeout(function () { btn.textContent = old; }, 1200);
        });
      } else {
        window.prompt('Copier:', v);
      }
      return;
    }

    if (e.target.classList.contains('hdc-select-maghreb')) {
      e.preventDefault();
      var want = { TN: 1, MA: 1, DZ: 1 };
      document.querySelectorAll('.hdc-countries input[type="checkbox"]').forEach(function (cb) {
        cb.checked = !!want[cb.value];
        var lab = closestLabel(cb);
        if (lab) lab.classList.toggle('is-on', cb.checked);
      });
    }
    if (e.target.classList.contains('hdc-select-none')) {
      e.preventDefault();
      document.querySelectorAll('.hdc-countries input[type="checkbox"]').forEach(function (cb) {
        cb.checked = false;
        var lab = closestLabel(cb);
        if (lab) lab.classList.remove('is-on');
      });
    }
  });
})();
