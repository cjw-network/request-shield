<?php
/**
 * Runs every tests/*Test.php. Each returns an array of name => closure; a
 * closure passes when it returns without an exception.
 *
 *   php tests/run.php [filter]
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/helpers.php';

final class TestFailure extends RuntimeException
{
}

final class TestSkipped extends RuntimeException
{
}

/** Ends a test that cannot run here (no node, no pcntl); counted, and named. */
function skip(string $why): never
{
    throw new TestSkipped($why);
}

function same(mixed $expected, mixed $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        throw new TestFailure(($what !== '' ? $what . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function truthy(bool $value, string $what): void
{
    if (!$value) {
        throw new TestFailure($what);
    }
}

$filter = $argv[1] ?? '';
$pass = $fail = $skipped = 0;
foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    foreach (require $file as $name => $test) {
        $label = basename($file, '.php') . ' > ' . $name;
        if ($filter !== '' && stripos($label, $filter) === false) {
            continue;
        }
        try {
            $test();
            $pass++;
        } catch (TestSkipped $e) {
            $skipped++;
            printf("  SKIP  %s\n        %s\n", $label, $e->getMessage());
        } catch (Throwable $e) {
            $fail++;
            printf("  FAIL  %s\n        %s\n", $label, $e->getMessage());
        }
    }
}
printf("\n  %s - %d passed, %d failed, %d skipped (PHP %s)\n\n", $fail === 0 ? 'PASS' : 'FAIL', $pass, $fail, $skipped, PHP_VERSION);
exit($fail === 0 ? 0 : 1);
