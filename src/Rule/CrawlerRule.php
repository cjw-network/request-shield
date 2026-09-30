<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Rule;

use CjwNetwork\RequestShield\Challenge\Crawlers;
use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Request;

/**
 * Known crawlers a site refuses (crawlers ai-training block, crawler
 * CRAWL-GPTBOT block): 403 -- for those that ignore robots.txt. Only a
 * verified one: a request that merely borrows the name is an ordinary
 * visitor. Built only when some crawler is refused; one expression over the
 * User-Agent for every other request.
 */
final class CrawlerRule implements Rule
{
    public function __construct(private Crawlers $crawlers)
    {
    }

    public function check(Request $request, float $now): ?Decision
    {
        $id = $this->crawlers->claims((string) $request->header('user-agent'));
        if ($id === null || $this->crawlers->policy($id) !== 'block' || !$this->crawlers->verified($request->clientIp, $id)) {
            return null;
        }
        return Decision::reject(403, 'crawler');
    }
}
