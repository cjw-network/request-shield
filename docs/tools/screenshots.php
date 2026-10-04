<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 *
 *   php docs/tools/screenshots.php [--browser=<chrome or chromium>]
 *
 * The docs' screenshots, from the demo (0031 F.9): `request-shield examples
 * --html` records the demo's rules as a page that needs no server, and a
 * headless Chrome takes its picture -- docs/screenshots/examples.png (the
 * whole page's top) and docs/screenshots/examples-<id>.png for a few
 * features. Nothing made up: every row on them was decided by the rules.
 * Run it after the demo changed; the tests only check that the pictures are
 * there and are PNG.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$browser = null;
foreach (array_slice($argv, 1) as $a) {
    if (strncmp($a, '--browser=', 10) === 0) {
        $browser = substr($a, 10);
    }
}
foreach ($browser !== null ? [$browser] : ['google-chrome', 'chromium', 'chromium-browser'] as $try) {
    $path = trim((string) shell_exec('command -v ' . escapeshellarg($try) . ' 2>/dev/null'));
    if ($path !== '') {
        $browser = $path;
        break;
    }
    $browser = null;
}
if ($browser === null) {
    fwrite(STDERR, "screenshots: no Chrome or Chromium found (--browser=<path>)\n");
    exit(2);
}

$tmp = sys_get_temp_dir() . '/rs-shots-' . getmypid();
@mkdir($tmp);
$html = "$tmp/examples.html";
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/bin/request-shield") . ' examples ' . escapeshellarg("$root/examples/demo/request-shield.rules")
    . ' --html --out=' . escapeshellarg($html) . ' 2>&1', $out, $code);
if ($code !== 0 || !is_file($html)) {
    fwrite(STDERR, "screenshots: examples --html failed\n" . implode("\n", $out) . "\n");
    exit(1);
}

/**
 * One picture of a page, $w x $h. Headless Chrome leaves a blank band under
 * the page as tall as a window's own bar: the window is that much taller,
 * and the band is cut off (GD).
 */
$shoot = static function (string $url, string $png, int $w, int $h) use ($browser, $tmp): void {
    $band = 88;
    $raw = "$tmp/raw.png";
    @unlink($raw);
    $cmd = escapeshellarg($browser) . ' --headless=new --disable-gpu --no-sandbox --hide-scrollbars --force-device-scale-factor=1'
        . ' --user-data-dir=' . escapeshellarg("$tmp/profile") . ' --window-size=' . $w . ',' . ($h + $band) . ' --screenshot=' . escapeshellarg($raw) . ' ' . escapeshellarg($url) . ' 2>/dev/null';
    exec($cmd, $o, $c);
    $img = is_file($raw) ? @imagecreatefrompng($raw) : false;
    if ($img === false) {
        fwrite(STDERR, "screenshots: $png was not written\n");
        exit(1);
    }
    $cut = imagecrop($img, ['x' => 0, 'y' => 0, 'width' => $w, 'height' => $h]);
    if ($cut === false || !imagepng($cut, $png, 9)) {
        fwrite(STDERR, "screenshots: $png could not be cut\n");
        exit(1);
    }
    echo 'screenshots: ' . basename($png) . "\n";
};

$shoot('file://' . $html, "$root/docs/screenshots/examples.png", 1200, 900);
// A feature's group alone (--feature), so its picture starts there.
foreach (['RSF02-06', 'RSF03-01'] as $id) {
    $one = "$tmp/examples-$id.html";
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/bin/request-shield") . ' examples ' . escapeshellarg("$root/examples/demo/request-shield.rules")
        . ' --html --feature=' . $id . ' --out=' . escapeshellarg($one) . ' 2>&1', $o2, $c2);
    $shoot('file://' . $one, "$root/docs/screenshots/examples-$id.png", 1200, 560);
}
exec('rm -rf ' . escapeshellarg($tmp));
