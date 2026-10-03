<?php
/**
 * A pretend Exponential site behind the proposed rules, to click through:
 * the addresses an Exponential site gets -- by alias, system URLs, admin
 * modules, internal files, view parameters, the search with its time filter,
 * forms, the admin as /admin -- and what the shield answers to each.
 *
 *   php -S 127.0.0.1:8095 examples/exponential/router.php
 *
 * and open http://127.0.0.1:8095/. Without the router, under a web server:
 * .../examples/exponential/index.php/ (the links keep that prefix).
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

// Where the demo lives: at the root (router.php), or in a subdirectory of a
// web server -- with rewrite rules (.../exponential/content/view/full/2) or
// without them (.../exponential/index.php/content/view/full/2). $front is the
// demo's own address, $path the address as the Exponential site would have it.
// Every link is relative to $front (<base href>), so it works wherever it lies.
$uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
$front = '';
if (substr($script, -10) === '/index.php') {
    $front = strncmp($uri, $script, strlen($script)) === 0 ? $script : substr($script, 0, -10);
}
$path = '/' . ltrim((string) substr($uri, strlen($front)), '/');
$url = static fn (string $local): string => $local === '/' ? './' : ltrim($local, '/');

// Demo only: the rules are written for a site at the root (/content/view/…,
// /admin/…, /settings/…). In a subdirectory the shield is handed the address
// the site itself would see. At the root this changes nothing.
$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
$_SERVER['REQUEST_URI'] = $path . ($query !== '' ? '?' . $query : '');
if (function_exists('putenv')) {                // a tight shared host may disable it: the rules' default then
    putenv('EXP_DEMO_HOME=' . $front . '/');
}

// ── The integration, as in config.php ───────────────────────────────────────
define('REQUEST_SHIELD_CONFIG', __DIR__ . '/demo.rules');
require __DIR__ . '/../../bootstrap.php';       // with Composer: vendor/autoload.php + Shield::protectFile(...)

use CjwNetwork\RequestShield\Shield;

// ─────────────────────────────────────────────────────────────────────────────
// Everything below runs only for requests the shield let through.

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$decision = Shield::current();
$rule = Shield::currentRule();
$verdict = $decision === null ? 'not checked' : $decision->action . ($rule !== null ? ' · rule ' . $rule : '');

if ($path === '/reset') {                       // forget the pass, to see the check again
    setcookie('rs_pass', '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    header('Location: ' . $front . '/', true, 303);
    exit;
}

/** The request as it arrived (after the shield: what it removed is gone) and the answer's headers so far. */
$headers = static function () use ($e, $method): string {
    $in = '';
    foreach ($_SERVER as $k => $v) {
        if (strncmp((string) $k, 'HTTP_', 5) === 0 && is_string($v)) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string) $k, 5)))));
            $in .= $e($name) . ': ' . $e($name === 'Cookie' ? (string) preg_replace('/=([^;]{12})[^;]*/', '=$1…', $v) : $v) . "\n";
        }
    }
    foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $k => $name) {
        if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') {
            $in .= $name . ': ' . $e((string) $_SERVER[$k]) . "\n";
        }
    }
    $out = 'HTTP ' . (int) http_response_code() . "\n";
    foreach (headers_list() as $h) {
        $out .= $e($h) . "\n";
    }
    return '<div class="hdrs"><details open><summary>Request headers</summary><pre>' . $e($method . ' ' . (string) ($_SERVER['REQUEST_URI'] ?? '')) . "\n" . $in . '</pre></details>'
        . '<details open><summary>Response headers</summary><pre>' . $out . '</pre></details></div>';
};

