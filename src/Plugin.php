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
 * A plugin: told what the shield decided, and how the request ended. The
 * core stays the firewall; anything else -- the statistics, metrics, alerts,
 * exports -- is a plugin (proposal 0023).
 *
 * Registered in a rule file (`plugin Vendor\Package\MyPlugin`) or in the PHP
 * settings ('plugins' => [MyPlugin::class]); made once per request with
 * `new MyPlugin($settings)`. A plugin cannot change a decision, and an error
 * in it never reaches the visitor: the shield catches it and logs it (once a
 * minute).
 */
interface Plugin
{
    /**
     * After the decision, before the answer: every request the shield saw.
     *
     * @param ?string $rule the rule that decided (null: none, or a request let through)
     * @param bool $continues the site answers it: ended() follows when it is done
     *   (false: the shield answered itself, or the caller wants it counted now)
     * @param ?Decision $would in monitor mode: what would have been decided
     */
    public function decided(Request $request, Decision $decision, ?string $rule, Seen $seen, float $now, bool $continues, ?Decision $would = null): void;

    /**
     * When a request that went on to the site has ended ($continues was true):
     * the status and headers the site sent.
     *
     * @param int $status the HTTP status (0 when unknown)
     * @param list<string> $headers headers_list()
     */
    public function ended(Request $request, int $status, array $headers, Seen $seen, float $now): void;
}
