<?php
/**
 * This file is part of cjw-network/request-shield-testkit.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 *
 * The test runner and the helpers every package's tests use: a test file
 * returns an array of name => closure; a closure passes when it returns
 * without an exception. No framework, nothing to install -- the core and the
 * plugins test themselves the same way (0031 H.1).
 */

declare(strict_types=1);

if (!class_exists('TestFailure', false)) {
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

    /**
     * A port nobody listens on, from the operating system -- a guessed one can
     * belong to another service on the machine, which then answers the test.
     */
    function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            throw new RuntimeException('no free port');
        }
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /** Node, for the tests of a page's script, or null (the test then skips). */
    function nodeBinary(): ?string
    {
        foreach (['node', 'nodejs'] as $name) {
            $path = trim((string) shell_exec('command -v ' . $name . ' 2>/dev/null'));
            if ($path !== '') {
                return $path;
            }
        }
        return null;
    }

    /**
     * In GitHub Actions, a failure also as an annotation (::error::) -- readable on the run's
     * page and through the API without the log, which needs a login.
     */
    function ciAnnotate(string $what, string $label, string $message): void
    {
        if (getenv('GITHUB_ACTIONS') !== 'true') {
            return;
        }
        $esc = static fn (string $s): string => str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $s);
        echo '::error title=' . $esc(str_replace([',', ':'], [';', ' '], "$what: $label")) . ' (PHP ' . PHP_VERSION . ')::' . $esc(substr($message, 0, 2000)) . "\n";
    }

    /**
     * Runs the test files; returns the exit code (0: all passed).
     *
     *   $self    the script that called it -- an isolated run starts it again per file (--file=<path>)
     *   $argv    its arguments: [filter], a part of "File > test name" or a feature id (RSF02-06, the group RSF02)
     *   $files   every *Test.php of the package
     *   $before  called with a test's name before it runs; may skip() it
     *
     * Isolated (in CI, or TESTS_ISOLATE=1): each file in a process of its own. A
     * crash of PHP itself (exit 139, a segfault) then names the file and the test
     * it was in -- as an annotation GitHub shows -- and the other files still run.
     *
     * @param list<string> $argv
     * @param list<string> $files
     * @param (callable(string): void)|null $before
     */
    function testkitRun(string $self, array $argv, array $files, ?callable $before = null): int
    {
        $args = array_slice($argv, 1);
        $only = null;                                   // --file=<path>: one file (a child of an isolated run)
        foreach ($args as $i => $a) {
            if (strncmp($a, '--file=', 7) === 0) {
                $only = substr($a, 7);
                unset($args[$i]);
            }
        }
        $filter = (string) (array_values($args)[0] ?? '');
        // A feature id is written RSF02-06 (the group: RSF02); another form names the right one.
        if (preg_match('/^RSF\d/', $filter) === 1 && preg_match('/^RSF\d{2}(-\d{2})?$/', $filter) !== 1) {
            fwrite(STDERR, basename(dirname($self)) . '/' . basename($self) . ": a feature id is written RSF02-06 (two digits each, a dash), the group RSF02 -- not \"$filter\"\n");
            return 2;
        }
        $pass = $fail = $skipped = 0;
        $isolate = $only === null && (getenv('TESTS_ISOLATE') === '1' || (getenv('GITHUB_ACTIONS') === 'true' && getenv('TESTS_ISOLATE') !== '0'));
        $tests = [];
        if ($isolate) {
            foreach ($files as $file) {
                $cmd = [PHP_BINARY, $self, '--file=' . $file];
                if ($filter !== '') {
                    $cmd[] = $filter;
                }
                // ['redirect', 1]: stderr into the same pipe (PHP 7.4+); the stubs know only pipe and file.
                $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes); // @phpstan-ignore argument.type
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
            foreach ($files as $f) {
                $set = require $f;
                // A file that returns no tests must not pass in silence.
                $sets[$f] = is_array($set) ? $set : ['(the file)' => static function () use ($f): void {
                    throw new TestFailure(basename($f) . ' returns no array of name => closure');
                }];
            }
            // The loaded tests by file, names as they run (some are made at load time) -- for the feature contract.
            $GLOBALS['RS_TEST_SETS'] = $sets;
            foreach ($only !== null ? [$only] : $files as $file) {
                $tests[$file] = $sets[$file] ?? $sets[realpath($file) ?: $file] ?? [];
            }
        }
        $byId = preg_match('/^RSF\d{2}(-\d{2})?$/', $filter) === 1;
        foreach ($tests as $file => $set) {
            foreach ($set as $name => $test) {
                $name = (string) $name;
                $label = basename($file, '.php') . ' > ' . $name;
                // A feature id (RSF02-06, or a group: RSF02) runs exactly that feature's tests, by
                // their names' beginning (0031 F.1); anything else is a part of "File > name".
                if ($filter !== '' && ($byId ? preg_match('/^' . preg_quote($filter, '/') . '[ -]/', $name) !== 1 : stripos($label, $filter) === false)) {
                    continue;
                }
                if ($only !== null) {
                    echo "@@START $name\n";                 // for the parent: where a crash happened
                }
                try {
                    if (!is_callable($test)) {
                        throw new TestFailure('not a closure');
                    }
                    if ($before !== null) {
                        $before($name);
                    }
                    $test();
                    $pass++;
                } catch (TestSkipped $e) {
                    $skipped++;
                    printf("  SKIP  %s\n        %s\n", $label, $e->getMessage());
                    if (getenv('TESTS_FAIL_ON_SKIP') === '1') {
                        ciAnnotate('skipped (TESTS_FAIL_ON_SKIP)', $label, $e->getMessage());
                    }
                } catch (Throwable $e) {
                    $fail++;
                    printf("  FAIL  %s\n        %s\n", $label, $e->getMessage());
                    ciAnnotate('failed', $label, $e->getMessage());
                }
            }
        }
        if ($only !== null) {
            echo "@@RESULT $pass $fail $skipped\n";        // the parent adds them up
            return 0;
        }
        // A feature id that matched no test proves nothing: a typo (RSF26) or a feature
        // without tests must not pass in silence.
        if ($byId && $pass + $fail + $skipped === 0) {
            echo "  FAIL  no test has the id $filter\n";
            $fail++;
        }
        // In CI a skipped test is a failure unless the job is meant to lack something
        // (TESTS_FAIL_ON_SKIP=1): a test that did not run proves nothing.
        if (getenv('TESTS_FAIL_ON_SKIP') === '1' && $skipped > 0) {
            $fail += $skipped;
            echo "  (TESTS_FAIL_ON_SKIP: skipped tests count as failures)\n";
        }
        printf("\n  %s - %d passed, %d failed, %d skipped (PHP %s)\n\n", $fail === 0 ? 'PASS' : 'FAIL', $pass, $fail, $skipped, PHP_VERSION);
        return $fail === 0 ? 0 : 1;
    }
}
