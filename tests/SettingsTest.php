<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Settings;

function expectInvalid(array $config, string $key): void
{
    try {
        Settings::from($config);
    } catch (InvalidArgumentException $e) {
        truthy(strpos($e->getMessage(), "'$key'") !== false, "names $key: " . $e->getMessage());
        return;
    }
    throw new TestFailure("accepted a wrong $key");
}

return [
    'defaults are valid; values are typed and normalised' => function (): void {
        $s = Settings::from(['methods' => ['get', 'post'], 'hosts' => ['WWW.Example.org'], 'budgets' => ['x' => ['limit' => '5', 'window' => '30']]]);
        same(['GET', 'POST'], $s->methods);
        same(['www.example.org'], $s->hosts);
        same(5, $s->budgets['x']->limit);
        same(30, $s->budgets['x']->window);
        same(null, $s->budgets['x']->challengeAt);
        truthy(isset($s->budgets['requests']), 'the default budget stays');
        same(null, Settings::from(['budgets' => ['requests' => ['limit' => 0]]])->budgets['requests'] ?? null, 'limit 0 switches a budget off');
        truthy($s->challenge->searchEngines !== null && count($s->challenge->searchEngines) > 3, 'crawler list by default');
        same(null, Settings::from(['challenge' => ['searchEngines' => false]])->challenge->searchEngines);
    },
    'a wrong type is an error naming the key' => function (): void {
        expectInvalid(['hosts' => 'example.org'], 'hosts');
        expectInvalid(['trustedProxies' => [1]], 'trustedProxies');
        expectInvalid(['limits' => ['uri' => 'long']], 'limits.uri');
        expectInvalid(['budgets' => ['requests' => ['limit' => 'many']]], 'budgets.requests.limit');
        expectInvalid(['budgets' => ['x' => 5]], 'budgets.x');
        expectInvalid(['debugHeader' => 'yes'], 'debugHeader');
        expectInvalid(['challenge' => ['secret' => 'short']], 'challenge.secret');
        expectInvalid(['challenge' => ['cookie' => 'a b']], 'challenge.cookie');
        expectInvalid(['challenge' => ['searchEngines' => 'google']], 'challenge.searchEngines');
        expectInvalid(['cacheable' => ['query' => 'page']], 'cacheable.query');
    },
    'contentRules: targets and patterns checked, one expression per target' => function (): void {
        $s = Settings::from(['contentRules' => [
            ['target' => 'query', 'patterns' => ['#a#', '#b#i']],
            ['target' => 'HEADER:User-Agent', 'patterns' => ['#c#']],
            ['target' => 'query', 'patterns' => ['#d#']],
        ]]);
        same('header:user-agent', $s->contentRules[1]['target'], 'the header name lower-cased');
        same('#(?:a)|(?:b)|(?:d)#i', $s->contentIndex['query'], 'all of a target\'s patterns in one expression');
        same('#(?:c)#i', $s->contentIndex['header:user-agent']);
        truthy(Settings::from(['contentRules' => []])->contentIndex === [], 'none: no index, no work on the request path');
        expectInvalid(['contentRules' => [['target' => 'body', 'patterns' => ['#x#']]]], 'contentRules.0.target');
        expectInvalid(['contentRules' => [['patterns' => ['#x#']]]], 'contentRules.0.target');
        expectInvalid(['contentRules' => [['target' => 'header:Bad_Name', 'patterns' => ['#x#']]]], 'contentRules.0.target');
        expectInvalid(['contentRules' => [['target' => 'query', 'patterns' => ['/x/']]]], 'contentRules.0.patterns');
        expectInvalid(['contentRules' => [['target' => 'query', 'patterns' => ['#[#']]]], 'contentRules.0.patterns');
        // A back reference or a named group would point into another pattern
        // of the combined expression -- refused.
        expectInvalid(['contentRules' => [['target' => 'query', 'patterns' => ['#(a)\\1#']]]], 'contentRules.0.patterns');
        expectInvalid(['contentRules' => [['target' => 'query', 'patterns' => ['#(?<n>a)#']]]], 'contentRules.0.patterns');
        expectInvalid(['contentRules' => ['not a map']], 'contentRules.0');
    },
    'export and import lose nothing' => function (): void {
        $s = Settings::from(['trustedProxies' => ['10.0.0.0/8'], 'hosts' => ['a.example'], 'cacheable' => ['query' => ['page'], 'paths' => null],
            'budgets' => ['misses' => ['limit' => 9, 'window' => 10, 'onDemand' => true]], 'challenge' => ['secret' => str_repeat('k', 40), 'texts' => ['title' => 'T']]]);
        $round = Settings::import(eval('return ' . var_export($s->export(), true) . ';'));
        same(serialize($s), serialize($round));
    },
    'load(): compiled once, reused, rebuilt when the file changes' => function (): void {
        $dir = sys_get_temp_dir() . '/rshield-load-' . getmypid() . '-' . mt_rand();
        mkdir($dir);
        $file = $dir . '/config.php';
        file_put_contents($file, "<?php return ['hosts' => ['one.example']];");
        same(['one.example'], Settings::load($file, $dir . '/cache')->hosts);
        $compiled = glob($dir . '/cache/settings-*.php');
        same(1, count($compiled), 'one compiled file');
        same('0600', substr(sprintf('%o', fileperms($compiled[0])), -4));

        // The compiled file answers: the settings file is not run again.
        chmod($file, 0000);
        $readable = @file_get_contents($file) !== false;     // root reads anyway
        chmod($file, 0600);
        if (!$readable) {
            chmod($file, 0000);
            touch($file, filemtime($file));
            same(['one.example'], Settings::load($file, $dir . '/cache')->hosts, 'from the compiled file');
            chmod($file, 0600);
        }

        // A change (other size, same second) is seen.
        file_put_contents($file, "<?php return ['hosts' => ['two.example', 'x.example']];");
        same(['two.example', 'x.example'], Settings::load($file, $dir . '/cache')->hosts);
        // An invalid change is an error, not the old settings.
        file_put_contents($file, "<?php return ['hosts' => 'broken'];");
        try {
            Settings::load($file, $dir . '/cache');
            throw new TestFailure('accepted a broken file');
        } catch (InvalidArgumentException $e) {
        }
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'load(): a missing file is an error' => function (): void {
        try {
            Settings::load('/no/such/request-shield.php', sys_get_temp_dir());
            throw new TestFailure('no error');
        } catch (RuntimeException $e) {
            truthy(strpos($e->getMessage(), 'cannot read') !== false, $e->getMessage());
        }
    },
];
