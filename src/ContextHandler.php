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
 * A capability on a Plugin (0031 G.4, proposal 0039): the application tells
 * it who the visitor is, in the same PHP process -- an HTTP cache keeps a
 * page once per role instead of none for everyone with a login. Called
 * through Shield::active()?->cacheContext() by an adapter (a WordPress
 * plugin, an Exponential extension) while the application runs; nothing is
 * asked when no plugin has it. A plugin that throws is noted once a minute
 * and the application goes on.
 */
interface ContextHandler
{
    /**
     * The visitor of $request has the role $context (a hash of the user's
     * roles, "editor+author"): remembered for the session the request
     * carries. $shared: this answer is the same for everyone with the role
     * and may be kept for them.
     */
    public function cacheContext(Request $request, string $context, bool $shared): void;

    /** The visitor of $request signed out: the session's role is forgotten. */
    public function forgetContext(Request $request): void;
}
