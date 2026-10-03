<?php

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Tests;

use CjwNetwork\RequestShield\Extension;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Rules\Vocabulary;
use CjwNetwork\RequestShield\Settings;

/**
 * The smallest extension, for the tests: two settings and one word, and a
 * place to fail on purpose (`set fail-at <stage>`), so the fail-safe tests
 * can make the shield throw at a chosen stage (0031 B.2, C.3).
 */
final class RsTestExtension implements Extension
{
    /** Where the extension (and, from 0031 C.3, its plugin) throws when asked to. */
    public const STAGES = ['compile', 'rules', 'handler', 'pages', 'sink', 'decided', 'ended'];

    public static function id(): string
    {
        return 'rs-test';
    }

    public static function vocabulary(Vocabulary $v): void
    {
        $v->set('fail-at', 'string', 'where the test extension throws: ' . implode(', ', self::STAGES),
            static function ($value, string $at) {
                if (!in_array($value, self::STAGES, true)) {
                    throw new RuleFileException("$at: fail-at is one of " . implode(', ', self::STAGES) . ', not "' . (is_scalar($value) ? (string) $value : gettype($value)) . '"');
                }
                return $value;
            });
        $v->set('marks-max', 'int', 'how many marks the rules may set');
        $v->word('rs-test-mark', static function (array $args, array $values, string $at, string $rid): array {
            if ($args === []) {
                throw new RuleFileException("$at: rs-test-mark <words>");
            }
            /** @var list<string> $marks */
            $marks = is_array($values['marks'] ?? null) ? $values['marks'] : [];
            foreach ($args as $a) {
                $marks[] = $a;
            }
            $values['marks'] = $marks;
            $values['by'] = $rid;
            return $values;
        }, 'rs-test-mark <words>: remembered in ext.rs-test.marks');
    }

    public static function compile(array $raw, Settings $base): array
    {
        $failAt = $raw['failAt'] ?? null;
        if ($failAt === 'compile') {
            throw Settings::wrong('ext.rs-test.fail-at', 'a stage after compile -- it failed at compile, as asked');
        }
        /** @var list<string> $marks */
        $marks = is_array($raw['marks'] ?? null) ? array_values($raw['marks']) : [];
        $max = $raw['marksMax'] ?? null;
        if (is_int($max) && count($marks) > $max) {
            throw Settings::wrong('ext.rs-test.marks', "at most $max marks (marks-max)");
        }
        return ['failAt' => is_string($failAt) ? $failAt : null, 'marks' => $marks, 'hosts' => $base->hosts];
    }

    public static function plugins(array $compiled): array
    {
        return [];
    }

    public static function routes(): array
    {
        return [];
    }

    public static function commands(): array
    {
        return [];
    }

    public static function check(Settings $s): array
    {
        return [];
    }
}
