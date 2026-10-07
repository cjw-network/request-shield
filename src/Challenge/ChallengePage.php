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
 * The page a challenged client gets instead of the one it asked for: a few
 * kilobytes, no external resource, nothing to click. Its script finds the
 * proof of work, puts the solution in a cookie and loads the same URL again;
 * the shield checks it there, hands out the pass cookie and lets the request
 * through. Without JavaScript or cookies it says what is missing.
 *
 * The script is ES5 with its own SHA-256: crypto.subtle only exists on HTTPS
 * pages, and for strings this short plain JavaScript is faster anyway.
 */
final class ChallengePage
{
    /**
     * @param array{algorithm: string, challenge: string, maxnumber: int, salt: string, signature: string} $challenge
     * @param array<string, string> $texts in the visitor's language (Texts::all()); missing ones in English
     * @param array{action: string, fields: list<array{0: string, 1: string}>}|false|null $resend
     *   a form that was sent without a pass: its fields, to send it again after the check;
     *   false when it cannot be (files, too large): the visitor is asked to send it again
     * @param string|null $logo the site's logo for the ring's middle (ChallengeLogo, checked when the settings were read); null: a plain shield
     * @param string|null $about where the check is explained for visitors (Help::explained(): set docs-url); null: no link
     */
    public static function render(array $challenge, string $cookieName, bool $secure, array $texts = [], $resend = null, ?string $home = null, ?string $logo = null, ?string $about = null): string
    {
        $t = $texts + \CjwNetwork\RequestShield\Texts::all('en');
        if ($resend !== null) {
            $t['text'] = $resend === false ? $t['resend-lost'] : $t['sending'];
        }
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $js = static fn ($v): string => json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $config = $js([
            'resend' => is_array($resend),
            'back' => $resend === false,
            'c' => $challenge,
            'cookie' => $cookieName,
            'secure' => $secure,
            'nocookies' => $t['nocookies'],
            'failed' => $t['failed'],
        ]);

        return '<!doctype html><html lang="' . $e($t['lang']) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
            . '<title>' . $e($t['title']) . '</title><style>' . self::CSS . '</style></head><body><main>'
            . '<svg id="r" viewBox="0 0 120 120" width="120" height="120" aria-hidden="true" focusable="false">'
            . '<circle class="t" cx="60" cy="60" r="52"/><circle id="b" class="f" cx="60" cy="60" r="52" transform="rotate(-90 60 60)"/>'
            . '<g class="o"><circle cx="60" cy="8" r="5"/></g>'
            . '<g class="l">' . ($logo ?? ChallengeLogo::DEFAULT) . '</g>'
            . '<g class="s"><circle cx="60" cy="60" r="27"/><circle class="e" cx="50" cy="54" r="3.2"/><circle class="e" cx="70" cy="54" r="3.2"/><path d="M47 66q13 12 26 0"/></g>'
            . '<g class="x"><path d="M60 43v20"/><circle cx="60" cy="75" r="3.4"/></g></svg>'
            . '<h1>' . $e($t['title']) . '</h1><p id="m" role="status">' . $e($t['text']) . '</p>'
            . '<noscript><p><strong>' . $e($t['noscript']) . '</strong></p></noscript>'
            . self::resendForm($resend, $t, $e)
            . ($home !== null ? '<p class="home"><a href="' . $e($home) . '">' . $e($t['home']) . '</a></p>' : '')
            . ($about !== null ? '<p class="home"><a href="' . $e($about) . '" target="_blank" rel="noopener">' . $e($t['about']) . '</a></p>' : '')
            . '</main>'
            . '<script>var RS=' . $config . ';' . self::SCRIPT . '</script></body></html>';
    }

