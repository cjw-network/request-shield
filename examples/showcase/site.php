<?php
/**
 * The showcase's pages, after the shield let the request through: the front
 * page (page.php), what a try asks for (/admin/, /login, /contact …), and
 * /__try -- the one try a browser cannot send itself (a form from another
 * website: no browser lets a page forge Origin), decided here with the live
 * rules, nothing counted.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

use CjwNetwork\RequestShield\Inspector;
use CjwNetwork\RequestShield\Shield;

/**
 * The tries: the "expect" lines of the rules, with the rule or include they
 * stand below and their comment -- the page shows what the rules decide.
 *
 * @return list<array{n: int, method: string, url: string, headers: array<string, string>, from: string, pass: bool, times: int, outcome: string, by: ?string, text: string, section: string}>
 */
function showcaseTries(string $file): array
{
    $tries = [];
    $section = 'built-in';
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*\[([A-Z0-9-]+)\]/', $line, $m) === 1) {
            $section = $m[1];
            continue;
        }
        if (preg_match('/^include\s+@(\w+)/', $line, $m) === 1) {
            $section = '@' . $m[1];
            continue;
        }
        if (strncmp(ltrim($line), 'expect ', 7) !== 0) {
            continue;
        }
        $try = showcaseExpect($line, count($tries), $section);
        $tries[] = $try;
    }
    return $tries;
}

/**
 * One "expect" line as the page shows it: method, address, headers, from, pass,
 * times, the outcome and rule, its comment.
 *
 * @return array{n: int, method: string, url: string, headers: array<string, string>, from: string, pass: bool, times: int, outcome: string, by: ?string, text: string, section: string}
 */
function showcaseExpect(string $line, int $n, string $section): array
{
    $line = ltrim($line);       // an example inside a match block is indented
    $hash = strpos($line, ' # ');
    $text = $hash !== false ? trim(substr($line, $hash + 3)) : '';
    $words = preg_split('/\s+/', trim(substr($hash !== false ? substr($line, 0, $hash) : $line, 7))) ?: [];
    $try = ['n' => $n, 'method' => (string) array_shift($words), 'url' => (string) array_shift($words), 'headers' => [], 'from' => \CjwNetwork\RequestShield\Rules\RuleFile::EXAMPLE_FROM,
        'pass' => false, 'times' => 1, 'outcome' => '', 'by' => null, 'text' => $text, 'section' => $section];
    while ($words !== []) {
        $w = (string) array_shift($words);
        if ($w === 'from') {
            $try['from'] = (string) array_shift($words);
        } elseif ($w === 'header') {
            [$name, $value] = explode(':', (string) array_shift($words), 2) + [1 => ''];
            $try['headers'][$name] = $value;
        } elseif ($w === 'times') {
            $try['times'] = (int) array_shift($words);
        } elseif ($w === 'with') {
            array_shift($words);
            $try['pass'] = true;
        } elseif ($w === 'by') {
            $try['by'] = (string) array_shift($words);
        } else {
            $try['outcome'] = $w;
        }
    }
    return $try;
}

/**
 * The Exponential example's rules and examples (examples/exponential), by the
 * sections of its files ("# 1. System URLs in the frontend"): the rule lines
 * and the examples written below them.
 *
 * @param string $dir examples/exponential
 * @return list<array{title: string, rules: list<string>, tries: list<array{n: int, method: string, url: string, headers: array<string, string>, from: string, pass: bool, times: int, outcome: string, by: ?string, rule: ?string, ua: ?string, site: ?string, text: string, section: string, at: string}>}>
 */
