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
 * Whether a client that says it is a search engine's crawler is one: its
 * address resolves to a host of that engine, and the host back to the
 * address (the check the engines themselves document). Only asked when a
 * crawler would be challenged or throttled, and remembered for a day, since
 * a DNS round trip costs more than everything else the shield does.
 */
final class SearchEngines
{
    /**
     * User-Agent pattern => host suffixes its crawlers resolve to.
     *
     * @return array<string, list<string>>
     */
    public static function defaults(): array
    {
        return [
            '/Googlebot|Google-InspectionTool|GoogleOther|AdsBot-Google|Mediapartners-Google/i' => ['.googlebot.com', '.google.com', '.googleusercontent.com'],
            '/bingbot|BingPreview|msnbot|adidxbot/i' => ['.search.msn.com'],
            '/DuckDuckBot/i' => ['.duckduckgo.com'],
            '/Applebot/i' => ['.applebot.apple.com'],
            '/YandexBot|YandexImages|YandexMobileBot/i' => ['.yandex.ru', '.yandex.net', '.yandex.com'],
            '/Baiduspider/i' => ['.baidu.com', '.baidu.jp'],
            '/Qwantbot|Qwantify/i' => ['.qwant.com'],
            '/SeznamBot/i' => ['.seznam.cz'],
        ];
    }

    /**
     * @param array<string, list<string>> $engines
     * @param (callable(string): (string|false))|null $reverse gethostbyaddr, replaceable for tests
     * @param (callable(string): list<string>)|null $forward addresses of a host, replaceable for tests
     */
    public function __construct(
        private readonly array $engines,
        private readonly ?\Closure $cacheGet = null,
        private readonly ?\Closure $cacheSet = null,
        private $reverse = null,
        private $forward = null,
    ) {
    }

    /**
     * The engine's suffixes the User-Agent claims, or null for no crawler.
     *
     * @return list<string>|null
     */
    public function claims(string $userAgent): ?array
    {
        foreach ($this->engines as $pattern => $suffixes) {
            if (@preg_match($pattern, $userAgent) === 1) {
                return $suffixes;
            }
        }
        return null;
    }

    public function verified(string $ip, string $userAgent): bool
    {
        $suffixes = $this->claims($userAgent);
        if ($suffixes === null) {
            return false;
        }
        $key = 'se:' . $ip;
        if ($this->cacheGet !== null) {
            $known = ($this->cacheGet)($key);
            if ($known === '1' || $known === '0') {
                return $known === '1';
            }
        }
        $ok = $this->resolve($ip, $suffixes);
        if ($this->cacheSet !== null) {
            ($this->cacheSet)($key, $ok ? '1' : '0');
        }
        return $ok;
    }

    /** @param list<string> $suffixes */
    private function resolve(string $ip, array $suffixes): bool
    {
        $host = $this->reverse !== null ? ($this->reverse)($ip) : @gethostbyaddr($ip);
        if (!is_string($host) || $host === '' || $host === $ip) {
            return false;
        }
        $host = strtolower(rtrim($host, '.'));
        $matches = false;
        foreach ($suffixes as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                $matches = true;
                break;
            }
        }
        if (!$matches) {
            return false;
        }
        $addresses = $this->forward !== null ? ($this->forward)($host) : self::addressesOf($host);
        return in_array(inet_ntop((string) inet_pton($ip)), array_map(static fn ($a) => inet_ntop((string) inet_pton($a)), array_filter($addresses, static fn ($a) => @inet_pton($a) !== false)), true);
    }

    /** @return list<string> */
    private static function addressesOf(string $host): array
    {
        $out = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? ($record['ipv6'] ?? null);
            if (is_string($ip)) {
                $out[] = $ip;
            }
        }
        return $out;
    }
}
