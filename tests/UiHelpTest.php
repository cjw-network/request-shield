<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Challenge\ChallengePage;
use CjwNetwork\RequestShield\Dashboard;
use CjwNetwork\RequestShield\Help;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Settings;

/**
 * The pages explain themselves (0031 F.9): every page of the dashboard has
 * its "?" after the title and on every section, each pointing at a page of
 * the docs and a heading there; empty, a part says how it gets something;
 * with set docs-url off there is no link and the sentences stay. The check
 * page links visitors to the check in plain words.
 */

/** The dashboard's settings for these tests: guarded by a restrict rule, statistics and log on, a fresh store. */
function uiSettings(string $dir, string $more = ''): Settings
{
    file_put_contents("$dir/site.rules", "restrict **/rs/** to 203.0.113.0/24\nset stats on\nset store-dir $dir/store\nset log $dir/shield.log\n$more");
    touch("$dir/shield.log");
    return Settings::load("$dir/site.rules", "$dir/cache-" . md5($more));
}

/** Every page the dashboard serves, rendered: path => HTML. @return array<string, string> */
function uiPages(Settings $s, string $lang = 'en'): array
{
    $out = [];
    foreach (array_keys($s->routes) as $path) {
        $request = Request::fromServer(['REQUEST_URI' => "$path?lang=$lang", 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'www.example.org', 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Mozilla/5.0']);
        $route = Dashboard::routeFor($s, $request);
        if ($route === null) {
            continue;
        }
        $r = Dashboard::serve($s, $request, $route, ['lang' => $lang], []);
        if ($r->status === 200 && strpos(implode("\n", $r->headers), 'text/html') !== false) {
            $out[$path] = $r->body;
        }
    }
    return $out;
}

/** The anchors a page of the docs has, as GitHub makes them from its headings. @return array<string, true> */
function uiAnchors(string $file): array
{
    $out = [];
    $fence = false;
    foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $line) {
        if (preg_match('/^\s*```/', $line) === 1) {
            $fence = !$fence;
        } elseif (!$fence && preg_match('/^#{1,6}\s+(.+?)\s*$/', $line, $m) === 1) {
            $h = (string) preg_replace('/`([^`]*)`/', '$1', $m[1]);
            $out[str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{N} _-]/u', '', mb_strtolower(trim($h))))] = true;
        }
    }
    return $out;
}

/** Where a "?" points, if it is a page of these docs with that heading; else why not. */
function uiTarget(string $href): string
{
    $href = html_entity_decode($href, ENT_QUOTES);
    if (strncmp($href, Help::DOCS . '/', strlen(Help::DOCS) + 1) !== 0) {
        return "not into the docs: $href";
    }
    [$path, $anchor] = array_pad(explode('#', substr($href, strlen(Help::DOCS) + 1), 2), 2, '');
    $file = dirname(__DIR__) . '/docs/' . $path;
    if (!is_file($file)) {
        return "no page docs/$path";
    }
    return $anchor === '' || isset(uiAnchors($file)[$anchor]) ? 'ok' : "no heading #$anchor in docs/$path";
}

return [
    'RSF06-01 every page of the dashboard: a "?" after the title and on every section, each to a page of the docs and a heading there -- in English and German' => function (): void {
        $dir = sys_get_temp_dir() . '/rs-uihelp-' . getmypid() . '-' . mt_rand();
        mkdir($dir);
        try {
            $s = uiSettings($dir);
            foreach (['en', 'de'] as $lang) {
                $pages = uiPages($s, $lang);
                truthy(count($pages) >= 7, "the pages ($lang): " . implode(', ', array_keys($pages)));
                // Rules & setup brings its own styles (the steps as circles): the route is the core's, not the statistics'.
                truthy(strpos((string) ($pages['/rs/waf/rules'] ?? ''), '.way .step{') !== false && strpos((string) ($pages['/rs/waf/rules'] ?? ''), 'table.settings{') !== false, 'Rules & setup has the styles of its steps');
                foreach ($pages as $path => $html) {
                    truthy(preg_match('#<h1>(?:(?!</h1>).)*<a class="rs-help"[^>]*>\?</a></h1>#s', $html) === 1, "$path ($lang): a \"?\" after the title");
                    preg_match_all('#<h2[^>]*>(.*?)</h2>#s', $html, $h2);
                    truthy($h2[1] !== [] || strpos($path, '/waf/live') !== false || substr($path, -4) === '/waf', "$path ($lang): sections");
                    foreach ($h2[1] as $heading) {
                        truthy(strpos($heading, 'class="rs-help"') !== false, "$path ($lang): the section \"" . strip_tags($heading) . '" has its "?"');
                    }
                    preg_match_all('#<a class="rs-help" href="([^"]+)"[^>]*title="(RSF\d{2}-\d{2}) [^"]+"#', $html, $links, PREG_SET_ORDER);
                    truthy($links !== [], "$path ($lang): links with their feature's id");
                    foreach ($links as [, $href, $id]) {
                        same('ok', uiTarget($href), "$path ($lang): $href");
                        truthy(strpos($href, "/features/$id-") !== false, "$path: the title names the page's id $id");
                    }
                }
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-01 empty, a part says how it gets something: the live view, the lists, the bans, the statistics, the visitors\' cards' => function (): void {
        $dir = sys_get_temp_dir() . '/rs-uihelp-' . getmypid() . '-' . mt_rand();
        mkdir($dir);
        try {
            $pages = uiPages(uiSettings($dir));
            $all = implode("\n", $pages);
            foreach ([
                'Nothing stopped yet: a row appears as soon as the shield refuses, checks or bans a request',
                'No entries yet: add an address above, or keep one out from a row of the live view.',
                'No address is banned right now: a ban rule (ban …) bans an address for a while',
                'Nothing yet: the numbers come as requests arrive, counted per hour.',
                'Nothing in this period: pick a longer one above, or come back after the next hour is counted.',
            ] as $sentence) {
                truthy(strpos($all, htmlspecialchars($sentence, ENT_QUOTES)) !== false, "said where it is empty: $sentence");
            }
            $de = implode("\n", uiPages(uiSettings($dir), 'de'));
            truthy(strpos($de, 'Noch nichts: Die Zahlen kommen mit den Anfragen, gezählt pro Stunde.') !== false, 'in German too');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF06-01 set docs-url: the "?" follow it; off, no page has one and the sentences stay' => function (): void {
        $dir = sys_get_temp_dir() . '/rs-uihelp-' . getmypid() . '-' . mt_rand();
        mkdir($dir);
        try {
            $own = implode("\n", uiPages(uiSettings($dir, "set docs-url https://docs.example.org/rs\n")));
            truthy(substr_count($own, 'href="https://docs.example.org/rs/features/RSF') > 10 && strpos($own, Help::DOCS) === false, 'every link to the site\'s own copy');
            $off = uiPages(uiSettings($dir, "set docs-url off\n"));
            truthy(count($off) >= 7, 'the pages');
            foreach ($off as $path => $html) {
                truthy(strpos($html, 'class="rs-help"') === false, "$path: no \"?\"");
            }
            truthy(strpos(implode("\n", $off), 'Nothing yet: the numbers come as requests arrive') !== false, 'the sentences stay');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF03-02 the check page tells a visitor what it is: a link to the check in plain words, escaped, in the visitor\'s language; none with the links off' => function (): void {
        $task = ['algorithm' => 'SHA-256', 'challenge' => 'x', 'maxnumber' => 1, 'salt' => 's', 'signature' => 's'];
        $page = ChallengePage::render($task, 'rss', true, \CjwNetwork\RequestShield\Texts::all('de'), null, null, null, Help::explained());
        truthy(strpos($page, '<a href="' . Help::DOCS . '/explained/browser-check.md" target="_blank" rel="noopener">Was ist diese Prüfung?</a>') !== false, 'the link, in German');
        same('ok', uiTarget(Help::DOCS . '/explained/browser-check.md'), 'the page is there');
        truthy(strpos(ChallengePage::render($task, 'rss', true, [], null, null, null, '/d"x'), 'href="/d&quot;x"') !== false, 'escaped');
        truthy(strpos(ChallengePage::render($task, 'rss', true), 'What is this check?') === false, 'without one: no link');
        same(null, Help::explained(''), 'off');
        truthy(strpos(\CjwNetwork\RequestShield\Challenge\Widget::script(), "a.className = 'rs-about'") !== false, 'the box in a form adds it too, from the endpoint\'s answer');
    },
];