/** One page of the pretend site. */
$page = static function (string $title, string $body) use ($e, $url, $path, $method, $verdict, $front, $headers): void {
    header('Content-Type: text/html; charset=utf-8');
    $admin = strncmp($path, '/admin', 6) === 0;
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">',
        '<base href="', $e($front . '/'), '"><title>', $e($title), ' · Exponential demo</title><style>', CSS, '</style></head><body', $admin ? ' class="admin"' : '', '>',
        '<header><a class="brand" href="', $e($url('/')), '">', $admin ? 'Exponential admin (pretend)' : 'Exponential demo site (pretend)', '</a>',
        '<nav><a href="', $e($url('/')), '">Tests</a><a href="', $e($url('/news/2026/fit-and-healthy')), '">An article</a>',
        '<a href="', $e($url('/content/search?SearchText=yoga&SearchDate=3')), '">Search</a><a href="', $e($url('/kontakt')), '">Contact</a>',
        '<a href="', $e($url('/user/login')), '">Login</a><a href="', $e($url('/admin/dashboard')), '">Admin</a><a href="', $e($url('/reset')), '">Forget my pass</a></nav></header>',
        '<main><p class="shown">', $e($method . ' ' . $front . $path), ' — <b>the shield let it through:</b> ', $e($verdict), '</p>',
        '<h1>', $e($title), '</h1>', $body, $headers(), '</main></body></html>';
};

const CSS = ':root{--bg:#f6f7f9;--card:#fff;--ink:#1d2127;--muted:#5b6470;--line:#d9dde3;--accent:#2f62c9;--ok:#1e7b43;--no:#a3361f;--warn:#8a5a00}'
    . '@media (prefers-color-scheme:dark){:root{--bg:#14171b;--card:#1d2127;--ink:#e7e9ec;--muted:#a0a8b3;--line:#3a414b;--accent:#7aa2ff;--ok:#4cc38a;--no:#ff7a66;--warn:#e0a43c}}'
    . '*{box-sizing:border-box}body{margin:0;font:15px/1.45 system-ui,sans-serif;background:var(--bg);color:var(--ink)}'
    . 'header{display:flex;flex-wrap:wrap;gap:8px 20px;align-items:center;padding:12px 16px;background:var(--card);border-bottom:1px solid var(--line)}'
    . 'body.admin header{border-bottom:3px solid var(--warn)}.brand{font-weight:700;color:var(--ink);text-decoration:none}'
    . 'nav{display:flex;flex-wrap:wrap;gap:4px 14px}a{color:var(--accent)}main{max-width:1180px;margin:0 auto;padding:16px}'
    . '.shown{font:13px ui-monospace,monospace;color:var(--muted);overflow-wrap:anywhere}'
    . 'table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--line);border-radius:8px;margin:8px 0 22px}'
    . 'th,td{text-align:left;padding:7px 9px;border-top:1px solid var(--line);vertical-align:top}th{font-size:13px;color:var(--muted);border-top:0}'
    . 'td.n{color:var(--muted);white-space:nowrap}td.u{font:13px ui-monospace,monospace;overflow-wrap:anywhere;max-width:380px}'
    . '.exp{white-space:nowrap}.c404,.c403,.c405,.c429{color:var(--no)}.c200{color:var(--ok)}.check{color:var(--warn)}'
    . 'button{font:inherit;font-size:13px;padding:3px 9px;border:1px solid var(--line);border-radius:6px;background:var(--bg);color:var(--ink);cursor:pointer}'
    . '.ans{display:block;font:12px ui-monospace,monospace;color:var(--muted);margin-top:4px;overflow-wrap:anywhere}'
    . 'form.box{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:14px;max-width:520px}'
    . 'label{display:block;margin:8px 0 3px}input,select,textarea{font:inherit;width:100%;padding:6px;border:1px solid var(--line);border-radius:6px;background:var(--bg);color:var(--ink)}'
    . '.hdrs{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px;margin-top:24px}'
    . 'details{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:8px 12px}summary{cursor:pointer;font-weight:600}'
    . 'pre{font:12px/1.5 ui-monospace,monospace;white-space:pre-wrap;overflow-wrap:anywhere;margin:8px 0 0}'
    . '.ans details{margin-top:4px;padding:4px 8px}.ans summary{font-weight:400}'
    . '.note{color:var(--muted);font-size:14px}@media (max-width:720px){td.why,th.why{display:none}}';

