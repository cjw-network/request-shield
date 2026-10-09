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
 * A capability on a Plugin (0031 G.5, proposal 0039): the application
 * changed content, in the same PHP process -- an HTTP cache makes the
 * answers with these tags out of date, with no PURGE request. Called through
 * Shield::active()?->purge() by an adapter (a CMS plugin on publish);
 * nothing is asked when no plugin has it. A plugin that throws is noted once
 * a minute and the application goes on.
 */
interface Purger
{
    /**
     * These tags are out of date ("*": everything). Tags are what the
     * answers carried (xkey, X-Cache-Tags …): each value is split at whitespace
     * and commas, as those headers are; a piece over 200 bytes or with
     * other than visible characters is no tag an answer can carry, and is
     * left out.
     *
     * @param list<string> $tags
     */
    public function purge(array $tags): void;
}
