<?php
// For PHP's built-in server: the page's own files (styles, scripts, fonts)
// from assets/, whatever directory the server was started in; every other
// path to index.php, as a web server's rewrite rules would send it -- so
// /.env and the like reach the shield.
//
//   php -S 127.0.0.1:8090 examples/showcase/router.php
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (strncmp($path, '/assets/', 8) === 0 && strpos($path, '..') === false && is_file(__DIR__ . $path)) {
    $types = ['css' => 'text/css', 'js' => 'text/javascript', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'svg' => 'image/svg+xml', 'png' => 'image/png'];
    header('Content-Type: ' . ($types[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=3600');
    readfile(__DIR__ . $path);
    return true;
}
require __DIR__ . '/index.php';
