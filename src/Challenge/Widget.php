<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Challenge;

/**
 * The browser check inside a form (proposal 0010): a placeholder
 *
 *   <div data-request-shield></div>
 *   <script src="/request-shield/widget.js" defer></script>
 *
 * becomes a small box that fetches a task from the shield's endpoint when the
 * visitor starts typing, solves it, and puts the answer into a hidden field of
 * the form; Shield::requirePass() (or a checked path) takes it from there. The
 * page itself carries no task, so it stays cacheable. Without JavaScript,
 * nothing changes: the check page as before.
 *
 * Options on the placeholder: data-start="input" (default: the first input
 * into the form), "load", or "submit"; data-endpoint (default: next to the
 * script).
 */
final class Widget
{
    /** The placeholder and, once per page, the script. */
    public static function html(string $path, string $start = 'input'): string
    {
        static $script = false;
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $h = '<div data-request-shield' . ($start !== 'input' ? ' data-start="' . $e($start) . '"' : '') . '></div>';
        if (!$script) {
            $script = true;
            // Versioned: the browser keeps the script a day, and a changed one
            // must reach it at once.
            $h .= '<script src="' . $e($path . '/widget.js?v=' . self::version()) . '" defer></script>';
        }
        return $h;
    }

    /** A short hash of the script, for its address. */
    public static function version(): string
    {
        /** @var string|null $v */
        static $v = null;
        return $v ??= substr(hash('sha256', self::script()), 0, 10);
    }

    /** widget.js: the solver of the check page, and the box. */
    public static function script(): string
    {
        return "var RS = {};\n" . ChallengePage::SCRIPT . "\n" . self::BOX;
    }

    private const BOX = <<<'JS'
(function (R) {
  if (typeof document === 'undefined') { return; }
  var me = document.currentScript, base = ((me && me.src) || '').replace(/\/widget\.js(\?.*)?$/, '');
  function formOf(el) { while (el && el.nodeName !== 'FORM') { el = el.parentNode; } return el; }
  function run(box) {
    var form = formOf(box), endpoint = box.getAttribute('data-endpoint') || (base + '/challenge');
    var start = box.getAttribute('data-start') || 'input';
    var state = 'idle', waiting = null, field = null, expires = 0, texts = { checking: '', checked: '✓', failed: '' };
    box.className += ' rs-widget';
    box.setAttribute('role', 'status');
    box.setAttribute('aria-live', 'polite');
    box.innerHTML = '<span class="rs-icon" aria-hidden="true"></span> <span class="rs-text"></span>';
    var text = box.querySelector('.rs-text'), icon = box.querySelector('.rs-icon');
    function show(s, t) { box.setAttribute('data-state', s); text.textContent = t; icon.textContent = s === 'done' ? '✓' : (s === 'failed' ? '!' : '…'); }
    function finish(s, t) {
      state = s; show(s, t);
      if (waiting) { var b = waiting; waiting = null; if (form.requestSubmit) { form.requestSubmit(b === true ? undefined : b); } else { form.submit(); } }
    }
    function go() {
      if (state !== 'idle') { return; }
      state = 'checking';
      show('checking', texts.checking);
      if (!window.fetch) { finish('failed', ''); return; }
      fetch(endpoint, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) {
          if (!j) { finish('failed', texts.failed); return; }
          texts = j.texts || texts;
          // The "?" next to the box, not in it: the box is a live region, read out on every change.
          var next = box.nextSibling;
          if (j.about && j.about.url && !(next && next.className === 'rs-about')) {
            var a = document.createElement('a');
            a.className = 'rs-about'; a.href = j.about.url; a.target = '_blank'; a.rel = 'noopener';
            a.textContent = '?'; a.title = j.about.text || ''; a.setAttribute('aria-label', j.about.text || '?');
            a.style.marginLeft = '.4em';
            box.parentNode.insertBefore(a, box.nextSibling);
          }
          if (j.passed) {
            // The pass holds until then: send before, or fetch a task first.
            expires = j.until ? +j.until * 1000 : 0;
            finish('done', texts.checked); return;
          }
          show('checking', texts.checking);
          R.solve(j.challenge, function (n, took) {
            if (n < 0) { finish('failed', texts.failed); return; }
            if (!field) { field = document.createElement('input'); field.type = 'hidden'; field.name = j.field; form.appendChild(field); }
            field.value = R.payload(j.challenge, n, took);
            // An answer counts once and for a few minutes (the task says until when).
            var until = /[?&]expires=(\d+)/.exec(j.challenge.salt || '');
            expires = until ? +until[1] * 1000 : 0;
            finish('done', texts.checked);
          });
        }, function () { finish('failed', texts.failed); });
    }
    // Back to the form (the browser's back button, a page kept in its cache):
    // the answer was sent already and counts once -- start again.
    function reset() {
      state = 'idle'; waiting = null; expires = 0;
      if (field) { field.value = ''; }
      show('idle', '');
      if (start === 'load') { go(); }
    }
    window.addEventListener('pageshow', function (ev) { if (ev.persisted) { reset(); } });
    show('idle', '');
    if (!form) { return; }
    if (start === 'load') { go(); }
    if (start === 'input') { form.addEventListener('input', go); form.addEventListener('change', go); }
    // Sent before the check is done: wait for it, then send. Failed: send
    // anyway -- the shield checks on its own then (the check page).
    form.addEventListener('submit', function (ev) {
      // An answer about to expire (the visitor typed for minutes): a new one first.
      if (state === 'done' && expires && Date.now() > expires - 20000) { state = 'idle'; if (field) { field.value = ''; } }
      if (state === 'done' || state === 'failed') { return; }
      ev.preventDefault();
      waiting = ev.submitter || true;
      go();
    });
  }
  var boxes = document.querySelectorAll('[data-request-shield]');
  for (var i = 0; i < boxes.length; i++) { run(boxes[i]); }
})(RS);
JS;
}
