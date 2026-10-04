<?php
/**
 * The demo's pages (index.php includes this after the shield let the request
 * through): what the site shows for each path, and the front page -- its rows
 * from the rules' "# demo:" groups (Report\DemoSite, Report\ExamplesPage).
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

use CjwNetwork\RequestShield\IpAddress;
use CjwNetwork\RequestShield\Report\Describe;
use CjwNetwork\RequestShield\Report\Diagram;
use CjwNetwork\RequestShield\Report\RulesPage;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Report\DemoSite;
use CjwNetwork\RequestShield\Report\ExamplesPage;


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
    $stats = \CjwNetwork\RequestShield\Stats\Report\StatsPage::links($shield->settings);
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

// "Show the answer": a row decided on the server, with the live rules and
// store -- nothing counted (DemoSite::answer()). JSON for the page's script.
if ($path === '/__answer' && $shield !== null) {
    $row = DemoSite::row(DemoSite::groups(__DIR__ . '/request-shield.rules'), (string) ($_GET['n'] ?? ''));
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($row === null || $row['kind'] !== 'expect') {
        http_response_code(404);
        echo json_encode(['error' => 'no such row']), "\n";
        exit;
    }
    // A row of a site block is decided with that website's rules.
    $rowSettings = $row['site'] !== null ? \CjwNetwork\RequestShield\Settings::from(\CjwNetwork\RequestShield\Rules\RuleFile::read([__DIR__ . '/request-shield.rules'], $row['site'])['config']) : $shield->settings;
    echo json_encode(DemoSite::answer($rowSettings, $shield->store(), $row, $front, $request->host) + ['expected' => ExamplesPage::expected($row)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
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

// The rows of the front page: the rules' "# demo:" groups (0031 F.4) -- one per
// feature, its expect lines the rows, decided by request-shield test as well.
$groups = DemoSite::groups(__DIR__ . '/request-shield.rules');

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
  <p class="note">One example per row, grouped by feature and numbered so we can talk about them ("3.2"); each number is a link to its row. The rows come from the rules themselves (<code># demo:</code> groups in <code>request-shield.rules</code>) — <code>request-shield test</code> decides the same lines. <em>Show the answer</em> decides it on the server, nothing counted; <em>See the path</em> checks it step by step on the rules page.</p>
  <?= ExamplesPage::render($groups, $url, static fn (string $local, string $how): string => $pathOf($local, $how, $request->clientIp), $url('/__answer')) ?>

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
// "Show the answer": the row decided on the server (/__answer) with the live rules
// and store -- nothing counted: the request as the browser sends it, the status and
// headers the visitor gets, the cookies the shield sets or needs, the verdict and the steps. The address is the example's, not necessarily yours.
document.querySelectorAll('button.peek').forEach(function (b) {
  b.addEventListener('click', function () {
    var row = b.parentNode.parentNode.nextElementSibling, out = row.querySelector('pre');
    row.hidden = false;
    out.textContent = '…';
    fetch(b.getAttribute('data-answer'), { cache: 'no-store', credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (a) {
      if (a.error) { out.textContent = a.error; return; }
      // The request as the browser sends it, the answer the shield gives, what it does with
      // cookies, and why -- in that order.
      var text = '── Request (from ' + a.from + ')\n' + (a.request || []).join('\n') + '\n\n'
        + '── Answer\nHTTP/1.1 ' + a.status + (a.statusText ? ' ' + a.statusText : '') + '\n' + a.headers.join('\n') + '\n\n'
        + '── Cookies\n' + (a.cookies || []).join('\n') + '\n\n'
        + '── Why: ' + a.verdict + (a.rule ? ' (' + a.rule + ')' : '') + '\n' + a.steps.join('\n');
      out.textContent = text;
    }, function (err) { out.textContent = 'failed: ' + err; });
  });
});
</script>
</body>
</html>
