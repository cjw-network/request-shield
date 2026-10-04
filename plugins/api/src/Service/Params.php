<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Api\Service;


use CjwNetwork\RequestShield\ApiProblem;

/** The parameters of a call, checked: a missing or wrong one is a 400 that names it. */
final class Params
{
    /** @param array<string, mixed> $p */
    public static function string(array $p, string $name, ?string $default = null, int $max = 2048): string
    {
        $v = $p[$name] ?? null;
        if ($v === null || $v === '') {
            if ($default === null) {
                throw new ApiProblem(400, 'Bad request', "$name is missing.");
            }
            return $default;
        }
        if (!is_scalar($v) || strlen((string) $v) > $max) {
            throw new ApiProblem(400, 'Bad request', "$name is a text of at most $max characters.");
        }
        return trim((string) $v);
    }

    /** @param array<string, mixed> $p */
    public static function int(array $p, string $name, int $default, int $min, int $max): int
    {
        $v = $p[$name] ?? null;
        if ($v === null || $v === '') {
            return $default;
        }
        if (!is_numeric($v) || (int) $v != $v || (int) $v < $min || (int) $v > $max) {
            throw new ApiProblem(400, 'Bad request', "$name is a whole number from $min to $max.");
        }
        return (int) $v;
    }

    /** @param array<string, mixed> $p */
    public static function bool(array $p, string $name): bool
    {
        $v = $p[$name] ?? false;
        if (is_bool($v)) {
            return $v;
        }
        if (in_array($v, ['1', 'true', 'on', 'yes', 1], true)) {
            return true;
        }
        if (in_array($v, ['', '0', 'false', 'off', 'no', 0, null], true)) {
            return false;
        }
        throw new ApiProblem(400, 'Bad request', "$name is true or false.");
    }

    /**
     * A message without the server's paths: a file below the rule file's
     * folder by its path from there, any other by its name.
     */
    public static function relative(string $text, ?string $ruleFile): string
    {
        $dir = $ruleFile !== null ? rtrim(dirname($ruleFile), '/') . '/' : null;
        if ($dir !== null && $dir !== '/') {
            $text = str_replace($dir, '', $text);
        }
        return (string) preg_replace_callback('#(?<![\w.])/(?:[^\s:"\'/()]+/)+([^\s:"\'/()]+)#', static fn (array $m): string => $m[1], $text);
    }

    /** The main rule file, or a 409: settings from a PHP array have none to read, test or touch. */
    public static function ruleFile(?string $file): string
    {
        if ($file === null || !is_file($file)) {
            throw new ApiProblem(409, 'Conflict', 'The shield runs from no rule file here (settings from a PHP array): nothing to read, test or reload.');
        }
        return $file;
    }
}
