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
 * The cookie a client gets for a solved challenge:
 * "2.<expires base36>.<tag base64url, 11>.<mac base64url, 22>" -- 43 bytes,
 * sent by the browser on every request while the pass lasts. Signed, bound
 * to the client's bucket (and its User-Agent, unless switched off), checked
 * without any storage -- so it works across every process and server that
 * shares the secret. The tag is 64 bits of an HMAC, the MAC 128 bits.
 */
final class PassCookie
{
    /** How long the encoded expiry may be: base36 of a Unix time (6 digits until 2038, 7 until 2059). */
    private const EXPIRES = '/^[0-9a-z]{1,8}$/';

    public function __construct(private string $secret, private bool $bindUserAgent = true)
    {
    }

    public function issue(string $bucket, string $userAgent, int $expires): string
    {
        $tag = $this->tag($bucket, $userAgent);
        $exp = base_convert((string) max(0, $expires), 10, 36);
        return '2.' . $exp . '.' . $tag . '.' . $this->mac($exp, $tag);
    }

    /** When a pass cookie expires (0 for none): with the pass lifetime, when it was issued. Check valid() first. */
    public function expires(string $cookie): int
    {
        $parts = explode('.', $cookie, 3);
        return isset($parts[1]) && preg_match(self::EXPIRES, $parts[1]) === 1 ? (int) base_convert($parts[1], 36, 10) : 0;
    }

    public function valid(?string $cookie, string $bucket, string $userAgent, float $now): bool
    {
        if ($cookie === null || strlen($cookie) > 64) {
            return false;
        }
        $parts = explode('.', $cookie);
        if (count($parts) !== 4 || $parts[0] !== '2' || preg_match(self::EXPIRES, $parts[1]) !== 1) {
            return false;
        }
        [, $exp, $tag, $mac] = $parts;
        return (int) base_convert($exp, 36, 10) >= $now
            && hash_equals($this->tag($bucket, $userAgent), $tag)
            && hash_equals($this->mac($exp, $tag), $mac);
    }

    private function tag(string $bucket, string $userAgent): string
    {
        return self::encode(substr(hash_hmac('sha256', 'pass|' . $bucket . '|' . ($this->bindUserAgent ? $userAgent : ''), $this->secret, true), 0, 8));
    }

    private function mac(string $exp, string $tag): string
    {
        return self::encode(substr(hash_hmac('sha256', '2|' . $exp . '|' . $tag, $this->secret, true), 0, 16));
    }

    /** base64url without padding: cookie-safe, 4 characters per 3 bytes. */
    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
