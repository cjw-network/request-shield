<?php

declare(strict_types=1);

/**
 * The docs' diagrams from text (0031 F.7): docs/diagrams/*.dg become SVGs in
 * the house style (docs/tools/diagram.php); the recorded demo goes to GitHub
 * Pages (.github/workflows/pages.yml).
 */

/** @return array{0: string, 1: int} what the tool said (stderr too), and its exit code */
function diagramRun(string $args): array
{
    if (!function_exists('exec')) {
        skip('no exec');
    }
    // Without the JIT: PHP 8.1-8.3's tracing JIT (setup-php turns it on in CI) now and then misread
    // the tool's quotes -- "a quote is not closed" for a line that has them all; 8.4 does not.
    exec(escapeshellarg(PHP_BINARY) . ' -d opcache.jit=disable ' . escapeshellarg(dirname(__DIR__) . '/docs/tools/diagram.php') . " $args 2>&1", $out, $code);
    return [implode("\n", $out), $code];
}

return [
    'diagrams: every SVG is what its .dg text gives, well-formed, titled for screen readers, light and dark; every one is shown by a page' => function (): void {
        [$out, $code] = diagramRun('--check');
        same(0, $code, $out);
        $dir = dirname(__DIR__) . '/docs/diagrams';
        $dg = array_map(static fn (string $f): string => basename($f, '.dg'), glob("$dir/*.dg") ?: []);
        $svg = array_map(static fn (string $f): string => basename($f, '.svg'), glob("$dir/*.svg") ?: []);
        truthy(count($dg) >= 3, 'diagrams: ' . implode(', ', $dg));
        same($dg, $svg, 'one SVG per text, none without one');
        $docs = '';
        foreach (glob(dirname(__DIR__) . '/docs/{features,for,explained,use-cases}/*.md', GLOB_BRACE) ?: [] as $f) {
            $docs .= (string) file_get_contents($f);
        }
        foreach ($svg as $name) {
            $text = (string) file_get_contents("$dir/$name.svg");
            $xml = @simplexml_load_string($text);
            truthy($xml !== false, "$name.svg is well-formed XML");
            truthy(preg_match('#<title>[^<]{20,}</title>#', $text) === 1, "$name.svg has a title that says what it shows");
            truthy(strpos($text, 'prefers-color-scheme:dark') !== false, "$name.svg has the dark colours");
            truthy(strpos($docs, "../diagrams/$name.svg") !== false, "$name.svg is shown by a page");
        }
    },
    'diagrams: a mistake names the file and the line, and nothing is written' => function (): void {
        $dir = sys_get_temp_dir() . '/rs-dg-' . getmypid() . '-' . mt_rand();
        mkdir($dir);
        try {
            $cases = [
                "row a:box \"A\"\n" => ['x.dg:1', 'needs a title'],
                "title Something long enough to say\nrow a:box \"A\" | a:okbox \"B\"\n" => ['x.dg:2', 'the box a is there already'],
                "title Something long enough to say\nrow a:box \"A\"\narrow a -> b\n" => ['x.dg:3', 'no box b'],
                "title Something long enough to say\nrow a:redbox \"A\"\n" => ['x.dg:2', 'row <id>:<'],
                "title Something long enough to say\nrow a:box \"A\"\nbox a\n" => ['x.dg:3', 'title, row, arrow or note'],
                "title Something long enough to say\nrow a:box \"A\nB\"\n" => ['x.dg:2', 'a quote is not closed'],
            ];
            foreach ($cases as $text => [$at, $why]) {
                file_put_contents("$dir/x.dg", $text);
                [$out, $code] = diagramRun(escapeshellarg("$dir/x.dg"));
                same(2, $code, $out);
                truthy(strpos($out, "$at: ") !== false && strpos($out, $why) !== false, "$at, $why: $out");
                truthy(!is_file("$dir/x.svg"), 'nothing written');
            }
            file_put_contents("$dir/x.dg", "title A test diagram that says what it shows\nrow a:box \"<A & B>\" \"one\" | b:okbox \"B\"\narrow a -> b \"go on\" go\n");
            [$out, $code] = diagramRun(escapeshellarg("$dir/x.dg"));
            same(0, $code, $out);
            $svg = (string) file_get_contents("$dir/x.svg");
            truthy(strpos($svg, '&lt;A &amp; B&gt;') !== false && @simplexml_load_string($svg) !== false, 'text is escaped');
            truthy(strpos($svg, 'class="arrow go"') !== false && strpos($svg, '>go on</text>') !== false, 'go: green, the label kept');
            // Quotes are read before anything else: a | inside a title is text, a quoted
            // "go" is a label, an escaped backslash ends a title.
            file_put_contents("$dir/x.dg", "title A test diagram that says what it shows\nrow a:box \"GET | POST\" | b:box \"C:\\\\\" \"say \\\"hi\\\"\"\narrow a -> b \"go\"\n");
            [$out, $code] = diagramRun(escapeshellarg("$dir/x.dg"));
            same(0, $code, $out);
            $svg = (string) file_get_contents("$dir/x.svg");
            truthy(strpos($svg, '>GET | POST</text>') !== false, 'a | inside quotes is text');
            truthy(strpos($svg, '>C:\\</text>') !== false && strpos($svg, '>say &quot;hi&quot;</text>') !== false, 'escaped backslash and quotes');
            truthy(strpos($svg, 'class="arrow go"') === false && strpos($svg, '>go</text>') !== false, 'a quoted "go" is a label, not the keyword');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'the recorded demo for GitHub Pages (started by hand): the page from request-shield examples --html, the diagrams, actions pinned by commit' => function (): void {
        $yml = (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/pages.yml');
        // Pages is off for now (owner, 2026-10-08): started by hand only, never on a push.
        truthy(preg_match("/^on:\n  workflow_dispatch:\n/m", $yml) === 1 && strpos($yml, '  push:') === false, 'by hand only, not on a push');
        truthy(strpos($yml, 'php bin/request-shield examples examples/demo/request-shield.rules --html --out=_site/index.html') !== false, 'the recorded demo');
        truthy(strpos($yml, 'php docs/tools/diagram.php --check') !== false && strpos($yml, 'cp docs/diagrams/*.svg') !== false, 'the diagrams, current');
        preg_match_all('/^\s*(?:-\s+)?uses:\s*(\S+)/m', $yml, $m);
        foreach ($m[1] as $uses) {
            truthy(preg_match('/^[\w.-]+\/[\w.-]+@[0-9a-f]{40}$/', $uses) === 1, "pinned by commit: $uses");
        }
        truthy(strpos($yml, "permissions:\n  contents: read") !== false && strpos($yml, 'pages: write') !== false && strpos($yml, 'id-token: write') !== false, 'read-only, the deploy job may write pages');
    },
];