    /**
     * The ring fills with the solver's progress while a dot circles the logo;
     * done, the logo gives way to a smile; failed, a calm "!". Without
     * JavaScript nothing moves (the dot only circles once the script runs).
     * With prefers-reduced-motion: no circling, no fading -- the ring fills.
     */
    private const CSS = ':root{--bg:#f6f7f9;--fg:#222;--acc:#3b6fd4;--trk:#dde1e6;--ok:#1e7b43;--okbg:#e6f4ea;--warn:#a86b00}'
        . '@media(prefers-color-scheme:dark){:root{--bg:#16181c;--fg:#e6e6e6;--acc:#7aa2ff;--trk:#2b3038;--ok:#6fcf97;--okbg:#17301f;--warn:#e0b050}}'
        . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font:16px/1.5 system-ui,sans-serif;background:var(--bg);color:var(--fg)}'
        . 'main{max-width:28rem;padding:2rem;text-align:center}.home{margin-top:2rem;font-size:.9rem}.home a{color:inherit;opacity:.7}h1{font-size:1.3rem;margin:.8rem 0 .5rem}'
        . '#r{display:block;margin:0 auto;color:var(--acc);overflow:visible}.t,.f,#r .s path,#r .x path{fill:none;stroke-linecap:round}'
        . '.t{stroke:var(--trk);stroke-width:6}.f{stroke:var(--acc);stroke-width:6;stroke-dasharray:326.7;stroke-dashoffset:326.7;transition:stroke-dashoffset .25s,stroke .3s}'
        . '#r .o circle{fill:var(--acc);opacity:0}.o{transform-origin:60px 60px}#r.run .o circle{opacity:.9}.run .o{animation:rs-o 1.4s linear infinite}@keyframes rs-o{to{transform:rotate(1turn)}}'
        . '.l,.s,.x{transform-origin:60px 60px;transition:opacity .3s,transform .3s}.s,.x{opacity:0;transform:scale(.6)}'
        . '#r .s circle{fill:var(--okbg);stroke:var(--ok);stroke-width:3.5}#r .s .e{fill:var(--ok);stroke:none}#r .s path{stroke:var(--ok);stroke-width:3.5}'
        . '#r .x path{stroke:var(--warn);stroke-width:6}#r .x circle{fill:var(--warn)}'
        . '.ok .l,.no .l{opacity:0;transform:scale(.6)}.ok .s,.no .x{opacity:1;transform:none}.ok .f{stroke:var(--ok)}.no .f{stroke:var(--warn)}.ok .o,.no .o{display:none}'
        . '@media(prefers-reduced-motion:reduce){.run .o{animation:none}#r.run .o circle{opacity:0}.l,.s,.x,.f{transition:none}}';

    /**
     * The form that was sent, as hidden fields: the script sends it again once
     * the check is done; without JavaScript the button does. Nothing of it is
     * kept on the server.
     *
     * @param array{action: string, fields: list<array{0: string, 1: string}>}|false|null $resend
     * @param array<string, string> $t
     * @param \Closure(string): string $e
     */
    private static function resendForm($resend, array $t, \Closure $e): string
    {
        if ($resend === false) {
            return '<p><a href="javascript:history.back()" id="back" hidden>' . $e($t['back']) . '</a></p>';
        }
        if ($resend === null) {
            return '';
        }
        $h = '<form id="resend" method="post" action="' . $e($resend['action']) . '">';
        foreach ($resend['fields'] as [$name, $value]) {
            $h .= '<input type="hidden" name="' . $e($name) . '" value="' . $e($value) . '">';
        }
        return $h . '<noscript><button type="submit">' . $e($t['send-again']) . '</button></noscript></form>';
    }

    /** The solver. Kept in one place so tests can run exactly this code. */
    public const SCRIPT = <<<'JS'
