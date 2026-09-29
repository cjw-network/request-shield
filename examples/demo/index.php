<?php
/**
 * A mini site protected by cjw-network/request-shield: how it is included, and
 * what each part of it does. Start it with
 *
 *   php -S 127.0.0.1:8080 examples/demo/router.php
 *
 * and open http://127.0.0.1:8080/ -- or put the repository under a web server's
 * document root and open .../examples/demo/ (index.php/... without rewrite rules).
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

// ── The integration: the first lines of the front controller ─────────────────
// Everything below this block runs only for requests the shield lets through.
// (On a site without a front controller, auto_prepend_file does the same.)
$arrived = $_SERVER;                            // demo only: the request before the shield, to show what it removes
define('REQUEST_SHIELD_CONFIG', __DIR__ . '/request-shield.rules');
require __DIR__ . '/../../bootstrap.php';       // with Composer: vendor/autoload.php + Shield::protectFile(...)
// ─────────────────────────────────────────────────────────────────────────────

use CjwNetwork\RequestShield\IpAddress;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Shield;

$decision = Shield::current();
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// Where the demo lives: at the root (PHP's built-in server with router.php),
// or in any subdirectory of a web server -- with rewrite rules (.htaccess:
// /demo/challenge) or without them (/demo/index.php/challenge). $front is
// what every link starts with, $path the demo's own path after it.
$uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
$front = '';
if (substr($script, -10) === '/index.php') {
    $front = strncmp($uri, $script, strlen($script)) === 0 ? $script : substr($script, 0, -10);
}
$path = '/' . ltrim((string) substr($uri, strlen($front)), '/');
$url = static fn (string $local): string => $front . $local;

// "Forget my pass": delete the pass cookie, to see the check again.
if ($path === '/reset') {
    setcookie('rs_pass', '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    header('Location: ' . $url('/'), true, 303);
    exit;
}

$request = Request::fromServer($_SERVER);
$shield = Shield::active();
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

// The pages behind the examples. Everything that gets here was let through.
$content = null;
if ($path === '/search') {
    // An on-demand budget: the page counts each search itself.
    $query = (string) ($_GET['q'] ?? '');
    $counted = $shield !== null ? $shield->consume('searches') : null;
    if ($counted !== null && !$counted->passes()) {
        http_response_code(429);
        header('Retry-After: ' . $counted->retryAfter);
        $content = ['Too many searches', 'More than 10 searches a minute from your address: please wait ' . $counted->retryAfter . ' seconds. (The "searches" budget, counted by this page with Shield::active()->consume(\'searches\').)'];
    } else {
        $content = ['Search', $query === '' ? 'Type something to search for.' : 'No results for "' . $query . '" -- this is a demo. The answer is never cached: q is not in cache-query.'];
    }
} elseif ($path === '/edit') {
    $content = ['Edit form', $method === 'POST' ? 'Saved: "' . (string) ($_POST['message'] ?? '') . '" -- a POST is allowed here (allow POST **/edit) and never cached.' : 'A POST is allowed on this page only.'];
} elseif (strncmp($path, '/admin/', 7) === 0) {
    $content = ['Admin area', 'Only the office network (192.0.2.0/24) gets here.'];
} elseif ($path === '/api/status') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'note' => 'the API answers only this machine (restrict **/api/** to 127.0.0.1 ::1)']), "\n";
    exit;
}

$rule = Shield::currentRule();
// The pass cookie carries its expiry: v1.<expires>.<client>.<signature>. (Shown
// here only; the shield checks the signature, this page just reads the time.)
$passLeft = null;
$pass = explode('.', (string) ($_COOKIE['rs_pass'] ?? ''));
if (count($pass) === 4 && ctype_digit($pass[1]) && (int) $pass[1] > time()) {
    $passLeft = (int) $pass[1] - time();
}
$status = [
    'Decision' => $decision !== null ? $decision->action . ($decision->reason !== '' ? ' (' . $decision->reason . ')' : '') : '—',
    'The rule behind it' => $rule ?? '— (no rule needed: allowed)',
    'May a cache keep this page?' => $decision !== null && $decision->cacheable() ? 'yes' : 'no',
    'Your address' => $request->clientIp,
    'Counted as' => IpAddress::bucket($request->clientIp),
    'Browser check passed (pass cookie)' => $passLeft !== null ? 'yes' : 'no',
    'Pass valid for' => $passLeft !== null ? $passLeft . ' more seconds (set pass-ttl 1m), then the check comes back' : '—',
];

