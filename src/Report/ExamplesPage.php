<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Report;

/**
 * The demo's rows as a table (0031 F.4), from its rule file's "# demo:"
 * groups (DemoSite::groups()): one group per feature, its explanation, one
 * numbered row per example -- a link (or a form, for a POST), what the rules
 * decide in words, "Show the answer" (decided on the server, DemoSite::answer())
 * and "See the path" (the active rules page's check). A "# try:" row is a link
 * with what to look at. Everything it embeds is escaped.
 *
 * @phpstan-import-type Group from DemoSite
 * @phpstan-import-type Row from DemoSite
 */
final class ExamplesPage
{
    /**
     * @param list<Group> $groups
     * @param callable(string): string $url the demo's address of one of its paths
     * @param callable(string, string): string $pathOf the rules page's check of a path and method
     * @param string $answer the address that answers a row (?n=3.2)
     */
    public static function render(array $groups, callable $url, callable $pathOf, string $answer): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $h = '<table class="tests">';
        foreach ($groups as $g) {
            $h .= '<tbody id="g' . $g['n'] . '"><tr class="group"><th colspan="4">' . $g['n'] . ' · ' . $e($g['title'] !== '' ? $g['title'] : $g['id'])
                . ' <span class="feature">' . $e($g['id']) . '</span></th></tr>';
            if ($g['about'] !== []) {
                $h .= '<tr class="about"><td></td><td colspan="3">' . $e(trim(implode(' ', array_map('trim', $g['about'])))) . '</td></tr>';
            }
            foreach ($g['rows'] as $r) {
                $id = 't' . str_replace('.', '-', $r['n']);
                $what = $r['text'] !== '' ? $r['text'] : $r['method'] . ' ' . $r['url'];
                $local = $r['url'][0] === '/' && $r['site'] === null;
                $link = $r['site'] !== null && $r['url'][0] === '/' ? 'https://' . $r['site'] . $r['url'] : ($local ? $url($r['url']) : $r['url']);
                $h .= '<tr id="' . $id . '"' . ($r['kind'] === 'try' ? ' class="try-row"' : '') . '><td class="no"><a href="#' . $id . '">' . $e($r['n']) . '</a></td><td class="what">';
                if ($r['site'] !== null) {
                    // Another website's row (a site block): no address to click here -- "Show the answer" decides it.
                    $h .= '<strong>' . $e($what) . '</strong>';
                } elseif ($r['method'] === 'GET') {
                    $h .= '<a href="' . $e($link) . '">' . $e($what) . '</a>';
                } else {
                    $h .= '<form method="post" action="' . $e($link) . '" class="inline"><input type="hidden" name="message" value="test ' . $e($r['n']) . '">'
                        . '<button type="submit" class="linkish">' . $e($what) . '</button></form>';
                }
                $h .= '<br><code>' . ($r['method'] !== 'GET' ? $e($r['method']) . ' ' : '') . $e($r['url']) . '</code></td>';
                $h .= '<td class="expect">' . $e(self::expected($r)) . '</td><td class="try">';
                if ($r['kind'] === 'expect') {
                    $h .= '<button type="button" class="peek" data-answer="' . $e($answer . (strpos($answer, '?') === false ? '?' : '&') . 'n=' . $r['n']) . '">Show the answer</button>';
                }
                if ($local) {
                    $h .= '<a class="path" href="' . $e($pathOf($r['url'], $r['method'])) . '">See the path →</a>';
                }
                $h .= '</td></tr><tr class="answer-row" hidden><td></td><td colspan="3"><pre class="answer"></pre></td></tr>';
            }
            $h .= '</tbody>';
        }
        return $h . '</table>';
    }

    /**
     * The groups as Markdown tables, for the docs (`request-shield examples
     * --markdown`, docs/tools/sync-examples.php): per group its title, its
     * explanation and a table -- the request, what the rules decide, the row's
     * comment. $only: one feature's groups.
     *
     * @param list<Group> $groups
     */
    public static function markdown(array $groups, ?string $only = null): string
    {
        $cell = static fn (string $s): string => str_replace(['|', "\n"], ['\\|', ' '], $s);
        $out = [];
        foreach ($groups as $g) {
            if ($only !== null && $g['id'] !== $only) {
                continue;
            }
            $md = '**' . $g['id'] . ($g['title'] !== '' ? ' · ' . $g['title'] : '') . "**\n\n";
            if ($g['about'] !== []) {
                $md .= trim(implode(' ', array_map('trim', $g['about']))) . "\n\n";
            }
            $md .= "| Request | The rules decide | |\n|---|---|---|\n";
            foreach ($g['rows'] as $r) {
                $md .= '| `' . $cell(($r['method'] !== 'GET' ? $r['method'] . ' ' : '') . $r['url']) . '` | ' . $cell(self::expected($r)) . ' | ' . $cell($r['text']) . " |\n";
            }
            $out[] = $md;
        }
        return implode("\n", $out);
    }

    /**
     * The groups as one page that needs no server: each row with what
     * `request-shield test` decided for it, recorded ($results by the line it
     * is written at) -- the demo for a static host (`request-shield examples
     * --html`).
     *
     * @param list<Group> $groups
     * @param array<string, array{status: string, got: string, gotRule: ?string, http: int}> $results by "file:line"
     */
    public static function html(array $groups, array $results, string $title, string $recorded): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $h = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($title) . '</title>'
            . '<style>body{margin:0;font:16px/1.55 system-ui,sans-serif;background:#f6f7f9;color:#1d2127}main{max-width:60rem;margin:0 auto;padding:1.2rem 1rem 3rem}'
            . 'h1{font-size:1.5rem}h2{font-size:1.1rem;margin:2rem 0 .3rem}p.about,p.note{color:#5b6470}table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #dfe3e8}'
            . 'td,th{padding:.45rem .6rem;border-top:1px solid #dfe3e8;text-align:left;vertical-align:top}code{font:13px ui-monospace,monospace}.ok{color:#1e7b43;font-weight:600}.no{color:#a3361f;font-weight:600}'
            . '@media (prefers-color-scheme:dark){body{background:#15181c;color:#e7e9ec}table{background:#1d2127;border-color:#2d333b}td,th{border-color:#2d333b}p.about,p.note{color:#a0a8b3}}</style></head><body><main>'
            . '<h1>' . $e($title) . '</h1><p class="note">' . $e($recorded) . '</p>';
        foreach ($groups as $g) {
            $h .= '<h2 id="' . $e($g['id']) . '">' . $g['n'] . ' · ' . $e($g['title'] !== '' ? $g['title'] : $g['id']) . ' <small>' . $e($g['id']) . '</small></h2>';
            if ($g['about'] !== []) {
                $h .= '<p class="about">' . $e(trim(implode(' ', array_map('trim', $g['about'])))) . '</p>';
            }
            $h .= '<table><tr><th>#</th><th>Request</th><th>The rules decide</th><th>Recorded</th></tr>';
            foreach ($g['rows'] as $r) {
                $res = $results[$r['at']] ?? null;
                $mark = ['pass' => '<span class="ok">✓</span> ', 'skip' => '– skipped: '][$res['status'] ?? ''] ?? '<span class="no">✕</span> ';
                $rec = $r['kind'] === 'try' ? 'to look at in the live demo' : ($res === null ? '—' : $mark
                    . $e('HTTP ' . $res['http'] . ', ' . $res['got'] . ($res['gotRule'] !== null ? ' by ' . $res['gotRule'] : '')));
                $h .= '<tr><td>' . $e($r['n']) . '</td><td><code>' . $e(($r['method'] !== 'GET' ? $r['method'] . ' ' : '') . $r['url']) . '</code>'
                    . ($r['text'] !== '' ? '<br>' . $e($r['text']) : '') . '</td><td>' . $e(self::expected($r)) . '</td><td>' . $rec . '</td></tr>';
            }
            $h .= '</table>';
        }
        return $h . '</main></body></html>' . "\n";
    }

    /**
     * What a row shows in words: what the rules decide, the rule, and what is
     * different from a click on this page (another address, a pass, a count).
     *
     * @param Row $r
     */
    public static function expected(array $r): string
    {
        if ($r['kind'] === 'try') {
            return 'look at it';
        }
        $words = ['passes' => 'passes; a cache may keep it', 'uncached' => 'passes, but a cache must not keep it', 'answered' => 'the site answers it',
            'check' => 'the invisible browser check', '400' => 'a broken request (400)', '403' => 'no access (403)', '404' => '"not found" (404) — the site never sees it',
            '405' => '"not allowed here" (405)', '429' => 'wait (429)'];
        $outcome = (string) $r['outcome'];
        $out = $words[$outcome] ?? "refused ($outcome)";
        if ($r['by'] !== null) {
            $out .= ' · rule ' . $r['by'];
        }
        $about = [];
        if ($r['site'] !== null) {
            $about[] = 'on the website ' . $r['site'];
        }
        if ($r['from'] !== null && $r['from'] !== DemoSite::FROM) {
            $about[] = 'from ' . $r['from'];
        } elseif ($r['from'] === DemoSite::FROM) {
            $about[] = 'from another address (' . DemoSite::FROM . ')';
        }
        if ($r['pass']) {
            $about[] = 'with a pass';
        }
        if ($r['times'] > 1) {
            $about[] = $r['times'] . ' times in a row';
        }
        if ($r['ua'] !== null) {
            $about[] = 'as "' . $r['ua'] . '"';
        }
        foreach ($r['headers'] as $name => $value) {
            // The headers are what the example is about as often as not (X-Forwarded-For, Origin).
            $about[] = 'with ' . str_replace(' ', '-', ucwords(str_replace('-', ' ', (string) $name))) . ': ' . $value;
        }
        return $out . ($about !== [] ? ' — ' . implode(', ', $about) : '');
    }
}
