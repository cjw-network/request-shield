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
// The same request, checked step by step on the active rules page -- with the
// diagram of where it goes. Nothing is counted there.
$pathOf = static fn (string $local, string $method = 'GET', string $ip = ''): string => $front . '/rules?method=' . $method
    . '&url=' . rawurlencode($front . $local) . ($ip !== '' ? '&ip=' . rawurlencode($ip) : '') . '#check';
// The shield's own pages (404, a pause, the check page) link back to the demo's
// front page: "set home ${REQUEST_SHIELD_DEMO_HOME:-/}" in the rules.
if (function_exists('putenv')) {                // a tight shared host may disable it: the rules' default "/" then
    putenv('REQUEST_SHIELD_DEMO_HOME=' . $front . '/');
}

// ── The integration: the first lines of the front controller ─────────────────
// Everything below this block runs only for requests the shield lets through.
// (On a site without a front controller, auto_prepend_file does the same.)
$arrived = $_SERVER;                            // demo only: the request before the shield, to show what it removes
define('REQUEST_SHIELD_CONFIG', __DIR__ . '/request-shield.rules');
require __DIR__ . '/../../bootstrap.php';       // with Composer: vendor/autoload.php + Shield::protectFile(...)
// ─────────────────────────────────────────────────────────────────────────────

use CjwNetwork\RequestShield\IpAddress;
use CjwNetwork\RequestShield\Report\Describe;
use CjwNetwork\RequestShield\Report\Diagram;
use CjwNetwork\RequestShield\Report\RulesPage;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Shield;

$decision = Shield::current();
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');


