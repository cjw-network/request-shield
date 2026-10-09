<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 *
 *   php build/showcase.php --out=<dir> [--host=<name>[:<port>]] [--admin=<address or range>,...]
 *
 * The showcase as one directory that runs on its own -- on a shared host
 * with open_basedir too: the page (examples/showcase), the Exponential
 * example it shows (exponential/), the library (lib/: bootstrap.php, src/,
 * plugins/, rules/) and var/ for what the shield keeps -- nothing outside
 * the directory is read or written. Upload it and point a (sub)domain's
 * document root at it; Apache's mod_rewrite sends every address to
 * index.php (.htaccess), lib/ and var/ are never served. --host names the
 * address visitors use, for the HTTP cache's tab (http-cache-hosts; else
 * REQUEST_SHIELD_SHOWCASE_HOST, else 127.0.0.1:8090). The shield's own
 * pages (/rs/**, the statistics, the cache) are for --admin's addresses
 * only -- none by default: on a public host "this machine" (127.0.0.1) may
 * be the hoster's proxy in front of every visitor.
 *
 * Exit 0: written; 1: refused (the message says why) -- an existing
 * directory is never written into, a failed build leaves nothing behind.
 */

declare(strict_types=1);

const ROOT = __DIR__ . '/..';

/** Copies a directory, leaving out what the shield keeps next to a rule file and var/. */
function showcaseCopy(string $from, string $to): void
{
    if (!is_dir($to) && !mkdir($to, 0755, true)) {
        throw new RuntimeException("cannot make $to");
    }
    foreach (scandir($from) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name === '.request-shield' || $name === 'var') {
            continue;
        }
        $src = "$from/$name";
        if (is_dir($src)) {
            showcaseCopy($src, "$to/$name");
        } elseif (!copy($src, "$to/$name")) {
            throw new RuntimeException("cannot copy $src");
        }
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
$admin = is_string($opts['admin'] ?? null) ? array_values(array_filter(array_map('trim', explode(',', $opts['admin'])))) : [];
$out = is_string($opts['out'] ?? null) ? rtrim($opts['out'], '/') : '';
$host = is_string($opts['host'] ?? null) ? strtolower(trim($opts['host'])) : null;
if ($out === '') {
    fwrite(STDERR, "usage: php build/showcase.php --out=<dir> [--host=<name>[:<port>]] [--admin=<address or range>,...]\n");
    exit(1);
}
if (file_exists($out)) {
    fwrite(STDERR, "build/showcase.php: $out exists -- name a new directory\n");
    exit(1);
}
foreach ($admin as $a) {
    if (preg_match('#^[0-9a-fA-F:.]+(/\d{1,3})?$#', $a) !== 1) {
        fwrite(STDERR, "build/showcase.php: --admin takes addresses or ranges (203.0.113.7, 2001:db8::/48) -- not \"$a\"\n");
        exit(1);
    }
}
if ($host !== null && preg_match('/^[a-z0-9.-]+(:\d{1,5})?$/', $host) !== 1) {
    fwrite(STDERR, "build/showcase.php: --host is a host name, a port may follow (showcase.example.org, 127.0.0.1:8090) -- not \"$host\"\n");
    exit(1);
}
$tmp = $out . '.part-' . getmypid();
try {
    showcaseCopy(ROOT . '/examples/showcase', $tmp);
    showcaseCopy(ROOT . '/examples/exponential', "$tmp/exponential");
    foreach (['src', 'plugins', 'rules'] as $dir) {
        showcaseCopy(ROOT . "/$dir", "$tmp/lib/$dir");
    }
    foreach (['bootstrap.php', 'LICENSE'] as $file) {
        if (!copy(ROOT . "/$file", "$tmp/lib/$file")) {
            throw new RuntimeException("cannot copy $file");
        }
    }
    // Never served, whatever the server's rewrite does: the library and what the shield keeps.
    mkdir("$tmp/var", 0700);
    mkdir("$tmp/exponential/var", 0700);
    foreach (['lib', 'var', 'exponential/var'] as $dir) {
        file_put_contents("$tmp/$dir/.htaccess", "# Not for the web: read and written by index.php only.\nRequire all denied\n");
    }
    // The shield's own pages: --admin's addresses, else nobody (192.0.2.1 is a documentation address, never a visitor).
    $rules = (string) file_get_contents("$tmp/showcase.rules");
    $rs = 'restrict /rs/** to 127.0.0.1 ::1';
    if (strpos($rules, $rs) === false) {
        throw new RuntimeException("showcase.rules has no \"$rs\" to close");
    }
    file_put_contents("$tmp/showcase.rules", str_replace($rs, 'restrict /rs/** to ' . ($admin === [] ? '192.0.2.1' : implode(' ', $admin)), $rules));
    if ($host !== null) {
        $rules = (string) file_get_contents("$tmp/showcase.rules");
        $was = '${REQUEST_SHIELD_SHOWCASE_HOST:-127.0.0.1:8090}';
        if (strpos($rules, $was) === false) {
            throw new RuntimeException("showcase.rules has no $was to name the host in");
        }
        file_put_contents("$tmp/showcase.rules", str_replace($was, '${REQUEST_SHIELD_SHOWCASE_HOST:-' . $host . '}', $rules));
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
echo "build/showcase.php: written $out -- upload it, a (sub)domain's document root pointing at it (Apache with mod_rewrite)\n";