$tests = [
    ['/', 'A normal page', 'allow — passes, may be cached'],
    ['/page/about', 'A known page', 'allow'],
    ['/?utm_source=newsletter', 'An unknown parameter', 'allow-uncached — answered, never cached'],
    ['/random/' . bin2hex(random_bytes(3)), 'An unknown path', 'allow-uncached — passes, the site answers it (a CMS: 200 or 404), but a cache must not keep it'],
    ['/search?q=shield', 'A search page', 'allow-uncached; past 10 searches a minute 429 (a budget the page counts)'],
    ['/edit', 'An edit form', 'a POST is allowed here only'],
    ['/admin/', 'The admin area', '403 — only for the office network'],
    ['//admin/', 'The admin area, sneaked', '403 — "//", "%61" and case do not get past it'],
    ['/api/status', 'The API', 'allowed from this machine only'],
    ['/challenge', 'A page that always checks the browser', 'the invisible check once, then the page'],
    ['/.env', 'What a scanner looks for', '404 — the site never sees it'],
    ['/files/%2e%2e/secret', 'Path traversal', '400'],
    ['/reset', 'Forget my pass cookie', 'the check appears again on /challenge'],
];

// The shield's log (set log ...; log-level flag): what it stopped or flagged, newest first.
$logLines = [];
$logFile = $shield !== null ? $shield->settings->logFile : null;
if ($logFile !== null && is_readable($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES) ?: [];
    $logLines = array_reverse(array_slice($lines, -15));
}

// The request as it arrived: request line and headers, and which of them the
// shield took out of $_SERVER (X-Forwarded-* from a peer that is not a
// trusted proxy). Long values -- the pass cookie -- are shortened.
$short = static fn (string $v): string => strlen($v) > 90 ? substr($v, 0, 87) . '…' : $v;
$requestLines = [];
foreach ($arrived as $name => $value) {
    $header = null;
    if (is_string($name) && strncmp($name, 'HTTP_', 5) === 0) {
        $header = substr($name, 5);
    } elseif ($name === 'CONTENT_TYPE' || $name === 'CONTENT_LENGTH') {
        $header = $name;
    }
    if ($header !== null && is_string($value) && $value !== '') {
        $requestLines[] = [
            str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $header)))),
            $short($value),
            !array_key_exists($name, $_SERVER),
        ];
    }
}
// The full URL: the browser's address bar shortens it.
$fullUrl = $request->scheme . '://' . (is_string($arrived['HTTP_HOST'] ?? null) ? $arrived['HTTP_HOST'] : $request->host)
    . (string) ($arrived['REQUEST_URI'] ?? '/');
$requestLine = ($arrived['REQUEST_METHOD'] ?? 'GET') . ' ' . ($arrived['REQUEST_URI'] ?? '/') . ' ' . ($arrived['SERVER_PROTOCOL'] ?? 'HTTP/1.1');

