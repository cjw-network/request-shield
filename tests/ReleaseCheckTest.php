<?php

declare(strict_types=1);

/**
 * The release (0031 E.5): build/release-check.php decides whether a tag may
 * be released; .github/workflows/release.yml builds, tests and publishes.
 */

/**
 * The check against a small checkout of its own: Shield.php with $version,
 * CHANGELOG.md as given.
 *
 * @return array{0: string, 1: int, 2: ?string} what it said, its exit code, the notes it wrote
 */
function releaseCheck(string $tag, string $version, string $changelog): array
{
    if (!function_exists('exec')) {
        skip('no exec');
    }
    $root = sys_get_temp_dir() . '/rs-release-' . getmypid() . '-' . mt_rand();
    mkdir("$root/src", 0700, true);
    try {
        file_put_contents("$root/src/Shield.php", "<?php\nfinal class Shield\n{\n    public const VERSION = '$version';\n}\n");
        file_put_contents("$root/CHANGELOG.md", $changelog);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/build/release-check.php') . ' ' . escapeshellarg($tag)
            . ' --notes=' . escapeshellarg("$root/notes.md") . ' --root=' . escapeshellarg($root) . ' 2>&1', $out, $code);
        return [implode("\n", $out), $code, is_file("$root/notes.md") ? (string) file_get_contents("$root/notes.md") : null];
    } finally {
        exec('rm -rf ' . escapeshellarg($root));
    }
}

const RELEASE_LOG = "# Changelog\n\n## [Unreleased]\n\n## [1.2.0] — 2026-10-04\n\n### Added\n- **One thing.**\n\n## [1.1.0] — 2026-09-01\n\n### Fixed\n- An older one.\n";

return [
    'a tag is released when Shield::VERSION and the changelog name it; the section becomes the notes' => function (): void {
        [$said, $code, $notes] = releaseCheck('v1.2.0', '1.2.0', RELEASE_LOG);
        same(0, $code, $said);
        same("### Added\n- **One thing.**\n", $notes, 'the section, without its heading and without the next one');
    },
    'refused: not vX.Y.Z, another version in the code, no section, an empty one' => function (): void {
        foreach ([
            ['1.2.0', '1.2.0', RELEASE_LOG, 'is not vX.Y.Z'],
            ['v1.2', '1.2.0', RELEASE_LOG, 'is not vX.Y.Z'],
            ['v1.2.0', '1.2.0-dev', RELEASE_LOG, 'Shield::VERSION is 1.2.0-dev, the tag says 1.2.0'],
            ['v1.3.0', '1.3.0', RELEASE_LOG, 'no section "## [1.3.0]"'],
            ['v1.2.0', '1.2.0', "## [1.2.0] — 2026-10-04\n\n## [1.1.0]\n- x\n", 'is empty'],
        ] as [$tag, $version, $log, $why]) {
            [$said, $code, $notes] = releaseCheck($tag, $version, $log);
            same(1, $code, "$tag/$version: $said");
            truthy(strpos($said, $why) !== false, "$tag/$version: $said");
            same(null, $notes, 'no notes written');
        }
    },
    'the release workflow: on v* tags, actions pinned by commit, built twice and compared, the suite against the file, checksums, attestation, the release environment' => function (): void {
        $yml = (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/release.yml');
        truthy(preg_match("/^on:\n  push:\n    tags: \['v\*'\]/m", $yml) === 1, 'triggered by a v* tag only');
        preg_match_all('/^\s*(?:-\s+)?uses:\s*(\S+)/m', $yml, $m);
        truthy(count($m[1]) >= 5, 'actions: ' . implode(', ', $m[1]));
        foreach ($m[1] as $uses) {
            truthy(preg_match('/^[\w.-]+\/[\w.-]+@[0-9a-f]{40}$/', $uses) === 1, "pinned by commit: $uses");
        }
        foreach (['git merge-base --is-ancestor', 'php build/release-check.php', 'cmp ', 'REQUEST_SHIELD_ENTRY', 'php tests/run.php', 'sha256sum', 'sha256sum -c', 'attest-build-provenance', 'environment: release', 'gh release create'] as $step) {
            truthy(strpos($yml, $step) !== false, "the workflow has: $step");
        }
        truthy(strpos($yml, "permissions:\n  contents: read") !== false, 'read-only by default; only the publishing job may write');
    },
];
