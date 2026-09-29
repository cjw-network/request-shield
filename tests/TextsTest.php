<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Challenge\ChallengePage;
use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Responder;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Texts;

return [
    'the language: the browser\'s, among those there are texts for; else English' => function (): void {
        same('de', Texts::language('auto', 'de-DE,de;q=0.9,en;q=0.8'));
        same('en', Texts::language('auto', 'en-US,en;q=0.9,de;q=0.8'));
        same('de', Texts::language('auto', 'fr-FR,fr;q=0.9,de;q=0.7,en;q=0.5'), 'no French texts: the next the browser takes');
        same('en', Texts::language('auto', 'fr-FR,fr;q=0.9'), 'nothing that fits: English');
        same('en', Texts::language('auto', null), 'no header');
        same('en', Texts::language('auto', '*'));
        same('de', Texts::language('auto', 'en;q=0.5, de;q=0.9'), 'by weight, not by position');
        same('fr', Texts::language('auto', 'fr-FR,fr;q=0.9', ['fr.title' => 'Un instant']), 'a language the site has texts for');
        same('de', Texts::language('de', 'en-US'), 'fixed by the site');
    },
    'the texts: the site\'s for the language, the site\'s for all, built in, English' => function (): void {
        $de = Texts::all('de');
        same('Einen Moment, bitte', $de['title']);
        same('de', $de['lang']);
        same('Bitte versuchen Sie es in %s Sekunden noch einmal.', $de['try-again']);
        $own = ['title' => 'Checking…', 'de.text' => 'Wir prüfen kurz.', 'fr.title' => 'Un instant'];
        same(['Checking…', 'Wir prüfen kurz.'], [Texts::all('de', $own)['title'], Texts::all('de', $own)['text']], 'for all, then for one');
        same('Un instant', Texts::all('fr', $own)['title']);
        same(Texts::BUILT_IN['en']['text'], Texts::all('fr', $own)['text'], 'what French lacks: English');
        same('Wir prüfen kurz.', Texts::all('de-at', $own)['text'], 'de-at falls back to de');
        foreach (Texts::KEYS as $key) {
            truthy(isset(Texts::BUILT_IN['de'][$key], Texts::BUILT_IN['en'][$key]), "every text in German and English: $key");
        }
    },
    'rule files: set language, set text.<lang>.<key>; errors name file and line' => function (): void {
        $dir = sys_get_temp_dir() . '/rshield-texts-' . getmypid() . '-' . mt_rand();
        mkdir($dir);
        try {
            file_put_contents("$dir/site.rules", "set language de\nset text.title Checking\nset text.de.title Einen Augenblick\nset text.fr.title Un instant\n");
            $s = Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
            same('de', $s->challenge->language);
            same(['title' => 'Checking', 'de.title' => 'Einen Augenblick', 'fr.title' => 'Un instant'], $s->challenge->texts);
            foreach (["set language klingon1\n" => 'language is auto or a code', "set text.de.titel x\n" => 'unknown text "titel"', "set text.D E.title x\n" => 'set <key> <value>'] as $rule => $says) {
                file_put_contents("$dir/site.rules", $rule);
                try {
                    RuleFile::read(["$dir/site.rules"]);
                    throw new TestFailure("accepted: $rule");
                } catch (InvalidArgumentException $e) {
                    truthy(strpos($e->getMessage(), 'site.rules:1: ') === 0 || strpos($e->getMessage(), 'site.rules:1:') !== false, $e->getMessage());
                }
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'the pages in the language: the check page, a pause, "not found"' => function (): void {
        $challenge = ['algorithm' => 'SHA-256', 'challenge' => str_repeat('a', 64), 'maxnumber' => 1000, 'salt' => 's', 'signature' => str_repeat('b', 64)];
        $page = ChallengePage::render($challenge, 'rs_solution', false, Texts::all('de'));
        truthy(strpos($page, '<html lang="de">') !== false && strpos($page, 'Einen Moment, bitte') !== false && strpos($page, 'Bitte aktivieren Sie JavaScript') !== false, 'the check page in German');
        $r = Request::fromServer(['REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET']);
        ob_start();
        (new Responder())->send(Decision::throttle('requests', 7), $r, false, null, null, Texts::all('de', ['de.try-again' => 'Noch %s Sekunden – 100 % sicher.']));
        $html = (string) ob_get_clean();
        truthy(strpos($html, '<h1>Zu viele Anfragen</h1>') !== false, $html);
        truthy(strpos($html, 'Noch 7 Sekunden – 100 % sicher.') !== false, 'a "%" of the site\'s own does no harm');
        ob_start();
        (new Responder())->send(Decision::reject(404, 'blocked path'), $r);
        truthy(strpos((string) ob_get_clean(), '<h1>Not Found</h1>') !== false, 'English without texts');
        ob_start();
        (new Responder())->send(Decision::reject(403, 'restricted'), $r, false, null, null, Texts::all('de'), '/start?a=1&b="2"');
        truthy(strpos((string) ob_get_clean(), '<a href="/start?a=1&amp;b=&quot;2&quot;">Zur Startseite</a>') !== false, 'a link home, escaped');
        foreach (['javascript:alert(1)', 'www.example.org', "/x\ny"] as $bad) {
            try {
                Settings::from(['challenge' => ['home' => $bad]]);
                throw new TestFailure("accepted home $bad");
            } catch (InvalidArgumentException $e) {
                truthy(strpos($e->getMessage(), 'challenge.home') !== false, $e->getMessage());
            }
        }
    },
];
