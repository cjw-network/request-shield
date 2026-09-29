<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Rules;

/**
 * Simple path patterns, turned into the regular expressions the checks use.
 *
 *   /wp-admin/**   /wp-admin and everything below it
 *   /page/*        /page/about, not /page/a/b      (* stays within one segment)
 *   *.sql          any path ending in .sql         (no leading /: anywhere)
 *   /file?.txt     ? is one character, not /
 *
 * A pattern starting with two stars and a slash matches in any directory:
 * the demo's admin pattern finds /admin and /shop/admin/x alike.
 */
final class Pattern
{
    public static function fromGlob(string $glob): string
    {
        // "/..." from the root; "**/..." already anywhere; else anywhere.
        $anchored = $glob !== '' && ($glob[0] === '/' || strncmp($glob, '**/', 3) === 0);
        $out = '';
        $n = strlen($glob);
        for ($i = 0; $i < $n; $i++) {
            $c = $glob[$i];
            if ($c === '*' && ($glob[$i + 1] ?? '') === '*') {
                // "/**" at the end also matches the directory itself.
                if ($i + 2 === $n && substr($out, -1) === '/') {
                    $out = substr($out, 0, -1) . '(?:/.*)?';
                } else {
                    $out .= '.*';
                }
                $i++;
            } elseif ($c === '*') {
                $out .= '[^/]*';
            } elseif ($c === '?') {
                $out .= '[^/]';
            } else {
                $out .= preg_quote($c, '#');
            }
        }
        return '#^' . ($anchored ? '' : '(?:.*/)?') . $out . '$#';
    }

    /** A regular expression written without delimiters, as rule files have it. */
    public static function fromRegex(string $regex): string
    {
        return '#' . str_replace('#', '\\#', $regex) . '#';
    }

    public static function valid(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            return preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }
}
