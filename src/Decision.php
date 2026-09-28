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
 * What the shield decided about a request.
 *
 *   ALLOW           the application runs, and may cache the answer
 *   ALLOW_UNCACHED  the application runs, but must not keep the answer
 *                   (a URL outside the definition: rendered, never stored)
 *   CHALLENGE       the client has to prove it is a browser first
 *   THROTTLE        429 Too Many Requests, with Retry-After
 *   REJECT          an error status; the application never runs
 */
final class Decision
{
    public const ALLOW = 'allow';
    public const ALLOW_UNCACHED = 'allow-uncached';
    public const CHALLENGE = 'challenge';
    public const THROTTLE = 'throttle';
    public const REJECT = 'reject';

    private function __construct(
        public readonly string $action,
        public readonly int $status,
        public readonly string $reason,
        public readonly int $retryAfter = 0,
        public readonly float $level = 0.0,
    ) {
    }

    public static function allow(): self
    {
        static $allow = null;          // immutable, so one is enough
        return $allow ??= new self(self::ALLOW, 200, '');
    }

    public static function allowUncached(string $reason): self
    {
        return new self(self::ALLOW_UNCACHED, 200, $reason);
    }

    /** @param float $level how far the client is past the threshold: 0 there, 1 at the limit */
    public static function challenge(string $reason, float $level = 0.0): self
    {
        return new self(self::CHALLENGE, 429, $reason, 0, max(0.0, min(1.0, $level)));
    }

    public static function throttle(string $reason, int $retryAfter): self
    {
        return new self(self::THROTTLE, 429, $reason, max(1, $retryAfter));
    }

    public static function reject(int $status, string $reason): self
    {
        return new self(self::REJECT, $status, $reason);
    }

    /** Whether the application gets to run. */
    public function passes(): bool
    {
        return $this->action === self::ALLOW || $this->action === self::ALLOW_UNCACHED;
    }

    /** Whether the application may keep its answer in a cache. */
    public function cacheable(): bool
    {
        return $this->action === self::ALLOW;
    }

    /** The more restrictive of two decisions. */
    public function stricter(self $other): self
    {
        static $rank = [
            self::ALLOW => 0, self::ALLOW_UNCACHED => 1, self::CHALLENGE => 2,
            self::THROTTLE => 3, self::REJECT => 4,
        ];
        return $rank[$other->action] > $rank[$this->action] ? $other : $this;
    }
}
