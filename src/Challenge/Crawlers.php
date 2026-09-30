<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Challenge;

use CjwNetwork\RequestShield\IpAddress;
use CjwNetwork\RequestShield\Settings;

/**
 * The known crawlers (proposal 0011): which one a User-Agent names, and
 * whether the request really comes from it -- by the operator's published
 * address list (a comparison, no network) or by DNS (reverse and forward). A
 * name proves nothing; only the address does.
 *
 * Asked only when it matters (a crawler that would be checked, or one the site
 * refuses). A DNS answer is remembered per address and crawler for a day.
 */
final class Crawlers
{
    /**
     * @param array<string, array{kind: string, ua: string, dns: list<string>, ranges: list<string>, policy: string, nets: array<string, list<array{0: string, 1: int}>>}> $crawlers
     * @param list<string> $ids the crawler of each (*MARK:n) in $index
     * @param string $verify both, ranges (no DNS) or dns
     * @param (\Closure(string): ?string)|null $cacheGet
     * @param (\Closure(string, string): void)|null $cacheSet
     * @param (callable(string): (string|false))|null $reverse
     * @param (callable(string): list<string>)|null $forward
     * @param (\Closure(): bool)|null $mayLookUp asked before every new DNS lookup (see SearchEngines)
     */
    public function __construct(
        private array $crawlers,
        private string $index,
        private array $ids,
        private string $verify = 'both',
        private ?\Closure $cacheGet = null,
        private ?\Closure $cacheSet = null,
        private $reverse = null,
        private $forward = null,
        private ?\Closure $mayLookUp = null,
    ) {
    }

    /** The shipped or configured crawlers of the settings, with these caches and DNS functions. */
    public static function of(Settings $s, ?\Closure $cacheGet = null, ?\Closure $cacheSet = null, ?\Closure $mayLookUp = null, ?callable $reverse = null, ?callable $forward = null): self
    {
        return new self($s->crawlers, $s->crawlerIndex, $s->crawlerIds, $s->crawlerVerify, $cacheGet, $cacheSet, $reverse, $forward, $mayLookUp);
    }

    /** The ID of the crawler a User-Agent names, or null. One expression for all of them. */
    public function claims(string $userAgent): ?string
    {
        if ($this->index === '' || $userAgent === '' || preg_match($this->index, $userAgent, $m) !== 1 || !isset($m['MARK'])) {
            return null;
        }
        return $this->ids[(int) $m['MARK']] ?? null;
    }

    /** What the site does with it: allow, check or block. */
    public function policy(string $id): string
    {
        return $this->crawlers[$id]['policy'] ?? 'check';
    }

    public function kind(string $id): string
    {
        return $this->crawlers[$id]['kind'] ?? '';
    }

    /** Whether the address is one of the crawler's: its published list, or its DNS names. */
    public function verified(string $ip, string $id): bool
    {
        $x = $this->crawlers[$id] ?? null;
        if ($x === null) {
            return false;
        }
        // The published list: a lookup of about half a microsecond, no need to remember it.
        if ($this->verify !== 'dns' && $x['nets'] !== [] && IpAddress::inIndex($ip, $x['nets'])) {
            return true;
        }
        if ($this->verify === 'ranges' || $x['dns'] === []) {
            return false;
        }
        // DNS: remembered for a day, a lookup costs milliseconds.
        $key = 'crawler:' . $id . ':' . $ip;
        if ($this->cacheGet !== null) {
            $known = ($this->cacheGet)($key);
            if ($known === '1' || $known === '0') {
                return $known === '1';
            }
        }
        // Past the lookups allowed: not verified, at once -- and not
        // remembered, so a real crawler is known again once the flood is over.
        if ($this->mayLookUp !== null && !($this->mayLookUp)()) {
            return false;
        }
        $ok = SearchEngines::dns($ip, $x['dns'], $this->reverse, $this->forward);
        if ($this->cacheSet !== null) {
            ($this->cacheSet)($key, $ok ? '1' : '0');
        }
        return $ok;
    }
}