// "Forget my pass": delete the pass cookie, to see the check again.
if ($path === '/reset') {
    setcookie('rsp', '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    header('Location: ' . $url('/'), true, 303);
    exit;
}

$request = Request::fromServer($_SERVER);
$shield = Shield::active();
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Who reads the dashboard (dashboard-access in the rules): a customer by its cookie

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
    // 3 edits a minute, counted here; past that the shield answers itself --
    // the check, then this form is sent again -- and this request ends there.
    if ($method === 'POST' && $shield !== null) {
        $shield->consume('edits', null, null, true);
    }
    $content = ['Edit form', $method === 'POST' ? 'Saved: "' . (string) ($_POST['message'] ?? '') . '" -- a POST is allowed here (allow POST **/edit) and never cached.' : 'A POST is allowed on this page only.'];
} elseif (strncmp($path, '/admin/', 7) === 0) {
    $content = ['Admin area', 'Only the office network (192.0.2.0/24) gets here.'];
} elseif ($path === '/comment') {
    // The site decides: a comment is only taken from a browser that passed the
    // check. Without a pass, the visitor gets the check -- and the comment is
    // sent again by itself afterwards, nothing typed is lost.
    if ($method === 'POST' && $shield !== null) {
        $shield->requirePass();
        $content = ['Comment', 'Thank you — your comment "' . (string) ($_POST['comment'] ?? '') . '" arrived (a demo: nothing is saved). It came from a browser that passed the check: Shield::active()->requirePass() before saving.'];
    } else {
        $content = ['Comment', 'Write a comment and send it. Without a pass, the check comes first — and your comment is sent again by itself afterwards.'];
    }
} elseif ($path === '/contact') {
    // The check inside the form: the box solved it while the visitor typed,
    // and requirePass() finds the answer in the form -- files and all.
    if ($method === 'POST' && $shield !== null) {
        $shield->requirePass();
        $file = $_FILES['attachment'] ?? null;
        $got = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK ? ' with the file "' . (string) $file['name'] . '" (' . (int) $file['size'] . ' bytes)' : '';
        $content = ['Contact', 'Thank you — your message "' . (string) ($_POST['message'] ?? '') . '" arrived' . $got . ' (a demo: nothing is kept). The browser check ran inside the form while you typed; no check page, nothing sent twice.'];
    } else {
        $content = ['Contact', 'Start typing: the box under the form checks your browser in the background. Send it, with a file if you like — it goes straight through.'];
    }
} elseif ($path === '/checkout') {
    $content = ['Checkout', 'You passed a browser check in the last 20 seconds (challenge **/checkout max-age 20s). Come back after 20 seconds: the check comes again, although your pass still holds everywhere else.'];
} elseif (strncmp($path, '/old/', 5) === 0) {
    $content = ['The old API', 'The site answered: the rule for /old/** is only watched (monitor). The log below notes "monitor-reject" — what it would have done. When the log shows no false hits, remove the word monitor.'];
} elseif ($path === '/profile') {
    // The page itself asks for the check, with a header (set app-challenge on).
    header('X-RS-Check: 1');
    $content = ['Profile', $method === 'POST' ? 'Saved: "' . (string) ($_POST['name'] ?? '') . '" (a demo: nothing is saved).' : 'This page asked for the browser check with a header — X-RS-Check: 1 — so you only see this form with a pass. The header never reaches your browser.'];
} elseif (strncmp($path, '/files/', 7) === 0) {
    $content = ['File reader', 'The file reader would show "' . substr($path, 7) . '" here (a demo: nothing is read). Hidden files and backups get through at /files/ only, and only for this machine (unblock … at **/files/** for 127.0.0.1 ::1); from anywhere else they are refused.'];
} elseif ($path === '/rules' && $shield !== null) {
    // The active rules, in plain words, with a live check (restricted to this
    // machine by the rules). Loaded only here: a normal request never does.
    header('Content-Type: text/html; charset=utf-8');
    echo RulesPage::render($shield->settings, ['check' => $_GET, 'action' => $url('/rules'), 'ip' => $request->clientIp, 'title' => 'Active rules — request-shield demo',
        'home' => $url('/'), 'homeLabel' => 'request-shield demo']);
    exit;
} elseif ($path === '/sitemap.xml') {
    // A sitemap, so the statistics can show who reads it (and when last).
    header('Content-Type: application/xml; charset=utf-8');
    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $request->host;
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach (['/', '/page/about', '/contact', '/search'] as $p) {
        echo '<url><loc>' . htmlspecialchars($base . $url($p), ENT_XML1) . '</loc></url>';
    }
    echo "</urlset>\n";
    exit;
} elseif ($path === '/customer-menu' && $shield !== null) {
    // A pretend hosting panel: Customer A's own menu. Its "Statistics" item is a
    // signed link made here, on the panel's server, with the shield's secret
    // (Access::link()): no token in it, valid ten minutes, Customer A's websites only.
    $stats = \CjwNetwork\RequestShield\Report\StatsPage::links($shield->settings);
    $link = $url($stats['sites'] ?? $stats['site']) . '?' . \CjwNetwork\RequestShield\Access::link($shield->settings, 'Customer A', 600);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Panel — Customer A</title>'
        . '<style>body{margin:0;font:15px/1.5 system-ui,sans-serif;background:#eef1f5;color:#1d2127}header{background:#1f3b5c;color:#fff;padding:12px 20px;font-weight:600}'
        . 'main{display:flex;gap:20px;padding:20px;max-width:1000px;margin:0 auto}nav{background:#fff;border-radius:10px;padding:10px 0;min-width:220px;align-self:start}'
        . 'nav a{display:block;padding:8px 18px;color:#1f3b5c;text-decoration:none}nav a.on{background:#e3eaf5;font-weight:600}section{background:#fff;border-radius:10px;padding:16px 20px;flex:1}'
        . 'code{background:#f2f4f7;padding:2px 6px;border-radius:4px}.note{color:#5b6470;font-size:13px}</style></head><body>'
        . '<header>Hosting panel · Customer A <span style="font-weight:400;opacity:.8">(a demo: a pretend panel)</span></header><main><nav>'
        . '<a href="#">Domains</a><a href="#">Mail</a><a href="#">Databases</a><a class="on" href="' . $e($link) . '">Statistics</a><a href="#">Invoices</a></nav><section>'
        . '<h2 style="margin-top:0">Your statistics</h2><p><a href="' . $e($link) . '">Open the statistics of your websites →</a></p>'
        . '<p class="note">The link is made on the panel\'s server with <code>Access::link($settings, \'Customer A\', 600)</code>: no token in it, valid ten minutes, '
        . 'and it opens Customer A\'s websites only (here: localhost84) — never Customer B\'s, never the rules or the live view. Opened, it signs the browser in for eight hours.</p>'
        . '<h3>Or sign in with a token</h3><p>The demo\'s token for Customer A (a demo only — a real one is printed once by <code>bin/request-shield access-token</code>):<br><code>demo-customer-a-5b8e2d1f7c4a9e06b3d1f8a2c7e5</code></p>'
        . '<p><a href="' . $e($url($stats['site']) . '?rs-login=1') . '">The login form</a> · <a href="' . $e($url($stats['site']) . '?rs-logout=1') . '">Sign out</a> (back to the admin\'s view on this machine)</p>'
        . '</section></main></body></html>';
    exit;
} elseif ($path === '/api/status') {
    // 5 calls a minute; past that: 429 with the task as JSON and in the header
    // Request-Shield-Challenge -- solved, the call is repeated with Request-Shield-Solution.
    if ($shield !== null) {
        $shield->consume('calls', null, null, true);
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'note' => 'the API answers only this machine (restrict **/api/** to 127.0.0.1 ::1)']), "\n";
    exit;
}

