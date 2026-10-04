<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Report;

use CjwNetwork\RequestShield\Responder;
use CjwNetwork\RequestShield\Rules\Examples;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Store\Store;

/**
 * The base a demo site stands on (0031 F.4): its rows -- read from its rule
 * file's "# demo:" groups, not from a PHP table -- and the answer to a row,
 * decided here. The demo's own pages stay the demo's (where it lives is
 * worked out in its index.php, before the shield's classes are loaded).
 *
 * The answer is decided in the same process with the live settings and the
 * live store, nothing counted (Inspector::trace()): a request to the demo's
 * own address from inside a request would never be answered by PHP's built-in
 * server, which serves one request at a time.
 *
 * @phpstan-type Row array{n: string, kind: string, method: string, url: string, outcome: ?string, by: ?string, from: ?string, pass: bool, times: int, ua: ?string, headers: array<string, string>, text: string, at: string, site: ?string}
 * @phpstan-type Group array{n: int, id: string, slug: string, title: string, about: list<string>, rows: list<Row>}
 */
final class DemoSite
{
    /** Where the examples come from when they name no address: a documentation range, as in request-shield test. */
    public const FROM = RuleFile::EXAMPLE_FROM;

    /**
     * The demo's groups, numbered for the page: each "# demo:" group of the
     * rule file with its explanation and its rows -- the expect lines and the
     * "# try:" rows, in the order they are written.
     *
     * @return list<Group>
     */
    public static function groups(string $rulesFile): array
    {
        $read = RuleFile::read([$rulesFile]);
        $base = basename($rulesFile) . ':';
        // The base's examples, then each site block's (as request-shield test reads them): an
        // example inside a site block belongs to that website and is decided with its rules.
        $examples = [];
        foreach ($read['examples'] as $x) {
            $examples[$x['at']] = $x;
        }
        foreach ((array) ($read['config']['sites'] ?? []) as $site) {
            foreach (is_string($site) ? RuleFile::read([$rulesFile], $site)['examples'] : [] as $x) {
                if ($x['site'] !== null) {
                    $examples[$x['at']] = $x;
                }
            }
        }
        $rows = [];
        foreach ($examples as $x) {
            if ($x['demo'] !== null && strncmp($x['at'], $base, strlen($base)) === 0) {
                $rows[] = ['demo' => $x['demo'], 'line' => (int) substr($x['at'], strlen($base)), 'row' => ['kind' => 'expect', 'method' => $x['method'], 'url' => $x['url'],
                    'outcome' => $x['outcome'], 'by' => $x['by'], 'from' => $x['from'], 'pass' => $x['pass'], 'times' => $x['times'], 'ua' => $x['ua'], 'headers' => $x['headers'],
                    'text' => (string) $x['text'], 'at' => $x['at'], 'site' => $x['site']]];
            }
        }
        foreach ($read['tries'] as $t) {
            if ($t['demo'] !== null && strncmp($t['at'], $base, strlen($base)) === 0) {
                $rows[] = ['demo' => $t['demo'], 'line' => (int) substr($t['at'], strlen($base)), 'row' => ['kind' => 'try', 'method' => $t['method'], 'url' => $t['url'],
                    'outcome' => null, 'by' => null, 'from' => null, 'pass' => false, 'times' => 1, 'ua' => null, 'headers' => [], 'text' => $t['text'], 'at' => $t['at'], 'site' => null]];
            }
        }
        usort($rows, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);
        $out = [];
        foreach ($read['demos'] as $i => $g) {
            $start = (int) substr($g['at'], strlen($base));
            $end = isset($read['demos'][$i + 1]) ? (int) substr($read['demos'][$i + 1]['at'], strlen($base)) : PHP_INT_MAX;
            $mine = [];
            foreach ($rows as $r) {
                if ($r['demo'] === $g['id'] && $r['line'] > $start && $r['line'] < $end) {
                    $mine[] = ['n' => ($i + 1) . '.' . (count($mine) + 1)] + $r['row'];
                }
            }
            $out[] = ['n' => $i + 1, 'id' => $g['id'], 'slug' => $g['slug'], 'title' => $g['title'], 'about' => $g['about'], 'rows' => $mine];
        }
        return $out;
    }

    /**
     * A row by its number ("3.2"), or null.
     *
     * @param list<Group> $groups
     * @return Row|null
     */
    public static function row(array $groups, string $n): ?array
    {
        foreach ($groups as $g) {
            foreach ($g['rows'] as $r) {
                if ($r['n'] === $n) {
                    return $r;
                }
            }
        }
        return null;
    }

    /**
     * What a row's request gets, decided now with the live settings and store
     * (nothing counted) -- for a row of a site block, $s is that website's: the outcome as an expect line writes it, the status
     * and the headers the visitor would get, the verdict in words, the rule,
     * and the steps.
     *
     * @param Row $row
     * @return array{outcome: string, status: int, headers: list<string>, verdict: string, rule: ?string, watched: ?string, steps: list<string>, from: string}
     */
    public static function answer(Settings $s, ?Store $store, array $row, string $front, string $host): array
    {
        $from = $row['from'] ?? self::FROM;
        if ($row['times'] > 1 || $row['pass']) {
            // A count or a pass: decided as request-shield test does, on a fresh store
            // (the live one would carry this visitor's own counters).
            $x = ['method' => $row['method'], 'url' => $row['url'], 'outcome' => (string) $row['outcome'], 'by' => $row['by'], 'rule' => null, 'from' => $from,
                'pass' => $row['pass'], 'times' => $row['times'], 'headers' => $row['headers'], 'text' => null, 'at' => $row['at'], 'site' => $row['site'], 'ua' => $row['ua'], 'demo' => null];
            $r = Examples::one($s, $x);
            $how = ($row['pass'] ? 'with a pass' : '') . ($row['pass'] && $row['times'] > 1 ? ', ' : '') . ($row['times'] > 1 ? $row['times'] . ' requests in a row' : '');
            return ['outcome' => $r['got'], 'status' => $r['http'], 'headers' => $r['headers'], 'verdict' => ExamplesPage::expected(['outcome' => $r['got'], 'by' => null, 'from' => null, 'pass' => false, 'times' => 1, 'ua' => null] + $row),
                'rule' => $r['gotRule'], 'watched' => null, 'steps' => ["decided on a fresh store, $how -- as request-shield test does"], 'from' => $from];
        }
        $headers = $row['headers'];
        if ($row['ua'] !== null) {
            $headers['user-agent'] = $row['ua'];
        }
        // A row of a site block: that website's address (its rules are in $s, see the caller).
        $url = $row['url'][0] === '/' ? 'http://' . ($row['site'] ?? $host) . $front . $row['url'] : $row['url'];
        $t = (new Inspector($s, $store))->trace(Inspector::request($row['method'], $url, $from, $headers, $s->trustedProxies));
        $d = $t['decision'];
        $steps = [];
        foreach ($t['steps'] as $step) {
            $steps[] = (['stop' => '✕ ', 'skip' => '– ', 'note' => '! '][$step['state']] ?? '✓ ') . $step['check'] . ': ' . $step['text'] . ($step['rule'] !== null ? ' [' . $step['rule'] . ']' : '');
        }
        return ['outcome' => Examples::outcome($d), 'status' => $d->passes() ? 200 : $d->status, 'headers' => $d->passes() ? [] : Responder::headerLines($d),
            'verdict' => $t['verdict'], 'rule' => $t['rule'], 'watched' => $t['watched'], 'steps' => $steps, 'from' => $from];
    }
}