function exponentialGroups(string $dir): array
{
    // The examples as RuleFile reads them (quotes, ua, header names and all), by their place.
    $read = [];
    foreach (\CjwNetwork\RequestShield\Rules\RuleFile::read(["$dir/demo.rules"])['examples'] as $x) {
        $read[$x['at']] = $x;
    }
    $groups = [];
    $n = 1;                     // from 1: n=0 at /__exp means all of them
    foreach (['exponential.rules' => null, 'exponential-admin-uri.rules' => 'Admin (/admin)'] as $file => $title) {
        if ($title !== null) {
            $groups[] = ['title' => $title, 'rules' => [], 'tries' => []];
        }
        foreach (file("$dir/$file", FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
            if (preg_match('/^# \d+\. (.+)$/', $line, $m) === 1) {
                $groups[] = ['title' => $m[1], 'rules' => [], 'tries' => []];
                continue;
            }
            $g = count($groups) - 1;
            $t = ltrim($line);
            if ($g < 0 || $t === '' || $t[0] === '#') {
                continue;
            }
            if (strncmp($t, 'expect ', 7) === 0) {
                $x = $read["$file:" . ($i + 1)] ?? null;
                if ($x !== null) {
                    $hash = strpos($t, ' # ');
                    $groups[$g]['tries'][] = ['n' => $n++, 'method' => $x['method'], 'url' => $x['url'], 'headers' => $x['headers'], 'from' => $x['from'],
                        'pass' => $x['pass'], 'times' => $x['times'], 'outcome' => $x['outcome'], 'by' => $x['by'], 'rule' => $x['rule'], 'ua' => $x['ua'], 'site' => $x['site'],
                        'text' => $hash !== false ? trim(substr($t, $hash + 3)) : '', 'section' => (string) $groups[$g]['title'], 'at' => $x['at']];
                }
                continue;
            }
            $hash = strpos($line, ' # ');
            $groups[$g]['rules'][] = rtrim($hash !== false ? substr($line, 0, $hash) : $line);
        }
    }
    return array_values(array_filter($groups, static fn (array $g): bool => $g['tries'] !== []));
}

/**
 * The page's language: ?lang=de or ?lang=en when the address names one, else the one of
 * the two the browser ranks higher (Accept-Language, by its q weights and order) -- English
 * when it names neither.
 */
function showcaseLang(): string
{
    $asked = (string) ($_GET['lang'] ?? '');
    if ($asked === 'de' || $asked === 'en') {
        return $asked;
    }
    $best = ['lang' => 'en', 'q' => -1.0];
    foreach (explode(',', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
        $bits = explode(';', trim($part));
        $tag = strtolower(substr(trim($bits[0]), 0, 2));
        $q = 1.0;
        foreach (array_slice($bits, 1) as $param) {
            if (preg_match('/^\s*q\s*=\s*([01](?:\.\d{1,3})?)\s*$/', $param, $m) === 1) {
                $q = (float) $m[1];
            }
        }
        if (($tag === 'de' || $tag === 'en') && $q > 0 && $q > $best['q']) {     // q=0: "not this one"
            $best = ['lang' => $tag, 'q' => $q];        // the first of equal weight stays: the browser's order
        }
    }
    return $best['lang'];
}

/**
 * Who is banned right now: the store's bans (APCu or files; with ban-keep file also the files
 * that outlast a restart) -- the same list the dashboard's lists page shows. The address only
 * as its network (showcaseMask), whatever log-ip says; the rule and what kind of visitor it looks like from the log's
 * end, when it still has a line of that address -- the kind is a guess from the User-Agent.
 *
 * @param list<string> $lines
 * @return list<array{client: string, until: int, rule: ?string, kind: string, agent: string}>
 */
function showcaseBans(?Shield $shield, array $lines, int $now): array
{
    if ($shield === null) {
        return [];
    }
    $s = $shield->settings;
    $marks = $shield->store()->marks('ban:', $now);
    if ($s->banKeep === 'file' && !$shield->store() instanceof \CjwNetwork\RequestShield\Store\FileStore) {
        foreach ((new \CjwNetwork\RequestShield\Store\FileStore($s->storeDir))->marks('ban:', $now) as $key => $until) {
            $marks[$key] = max($until, $marks[$key] ?? 0);
        }
    }
    // masked address => its latest ban line (the first of a ban names the rule); a network's other
    // lines only when it has none -- another visitor of the same network is not the one banned
    $seen = [];
    foreach ($lines as $line) {
        $r = \CjwNetwork\RequestShield\LogStats::parse($line);
        if ($r === null) {
            continue;
        }
        // the network the store bans (its bucket: an IPv6 address's /64, or what ipv6-prefix says),
        // shown as showcaseMask shows a ban -- whether the line has the address (log-ip full) or its /24, /48
        $who = showcaseMask(\CjwNetwork\RequestShield\IpAddress::bucket(explode('/', $r['client'])[0], $s->ipv6Prefix));
        $was = $seen[$who] ?? null;
        $banned = $r['reason'] === 'banned';
        if ($banned || $was === null || !$was['banned']) {
            $seen[$who] = ['rule' => $banned && $r['rule'] !== null ? $r['rule'] : ($was['rule'] ?? null), 'claimed' => $r['claimed'], 'agent' => $r['agent'],
                'banned' => $banned || ($was['banned'] ?? false)];
        }
    }
    $bans = [];
    foreach ($marks as $key => $until) {
        $bucket = substr($key, 4);
        $client = showcaseMask($bucket);
        if ($until <= $now || (isset($bans[$client]) && $bans[$client]['until'] >= $until)) {
            continue;
        }
        $l = $seen[$client] ?? ['rule' => null, 'claimed' => null, 'agent' => null, 'banned' => false];
        $bans[$client] = ['client' => $client, 'until' => $until, 'rule' => $l['rule'],
            'kind' => $l['agent'] === null ? 'unknown' : showcaseVisitorKind($l['claimed'], $l['agent']), 'agent' => substr((string) $l['agent'], 0, 80)];
    }
    $out = array_values($bans);
    usort($out, static fn (array $a, array $b): int => $b['until'] <=> $a['until']);
    return $out;
}

/**
 * The addresses kept out by hand (deny): from the rule file, or from the list file the command
 * line and the dashboard write (LIST-…) -- for good, or until a time. The first ones only
 * (the settings keep DENY_SHOWN), each address as its network.
 *
 * @return list<array{clients: list<string>, until: ?int, rule: string, source: string}>
 */
function showcaseDenied(?Shield $shield): array
{
    $out = [];
    foreach ($shield !== null ? $shield->settings->deny : [] as $d) {
        $out[] = ['clients' => array_values(array_unique(array_map('showcaseMask', $d['ips']))), 'until' => $d['until'], 'rule' => $d['rule'],
            'source' => strncmp($d['rule'], 'LIST-', 5) === 0 ? 'list' : 'rules'];
    }
    return $out;
}

/**
 * An address as the demo may show it: its network, as log-ip masked writes it (198.51.100.7 ->
 * 198.51.100.0/24, IPv6 -> /48). A range that wide or wider keeps its width, its host bits
 * cleared (198.51.100.7/16 -> 198.51.100.0/16); a narrower one, or anything odd after the
 * slash, is cut to the network like an address. What is no address at all: "-".
 */
function showcaseMask(string $a): string
{
    $slash = strpos($a, '/');
    $base = $slash === false ? $a : substr($a, 0, $slash);
    $bin = @inet_pton($base);
    if ($bin === false) {
        return '-';
    }
    $bits = $slash === false ? '' : substr($a, $slash + 1);
    if (preg_match('/^\d{1,3}$/', $bits) === 1 && (int) $bits <= (strlen($bin) === 4 ? 24 : 48)) {
        $n = (int) $bits;
        $net = substr($bin, 0, intdiv($n, 8));
        if ($n % 8 !== 0) {
            $net .= chr(ord($bin[intdiv($n, 8)]) & (0xff << (8 - $n % 8)) & 0xff);
        }
        return inet_ntop(str_pad($net, strlen($bin), "\0")) . '/' . $n;
    }
    return \CjwNetwork\RequestShield\Log::mask($base);
}

/** What a User-Agent looks like: crawler (says so), tool (a script, a library), browser (likely a person), unknown. */
function showcaseVisitorKind(?string $claimed, string $agent): string
{
    if ($claimed !== null || preg_match('/bot\b|crawler|spider|slurp|bingpreview/i', $agent) === 1) {
        return 'crawler';
    }
    if ($agent === '' || $agent === '-' || preg_match('#^(curl|wget|python|go-http|java/|okhttp|libwww|php|node|axios|httpie|scrapy|ruby|perl)#i', $agent) === 1) {
        return 'tool';
    }
    return preg_match('#Mozilla/5\.0 .*(Firefox|Chrome|Safari|Edg)/#', $agent) === 1 ? 'browser' : 'unknown';
}

/** @param array<string, mixed> $data */
function showcaseJson(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** A small page for what a try asks for: the site answered, the shield let it through. */
function showcaseAnswer(int $status, string $title, string $text): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>', $e($title), '</title>',
        '<link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css"><link rel="stylesheet" href="/assets/showcase.css?v=' . (int) @filemtime(__DIR__ . '/assets/showcase.css') . '"></head>',
        '<body class="answer-page"><main class="container py-5"><p class="eyebrow">request-shield showcase</p><h1>', $e($title), '</h1><p class="lead">', $e($text),
        '</p><a class="btn btn-primary" href="/">Back to the showcase</a></main></body></html>';
}

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
/** The Exponential example: in lib/ in a standalone copy (build/showcase.php), the repository's otherwise. */
function showcaseExponential(): string
{
    return is_dir(__DIR__ . '/lib/exponential') ? __DIR__ . '/lib/exponential' : dirname(__DIR__) . '/exponential';
}

$tries = showcaseTries(__DIR__ . '/showcase.rules');

// The page on building rules (0016): a learning run started here, for this browser -- only from
// this machine (the showcase runs on yours), or when the showcase is told so (a demo server).
// The visitor as the shield sees it: behind a proxy the shield trusts, the forwarded address -- a
// local web server in front would otherwise make everyone "this machine".
$learnShield = Shield::active();
$learnClient = $learnShield !== null ? \CjwNetwork\RequestShield\Request::fromServer($_SERVER, $learnShield->settings->trustedProxies)->clientIp : '';
// A standalone copy for a public host (build/showcase.php) says who that is instead: lib/public.php,
// the --admin addresses -- there "this machine" may be the hoster's proxy in front of everyone.
$public = is_file(__DIR__ . '/lib/public.php') ? (array) require __DIR__ . '/lib/public.php' : null;
$learnHere = ($public === null ? in_array($learnClient, ['127.0.0.1', '::1'], true)
    : \CjwNetwork\RequestShield\IpAddress::inRanges($learnClient, array_values(array_map('strval', (array) ($public['admin'] ?? [])))))
    || getenv('REQUEST_SHIELD_SHOWCASE_LEARN') === 'on';
$learnStore = $learnShield !== null ? $learnShield->settings->storeDir : null;
if ($path === '/try' || $path === '/exponential') {
    $view = substr($path, 1);         // a page of its own: everything to try, the Exponential example
    require __DIR__ . '/page.php';
    return;
}
if ($path === '/learn') {
    require __DIR__ . '/learn.php';
    return;
}
// The tab "Cache": the page that shows the HTTP cache at work, and the magazine it asks for -- a
// small CMS that takes half a second for a page, as a real one does with its database and templates.
if ($path === '/cache') {
    require __DIR__ . '/cache.php';
    return;
}
if ($path === '/magazin' || preg_match('#^/magazin/([1-5])$#', $path, $article) === 1) {
    $n = isset($article[1]) ? (int) $article[1] : 0;
    // A signed-in reader: a member, or an editor ("editor-…") -- each role its own page.
    $session = is_string($_COOKIE['rs-demo-member'] ?? null) ? $_COOKIE['rs-demo-member'] : '';
    $role = $session === '' ? null : (strncmp($session, 'editor-', 7) === 0 ? 'editor' : 'member');
    if ($role !== null) {
        // The adapter's one call: this visitor has the role, and this page is the same for everyone with it.
        Shield::active()?->cacheContext($role, true);
    }
    // The magazine takes no parameter: only its plain pages take their time and may be kept -- a made-up
    // ?page=<n> or ?lang=<x> (names cache-query lets through) neither stalls the server nor fills the cache.
    $plain = $_GET === [];
    if ($plain) {
        usleep(random_int(350000, 650000));
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: ' . ($plain ? 'public, max-age=300' : 'no-store'));
    header('xkey: ' . ($n > 0 ? "article-$n magazin" : 'magazin-list magazin'));     // the tags a publish purges
    $title = $n > 0 ? "Artikel $n / Article $n" : 'Magazin / Magazine';
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title></head><body><h1>'
        . htmlspecialchars($title) . '</h1><p>Gebaut um / built at ' . gmdate('H:i:s') . ' UTC'
        . ($role === 'member' ? ' -- für Mitglieder / for members' : ($role === 'editor' ? ' -- für Redakteure, mit Bearbeiten-Links / for editors, with edit links' : '')) . '</p></body></html>';
    return;
}
if (($path === '/__cache/publish' || $path === '/__cache/clear') && $method === 'POST') {
    // A CMS after publishing: the shield's purge in the same process -- no PURGE request.
    $n = (int) ($_POST['n'] ?? 0);
    $tags = $path === '/__cache/clear' ? ['*'] : ($n >= 1 && $n <= 5 ? ["article-$n", 'magazin-list'] : []);
    Shield::active()?->purge($tags);
    showcaseJson(200, ['purged' => $tags]);
    return;
}
if ((strncmp($path, '/__learn/', 9) === 0 && $method === 'POST') || $path === '/__learned' || $path === '/__replay') {
    if (!$learnHere || $learnStore === null) {
        showcaseJson(403, ['error' => 'only on this machine']);        // a run, and what it recorded, is this machine's
        return;
    }
}
if (strncmp($path, '/__learn/', 9) === 0 && $method === 'POST') {
    if ($path === '/__learn/start') {
        $for = 600;
        if (!@touch(__DIR__ . '/showcase.rules')) {         // as learn start does: the settings read the run
            showcaseJson(500, ['error' => 'showcase.rules cannot be touched: the settings would not see the run']);
            return;
        }
        $token = \CjwNetwork\RequestShield\Learn::start($learnStore, $for, [], false, time());
        @touch(__DIR__ . '/showcase.rules');
        setcookie(\CjwNetwork\RequestShield\Learn::COOKIE, $token, ['expires' => time() + $for, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true]);
        showcaseJson(200, ['until' => time() + $for]);
        return;
    }
    if ($path === '/__learn/stop') {
        \CjwNetwork\RequestShield\Learn::stop($learnStore);
        @touch(__DIR__ . '/showcase.rules');
        setcookie(\CjwNetwork\RequestShield\Learn::COOKIE, '', ['expires' => 1, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true]);
        showcaseJson(200, ['until' => null]);
        return;
    }
}
if ($path === '/__learned') {
    // The recording's end, newest first: its lines are shapes (no values), shown as they are.
    $run = \CjwNetwork\RequestShield\Learn::read($learnStore);
    $lines = @file(\CjwNetwork\RequestShield\Learn::recordFile($learnStore), FILE_IGNORE_NEW_LINES) ?: [];
    $rows = [];
    foreach (array_reverse(array_slice($lines, -40)) as $line) {
        $r = json_decode($line, true);
        if (is_array($r)) {
            $rows[] = $r;
        }
    }
    showcaseJson(200, ['until' => $run !== null && $run['until'] > time() ? $run['until'] : null, 'count' => count($lines), 'rows' => $rows]);
    return;
}
if ($path === '/__replay') {
    // replay, as request-shield replay does: the recording through the showcase's rules, switched on.
    $file = \CjwNetwork\RequestShield\Learn::recordFile($learnStore);
    $text = (string) @file_get_contents($file);
    if (trim($text) === '') {
        showcaseJson(200, ['results' => [], 'skipped' => 0]);
        return;
    }
    $settings = \CjwNetwork\RequestShield\Settings::from(\CjwNetwork\RequestShield\Rules\RuleFile::switchedOn([__DIR__ . '/showcase.rules']));
    $rec = \CjwNetwork\RequestShield\Rules\Replay::read($text);
    $run = \CjwNetwork\RequestShield\Rules\Replay::run($rec['requests'], static fn (string $h): \CjwNetwork\RequestShield\Settings => $settings, null);
    $out = [];
    foreach ($run['results'] as $r) {
        $out[] = ['method' => $r['method'], 'url' => (string) preg_replace('#^https?://[^/]+#i', '', $r['url']),
            'kind' => $r['kind'] === 'refused' && $r['found'] ? 'offered' : $r['kind'], 'got' => $r['got'], 'rule' => $r['rule']];
    }
    showcaseJson(200, ['results' => $out, 'skipped' => $rec['skipped']]);
    return;
}
if ($path === '/__exp') {
    // The Exponential example's examples, decided as request-shield test does: with its rules
    // (demo.rules: the admin as /admin), each on a fresh store -- nothing counted here. n=0 decides
    // them all in one request ("check all": one request each would run into SHOW-PACE).
    $want = (int) ($_GET['n'] ?? -1);
    $found = [];
    foreach (exponentialGroups(showcaseExponential()) as $g) {
        foreach ($g['tries'] as $x) {
            if ($want === 0 || $x['n'] === $want) {
                $found[] = $x;
            }
        }
    }
    if ($found === []) {
        showcaseJson(404, ['error' => 'no such example']);
        return;
    }
    // Switched on as request-shield test has it (EXP-BAN ships watched), with a secret of its own,
    // no log and no live view -- an example never touches the Exponential demo's files.
    $c = \CjwNetwork\RequestShield\Rules\RuleFile::switchedOn([showcaseExponential() . '/demo.rules']);
    unset($c['monitorRules']);
    $c['storeDir'] = (getenv('REQUEST_SHIELD_SHOWCASE_VAR') ?: __DIR__ . '/var') . '/exponential';
    $c['store'] = 'memory';
    $c['challenge'] = (is_array($c['challenge'] ?? null) ? $c['challenge'] : []);
    $c['challenge']['secret'] = bin2hex(random_bytes(32));
    $c['challenge']['dnsLookups'] = 0;
    $c['log'] = (is_array($c['log'] ?? null) ? $c['log'] : []);
    $c['log']['file'] = null;
    $c['live'] = ['enabled' => false];
    $settings = \CjwNetwork\RequestShield\Settings::from($c);
    $results = [];
    foreach ($found as $x) {
        $r = \CjwNetwork\RequestShield\Rules\Examples::one($settings, ['method' => $x['method'], 'url' => $x['url'], 'outcome' => $x['outcome'], 'by' => $x['by'],
            'rule' => $x['rule'], 'from' => $x['from'], 'pass' => $x['pass'], 'times' => $x['times'], 'headers' => $x['headers'], 'text' => null, 'at' => $x['at'],
            'site' => $x['site'], 'ua' => $x['ua'], 'demo' => null]);
        $results[(string) $x['n']] = ['got' => $r['got'], 'http' => $r['http'], 'rule' => $r['gotRule'], 'ok' => $r['status'] === 'pass'];
    }
    showcaseJson(200, $want === 0 ? ['results' => $results] : $results[(string) $want]);
    return;
}
if ($path === '/__try') {
    $try = $tries[(int) ($_GET['n'] ?? -1)] ?? null;
    $shield = Shield::active();
    if ($try === null || $shield === null || $try['headers'] === []) {
        showcaseJson(404, ['error' => 'no such try: only the ones a browser cannot send itself are decided here']);
        return;
    }
    $s = $shield->settings;
    $request = Inspector::request($try['method'], $try['url'], $try['from'], $try['headers'], $s->trustedProxies);
    $t = (new Inspector($s, $shield->store()))->trace($request);
    $d = $t['decision'];
    showcaseJson(200, ['status' => $d->passes() ? 200 : $d->status, 'action' => $d->action, 'reason' => $d->reason, 'rule' => $t['rule'], 'verdict' => $t['verdict']]);
    return;
}
// The API (match /api/** in the rules): what is left for it to check is its own business -- the
// shield has already let through only GET or POST, typed parameters, a visitor within its pace,
// and for a POST one that holds a pass. The body is not the shield's: the API reads its JSON itself.
if (strncmp($path, '/api/v1/products', 16) === 0 && $method === 'GET') {
    $all = [['id' => 1, 'name' => 'Shield, small', 'price' => 9.5], ['id' => 2, 'name' => 'Shield, large', 'price' => 19.0],
        ['id' => 3, 'name' => 'Pass, one hour', 'price' => 0.0], ['id' => 4, 'name' => 'Rule file', 'price' => 0.0]];
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int) ($_GET['limit'] ?? 10)));
    $items = array_slice($all, ($page - 1) * $limit, $limit);
    $xml = ($_GET['format'] ?? '') === 'xml' || (!isset($_GET['format']) && stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'xml') !== false);
    header('Cache-Control: no-store');
    if ($xml) {
        header('Content-Type: application/xml; charset=utf-8');
        $x = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>', "\n<products page=\"$page\">\n";
        foreach ($items as $i) {
            echo '  <product id="', (int) $i['id'], '"><name>', $x($i['name']), '</name><price>', number_format($i['price'], 2, '.', ''), "</price></product>\n";
        }
        echo "</products>\n";
        return;
    }
    showcaseJson(200, ['page' => $page, 'products' => $items]);
    return;
}
if ($path === '/api/v1/messages' && $method === 'POST') {
    // JSON only, checked by the API: the shield has made sure a browser sent it (a pass), not what it says.
    if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
        showcaseJson(415, ['error' => 'send JSON (Content-Type: application/json)']);
        return;
    }
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 16384), true);
    $name = is_array($in) && is_string($in['name'] ?? null) ? trim($in['name']) : '';
    $message = is_array($in) && is_string($in['message'] ?? null) ? trim($in['message']) : '';
    if ($name === '' || $message === '' || strlen($name) > 100 || strlen($message) > 2000) {
        showcaseJson(422, ['error' => 'name and message, at most 100 and 2000 characters']);
        return;
    }
    showcaseJson(201, ['ok' => true, 'id' => bin2hex(random_bytes(4)), 'note' => 'a showcase: nothing is kept']);
    return;
}
if (strncmp($path, '/api/', 5) === 0) {
    showcaseJson(404, ['error' => 'no such endpoint']);
    return;
}
if ($path === '/search') {
    // The search counts itself (on-demand): past 5 a minute the shield answers instead -- a 429 with
    // Retry-After, and a pause for this visitor that doubles each time (ban-growth).
    Shield::active()?->consume('searches', null, null, true);
    $q = trim((string) ($_GET['q'] ?? ''));
    $hits = $q === '' ? [] : [['title' => 'Getting started', 'url' => '/'], ['title' => 'The rules', 'url' => '/#rules'], ['title' => 'Try it', 'url' => '/#try']];
    showcaseJson(200, ['q' => $q, 'results' => $hits]);
    return;
}
if ($path === '/account/login' && $method === 'POST') {
    // A right password counts nothing; a wrong one is counted -- past 3 in 15 minutes the shield answers.
    if (hash_equals('sesam', (string) ($_POST['password'] ?? ''))) {
        showcaseJson(200, ['ok' => true]);
        return;
    }
    Shield::active()?->consume('logins', null, null, true);
    showcaseJson(200, ['ok' => false]);
    return;
}
if ($path === '/__log' && $public !== null && !$learnHere) {
    // On a public host the log holds other visitors' addresses as they asked for them: for --admin only.
    showcaseJson(403, ['error' => 'the log is for the showcase\'s admin addresses (build/showcase.php --admin)']);
    return;
}
if ($path === '/__log') {
    // The end of the shield's own log (set log): what it stopped, checked or slowed down, addresses
    // masked (log-ip masked, the default). The showcase runs on your machine; a real site keeps its log to itself.
    $file = Shield::active()?->settings->logFile;
    $lines = $all = [];
    if (is_string($file) && is_file($file)) {
        $size = (int) filesize($file);
        $h = fopen($file, 'rb');
        if ($h !== false) {
            fseek($h, max(0, $size - 65536));
            $all = array_values(array_filter(explode("\n", (string) stream_get_contents($h)), 'strlen'));
            fclose($h);
            if ($size > 65536) {
                array_shift($all);      // read from the middle of the file: the first line may be cut
            }
            $lines = array_slice($all, -14);
        }
    }
    // A demo never shows an address whole, whatever log-ip says: only its network.
    $lines = array_map(static fn (string $l): string => (string) preg_replace_callback('/^(\S+) (\S+) /', static fn (array $m): string => $m[1] . ' ' . showcaseMask($m[2]) . ' ', $l), $lines);
    showcaseJson(200, ['lines' => $lines, 'bans' => showcaseBans(Shield::active(), $all, time()), 'denied' => showcaseDenied(Shield::active()),
        'deniedCount' => Shield::active()?->settings->denyCount ?? 0, 'now' => time()]);
    return;
}
if ($path === '/__forget') {
    // "Forget the pass" (the JSON form): the next message asks for the check again.
    setcookie('rsp', '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    http_response_code(204);
    return;
}
if ($path === '/__login') {
    // "See the check": the pass forgotten first, so the login checks again (a pass holds an hour).
    setcookie('rsp', '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    header('Location: /login', true, 303);
    return;
}
if ($path === '/contact') {
    if ($method === 'POST') {
        showcaseJson(200, ['ok' => true, 'message' => 'Thank you -- your message arrived (a showcase: nothing is sent).']);
        return;
    }
    showcaseAnswer(200, 'Contact', 'The contact form would be here. Sent from this site, it arrives; sent from another site, the shield stops it.');
    return;
}
if ($path === '/admin/' || $path === '/admin') {
    showcaseAnswer(200, 'Admin area', 'You are in: the shield let you through because you came from the office network.');
    return;
}
if ($path === '/login') {
    showcaseAnswer(200, 'Sign in', 'You passed the invisible browser check -- no puzzle, no click. Your browser now holds a pass; the next pages come straight through.');
    return;
}
if ($path !== '/') {
    showcaseAnswer(404, 'Not found', 'The site itself has no page here -- the shield let the request through, the application answered.');
    return;
}
$view = 'main';
require __DIR__ . '/page.php';
