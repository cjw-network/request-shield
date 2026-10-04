<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Help;
use CjwNetwork\RequestShield\Rules\Vocabulary;
use CjwNetwork\RequestShield\Settings;

/**
 * The pages explain themselves (0031 F.9): Help renders a feature's sentence
 * and its "?" link from Vocabulary::TOPICS, to where `set docs-url` says;
 * docs/tools/check-anchors.php finds every anchor a link points at.
 */

/** @return array{0: string, 1: int} what check-anchors printed, and its exit code */
function helpAnchors(string $args = ''): array
{
    if (!function_exists('exec')) {
        skip('no exec');
    }
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/docs/tools/check-anchors.php') . ($args !== '' ? " $args" : '') . ' 2>&1', $out, $code);
    return [implode("\n", $out), $code];
}

return [
    'RSF06-01 every feature has its topic: the page it links to, a short title, one sentence in English and in German -- no topic without a page' => function (): void {
        $pages = [];
        foreach (glob(dirname(__DIR__) . '/docs/features/RSF*.md') ?: [] as $f) {
            preg_match('/^(RSF\d{2}-\d{2})-(.+)\.md$/', basename($f), $m);
            $pages[$m[1]] = $m[2];
        }
        ksort($pages);
        $topics = array_map(static fn (array $t): string => $t[0], Vocabulary::TOPICS);
        ksort($topics);
        same($pages, $topics, 'a topic for each page in docs/features, its file name');
        foreach (Vocabulary::TOPICS as $id => [, $title, $en, $de]) {
            truthy($title !== '' && strlen($title) <= 40, "$id: a short title");
            foreach (['en' => $en, 'de' => $de] as $lang => $s) {
                $words = count(preg_split('/\s+/', trim($s)) ?: []);
                truthy(substr($s, -1) === '.' && $words >= 6 && $words <= 30, "$id ($lang): one sentence of 6 to 30 words: $s");
            }
            truthy($en !== $de, "$id: German is not English");
        }
    },
    'RSF06-01 Help: the link escapes and names its feature, follows set docs-url, and is gone with the links off -- the sentence stays' => function (): void {
        $link = Help::link('RSF02-06', 'configuration');
        same('<a class="rs-help" href="https://github.com/cjw-network/request-shield/blob/main/docs/features/RSF02-06-attack-patterns.md#configuration" target="_blank" rel="noopener" title="RSF02-06 Attack patterns: in the docs" aria-label="RSF02-06 Attack patterns: in the docs">?</a>', $link);
        truthy(strpos(Help::link('RSF02-06', '', '/d"x', 'de'), 'href="/d&quot;x/features/') !== false, 'escaped');
        truthy(strpos(Help::link('RSF02-06', '', Help::DOCS, 'de'), 'in der Dokumentation') !== false, 'German');
        same('', Help::link('RSF02-06', '', ''), 'links off');
        same('', Help::link('RSF99-99'), 'an unknown id: no link');
        same('<p class="rs-about">' . htmlspecialchars(Help::sentence('RSF02-06'), ENT_QUOTES) . '</p>', Help::about('RSF02-06', '', ''), 'links off: the sentence stays');
        truthy(strpos(Help::about('RSF02-06', '', Help::DOCS, 'en', 'Nothing yet <b>.'), 'Nothing yet &lt;b&gt;.') !== false, 'a sentence of the page\'s own, escaped');
        same('see https://docs.example.org/rs/features/RSF05-01-rule-files.md#paths', Help::see('RSF05-01', 'paths', 'https://docs.example.org/rs'));
        same('see RSF05-01 Rule files in the docs', Help::see('RSF05-01', 'paths', ''));
        $s = Settings::from([]);
        same(Help::DOCS, $s->docsUrl, 'the repository\'s by default');
        same('https://docs.example.org/rs', Settings::from(['docsUrl' => 'https://docs.example.org/rs/'])->docsUrl);
        same('/docs', Settings::from(['docsUrl' => '/docs'])->docsUrl);
        same('', Settings::from(['docsUrl' => 'off'])->docsUrl);
        foreach (['javascript:alert(1)', 'docs', 'https://a b', 7] as $wrong) {
            $thrown = false;
            try {
                Settings::from(['docsUrl' => $wrong]);
            } catch (\InvalidArgumentException $e) {
                $thrown = strpos($e->getMessage(), 'docsUrl') !== false;
            }
            truthy($thrown, 'refused: ' . var_export($wrong, true));
        }
    },
    'RSF06-01 every anchor is found: the docs\' links and the code\'s Help calls (check-anchors), and a missing one is named with file and line' => function (): void {
        [$out, $code] = helpAnchors();
        same(0, $code, $out);
        $dir = sys_get_temp_dir() . '/rs-anchors-' . getmypid() . '-' . mt_rand();
        mkdir("$dir/docs/features", 0777, true);
        mkdir("$dir/src", 0777, true);
        try {
            file_put_contents("$dir/docs/a.md", "# A\n\nSee [b](features/RSF02-06-x.md#cost) and [there](features/RSF02-06-x.md#the-config-uration).\n\n```\n[not](features/RSF02-06-x.md#code)\n```\n");
            file_put_contents("$dir/docs/features/RSF02-06-x.md", "# RSF02-06 X\n\n## Cost\n\n## The `config` (uration)\n");
            file_put_contents("$dir/src/P.php", "<?php\nHelp::link('RSF02-06', 'cost');\nHelp::about('RSF02-06', 'limits');\nHelp::url('RSF09-09');\n");
            [$out, $code] = helpAnchors('--root=' . escapeshellarg($dir));
            same(1, $code, $out);
            truthy(strpos($out, 'src/P.php:3: RSF02-06#limits -- no such heading') !== false, $out);
            truthy(strpos($out, 'src/P.php:4: RSF09-09 -- no page') !== false, $out);
            same(2, substr_count($out, '-- no'), 'only those two: ' . $out);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
