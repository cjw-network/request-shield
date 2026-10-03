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
 * A capability on a Plugin (0031 B.9): it hears what the log hears -- every
 * request the shield did something about (and, at log-level all, every one),
 * as the record Log::note() has. Recorded into the compiled settings
 * (`$s->hooks['sink']`) when the rules are compiled; asked only where the log
 * is written, so a passing request pays nothing. A sink that throws is left
 * out for that request and noted in PHP's error log once a minute.
 *
 * A sink writes addresses masked as the log does (Log::mask()), unless it has
 * the same reason the log has for a full one (set log-ip full). The live view
 * (Live) is the first sink; a CMS logger or a Monolog handler are others.
 */
interface Sink
{
    /**
     * @param ?string $rule the rule that decided, as the log names it (null: none)
     * @param bool $monitor the decision was only watched (monitor): nothing was enforced
     */
    public function note(Request $request, Decision $decision, ?string $rule, float $now, bool $monitor): void;
}
