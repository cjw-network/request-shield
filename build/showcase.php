<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 *
 *   php build/showcase.php --out=<dir> [--host=<name>[:<port>]] [--admin=<address or range>,...]
 *
 * The showcase as one directory for a public host -- a shared host with
 * open_basedir too: the page (examples/showcase), the library and the
 * Exponential example it shows (lib/), and var/ for what the shield keeps;
 * nothing outside the directory is read or written. Upload it and point a
 * (sub)domain's document root at it: Apache with .htaccess (AllowOverride)
 * and mod_rewrite sends every address to index.php, lib/, var/ and
 * .request-shield/ are never served.
 *
 * On a public host "this machine" (127.0.0.1) may be the hoster's proxy in
 * front of every visitor, so the copy trusts no proxy, and what the
 * showcase keeps for this machine -- the shield's own pages (/rs/**), the
 * log (/__log), the learning run -- is for --admin's addresses only (none:
 * nobody). --host names the address visitors use, for the HTTP cache's tab
 * (http-cache-hosts; else REQUEST_SHIELD_SHOWCASE_HOST, else 127.0.0.1:8090).
 *
 * The rules are compiled once before the copy is named done: a wrong
 * --admin or --host is refused here, not by a site that switches the shield
 * off. Exit 0: written; 1: refused (the message says why) -- an existing
 * directory is never written into, a failed build leaves nothing behind.
 */

declare(strict_types=1);

const ROOT = __DIR__ . '/..';

require ROOT . '/bootstrap.php';        // the command line: only loads the classes (RuleFile, below)

/** Copies a directory -- not what the shield keeps next to a rule file, not var/, no link. */
function showcaseCopy(string $from, string $to): void
{
    if (!is_dir($to) && !mkdir($to, 0755, true)) {
        throw new RuntimeException("cannot make $to");
    }
    foreach (scandir($from) ?: [] as $name) {
        $src = "$from/$name";
        if ($name === '.' || $name === '..' || $name === '.request-shield' || $name === 'var' || is_link($src)) {
            continue;
        }
        if (is_dir($src)) {
            showcaseCopy($src, "$to/$name");
        } elseif (!copy($src, "$to/$name")) {
            throw new RuntimeException("cannot copy $src");
        }
    }
}

function showcaseWrite(string $file, string $text, int $mode = 0644): void
{
    if (file_put_contents($file, $text) !== strlen($text) || !chmod($file, $mode)) {
        throw new RuntimeException("cannot write $file");
    }
}

function showcaseRemove(string $dir): void
{
    foreach (scandir($dir) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            is_dir("$dir/$name") && !is_link("$dir/$name") ? showcaseRemove("$dir/$name") : unlink("$dir/$name");
        }
    }
    rmdir($dir);
}

