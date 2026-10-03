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
 * A page of the dashboard: what a route's 'page' class answers when the
 * shield serves the route (Dashboard::serve(), 0031 B.6). Called only for a
 * request the rules let through, after Access::gate() said who is reading
 * and that the route's role allows them. The answer gets the dashboard's
 * headers (no-store, noindex, the gate's cookies) in front.
 */
interface RoutePage
{
    /**
     * @param array{key: string, ext: ?string, tab: ?array{0: string, 1: string}, role: string, order: int, page?: string, path: string} $route the matched route (Routes::match())
     * @param array{who: string, prefix: string, get: array<mixed>, post: array<mixed>, method: string, lang: string, accept: ?string,
     *   links: array<string, string>, ip: string, home: string, homeLabel: string, ruleFile: ?string} $ctx
     *   who: '*' (the administrator) or a group's id; prefix: what stands before the route in the address (a front
     *   controller: "/demo/index.php"), so links are prefix . path; links: the tabs the reader may see, prefixed;
     *   lang: what ?lang says (auto, en, de); ruleFile: the rule file the shield runs from (to touch after a change)
     */
    public static function serve(Settings $s, Request $request, array $route, array $ctx): Response;
}
