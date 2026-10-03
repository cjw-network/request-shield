<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Cli;

/**
 * A command of bin/request-shield that an extension adds (Extension::commands(),
 * 0031 D.1): `request-shield <name> <main.rules> [options]`. The script reads
 * the rule file, compiles the settings and parses the common options into a
 * Context; the command prints to stdout and stderr and returns the exit code.
 */
interface Command
{
    /** The usage line, as the script prints it: "request-shield stats <main.rules> [--days=7] …". */
    public static function usage(): string;

    /** Runs it; the exit code (0 fine, 1 a mistake in the installation, 2 a mistake in the call). */
    public static function run(Context $c): int;
}
