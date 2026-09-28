<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Rule;

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Request;

/**
 * Only the site's own hosts: "example.org", or "*.example.org" for every
 * subdomain. A request for any other host never reaches the application, so
 * it can neither render nor cache a page for a host someone made up.
 */
final class HostRule implements Rule
{
    /** @param list<string> $hosts */
    public function __construct(private readonly array $hosts)
    {
    }

    public function check(Request $request, float $now): ?Decision
    {
        foreach ($this->hosts as $allowed) {
            $allowed = strtolower($allowed);
            if ($allowed === $request->host) {
                return null;
            }
            if (strncmp($allowed, '*.', 2) === 0) {
                $suffix = substr($allowed, 1);
                if (strlen($request->host) > strlen($suffix) && substr($request->host, -strlen($suffix)) === $suffix) {
                    return null;
                }
            }
        }
        return Decision::reject(404, 'host');
    }
}
