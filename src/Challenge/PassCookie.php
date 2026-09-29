<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Challenge;

/**
 * The cookie a client gets for a solved challenge: "v1.<expires>.<tag>.<mac>".
 * Signed, bound to the client's bucket (and its User-Agent, unless switched
 * off), checked without any storage -- so it works across every process and
 * server that shares the secret.
 */
final class PassCookie
{
    public function __construct(private string $secret, private bool $bindUserAgent = true)
    {
    }

    public function issue(string $bucket, string $userAgent, int $expires): string
    {
        $tag = $this->tag($bucket, $userAgent);
        return 'v1.' . $expires . '.' . $tag . '.' . $this->mac($expires, $tag);
    }

    /** When a pass cookie expires (0 for none): with the pass lifetime, when it was issued. Check valid() first. */
    public function expires(string $cookie): int
    {
        $parts = explode('.', $cookie);
        return isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : 0;
    }

    public function valid(?string $cookie, string $bucket, string $userAgent, float $now): bool
    {
        if ($cookie === null || strlen($cookie) > 200) {
            return false;
        }
        $parts = explode('.', $cookie);
        if (count($parts) !== 4 || $parts[0] !== 'v1' || !ctype_digit($parts[1])) {
            return false;
        }
        [, $expires, $tag, $mac] = $parts;
        return (int) $expires >= $now
            && hash_equals($this->tag($bucket, $userAgent), $tag)
            && hash_equals($this->mac((int) $expires, $tag), $mac);
    }

    private function tag(string $bucket, string $userAgent): string
    {
        return substr(hash_hmac('sha256', 'pass|' . $bucket . '|' . ($this->bindUserAgent ? $userAgent : ''), $this->secret), 0, 16);
    }

    private function mac(int $expires, string $tag): string
    {
        return substr(hash_hmac('sha256', 'v1|' . $expires . '|' . $tag, $this->secret), 0, 32);
    }
}
