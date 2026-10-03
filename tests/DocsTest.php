<?php

declare(strict_types=1);

/**
 * The documentation's shape: proposal and ADR numbers are unique and every
 * document is reachable from docs/README.md; what llms.txt names exists.
 */

function docsRoot(): string
{
    return dirname(__DIR__);
}

/** @return list<string> the relative targets of every [text](target) link, fragments and URLs left out */
function docsLinks(string $markdown): array
{
    preg_match_all('/\]\(([^)\s]+)\)/', $markdown, $m);
    $out = [];
    foreach ($m[1] as $target) {
        if (preg_match('#^[a-z]+:#i', $target) === 1) {
            continue;                                   // http(s), mailto
        }
        $path = (string) strtok($target, '#');
        if ($path !== '') {
            $out[] = $path;
        }
    }
    return $out;
}

return [
    'proposals: one number each, none twice, every one linked from docs/README.md' => function (): void {
        $dir = docsRoot() . '/docs/proposals';
        $index = (string) file_get_contents(docsRoot() . '/docs/README.md');
        $seen = [];
        foreach (glob("$dir/[0-9][0-9][0-9][0-9]-*.md") ?: [] as $file) {
            $name = basename($file);
            $number = substr($name, 0, 4);
            truthy(strpos($index, "proposals/$name") !== false, "$name is not linked from docs/README.md");
            if (preg_match('/^\d{4}-steps\.md$/', $name) === 1) {
                continue;                               // a proposal's companion: its steps and progress
            }
            if (isset($seen[$number])) {
                truthy(false, "proposal number $number is used twice: {$seen[$number]} and $name");
            }
            $seen[$number] = $name;
        }
        truthy(count($seen) >= 30, 'the proposals were found');
    },
    'architecture decisions: one number each, every one linked from docs/README.md' => function (): void {
        $index = (string) file_get_contents(docsRoot() . '/docs/README.md');
        $seen = [];
        foreach (glob(docsRoot() . '/docs/adr/[0-9][0-9][0-9][0-9]-*.md') ?: [] as $file) {
            $name = basename($file);
            $number = substr($name, 0, 4);
            if (isset($seen[$number])) {
                truthy(false, "ADR number $number is used twice: {$seen[$number]} and $name");
            }
            $seen[$number] = $name;
            truthy(strpos($index, "adr/$name") !== false, "$name is not linked from docs/README.md");
        }
        truthy(count($seen) >= 6, 'the decisions were found');
    },
    'docs/README.md: every relative link points at a file that exists' => function (): void {
        $index = (string) file_get_contents(docsRoot() . '/docs/README.md');
        foreach (docsLinks($index) as $path) {
            truthy(is_file(docsRoot() . '/docs/' . $path), "docs/README.md links $path, which does not exist");
        }
    },
    'llms.txt: every path it names exists -- except what it marks as coming with a step' => function (): void {
        $root = docsRoot();
        $lines = file("$root/llms.txt", FILE_IGNORE_NEW_LINES) ?: [];
        $checked = 0;
        foreach ($lines as $line) {
            if (strpos($line, 'step E.') !== false || strpos($line, 'coming with') !== false) {
                continue;                               // announced, not there yet: 0031 says when
            }
            foreach (docsLinks($line) as $path) {
                truthy(file_exists("$root/$path"), "llms.txt names $path, which does not exist");
                $checked++;
            }
            if (preg_match_all('/`((?:rules|docs|src|bin|plugins)\/[^`]+)`/', $line, $m) > 0) {
                foreach ($m[1] as $path) {
                    if (strpos($path, '*') === false) {
                        truthy(file_exists("$root/$path"), "llms.txt names $path, which does not exist");
                        $checked++;
                    }
                }
            }
        }
        truthy($checked >= 8, 'llms.txt was read');
        truthy(strpos(implode("\n", $lines), '## Never') !== false, 'llms.txt has the "Never" block');
    },
    'the steps file: every step is a checkbox with an id, and the status names the next step' => function (): void {
        $steps = (string) file_get_contents(docsRoot() . '/docs/proposals/0031-steps.md');
        preg_match_all('/^- \[( |x|~[^\]]*)\] \*\*([0-9A-Z]+\.[0-9]+)\*\*/m', $steps, $m);
        truthy(count($m[2]) >= 60, 'the steps were found: ' . count($m[2]));
        same(count($m[2]), count(array_unique($m[2])), 'no step id twice');
        truthy(preg_match('/^- \*\*Next step:\*\* \S+/m', $steps) === 1, 'the status names the next step');
    },
];
