/*
 * The showcase's page on building rules: start and stop a learning run for
 * this browser, show what it records (polled without the cookie -- the page's
 * own look is not recorded), and check the rules against it (replay).
 */
(function () {
  'use strict';
  var data = JSON.parse((document.getElementById('learn-data') || {}).textContent || '{}');
  var w = data.words || {};
  var $ = function (s) { return document.querySelector(s); };
  var el = function (tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text !== undefined) { e.textContent = text; } return e; };
  var fmt = function (s, v) { return String(s).replace(/\{(\w+)\}/g, function (m, k) { return v[k] !== undefined ? v[k] : m; }); };
  var time = function (t) { var d = new Date(t * 1000); return d.toLocaleTimeString(w.lang === 'de' ? 'de-DE' : 'en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' }); };
  var start = $('.learn-start'), stop = $('.learn-stop'), state = $('.learn-state'), rows = $('.learn-rows'), count = $('.learn-count');

  var types = function (o) {
    var out = [];
    Object.keys(o || {}).forEach(function (k) { out.push(k + ': ' + (o[k] || '–')); });
    return out.join(', ');
  };
  var show = function (j) {
    if (start) {
      start.disabled = !!j.until;
      stop.disabled = !j.until;
      state.textContent = j.until ? fmt(w.on, { until: time(j.until) }) : w.off;
      state.classList.toggle('learn-on', !!j.until);
    }
    count.textContent = j.count ? String(j.count) : '';
    rows.textContent = '';
    if (!j.rows || !j.rows.length) {
      var tr = el('tr', 'learn-empty'); var td = el('td', '', w.empty); td.colSpan = 5; tr.appendChild(td); rows.appendChild(tr);
      return;
    }
    j.rows.forEach(function (r) {
      var tr = el('tr', r.decided === 'allow' || r.decided === 'allow-uncached' ? '' : 'learn-stopped');
      tr.appendChild(el('td', 'text-nowrap small', time(r.t)));
      var req = el('td'); req.appendChild(el('code', '', r.method + ' ' + r.path)); tr.appendChild(req);
      tr.appendChild(el('td', 'small', [types(r.query), types(r.form)].filter(Boolean).join(' · ')));
      tr.appendChild(el('td', 'small', String(r.status === null ? '' : r.status) + (r.decided !== 'allow' && r.decided !== 'allow-uncached' ? ' (' + r.decided + ')' : '')));
      var f = r.found;
      tr.appendChild(el('td', 'small', f ? fmt(w.foundSum, { forms: (f.forms || []).length, links: (f.links || []).length, scripts: (f.scripts || []).length }) : ''));
      rows.appendChild(tr);
    });
  };
  var poll = function () {
    fetch('/__learned', { credentials: 'omit', cache: 'no-store' }).then(function (r) { return r.json(); }).then(show).catch(function () {});
  };
  var post = function (url) {
    // Same origin: the answer sets or clears this browser's cookie.
    return fetch(url, { method: 'POST', credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
      return r.json().then(function (j) {
        if (!r.ok) { state.textContent = j.error || ('HTTP ' + r.status); state.classList.remove('learn-on'); return; }
        poll();
      });
    }).catch(function () {});
  };
  if (start) {
    start.addEventListener('click', function () { post('/__learn/start'); });
    stop.addEventListener('click', function () { post('/__learn/stop'); });
  }
  $('.learn-check').addEventListener('click', function () {
    var box = $('.learn-replay'), list = $('.learn-replay-list'), sum = $('.learn-replay-sum');
    fetch('/__replay', { credentials: 'omit', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
      box.hidden = false;
      list.textContent = '';
      var n = { pass: 0, refused: 0, offered: 0, check: 0 };
      (j.results || []).slice().sort(function (a, b) { var o = { refused: 0, offered: 1, check: 2, pass: 3 }; return o[a.kind] - o[b.kind]; }).forEach(function (r) {
        n[r.kind] = (n[r.kind] || 0) + 1;
        var li = el('li', 'learn-r learn-r-' + r.kind);
        li.appendChild(el('span', 'learn-mark', { pass: '✓', refused: '✕', offered: '?', check: '!' }[r.kind] || '·'));
        li.appendChild(el('code', '', r.method + ' ' + r.url));
        li.appendChild(el('span', 'small text-secondary ms-2', r.kind === 'pass' ? '' : r.got + (r.rule ? ' · ' + r.rule : '')));
        list.appendChild(li);
      });
      sum.textContent = fmt(w.replaySum, { n: (j.results || []).length, pass: n.pass + n.check, refused: n.refused, offered: n.offered });
    }).catch(function () {});
  });
  poll();
  setInterval(function () { if (!document.hidden) { poll(); } }, 2000);
})();
