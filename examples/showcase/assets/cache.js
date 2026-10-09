// The showcase's tab "Cache" (cache.php): every button a real request to the
// magazine, timed here; the cache's answer from X-RS-Cache. A member or an
// editor is a cookie set for the one request (rs-demo-member), publishing and
// emptying are POSTs the page answers with Shield::purge(). The picture: a dot
// travels the stations a request passes (visitor, doorkeeper, shelf, kitchen),
// the shelf shows which page is kept for which role.
(() => {
  'use strict';
  const data = JSON.parse(document.getElementById('cache-data').textContent);
  const w = data.words;
  const rows = document.querySelector('.cache-rows');
  const sum = document.querySelector('.cache-sum');
  const article = document.querySelector('.cache-article');
  const caption = document.querySelector('.flow-caption');
  const detail = document.querySelector('.flow-detail');
  const story = document.querySelector('.cache-story');
  const dot = document.querySelector('.flow-dot');
  const stations = [...document.querySelectorAll('.flow-station')];
  const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const rand = () => Math.random().toString(36).slice(2, 8);
  const sessions = {A: 'a-' + rand(), B: 'b-' + rand(), E: 'editor-' + rand()};
  const roleOf = (who) => (who === 'E' ? 'editor' : (who ? 'member' : 'visitor'));
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

  // ── Plain or technical (an option for developers; the browser remembers it) ──
  let tech = false;
  try {
    tech = localStorage.getItem('rs-cache-view') === 'tech';
  } catch (e) {
    tech = false;
  }
  const words = (key) => (tech && w.tech[key] ? w.tech[key] : w[key]);
  const view = (t) => {
    tech = t;
    try {
      localStorage.setItem('rs-cache-view', t ? 'tech' : 'plain');
    } catch (e) {
      // no storage: the choice lasts for this page
    }
    document.querySelectorAll('[data-plain]').forEach((el) => {
      el.textContent = t ? el.dataset.tech : el.dataset.plain;
    });
    document.querySelectorAll('.cache-view button').forEach((b) => {
      const on = (b.dataset.view === 'tech') === t;
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.classList.toggle('btn-dark', on);
      b.classList.toggle('btn-outline-dark', !on);
    });
    detail.hidden = !t;
  };
  document.querySelectorAll('.cache-view button').forEach((b) => b.addEventListener('click', () => view(b.dataset.view === 'tech')));
  view(tech);

  // ── The picture ──────────────────────────────────────────────────────────
  // Station x offsets from the visitor: doorkeeper +220, shelf +440, kitchen +660.
  const X = [0, 220, 440, 660];
  const ROUTES = {hit: [0, 1, 2, 1, 0], miss: [0, 1, 2, 3, 2, 1, 0], notkept: [0, 1, 2, 3, 2, 1, 0], refused: [0, 1, 0]};
  const STEP = 280;
  let film = Promise.resolve();
  const play = (kind, text1, text2) => {
    film = film.then(() => new Promise((done) => {
      caption.textContent = text1;
      detail.textContent = text2 || '';
      if (still || !dot.animate) {
        done();
        return;
      }
      const path = ROUTES[kind] || ROUTES.hit;
      dot.setAttribute('class', 'flow-dot ' + kind);
      const anim = dot.animate(path.map((st) => ({transform: `translate(${X[st]}px, 0)`, opacity: 1})), {duration: STEP * (path.length - 1), easing: 'ease-in-out'});
      // The station the dot is at lights up; the last stop is the end, which clears them all.
      const timers = path.slice(0, -1).map((st, k) => setTimeout(() => stations.forEach((s, n) => {
        s.classList.toggle('on', n === st);
        s.classList.toggle('no', kind === 'refused' && n === 1 && st === 1);
      }), STEP * k));
      anim.onfinish = () => {
        timers.forEach(clearTimeout);
        stations.forEach((s) => s.classList.remove('on', 'no'));
        done();
      };
    }));
    return film;
  };

  // ── The shelf ────────────────────────────────────────────────────────────
  const fill = (role, n) => {
    const s = document.querySelector(`.cache-shelf tr[data-role="${role}"] .slot[data-slot="${n}"]`);
    if (s && !s.classList.contains('full')) {
      s.classList.add('full', 'pop');
      setTimeout(() => s.classList.remove('pop'), 600);
    }
  };
  const empty = (n) => document.querySelectorAll('.cache-shelf .slot').forEach((s) => {
    if (n === null || s.dataset.slot === String(n) || s.dataset.slot === '0') {
      s.classList.remove('full');
    }
  });
  // Its slot on the shelf: /magazin is 0, /magazin/1…5 their number; with a parameter other than tracking: none.
  const shelfOf = (url) => {
    const m = url.match(/^\/magazin(?:\/([1-5]))?(?:\?(.*))?$/);
    if (!m) {
      return null;
    }
    const query = (m[2] || '').split('&').filter((p) => p !== '' && !/^utm_/.test(p));
    return query.length ? null : (m[1] ? Number(m[1]) : 0);
  };

  // ── The table and the sum ────────────────────────────────────────────────
  function line(cells, cls) {
    const none = rows.querySelector('.cache-empty');
    if (none) {
      none.remove();
    }
    const tr = document.createElement('tr');
    if (cls) {
      tr.className = cls;
    }
    tr.append(...cells);
    rows.prepend(tr);
  }
  const show = (url, who, kind, label, ms) => {
    line([text('td', url), text('td', who), text('td', label, kind === 'hit' ? 'text-success fw-bold' : (kind === 'refused' ? 'text-danger' : '')), text('td', fmt(ms))]);
    seen.push({kind, ms});
    const avg = (k) => {
      const list = seen.filter((s) => s.kind === k).map((s) => s.ms);
      return list.length ? fmt(list.reduce((a, b) => a + b, 0) / list.length) : '–';
    };
    const hits = seen.filter((s) => s.kind === 'hit').length;
    const asked = seen.filter((s) => s.kind === 'hit' || s.kind === 'miss').length;
    sum.textContent = w.sum.replace('%s', avg('hit')).replace('%s', avg('miss')).replace('%s', asked ? Math.round(100 * hits / asked) + ' %' : '–');
  };
  const purged = (what) => line([Object.assign(text('td', '↻ ' + what), {colSpan: 4})], 'cache-purge');

  // ── The requests: one at a time, so a reader's cookie belongs to its own request ──
  let queue = Promise.resolve();
  const one = (job) => {
    queue = queue.then(job).catch(() => purged('✕'));
    return queue;
  };
  const load = (url, who, by) => one(async () => {
    if (who) {
      document.cookie = 'rs-demo-member=' + sessions[who] + '; path=/; SameSite=Lax';
    }
    const t0 = performance.now();
    try {
      const r = await fetch(url, {cache: 'no-store', credentials: 'same-origin'});
      await r.text();
      const ms = performance.now() - t0;
      const cache = (r.headers.get('X-RS-Cache') || '').toLowerCase();
      const place = shelfOf(url);
      const kind = !r.ok ? 'refused' : (cache.startsWith('hit') ? 'hit' : (cache.startsWith('miss') && place !== null ? 'miss' : 'notkept'));
      const label = {hit: w.hit, miss: w.miss, refused: w.refused + ' (' + r.status + ')', notkept: w.notKept}[kind];
      show(url, by || (who === 'E' ? w.editor : (who ? w.member.replace('%s', who) : w.visitor)), kind, label, ms);
      if ((kind === 'hit' || kind === 'miss') && place !== null) {
        fill(roleOf(who), place);
      }
      play(kind, words({hit: 'capHit', miss: 'capMiss', refused: 'capRefused', notkept: 'capNotKept'}[kind]),
        'GET ' + url + ' → ' + r.status + ' · X-RS-Cache: ' + (cache || '—') + ' · ' + fmt(ms) + (who ? ' · rs-demo-member=' + sessions[who] : ''));
    } finally {
      if (who) {
        document.cookie = 'rs-demo-member=; path=/; max-age=0; SameSite=Lax';
      }
    }
  });
  const post = (path, body, what, after) => one(async () => {
    const r = await fetch(path, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body});
    if (r.ok) {
      after();
      film = film.then(() => {
        caption.textContent = words(what === '*' ? 'capClear' : 'capPurge');     // after the dot that is still on its way
        detail.textContent = 'POST ' + path + (body ? ' ' + body : '') + ' → ' + r.status;
      });
    }
    purged(r.ok ? what : what + ' ✕ ' + r.status);
  });

  // What scanners try on a front page: made-up parameters and articles, secrets, an injection.
  const scan = () => [
    '/magazin?id=' + rand(),
    '/magazin?page=' + Math.floor(Math.random() * 9000 + 1000),
    '/magazin/' + Math.floor(Math.random() * 90 + 6),
    '/.env',
    '/magazin?q=' + encodeURIComponent("' UNION SELECT password FROM users--"),
    '/wp-login.php',
  ];

  document.querySelector('.cache-load').addEventListener('click', () => load('/magazin/' + article.value));
  document.querySelectorAll('.cache-member').forEach((b) => b.addEventListener('click', () => load('/magazin/' + article.value, b.dataset.member)));
  document.querySelector('.cache-campaign').addEventListener('click', () => load('/magazin/' + article.value + '?utm_source=newsletter&utm_campaign=c' + Math.floor(Math.random() * 1000)));
  document.querySelector('.cache-list').addEventListener('click', () => load('/magazin'));
  document.querySelector('.cache-scan').addEventListener('click', () => scan().forEach((u) => load(u, '', w.bot)));
  document.querySelector('.cache-publish').addEventListener('click', () => {
    const n = article.value;
    post('/__cache/publish', 'n=' + encodeURIComponent(n), '/magazin/' + n + ', /magazin', () => empty(Number(n)));
  });
  document.querySelector('.cache-clear').addEventListener('click', () => post('/__cache/clear', '', '*', () => empty(null)));

  // ── The dock: the log a height of its own (about seven lines), drawn bigger or smaller at the grip ──
  const dock = document.querySelector('.cache-dock');
  const log = document.querySelector('.cache-dock-log');
  const grip = document.querySelector('.cache-dock-grip');
  const space = document.querySelector('.cache-dock-space');
  const fit = () => {
    space.style.height = dock.offsetHeight + 'px';      // the page ends above the dock, whatever its height
  };
  const height = (px) => {
    const h = Math.round(Math.max(60, Math.min(window.innerHeight * 0.7, px)));
    log.style.height = h + 'px';
    grip.setAttribute('aria-valuenow', String(h));
    try {
      localStorage.setItem('rs-cache-log', String(h));
    } catch (e) {
      // no storage: the height lasts for this page
    }
    fit();
  };
  try {
    const kept = Number(localStorage.getItem('rs-cache-log'));
    if (kept > 0) {
      height(kept);
    }
  } catch (e) {
    // the default height
  }
  grip.addEventListener('pointerdown', (ev) => {
    const y0 = ev.clientY;
    const h0 = log.offsetHeight;
    grip.setPointerCapture(ev.pointerId);
    const move = (e) => height(h0 + (y0 - e.clientY));
    grip.addEventListener('pointermove', move);
    grip.addEventListener('pointerup', () => grip.removeEventListener('pointermove', move), {once: true});
  });
  grip.addEventListener('keydown', (ev) => {
    if (ev.key === 'ArrowUp' || ev.key === 'ArrowDown') {
      ev.preventDefault();
      height(log.offsetHeight + (ev.key === 'ArrowUp' ? 28 : -28));       // a line more or less
    }
  });
  if (window.ResizeObserver) {
    new ResizeObserver(fit).observe(dock);
  }
  window.addEventListener('resize', fit);
  fit();

  // ── The dock: its log can be folded away, the actions stay ───────────────
  const fold = document.querySelector('.cache-dock-toggle');
  fold.addEventListener('click', () => {
    const open = fold.getAttribute('aria-expanded') !== 'true';
    fold.setAttribute('aria-expanded', open ? 'true' : 'false');
    dock.classList.toggle('closed', !open);
    fit();
  });

  // ── Auto: the whole story, one article after the other, until stopped ────
  const autoButton = document.querySelector('.cache-auto');
  let auto = false;
  let round = 0;          // one run at a time: a run stopped and started again is a new round
  const wait = (ms) => new Promise((go) => setTimeout(go, ms));
  const tell = (key, n) => {
    story.textContent = w.story[key].replace('%s', n);
  };
  const step = async (id, key, n, job) => {
    if (!auto || id !== round) {
      return;
    }
    tell(key, n);
    await job();
    await queue;
    await film;
    await wait(still ? 1600 : 900);
  };
  const run = async (id) => {
    const s2 = (key, n, job) => step(id, key, n, job);
    for (let n = Number(article.value); auto && id === round; n = n % 5 + 1) {
      article.value = String(n);
      const page = '/magazin/' + n;
      await s2('publish', n, () => post('/__cache/publish', 'n=' + n, page + ', /magazin', () => empty(n)));
      await s2('visit1', n, () => load(page));
      await s2('visit2', n, () => load(page));
      await s2('campaign', n, () => load(page + '?utm_source=newsletter&utm_campaign=auto'));
      await s2('memberA', n, () => load(page, 'A'));
      await s2('memberB1', n, () => load('/magazin', 'B'));
      await s2('memberB2', n, () => load(page, 'B'));
      await s2('editor1', n, () => load(page, 'E'));
      await s2('editor2', n, () => load(page, 'E'));
      await s2('scan', n, () => Promise.all(scan().map((u) => load(u, '', w.bot))));
      await s2('republish', n, () => post('/__cache/publish', 'n=' + n, page + ', /magazin', () => empty(n)));
      await s2('visit3', n, () => load(page));
      await s2('visit4', n, () => load(page));
    }
  };
  const setAuto = (on) => {
    auto = on;
    autoButton.setAttribute('aria-pressed', on ? 'true' : 'false');
    autoButton.querySelector('span').textContent = on ? w.autoStop : w.auto;
    autoButton.querySelector('i').className = 'bi ' + (on ? 'bi-stop-fill' : 'bi-play-fill');
    document.querySelectorAll('.cache-manual').forEach((b) => {
      b.disabled = on;
    });
    if (on) {
      round++;
      const id = round;
      run(id).finally(() => {
        if (id === round && auto) {
          setAuto(false);
        }
      });
    } else {
      story.textContent = '';
    }
  };
  autoButton.addEventListener('click', () => setAuto(!auto));
})();