$title = $path === '/challenge' ? 'You passed the browser check' : ($content !== null ? $content[0] : 'request-shield demo');
header('Content-Type: text/html; charset=utf-8');
// What PHP sends (the web server adds Date, Server and the like).
$responseLines = array_map(static function (string $line) use ($short): array {
    [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
    return [$name, $short(trim($value))];
}, headers_list());
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($title) ?></title>
<style>
  :root { --bg: #f6f7f9; --fg: #1d2127; --muted: #5b6470; --card: #fff; --line: #dfe3e8; --accent: #2f62c9; --ok: #1e7b43; --no: #a3361f; }
  @media (prefers-color-scheme: dark) { :root { --bg: #15181c; --fg: #e7e9ec; --muted: #a0a8b3; --card: #1d2127; --line: #2d333b; --accent: #7aa2ff; --ok: #5fcf8a; --no: #ff8a70; } }
  body { margin: 0; background: var(--bg); color: var(--fg); font: 16px/1.55 system-ui, sans-serif; }
  main { max-width: 52rem; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
  h1 { font-size: 1.6rem; margin: .2rem 0 .3rem; } h2 { font-size: 1.15rem; margin: 2rem 0 .6rem; }
  p.lead { color: var(--muted); margin: 0 0 1.2rem; }
  .card { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 1rem 1.2rem; }
  table { width: 100%; border-collapse: collapse; } td, th { text-align: left; padding: .45rem .5rem; border-top: 1px solid var(--line); vertical-align: top; }
  tr:first-child td, tr:first-child th { border-top: 0; } th { font-weight: 600; width: 45%; }
  .yes { color: var(--ok); font-weight: 600; } .no { color: var(--no); font-weight: 600; }
  a { color: var(--accent); } code, pre { font: 14px/1.5 ui-monospace, monospace; }
  pre { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: .9rem 1rem; overflow-x: auto; }
  form { display: flex; gap: .5rem; flex-wrap: wrap; } input[type=text] { flex: 1 1 14rem; padding: .45rem .6rem; border: 1px solid var(--line); border-radius: 6px; background: var(--bg); color: var(--fg); }
  p.url { margin: 0 0 1rem; } p.url code { word-break: break-all; font-size: 15px; }
  .note { color: var(--muted); font-size: .92rem; margin: 0 0 .5rem; }
  button.peek { margin-top: .35rem; padding: .2rem .6rem; font-size: .85rem; background: transparent; color: var(--accent); border: 1px solid var(--line); }
  pre.answer { margin: .4rem 0 0; font-size: 13px; white-space: pre-wrap; word-break: break-all; }
  button { padding: .45rem .9rem; border: 0; border-radius: 6px; background: var(--accent); color: #fff; cursor: pointer; }
</style>
</head>
<body>
<main>
  <h1><?= $e($title) ?></h1>
  <p class="lead">This page is protected by <strong>cjw-network/request-shield</strong>. Every request below is checked before this page's code runs.</p>

  <?php if ($content !== null): ?><p class="card"><?= $e($content[1]) ?></p><?php endif ?>
  <p class="url"><span class="note">You asked for</span><br><code><?= $e($fullUrl) ?></code></p>

  <div class="card">
    <table>
      <?php foreach ($status as $label => $value): ?>
        <tr><th><?= $e($label) ?></th><td class="<?= $value === 'yes' ? 'yes' : ($value === 'no' ? 'no' : '') ?>"><?= $e($value) ?></td></tr>
      <?php endforeach ?>
    </table>
  </div>

  <h2>Try it</h2>
  <div class="card">
    <table>
      <tr><th>Request</th><td><strong>What the shield does</strong></td></tr>
      <?php foreach ($tests as [$local, $what, $expect]): ?>
        <tr><th><a href="<?= $e($url($local)) ?>"><?= $e($what) ?></a><br><code><?= $e($local) ?></code></th><td><?= $e($expect) ?><br><button type="button" class="peek" data-url="<?= $e($url($local)) ?>">Show the answer</button><pre class="answer" hidden></pre></td></tr>
      <?php endforeach ?>
      <tr><th>Reload any page 20 times</th><td>the invisible check (more than 20 requests a minute), then past 60 a short pause (429)</td></tr>
    </table>
  </div>

  <h2>This request, as it arrived</h2>
  <p class="note">What your browser sent. <span class="no">Struck out</span>: removed by the shield before the page ran (<code>X-Forwarded-*</code> is believed only from a trusted proxy).</p>
  <pre><?= $e($requestLine) . "\n" ?>
<?php foreach ($requestLines as [$name, $value, $removed]): ?>
<?= $removed ? '<del class="no">' : '' ?><?= $e($name) ?>: <?= $e($value) ?><?= $removed ? '</del>' : '' ?>

<?php endforeach ?></pre>

  <h2>The answer's headers</h2>
  <p class="note">What this page sends back; <code>X-Request-Shield</code> is the shield's decision (the web server adds <code>Date</code>, <code>Server</code> and the like).</p>
  <pre><?php foreach ($responseLines as [$name, $value]): ?>
<?= $e($name) ?>: <?= $e($value) ?>

<?php endforeach ?></pre>

  <h2>Forms (POST)</h2>
  <div class="card">
    <form method="post" action="<?= $e($url('/edit')) ?>"><input type="text" name="message" placeholder="Type something"><button type="submit">Save (POST to /edit)</button></form>
    <p class="note" style="margin-top:.8rem">A bot posting wherever it finds a URL: <code>allow POST **/edit</code> lets a POST through on the edit page only.</p>
    <form method="post" action="<?= $e($url('/page/about')) ?>"><input type="hidden" name="message" value="spam"><button type="submit">POST to /page/about</button></form>
  </div>

  <h2>The shield's log</h2>
  <p class="note">What it stopped or flagged, newest first (<code>set log …</code>, <code>set log-level flag</code>); addresses shortened unless <code>set log-ip full</code>.</p>
  <pre><?php if ($logLines === []): ?>(nothing yet — try /.env or /admin/)<?php endif ?><?php foreach ($logLines as $line): ?>
<?= $e($line) . "\n" ?>
<?php endforeach ?></pre>

  <h2>How this page includes the shield</h2>
  <pre>define('REQUEST_SHIELD_CONFIG', __DIR__ . '/request-shield.rules');
require __DIR__ . '/../../bootstrap.php';

$decision = CjwNetwork\RequestShield\Shield::current();   // what the shield decided</pre>
  <p>The rules: <code>examples/demo/request-shield.rules</code> — one per line, and every decision names the line behind it. Every response carries <code>X-Request-Shield</code> with the decision (see your browser's network tab).</p>
</main>
<script>
// "Show the answer": fetches the example in the background and shows status and
// headers -- also for answers the page itself never sees (404, 400, 429).
// Browsers hide Set-Cookie from scripts, and a redirect's details too.
document.querySelectorAll('button.peek').forEach(function (b) {
  b.addEventListener('click', function () {
    var out = b.nextElementSibling;
    out.hidden = false;
    out.textContent = '…';
    fetch(b.getAttribute('data-url'), { redirect: 'manual', cache: 'no-store', credentials: 'same-origin' }).then(function (r) {
      if (r.type === 'opaqueredirect') { out.textContent = 'a redirect (3xx) -- a browser does not let a script read its headers; click the link'; return; }
      var text = 'HTTP ' + r.status + ' ' + r.statusText + '\n';
      r.headers.forEach(function (value, name) { text += name + ': ' + value + '\n'; });
      out.textContent = text;
    }, function (err) { out.textContent = 'failed: ' + err; });
  });
});
</script>
</body>
</html>
