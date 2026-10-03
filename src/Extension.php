<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield;

use CjwNetwork\RequestShield\Rules\Vocabulary;

/**
 * An extension: words and settings of its own in the rule file, checked when
 * the rules are compiled -- never at request time (ADR 0008). Everything
 * here is static: the compiler asks, the answers end up in the compiled
 * settings (`$s->ext[<id>]`, `$s->routes`), and a request reads those.
 *
 * What runs per request is a `Plugin`, named by the rules or appended by
 * compile(); an extension may be both. The shield's own statistics are the
 * first extension (plugins/stats); `tests/support/RsTestExtension.php` is
 * the smallest one.
 */
interface Extension
{
    /** The id: letters, digits, "-" (stats, rs-test). Its settings live in ext.<id>. */
    public static function id(): string;

    /** Its words (rules) and set keys: $v->word(), $v->set(). */
    public static function vocabulary(Vocabulary $v): void;

    /**
     * Checks what the rule file wrote into ext.<id> and returns the values the
     * plugin reads at request time; the whole base settings are there to
     * check against (hosts, dashboard-path, …). A wrong value is an
     * InvalidArgumentException naming the setting (Settings::wrong('ext.<id>.<key>', …)):
     * on the request path the last good compiled settings stay in force.
     *
     * @param array<string, mixed> $raw what the parser collected (ext.<id>)
     * @return array<string, mixed> the checked values, as the plugin wants them
     */
    public static function compile(array $raw, Settings $base): array;

    /**
     * The Plugin classes to run per request, given the compiled slot: the
     * compiler appends them to the settings' plugins (0031 B.4), so a request
     * reads one list as before. [] for an extension that only speaks at
     * compile time.
     *
     * @param array<string, mixed> $compiled what compile() returned
     * @return list<class-string>
     */
    public static function plugins(array $compiled): array;

    /**
     * The pages under dashboard-path it serves: path => what the page is
     * (0031 B.5 defines the entries; until then []).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function routes(): array;

    /**
     * Its command-line commands: name => how to run it (0031 D.1 defines the
     * entries; until then []).
     *
     * @return array<string, mixed>
     */
    public static function commands(): array;

    /**
     * Warnings for `request-shield check` about the compiled settings: a plugin
     * class that is not there, a page without access rules (0031 B.4).
     *
     * @return list<string>
     */
    public static function check(Settings $s): array;
}