$opts = getopt('', ['out:', 'host:', 'admin:']);
$out = is_string($opts['out'] ?? null) ? rtrim($opts['out'], '/') : '';
$host = is_string($opts['host'] ?? null) ? strtolower(trim($opts['host'])) : null;
$admin = is_string($opts['admin'] ?? null) ? array_values(array_filter(array_map('trim', explode(',', $opts['admin'])))) : [];
if ($out === '') {
    fwrite(STDERR, "usage: php build/showcase.php --out=<dir> [--host=<name>[:<port>]] [--admin=<address or range>,...]\n");
    exit(1);
}
if (file_exists($out)) {
    fwrite(STDERR, "build/showcase.php: $out exists -- name a new directory\n");
    exit(1);
}
if (!is_dir(dirname($out))) {
    fwrite(STDERR, 'build/showcase.php: ' . dirname($out) . " does not exist\n");
    exit(1);
}
if ($host !== null && preg_match('/^[a-z0-9.-]+(:\d{1,5})?$/', $host) !== 1) {
    fwrite(STDERR, "build/showcase.php: --host is a host name, a port may follow (showcase.example.org, 127.0.0.1:8090) -- not \"$host\"\n");
    exit(1);
}
foreach ($admin as $a) {
    if (preg_match('#^[0-9a-fA-F:.]+(/\d{1,3})?$#', $a) !== 1) {
        fwrite(STDERR, "build/showcase.php: --admin takes addresses or ranges (203.0.113.7, 2001:db8::/48) -- not \"$a\"\n");
        exit(1);
    }
}
foreach ($admin as $a) {
    if (preg_match('#^(127\.|::1$|::1/)#', $a) === 1) {
        fwrite(STDERR, "build/showcase.php: warning: --admin $a is this machine -- behind a hoster's proxy that may be every visitor\n");
    }
}
$tmp = $out . '.part-' . getmypid();
try {
    showcaseCopy(ROOT . '/examples/showcase', $tmp);
    showcaseCopy(ROOT . '/examples/exponential', "$tmp/lib/exponential");
    foreach (['src', 'plugins', 'rules'] as $dir) {
        showcaseCopy(ROOT . "/$dir", "$tmp/lib/$dir");
    }
    foreach (['bootstrap.php', 'LICENSE'] as $file) {
        if (!copy(ROOT . "/$file", "$tmp/lib/$file")) {
            throw new RuntimeException("cannot copy $file");
        }
    }
    // Who is "this machine" here: the --admin addresses (lib/public.php; the page reads it).
    showcaseWrite("$tmp/lib/public.php", "<?php\n// Written by build/showcase.php: who may see the log, learn and /rs/** here.\nreturn " . var_export(['admin' => $admin], true) . ";\n");
    // Never served, whatever the server's rewrite does: the library, what the shield keeps, the compiled settings.
    foreach (['var' => 0700, '.request-shield' => 0700] as $dir => $mode) {
        if (!mkdir("$tmp/$dir", $mode)) {
            throw new RuntimeException("cannot make $tmp/$dir");
        }
    }
    foreach (['lib', 'var', '.request-shield'] as $dir) {
        showcaseWrite("$tmp/$dir/.htaccess", "# Not for the web: read and written by index.php only.\nRequire all denied\n");
    }
    $rules = (string) file_get_contents("$tmp/showcase.rules");
    $change = [
        // No proxy trusted: on a public host 127.0.0.1 may be the hoster's proxy, and any visitor could name an address.
        '#^trust\s+127\.0\.0\.1 ::1\b.*$#m' => '# trust: none in a copy for a public host (build/showcase.php) -- name your proxy here if you have one',
        // The shield's own pages: --admin's addresses, else nobody (192.0.2.1: a documentation address, never a visitor).
        '#restrict /rs/\*\* to 127\.0\.0\.1 ::1.*$#m' => 'restrict /rs/** to ' . ($admin === [] ? '192.0.2.1' : implode(' ', $admin))
            . '   # the shield\'s own pages: the --admin addresses (build/showcase.php)' . ($admin === [] ? ' -- none: 192.0.2.1 is nobody' : ''),
    ];
    if ($host !== null) {
        $change['#\$\{REQUEST_SHIELD_SHOWCASE_HOST:-127\.0\.0\.1:8090\}#'] = '${REQUEST_SHIELD_SHOWCASE_HOST:-' . $host . '}';
    }
    foreach ($change as $pattern => $to) {
        $rules = (string) preg_replace($pattern, $to, $rules, -1, $count);
        if ($count !== 1) {
            throw new RuntimeException("showcase.rules: \"$pattern\" is not there once -- the build and the rules disagree");
        }
    }
    showcaseWrite("$tmp/showcase.rules", $rules);
    // Compiled once here: a mistake is refused now, not by a site that switches the shield off.
    try {
        \CjwNetwork\RequestShield\Rules\RuleFile::read(["$tmp/showcase.rules"]);
    } catch (\CjwNetwork\RequestShield\Rules\RuleFileException $e) {
        throw new RuntimeException('the copy\'s rules do not compile: ' . $e->getMessage());
    }
    if (!rename($tmp, $out)) {
        throw new RuntimeException("cannot move the build to $out");
    }
} catch (Throwable $e) {
    if (is_dir($tmp)) {
        showcaseRemove($tmp);
    }
    fwrite(STDERR, 'build/showcase.php: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "build/showcase.php: written $out -- upload it, a (sub)domain's document root pointing at it (Apache, .htaccess and mod_rewrite);\n"
    . "then check that https://<host>/var/secret is not served (403 or the showcase's 404)\n";
