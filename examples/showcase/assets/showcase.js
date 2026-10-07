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
    var send = card.getAttribute('data-mode') === 'server' ? server : live;
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
