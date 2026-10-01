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
function skip(string $why): void
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

$args = array_slice($argv, 1);
$only = null;                                   // --file=<path>: one file (a child of an isolated run)
foreach ($args as $i => $a) {
    if (strncmp($a, '--file=', 7) === 0) {
        $only = substr($a, 7);
        unset($args[$i]);
    }
}
$filter = (string) (array_values($args)[0] ?? '');
$pass = $fail = $skipped = 0;
$files = $only !== null ? [$only] : (glob(__DIR__ . '/*Test.php') ?: []);

// Isolated (in CI, or TESTS_ISOLATE=1): each file in a process of its own. A
// crash of PHP itself (exit 139, a segfault) then names the file and the test
// it was in -- as an annotation GitHub shows -- and the other files still run.
$isolate = $only === null && (getenv('TESTS_ISOLATE') === '1' || (getenv('GITHUB_ACTIONS') === 'true' && getenv('TESTS_ISOLATE') !== '0'));
$tests = [];
if ($isolate) {
    foreach ($files as $file) {
        $cmd = [PHP_BINARY, __FILE__, '--file=' . $file];
        if ($filter !== '') {
            $cmd[] = $filter;
        }
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($proc)) {
            echo "  FAIL  " . basename($file, '.php') . "\n        cannot start a process for it\n";
            $fail++;
            continue;
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($proc);
        $result = null;
        $last = '';
        foreach (explode("\n", $out) as $line) {
            if (strncmp($line, '@@START ', 8) === 0) {
                $last = substr($line, 8);
            } elseif (preg_match('/^@@RESULT (\d+) (\d+) (\d+)$/', $line, $m) === 1) {
                $result = [(int) $m[1], (int) $m[2], (int) $m[3]];
            } elseif ($line !== '' && strncmp($line, '  PASS -', 8) !== 0 && strncmp($line, '  FAIL -', 8) !== 0) {
                echo $line, "\n";
            }
        }
        if ($result === null) {
            $fail++;
            $where = basename($file, '.php') . ($last !== '' ? ' > ' . $last : '');
            echo "  FAIL  $where\n        PHP ended " . ($code === 139 ? 'with a crash (exit 139, a segfault)' : "with exit code $code") . " -- during this test, before it could report\n";
            if (getenv('GITHUB_ACTIONS') === 'true') {
                echo '::error title=PHP crashed (exit ' . $code . ')::' . str_replace(["\n", '%'], [' ', '%25'], $where . ' on PHP ' . PHP_VERSION) . "\n";
            }
            continue;
        }
        [$p, $f, $sk] = $result;
        $pass += $p;
        $fail += $f;
        $skipped += $sk;
    }
} else {
    // A child loads every file (they share helper functions, ModesTest uses
    // ChallengeTest's creq()) and runs only its own.
    $sets = [];
    foreach (glob(__DIR__ . '/*Test.php') ?: [] as $f) {
        $sets[$f] = require $f;
    }
    foreach ($files as $file) {
        $tests[$file] = $sets[$file] ?? $sets[realpath($file) ?: $file] ?? [];
    }
}
foreach ($tests as $file => $set) {
    foreach ($set as $name => $test) {
        $label = basename($file, '.php') . ' > ' . $name;
        if ($filter !== '' && stripos($label, $filter) === false) {
            continue;
        }
        if ($only !== null) {
            echo "@@START $name\n";                 // for the parent: where a crash happened
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
if ($only !== null) {
    echo "@@RESULT $pass $fail $skipped\n";        // the parent adds them up
    exit(0);
}
// In CI a skipped test is a failure unless the job is meant to lack something
// (TESTS_FAIL_ON_SKIP=1): a test that did not run proves nothing.
if (getenv('TESTS_FAIL_ON_SKIP') === '1' && $skipped > 0) {
    $fail += $skipped;
    echo "  (TESTS_FAIL_ON_SKIP: skipped tests count as failures)\n";
}
printf("\n  %s - %d passed, %d failed, %d skipped (PHP %s)\n\n", $fail === 0 ? 'PASS' : 'FAIL', $pass, $fail, $skipped, PHP_VERSION);
exit($fail === 0 ? 0 : 1);
