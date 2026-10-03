<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\Vocabulary;
use CjwNetwork\RequestShield\Stats\StatsExtension;

/**
 * The feature contract (0031 F.2, ADR 0011): a feature exists when all of its
 * parts are there under its id RSF<gg>-<nn> -- a page in docs/features, tests
 * whose names begin with the id, an end-to-end test for what lies on the
 * request path (groups 01 to 04), a "# demo:" group in the demo's rules with
 * a refusal and a near miss, and every rule word and set key it owns named in
 * Vocabulary::FEATURES. The docs index (docs/README.md) is the list of
 * features; a row without a page is either "planned:" or a gap below.
 *
 * FEATURE_GAPS holds what is missing today. It only shrinks: a gap that is
 * closed but still listed fails the test, so the list is always the truth.
 * Step F.6 empties it.
 */

const FEATURE_GAPS = [
    // A feature documented inside another page for now (rule-files.md, "The built-in rules").
    'page' => ['RSF02-02', 'RSF02-06'],
    // No "# demo:" group yet -- the markers come with F.3, the groups with F.6.
    'demo' => ['RSF01-01', 'RSF01-02', 'RSF01-03', 'RSF01-04', 'RSF02-01', 'RSF02-02', 'RSF02-03', 'RSF02-04', 'RSF02-05', 'RSF02-06',
        'RSF03-01', 'RSF03-02', 'RSF03-03', 'RSF03-04', 'RSF04-01', 'RSF05-01', 'RSF05-03', 'RSF05-04', 'RSF05-05', 'RSF06-01', 'RSF06-02', 'RSF06-03'],
    // On the request path, but no test of the id sits in a file that starts a server (many are
    // exercised by another feature's end-to-end test, not yet under their own id).
    'e2e' => ['RSF01-01', 'RSF01-04', 'RSF02-02', 'RSF02-03', 'RSF02-04', 'RSF02-05', 'RSF03-01', 'RSF03-03', 'RSF03-04', 'RSF04-01'],
];

/** The extensions' words belong to their feature. */
const EXTENSION_FEATURES = ['stats' => 'RSF06-03'];

/**
 * The docs index's features: id => ['page' => file or null, 'planned' => bool].
 *
 * @return array<string, array{page: ?string, planned: bool}>
 */
function contractFeatures(): array
{
    $out = [];
    preg_match_all('/^\s*\| (RSF\d{2}-\d{2}) \| [^|]+ \| (.+) \|$/m', (string) file_get_contents(dirname(__DIR__) . '/docs/README.md'), $m, PREG_SET_ORDER);
    foreach ($m as [, $id, $cell]) {
        $page = preg_match('#\(features/(RSF\d{2}-\d{2}-[a-z0-9-]+\.md)\)#', $cell, $p) === 1 ? $p[1] : null;
        $out[$id] = ['page' => $page, 'planned' => strncmp($cell, 'planned:', 8) === 0];
    }
    return $out;
}

/**
 * Per id, the test names that begin with it, and whether one of them sits in
 * a file that starts a server (an end-to-end test).
 *
 * @return array<string, array{tests: int, e2e: bool}>
 */
function contractTests(): array
{
    $out = [];
    foreach (glob(__DIR__ . '/*Test.php') ?: [] as $f) {
        $text = (string) file_get_contents($f);
        $server = strpos($text, '-S 127.0.0.1') !== false;
        preg_match_all("/^ *'(RSF\\d{2}-\\d{2}) /m", $text, $m);
        foreach ($m[1] as $id) {
            $out[$id]['tests'] = ($out[$id]['tests'] ?? 0) + 1;
            $out[$id]['e2e'] = ($out[$id]['e2e'] ?? false) || $server;
        }
    }
    return $out;
}

/**
 * The demo's groups: id => [the expect lines' outcomes].
 *
 * @return array<string, list<string>>
 */
function contractDemos(?string $file = null): array
{
    $out = [];
    $id = null;
    foreach (preg_split('/\r\n|\n/', (string) file_get_contents($file ?? dirname(__DIR__) . '/examples/demo/request-shield.rules')) ?: [] as $line) {
        if (preg_match('/^# demo: (RSF\d{2}-\d{2})(\s|$)/', $line, $m) === 1) {
            $id = $m[1];
            $out[$id] ??= [];
        } elseif ($id !== null && preg_match('/^\s*expect\s+\S+\s+\S+(.*)$/', $line, $m) === 1) {
            // The outcome, wherever it stands after the address (options first is fine):
            // quoted values and the options' arguments are no outcome.
            $rest = (string) preg_replace('/"(?:[^"\\\\]|\\\\.)*"|#.*$/', ' ', $m[1]);
            $rest = (string) preg_replace('/\b(by|from|times|header|ua)\s+\S+/i', ' ', $rest);
            if (preg_match('/(?:^|\s)(passes|uncached|answered|check|\d{3})(?=\s|$)/i', $rest, $o) === 1) {
                $out[$id][] = strtolower($o[1]);
            }
        }
    }
    return $out;
}

/** @return array<string, string> id => why, from examples/demo/.demo-exempt */
function contractDemoExempt(): array
{
    $out = [];
    foreach (file(dirname(__DIR__) . '/examples/demo/.demo-exempt', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^(RSF\d{2}-\d{2})\s+(\S.*)$/', $line, $m) === 1) {
            $out[$m[1]] = $m[2];
        }
    }
    return $out;
}

