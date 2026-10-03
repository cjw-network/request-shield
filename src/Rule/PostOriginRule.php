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
use CjwNetwork\RequestShield\IpAddress;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Settings;

/**
 * Forms only from the website itself (post-origin same, proposal 0028): a
 * POST, PUT, PATCH or DELETE must come from a page of one of the website's own
 * names -- the browser's Origin header, else the Referer. Another website: 403
 * (a form sent in the visitor's name: cross-site request forgery). Neither
 * header: the browser check (default), let through, or refused.
 *
 * Not a bot defence: a script sets both headers as it likes. It stops a foreign
 * page from sending a form with the visitor's cookies.
 */
final class PostOriginRule implements Rule
{
    private const METHODS = ['POST' => true, 'PUT' => true, 'PATCH' => true, 'DELETE' => true];

    /**
     * @param list<string> $names the website's own names (exact, or *.domain: one label); [] the name the request was sent to
     * @param string $missing neither header: check, allow or refuse
     * @param list<string> $except paths it never applies to (a payment provider's callback, single sign-on), as patterns
     * @param list<string> $apiPaths the site's API (api-path): authenticated otherwise
     * @param list<string> $exempt addresses let in
     */
    public function __construct(
        private array $names,
        private string $missing,
        private array $except,
        private array $apiPaths,
        private array $exempt,
    ) {
    }

    public function check(Request $request, float $now): ?Decision
    {
        if (!isset(self::METHODS[$request->method])) {
            return null;
        }
        $path = $request->matchPath();
        foreach ([$this->except, $this->apiPaths] as $patterns) {
            foreach ($patterns as $p) {
                if (@preg_match($p, $path) === 1) {
                    return null;
                }
            }
        }
        $from = self::sentFrom($request);
        if ($from === null) {
            $d = $this->missing === 'allow' ? null
                : ($this->missing === 'refuse' ? Decision::reject(403, 'origin missing') : Decision::challenge('origin missing'));
        } else {
            $d = $this->own($from, $request->host) ? null : Decision::reject(403, 'cross-site');
        }
        // An address let in: looked up only when it would be stopped (the lookup costs more than the rest).
        return $d !== null && $this->exempt !== [] && IpAddress::inRanges($request->clientIp, $this->exempt) ? null : $d;
    }

    /** The host a form was sent from: the Origin's, else the Referer's; null when neither says ("null" counts as nothing). */
    public static function sentFrom(Request $request): ?string
    {
        foreach (['origin', 'referer'] as $header) {
            $v = trim((string) $request->header($header));
            if ($v === '' || $v === 'null') {
                continue;
            }
            $host = parse_url($v, PHP_URL_HOST);
            return is_string($host) && $host !== '' ? rtrim(strtolower($host), '.') : '';
        }
        return null;
    }

    /** Whether a host is one of the website's own names (without names: the one the request was sent to). */
    private function own(string $host, string $requestHost): bool
    {
        if ($host === '') {
            return false;
        }
        if ($this->names === []) {
            return $host === $requestHost;
        }
        foreach ($this->names as $name) {
            if ($name === $host) {
                return true;
            }
            if (strncmp($name, '*.', 2) === 0) {
                $suffix = substr($name, 1);
                $label = substr($host, 0, -strlen($suffix));
                if (strlen($host) > strlen($suffix) && substr($host, -strlen($suffix)) === $suffix && strpos($label, '.') === false) {
                    return true;
                }
            }
        }
        return false;
    }

    public function explain(Decision $d, Request $request, Settings $s): ?string
    {
        return $d->reason === 'cross-site' || $d->reason === 'origin missing' ? $s->ruleName('postOrigin', '*', 'postOrigin') : null;
    }
}
