<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Rules\Reference;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\Vocabulary;

/**
 * The reference and the examples from one source (0031 F.5): Rules\Reference
 * holds every word and set key in words, docs/tools/gen-reference.php writes
 * the tables and docs/reference/* from it, docs/tools/sync-examples.php the
 * demo's tables into the feature pages; `vocabulary` and `examples` print the
 * same. The files must be what the tools write (--check).
 */

/** @return array{0: string, 1: int} what a PHP script printed (stderr too), and its exit code */
function refRun(string $script, string $args = ''): array
{
    if (!function_exists('exec')) {
        skip('no exec');
    }
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ($args !== '' ? ' ' . $args : '') . ' 2>&1', $out, $code);
    return [implode("\n", $out), $code];
}

return [
    'RSF05-01 the reference: every word and set key of the core and of the shipped extension has its row -- no row for a word that is not there' => function (): void {
        needsPlugins();         // the shipped plugins' pages (the WAF's, the statistics'): not in the core single file
        $words = [];
        foreach (Reference::RULES as $r) {
            $words = array_merge($words, $r['words']);
            truthy($r['syntax'] !== '' && $r['about'] !== '', 'a row says how it is written and what it does: ' . implode(', ', $r['words']));
        }
        $keys = [];
        foreach (Reference::SETTINGS as $r) {
            $keys = array_merge($keys, $r['keys']);
        }
        // Every shipped extension offered, whatever the tests before left behind: the reference covers them all.
        Vocabulary::reset();
        $known = Vocabulary::known();
        same([], array_values(array_diff(RuleFile::coreWords(), $words)), 'the core\'s words');
        same([], array_values(array_diff(RuleFile::coreSettings(), $keys)), 'the core\'s set keys');
        same([], array_values(array_diff($known['words'], $words)), 'the extension\'s words');
        same([], array_values(array_diff($known['settings'], $keys)), 'the extension\'s set keys');
        same([], array_values(array_diff($words, RuleFile::coreWords(), $known['words'])), 'no row for a word the rule file does not know');
        same([], array_values(array_diff($keys, RuleFile::coreSettings(), $known['settings'], ['text.<key>', 'text.<lang>.<key>'])), 'no row for a key it does not know');
    },
    'RSF05-01 the docs are what the tools write: the rule files page\'s tables and docs/reference (gen-reference), the feature pages\' examples (sync-examples)' => function (): void {
        [$out, $code] = refRun(dirname(__DIR__) . '/docs/tools/gen-reference.php', '--check');
        same(0, $code, $out);
        [$out, $code] = refRun(dirname(__DIR__) . '/docs/tools/sync-examples.php', '--check');
        same(0, $code, $out);
        foreach (['rule-files.md', 'settings.md', 'cli.md'] as $f) {
            truthy(strpos((string) file_get_contents(dirname(__DIR__) . "/docs/reference/$f"), 'Written by docs/tools/gen-reference.php') !== false, "docs/reference/$f says where it comes from");
        }
        truthy(strpos((string) file_get_contents(dirname(__DIR__) . '/docs/reference/rule-files.md'), '| `restrict <paths> to <addresses or ranges>` | `restricted` | [RSF02-03](../features/RSF02-03-access-rules.md) |') !== false, 'a rule with its feature, linked');
    },
    'RSF05-01 request-shield vocabulary: every rule and set key with its feature; --json with what each does' => function (): void {
        [$out, $code] = refRun(rsCli(), 'vocabulary');
        same(0, $code, $out);
        truthy(preg_match('/^restrict <paths> to <addresses or ranges>\s+RSF02-03$/m', $out) === 1 && preg_match('/^set mode\s+RSF05-03$/m', $out) === 1, $out);
        [$json, $code] = refRun(rsCli(), 'vocabulary --json');
        $v = json_decode($json, true);
        truthy($code === 0 && is_array($v) && count($v['rules']) === count(Reference::RULES) && ($v['features']['stats-skip'] ?? null) === 'RSF06-03', substr($json, 0, 200));
    },
    'RSF05-04 request-shield examples: the demo groups as Markdown, as a page recorded by test, and what is covered' => function (): void {
        needsPlugins();         // the shipped plugins' pages (the WAF's, the statistics'): not in the core single file
        $demo = escapeshellarg(dirname(__DIR__) . '/examples/demo/request-shield.rules');
        [$md, $code] = refRun(rsCli(), "examples $demo --markdown --feature=RSF02-03");
        same(0, $code, $md);
        truthy(strpos($md, '**RSF02-03 · Doors for certain people**') === 0 && strpos($md, '| `/admin/` | no access (403) · rule DEMO-ADMIN') !== false && strpos($md, 'RSF04-01') === false, 'one feature\'s table: ' . substr($md, 0, 300));
        [$html, $code] = refRun(rsCli(), "examples $demo --html");
        same(0, $code, substr($html, 0, 200));
        $groups = \CjwNetwork\RequestShield\Waf\DemoSite::groups(dirname(__DIR__) . '/examples/demo/request-shield.rules');
        $expects = 0;
        foreach ($groups as $g) {
            truthy(strpos($html, '<h2 id="' . $g['id'] . '">') !== false, "the page has {$g['id']}");
            $expects += count(array_filter($g['rows'], static fn (array $r): bool => $r['kind'] === 'expect'));
        }
        same($expects, substr_count($html, '<span class="ok">✓</span>'), 'every example recorded as passing');
        truthy(strpos($html, 'Recorded by request-shield test') !== false && strpos($html, '<script') === false, 'says how it was made; no script: a static page');
        [$cov, $code] = refRun(rsCli(), "examples $demo --coverage");
        same(0, $code, $cov);
        truthy(preg_match('/^RSF02-03\s+7 examples, 7 pass, 0 to look at/m', $cov) === 1 && strpos($cov, 'rule(s) without an example') !== false, $cov);
        [$none, $code] = refRun(rsCli(), "examples $demo --markdown --feature=RSF2-03");
        same([2, true], [$code, strpos($none, 'no "# demo: RSF2-03" group') !== false], 'an id without a group is a mistake, not an empty table');
        [$one, $code] = refRun(rsCli(), "examples $demo --coverage --feature=RSF02-03");
        same([0, 1], [$code, preg_match_all('/^RSF\d{2}-\d{2} /m', $one)], '--feature narrows --coverage too');
        [$usage, $code] = refRun(rsCli(), "examples $demo");
        same([2, true], [$code, strpos($usage, '--markdown') !== false], 'without a form: the usage');
    },
];