// "Show the answer": fetches the address in the background, shows the status and the decision.
const JS = <<<'JS'
document.querySelectorAll('button[data-u]').forEach(function (b) {
  b.addEventListener('click', async function () {
    var out = b.nextElementSibling, m = b.dataset.m, n = m === 'SEARCH11' ? 11 : 1, r = null;
    var post = m === 'POST', url = new URL(b.dataset.u, document.baseURI);
    out.textContent = '…';
    for (var i = 0; i < n; i++) {
      r = await fetch(url, post
        ? {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'demo=1', credentials: 'same-origin'}
        : {credentials: 'same-origin'});
    }
    // What was sent (the browser adds its own: User-Agent, Accept, cookies) and every header of the answer.
    var sent = (post ? 'POST ' : 'GET ') + url.pathname + url.search + '\n' + (post ? 'Content-Type: application/x-www-form-urlencoded\n\ndemo=1' : '(the browser\'s own headers and cookies)');
    var got = 'HTTP ' + r.status + '\n';
    r.headers.forEach(function (v, k) { got += k + ': ' + v + '\n'; });
    out.textContent = r.status + ' · ' + (r.headers.get('X-RS') || '(no decision header)');
    [['Request', sent], ['Response headers', got]].forEach(function (p) {
      var d = document.createElement('details'), s = document.createElement('summary'), pre = document.createElement('pre');
      s.textContent = p[0]; pre.textContent = p[1]; d.append(s, pre); out.append(d);
    });
  });
});
JS;

// ── The pretend site's pages ────────────────────────────────────────────────

