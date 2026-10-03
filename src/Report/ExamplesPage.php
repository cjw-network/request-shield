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
                $local = $r['url'][0] === '/';
                $link = $local ? $url($r['url']) : $r['url'];
                $h .= '<tr id="' . $id . '"' . ($r['kind'] === 'try' ? ' class="try-row"' : '') . '><td class="no"><a href="#' . $id . '">' . $e($r['n']) . '</a></td><td class="what">';
                if ($r['method'] === 'GET') {
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
        return $out . ($about !== [] ? ' — ' . implode(', ', $about) : '');
    }
}
