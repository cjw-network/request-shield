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
 * A capability on a Plugin (0031 C.4): it may answer a request the shield
 * lets through, before the application -- an HTTP cache hit, a page of its
 * own. Asked after every rule and the shield's own pages, for passing
 * requests only, one array access when no plugin has it. The first Response
 * wins and goes out as it is (its headers are the plugin's); null means "on to
 * the application"; a handler that throws is noted once a minute and the
 * application runs. A refused or checked request never reaches a handler.
 */
interface Handler
{
    /**
     * @param Decision $decision what the rules decided (allow or allow-uncached: cacheable() says which)
     */
    public function handle(Request $request, Decision $decision): ?Response;
}