return [
    'RSF06-04 the contract: every feature in the index has its page, its tests and -- on the request path -- an end-to-end test; the gaps are listed and only shrink' => function (): void {
        $features = contractFeatures();
        $tests = contractTests();
        truthy(count($features) >= 25, 'the index lists the features: ' . count($features));
        $missing = ['page' => [], 'tests' => [], 'e2e' => []];
        foreach ($features as $id => $f) {
            if ($f['planned']) {
                same(null, $f['page'], "$id is planned and has no page yet");
                continue;
            }
            if ($f['page'] === null) {
                $missing['page'][] = $id;
            } else {
                truthy(is_file(dirname(__DIR__) . '/docs/features/' . $f['page']), "$id: its page {$f['page']} is there");
            }
            if (($tests[$id]['tests'] ?? 0) === 0) {
                $missing['tests'][] = $id;
            }
            if ((int) substr($id, 3, 2) <= 4 && !($tests[$id]['e2e'] ?? false)) {
                $missing['e2e'][] = $id;
            }
        }
        same(FEATURE_GAPS['page'], $missing['page'], 'features without a page of their own (FEATURE_GAPS[page] -- remove an id once its page is there)');
        same([], $missing['tests'], 'every feature has tests under its id');
        same(FEATURE_GAPS['e2e'], $missing['e2e'], 'request-path features without an end-to-end test (FEATURE_GAPS[e2e])');
    },
    'RSF06-04 the contract: every feature has a demo group with an effect and a near miss -- or a reason in .demo-exempt; the gaps only shrink' => function (): void {
        // The reading of a group, held to what the parser allows (any order after the address, a bare marker).
        $tmp = sys_get_temp_dir() . '/rs-contract-' . getmypid() . '.rules';
        file_put_contents($tmp, "# demo: RSF02-02\nexpect GET /backup.zip by SCAN-BACKUP 404\nexpect GET /a.php ua \"Mozilla 404 passes\" header X-A:1 passes   # 403 in a comment\n"
            . "# demo: RSF04-01 the cache\nexpect GET /x uncached\nexpect GET /y times 3 414\n");
        try {
            same(['RSF02-02' => ['404', 'passes'], 'RSF04-01' => ['uncached', '414']], contractDemos($tmp), 'the outcome anywhere after the address, a bare marker, quotes and options skipped');
        } finally {
            unlink($tmp);
        }
        $features = contractFeatures();
        $demos = contractDemos();
        $exempt = contractDemoExempt();
        $missing = [];
        foreach ($features as $id => $f) {
            if ($f['planned'] || isset($exempt[$id])) {
                continue;
            }
            // An effect (a status, the check, or "not for a cache" -- RSF04-01 refuses nothing)
            // and a near miss that passes.
            $outcomes = $demos[$id] ?? [];
            $effect = count(array_filter($outcomes, static fn (string $o): bool => $o === 'check' || $o === 'uncached' || ctype_digit($o)));
            $passes = count(array_filter($outcomes, static fn (string $o): bool => in_array($o, ['answered', 'passes'], true)));
            if ($effect === 0 || $passes === 0) {
                $missing[] = $id;
            }
        }
        same(FEATURE_GAPS['demo'], $missing, 'features without a demo group that shows a refusal and a near miss (FEATURE_GAPS[demo] -- remove an id once its group is there)');
        foreach ($exempt as $id => $why) {
            truthy(isset($features[$id]) && !$features[$id]['planned'], "$id in .demo-exempt is a feature of the index");
            truthy(!isset($demos[$id]), "$id is exempt and has a demo group all the same -- take it out of .demo-exempt");
        }
    },
    'RSF06-04 the contract: every rule word and set key belongs to a feature of the index; the extensions\' words to their extension\'s feature' => function (): void {
        $features = contractFeatures();
        $core = array_merge(RuleFile::coreWords(), RuleFile::coreSettings());
        same([], array_values(array_diff($core, array_keys(Vocabulary::FEATURES))), 'every core word and set key is in Vocabulary::FEATURES');
        same([], array_values(array_diff(array_keys(Vocabulary::FEATURES), $core)), 'Vocabulary::FEATURES names no word the core does not have');
        foreach (Vocabulary::FEATURES as $word => $id) {
            truthy(isset($features[$id]) && !$features[$id]['planned'], "$word: $id is a feature of the index, not a planned one");
        }
        foreach (Vocabulary::extensions() as $ext => $class) {
            truthy(isset(EXTENSION_FEATURES[$ext]) && isset($features[EXTENSION_FEATURES[$ext]]), "the extension $ext ($class) belongs to a feature of the index");
        }
        truthy(isset(Vocabulary::extensions()['stats']) && Vocabulary::extensions()['stats'] === StatsExtension::class, 'the statistics are the shipped extension');
    },
    'RSF06-04 the contract, the other way: every id named in a test, a demo group, .demo-exempt or the gap list is a feature of the index' => function (): void {
        $features = contractFeatures();
        $named = array_merge(array_keys(contractTests()), array_keys(contractDemos()), array_keys(contractDemoExempt()), ...array_values(FEATURE_GAPS));
        same([], array_values(array_unique(array_diff($named, array_keys($features)))), 'ids that are no feature of docs/README.md');
    },
];
