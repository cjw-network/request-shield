<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 *
 *   php build/release-check.php <tag> [--notes=<file>] [--root=<dir>]
 *
 * Before a release is built (0031 step E.5, .github/workflows/release.yml):
 * the tag is vX.Y.Z, Shield::VERSION is X.Y.Z, and CHANGELOG.md has a
 * section "## [X.Y.Z]". With --notes, that section is written to the file,
 * as the release's notes. --root reads another checkout (the tests).
 * Exit 0: fit to release; 1: not (the message says why).
 */

declare(strict_types=1);

$tag = null;
$notes = null;
$root = __DIR__ . '/..';
foreach (array_slice($argv, 1) as $a) {
    if (strncmp($a, '--notes=', 8) === 0) {
        $notes = substr($a, 8);
    } elseif (strncmp($a, '--root=', 7) === 0) {
        $root = substr($a, 7);
    } elseif ($tag === null && $a !== '' && $a[0] !== '-') {
        $tag = $a;
    } else {
        $tag = null;
        break;
    }
}
$fail = static function (string $why): void {
    fwrite(STDERR, "release-check: $why\n");
    exit(1);
};
if ($tag === null) {
    $fail('usage: php build/release-check.php <tag> [--notes=<file>] [--root=<dir>]');
}
if (preg_match('/^v(\d+\.\d+\.\d+)$/', (string) $tag, $m) !== 1) {
    $fail("the tag \"$tag\" is not vX.Y.Z");
}
$version = $m[1];
if (preg_match("/public const VERSION = '([^']+)';/", (string) @file_get_contents("$root/src/Shield.php"), $v) !== 1) {
    $fail('src/Shield.php has no VERSION');
}
if ($v[1] !== $version) {
    $fail("Shield::VERSION is {$v[1]}, the tag says $version -- set the version, commit, then tag");
}
$log = (string) @file_get_contents("$root/CHANGELOG.md");
if (preg_match('/^## \[' . preg_quote($version, '/') . '\][^\n]*\n(.*?)(?=^## \[|\z)/ms', $log, $section) !== 1) {
    $fail("CHANGELOG.md has no section \"## [$version]\" -- move Unreleased there");
}
$body = trim($section[1]);
if ($body === '') {
    $fail("CHANGELOG.md's section $version is empty");
}
if ($notes !== null && @file_put_contents($notes, $body . "\n") === false) {
    $fail("cannot write $notes");
}
echo "release-check: $tag fits -- Shield::VERSION $version, the changelog has its section\n";
