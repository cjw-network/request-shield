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

// The demo's pages: what the site shows for each path, and the front page with
// the rows from the rules' "# demo:" groups (pages.php).
require __DIR__ . '/pages.php';
