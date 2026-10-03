<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Log;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\MemoryStore;

/**
 * `plugin <class> from <file>` (0031 D.2): a site without Composer names the
 * file that holds the plugin, relative to the rule file; the compiler loads it
 * (an extension's words are known from that line on, its capabilities are
 * recorded), the shield loads it only when it makes its plugins. Uses
 * ruleDir() from RuleFileTest.php.
 */

const FROM_PLUGIN = <<<'PHP'
<?php
namespace Acme\FromFile;

use CjwNetwork\RequestShield\{Decision, Plugin, Request, Seen, Settings, Sink};

final class Heard implements Plugin, Sink
{
    /** @var list<string> */
    public static array $paths = [];

    public function __construct(private Settings $settings) {}
    public function decided(Request $request, Decision $decision, ?string $rule, Seen $seen, float $now, bool $continues, ?Decision $would = null): void {}
    public function ended(Request $request, int $status, array $headers, Seen $seen, float $now): void {}
    public function note(Request $request, Decision $decision, ?string $rule, float $now, bool $monitor): void
    {
        self::$paths[] = $request->path;
    }
}
PHP;

const FROM_EXTENSION = <<<'PHP'
<?php
namespace Acme\FromFile;

use CjwNetwork\RequestShield\Extension;
use CjwNetwork\RequestShield\Rules\Vocabulary;
use CjwNetwork\RequestShield\Settings;

final class Words implements Extension
{
    public static function id(): string { return 'acme'; }
    public static function vocabulary(Vocabulary $v): void { $v->set('acme-level', 'int', 'a level'); }
    public static function compile(array $raw, Settings $base): array { return ['level' => is_int($raw['level'] ?? null) ? $raw['level'] : 0]; }
    public static function plugins(array $compiled): array { return []; }
    public static function routes(array $compiled): array { return []; }
    public static function commands(): array { return []; }
    public static function check(Settings $s): array { return []; }
}
PHP;

return [
    'plugin … from <file>: the file is recorded relative to the rule file, loaded when the rules are compiled (its capability recorded) and when the shield makes its plugins' => function (): void {
        $dir = ruleDir(['site.rules' => "host a.example\nplugin Acme\\FromFile\\Heard from plugins/heard.php\n", 'plugins/heard.php' => FROM_PLUGIN]);
        try {
            $read = RuleFile::read(["$dir/site.rules"]);
            same(['Acme\\FromFile\\Heard' => "$dir/plugins/heard.php"], $read['config']['pluginFiles'] ?? null, 'recorded, relative to the rule file');
            truthy(isset($read['seen']["$dir/plugins/heard.php"]), 'the plugin file is watched like a rule file');
            $s = Settings::from($read['config']);
            same(['Acme\\FromFile\\Heard'], $s->plugins);
            same(['sink' => ['Acme\\FromFile\\Heard']], $s->hooks, 'its capability recorded: the compiler loaded the file');
            $shield = new Shield($s, new MemoryStore());
            same(1, count($shield->plugins()), 'the shield made it');
            \Acme\FromFile\Heard::$paths = [];
            Log::note($s, Request::fromServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/x', 'HTTP_HOST' => 'a.example', 'REMOTE_ADDR' => '203.0.113.7'], []), Decision::reject(404, 'blocked path'), 'SCAN', microtime(true));
            same(['/x'], \Acme\FromFile\Heard::$paths, 'and it hears what the log hears');
            $again = RuleFile::read(["$dir/site.rules"]);
            truthy(isset($again['seen']["$dir/plugins/heard.php"]), 'watched on every compile, also when the class is loaded already');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'an extension from a file: its words are known from that line on; a file that is not there is a warning (check), not an error -- the plugin is left out' => function (): void {
        \CjwNetwork\RequestShield\Rules\Vocabulary::forget();
        $dir = ruleDir(['site.rules' => "host a.example\nplugin Acme\\FromFile\\Words from acme/words.php\nset acme-level 3\n", 'acme/words.php' => FROM_EXTENSION,
            'missing.rules' => "host a.example\nplugin Acme\\FromFile\\Nowhere from acme/nowhere.php\n"]);
        try {
            $s = Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
            same(['level' => 3], $s->ext['acme'] ?? null, 'the extension\'s set key, known after its plugin line, compiled by it');
            same([], $s->plugins, 'an extension that is no Plugin runs nothing per request');
            $read = RuleFile::read(["$dir/missing.rules"]);
            truthy(count(array_filter($read['config']['origins']['warnings'] ?? [], static fn (string $w): bool => strpos($w, 'acme/nowhere.php is not there') !== false)) === 1, 'a warning, not an error: ' . json_encode($read['config']['origins']['warnings'] ?? []));
            $s2 = Settings::from($read['config']);
            same(0, count((new Shield($s2, new MemoryStore()))->plugins()), 'left out; the site stays up');
            if (function_exists('exec')) {
                exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/request-shield') . ' check ' . escapeshellarg("$dir/missing.rules") . ' 2>&1', $out, $code);
                truthy(strpos(implode("\n", $out), 'nowhere.php -- the file is not there') !== false, 'check names it: ' . implode(' | ', $out));
            }
        } finally {
            \CjwNetwork\RequestShield\Rules\Vocabulary::forget();
            \CjwNetwork\RequestShield\Rules\Vocabulary::offer(\CjwNetwork\RequestShield\Stats\StatsExtension::class);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
