// The showcase's tab "Cache" (cache.php): every button a real request to the
// magazine, timed here; the cache's answer from X-RS-Cache. A member or an
// editor is a cookie set for the one request (rs-demo-member), publishing and
// emptying are POSTs the page answers with Shield::purge(). The picture: a dot
// travels the stations a request passes (visitor, doorkeeper, shelf, newsroom),
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

  // ── The introduction above the buttons: behind its "?", open once asked for (the browser remembers it) ──
  const introToggle = document.querySelector('.cache-intro-toggle');
  const introText = document.getElementById('cache-intro-text');
  const intro = (open) => {
    introToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    introText.hidden = !open;
  };
  try {
    intro(localStorage.getItem('rs-cache-intro') === 'open');
  } catch (e) {
    intro(false);
  }
  introToggle.addEventListener('click', () => {
    const open = introToggle.getAttribute('aria-expanded') !== 'true';
    intro(open);
    try {
      localStorage.setItem('rs-cache-intro', open ? 'open' : 'closed');
    } catch (e) {
      // no storage: the choice lasts for this page
    }
  });

  // ── Plain or technical (an option for developers; the browser remembers it) ──
  let tech = false;
  try {
    tech = localStorage.getItem('rs-cache-view') === 'tech';
  } catch (e) {
    tech = false;
  }
  const words = (key) => (tech && w.tech[key] ? w.tech[key] : w[key]);
  let sender = '';            // who sent the last request (the picture's first station); actor() draws it, set below
  let redraw = null;
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
    document.querySelector('.cache-dock').classList.toggle('tech', t);     // the log's technical columns
    if (redraw) {
      redraw(sender);       // the view's words above rewrote the first station: the sender again
    }
  };
  document.querySelectorAll('.cache-view button').forEach((b) => b.addEventListener('click', () => view(b.dataset.view === 'tech')));
  view(tech);

  // ── The picture ──────────────────────────────────────────────────────────
  // Station x offsets from the visitor: doorkeeper +220, shelf +440, newsroom +660.
  const X = [0, 220, 440, 660];
  const ROUTES = {hit: [0, 1, 2, 1, 0], miss: [0, 1, 2, 3, 2, 1, 0], notkept: [0, 1, 2, 3, 2, 1, 0], off: [0, 1, 2, 3, 2, 1, 0], refused: [0, 1, 0]};
  // The result in the picture, above the station that decided it: the doorkeeper refuses, the shelf answers or not.
  const result = document.querySelector('.flow-result');
  const resultText = result.querySelector('text');
  const resultBox = result.querySelector('rect');
  const decides = (kind) => (kind === 'refused' ? 1 : 2);
  const badge = (kind, label) => {
    if (!label) {
      result.classList.remove('show');      // fades out as it was: colour and words stay until it is gone
      return;
    }
    resultText.textContent = label;
    const width = Math.max(60, label.length * 7.4 + 22);
    resultBox.setAttribute('width', String(width));
    resultBox.setAttribute('x', String(-width / 2));
    result.setAttribute('transform', `translate(${80 + X[decides(kind)]}, 0)`);
    result.setAttribute('class', 'flow-result show ' + kind);
  };
  const STEP = 280;       // ms from one station to the next; slower while Auto plays (AUTO_STEP)
  const AUTO_STEP = 480;
  const LEAD = 1500;      // ms between a step's story and its request while Auto plays
  let film = Promise.resolve();
  // The lines below the picture keep their height: a long one ends in "…", whole on hover.
  const say = (el, s2) => {
    el.textContent = s2;
    el.title = s2;
  };
  const pace = () => (auto ? AUTO_STEP : STEP);
  // Who sends it, in both views: a person each (alike, each their own), a bot as a robot; the visitor as drawn.
  const visitorIcon = stations[0].querySelector('.flow-icon');
  const visitorName = stations[0].querySelector('.flow-name');
  const FACES = {A: '🧑', B: '👩', E: '🧑‍💻', bot: '🤖', crawler: '🔎'};
  const face = (who) => FACES[who] || '🙂';
  const actor = (who) => {
    sender = who;
    ['A', 'B', 'E', 'bot', 'crawler'].forEach((k) => stations[0].classList.toggle('as-' + k, k === who));
    visitorIcon.textContent = FACES[who] || (tech ? visitorIcon.dataset.tech : visitorIcon.dataset.plain);
    visitorName.textContent = who === 'bot' ? w.bot : (who === 'crawler' ? w.crawlerName : w.people[who] || (tech ? visitorName.dataset.tech : visitorName.dataset.plain));
  };
  redraw = actor;
  const play = (kind, text1, text2, label, who) => {
    film = film.then(() => new Promise((done) => {
      actor(who || '');
      say(caption, text1);
      say(detail, text2 || '');
      badge(kind, '');
      if (still || !dot.animate) {
        badge(kind, label);
        done();
        return;
      }
      const path = ROUTES[kind] || ROUTES.hit;
      dot.setAttribute('class', 'flow-dot ' + kind);
      const anim = dot.animate(path.map((st) => ({transform: `translate(${X[st]}px, 0)`, opacity: 1})), {duration: pace() * (path.length - 1), easing: 'ease-in-out'});
      // The station the dot is at lights up; the last stop is the end, which clears them all.
      const timers = path.slice(0, -1).map((st, k) => setTimeout(() => stations.forEach((s, n) => {
        s.classList.toggle('on', n === st);
        s.classList.toggle('no', kind === 'refused' && n === 1 && st === 1);
      }), pace() * k));
      // The result shows when the dot reaches the station that decides it, and stays until the next request.
      timers.push(setTimeout(() => badge(kind, label), pace() * path.indexOf(decides(kind))));
      anim.onfinish = () => {
        timers.forEach(clearTimeout);
        badge(kind, label);
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

  // ── The table and the sum: the newest line at the bottom, scrolled to ────
  const scroller = document.querySelector('.cache-dock-log');
  const clock = () => new Date().toLocaleTimeString(w.lang === 'de' ? 'de-DE' : 'en-GB');
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
    rows.append(tr);
    scroller.scrollTop = scroller.scrollHeight;
  }
  const pill = (kind, label) => {
    const td = document.createElement('td');
    td.append(text('span', label, 'pill pill-' + kind));
    return td;
  };
  // Long values (an address with a campaign, a header) end in "…" -- the whole value on hover.
  const cut = (td) => Object.assign(td, {title: td.textContent});
  const techCell = (s2, cls) => cut(text('td', s2 || '—', 'tech-col mono cut' + (cls ? ' ' + cls : '')));
  const show = (url, who, kind, label, ms, t) => {
    line([text('td', clock(), 'mono when'), cut(text('td', url, 'mono url cut')), text('td', who), pill(kind, label), text('td', fmt(ms), 'mono num'),
      techCell(t.status), techCell(t.xrs), techCell(t.cache), techCell(t.cc), techCell(t.age ? t.age + ' s' : '', 'num'), techCell(t.role, 'role')]);
    seen.push({kind, ms});
    const avg = (k) => {
      const list = seen.filter((s) => s.kind === k).map((s) => s.ms);
      return list.length ? fmt(list.reduce((a, b) => a + b, 0) / list.length) : '–';
    };
    const hits = seen.filter((s) => s.kind === 'hit').length;
    const asked = seen.filter((s) => s.kind === 'hit' || s.kind === 'miss').length;
    sum.textContent = w.sum.replace('%s', avg('hit')).replace('%s', avg('miss')).replace('%s', asked ? Math.round(100 * hits / asked) + ' %' : '–');
  };
  const purged = (what) => line([text('td', clock(), 'mono when'), Object.assign(text('td', '↻ ' + what), {colSpan: 10})], 'cache-purge');

  // ── The requests: one at a time, so a reader's cookie belongs to its own request ──
  let queue = Promise.resolve();
  const one = (job) => {
    queue = queue.then(job).catch(() => purged('✕'));
    return queue;
  };
  // by: a bot's name for the log (who is then ''); good: a crawler that may read the pages, not the bad bot.
  const load = (url, who, by, good) => one(async () => {
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
      // No X-RS-Cache at all: the cache did not look. "Off" only when the page knows it is off for this host
      // name; else not kept (a cookie of another site, no APCu for a member, a hit-only lookup that missed).
      const kind = !r.ok ? 'refused' : (cache === '' && w.cacheOff ? 'off' : (cache.startsWith('hit') ? 'hit' : (cache.startsWith('miss') && place !== null ? 'miss' : 'notkept')));
      const label = {hit: w.hit, miss: w.miss, refused: w.refused + ' (' + r.status + ')', notkept: w.notKept, off: w.off}[kind];
      // The role the cache keys by, and for a member the session (the cookie rs-demo-member's value, the detail line names it).
      const role = by ? (good ? 'crawler' : 'bot') + ' · ' + w.anonymous : (who ? roleOf(who) + ' · ' + sessions[who] : w.anonymous);
      const from = by ? (good ? 'crawler' : 'bot') : who;
      show(url, face(from) + ' ' + (by || (who === 'E' ? w.editor : (who ? w.member.replace('%s', who) : w.visitor))), kind, label, ms,
        {status: String(r.status), xrs: r.headers.get('X-RS'), cache: r.headers.get('X-RS-Cache'), cc: r.headers.get('Cache-Control'), age: r.headers.get('Age'), role});
      if ((kind === 'hit' || kind === 'miss') && place !== null) {
        fill(roleOf(who), place);
      }
      play(kind, words({hit: 'capHit', miss: 'capMiss', refused: 'capRefused', notkept: 'capNotKept', off: 'capOff'}[kind]),
        'GET ' + url + ' → ' + r.status + ' · X-RS-Cache: ' + (cache || '—') + ' · ' + fmt(ms) + (who ? ' · rs-demo-member=' + sessions[who] : ''), label, from);
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
        say(caption, words(what === '*' ? 'capClear' : 'capPurge'));     // after the dot that is still on its way
        badge('', '');
        say(detail, 'POST ' + path + (body ? ' ' + body : '') + ' → ' + r.status);
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
  // A search or AI-search crawler reads like a visitor: anonymous, the visitors' pages -- from the shelf when they are there.
  const crawl = () => ['/magazin', '/magazin/1', '/magazin/2', '/magazin/3', '/magazin/4', '/magazin/5'].map((u) => load(u, '', w.crawlerName, true));
  document.querySelector('.cache-crawler').addEventListener('click', crawl);
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
    space.style.height = dock.classList.contains('docked') ? dock.offsetHeight + 'px' : '0';      // docked: the page ends above it
  };
  // Below the picture by default (it scrolls with the page, next to what it is about); docked at the bottom on request.
  const pin = document.querySelector('.cache-dock-pin');
  const docked = (on) => {
    dock.classList.toggle('docked', on);
    log.insertAdjacentElement(on ? 'beforebegin' : 'afterend', grip);      // the grip on the log's free edge
    pin.setAttribute('aria-pressed', on ? 'true' : 'false');
    pin.querySelector('span').textContent = on ? w.dockUnpin : w.dockPin;
    pin.querySelector('i').className = 'bi ' + (on ? 'bi-arrow-up-square' : 'bi-pin-angle');
    try {
      localStorage.setItem('rs-cache-dock', on ? 'docked' : 'embedded');
    } catch (e) {
      // no storage: the choice lasts for this page
    }
    fit();
  };
  pin.addEventListener('click', () => docked(!dock.classList.contains('docked')));
  const limits = () => {
    grip.setAttribute('aria-valuemin', '60');
    grip.setAttribute('aria-valuemax', String(Math.round(window.innerHeight * 0.7)));
    grip.setAttribute('aria-valuenow', String(Math.max(60, log.offsetHeight || parseInt(log.style.height, 10) || 200)));     // folded: the height it opens with
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
  // One drag at a time; it ends however the pointer goes (up, cancelled, capture lost). Folded: no drag.
  let drag = null;
  grip.addEventListener('pointerdown', (ev) => {
    if (drag !== null || ev.button !== 0 || dock.classList.contains('closed')) {
      return;       // one drag at a time, the main button only, not while folded
    }
    drag = {y0: ev.clientY, h0: log.offsetHeight};
    grip.setPointerCapture(ev.pointerId);
  });
  // The grip sits on the log's edge away from the page: below it embedded (drag down: bigger), above it docked (drag up: bigger).
  const outward = () => (dock.classList.contains('docked') ? -1 : 1);
  grip.addEventListener('pointermove', (ev) => {
    if (drag !== null) {
      height(drag.h0 + outward() * (ev.clientY - drag.y0));
    }
  });
  ['pointerup', 'pointercancel', 'lostpointercapture'].forEach((t) => grip.addEventListener(t, () => {
    drag = null;
  }));
  grip.addEventListener('keydown', (ev) => {
    if (dock.classList.contains('closed')) {
      return;
    }
    if (ev.key === 'ArrowUp' || ev.key === 'ArrowDown') {
      ev.preventDefault();
      height(log.offsetHeight + (ev.key === 'ArrowDown' ? 28 : -28) * outward());       // a line more or less, towards the grip's side
    }
  });
  if (window.ResizeObserver) {
    new ResizeObserver(fit).observe(dock);
  }
  window.addEventListener('resize', () => {
    limits();
    fit();
  });
  limits();
  try {
    docked(localStorage.getItem('rs-cache-dock') === 'docked');
  } catch (e) {
    docked(false);
  }

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
    say(story, w.story[key].replace('%s', n));
  };
  // What comes next, and when: a countdown with the next step's story, so a reader is not surprised.
  const next = document.querySelector('.cache-next');
  const nextText = next.querySelector('.cache-next-text');
  const nextBar = next.querySelector('.cache-next-bar span');
  const countdown = async (id, ms, upcoming) => {
    const end = performance.now() + ms;
    next.hidden = false;                            // shown first: a hidden element starts no transition
    nextBar.parentElement.hidden = still;           // with reduced motion the seconds alone count down
    nextBar.style.transition = 'none';
    nextBar.style.width = '100%';
    void nextBar.offsetWidth;                       // the bar starts full, then empties over ms
    nextBar.style.transition = 'width ' + ms + 'ms linear';
    nextBar.style.width = '0%';
    while (auto && id === round) {
      const left = end - performance.now();
      if (left <= 0) {
        break;
      }
      nextText.textContent = w.next.replace('%s', String(Math.ceil(left / 1000))).replace('%s', upcoming);
      nextText.title = upcoming;
      await wait(Math.min(250, left));
    }
    nextText.textContent = w.now;       // while the step runs (the story line above says what it is)
    nextText.title = '';
  };
  // One article's steps: the story's key and the request it makes.
  const steps = (n) => {
    const page = '/magazin/' + n;
    return [
      ['publish', () => post('/__cache/publish', 'n=' + n, page + ', /magazin', () => empty(n))],
      ['visit1', () => load(page)],
      ['visit2', () => load(page)],
      ['campaign', () => load(page + '?utm_source=newsletter&utm_campaign=auto')],
      ['memberA', () => load(page, 'A')],
      ['memberB1', () => load('/magazin', 'B')],
      ['memberB2', () => load(page, 'B')],
      ['editor1', () => load(page, 'E')],
      ['editor2', () => load(page, 'E')],
      ['scan', () => Promise.all(scan().map((u) => load(u, '', w.bot)))],
      ['crawler', () => Promise.all(crawl())],
      ['republish', () => post('/__cache/publish', 'n=' + n, page + ', /magazin', () => empty(n))],
      ['visit3', () => load(page)],
      ['visit4', () => load(page)],
    ];
  };
  const run = async (id) => {
    for (let n = Number(article.value); auto && id === round; n = n % 5 + 1) {
      article.value = String(n);
      const list = steps(n);
      for (let i = 0; i < list.length && auto && id === round; i++) {
        const [key, job] = list[i];
        // First the words, then -- a moment later -- the request and its dot: a reader knows what to watch.
        tell(key, n);
        await wait(LEAD);
        if (!auto || id !== round) {
          break;
        }
        await job();
        await queue;
        await film;
        if (!auto || id !== round) {
          break;
        }
        // Time to read the caption (the story was read before): about 40 characters a second, 2 to 4.5 s --
        // counted down with what comes next (after the last step: the next article's first).
        const [nextKey, nextN] = i + 1 < list.length ? [list[i + 1][0], n] : [list[0][0], n % 5 + 1];
        await countdown(id, Math.min(4500, Math.max(2000, 25 * caption.textContent.length)),
          w.story[nextKey].replace('%s', nextN));
      }
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
    // While it plays, the story alone speaks to a screen reader: a caption and a sum each second would be noise.
    [caption, sum].forEach((el) => el.setAttribute('aria-live', on ? 'off' : 'polite'));
    if (on) {
      round++;
      const id = round;
      run(id).finally(() => {
        if (id === round && auto) {
          setAuto(false);
        }
      });
    } else {
      say(story, '');
      next.hidden = true;
    }
  };
  autoButton.addEventListener('click', () => setAuto(!auto));
})();
