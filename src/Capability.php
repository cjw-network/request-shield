<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield;

/**
 * What this PHP can do (ADR 0013): asked in one place, so every use of an
 * optional extension sits behind the same check and has a way without it.
 */
final class Capability
{
    /** APCu, enabled for this SAPI (the CLI needs apc.enable_cli=1). */
    public static function apcu(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }
}
