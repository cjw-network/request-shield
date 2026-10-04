<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 *
 *   php docs/tools/check-anchors.php [--root=<dir>]
 *
 * Every link into the docs finds what it points at (0031 F.9): a Markdown
 * link with a #anchor to a page of the repository, and every Help::link(),
 * url(), about() and see() in src/ and plugins/ with a feature id and an
 * anchor -- the page must be there, and a heading (GitHub's anchor for it) or
 * an <a id> with that name. Prints each one that does not, with file and
 * line; exit 1 then, 0 when all are found.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
foreach (array_slice($argv, 1) as $arg) {
    if (strncmp($arg, '--root=', 7) === 0) {
        $root = rtrim(substr($arg, 7), '/');       // another tree, for the tests
    }
}

/** GitHub's anchor for a heading: lower case, punctuation out, spaces to hyphens. */
function anchorOf(string $heading): string
{
    $h = (string) preg_replace('/`([^`]*)`/', '$1', $heading);
    $h = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $h);
    $h = (string) preg_replace('/[^\p{L}\p{N} _-]/u', '', mb_strtolower(trim($h)));
    return str_replace(' ', '-', $h);
}

/** @return array<string, true> the anchors a Markdown file has */
function anchorsIn(string $file): array
{
    static $cache = [];
    if (isset($cache[$file])) {
        return $cache[$file];
    }
    $out = [];
    $fence = false;
    foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $line) {
        if (preg_match('/^\s*```/', $line) === 1) {
            $fence = !$fence;
            continue;
        }
        if ($fence) {
            continue;
        }
        if (preg_match('/^#{1,6}\s+(.+?)\s*#*\s*$/', $line, $m) === 1) {
            $a = anchorOf($m[1]);
            $n = 0;
            $try = $a;
            while (isset($out[$try])) {
                $try = $a . '-' . ++$n;
            }
            $out[$try] = true;
        }
        if (preg_match_all('/<(?:a|span|div)\s[^>]*(?:id|name)="([^"]+)"/', $line, $ids) > 0) {
            foreach ($ids[1] as $id) {
                $out[$id] = true;
            }
        }
    }
    return $cache[$file] = $out;
}

$problems = [];
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/docs", FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && substr($f->getFilename(), -3) === '.md') {
        $files[] = $f->getPathname();
    }
}
foreach (['README.md', 'CONTRIBUTING.md', 'AGENTS.md', 'CHANGELOG.md', 'SECURITY.md', 'examples/demo/README.md'] as $f) {
    if (is_file("$root/$f")) {
        $files[] = "$root/$f";
    }
}
sort($files);
foreach ($files as $file) {
    $fence = false;
    foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $i => $line) {
        if (preg_match('/^\s*```/', $line) === 1) {
            $fence = !$fence;
        }
        if ($fence) {
            continue;
        }
        $line = (string) preg_replace('/`[^`]*`/', '', $line);
        preg_match_all('/\]\(([^)\s]*)#([^)\s]+)\)/', $line, $m, PREG_SET_ORDER);
        foreach ($m as [, $target, $anchor]) {
            if (preg_match('#^[a-z]+:#i', $target) === 1) {
                continue;
            }
            $page = $target === '' ? $file : dirname($file) . '/' . $target;
            if (substr($page, -3) !== '.md' || !is_file($page)) {
                continue;               // a page that is not there: DocsTest says so
            }
            if (!isset(anchorsIn($page)[$anchor])) {
                $problems[] = substr($file, strlen($root) + 1) . ':' . ($i + 1) . ": $target#$anchor -- no such heading";
            }
        }
    }
}

// The pages' and the command line's links into the docs (Help).
$code = [];
foreach (['src', 'plugins'] as $dir) {
    if (!is_dir("$root/$dir")) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && substr($f->getFilename(), -4) === '.php') {
            $code[] = $f->getPathname();
        }
    }
}
sort($code);
foreach ($code as $file) {
    foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $i => $line) {
        preg_match_all("/Help::(?:link|url|about|see)\\(\\s*'(RSF\\d{2}-\\d{2})'(?:\\s*,\\s*'([^']*)')?/", $line, $m, PREG_SET_ORDER);
        foreach ($m as $call) {
            $id = $call[1];
            $anchor = $call[2] ?? '';
            $page = glob("$root/docs/features/$id-*.md") ?: [];
            $at = substr($file, strlen($root) + 1) . ':' . ($i + 1);
            if (count($page) !== 1) {
                $problems[] = "$at: $id -- no page docs/features/$id-….md";
                continue;
            }
            if ($anchor !== '' && !isset(anchorsIn($page[0])[$anchor])) {
                $problems[] = "$at: $id#$anchor -- no such heading in " . basename($page[0]);
            }
        }
    }
}

if ($problems !== []) {
    fwrite(STDERR, implode("\n", $problems) . "\n" . count($problems) . " link(s) point at nothing\n");
    exit(1);
}
echo 'check-anchors: ' . count($files) . " pages, every anchor found\n";
