<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 *
 *   php build/ci-plan.php <event>      pull_request | push | schedule | workflow_dispatch
 *
 * Which test legs CI runs for an event (0031 step E.4), as the JSON matrix
 * the `plan` job of .github/workflows/tests.yml hands to the `tests` job:
 * {"include": [{"php": "8.4", "apcu": true, "entry": "source"}, …]}.
 *
 * - a pull request: 3 legs -- PHP 8.0 with the file store, 8.4 with APCu,
 *   8.4 with APCu against the single file (minimal hosting and static
 *   analysis run beside them in their own jobs);
 * - a push to main: every PHP version with and without APCu (12) and the
 *   single file on 8.0 and 8.4 (2);
 * - nightly and by hand: everything, the single file on every leg too (24);
 *   the determinism check runs beside them.
 */

declare(strict_types=1);

const VERSIONS = ['8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

/**
 * @return list<array{php: string, apcu: bool, entry: string}>
 */
function legs(string $event): array
{
    $leg = static fn (string $php, bool $apcu, string $entry): array => ['php' => $php, 'apcu' => $apcu, 'entry' => $entry];
    $all = static function (string $entry) use ($leg): array {
        $out = [];
        foreach (VERSIONS as $php) {
            foreach ([true, false] as $apcu) {
                $out[] = $leg($php, $apcu, $entry);
            }
        }
        return $out;
    };
    switch ($event) {
        case 'pull_request':
            return [$leg('8.0', false, 'source'), $leg('8.4', true, 'source'), $leg('8.4', true, 'single')];
        case 'push':
            return array_merge($all('source'), [$leg('8.0', false, 'single'), $leg('8.4', true, 'single')]);
        case 'schedule':
        case 'workflow_dispatch':
            return array_merge($all('source'), $all('single'));
    }
    fwrite(STDERR, "ci-plan: no plan for the event \"$event\" (pull_request, push, schedule, workflow_dispatch)\n");
    exit(1);
}

echo json_encode(['include' => legs($argv[1] ?? '')], JSON_UNESCAPED_SLASHES), "\n";
