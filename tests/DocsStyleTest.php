<?php

declare(strict_types=1);

/**
 * Plain language (0031 F.8, CONTRIBUTING "Plain language"): docs/glossary.md
 * explains the words; the pages for people (docs/for, docs/explained) link a
 * glossary word where they use it and keep their sentences short; no page
 * without a diagram -- the pages that have none yet are listed in
 * DIAGRAM_GAPS, which 0031 F.9 empties, and which stays exact.
 */

/** Pages without a picture yet: 0031 F.9 gives each one and empties the list. */
const DIAGRAM_GAPS = [
    'features/RSF01-01-trusted-proxies.md',
    'features/RSF01-02-ip-lists.md',
    'features/RSF01-03-blocklist-feeds.md',
    'features/RSF02-01-hard-rejects.md',
    'features/RSF02-03-access-rules.md',
    'features/RSF02-04-forms-from-the-website.md',
    'features/RSF03-02-browser-challenge.md',
    'features/RSF03-03-browser-check-in-the-form.md',
    'features/RSF03-04-app-challenges.md',
    'features/RSF04-01-cacheable-definition.md',
    'features/RSF05-01-rule-files.md',
    'features/RSF05-02-settings.md',
    'features/RSF05-04-rule-examples.md',
    'features/RSF05-05-log-and-rule-ids.md',
    'features/RSF06-01-active-rules-page.md',
    'features/RSF06-02-live-and-lists.md',
    'features/RSF06-04-plugins.md',
    'use-cases/behind-a-load-balancer.md',
    'use-cases/exponential.md',
    'use-cases/page-cache-pollution.md',
    'use-cases/scraping-and-bots.md',
    'use-cases/shared-hosting-dos-guard.md',
];

/** The longest sentence a page for people may have, in words. */
const LONGEST_SENTENCE = 30;

function styleDocs(): string
{
    return dirname(__DIR__) . '/docs';
}

/** The anchor GitHub gives a heading. */
function styleAnchor(string $heading): string
{
    $a = (string) preg_replace('/[^\p{L}\p{N} _-]/u', '', mb_strtolower(trim($heading)));
    return str_replace(' ', '-', $a);
}

/**
 * The glossary's entries: anchor => the words it explains (its heading and its "Also:" line).
 *
 * @return array<string, array{words: list<string>, text: string}>
 */
function styleGlossary(?string $markdown = null): array
{
    $markdown ??= (string) file_get_contents(styleDocs() . '/glossary.md');
    $out = [];
    foreach (array_slice(preg_split('/^### /m', $markdown) ?: [], 1) as $part) {
        $heading = (string) strtok($part, "\n");
        $body = trim(substr($part, strlen($heading)));
        $words = [$heading];
        if (preg_match('/^\*Also:\* (.+)$/m', $body, $m) === 1) {
            $words = array_merge($words, array_map('trim', explode(',', $m[1])));
            $body = trim((string) preg_replace('/^\*Also:\* .+$/m', '', $body));
        }
        $out[styleAnchor($heading)] = ['words' => $words, 'text' => $body];
    }
    return $out;
}

/** The running text of a page: no code, no tables, no pictures, no addresses of links, no comments. */
function styleProse(string $markdown): string
{
    $t = (string) preg_replace('/^[ \t]*```.*?^[ \t]*```/ms', '', $markdown);
    $t = (string) preg_replace('/<!--.*?-->/s', '', $t);
    $t = (string) preg_replace('/^\|.*$/m', '', $t);
    $t = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $t);
    $t = (string) preg_replace('/\]\([^)]*\)/', ']', $t);
    return (string) preg_replace('/`[^`]*`/', 'CODE', $t);
}

/** @return list<string> the sentences of the running text longer than LONGEST_SENTENCE words */
function styleLongSentences(string $markdown): array
{
    $long = [];
    foreach (preg_split('/\n\s*\n|\n(?=\s*(?:[-*]|\d+\.) )|\n#/', styleProse($markdown)) ?: [] as $block) {
        $block = (string) preg_replace('/\s+/', ' ', $block);
        foreach (preg_split('/(?<=[.!?:])\s+(?=[A-Z"*(`\[])/', $block) ?: [] as $sentence) {
            if (count(preg_split('/\s+/u', trim((string) preg_replace('/\s[—–]\s/u', ' ', $sentence))) ?: []) > LONGEST_SENTENCE) {
                $long[] = trim($sentence);
            }
        }
    }
    return $long;
}

/**
 * The glossary words a page uses without linking their entry.
 *
 * @param array<string, array{words: list<string>, text: string}> $glossary
 * @return list<string>
 */
function styleUnlinked(string $markdown, array $glossary, string $link): array
{
    $prose = styleProse($markdown);
    // The longest words first, each taken out once found: "rule file" is not also "rule".
    $words = [];
    foreach ($glossary as $anchor => $entry) {
        foreach ($entry['words'] as $word) {
            $words[] = [$word, $anchor];
        }
    }
    usort($words, static fn (array $a, array $b): int => mb_strlen($b[0]) <=> mb_strlen($a[0]));
    $used = [];
    foreach ($words as [$word, $anchor]) {
        $re = '/(?<![\w-])' . preg_quote($word, '/') . '(?:s|es)?(?![\w-])/iu';
        if (preg_match($re, $prose) === 1) {
            $used[$anchor] ??= $word;
            $prose = (string) preg_replace($re, ' ', $prose);
        }
    }
    $missing = [];
    foreach ($glossary as $anchor => $entry) {
        if (isset($used[$anchor]) && strpos($markdown, "$link#$anchor)") === false) {
            $missing[] = "\"{$used[$anchor]}\" (#$anchor)";
        }
    }
    return $missing;
}

