<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Challenge\ChallengeLogo;
use CjwNetwork\RequestShield\Challenge\ChallengePage;
use CjwNetwork\RequestShield\Challenge\Gate;
use CjwNetwork\RequestShield\ChallengeSettings;
use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Settings;

/** How the check page looks: the ring, the logo in its middle, a site's own logo -- checked strictly. */

const PAGE_TASK = ['algorithm' => 'SHA-256', 'challenge' => 'x', 'maxnumber' => 1000, 'salt' => 's?expires=1', 'signature' => 'y'];

function logoFile(string $svg): string
{
    $file = sys_get_temp_dir() . '/rshield-logo-' . getmypid() . '-' . mt_rand() . '.svg';
    file_put_contents($file, $svg);
    return $file;
}

function logoRefused(string $svg, string $part): void
{
    try {
        ChallengeLogo::check($svg, 'challenge.logo');
    } catch (InvalidArgumentException $e) {
        truthy(strncmp($e->getMessage(), 'challenge.logo: the logo is refused -- ', 39) === 0, 'names the setting: ' . $e->getMessage());
        truthy(stripos($e->getMessage(), $part) !== false, "says \"$part\": " . $e->getMessage());
        return;
    }
    throw new TestFailure('accepted: ' . $svg);
}

return [
    'the page: a ring that fills, a dot circling a plain shield, a smile and a calm "!" ready -- no motion for those who ask for none' => function (): void {
        $page = ChallengePage::render(PAGE_TASK, 'rs_solution', false);
        truthy(strpos($page, '<svg id="r" viewBox="0 0 120 120"') !== false, 'the ring');
        truthy(strpos($page, '<circle id="b" class="f"') !== false, 'its arc, filled by the progress');
        truthy(strpos($page, '<g class="o">') !== false, 'the circling dot');
        truthy(strpos($page, ChallengeLogo::DEFAULT) !== false, 'the plain shield');
        truthy(strpos($page, '<g class="s">') !== false && strpos($page, '<g class="x">') !== false, 'the smile and the "!"');
        truthy(strpos($page, '@media(prefers-reduced-motion:reduce){.run .o{animation:none}') !== false, 'no circling with reduced motion');
        truthy(strpos($page, '@media(prefers-color-scheme:dark){:root{') !== false, 'dark colours');
        truthy(strpos($page, '.run .o{animation:') !== false && strpos($page, "state('run')") !== false, 'the dot circles only once the script runs (not without JavaScript)');
        truthy(strpos($page, "state('ok')") !== false && strpos($page, 'setTimeout(function () {') !== false, 'done: the smile, then on at once');
        same(false, strpos($page, 'id="p"'), 'the old bar is gone');
        truthy(strlen($page) < 10000, 'still small: ' . strlen($page) . ' bytes');
        truthy(strpos(ChallengePage::render(PAGE_TASK, 'rs_solution', false, [], ['action' => '/x', 'fields' => []]), '<form id="resend"') !== false, 'a form to send again: as before');
    },
    'a site\'s own logo: checked, inlined in the middle, its IDs kept apart from the page\'s' => function (): void {
        $svg = '<?xml version="1.0"?><!-- made by hand --><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" width="200" class="big">'
            . '<defs><linearGradient id="m"><stop offset="0" stop-color="#f00"/></linearGradient></defs>'
            . '<rect id="b" width="10" height="10" fill="url(#m)"/><use href="#b"/></svg>';
        $logo = ChallengeLogo::check($svg, 'challenge.logo');
        $head = '<svg x="32" y="32" width="56" height="56" viewBox="0 0 10 10"';
        truthy(strncmp($logo, $head, strlen($head)) === 0, 'in the ring\'s middle, with its own viewBox: ' . substr($logo, 0, 90));
        truthy(strpos($logo, 'width="200"') === false && strpos($logo, 'class="big"') === false, 'its own size and class are replaced');
        truthy(strpos($logo, 'id="rsl-m"') !== false && strpos($logo, 'fill="url(#rsl-m)"') !== false && strpos($logo, 'href="#rsl-b"') !== false, 'IDs prefixed, references too -- the page\'s #m and #b stay the page\'s');
        truthy(strpos($logo, 'made by hand') === false && strpos($logo, '<?xml') === false, 'no comments, no declaration');
        same('0 0 30 20', (string) preg_replace('/.*viewBox="([^"]*)".*/s', '$1', ChallengeLogo::check('<svg width="30" height="20"><rect width="3" height="3"/></svg>', 'x')), 'no viewBox: from width and height');
        $page = ChallengePage::render(PAGE_TASK, 'rs_solution', false, [], null, null, $logo);
        truthy(strpos($page, $logo) !== false && strpos($page, ChallengeLogo::DEFAULT) === false, 'on the page instead of the shield');
        $file = logoFile($svg);
        try {
            $c = ChallengeSettings::from(['logo' => $file]);
            same($logo, $c->logo, 'read when the settings are read');
            $gate = new Gate($c, str_repeat('s', 40));
            $r = Request::fromServer(['REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'x', 'REMOTE_ADDR' => '203.0.113.7']);
            truthy(strpos((string) $gate->resolve(Decision::challenge('requests'), Decision::allow(), $r, 1.0)['page'], $logo) !== false, 'the check page shows it');
            same($logo, Settings::import(Settings::from(['challenge' => ['logo' => $file]])->export())->challenge->logo, 'compiled with the settings');
        } finally {
            unlink($file);
        }
        same(null, ChallengeSettings::from([])->logo, 'none: the plain shield');
    },
    'a logo that could run or load something is refused, with the setting named' => function (): void {
        $cases = [
            ['<svg viewBox="0 0 1 1"><script>x()</script></svg>', '<script>'],
            ['<svg viewBox="0 0 1 1"><foreignObject><div/></foreignObject></svg>', '<foreignObject>'],
            ['<svg viewBox="0 0 1 1"><iframe/></svg>', '<iframe>'],
            ['<svg viewBox="0 0 1 1"><style>body{display:none}</style></svg>', 'style the whole page'],
            ['<svg viewBox="0 0 1 1" onload="x()"/>', 'event handler'],
            ['<svg viewBox="0 0 1 1"><rect onclick="x()"/></svg>', 'event handler'],
            ['<svg viewBox="0 0 1 1"><image href="https://example.org/x.png"/></svg>', 'links outside'],
            ['<svg xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 1 1"><use xlink:href="other.svg#a"/></svg>', 'links outside'],
            ['<svg viewBox="0 0 1 1"><a href="data:text/html,x"><rect/></a></svg>', 'links outside'],
            ['<svg viewBox="0 0 1 1"><rect style="fill:url(https://example.org/x)"/></svg>', 'url()'],
            ['<svg viewBox="0 0 1 1"><rect style="@import \'x.css\'"/></svg>', 'url(), @import'],
            ['<svg viewBox="0 0 1 1"><rect fill="url(http://example.org/p)"/></svg>', 'points outside'],
            ['<svg viewBox="0 0 1 1"><set attributeName="href" to="#x"/></svg>', 'animation changes a link'],
            ['<svg viewBox="0 0 1 1"><rect x="java&#x09;script:x"/></svg>', 'script address'],
            ['<!DOCTYPE svg [<!ENTITY a "b">]><svg viewBox="0 0 1 1"/>', 'DOCTYPE'],
            ['<html><body/></html>', 'not <svg>'],
            ['<svg viewBox="0 0 1 1"><rect></svg>', 'not well-formed'],
            ['<svg><rect/></svg>', 'neither a viewBox'],
            ['<svg viewBox="0 0 1 1">' . str_repeat('<rect/>', 3000) . '</svg>', 'larger than 16 KB'],
        ];
        foreach ($cases as [$svg, $part]) {
            logoRefused($svg, $part);
        }
        try {
            ChallengeSettings::from(['logo' => '/nonexistent/logo.svg']);
            throw new TestFailure('a missing file was accepted');
        } catch (InvalidArgumentException $e) {
            truthy(strpos($e->getMessage(), 'challenge.logo: cannot read') === 0, $e->getMessage());
        }
    },
    'rule files: set challenge-logo, relative to the file; a refused logo names its line; a changed logo is noticed' => function (): void {
        $dir = sys_get_temp_dir() . '/rshield-logo-rules-' . getmypid() . '-' . mt_rand();
        mkdir($dir);
        try {
            file_put_contents("$dir/logo.svg", '<svg viewBox="0 0 4 4"><circle cx="2" cy="2" r="2"/></svg>');
            file_put_contents("$dir/bad.svg", '<svg viewBox="0 0 4 4" onload="x()"/>');
            file_put_contents("$dir/site.rules", "\nset challenge-logo logo.svg\n");
            $read = RuleFile::read(["$dir/site.rules"]);
            truthy(strpos((string) Settings::from($read['config'])->challenge->logo, '<circle cx="2" cy="2" r="2"') !== false, 'read and inlined');
            truthy(isset($read['seen']["$dir/logo.svg"]), 'watched like a rule file');
            file_put_contents("$dir/site.rules", "set challenge-logo bad.svg\n");
            try {
                RuleFile::read(["$dir/site.rules"]);
                throw new TestFailure('accepted a logo with a handler');
            } catch (RuleFileException $e) {
                truthy(strncmp($e->getMessage(), 'site.rules:1: challenge-logo: the logo is refused -- it has an event handler', 76) === 0, $e->getMessage());
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
