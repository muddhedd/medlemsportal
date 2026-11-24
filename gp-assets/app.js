(function () {
  'use strict';

  // Confirm before destructive actions: <form data-confirm="Are you sure?">
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (evt) {
      if (!window.confirm(form.getAttribute('data-confirm'))) {
        evt.preventDefault();
      }
    });
  });

  // Tap to copy: <code data-copy>
  document.querySelectorAll('[data-copy]').forEach(function (el) {
    el.setAttribute('title', document.documentElement.lang === 'sv' ? 'Kopiera' : 'Copy');
    el.addEventListener('click', function () {
      var text = el.textContent.trim();
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
          el.classList.add('copied');
          window.setTimeout(function () { el.classList.remove('copied'); }, 1500);
        });
      }
    });
  });

  // Admin: live address preview from country / city / name.
  var form = document.querySelector('[data-slug-form]');
  if (form) {
    var out = form.querySelector('[data-slug-out]');
    var profile = form.querySelector('[data-profile]');
    var multi = form.querySelector('[data-multi]');
    var touched = out.value !== '';
    var map = { 'æ': 'ae', 'ø': 'o', 'ß': 'ss', 'þ': 'th', 'ð': 'd', 'ł': 'l' };

    var slugify = function (s) {
      s = s.toLowerCase().replace(/[æøßþðł]/g, function (c) { return map[c]; });
      s = s.normalize('NFD').replace(/[̀-ͯ]/g, '');
      return s.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60);
    };
    var levels = function () {
      var opt = profile.options[profile.selectedIndex];
      return opt ? parseInt(opt.getAttribute('data-levels') || '1', 10) : 1;
    };
    var update = function () {
      multi.hidden = levels() === 1;
      if (touched) { return; }
      var parts = [];
      form.querySelectorAll('[data-slug-part]').forEach(function (inp) {
        if (levels() === 1 && multi.contains(inp)) { return; }
        var v = slugify(inp.value);
        if (v) { parts.push(v); }
      });
      out.value = parts.join('/');
    };
    form.querySelectorAll('[data-slug-part]').forEach(function (inp) { inp.addEventListener('input', update); });
    profile.addEventListener('change', update);
    out.addEventListener('input', function () { touched = out.value !== ''; });
    update();
  }
  // Meeting series form: only show the fields that belong to the chosen repeat type.
  document.querySelectorAll('[data-recur-form]').forEach(function (rform) {
    var sel = rform.querySelector('[data-recur]');
    var sync = function () {
      rform.querySelectorAll('[data-recur-show]').forEach(function (el) {
        el.hidden = el.getAttribute('data-recur-show').split(' ').indexOf(sel.value) === -1;
      });
    };
    sel.addEventListener('change', sync);
    sync();
  });
  // Confirm a specific submit button: <button data-confirm-click="Sure?">
  document.querySelectorAll('[data-confirm-click]').forEach(function (btn) {
    btn.addEventListener('click', function (evt) {
      if (!window.confirm(btn.getAttribute('data-confirm-click'))) {
        evt.preventDefault();
      }
    });
  });

  // Print button: <button data-print>
  document.querySelectorAll('[data-print]').forEach(function (btn) {
    btn.addEventListener('click', function () { window.print(); });
  });

  // Minutes: save automatically a moment after typing stops.
  var auto = document.querySelector('form[data-autosave]');
  if (auto && window.fetch && window.FormData) {
    var status = auto.querySelector('[data-autosave-status]');
    var timer = null;
    var dirty = false;
    var busy = false;
    var save = function () {
      if (busy || !dirty) { return; }
      busy = true;
      dirty = false;
      var fd = new FormData(auto);
      fd.set('do', 'draft');
      fd.set('ajax', '1');
      fetch(auto.getAttribute('action'), { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (status) {
            status.textContent = j.ok ? '✓ ' + j.time : (j.msg || '!');
            status.classList.toggle('flash-error', !j.ok);
          }
        })
        .catch(function () {
          dirty = true;
          if (status) { status.textContent = '⚠ …'; }
        })
        .then(function () { busy = false; });
    };
    auto.addEventListener('input', function () {
      dirty = true;
      window.clearTimeout(timer);
      timer = window.setTimeout(save, 2000);
    });
    auto.addEventListener('change', function () {
      dirty = true;
      window.clearTimeout(timer);
      timer = window.setTimeout(save, 800);
    });
    window.setInterval(save, 15000);
    auto.addEventListener('submit', function () { dirty = false; window.clearTimeout(timer); });
  }
})();