if ($path === '/' && $method === 'GET') {
    // [number, method, address, expected, rule, why]
    $tests = [
        'Pages by their alias' => [
            ['GET', '/', '200', '', 'the front page'],
            ['GET', '/news/2026/fit-and-healthy', '200', '', 'an article by its URL alias'],
            ['GET', '/news/(offset)/20', '200', '', 'a list, page 3: the view parameter is a number'],
            ['GET', '/blog/(year)/2026/(month)/9', '200', '', 'an archive by year and month'],
            ['GET', '/shop/package', '200', '', 'an alias that happens to name an admin module stays untouched (EXP-MODULES is anchored)'],
        ],
        'System URLs: pages by node number' => [
            ['GET', '/content/view/full/2', '404', 'EXP-SYSVIEW', 'the same page under a number: what scrapers count through'],
            ['GET', '/Content/View/Full/2', '404', 'EXP-SYSVIEW', 'in any case'],
            ['GET', '/ger/content/view/full/89', '404', 'EXP-SYSVIEW', 'behind a URI siteaccess'],
            ['GET', '/layout/set/print/content/view/full/89', '404', 'EXP-SYSVIEW', 'behind layout/set'],
            ['GET', '/content/view/sitemap/2', '200', 'EXP-SYSVIEW-OK', 'the sitemap the page header links to'],
        ],
        'Admin modules, internal files, downloads, view parameters' => [
            ['GET', '/class/grouplist', '404', 'EXP-MODULES', 'an admin module in the frontend'],
            ['GET', '/eng/setup/info', '404', 'EXP-MODULES', 'the setup module, behind a URI siteaccess'],
            ['GET', '/settings/site.ini', '404', 'EXP-INTERNAL', 'Exponential\'s configuration'],
            ['GET', '/var/site/cache/expiry.php', '404', 'EXP-INTERNAL', 'a cache file'],
            ['GET', '/extension/shop/settings/shop.ini.append.php', '404', 'EXP-INI', 'an extension\'s configuration'],
            ['GET', '/content/download/12/34/file/report.zip', '200', 'EXP-DOWNLOAD', 'an uploaded archive: open (the built-in SCAN-BACKUP would refuse .zip)'],
            ['GET', '/backup.zip', '404', 'SCAN-BACKUP', 'an archive anywhere else: a scanner\'s guess'],
            ['GET', "/news/(offset)/1'or1", '404', 'EXP-VIEWPARAMS', 'a view parameter that is no number'],
            ['GET', '/news/(offset)/99999999', '404', 'EXP-VIEWPARAMS', 'counting through a list'],
        ],
        'Search, with its time filter' => [
            ['GET', '/content/search?SearchText=yoga&SearchDate=3', '200', '', 'the last month; answered, never cached; counted (EXP-SEARCHES: 10 a minute)'],
            ['GET', '/content/advancedsearch?SearchText=yoga&SubTreeArray[]=2&SearchDate=-1', '200', '', 'the advanced search, all dates'],
            ['GET', '/content/search?SearchText=yoga&SearchDate=9', '404', 'EXP-STRICT', 'a time filter the search does not know'],
            ['GET', "/content/search?SearchText=x' union select 1--", '403', 'ATK-SQL-UNION', 'an attack in the search text'],
            ['GET', '/news?debug=1', '404', 'EXP-STRICT', 'a parameter the site does not take'],
            ['GET', '/news?utm_source=newsletter', '200', '', 'a newsletter link: a marketing tag is known (@tracking)'],
            ['SEARCH11', '/content/search?SearchText=yoga', 'check', 'EXP-SEARCHES', 'eleven searches in a row: the eleventh gets the browser check'],
        ],
        'Forms' => [
            ['POST', '/news/2026/fit-and-healthy', '405', 'EXP-POST', 'a POST to a page: no form lives there'],
            ['GET', '/user/login', 'check', 'EXP-LOGIN', 'the login: the browser check first, then the form'],
            ['POST', '/content/action', 'check', 'EXP-FORMS', 'a contact form sent without a pass: the check page, then sent again'],
            ['POST', '/ezjscore/call/ezstarrating::rate', '200', '', 'an AJAX call (ezjscore): allowed'],
        ],
        'The admin as /admin' => [
            ['GET', '/admin/dashboard', 'check', 'EXP-ADMIN-CHECK', 'every editor checked once per pass'],
            ['GET', '/admin/content/view/full/2', 'check', 'EXP-ADMIN-CHECK', 'the admin may use system URLs'],
            ['GET', '/admin/class/grouplist', 'check', 'EXP-ADMIN-CHECK', 'and its modules'],
            ['POST', '/admin/content/edit/87/1/ger-DE', 'check', 'EXP-ADMIN-CHECK', 'saving anywhere in the admin'],
            ['GET', '/admin/content/edit/87?x=<script>alert(1)</script>', '403', 'ATK-XSS-TAG', 'an attack in the admin\'s query'],
        ],
    ];
    $rows = '';
    $g = 0;
    foreach ($tests as $group => $list) {
        $g++;
        $rows .= '<h2 id="t' . $g . '">' . $g . ' · ' . $e($group) . '</h2><table><tr><th>#</th><th>Request</th><th>Expected</th><th class="why">Why</th><th></th></tr>';
        foreach ($list as $i => [$m, $local, $expected, $rid, $why]) {
            $id = 't' . $g . '-' . ($i + 1);
            $href = $url($local);
            $link = $m === 'GET' ? '<a href="' . $e($href) . '">' . $e($local) . '</a>' : $e($local);
            $label = $m === 'SEARCH11' ? '11 × GET' : $m;
            $exp = $expected === 'check' ? '<span class="check">the check</span>' : '<span class="c' . $e($expected) . '">' . $e($expected) . '</span>';
            $rows .= '<tr id="' . $id . '"><td class="n"><a href="#' . $id . '">' . $g . '.' . ($i + 1) . '</a></td><td class="u">' . $e($label) . ' ' . $link . '</td>'
                . '<td class="exp">' . $exp . ($rid !== '' ? '<br><small>' . $e($rid) . '</small>' : '') . '</td><td class="why">' . $e($why) . '</td>'
                . '<td><button data-m="' . $e($m) . '" data-u="' . $e($href) . '">Show the answer</button><span class="ans"></span></td></tr>';
        }
        $rows .= '</table>';
    }
    $page('What the shield answers an Exponential site', '<p class="note">The rules of <code>examples/exponential/</code> (the admin as the siteaccess <code>/admin</code>), switched on.'
        . ' A click opens the address as a visitor would; <b>Show the answer</b> fetches it in the background and shows the status and the header <code>X-RS</code>'
        . ' (the decision and the rule). Once a check is solved (the login, the admin), your browser holds a pass and the rows marked "the check" pass:'
        . ' <a href="' . $e($url('/reset')) . '">forget the pass</a> to see the check again. Why each rule is there: <code>docs/use-cases/exponential.md</code>.</p>'
        . $rows . '<script>' . JS . '</script>');
    exit;
}