(function (R) {
  var K = [0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2];
  var W = new Array(64);
  function sha256(s) {
    var n = s.length, w = [], i, j, t;
    for (i = 0; i < n; i++) { w[i >> 2] |= (s.charCodeAt(i) & 255) << (24 - (i % 4) * 8); }
    w[n >> 2] |= 0x80 << (24 - (n % 4) * 8);
    var len = (((n + 8) >> 6) + 1) * 16;
    for (i = 0; i < len; i++) { w[i] = w[i] | 0; }
    w[len - 1] = n * 8;
    var h0 = 0x6a09e667, h1 = 0xbb67ae85, h2 = 0x3c6ef372, h3 = 0xa54ff53a, h4 = 0x510e527f, h5 = 0x9b05688c, h6 = 0x1f83d9ab, h7 = 0x5be0cd19;
    for (j = 0; j < len; j += 16) {
      var a = h0, b = h1, c = h2, d = h3, e = h4, f = h5, g = h6, h = h7;
      for (t = 0; t < 64; t++) {
        if (t < 16) { W[t] = w[j + t]; }
        else {
          var x = W[t - 15], y = W[t - 2];
          W[t] = (W[t - 16] + ((x >>> 7 | x << 25) ^ (x >>> 18 | x << 14) ^ (x >>> 3)) + W[t - 7] + ((y >>> 17 | y << 15) ^ (y >>> 19 | y << 13) ^ (y >>> 10))) | 0;
        }
        var t1 = (h + ((e >>> 6 | e << 26) ^ (e >>> 11 | e << 21) ^ (e >>> 25 | e << 7)) + ((e & f) ^ (~e & g)) + K[t] + W[t]) | 0;
        var t2 = (((a >>> 2 | a << 30) ^ (a >>> 13 | a << 19) ^ (a >>> 22 | a << 10)) + ((a & b) ^ (a & c) ^ (b & c))) | 0;
        h = g; g = f; f = e; e = (d + t1) | 0; d = c; c = b; b = a; a = (t1 + t2) | 0;
      }
      h0 = (h0 + a) | 0; h1 = (h1 + b) | 0; h2 = (h2 + c) | 0; h3 = (h3 + d) | 0;
      h4 = (h4 + e) | 0; h5 = (h5 + f) | 0; h6 = (h6 + g) | 0; h7 = (h7 + h) | 0;
    }
    var out = '', hs = [h0, h1, h2, h3, h4, h5, h6, h7];
    for (i = 0; i < 8; i++) { out += ('0000000' + (hs[i] >>> 0).toString(16)).slice(-8); }
    return out;
  }
  function b64url(s) { return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
  function solve(c, done, progress) {
    var n = 0, start = Date.now();
    (function step() {
      var end = Math.min(n + 4000, c.maxnumber);
      for (; n <= end; n++) {
        if (sha256(c.salt + n) === c.challenge) { return done(n, Date.now() - start); }
      }
      if (n > c.maxnumber) { return done(-1, 0); }
      if (progress) { progress(n / c.maxnumber); }
      setTimeout(step, 0);
    })();
  }
  function payload(c, number, took) {
    return b64url(JSON.stringify({ algorithm: c.algorithm, challenge: c.challenge, number: number, salt: c.salt, signature: c.signature, took: took }));
  }
  R.sha256 = sha256;
  R.solve = solve;
  R.payload = payload;
  // Without a task (the widget, tests in Node): only the solver above.
  if (typeof document === 'undefined' || !R.c) { return; }
  var m = document.getElementById('m'), ring = document.getElementById('r'), bar = document.getElementById('b');
  // The ring: its arc fills with the progress; "run" (circling), "ok" (a smile), "no" (a calm "!").
  // Only looks: a page without the ring (a site's own) still checks.
  function show(p) { if (bar && bar.style) { bar.style.strokeDashoffset = (326.7 * (1 - p)).toFixed(1); } }
  function state(s) { if (ring && ring.setAttribute) { ring.setAttribute('class', s); } }
  function failed(text) { m.textContent = text; state('no'); }
  state('run');
  // Against a loop -- a check that never takes: after three attempts at the
  // same address within a minute, stop and say so. Only those count: checks
  // passed before, other pages, time gone by do not.
  var here = location.pathname + location.search, now = Date.now(), tries = 0;
  try {
    var last = JSON.parse(sessionStorage.getItem('rs-tries') || 'null');
    if (last && last.u === here && now - last.t < 60000) { tries = last.n; }
    sessionStorage.setItem('rs-tries', JSON.stringify({ u: here, n: tries + 1, t: now }));
  } catch (e) {}
  if (tries >= 3) { failed(R.failed); try { sessionStorage.removeItem('rs-tries'); } catch (e) {} return; }
  solve(R.c, function (number, took) {
    if (number < 0) { failed(R.failed); return; }
    document.cookie = R.cookie + '=' + payload(R.c, number, took) + '; path=/; max-age=300; SameSite=Lax' + (R.secure ? '; Secure' : '');
    if (document.cookie.indexOf(R.cookie + '=') < 0) { failed(R.nocookies); return; }
    show(1);
    state('ok');
    // The smile starts at once; the page it goes to loads meanwhile (a browser
    // keeps showing this one until the next arrives): two frames, no waiting.
    setTimeout(function () {
      if (R.resend) { document.getElementById('resend').submit(); return; }
      if (R.back) { var b = document.getElementById('back'); b.hidden = false; return; }
      location.reload();
    }, 40);
  }, show);
})(RS);
JS;
}
