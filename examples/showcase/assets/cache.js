// The showcase's tab "Cache" (cache.php): every button a real request to the
// magazine, timed here; the cache's answer from X-RS-Cache. Members are a
// cookie set for the one request (rs-demo-member), publishing and emptying
// are POSTs the page answers with Shield::purge().
(() => {
  'use strict';
  const data = JSON.parse(document.getElementById('cache-data').textContent);
  const w = data.words;
  const rows = document.querySelector('.cache-rows');
  const sum = document.querySelector('.cache-sum');
  const article = document.querySelector('.cache-article');
  const members = {A: 'a-' + Math.random().toString(36).slice(2, 10), B: 'b-' + Math.random().toString(36).slice(2, 10)};
  const seen = [];
  const fmt = (ms) => (ms < 10 ? ms.toFixed(1) : Math.round(ms)) + ' ms';
  const text = (tag, s, cls) => {
    const el = document.createElement(tag);
    el.textContent = s;
    if (cls) {
      el.className = cls;
    }
    return el;
  };

  const show = (url, who, kind, ms) => {
    const empty = rows.querySelector('.cache-empty');
    if (empty) {
      empty.remove();
    }
    const tr = document.createElement('tr');
    const label = kind.startsWith('hit') ? w.hit : (kind.startsWith('miss') ? w.miss : w.none);
    tr.append(text('td', url), text('td', who), text('td', label + (kind.includes('role') ? ' (role)' : ''), 'badge-cell ' + (kind.startsWith('hit') ? 'text-success fw-bold' : '')), text('td', fmt(ms)));
    rows.prepend(tr);
    seen.push({kind, ms});
    const avg = (k) => {
      const list = seen.filter((s) => s.kind.startsWith(k)).map((s) => s.ms);
      return list.length ? fmt(list.reduce((a, b) => a + b, 0) / list.length) : '–';
    };
    const hits = seen.filter((s) => s.kind.startsWith('hit')).length;
    const asked = seen.filter((s) => s.kind.startsWith('hit') || s.kind.startsWith('miss')).length;
    sum.textContent = w.sum.replace('%s', avg('hit')).replace('%s', avg('miss')).replace('%s', asked ? Math.round(100 * hits / asked) + ' %' : '–');
  };

  // One request at a time: a member's cookie belongs to that one request only.
  let queue = Promise.resolve();
  const one = (job) => {
    queue = queue.then(job).catch(() => purged('✕'));
    return queue;
  };

  const load = (url, member) => one(async () => {
    if (member) {
      document.cookie = 'rs-demo-member=' + members[member] + '; path=/; SameSite=Lax';
    }
    const t0 = performance.now();
    try {
      const r = await fetch(url, {cache: 'no-store', credentials: 'same-origin'});
      await r.text();
      show(url + (r.ok ? '' : ' (' + r.status + ')'), member ? w.member.replace('%s', member) : w.visitor, (r.headers.get('X-RS-Cache') || '').toLowerCase(), performance.now() - t0);
    } finally {
      if (member) {
        document.cookie = 'rs-demo-member=; path=/; max-age=0; SameSite=Lax';
      }
    }
  });

  const post = (path, body, what) => one(async () => {
    const r = await fetch(path, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body});
    purged(r.ok ? what : what + ' ✕ ' + r.status);
  });

  document.querySelector('.cache-load').addEventListener('click', () => load('/magazin/' + article.value));
  document.querySelectorAll('.cache-member').forEach((b) => b.addEventListener('click', () => load('/magazin/' + article.value, b.dataset.member)));
  document.querySelector('.cache-campaign').addEventListener('click', () => load('/magazin/' + article.value + '?utm_source=newsletter&utm_campaign=c' + Math.floor(Math.random() * 1000)));
  document.querySelector('.cache-list').addEventListener('click', () => load('/magazin'));
  // A line in the table for a purge (what the next load of those pages will be a miss for), or a failure.
  function purged(what) {
    const empty = rows.querySelector('.cache-empty');
    if (empty) {
      empty.remove();
    }
    const tr = document.createElement('tr');
    tr.className = 'table-light';
    tr.append(Object.assign(text('td', '↻ ' + what), {colSpan: 4}));
    rows.prepend(tr);
  }
  document.querySelector('.cache-publish').addEventListener('click', () => post('/__cache/publish', 'n=' + encodeURIComponent(article.value), '/magazin/' + article.value + ', /magazin'));
  document.querySelector('.cache-clear').addEventListener('click', () => post('/__cache/clear', '', '*'));
})();
