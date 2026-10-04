<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 *
 *   php docs/tools/diagram.php [--check] [<file.dg> …]
 *
 * The docs' diagrams from a few lines of text (0031 F.7): each
 * docs/diagrams/<name>.dg becomes docs/diagrams/<name>.svg, in the house
 * style of the shield's own pages (Report\Diagram::style(): light and dark).
 * A page shows one with ![what it shows](../diagrams/<name>.svg).
 *
 *   title  What the diagram shows, in one sentence (the SVG's title, for screen readers)
 *   row    a:box "Title" "the line below" | b:warnbox "Title" | c:okbox "Title" "…"
 *   row    d:nobox "Title" "…"
 *   arrow  a -> b "a label"
 *   arrow  b -> c "passes" go
 *   note   One sentence under the diagram.
 *   # a comment
 *
 * Rows are drawn top to bottom, their boxes spread across the width; a box's
 * kind is box (neutral), okbox (passes), warnbox (checked), nobox (refused).
 * An arrow runs straight within a row and around the corner between rows;
 * "go" draws it green. The same text gives the same SVG, byte for byte.
 *
 * --check writes nothing and exits 1 when an SVG is not what it would write
 * (the tests run it). A mistake names the file and the line; exit 2.
 */

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use CjwNetwork\RequestShield\Report\Diagram;

const KINDS = ['box', 'okbox', 'warnbox', 'nobox'];
const WIDTH = 760;

/** @return never */
function fail(string $at, string $why): void
{
    fwrite(STDERR, "diagram: $at: $why\n");
    exit(2);
}

/**
 * The words of a line, character by character: a quoted part is one word
 * (\" a quote and \\ a backslash inside it) and remembers that it was
 * quoted; a "|" outside quotes is a word of its own.
 *
 * @return list<array{0: string, 1: bool}> word, quoted
 */
function words(string $line, string $at): array
{
    $out = [];
    $cur = null;
    $quoted = false;
    $n = strlen($line);
    for ($i = 0; $i < $n; $i++) {
        $ch = $line[$i];
        if ($quoted) {
            if ($ch === '\\' && $i + 1 < $n && ($line[$i + 1] === '"' || $line[$i + 1] === '\\')) {
                $cur[0] .= $line[++$i];
            } elseif ($ch === '"') {
                $quoted = false;
            } else {
                $cur[0] .= $ch;
            }
        } elseif ($ch === '"') {
            if ($cur !== null) {
                $out[] = $cur;
            }
            $cur = ['', true];
            $quoted = true;
        } elseif ($ch === ' ' || $ch === "\t" || $ch === '|') {
            if ($cur !== null) {
                $out[] = $cur;
                $cur = null;
            }
            if ($ch === '|') {
                $out[] = ['|', false];
            }
        } else {
            if ($cur !== null && $cur[1]) {
                $out[] = $cur;
                $cur = null;
            }
            $cur = [($cur[0] ?? '') . $ch, false];
        }
    }
    if ($quoted) {
        fail($at, 'a quote is not closed');
    }
    if ($cur !== null) {
        $out[] = $cur;
    }
    return $out;
}

/**
 * A .dg text as its parts.
 *
 * @return array{title: string, rows: list<list<array{id: string, kind: string, title: string, sub: string}>>, arrows: list<array{from: string, to: string, label: string, go: bool, at: string}>, notes: list<string>}
 */
function parse(string $text, string $name): array
{
    $d = ['title' => '', 'rows' => [], 'arrows' => [], 'notes' => []];
    $ids = [];
    foreach (preg_split('/\r\n|\n/', $text) ?: [] as $i => $line) {
        $at = "$name:" . ($i + 1);
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $word = (string) strtok($line, " \t");
        $rest = trim(substr($line, strlen($word)));
        if ($word === 'title' || $word === 'note') {
            if ($rest === '') {
                fail($at, "$word of what?");
            }
            $word === 'title' ? $d['title'] = $rest : $d['notes'][] = $rest;
        } elseif ($word === 'row') {
            $row = [];
            $cells = [[]];
            foreach (words($rest, $at) as $w) {
                if ($w === ['|', false]) {
                    $cells[] = [];
                } else {
                    $cells[count($cells) - 1][] = $w;
                }
            }
            foreach ($cells as $cell) {
                $w = array_column($cell, 0);
                if (count($w) < 2 || count($w) > 3 || $cell[0][1] || preg_match('/^([a-z][a-z0-9-]*):([a-z]+)$/', $w[0], $m) !== 1 || !in_array($m[2], KINDS, true)) {
                    fail($at, 'row <id>:<' . implode('|', KINDS) . '> "Title" ["the line below"] | … -- not "' . implode(' ', $w) . '"');
                }
                if (isset($ids[$m[1]])) {
                    fail($at, "the box $m[1] is there already ({$ids[$m[1]]})");
                }
                $ids[$m[1]] = $at;
                $row[] = ['id' => $m[1], 'kind' => $m[2], 'title' => $w[1], 'sub' => $w[2] ?? ''];
            }
            $d['rows'][] = $row;
        } elseif ($word === 'arrow') {
            // arrow <from> -> <to> ["a label"] [go] -- "go" last draws it green.
            $q = words($rest, $at);
            $w = array_column($q, 0);
            $go = count($q) >= 4 && end($q) === ['go', false];
            $label = array_slice($w, 3, $go ? count($w) - 4 : null);
            if (count($w) < 3 || $w[1] !== '->' || count($label) > 1) {
                fail($at, 'arrow <from> -> <to> ["a label"] [go]');
            }
            $label = $label[0] ?? '';
            $d['arrows'][] = ['from' => $w[0], 'to' => $w[2], 'label' => $label, 'go' => $go, 'at' => $at];
        } else {
            fail($at, "title, row, arrow or note -- not \"$word\"");
        }
    }
    if ($d['title'] === '') {
        fail("$name:1", 'a diagram needs a title: what it shows, in one sentence');
    }
    if ($d['rows'] === []) {
        fail("$name:1", 'a diagram needs a row of boxes');
    }
    foreach ($d['arrows'] as $a) {
        foreach ([$a['from'], $a['to']] as $id) {
            if (!isset($ids[$id])) {
                fail($a['at'], "no box $id");
            }
        }
    }
    return $d;
}

/**
 * The SVG of a parsed diagram.
 *
 * @param array{title: string, rows: list<list<array{id: string, kind: string, title: string, sub: string}>>, arrows: list<array{from: string, to: string, label: string, go: bool, at: string}>, notes: list<string>} $d
 */
function render(array $d, string $marker): string
{
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $rowH = 110;
    $boxH = 56;
    $pos = [];
    // The room between a row's boxes: as wide as its longest arrow label (about 6 px a
    // character at 11 px), at least 40 -- the boxes give way.
    $rowOf = [];
    foreach ($d['rows'] as $r => $row) {
        foreach ($row as $box) {
            $rowOf[$box['id']] = $r;
        }
    }
    $room = [];
    foreach ($d['arrows'] as $a) {
        if ($rowOf[$a['from']] === $rowOf[$a['to']]) {
            $room[$rowOf[$a['from']]] = max($room[$rowOf[$a['from']]] ?? 40, (int) ceil(mb_strlen($a['label']) * 6.2) + 16);
        }
    }
    foreach ($d['rows'] as $r => $row) {
        $n = count($row);
        $w = (int) min(220, floor((WIDTH - 20 - ($n - 1) * ($room[$r] ?? 40)) / $n));
        $gap = $n > 1 ? (WIDTH - 20 - $n * $w) / ($n - 1) : 0;
        $x0 = $n > 1 ? 10 : (WIDTH - $w) / 2;
        foreach ($row as $i => $box) {
            $pos[$box['id']] = ['x' => (int) round($x0 + $i * ($w + $gap)), 'y' => 20 + $r * $rowH, 'w' => $w, 'row' => $r] + $box;
        }
    }
    $height = 20 + count($d['rows']) * $rowH - ($rowH - $boxH) + 20 + count($d['notes']) * 20;
    $h = '<svg class="rsd" role="img" viewBox="0 0 ' . WIDTH . ' ' . $height . '" width="100%" xmlns="http://www.w3.org/2000/svg">'
        . '<title>' . $e($d['title']) . '</title>' . Diagram::style($marker);
    foreach ($d['arrows'] as $a) {
        $f = $pos[$a['from']];
        $t = $pos[$a['to']];
        $class = 'arrow' . ($a['go'] ? ' go' : '');
        if ($f['row'] === $t['row']) {
            // Within a row: straight, from the side that faces the other box.
            $y = $f['y'] + $boxH / 2;
            [$x1, $x2] = $t['x'] > $f['x'] ? [$f['x'] + $f['w'], $t['x'] - 4] : [$f['x'], $t['x'] + $t['w'] + 4];
            $h .= '<path class="' . $class . '" d="M' . $x1 . ' ' . $y . ' H' . $x2 . '" marker-end="url(#' . $marker . ')"/>';
            $lx = ($x1 + $x2) / 2;
            $ly = $y - 8;
        } else {
            // Between rows: down (or up) from the middle, across, into the other box.
            $down = $t['row'] > $f['row'];
            $x1 = $f['x'] + $f['w'] / 2;
            $x2 = $t['x'] + $t['w'] / 2;
            $y1 = $down ? $f['y'] + $boxH : $f['y'];
            $y2 = $down ? $t['y'] - 4 : $t['y'] + $boxH + 4;
            $mid = ($y1 + $y2) / 2;
            $h .= '<path class="' . $class . '" d="M' . $x1 . ' ' . $y1 . ' V' . $mid . ' H' . $x2 . ' V' . $y2 . '" marker-end="url(#' . $marker . ')"/>';
            $lx = $x2 + 6;
            $ly = $mid + ($down ? 16 : -6);
        }
        if ($a['label'] !== '') {
            $anchor = $f['row'] === $t['row'] ? 'middle' : 'start';
            $h .= '<text class="m" x="' . $lx . '" y="' . $ly . '" text-anchor="' . $anchor . '" font-size="11">' . $e($a['label']) . '</text>';
        }
    }
    foreach ($pos as $b) {
        $cx = $b['x'] + $b['w'] / 2;
        $h .= '<rect class="' . $b['kind'] . '" x="' . $b['x'] . '" y="' . $b['y'] . '" width="' . $b['w'] . '" height="' . $boxH . '" rx="10" stroke-width="1.5"/>'
            . '<text class="t" x="' . $cx . '" y="' . ($b['y'] + ($b['sub'] !== '' ? 24 : 33)) . '" text-anchor="middle" font-weight="700">' . $e($b['title']) . '</text>'
            . ($b['sub'] !== '' ? '<text class="m" x="' . $cx . '" y="' . ($b['y'] + 42) . '" text-anchor="middle" font-size="12">' . $e($b['sub']) . '</text>' : '');
    }
    $y = 20 + count($d['rows']) * $rowH - ($rowH - $boxH) + 30;
    foreach ($d['notes'] as $note) {
        $h .= '<text class="m" x="' . (WIDTH / 2) . '" y="' . $y . '" text-anchor="middle" font-size="12">' . $e($note) . '</text>';
        $y += 20;
    }
    return $h . "</svg>\n";
}

$check = in_array('--check', $argv, true);
$files = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => $a !== '--check'));
$files = $files !== [] ? $files : (glob(__DIR__ . '/../diagrams/*.dg') ?: []);
sort($files, SORT_STRING);
$stale = [];
foreach ($files as $file) {
    $name = basename($file, '.dg');
    $svg = render(parse((string) file_get_contents($file), basename($file)), 'rsd-' . $name);
    $out = dirname($file) . "/$name.svg";
    if ((string) @file_get_contents($out) !== $svg) {
        $stale[] = basename($out);
        if (!$check) {
            file_put_contents($out, $svg);
        }
    }
}
if ($check && $stale !== []) {
    fwrite(STDERR, 'diagram: not current -- php docs/tools/diagram.php: ' . implode(', ', $stale) . "\n");
    exit(1);
}
echo $stale === [] ? "diagram: current\n" : 'diagram: written ' . implode(', ', $stale) . "\n";
