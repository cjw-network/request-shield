<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Cli;

use CjwNetwork\RequestShield\Settings;

/**
 * What a command gets from the script (0031 D.1): the compiled settings, the
 * rule file, what the rule files held (RuleFile::read()), the word after the
 * file ("update", an address), and the common options as parsed.
 */
final class Context
{
    /**
     * @param array<string, mixed> $read what RuleFile::read() returned (config, seen, env, recheck, examples)
     * @param list<string> $sources the --source globs
     * @param array<string, mixed> $options the common options: days (int), json (bool), force (bool), ip, ua,
     *   period (from, to, by, site, crawler, path, sort), feed (format, write), test (only, asWritten, junit),
     *   list (for, until, reason)
     * @param list<string> $args every argument as given
     */
    public function __construct(
        /** @readonly */
        public string $command,
        /** @readonly */
        public Settings $settings,
        /** @readonly */
        public string $file,
        /** @readonly */
        public array $read,
        /** @readonly */
        public array $sources,
        /** @readonly */
        public ?string $what,
        /** @readonly */
        public array $options,
        /** @readonly */
        public array $args,
    ) {
    }

    /** An option's value, typed by its default. */
    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }
}
