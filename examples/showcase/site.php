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
        if (strncmp($line, 'expect ', 7) !== 0) {
            continue;
        }
        $hash = strpos($line, ' # ');
        $text = $hash !== false ? trim(substr($line, $hash + 3)) : '';
        $words = preg_split('/\s+/', trim(substr($hash !== false ? substr($line, 0, $hash) : $line, 7))) ?: [];
        $try = ['n' => count($tries), 'method' => (string) array_shift($words), 'url' => (string) array_shift($words), 'headers' => [], 'from' => '198.51.100.1',
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
        $tries[] = $try;
    }
    return $tries;
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
        '<link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css"><link rel="stylesheet" href="/assets/showcase.css"></head>',
        '<body class="answer-page"><main class="container py-5"><p class="eyebrow">request-shield showcase</p><h1>', $e($title), '</h1><p class="lead">', $e($text),
        '</p><a class="btn btn-primary" href="/">Back to the showcase</a></main></body></html>';
}

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$tries = showcaseTries(__DIR__ . '/showcase.rules');

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
