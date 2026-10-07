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
 * @return list<array{title: string, rules: list<string>, tries: list<array{n: int, method: string, url: string, headers: array<string, string>, from: string, pass: bool, times: int, outcome: string, by: ?string, text: string, section: string, at: string}>}>
 */
function exponentialGroups(string $dir): array
{
    $groups = [];
    $n = 0;
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
                $groups[$g]['tries'][] = showcaseExpect($t, $n++, (string) $groups[$g]['title']) + ['at' => "$file:" . ($i + 1)];
                continue;
            }
            $hash = strpos($line, ' # ');
            $groups[$g]['rules'][] = rtrim($hash !== false ? substr($line, 0, $hash) : $line);
        }
    }
    return array_values(array_filter($groups, static fn (array $g): bool => $g['tries'] !== []));
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
$tries = showcaseTries(__DIR__ . '/showcase.rules');

if ($path === '/__exp') {
    // The Exponential example's examples, decided as request-shield test does: with its rules
    // (demo.rules: the admin as /admin), each on a fresh store -- nothing counted here. n=0 decides
    // them all in one request ("check all": 77 requests would run into SHOW-PACE).
    $want = (int) ($_GET['n'] ?? -1);
    $found = [];
    foreach (exponentialGroups(dirname(__DIR__) . '/exponential') as $g) {
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
    $c = \CjwNetwork\RequestShield\Rules\RuleFile::switchedOn([dirname(__DIR__) . '/exponential/demo.rules']);
    unset($c['monitorRules']);
    $c['storeDir'] = (getenv('REQUEST_SHIELD_SHOWCASE_VAR') ?: '/tmp/request-shield-showcase') . '/exponential';
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
            'rule' => null, 'from' => $x['from'], 'pass' => $x['pass'], 'times' => $x['times'], 'headers' => $x['headers'], 'text' => null, 'at' => $x['at'],
            'site' => null, 'ua' => null, 'demo' => null]);
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
if ($path === '/__log') {
    // The end of the shield's own log (set log): what it stopped, checked or slowed down, addresses
    // masked (log-ip masked, the default). The showcase runs on your machine; a real site keeps its log to itself.
    $file = Shield::active()?->settings->logFile;
    $lines = [];
    if (is_string($file) && is_file($file)) {
        $size = (int) filesize($file);
        $h = fopen($file, 'rb');
        if ($h !== false) {
            fseek($h, max(0, $size - 16384));
            $all = array_values(array_filter(explode("\n", (string) stream_get_contents($h)), 'strlen'));
            fclose($h);
            if ($size > 16384) {
                array_shift($all);      // read from the middle of the file: the first line may be cut
            }
            $lines = array_slice($all, -14);
        }
    }
    showcaseJson(200, ['lines' => $lines]);
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
require __DIR__ . '/page.php';
