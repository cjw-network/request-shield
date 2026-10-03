<?php

declare(strict_types=1);

/**
 * The CI plan (0031 E.4): build/ci-plan.php decides which legs an event runs;
 * .github/workflows/tests.yml's `plan` job hands its JSON to the `tests` job.
 */

/** @return array{0: list<array{php: string, apcu: bool, entry: string}>|null, 1: int} the legs of an event, and the exit code */
function ciPlan(string $event): array
{
    if (!function_exists('exec')) {
        skip('no exec');
    }
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/build/ci-plan.php') . ' ' . escapeshellarg($event) . ' 2>&1', $out, $code);
    $json = json_decode(implode("\n", $out), true);
    return [is_array($json) && is_array($json['include'] ?? null) ? $json['include'] : null, $code];
}

return [
    'a pull request: 3 legs -- PHP 8.0 file store, 8.4 APCu, 8.4 APCu against the single file' => function (): void {
        same([[['php' => '8.0', 'apcu' => false, 'entry' => 'source'], ['php' => '8.4', 'apcu' => true, 'entry' => 'source'], ['php' => '8.4', 'apcu' => true, 'entry' => 'single']], 0], ciPlan('pull_request'));
    },
    'a push to main: 12 from the source tree, every version with and without APCu, and 2 against the single file; nightly and by hand: all 24' => function (): void {
        $key = static fn (array $l): string => $l['php'] . ($l['apcu'] ? '+apcu' : '') . ':' . $l['entry'];
        [$push] = ciPlan('push');
        truthy(is_array($push), 'a plan');
        same(14, count($push));
        same(12, count(array_filter($push, static fn (array $l): bool => $l['entry'] === 'source')));
        same(['8.0:single', '8.4+apcu:single'], array_values(array_map($key, array_filter($push, static fn (array $l): bool => $l['entry'] === 'single'))));
        foreach (['schedule', 'workflow_dispatch'] as $event) {
            [$all] = ciPlan($event);
            truthy(is_array($all), $event);
            same(24, count(array_unique(array_map($key, $all))), "$event: every leg once");
        }
        same(array_values(array_unique(array_map($key, $push))), array_map($key, $push), 'no leg twice');
    },
    'an event without a plan is refused; the workflow asks the plan and knows its events' => function (): void {
        same([null, 1], ciPlan('release'));
        $yml = (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/tests.yml');
        truthy(strpos($yml, 'php build/ci-plan.php') !== false && strpos($yml, 'fromJSON(needs.plan.outputs.matrix)') !== false, 'the tests job takes its matrix from the plan');
        foreach (['push:', 'pull_request:', 'schedule:', 'workflow_dispatch:'] as $on) {
            truthy(strpos($yml, "\n  $on") !== false, "tests.yml runs on $on");
        }
        truthy(strpos($yml, "determinism:") !== false && strpos($yml, 'cmp ') !== false, 'the determinism check');
    },
];
