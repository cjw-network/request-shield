<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Extension;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Rules\Vocabulary;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Tests\RsTestExtension;

/**
 * Extensions (ADR 0008, 0031 B.2): words and settings of their own in the
 * rule file, collected into ext.<id>, checked by Extension::compile() with the
 * base settings in hand. Uses ruleDir()/rulesFail() from RuleFileTest.php.
 */

/** Runs $body with the registry empty; afterwards the extensions offered before (the bootstrap's) are back (the registry is per process). */
function withRegistry(callable $body): void
{
    $before = Vocabulary::extensions();
    Vocabulary::forget();
    try {
        $body();
    } finally {
        Vocabulary::forget();
        foreach ($before as $class) {
            Vocabulary::offer($class);
        }
    }
}

/** The config a rule text compiles to (no site), the directory removed. */
function extConfig(string $text, ?string $site = null): array
{
    $dir = ruleDir(['site.rules' => $text]);
    try {
        return RuleFile::read(["$dir/site.rules"], $site)['config'];
    } finally {
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

function extInvalid(string $text, string $key): void
{
    try {
        Settings::from(extConfig($text));
    } catch (InvalidArgumentException $e) {
        truthy(strpos($e->getMessage(), "'$key'") !== false, "names $key: " . $e->getMessage());
        return;
    }
    throw new TestFailure("accepted at compile: $text");
}

return [
    'not offered: an extension\'s words and settings are unknown, as before' => function (): void {
        withRegistry(function (): void {
            rulesFail(['site.rules' => "set fail-at rules\n"], 'site.rules:1', 'unknown setting "fail-at"');
            rulesFail(['site.rules' => "rs-test-mark a\n"], 'site.rules:1', 'unknown rule "rs-test-mark"');
            same([], Vocabulary::known()['words']);
        });
    },
    'offered: set and word land in ext.<id>, typed at the line; compile() checks them with the base settings, the result round-trips' => function (): void {
        withRegistry(function (): void {
            Vocabulary::offer(RsTestExtension::class);
            Vocabulary::offer(RsTestExtension::class);          // again: nothing
            same(['rs-test' => RsTestExtension::class], Vocabulary::extensions());
            $text = "host a.example\nset fail-at rules\nset marks-max 5\n[T-1] rs-test-mark a b\nrs-test-mark c\n";
            $raw = extConfig($text)['ext']['rs-test'] ?? null;
            same(['failAt' => 'rules', 'marksMax' => 5, 'marks' => ['a', 'b', 'c'], 'by' => 'site.rules:5'], $raw, 'the parser\'s values: typed, the word fed its values so far and the rule id');
            $s = Settings::from(extConfig($text));
            same(['failAt' => 'rules', 'marks' => ['a', 'b', 'c'], 'hosts' => ['a.example']], $s->ext['rs-test'] ?? null, 'compile(): checked, shaped, the base settings in hand');
            $round = Settings::import(eval('return ' . var_export($s->export(), true) . ';'));
            same(serialize($s), serialize($round), 'export/import keep it');
            same(null, Settings::from(extConfig("host a.example\n"))->ext['rs-test'] ?? null, 'nothing written: no slot');
            // Wrong at the line: the extension's check, the type.
            rulesFail(['site.rules' => "set fail-at nowhere\n"], 'site.rules:1', 'fail-at is one of compile, rules');
            rulesFail(['site.rules' => "set marks-max many\n"], 'site.rules:1', 'marks-max is a number');
            rulesFail(['site.rules' => "rs-test-mark\n"], 'site.rules:1', 'rs-test-mark <words>');
            rulesFail(['site.rules' => "set fail-att x\n"], 'site.rules:1', 'unknown setting "fail-att" (did you mean "fail-at"?)');
            rulesFail(['site.rules' => "rs-test-marks x\n"], 'site.rules:1', 'unknown rule "rs-test-marks" (did you mean "rs-test-mark"?)');
            rulesFail(['site.rules' => "match /x/** {\nrs-test-mark a\n}\n"], 'site.rules:2', 'does not go inside a match block');
            // Wrong at compile: an InvalidArgumentException naming the setting -- on the request path the last good settings stay.
            extInvalid("set fail-at compile\n", 'ext.rs-test.fail-at');
            extInvalid("set marks-max 1\nrs-test-mark a b\n", 'ext.rs-test.marks');
        });
    },
    'inside a site block: the website\'s own values, compiled for that website' => function (): void {
        withRegistry(function (): void {
            Vocabulary::offer(RsTestExtension::class);
            $text = "host a.example b.example\nrs-test-mark base\nsite b.example {\n  set fail-at sink\n  rs-test-mark b\n}\n";
            same(['failAt' => null, 'marks' => ['base']], array_intersect_key(Settings::from(extConfig($text))->ext['rs-test'] ?? [], ['marks' => 1, 'failAt' => 1]), 'the base');
            same(['failAt' => 'sink', 'marks' => ['base', 'b']], array_intersect_key(Settings::from(extConfig($text, 'b.example'))->ext['rs-test'] ?? [], ['marks' => 1, 'failAt' => 1]), 'the website: the base\'s values and its own');
        });
    },
    'plugin <class>: a class that is an extension is offered for the rest of the reading; one that is not stays a plugin as before' => function (): void {
        withRegistry(function (): void {
            $c = extConfig("plugin CjwNetwork\\RequestShield\\Tests\\RsTestExtension\nset fail-at rules\n");
            same('rules', $c['ext']['rs-test']['failAt'] ?? null, 'known from the plugin line on');
            same([], $c['plugins'] ?? [], 'not a Plugin (nothing to run per request): not in the plugins list');
            same(RsTestExtension::class, Vocabulary::extension('rs-test'));
            Vocabulary::forget();                                 // offered per process: a fresh one does not know it yet
            rulesFail(['site.rules' => "set fail-at rules\nplugin CjwNetwork\\RequestShield\\Tests\\RsTestExtension\n"], 'site.rules:1', 'unknown setting "fail-at"');
            same(['Acme\\Shield\\RefusalAlert'], extConfig("plugin Acme\\Shield\\RefusalAlert\n")['plugins'] ?? null, 'a class that is not there: recorded, check warns');
        });
    },
    'the registry refuses what would collide: a core word or setting, a second extension with the same id, a wrong id' => function (): void {
        withRegistry(function (): void {
            $bad = new class implements Extension {
                public static function id(): string { return 'rs-test'; }
                public static function vocabulary(Vocabulary $v): void { }
                public static function compile(array $raw, Settings $base): array { return $raw; }
                public static function plugins(array $compiled): array { return []; }
                public static function routes(): array { return []; }
                public static function commands(): array { return []; }
                public static function check(Settings $s): array { return []; }
            };
            Vocabulary::offer(RsTestExtension::class);
            foreach ([get_class($bad) => 'two extensions with the id rs-test', 'stdClass' => 'is not an extension'] as $class => $part) {
                try {
                    Vocabulary::offer($class);
                    throw new TestFailure("accepted $class");
                } catch (InvalidArgumentException $e) {
                    truthy(strpos($e->getMessage(), $part) !== false, $e->getMessage());
                }
            }
            Vocabulary::forget();
            $core = new class implements Extension {
                public static function id(): string { return 'core-ish'; }
                public static function vocabulary(Vocabulary $v): void { $v->word('block', static fn (array $a, array $v): array => $v); }
                public static function compile(array $raw, Settings $base): array { return $raw; }
                public static function plugins(array $compiled): array { return []; }
                public static function routes(): array { return []; }
                public static function commands(): array { return []; }
                public static function check(Settings $s): array { return []; }
            };
            Vocabulary::offer(get_class($core));
            try {
                Vocabulary::wordFor('x');
                throw new TestFailure('a core word was taken');
            } catch (InvalidArgumentException $e) {
                truthy(strpos($e->getMessage(), '"block" is a word of the core') !== false, $e->getMessage());
            }
            Vocabulary::forget();
            $setKey = new class implements Extension {
                public static function id(): string { return 'core-ish'; }
                public static function vocabulary(Vocabulary $v): void { $v->set('debug-header', 'bool'); }
                public static function compile(array $raw, Settings $base): array { return $raw; }
                public static function plugins(array $compiled): array { return []; }
                public static function routes(): array { return []; }
                public static function commands(): array { return []; }
                public static function check(Settings $s): array { return []; }
            };
            Vocabulary::offer(get_class($setKey));
            try {
                Vocabulary::settingFor('x');
                throw new TestFailure('a core setting was taken');
            } catch (InvalidArgumentException $e) {
                truthy(strpos($e->getMessage(), '"set debug-header" is a setting of the core') !== false, $e->getMessage());
            }
        });
    },
];
