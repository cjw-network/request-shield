<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield;

/**
 * The request as the shield sees it, built from $_SERVER before anything else
 * of the application has run.
 *
 * Client address, scheme and host come from X-Forwarded-For, -Proto and -Host
 * only when the direct peer is a trusted proxy. Anyone can send those headers;
 * believed from everybody, they let a client pick its own address (and so its
 * own budget) and invent hosts, one cache entry per invented host.
 */
final class Request
{
    /**
     * @param array<string, mixed> $server the $_SERVER it was built from; headers are read from it when asked for
     */
    public function __construct(
        public readonly string $method,
        public readonly string $scheme,
        public readonly string $host,
        public readonly string $path,
        public readonly string $query,
        public readonly string $rawUri,
        public readonly string $clientIp,
        public readonly string $peerIp,
        public readonly bool $viaTrustedProxy,
        public readonly int $headerBytes,
        private readonly array $server = [],
    ) {
    }

    /**
     * @param array<string, mixed> $server $_SERVER
     * @param list<string> $trustedProxies CIDR ranges or addresses
     */
    public static function fromServer(array $server, array $trustedProxies = []): self
    {
        // Only the sizes here: the few headers the shield reads are taken
        // from $server directly, not copied into a map of every header.
        $headerBytes = 0;
        foreach ($server as $name => $value) {
            if (is_string($value) && is_string($name) && strncmp($name, 'HTTP_', 5) === 0) {
                $headerBytes += strlen($name) - 1 + strlen($value);
            }
        }
        $forwardedFor = $server['HTTP_X_FORWARDED_FOR'] ?? null;
        $forwardedProto = $server['HTTP_X_FORWARDED_PROTO'] ?? null;
        $forwardedHost = $server['HTTP_X_FORWARDED_HOST'] ?? null;

        $peer = (string) ($server['REMOTE_ADDR'] ?? '');
        $trusted = $peer !== '' && $trustedProxies !== [] && IpAddress::inRanges($peer, $trustedProxies);

        $client = $peer;
        if ($trusted && is_string($forwardedFor)) {
            // Right to left: the last address a trusted proxy added is the
            // first one nobody here vouches for.
            $hops = array_reverse(array_map('trim', explode(',', $forwardedFor)));
            foreach ($hops as $hop) {
                if ($hop === '' || @inet_pton($hop) === false) {
                    break;
                }
                $client = $hop;
                if (!IpAddress::inRanges($hop, $trustedProxies)) {
                    break;
                }
            }
        }

        $https = !empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off';
        if ($trusted && is_string($forwardedProto)) {
            $https = strtolower(trim(explode(',', $forwardedProto)[0])) === 'https';
        }

        $host = (string) ($server['HTTP_HOST'] ?? ($server['SERVER_NAME'] ?? ''));
        if ($trusted && is_string($forwardedHost)) {
            $host = trim(explode(',', $forwardedHost)[0]);
        }
        $host = strtolower(preg_replace('/:\d+$/', '', $host) ?? $host);

        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $hash = strpos($uri, '#');
        if ($hash !== false) {
            $uri = substr($uri, 0, $hash);
        }
        $q = strpos($uri, '?');
        $path = $q === false ? $uri : substr($uri, 0, $q);
        $query = $q === false ? '' : substr($uri, $q + 1);

        return new self(
            strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')),
            $https ? 'https' : 'http',
            $host,
            $path === '' ? '/' : $path,
            $query,
            $uri,
            $client,
            $peer,
            $trusted,
            $headerBytes,
            $server,
        );
    }

    /** A request header ("User-Agent", "x-forwarded-for"). */
    public function header(string $name): ?string
    {
        $value = $this->server['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * The query string's parameter names, as PHP will see them ("a[b]" is "a").
     *
     * @return list<string>
     */
    public function queryNames(): array
    {
        if ($this->query === '') {
            return [];
        }
        $names = [];
        foreach (explode('&', $this->query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $name = urldecode(explode('=', $pair, 2)[0]);
            $bracket = strpos($name, '[');
            $names[] = $bracket === false ? $name : substr($name, 0, $bracket);
        }
        return $names;
    }
}
