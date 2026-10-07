// request-shield showcase: every try a real request to this server (or, for a
// form from another website, the server's own decision of exactly that
// request); the shield's answer is read from the status and its X-RS header.
(function () {
  'use strict';
  var data = JSON.parse(document.getElementById('showcase-data').textContent);
  var words = data.words;
  var tries = {};
  data.tries.forEach(function (t) { tries[t.n] = t; });

  function path(url) {
    var m = /^[a-z]+:\/\/[^/]+(.*)$/i.exec(url);
    return m ? (m[1] || '/') : url;
  }

  // What the shield decided, in the words of the rules' examples: answered, check, or the status.
  function classify(status, xrs) {
    var rule = null;
    var m = /;\s*rule=([A-Za-z0-9@.-]+)/.exec(xrs || '');
    if (m) { rule = m[1].replace(/@\d+$/, ''); }
    var action = (xrs || '').split(' ')[0];
    var outcome = action === 'challenge' ? 'check'
      : (action === 'allow' || action === 'allow-uncached') ? 'answered' : String(status);
    return { outcome: outcome, rule: rule, status: status, xrs: xrs || '' };
  }

  function live(t) {
    var opts = { method: t.method, headers: { 'X-Forwarded-For': t.from }, credentials: 'omit', cache: 'no-store', redirect: 'manual' };
    if (t.method === 'POST') {
      opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
      opts.body = 'message=Hello';
    }
    return fetch(path(t.url), opts).then(function (r) {
      return classify(r.status, r.headers.get('X-RS'));
    });
  }

  function server(t) {
    return fetch('/__try?n=' + t.n, { credentials: 'omit', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
      var outcome = j.action === 'challenge' ? 'check' : (j.action === 'allow' || j.action === 'allow-uncached') ? 'answered' : String(j.status);
      // A request let through names no rule (as the X-RS header of a real one).
      return { outcome: outcome, rule: j.rule && outcome !== 'answered' ? j.rule.replace(/@\d+$/, '') : null, status: j.status, xrs: j.action + (j.reason ? ' ' + j.reason : '') };
    });
  }

  function kind(outcome) {
    return outcome === 'answered' ? 'pass' : outcome === 'check' ? 'check' : 'stop';
  }

  function label(outcome) {
    return words.outcome[outcome] || outcome;
  }

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined) { e.textContent = text; }
    return e;
  }

  var faces = { pass: 'bi-emoji-smile-fill', check: 'bi-hourglass-split', stop: 'bi-shield-fill-check' };

  function show(card, t, got) {
    var box = card.querySelector('.try-result');
    box.textContent = '';
    var ok = got.outcome === t.outcome && (t.by === null || got.rule === t.by);
    // First in words, with a face; the technical part folded away.
    var say = el('div', 'result-say result-' + kind(got.outcome));
    say.appendChild(el('i', 'bi ' + faces[kind(got.outcome)]));
    say.appendChild(el('span', '', words.say[got.outcome] || label(got.outcome)));
    if (ok) { say.appendChild(el('i', 'bi bi-check2 ms-auto result-ok')); }
    box.appendChild(say);
    var more = el('details', 'result-details');
    more.appendChild(el('summary', '', words.details));
    var line = el('div', 'result-line');
    line.appendChild(el('span', 'pill pill-' + kind(got.outcome), label(got.outcome)));
    line.appendChild(el('span', 'result-status', 'HTTP ' + got.status));
    if (got.rule) { line.appendChild(el('span', 'rule-id', got.rule)); }
    more.appendChild(line);
    more.appendChild(el('div', 'result-from', words.from + ' ' + box.getAttribute('data-from')));
    if (got.xrs) { more.appendChild(el('code', 'result-xrs', 'X-RS: ' + got.xrs)); }
    box.appendChild(more);
    card.classList.remove('is-pass', 'is-check', 'is-stop');
    card.classList.add('is-' + kind(got.outcome), 'flash');
    setTimeout(function () { card.classList.remove('flash'); }, 600);
  }

  function run(card) {
    var t = tries[card.getAttribute('data-n')];
    var btn = card.querySelector('.try-go');
    btn.disabled = true;
    var cardMode = card.getAttribute('data-mode');
    var send = cardMode === 'server' ? server : live;
    if (cardMode === 'repeat') {
      // Sent as often as the example says, by a new visitor each time the button is pressed: the last answer counts.
      var times = parseInt(card.getAttribute('data-times'), 10);
      var visitor = Object.assign({}, t, { from: '198.18.' + Math.floor(Math.random() * 250 + 1) + '.' + Math.floor(Math.random() * 250 + 1) });
      card.querySelector('.try-result').setAttribute('data-from', visitor.from);       // the details name who really sent it
      send = function () {
        var k = 0, last = null;
        var next = function () { return k++ < times ? live(visitor).then(function (g) { last = g; return next(); }) : Promise.resolve(last); };
        return next();
      };
    }
    return send(t).then(function (got) { show(card, t, got); }).catch(function (e) {
      card.querySelector('.try-result').textContent = String(e);
    }).then(function () {
      btn.disabled = false;
      btn.querySelector('span').textContent = words.sent;
    });
  }

  document.querySelectorAll('.card-try[data-n] .try-go').forEach(function (b) {
    b.addEventListener('click', function () { run(b.closest('.card-try')); });
  });

  var all = document.getElementById('try-all');
  if (all) {
    all.addEventListener('click', function () {
      all.disabled = true;
      var cards = Array.prototype.slice.call(document.querySelectorAll('.card-try[data-n] .try-go')).map(function (b) { return b.closest('.card-try'); });
      cards.reduce(function (p, card) {
        return p.then(function () {
          card.scrollIntoView({ behavior: 'smooth', block: 'center' });
          return run(card).then(function () { return new Promise(function (r) { setTimeout(r, 350); }); });
        });
      }, Promise.resolve()).then(function () { all.disabled = false; });
    });
  }

  // The burst: one made-up visitor (an address of the benchmark range) sends page after page.
  document.querySelectorAll('.burst').forEach(function (box) {
    var go = box.querySelector('.burst-go');
    var bar = box.querySelector('.burst-bar');
    var total = parseInt(box.getAttribute('data-pause'), 10) + 4;
    go.addEventListener('click', function () {
      go.disabled = true;
      bar.textContent = '';
      var n = { pass: 0, check: 0, stop: 0 };
      ['pass', 'check', 'stop'].forEach(function (k) { box.querySelector('.n-' + k).textContent = '0'; });
      var ip = '198.18.' + Math.floor(Math.random() * 250 + 1) + '.' + Math.floor(Math.random() * 250 + 1);
      var list = box.querySelector('.burst-list');
      var who = box.querySelector('.burst-ip');
      list.textContent = '';
      who.textContent = '· ' + who.getAttribute('data-label') + ': X-Forwarded-For ' + ip;
      var cells = [];
      for (var i = 0; i < total; i++) { cells.push(bar.appendChild(el('span', 'cell'))); }
      var next = 0;
      function one() {
        var i = next++;
        if (i >= total) { return Promise.resolve(); }
        return fetch('/', { headers: { 'X-Forwarded-For': ip }, credentials: 'omit', cache: 'no-store' }).then(function (r) {
          var xrs = r.headers.get('X-RS') || '';
          var k = kind(classify(r.status, xrs).outcome);
          n[k]++;
          cells[i].className = 'cell cell-' + k;
          // Each cell is one real request: what was sent, what came back.
          cells[i].title = '#' + (i + 1) + ' GET / -> HTTP ' + r.status + '  X-RS: ' + xrs;
          var li = el('li', 'burst-row burst-' + k);
          li.appendChild(el('code', '', 'GET /'));
          li.appendChild(el('span', 'pill pill-' + k, 'HTTP ' + r.status));
          li.appendChild(el('code', 'result-xrs-inline', 'X-RS: ' + xrs));
          list.appendChild(li);
          box.querySelector('.n-' + k).textContent = String(n[k]);
        }).then(one);
      }
      // In order, so the bar reads left to right as the counter rises.
      one().catch(function () {}).then(function () { go.disabled = false; });
    });
  });

  // Pages that count for themselves: the search and the sign-in. Each box is one made-up visitor;
  // the shield's answer to it is real -- a 429 with Retry-After once the page's own count is spent.
  var self = words.self;
  function fmt(s) { var a = Array.prototype.slice.call(arguments, 1); return s.replace(/%[ds]/g, function () { return String(a.shift()); }); }
  function newIp() { return '198.18.' + Math.floor(Math.random() * 250 + 1) + '.' + Math.floor(Math.random() * 250 + 1); }
  document.querySelectorAll('.counter').forEach(function (box) {
    var kindOf = box.getAttribute('data-kind');
    var form = box.querySelector('.counter-form');
    var answer = box.querySelector('.counter-answer');
    var line = box.querySelector('.timeline');
    var summary = box.querySelector('.bot-summary');
    var bot = box.querySelector('.bot-go');
    var ip, running = false;
    function visitor() {
      ip = newIp();
      box.querySelector('.visitor').textContent = '· ' + fmt(self.visitor, ip);
      answer.textContent = ''; line.textContent = ''; summary.textContent = '';
    }
    visitor();
    // One try: the search for the term in the box, or the sign-in with the password in the box.
    function attempt(value) {
      var opts = { headers: { 'X-Forwarded-For': ip }, credentials: 'omit', cache: 'no-store' };
      var url = '/search?q=' + encodeURIComponent(value);
      if (kindOf === 'login') {
        url = '/account/login';
        opts.method = 'POST';
        opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
        opts.body = 'user=demo&password=' + encodeURIComponent(value);
      }
      return fetch(url, opts).then(function (r) {
        if (r.status === 429) {
          // "throttle banned": refused before the page ran; any other 429: the page counted and
          // its limit was spent -- the shield answered for it, and a new, longer pause begins.
          var banned = /^throttle banned/.test(r.headers.get('X-RS') || '');
          return { reached: false, banned: banned, wait: parseInt(r.headers.get('Retry-After') || '0', 10) };
        }
        return r.json().then(function (j) { return { reached: true, data: j }; });
      });
    }
    function say(got, value) {
      answer.textContent = '';
      var p = el('div', 'result-say result-' + (got.reached ? (kindOf === 'login' && !got.data.ok ? 'check' : 'pass') : 'stop'));
      var text;
      if (!got.reached && !got.banned) { p.appendChild(el('i', 'bi bi-hourglass-top')); text = self.limit; }
      else if (!got.reached) { p.appendChild(el('i', 'bi bi-hourglass-split')); text = fmt(self.pause, got.wait); }
      else if (kindOf === 'search') { p.appendChild(el('i', 'bi bi-emoji-smile-fill')); text = fmt(self.hits, got.data.results.length, value); }
      else if (got.data.ok) { p.appendChild(el('i', 'bi bi-emoji-smile-fill')); text = self.welcome; }
      else { p.appendChild(el('i', 'bi bi-x-circle')); text = self.wrong; }
      p.appendChild(el('span', '', text));
      answer.appendChild(p);
    }
    var go = form.querySelector('button');
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (go.disabled) { return; }
      go.disabled = true;
      var value = form.querySelector(kindOf === 'login' ? '[name=password]' : '[name=q]').value;
      attempt(value).then(function (got) { say(got, value); }).catch(function (e) { answer.textContent = String(e); })
        .then(function () { go.disabled = running; });
    });
    box.querySelector('.new-visitor').addEventListener('click', function () { if (!running) { visitor(); } });
    // Bot mode: one try a second for a minute -- the timeline shows which reached the page, and
    // how long each pause lasted (measured: the run of refused seconds).
    bot.addEventListener('click', function () {
      if (running) { running = false; return; }
      running = true;
      go.disabled = true;
      bot.querySelector('span').textContent = self.botStop;
      line.textContent = ''; summary.textContent = '';
      var value = kindOf === 'login' ? 'falsch' : 'bot';
      var total = 60, i = 0, reached = 0, block = null;
      var start = Date.now();
      function close() {
        if (block) { block.label.textContent = fmt(self.blockFor, block.n); block = null; }
      }
      function tick() {
        if (!running || i >= total) {
          close();
          running = false;
          go.disabled = false;
          bot.querySelector('span').textContent = self.botGo;
          summary.textContent = fmt(self.botSummary, reached, i);
          return;
        }
        var n = i++;
        attempt(value).then(function (got) {
          var cell = el('span', 'tick tick-' + (got.reached ? 'pass' : got.banned ? 'stop' : 'check'));
          cell.title = (n + 1) + 's: ' + (got.reached ? 'reached' : got.banned ? 'refused, Retry-After ' + got.wait : 'limit spent, a pause begins');
          if (got.reached) { reached++; close(); line.appendChild(cell); }
          else {
            // A pause starts with the second the limit was spent (yellow) and runs through the refused ones.
            if (!got.banned) { close(); }
            if (!block) {
              var group = el('span', 'tick-block');
              block = { n: 0, group: group, label: el('span', 'tick-label', '') };
              group.appendChild(block.label);
              line.appendChild(group);
            }
            block.n++;
            block.group.insertBefore(cell, block.label);
            block.label.textContent = fmt(self.blockFor, block.n);
          }
          summary.textContent = fmt(self.botSummary, reached, n + 1);
        }).catch(function () {}).then(function () {
          setTimeout(tick, Math.max(0, start + i * 1000 - Date.now()));
        });
      }
      tick();
    });
  });

  // The page's own log, docked bottom right on every part of the page: each line the shield wrote
  // (set log), parsed into time, decision, status, rule and request; new ones light up.
  var dock = document.getElementById('log-dock');
  if (dock) {
    var rows = dock.querySelector('.log-rows');
    var head = dock.querySelector('.log-dock-head');
    var badge = dock.querySelector('.log-new');
    var prev = [];          // the last poll's lines: what follows them is new
    var lines = [];         // every line shown, for switching between readable and original
    var mode = 'nice';
    var first = true;
    var unseen = 0;
    var open = window.matchMedia ? !window.matchMedia('(max-width: 767px)').matches : true;
    try { if (localStorage.getItem('rs-log-dock') === 'closed') { open = false; } } catch (e) {}
    var setOpen = function (o) {
      open = o;
      dock.classList.toggle('closed', !o);
      head.setAttribute('aria-expanded', o ? 'true' : 'false');
      if (o) { unseen = 0; badge.hidden = true; }
      try { localStorage.setItem('rs-log-dock', o ? 'open' : 'closed'); } catch (e) {}
    };
    setOpen(open);
    head.addEventListener('click', function () { setOpen(!open); });
    // Wider: across the page and taller -- for long lines, the original view above all.
    var wideBtn = dock.querySelector('.log-dock-wide');
    var setWide = function (w) {
      dock.classList.toggle('wide', w);
      wideBtn.setAttribute('aria-pressed', w ? 'true' : 'false');
      wideBtn.querySelector('i').className = 'bi ' + (w ? 'bi-arrows-angle-contract' : 'bi-arrows-angle-expand');
      try { localStorage.setItem('rs-log-wide', w ? '1' : '0'); } catch (e) {}
    };
    var wideAtStart = false;
    try { wideAtStart = localStorage.getItem('rs-log-wide') === '1'; } catch (e) {}
    setWide(wideAtStart);
    wideBtn.addEventListener('click', function () { setWide(!dock.classList.contains('wide')); if (!open) { setOpen(true); } });
    // 2026-10-07T21:09:33+00:00 198.51.100.0/24 reject 404 "blocked path" rule=SCAN-HIDDEN ref=X "GET http://…" "UA"
    var shape = /^(\S+) (\S+) (\S+) (\d+) "([^"]*)"(?: rule=(\S+))?(?: claimed=(\S+))?(?: wait=(\d+))?(?: ref=(\S+))? "(\S+) ([^"]*)" "([^"]*)"$/;
    var kindOfAction = function (a) {
      a = a.replace(/^monitor-/, '');
      return a === 'reject' ? 'stop' : a === 'throttle' ? 'slow' : a === 'challenge' ? 'check' : 'pass';
    };
    var row = function (line) {
      var m = shape.exec(line);
      var li = el('li', 'log-row');
      if (mode === 'raw' || !m) { li.classList.add('log-raw'); li.appendChild(el('code', '', line)); return li; }
      li.classList.add('log-' + kindOfAction(m[3]));
      li.appendChild(el('span', 'log-time', m[1].substr(11, 8)));
      li.appendChild(el('span', 'log-ip', m[2]));
      li.appendChild(el('span', 'log-act', m[3] + ' ' + m[4] + (m[8] ? ' · ' + m[8] + ' s' : '')));
      li.appendChild(el('span', 'log-rule', (m[6] || '-') + ' · ' + m[5]));
      var path = m[11].replace(/^[a-z]+:\/\/[^\/]+/i, '');
      var shown = path;
      try { shown = decodeURIComponent(path); } catch (e) {}     // a broken %-escape is shown as it came
      li.appendChild(el('code', 'log-req', m[10] + ' ' + (shown.length > 70 ? shown.substr(0, 70) + '…' : shown)));
      li.title = line;
      return li;
    };
    var poll = function () {
      fetch('/__log', { credentials: 'omit', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
        var fresh = 0;
        // The longest end of the last poll that starts this one: the lines after it are new -- so
        // the same request twice in a second (the same line) is shown twice.
        var cur = j.lines, n = Math.min(prev.length, cur.length);
        while (n > 0 && prev.slice(prev.length - n).join('\n') !== cur.slice(0, n).join('\n')) { n--; }
        prev = cur;
        cur.slice(n).forEach(function (line) {
          var empty = rows.querySelector('.log-empty');
          if (empty) { rows.removeChild(empty); }
          lines.push(line);
          if (lines.length > 40) { lines.shift(); }
          var li = row(line);
          if (!first) { li.classList.add('log-fresh'); fresh++; }
          rows.appendChild(li);
          while (rows.children.length > 40) { rows.removeChild(rows.firstChild); }
        });
        if (fresh && !open) { unseen += fresh; badge.textContent = '+' + unseen; badge.hidden = false; }
        first = false;
        rows.scrollTop = rows.scrollHeight;
      }).catch(function () {});
    };
    dock.querySelectorAll('.log-mode button').forEach(function (b) {
      b.addEventListener('click', function () {
        mode = b.getAttribute('data-mode');
        dock.querySelectorAll('.log-mode button').forEach(function (o) {
          var on = o === b;
          o.classList.toggle('active', on); o.classList.toggle('btn-light', on); o.classList.toggle('btn-outline-light', !on);
          o.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        if (lines.length) { rows.textContent = ''; lines.forEach(function (line) { rows.appendChild(row(line)); }); rows.scrollTop = rows.scrollHeight; }
      });
    });
    poll();
    setInterval(function () { if (!document.hidden) { poll(); } }, 2000);
  }

  // Install: copy a snippet as it is shown.
  document.querySelectorAll('.code-box .copy').forEach(function (b) {
    b.addEventListener('click', function () {
      var text = b.parentNode.querySelector('code').textContent;
      var label = b.lastChild.textContent;
      var done = function () { b.lastChild.textContent = ' ' + b.getAttribute('data-copied'); setTimeout(function () { b.lastChild.textContent = label; }, 1500); };
      if (navigator.clipboard) { navigator.clipboard.writeText(text).then(done, function () {}); }
    });
  });

  // The JSON form: fetch() sends JSON to the API; the shield asks for the check (429, the task in
  // Request-Shield-Challenge), the page solves it with the shield's own solver (widget.js: RS.solve)
  // and sends again with Request-Shield-Solution -- every step shown as it happens.
  var jf = document.querySelector('.json-form');
  if (jf) {
    var aw = words.api;
    var steps = document.querySelector('.json-steps');
    var step = function (cls, text) { var li = el('li', 'json-step json-' + cls, text); steps.appendChild(li); return li; };
    var body = function () { return JSON.stringify({ name: jf.querySelector('[name=name]').value, message: jf.querySelector('[name=message]').value }); };
    var post = function (headers) {
      var h = Object.assign({ 'Content-Type': 'application/json' }, headers || {});
      return fetch('/api/v1/messages', { method: 'POST', headers: h, body: body(), credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.text().then(function (t) { return { r: r, text: t }; }); });
    };
    var answer = function (res) {
      step(res.r.ok ? 'ok' : 'stop', fmt(aw.done, res.r.status, res.text.length > 160 ? res.text.substr(0, 160) + '…' : res.text));
      if (res.r.ok) { step('note', aw.pass); }
    };
    var go = jf.querySelector('[type=submit]');
    jf.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (go.disabled) { return; }
      go.disabled = true;
      steps.textContent = '';
      step('send', aw.sent);
      post().then(function (res) {
        var task = res.r.headers.get('Request-Shield-Challenge');
        if (res.r.status !== 429 || !task || !window.RS || !RS.solve) { return answer(res); }
        step('check', aw.challenge);
        var c = JSON.parse(atob(task.replace(/-/g, '+').replace(/_/g, '/')));
        var started = Date.now();
        return new Promise(function (done) {
          RS.solve(c, function (number, took) {
            step('solved', fmt(aw.solved, Date.now() - started));
            step('send', aw.resend);
            post({ 'Request-Shield-Solution': RS.payload(c, number, took) }).then(answer).then(done, done);
          });
        });
      }).catch(function (e) { step('stop', String(e)); }).then(function () { go.disabled = false; });
    });
    jf.querySelector('.json-bot').addEventListener('click', function () {
      steps.textContent = '';
      step('send', aw.sent);
      var ip = '198.18.' + Math.floor(Math.random() * 250 + 1) + '.' + Math.floor(Math.random() * 250 + 1);
      fetch('/api/v1/messages', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Forwarded-For': ip }, body: body(), credentials: 'omit', cache: 'no-store' })
        .then(function (r) { return r.text().then(function (t) { step('stop', fmt(aw.done, r.status, t.length > 160 ? t.substr(0, 160) + '…' : t)); step('note', aw.botDone); }); })
        .catch(function (e) { step('stop', String(e)); });
    });
    jf.querySelector('.json-forget').addEventListener('click', function () {
      fetch('/__forget', { credentials: 'same-origin', cache: 'no-store' }).then(function () { steps.textContent = ''; step('note', aw.forgotten); });
    });
  }

  // The Exponential example: each row decided on the server with the Exponential rules (/__exp), as
  // request-shield test does; a group and the whole section can be checked in one go.
  var expKind = function (o) { return o === 'answered' || o === 'passes' || o === 'uncached' ? 'pass' : o === 'check' ? 'check' : 'stop'; };
  var expShow = function (row, j) {
    var got = row.querySelector('.exp-got');
    got.textContent = '';
    if (!j) { return false; }
    got.appendChild(el('span', 'pill pill-' + expKind(j.got), label(j.got)));
    got.appendChild(el('span', 'result-status', ' HTTP ' + j.http + (j.rule ? ' · ' + j.rule : '')));
    got.appendChild(el('i', 'bi ' + (j.ok ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-warning') + ' ms-1'));
    row.classList.toggle('exp-ok', !!j.ok);
    row.classList.toggle('exp-bad', !j.ok);
    return !!j.ok;
  };
  var expRun = function (row) {
    var btn = row.querySelector('.exp-go');
    btn.disabled = true;
    return fetch('/__exp?n=' + row.getAttribute('data-n'), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); })
      .then(function (j) { return expShow(row, j); }).catch(function () { return false; }).then(function (ok) { btn.disabled = false; return ok; });
  };
  var expSum = function () {
    var sum = document.querySelector('.exp-summary');
    if (!sum) { return; }
    var rows = document.querySelectorAll('.exp-row'), done = document.querySelectorAll('.exp-row.exp-ok, .exp-row.exp-bad');
    sum.textContent = done.length ? fmt(sum.getAttribute('data-text'), document.querySelectorAll('.exp-row.exp-ok').length, done.length) + (done.length < rows.length ? ' …' : '') : '';
    document.querySelectorAll('.exp-group').forEach(function (g) {
      var ok = g.querySelectorAll('.exp-row.exp-ok').length, all = g.querySelectorAll('.exp-row').length, seen = g.querySelectorAll('.exp-row.exp-ok, .exp-row.exp-bad').length;
      g.querySelector('.exp-group-sum').textContent = seen ? ok + ' / ' + all + ' ✓' : '';
    });
  };
  document.querySelectorAll('.exp-row .exp-go').forEach(function (b) {
    b.addEventListener('click', function () { expRun(b.closest('.exp-row')).then(expSum); });
  });
  var expAll = document.querySelector('.exp-all');
  if (expAll) {
    expAll.addEventListener('click', function () {
      expAll.disabled = true;
      document.querySelectorAll('.exp-group').forEach(function (g) { g.open = true; });
      // All of them decided in one request (n=0), shown row by row.
      fetch('/__exp?n=0', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
        return Array.prototype.slice.call(document.querySelectorAll('.exp-row')).reduce(function (p, row) {
          return p.then(function () { expShow(row, (j.results || {})[row.getAttribute('data-n')]); expSum(); return new Promise(function (r) { setTimeout(r, 40); }); });
        }, Promise.resolve());
      }).catch(function () {}).then(function () { expAll.disabled = false; });
    });
  }

  // The hero's stream: the plain tries, sent one by one, twice round.
  var stream = document.getElementById('stream');
  if (stream) {
    var plain = data.tries.filter(function (t) { return t.times === 1 && !t.pass && Object.keys(t.headers).length === 0 && t.text.charAt(0) !== '('; });
    var i = 0;
    var tick = function () {
      if (i >= plain.length * 2) { return; }
      var t = plain[i++ % plain.length];
      live(t).then(function (got) {
        var li = el('li', 'stream-item stream-' + kind(got.outcome));
        var req = el('code', 'stream-req');
        req.appendChild(el('span', 'm', t.method));
        req.appendChild(document.createTextNode(' ' + decodeURIComponent(path(t.url))));
        li.appendChild(req);
        li.appendChild(el('span', 'pill pill-' + kind(got.outcome), label(got.outcome) + (got.rule ? ' · ' + got.rule : '')));
        stream.insertBefore(li, stream.firstChild);
        while (stream.children.length > 7) { stream.removeChild(stream.lastChild); }
      }).catch(function () {}).then(function () { setTimeout(tick, 1300); });
    };
    setTimeout(tick, 400);
  }
}());
