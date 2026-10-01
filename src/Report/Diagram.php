<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Report;

use CjwNetwork\RequestShield\Decision;

/**
 * Diagrams as SVG, drawn here -- no library, no external file: the path of a
 * request through the checks (Inspector::trace()), the browser check step by
 * step, and how the shield sits in front of a site. Light and dark.
 */
final class Diagram
{
    /** Short names for the checks, to fit under a circle. */
    private const SHORT = [
        'Kept out' => 'Denied', 'Banned' => 'Banned', 'Kind of request' => 'Kind', 'Size' => 'Size', 'Disguised address' => 'Disguise', 'Website name' => 'Name',
        'Addresses only attackers ask for' => 'Blocked', 'Where forms may be sent' => 'Forms',
        'Areas for certain visitors' => 'Areas', 'Known crawlers' => 'Crawlers', 'Known parameters' => 'Params', 'Attack patterns' => 'Attacks', 'May a cache keep the answer?' => 'Cache', 'Browser check' => 'Check',
    ];

    /** The same in German, and the diagram's other words. */
    private const SHORT_DE = [
        'Denied' => 'Sperrliste', 'Banned' => 'Sperre', 'Kind' => 'Art', 'Size' => 'Größe', 'Disguise' => 'Tarnung', 'Name' => 'Name', 'Blocked' => 'Gesperrt', 'Forms' => 'Formulare', 'Areas' => 'Bereiche',
        'Crawlers' => 'Crawler', 'Params' => 'Parameter', 'Attacks' => 'Angriffe', 'Cache' => 'Cache', 'Check' => 'Check', 'Pace' => 'Tempo',
        'Request' => 'Anfrage', 'Your site' => 'Ihre Website', 'a cache may keep it' => 'ein Cache darf sie behalten', 'not kept in a cache' => 'nicht im Cache',
        'Browser check' => 'Browser-Check', 'Please wait' => 'Bitte warten', 'the site never sees it' => 'die Website sieht sie nie',
    ];

