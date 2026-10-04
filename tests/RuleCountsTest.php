<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Counts;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\Vocabulary;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Stats\StatsPlugin;
use CjwNetwork\RequestShield\Tests\CountingPlugin;
use CjwNetwork\RequestShield\Tests\RsTestExtension;

/**
 * The RuleCounts capability (0031 B.8): a plugin that counts is recorded into
 * the compiled settings' hooks, and the rules page asks the hook -- never a
 * plugin by name. Uses ruleDir() from RuleFileTest.php.
 */

function countsSettings(string $rules): Settings
{
    $dir = ruleDir(['site.rules' => $rules]);
    try {
        return Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
    } finally {
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

return [
    'RSF06-04 hooks: a plugin with the capability is recorded when the rules are compiled; one without is not; the statistics plugin has it when the statistics are on' => function (): void {
        same([], countsSettings("host a.example\n")->hooks, 'no plugin: no hook');
        $s = countsSettings("host a.example\nplugin CjwNetwork\\RequestShield\\Tests\\CountingPlugin\n");
        same(['ruleCounts' => [CountingPlugin::class]], $s->hooks, 'recorded by instanceof, by hook name');
        same(['ruleCounts' => [StatsPlugin::class]], countsSettings("set stats on\n")->hooks, 'the statistics plugin: on, so appended by its extension, and it counts');
        same([], countsSettings("set stats off\n")->hooks, 'off: no plugin, no hook');
        same(['ruleCounts' => [CountingPlugin::class, StatsPlugin::class]], countsSettings("plugin CjwNetwork\\RequestShield\\Tests\\CountingPlugin\nset stats on\n")->hooks, 'both, in the plugins\' order');
        $round = Settings::import(eval('return ' . var_export($s->export(), true) . ';'));
        same($s->hooks, $round->hooks, 'the hooks travel with the compiled settings');
        same(['ruleCounts' => ['Acme\\Shield\\RefusalAlert']], countsSettings("plugin Acme\\Shield\\RefusalAlert\n")->hooks === [] ? ['ruleCounts' => ['Acme\\Shield\\RefusalAlert']] : [], 'a class that is not there is recorded nowhere (check warns about the plugin)');
    },
    'RSF06-04 Counts::rules(): the plugins\' numbers added up; a failing plugin is left out; nothing on the request path asks' => function (): void {
        Vocabulary::forget();
        try {
            Vocabulary::offer(RsTestExtension::class);
            CountingPlugin::$asked = [];
            $s = countsSettings("plugin CjwNetwork\\RequestShield\\Tests\\CountingPlugin\n");
            same(['T-1' => 21, 'built-in' => 1], Counts::rules($s, 7, 1000.0), 'the plugin\'s counts for 7 days');
            same([7 => 1], CountingPlugin::$asked, 'asked once, for the days wanted');
            same(null, Counts::crawlers($s, 7, 1000.0), 'no plugin counts crawlers: null');
            $failing = countsSettings("plugin CjwNetwork\\RequestShield\\Tests\\CountingPlugin\nset fail-at counts\n");
            same([], Counts::rules($failing, 7, 1000.0), 'a plugin that throws: left out, the page is drawn without its numbers');
            same([], Counts::rules(countsSettings("host a.example\n"), 7, 1000.0), 'no hook: nothing asked');
        } finally {
            Vocabulary::forget();
            Vocabulary::offer(\CjwNetwork\RequestShield\Stats\StatsExtension::class);
        }
    },
    'RSF06-04 the rules and setup page knows no plugin by name: src/Report has no StatsReport' => function (): void {
        foreach (['RulesPage.php', 'SetupPage.php'] as $f) {
            $code = (string) file_get_contents(dirname(__DIR__) . '/src/Report/' . $f);
            truthy(strpos($code, 'StatsReport::') === false && strpos($code, 'StatsPage::') === false && strpos($code, "Report\\\\StatsPage") === false, "$f asks Counts, not the statistics: no StatsReport::/StatsPage:: call");
        }
        truthy(strpos((string) file_get_contents(dirname(__DIR__) . '/src/Counts.php'), 'hooks[\'ruleCounts\']') !== false, 'Counts reads the hook');
    },
];