if (preg_match('#^/content/(advanced)?search$#i', $path)) {
    $text = is_string($_GET['SearchText'] ?? null) ? (string) $_GET['SearchText'] : '';
    $date = is_string($_GET['SearchDate'] ?? null) ? (string) $_GET['SearchDate'] : '-1';
    $options = '';
    foreach (['-1' => 'any time', '1' => 'the last day', '2' => 'the last week', '3' => 'the last month', '4' => 'the last three months', '5' => 'the last year'] as $v => $l) {
        $options .= '<option value="' . $v . '"' . ((string) $v === $date ? ' selected' : '') . '>' . $l . '</option>';
    }
    $page('Search', '<form class="box" method="get" action="' . $e($url('/content/search')) . '"><label>Search for</label><input name="SearchText" value="' . $e($text) . '">'
        . '<label>Published</label><select name="SearchDate">' . $options . '</select><p><button>Search</button></p></form>'
        . ($text !== '' ? '<p>3 pretend results for <b>' . $e($text) . '</b>. ' . 'This search was counted (EXP-SEARCHES: 10 a minute, then the check).' . '</p>' : '')
        . '<p class="note">The time filter is <code>SearchDate</code>: -1 to 5, nothing else (EXP-SEARCH-Q). Try <a href="' . $e($url('/content/search?SearchText=yoga&SearchDate=9')) . '">SearchDate=9</a>.</p>');
    exit;
}

if ($path === '/user/login' || $path === '/admin/user/login') {
    $page('Login', ($method === 'POST' ? '<p class="c403">Wrong user name or password (a pretend login).</p>' : '<p class="note">You got here after the browser check (EXP-LOGIN): a script trying passwords has to solve it again and again.</p>')
        . '<form class="box" method="post" action="' . $e($url($path)) . '"><label>User name</label><input name="Login"><label>Password</label><input name="Password" type="password"><p><button>Log in</button></p></form>');
    exit;
}

if ($path === '/kontakt') {
    $page('Contact', '<p class="note">An information collection form, as Exponential renders it: it posts to <code>/content/action</code>. Sent without a pass, the shield shows its check page, then sends the form again by itself (EXP-FORMS).</p>'
        . '<form class="box" method="post" action="' . $e($url('/content/action')) . '"><label>Your message</label><textarea name="ContentObjectAttribute_data_text_1" rows="4"></textarea>'
        . '<input type="hidden" name="ContentNodeID" value="89"><p><button name="ActionCollectInformation" value="1">Send</button></p></form>');
    exit;
}

if ($path === '/content/action' && $method === 'POST') {
    $page('Thank you', '<p>Your message was received (pretend: nothing is stored). It arrived with the form sent again after the check, nothing typed lost.</p>');
    exit;
}

if (strncmp($path, '/content/download/', 18) === 0) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "A pretend download: $path\n";
    exit;
}

if (strncmp($path, '/ezjscore/', 10) === 0 || strncmp($path, '/api/', 5) === 0) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'path' => $path]);
    exit;
}

if (strncmp($path, '/admin', 6) === 0) {
    $page('Admin: ' . $path, '<p class="note">The admin interface (pretend). You are here after the browser check (EXP-ADMIN-CHECK); the admin may use system URLs and its modules.</p><ul>'
        . '<li><a href="' . $e($url('/admin/content/view/full/2')) . '">/admin/content/view/full/2</a></li>'
        . '<li><a href="' . $e($url('/admin/class/grouplist')) . '">/admin/class/grouplist</a></li>'
        . '<li><a href="' . $e($url('/admin/setup/info')) . '">/admin/setup/info</a></li></ul>'
        . '<form class="box" method="post" action="' . $e($url('/admin/content/edit/87/1/ger-DE')) . '"><label>Title</label><input name="ContentObjectAttribute_ezstring_data_text_1" value="Fit &amp; Healthy"><p><button name="PublishButton" value="1">Send for publishing</button></p></form>');
    exit;
}

if (strncmp($path, '/content/view/sitemap/', 22) === 0) {
    $page('Sitemap', '<p>The site map, by its system URL: the one system URL the frontend keeps (EXP-SYSVIEW-OK).</p>');
    exit;
}

$page('A page: ' . $path, '<p>Exponential would look up the URL alias <code>' . $e($path) . '</code> and render its node here.</p>');