/** @return list<string> the pages for people, relative to docs/ */
function stylePeoplePages(): array
{
    $out = [];
    foreach (['for', 'explained'] as $dir) {
        foreach (glob(styleDocs() . "/$dir/*.md") ?: [] as $f) {
            $out[] = "$dir/" . basename($f);
        }
    }
    return $out;
}

return [
    'plain language: the glossary -- every entry explains its word in short sentences, in alphabetical order, each word once' => function (): void {
        $g = styleGlossary();
        truthy(count($g) >= 30, 'entries: ' . count($g));
        $headings = array_keys($g);
        $sorted = $headings;
        sort($sorted);
        same($sorted, $headings, 'alphabetical');
        $seen = [];
        foreach ($g as $anchor => $entry) {
            truthy(strlen($entry['text']) >= 40, "#$anchor explains its word");
            foreach ($entry['words'] as $w) {
                truthy(!isset($seen[mb_strtolower($w)]), "\"$w\" is explained once (#$anchor, #" . ($seen[mb_strtolower($w)] ?? '') . ')');
                $seen[mb_strtolower($w)] = $anchor;
            }
        }
        same([], styleLongSentences((string) file_get_contents(styleDocs() . '/glossary.md')), 'sentences of at most ' . LONGEST_SENTENCE . ' words');
    },
    'plain language: a page for people links a glossary word where it uses it, every link into the glossary finds its entry, and no sentence is longer than ' . LONGEST_SENTENCE . ' words' => function (): void {
        $g = styleGlossary();
        foreach (stylePeoplePages() as $page) {
            $md = (string) file_get_contents(styleDocs() . "/$page");
            same([], styleUnlinked($md, $g, '../glossary.md'), "$page: glossary words without a link");
            same([], styleLongSentences($md), "$page: sentences over " . LONGEST_SENTENCE . ' words');
        }
        foreach (glob(styleDocs() . '/{*,*/*}.md', GLOB_BRACE) ?: [] as $f) {
            preg_match_all('/glossary\.md#([^)\s]+)\)/', (string) file_get_contents($f), $m);
            foreach ($m[1] as $anchor) {
                truthy(isset($g[$anchor]), basename($f) . ": the glossary has #$anchor");
            }
        }
    },
    'plain language: the checks themselves -- a long sentence, an unlinked word and a linked one are told apart' => function (): void {
        $g = styleGlossary("# G\n\n### Ban\n\n*Also:* banned\n\nAn address kept out for a while, by hand or by itself.\n");
        same(['"banned" (#ban)'], styleUnlinked("An address was banned.\n", $g, '../glossary.md'), 'used, not linked');
        same([], styleUnlinked("An address was [banned](../glossary.md#ban).\n", $g, '../glossary.md'), 'linked');
        same([], styleUnlinked("Run `request-shield ban` here.\n\n  ```\n  ban\n  ```\n", $g, '../glossary.md'), 'code is not prose, indented too');
        $g = styleGlossary("# G\n\n### Rule\n\nOne line in the rule file, here.\n\n### Rule file\n\nA plain text file with rules.\n");
        same([], styleUnlinked("Edit the [rule file](../glossary.md#rule-file).\n", $g, '../glossary.md'), 'a longer word is not also its shorter one');
        same(1, count(styleLongSentences(str_repeat('word ', 31) . ".\n\nShort one. Word " . trim(str_repeat('word ', 29)) . ".\n")), 'one sentence over 30 words');
    },
    'no page without a diagram: every feature, use case and page for people shows a picture that says what it shows -- the gaps are listed, and the list is exact' => function (): void {
        $pages = [];
        foreach (['features', 'use-cases', 'for', 'explained'] as $dir) {
            foreach (glob(styleDocs() . "/$dir/*.md") ?: [] as $f) {
                $pages[] = "$dir/" . basename($f);
            }
        }
        $without = [];
        foreach ($pages as $page) {
            $md = (string) preg_replace('/^[ \t]*```.*?^[ \t]*```/ms', '', (string) file_get_contents(styleDocs() . "/$page"));
            preg_match_all('/!\[([^\]]*)\]\(([^)\s]+)\)/', $md, $m, PREG_SET_ORDER);
            foreach ($m as [, $alt, $src]) {
                truthy(strlen(trim($alt)) >= 20, "$page: the picture $src says what it shows (alt text)");
                truthy(preg_match('#^https?:#', $src) === 1 || is_file(dirname(styleDocs() . "/$page") . '/' . $src), "$page: $src is there");
            }
            if ($m === []) {
                $without[] = $page;
            }
        }
        same(DIAGRAM_GAPS, $without, 'the pages without a picture are exactly DIAGRAM_GAPS (0031 F.9 empties it)');
    },
    'docs for people: one page per role, each with its diagram, what to do and a typical day; the docs index and the glossary are linked' => function (): void {
        $index = (string) file_get_contents(styleDocs() . '/README.md');
        foreach (['admins', 'hosters', 'editors', 'customers', 'developers'] as $role) {
            $md = (string) file_get_contents(styleDocs() . "/for/$role.md");
            truthy(strpos($md, "](../diagrams/for-$role.svg)") !== false, "$role: its diagram");
            truthy(strpos($md, '## What you do when') !== false && strpos($md, '## A typical day') !== false, "$role: what to do, and a typical day");
            truthy(strpos($index, "(for/$role.md)") !== false, "$role: in docs/README.md");
        }
        truthy(strpos($index, '(glossary.md)') !== false, 'the glossary in docs/README.md');
    },
];
