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
 * A capability on a Plugin (0031 G.4): it may answer a request whose method
 * the site does not take (`methods`) -- an HTTP cache's PURGE, PURGEKEYS --
 * before the rules, which would refuse it (405) or its host (a purge client
 * sends Host: 127.0.0.1). Asked only for such a method: a GET, a POST never
 * gets here. Null means "not mine": the rules then decide as for any unknown
 * method -- so a plugin answers only clear cases (its own addresses, a
 * token) and everyone else meets the 405 they would have met anyway. A
 * plugin that throws is noted once a minute and the rules decide.
 */
interface MethodHandler
{
    public function handleMethod(Request $request): ?Response;
}