    private const STYLE = '<style>'
        . '.rsd{font:13px/1.2 system-ui,sans-serif}.rsd .t{fill:#1d2127}.rsd .m{fill:#5b6470}.rsd .box{fill:#fff;stroke:#c9ced6}'
        . '.rsd .line{stroke:#c9ced6;stroke-width:3;fill:none}.rsd .go{stroke:#1e7b43}.rsd .arrow{stroke:#5b6470;stroke-width:1.6;fill:none}'
        . '.rsd .pass{fill:#1e7b43}.rsd .note{fill:#b07400}.rsd .stop{fill:#a3361f}.rsd .skip{fill:#b5bcc5}'
        . '.rsd .okbox{fill:#e6f4ea;stroke:#1e7b43}.rsd .warnbox{fill:#fdf3dc;stroke:#b07400}.rsd .nobox{fill:#fbe9e5;stroke:#a3361f}'
        . '.rsd .lane{stroke:#c9ced6;stroke-dasharray:4 4}.rsd .w{fill:#fff;font-weight:700}'
        . '@media (prefers-color-scheme:dark){.rsd .t{fill:#e7e9ec}.rsd .m{fill:#a0a8b3}.rsd .box{fill:#1d2127;stroke:#3a414b}'
        . '.rsd .line,.rsd .lane{stroke:#3a414b}.rsd .arrow{stroke:#a0a8b3}.rsd .okbox{fill:#17301f}.rsd .warnbox{fill:#3a2f15}.rsd .nobox{fill:#3d1f19}.rsd .skip{fill:#555c66}}'
        . '</style>';

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    }

    /**
     * A request's path: the request, a circle per check, and where it ends --
     * the site, or the answer the shield gives itself.
     *
     * @param array{steps: list<array{check: string, key?: string, state: string, text: string, rule: ?string}>, decision: Decision, verdict: string, rule: ?string} $trace
     */
    public static function trace(array $trace, string $request, string $lang = 'en'): string
    {
        $tr = static fn (string $en): string => $lang === 'de' ? (self::SHORT_DE[$en] ?? $en) : $en;
        $steps = $trace['steps'];
        $d = $trace['decision'];
        $gap = 76;
        $x0 = 150;
        $w = $x0 + count($steps) * $gap + 170;
        $y = 60;
        $h = '<svg class="rsd" role="img" viewBox="0 0 ' . $w . ' 130" width="100%" xmlns="http://www.w3.org/2000/svg">'
            . '<title>' . self::e($request . ': ' . $trace['verdict']) . '</title>' . self::STYLE;
        // The request
        $h .= '<rect class="box" x="4" y="' . ($y - 24) . '" width="118" height="48" rx="8"/>'
            . '<text class="t" x="63" y="' . ($y - 4) . '" text-anchor="middle" font-weight="600">' . self::e($tr('Request')) . '</text>'
            . '<text class="m" x="63" y="' . ($y + 13) . '" text-anchor="middle" font-size="11">' . self::e(self::cut($request, 18)) . '</text>';
        // The line: green as far as the request got, grey after
        $stopAt = null;
        foreach ($steps as $i => $s) {
            if ($s['state'] === 'stop') {
                $stopAt = $i;
                break;
            }
        }
        $lastX = $x0 + (count($steps) - 1) * $gap;
        $goX = $stopAt === null ? $lastX + 60 : $x0 + $stopAt * $gap;
        $h .= '<path class="line" d="M122 ' . $y . ' H' . ($lastX + 60) . '"/>'
            . '<path class="line go" d="M122 ' . $y . ' H' . $goX . '"/>';
        foreach ($steps as $i => $s) {
            $cx = $x0 + $i * $gap;
            $key = $s['key'] ?? $s['check'];              // the step's English name
            $label = isset(self::SHORT[$key]) ? $tr(self::SHORT[$key]) : (strncmp($key, 'Pace', 4) === 0 ? $tr('Pace') : self::cut($s['check'], 9));
            $mark = ['pass' => '✓', 'note' => '!', 'stop' => '✕', 'skip' => '–'][$s['state']] ?? '';
            $h .= '<g><title>' . self::e($s['check'] . ': ' . $s['text'] . ($s['rule'] !== null ? ' (' . $s['rule'] . ')' : '')) . '</title>'
                . '<circle class="' . $s['state'] . '" cx="' . $cx . '" cy="' . $y . '" r="16"/>'
                . '<text class="w" x="' . $cx . '" y="' . ($y + 5) . '" text-anchor="middle">' . $mark . '</text>'
                . '<text class="' . ($s['state'] === 'skip' ? 'm' : 't') . '" x="' . $cx . '" y="' . ($y + 38) . '" text-anchor="middle" font-size="12">' . self::e($label) . '</text></g>';
        }
        // Where it ends
        $ex = $lastX + 60;
        if ($d->passes()) {
            $class = $d->action === Decision::ALLOW ? 'okbox' : 'warnbox';
            $title = $tr('Your site');
            $sub = $tr($d->action === Decision::ALLOW ? 'a cache may keep it' : 'not kept in a cache');
        } else {
            $class = $d->action === Decision::CHALLENGE ? 'warnbox' : 'nobox';
            $title = $d->action === Decision::CHALLENGE ? $tr('Browser check') : ($d->action === Decision::THROTTLE ? $tr('Please wait') : (string) $d->status);
            $sub = $tr('the site never sees it');
            if ($stopAt !== null) {
                // From the refusing check down to the shield's own answer
                $sx = $x0 + $stopAt * $gap;
                $h .= '<path class="arrow" d="M' . $sx . ' ' . ($y + 18) . ' V112 H' . ($ex + 75) . ' V' . ($y + 30) . '" marker-end="url(#rsd-a)"/>';
            }
        }
        $h .= '<defs><marker id="rsd-a" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M0 0 L10 5 L0 10z" class="m"/></marker></defs>';
        $h .= '<rect class="' . $class . '" x="' . $ex . '" y="' . ($y - 26) . '" width="150" height="52" rx="8" stroke-width="1.5"/>'
            . '<text class="t" x="' . ($ex + 75) . '" y="' . ($y - 4) . '" text-anchor="middle" font-weight="700">' . self::e($title) . '</text>'
            . '<text class="m" x="' . ($ex + 75) . '" y="' . ($y + 14) . '" text-anchor="middle" font-size="11">' . self::e($sub) . '</text>';
        return $h . '</svg>';
    }

    /**
     * How the shield is set up, as one picture: the request, a circle per
     * check (coloured: on, grey: off; each a link to its line below), the site
     * or the shield's own answer, and what comes after -- the log and the
     * statistics. A dot runs along the checks (not with reduced motion).
     *
     * @param list<array{label: string, name: string, on: bool, what: string, stops?: bool, feeds?: bool}> $steps stops: it can answer itself (a line down to the answer); feeds: the statistics use what it found (crawlers)
     * @param array{request: string, before: string, site: string, siteSub: string, answer: string, answerSub: string, after: string, lines: list<string>, feeds: string} $words
     */
    public static function setup(array $steps, array $words): string
    {
        $gap = 64;
        $x0 = 170;
        $y = 50;
        $last = $x0 + (count($steps) - 1) * $gap;
        $ex = $last + 46;
        $w = $ex + 196;
        $bottom = 214;
        $h = '<svg class="rsd setup" role="img" viewBox="0 0 ' . $w . ' ' . ($bottom + 30 + 16 * count($words['lines'])) . '" width="100%" xmlns="http://www.w3.org/2000/svg">'
            . '<title>' . self::e($words['request'] . ' → ' . $words['site']) . '</title>' . self::STYLE
            . '<style>.rsd .off{fill:#b5bcc5}.rsd .on{fill:#2f62c9}.rsd a:hover circle,.rsd a:focus circle{stroke:#1d2127;stroke-width:3}.rsd .run{fill:#1e7b43}'
            . '.rsd.setup .lane{fill:none}.rsd .feed{stroke:#8b5cf6;stroke-dasharray:3 4;fill:none;stroke-width:1.6}.rsd .fl{fill:#8b5cf6}@media (prefers-reduced-motion:reduce){.rsd .run{display:none}}</style>'
            . '<defs><marker id="rsd-s" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M0 0 L10 5 L0 10z" class="m"/></marker></defs>';
        // The request, where the visitor's address is worked out ("before").
        $h .= '<rect class="box" x="4" y="' . ($y - 26) . '" width="136" height="52" rx="8"/>'
            . '<text class="t" x="72" y="' . ($y - 6) . '" text-anchor="middle" font-weight="600">' . self::e($words['request']) . '</text>'
            . '<text class="m" x="72" y="' . ($y + 12) . '" text-anchor="middle" font-size="11">' . self::e(self::cut($words['before'], 22)) . '</text>';
        // The track, the way on to the site, and the way down to the shield's answer.
        $h .= '<path class="line" d="M140 ' . $y . ' H' . $ex . '"/><path class="lane" d="M' . $x0 . ' ' . ($y + 62) . ' H' . ($ex + 85) . ' V' . ($y + 90) . '" marker-end="url(#rsd-s)"/>';
        $feedX = null;
        foreach ($steps as $i => $s) {
            $cx = $x0 + $i * $gap;
            if ($s['on'] && ($s['stops'] ?? true)) {
                $h .= '<path class="lane" d="M' . $cx . ' ' . ($y + 17) . ' V' . ($y + 62) . '"/>';
            }
            if ($s['feeds'] ?? false) {
                $feedX = $cx;
            }
            $h .= '<a href="#step-' . ($i + 1) . '"><title>' . self::e($s['name'] . ': ' . $s['what']) . '</title>'
                . '<circle class="' . ($s['on'] ? 'on' : 'off') . '" cx="' . $cx . '" cy="' . $y . '" r="15"/>'
                . '<text class="w" x="' . $cx . '" y="' . ($y + 5) . '" text-anchor="middle" font-size="12">' . ($i + 1) . '</text>'
                . '<text class="' . ($s['on'] ? 't' : 'm') . '" x="' . $cx . '" y="' . ($y + 33) . '" text-anchor="middle" font-size="11">' . self::e(self::cut($s['label'], 10)) . '</text></a>';
        }
        // A dot runs along the checks to the site: the way of every request.
        $h .= '<circle class="run" r="5" cy="0" cx="0"><animateMotion dur="7s" repeatCount="indefinite" path="M140 ' . $y . ' H' . $ex . '"/></circle>';
        // Where it ends: the site, or the shield's own answer.
        $h .= '<rect class="okbox" x="' . $ex . '" y="' . ($y - 26) . '" width="170" height="52" rx="8" stroke-width="1.5"/>'
            . '<text class="t" x="' . ($ex + 85) . '" y="' . ($y - 5) . '" text-anchor="middle" font-weight="700">' . self::e($words['site']) . '</text>'
            . '<text class="m" x="' . ($ex + 85) . '" y="' . ($y + 13) . '" text-anchor="middle" font-size="11">' . self::e($words['siteSub']) . '</text>'
            . '<rect class="nobox" x="' . $ex . '" y="' . ($y + 92) . '" width="170" height="52" rx="8" stroke-width="1.5"/>'
            . '<text class="t" x="' . ($ex + 85) . '" y="' . ($y + 113) . '" text-anchor="middle" font-weight="700">' . self::e($words['answer']) . '</text>'
            . '<text class="m" x="' . ($ex + 85) . '" y="' . ($y + 131) . '" text-anchor="middle" font-size="11">' . self::e($words['answerSub']) . '</text>';
        // After: the log and the statistics, below everything.
        $top = $bottom;
        $height = 26 + 16 * count($words['lines']);
        $h .= '<path class="arrow" d="M' . ($ex + 170) . ' ' . $y . ' H' . ($w - 12) . ' V' . ($top - 2) . '" marker-end="url(#rsd-s)"/>'
            . '<path class="arrow" d="M' . ($ex + 85) . ' ' . ($y + 144) . ' V' . ($top - 2) . '" marker-end="url(#rsd-s)"/>'
            . '<rect class="box" x="4" y="' . $top . '" width="' . ($w - 8) . '" height="' . $height . '" rx="8"/>'
            . '<text class="t" x="18" y="' . ($top + 19) . '" font-weight="700">' . self::e($words['after']) . '</text>';
        foreach ($words['lines'] as $n => $line) {
            $h .= '<text class="m" x="18" y="' . ($top + 38 + 16 * $n) . '" font-size="12">' . self::e($line) . '</text>';
        }
        if ($feedX !== null) {
            // The statistics use what the crawler check found: people, crawlers, bots.
            $h .= '<path class="feed" d="M' . ($feedX + 8) . ' ' . ($y + 38) . ' V' . ($top - 2) . '" marker-end="url(#rsd-s)"/>'
                . '<text class="fl" x="' . ($feedX + 14) . '" y="' . ($top - 10) . '" font-size="11">' . self::e($words['feeds']) . '</text>';
        }
        return $h . '</svg>';
    }

    /** The browser check, step by step: browser, shield, site. */
    public static function browserCheck(): string
    {
        $lanes = [110 => 'Browser', 390 => 'request-shield', 640 => 'Your site'];
        $h = '<svg class="rsd" role="img" viewBox="0 0 760 430" width="100%" xmlns="http://www.w3.org/2000/svg">'
            . '<title>The browser check, step by step</title>' . self::STYLE
            . '<defs><marker id="rsd-b" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M0 0 L10 5 L0 10z" class="m"/></marker></defs>';
        foreach ($lanes as $x => $name) {
            $h .= '<rect class="box" x="' . ($x - 75) . '" y="8" width="150" height="34" rx="8"/>'
                . '<text class="t" x="' . $x . '" y="30" text-anchor="middle" font-weight="700">' . $name . '</text>'
                . '<path class="lane" d="M' . $x . ' 42 V420"/>';
        }
        $arrow = static function (int $from, int $to, int $y, string $n, string $text, string $class = 'arrow') use (&$h): void {
            $h .= '<path class="' . $class . '" d="M' . $from . ' ' . $y . ' H' . $to . '" marker-end="url(#rsd-b)"/>'
                . '<circle class="note" cx="' . (min($from, $to) + 14) . '" cy="' . ($y - 14) . '" r="9"/><text class="w" x="' . (min($from, $to) + 14) . '" y="' . ($y - 10) . '" text-anchor="middle" font-size="11">' . $n . '</text>'
                . '<text class="t" x="' . (min($from, $to) + 28) . '" y="' . ($y - 9) . '">' . $text . '</text>';
        };
        $arrow(110, 390, 82, '1', 'asks for a page — too often, or a checked page');
        $arrow(390, 110, 132, '2', 'a small page with a signed task instead');
        // 3: the browser solves it
        $h .= '<path class="arrow" d="M110 162 h60 v36 h-58" marker-end="url(#rsd-b)"/>'
            . '<circle class="note" cx="186" cy="170" r="9"/><text class="w" x="186" y="174" text-anchor="middle" font-size="11">3</text>'
            . '<text class="t" x="200" y="176">solves it by itself — a fraction of a second</text>';
        $arrow(110, 390, 244, '4', 'the same page again, with the answer');
        $h .= '<rect class="okbox" x="300" y="258" width="180" height="30" rx="6" stroke-width="1.2"/>'
            . '<text class="t" x="390" y="278" text-anchor="middle" font-size="12">5 · answer right: &lt; 1 ms</text>';
        $arrow(390, 640, 316, '6', 'the request goes on');
        $arrow(640, 110, 356, '7', 'the page — and a pass for an hour', 'arrow go');
        $h .= '<text class="m" x="110" y="400" font-size="12">Next pages: with the pass straight to the site. A bot without JavaScript never gets past step 2.</text>';
        return $h . '</svg>';
    }

    /** How the shield sits in front of a site, and what it does with whom. */
    public static function overview(): string
    {
        $h = '<svg class="rsd" role="img" viewBox="0 0 760 300" width="100%" xmlns="http://www.w3.org/2000/svg">'
            . '<title>How request-shield sits in front of a site</title>' . self::STYLE
            . '<defs><marker id="rsd-c" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M0 0 L10 5 L0 10z" class="m"/></marker></defs>';
        $box = static function (int $x, int $y, int $w, string $class, string $title, string $sub) use (&$h): void {
            $h .= '<rect class="' . $class . '" x="' . $x . '" y="' . $y . '" width="' . $w . '" height="56" rx="10" stroke-width="1.5"/>'
                . '<text class="t" x="' . ($x + $w / 2) . '" y="' . ($y + 24) . '" text-anchor="middle" font-weight="700">' . $title . '</text>'
                . '<text class="m" x="' . ($x + $w / 2) . '" y="' . ($y + 42) . '" text-anchor="middle" font-size="12">' . $sub . '</text>';
        };
        $box(10, 40, 150, 'box', 'Visitors', 'people, bots, crawlers');
        $box(250, 40, 220, 'warnbox', 'request-shield', 'every request, in microseconds');
        $box(580, 40, 170, 'okbox', 'Your site', 'CMS · 100–200 ms a page');
        $h .= '<path class="arrow" d="M160 68 H246" marker-end="url(#rsd-c)"/><path class="arrow go" d="M470 68 H576" marker-end="url(#rsd-c)"/>'
            . '<text class="m" x="523" y="60" text-anchor="middle" font-size="11">passes</text>';
        $outs = [[40, 'nobox', 'junk, scanners', '404 · 400'], [220, 'nobox', 'not for you', '403 · 405'],
            [400, 'warnbox', 'suspicious', 'browser check'], [580, 'warnbox', 'too fast', 'a pause (429)']];
        foreach ($outs as [$x, $class, $title, $sub]) {
            $h .= '<path class="arrow" d="M360 96 V150 H' . ($x + 70) . ' V186" marker-end="url(#rsd-c)"/>';
            $box($x, 190, 140, $class, $title, $sub);
        }
        $h .= '<text class="m" x="380" y="280" text-anchor="middle" font-size="12">Answered by the shield itself: the site never renders a page for them.</text>';
        return $h . '</svg>';
    }

    private static function cut(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }
}