// A page the demo does not have: "not found" (404), as a CMS would answer it --
// with statistics on, it shows up under "Not found", with the page linking to it.
if ($content === null && !in_array($path, ['/', '/index.php', '/challenge'], true) && strncmp($path, '/page/', 6) !== 0) {
    http_response_code(404);
    $content = ['Not found', 'There is no page "' . $path . '" in this demo (a 404, as a CMS would answer). With statistics on (set stats on), it is listed under "Not found" on /stats, with the page that linked here.'];
}

$rule = Shield::currentRule();
// The pass cookie carries its expiry: 2.<expires, base36>.<client>.<signature>.
// (Shown here only; the shield checks the signature, this page just reads the time.)
$passLeft = null;
$pass = explode('.', (string) ($_COOKIE['rsp'] ?? ''));
if (count($pass) === 4 && $pass[0] === '2' && preg_match('/^[0-9a-z]{1,8}$/', $pass[1]) === 1 && (int) base_convert($pass[1], 36, 10) > time()) {
    $passLeft = (int) base_convert($pass[1], 36, 10) - time();
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

// The examples, by what they show. [path, what, what the shield does]
$groups = [
    'Normal visitors' => [
        ['/', 'A normal page', 'passes; a cache may keep it'],
        ['/page/about', 'A known page', 'passes; a cache may keep it'],
        ['/search?q=shield', 'A search page', 'passes, never cached; past 10 searches a minute: wait (a budget the page counts)'],
        ['/edit', 'An edit form', 'a POST is accepted here, and only here'],
    ],
    'Keeping the cache clean' => [
        ['/?utm_source=newsletter', 'A link from a newsletter', 'passes (a known marketing tag), but a cache must not keep it'],
        ['/random/' . bin2hex(random_bytes(3)), 'An unknown path', 'the site answers it (a CMS: 200 or 404), but a cache must not keep it'],
    ],
    'Turning attackers away' => [
        ['/.env', 'What a scanner looks for', '"not found" (404) — the site never sees it'],
        ['/?page=2%27', 'A parameter not of its type', '"not found" (404) — page is a number: no attack pattern needs to run'],
        ['/?debug=1', 'A parameter the site does not know', '"not found" (404) — query strict'],
        ['/files/%2e%2e/secret', 'Leaving the site\'s folder', 'a broken request (400)'],
        ['/old/api', 'A rule being watched', 'passes — the rule is marked monitor: the log notes that it would have refused it'],
        ['//admin/', 'The admin area, sneaked', 'no access (403) — "//", "%61" and case do not get past it'],
    ],
    'Doors for certain people' => [
        ['/admin/', 'The admin area', 'no access (403) — only for the office network'],
        ['/api/status', 'The API', 'answers this machine only'],
        ['/files/.env', 'A hidden file in the admin\'s file reader', 'passes from this machine: hidden files are open at /files/ only'],
        ['/rules', 'The active rules', 'every rule in plain words, and a check for any address (this machine only)'],
        ['/rs/stats/overview', 'Statistics: the overview', 'everything at a glance: who came, what the shield did, pages, crawlers, rules (this machine only)'],
        ['/rs/stats/visitors', 'Statistics: visitors & pages', 'for editors: people, crawlers, bots; the most visited pages and sections, broken links, sitemaps'],
        ['/rs/stats/protection', 'Statistics: protection', 'for admins: what the shield did, the answers, the rules, bots'],
        ['/rs/waf/live', 'Live', 'what the shield stops right now, from the log: website, address, request, why, and where from (a list, a ban, the site\'s own rule, a built-in one) (this machine only)'],
        ['/rs/waf/lists', 'Lists', 'keep an address out or let it in, with a comment of your own; extend, remove; the active bans (this machine only)'],
        ['/rs/waf/rules', 'Statistics: rules & setup', 'the way of a request through the shield, every rule in words, every technical setting (this machine only)'],
        ['/rs/stats/visitors?lang=de', 'Statistik auf Deutsch', 'the same page in German (it also follows your browser\'s language)'],
        ['/customer-menu', 'A customer\'s menu', 'a pretend hosting panel: its "Statistics" item is a signed link (Access::link()) that opens Customer A\'s statistics only -- try it, then sign out'],
        ['/rs/stats/sites', 'Statistics: all websites', 'each customer (stats-group) with its websites, where the traffic is, against the period before; a click opens one'],
        ['/rs/stats/visitors?site=localhost84', 'Statistics: one website', 'set stats-hosts localhost84 127.0.0.1: each its own numbers, any other Host name as "other hosts"; the switch at the top'],
        ['/rs/stats/visitors?path=' . rawurlencode($url('/page/')), 'Statistics: one subtree', 'the "path starts with" filter: views of one section, by people, crawlers, bots'],
    ],
    'The site asks for the check' => [
        ['/comment', 'A comment form', 'sending it needs a pass: without one, the check — then the comment is sent again by itself'],
        ['/profile', 'A page that asks for the check', 'the page sets X-RS-Check: 1 — the form only with a pass'],
        ['/contact', 'The check inside the form', 'a box in the form checks while you type — sent straight through, a file too'],
    ],
    'Browser check and pace' => [
        ['/challenge', 'A page that always checks the browser', 'the invisible check once, then the page (valid for 1 minute here)'],
        ['/checkout', 'A checkout: only a fresh pass', 'the check if your pass is older than 20 seconds — although it holds a minute elsewhere (max-age)'],
        ['/reset', 'Forget my pass', 'the check comes back on /challenge'],
        [null, 'Reload any page 20 times', 'the invisible check (more than 20 requests a minute); past 60 a check that frees the counter — no pause'],
    ],
    'Forms (POST)' => [
        ['/edit', 'Send a form where one belongs', 'accepted: allow POST **/edit', 'POST'],
        ['/page/about', 'Send a form where none belongs', '"not allowed here" (405) — a bot posting wherever it finds a URL', 'POST'],
        ['/edit', 'Send the edit form a 4th time within a minute', 'the check instead of a pause — then the form is sent again, the counter starts over', 'POST'],
    ],
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
if ($path === '/challenge') {
    $content = ['You passed the browser check', 'Your browser just solved a small task in the background, sent the answer back and got a pass — that is all a visitor ever notices: a moment of "One moment, please". The steps below show what happened.'];
}
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
  :root { --bg: #f6f7f9; --fg: #1d2127; --muted: #5b6470; --card: #fff; --line: #dfe3e8; --accent: #2f62c9; --ok: #1e7b43; --okbg: #e6f4ea; --warn: #8a5a00; --warnbg: #fdf3dc; --no: #a3361f; --nobg: #fbe9e5; }
  @media (prefers-color-scheme: dark) { :root { --bg: #15181c; --fg: #e7e9ec; --muted: #a0a8b3; --card: #1d2127; --line: #2d333b; --accent: #7aa2ff; --ok: #5fcf8a; --okbg: #17301f; --warn: #f0c060; --warnbg: #3a2f15; --no: #ff8a70; --nobg: #3d1f19; } }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--fg); font: 16px/1.55 system-ui, sans-serif; }
  header.bar { background: var(--card); border-bottom: 1px solid var(--line); }
  header.bar div { max-width: 60rem; margin: 0 auto; padding: .7rem 1rem; display: flex; gap: .8rem; align-items: center; flex-wrap: wrap; }
  header.bar .name { font-weight: 700; margin-right: auto; color: var(--fg); text-decoration: none; }
  a.button { display: inline-block; padding: .4rem .9rem; border-radius: 6px; background: var(--accent); color: #fff; text-decoration: none; font-weight: 600; }
  a.quiet { color: var(--muted); }
  main { max-width: 60rem; margin: 0 auto; padding: 1.3rem 1rem 3rem; }
  h1 { font-size: 1.5rem; margin: .2rem 0 .2rem; } h2 { font-size: 1.15rem; margin: 2rem 0 .6rem; } h3 { font-size: 1rem; margin: 0 0 .5rem; }
  p.lead { color: var(--muted); margin: 0 0 1rem; }
  .card { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 1rem 1.2rem; }
  .verdict { display: flex; gap: .8rem; align-items: flex-start; border-radius: 10px; padding: .9rem 1.1rem; margin-bottom: .8rem; }
  .verdict.pass { background: var(--okbg); } .verdict.note { background: var(--warnbg); } .verdict.stop { background: var(--nobg); }
  .icon { flex: 0 0 1.7rem; height: 1.7rem; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; color: #fff; }
  .pass .icon { background: var(--ok); } .note .icon { background: var(--warn); } .stop .icon { background: var(--no); }
  .verdict code { word-break: break-all; }
  .facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(12rem, 100%), 1fr)); gap: .6rem; margin-bottom: .5rem; }
  .facts div { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: .55rem .8rem; }
  .facts span { display: block; color: var(--muted); font-size: .85rem; }
  .groups { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(22rem, 100%), 1fr)); gap: .8rem; }
  .groups ul { list-style: none; margin: 0; padding: 0; }
  .groups li { padding: .5rem 0; border-top: 1px solid var(--line); } .groups li:first-child { border-top: 0; padding-top: 0; }
  .groups li a { font-weight: 600; } .groups li code { color: var(--muted); font-size: 13px; }
  .expect { display: block; color: var(--muted); font-size: .92rem; }
  a { color: var(--accent); } code, pre { font: 14px/1.5 ui-monospace, monospace; }
  pre { background: var(--bg); border: 1px solid var(--line); border-radius: 8px; padding: .8rem 1rem; overflow-x: auto; margin: .5rem 0 0; }
  .yes { color: var(--ok); font-weight: 600; } .no { color: var(--no); font-weight: 600; }
  form { display: flex; gap: .5rem; flex-wrap: wrap; margin: .3rem 0; } input[type=text] { flex: 1 1 14rem; padding: .45rem .6rem; border: 1px solid var(--line); border-radius: 6px; background: var(--bg); color: var(--fg); font: inherit; }
  .note { color: var(--muted); font-size: .92rem; margin: .4rem 0; }
  button { padding: .45rem .9rem; border: 0; border-radius: 6px; background: var(--accent); color: #fff; cursor: pointer; font: inherit; }
  button.secondary { background: transparent; color: var(--accent); border: 1px solid var(--line); }
  .rs-widget { display: flex; align-items: center; gap: .45rem; flex-basis: 100%; font-size: .9rem; color: var(--muted); min-height: 1.6rem; }
  .rs-widget .rs-icon { display: inline-flex; width: 1.4rem; height: 1.4rem; border-radius: 50%; align-items: center; justify-content: center; border: 1px solid var(--line); font-weight: 700; }
  .rs-widget[data-state="done"] .rs-icon { background: var(--ok); color: #fff; border-color: var(--ok); }
  .rs-widget[data-state="checking"] .rs-icon { border-color: var(--accent); color: var(--accent); }
  table.tests { width: 100%; border-collapse: collapse; background: var(--card); border: 1px solid var(--line); border-radius: 10px; overflow: hidden; }
  table.tests td, table.tests th { padding: .5rem .6rem; border-top: 1px solid var(--line); vertical-align: top; text-align: left; }
  table.tests tr.group th { background: var(--bg); font-size: .95rem; padding-top: .7rem; }
  table.tests td.no { width: 3rem; font-weight: 700; white-space: nowrap; } table.tests td.no a { color: var(--fg); text-decoration: none; }
  table.tests td.what { width: 34%; } table.tests td.what code { color: var(--muted); font-size: 12.5px; word-break: break-all; }
  table.tests td.expect { color: var(--muted); font-size: .92rem; }
  table.tests td.try { width: 1%; white-space: nowrap; } table.tests td.try a.path { margin-left: .4rem; }
  table.tests tr:target { background: var(--warnbg); }
  table.tests tr.answer-row td { border-top: 0; padding-top: 0; }
  form.inline { display: inline; margin: 0; } button.linkish { background: none; border: 0; padding: 0; color: var(--accent); text-decoration: underline; font: inherit; cursor: pointer; }
  @media (max-width: 42rem) { table.tests td { display: block; width: auto !important; border-top: 0; padding: .2rem .6rem; } table.tests tr { display: block; border-top: 1px solid var(--line); padding: .3rem 0; } table.tests tr.group { padding: 0; } table.tests td.try { white-space: normal; } }
  a.path { display: inline-block; margin: .3rem 0 0 .6rem; font-size: .85rem; }
  button.peek { margin-top: .3rem; padding: .15rem .55rem; font-size: .82rem; background: transparent; color: var(--accent); border: 1px solid var(--line); }
  pre.answer { font-size: 13px; white-space: pre-wrap; word-break: break-all; }
  details { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: .7rem 1.1rem; margin-bottom: .6rem; }
  summary { cursor: pointer; font-weight: 600; }
  .diagram { overflow-x: auto; margin: .6rem 0; } .diagram svg { min-width: 36rem; max-width: 100%; height: auto; }
  ol.happened { padding-left: 1.3rem; margin: .3rem 0; } ol.happened li { margin: .35rem 0; }
</style>
</head>
<body>
<header class="bar"><div>
  <a class="name" href="<?= $e($url('/')) ?>">request-shield demo</a>
  <a class="quiet" href="<?= $e($url('/reset')) ?>">Reset my pass</a>
  <a class="button" href="<?= $e($url('/rules')) ?>">Active rules →</a>
</div></header>
<main>
  <h1><?= $e($title) ?></h1>
  <p class="lead">This page is protected by <strong>cjw-network/request-shield</strong>: every request is checked before this page's code runs. Click an example — or see <a href="<?= $e($url('/rules')) ?>">all active rules in plain words</a>, and try any address there.</p>

  <?php if ($content !== null): ?><p class="card"><?= $e($content[1]) ?></p><?php endif ?>
  <?php if ($path === '/comment' && $method !== 'POST'): ?>
  <section class="card"><form method="post" action="<?= $e($url('/comment')) ?>"><input type="text" name="comment" placeholder="Your comment"><button type="submit">Send comment</button></form>
  <p class="note">Try it after <a href="<?= $e($url('/reset')) ?>">Reset my pass</a>: the check comes, and your comment arrives anyway.</p></section>
  <?php elseif ($path === '/contact' && $method !== 'POST'): ?>
  <section class="card"><form method="post" action="<?= $e($url('/contact')) ?>" enctype="multipart/form-data">
    <input type="text" name="message" placeholder="Your message" aria-label="Your message">
    <input type="file" name="attachment" aria-label="A file">
    <?= $shield !== null ? $shield->widget(($_GET['start'] ?? '') === 'load' ? 'load' : 'input') : '' ?>
    <button type="submit">Send</button></form>
  <p class="note">The box is <code>&lt;?= Shield::active()?-&gt;widget() ?&gt;</code> — a placeholder and a script from the shield (<code>set widget-path …</code>). It starts on your first input — or at once: <a href="<?= $e($url('/contact?start=load')) ?>">check on loading</a>.</p></section>
  <?php elseif ($path === '/profile' && $method !== 'POST'): ?>
  <section class="card"><form method="post" action="<?= $e($url('/profile')) ?>"><input type="text" name="name" placeholder="Your name"><button type="submit">Save</button></form></section>
  <?php endif ?>
  <?php if ($path === '/challenge'): ?>
  <section class="card"><h3>What just happened</h3><ol class="happened">
    <li>You opened <code>/challenge</code>, where every visitor is checked (<code>[DEMO-LOGIN] challenge **/challenge</code>). Without a pass, the shield sent — instead of this page — a small page with a task, signed so it cannot be forged: <em>find the number n for which sha256(code + n) gives this result</em>.</li>
    <li>That page's script tried numbers until it found n — on average some tens of thousands of attempts, a fraction of a second — put the answer in a cookie and loaded the page again.</li>
    <li>The shield checked the answer (one calculation, well under a millisecond; every answer counts only once) and handed out the pass cookie <code>rsp</code>.</li>
    <li>With the pass you get through straight away<?= $passLeft !== null ? ' — for ' . (int) $passLeft . ' more seconds here (<code>set pass-ttl 1m</code>; a real site: an hour)' : '' ?>. After that, or after <a href="<?= $e($url('/reset')) ?>">Reset my pass</a>, the check comes again.</li>
  </ol>
  <div class="diagram"><?= Diagram::browserCheck() ?></div>
  <p class="note"><strong>What it brings:</strong> a scraper or bot that runs no JavaScript never gets past step 1 — the site renders nothing for it. One with a real browser engine pays computing time for every pass. Search engines are recognised and never checked. Nothing goes to a third party. More: <code>docs/explained/browser-check.md</code>.</p>
  </section>
  <?php endif ?>

  <?php $state = $decision === null ? 'note' : ($decision->action === 'allow' ? 'pass' : 'note'); ?>
  <div class="verdict <?= $state ?>"><span class="icon"><?= $state === 'pass' ? '✓' : '!' ?></span><div>
    <strong>This request: the visitor <?= $e($decision !== null ? Describe::verdict($decision) : 'was not checked') ?>.</strong><br>
    <span class="note">Decision <code><?= $e($status['Decision']) ?></code><?= $rule !== null ? ' · rule <code>' . $e($rule) . '</code>' : '' ?></span><br>
    <span class="note">You asked for</span> <code><?= $e($fullUrl) ?></code>
  </div></div>
  <div class="facts">
    <div><span>Your address</span><?= $e($request->clientIp) ?></div>
    <div><span>Counted as</span><?= $e(IpAddress::bucket($request->clientIp)) ?></div>
    <div><span>Browser check passed</span><b class="<?= $passLeft !== null ? 'yes' : 'no' ?>"><?= $passLeft !== null ? 'yes' : 'no' ?></b><?= $passLeft !== null ? ' — ' . $passLeft . ' s left' : '' ?></div>
    <div><span>May a cache keep this page?</span><b class="<?= $status['May a cache keep this page?'] ?>"><?= $e($status['May a cache keep this page?']) ?></b></div>
  </div>

  <details class="card"><summary>How it works — in one picture</summary><div class="diagram"><?= Diagram::overview() ?></div></details>

  <h2>Try it</h2>
  <p class="note">One test per row, numbered so we can talk about them ("test 3.2"); each number is a link to its row. <em>Show the answer</em> fetches it here, <em>See the path</em> checks it step by step on the rules page.</p>
  <table class="tests">
    <?php $g = 0; foreach ($groups as $heading => $items): $g++; $n = 0; ?>
      <tbody>
        <tr class="group"><th colspan="4"><?= $g ?> · <?= $e($heading) ?></th></tr>
        <?php foreach ($items as $item): $n++; [$local, $what, $expect] = $item; $how = $item[3] ?? 'GET'; $id = "t$g-$n"; ?>
          <tr id="<?= $id ?>">
            <td class="no"><a href="#<?= $id ?>"><?= $g ?>.<?= $n ?></a></td>
            <td class="what">
              <?php if ($local === null): ?><strong><?= $e($what) ?></strong>
              <?php elseif ($how === 'POST'): ?><form method="post" action="<?= $e($url($local)) ?>" class="inline"><input type="hidden" name="message" value="test <?= $g ?>.<?= $n ?>"><button type="submit" class="linkish"><?= $e($what) ?></button></form>
              <?php else: ?><a href="<?= $e($url($local)) ?>"><?= $e($what) ?></a>
              <?php endif ?>
              <?php if ($local !== null): ?><br><code><?= $how === 'POST' ? 'POST ' : '' ?><?= $e($local) ?></code><?php endif ?>
            </td>
            <td class="expect"><?= $e($expect) ?></td>
            <td class="try">
              <?php if ($local !== null && $how === 'GET'): ?><button type="button" class="peek" data-url="<?= $e($url($local)) ?>">Show the answer</button><?php endif ?>
              <?php if ($local !== null): ?><a class="path" href="<?= $e($pathOf($local, $how, $request->clientIp)) ?>">See the path →</a><?php endif ?>
            </td>
          </tr>
          <tr class="answer-row" hidden><td></td><td colspan="3"><pre class="answer"></pre></td></tr>
        <?php endforeach ?>
      </tbody>
    <?php endforeach ?>
  </table>

  <h2>Behind the scenes</h2>
  <details open><summary>The shield's log — what it stopped or flagged, newest first</summary>
    <p class="note">Each line with the full URL; addresses anonymised to their network (<code>/24</code>, <code>/48</code>) unless <code>set log-ip full</code>. Counted per rule on the <a href="<?= $e($url('/rules')) ?>">active rules page</a>.</p>
    <pre><?php if ($logLines === []): ?>(nothing yet — try /.env or /admin/)<?php endif ?><?php foreach ($logLines as $line): ?>
<?= $e($line) . "\n" ?>
<?php endforeach ?></pre>
  </details>
  <details><summary>This request, as it arrived</summary>
    <p class="note">What your browser sent. <span class="no">Struck out</span>: removed by the shield before the page ran (<code>X-Forwarded-*</code> is believed only from a trusted proxy).</p>
    <pre><?= $e($requestLine) . "\n" ?>
<?php foreach ($requestLines as [$name, $value, $removed]): ?>
<?= $removed ? '<del class="no">' : '' ?><?= $e($name) ?>: <?= $e($value) ?><?= $removed ? '</del>' : '' ?>

<?php endforeach ?></pre>
  </details>
  <details><summary>The answer's headers</summary>
    <p class="note"><code>X-RS</code> is the shield's decision, with the rule behind it (the web server adds <code>Date</code>, <code>Server</code> and the like).</p>
    <pre><?php foreach ($responseLines as [$name, $value]): ?>
<?= $e($name) ?>: <?= $e($value) ?>

<?php endforeach ?></pre>
  </details>
  <details><summary>How this page includes the shield</summary>
    <pre>define('REQUEST_SHIELD_CONFIG', __DIR__ . '/request-shield.rules');
require __DIR__ . '/../../bootstrap.php';

$decision = CjwNetwork\RequestShield\Shield::current();   // what the shield decided</pre>
    <p class="note">The rules: <code>examples/demo/request-shield.rules</code> — one per line; every decision names the line behind it.</p>
  </details>
</main>
<script>
// "Show the answer": fetches the example in the background and shows status and
// headers -- also for answers the page itself never sees (404, 400, 429).
// Browsers hide Set-Cookie from scripts, and a redirect's details too.
document.querySelectorAll('button.peek').forEach(function (b) {
  b.addEventListener('click', function () {
    var row = b.parentNode.parentNode.nextElementSibling, out = row.querySelector('pre');
    row.hidden = false;
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
