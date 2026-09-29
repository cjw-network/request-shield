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
        /** @readonly */
        public string $method,
        /** @readonly */
        public string $scheme,
        /** @readonly */
        public string $host,
        /** @readonly */
        public string $path,
        /** @readonly */
        public string $query,
        /** @readonly */
        public string $rawUri,
        /** @readonly */
        public string $clientIp,
        /** @readonly */
        public string $peerIp,
        /** @readonly */
        public bool $viaTrustedProxy,
        /** @readonly */
        public int $headerBytes,
        private array $server = [],
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
            if (is_string($value) && strncmp($name, 'HTTP_', 5) === 0) {
                $headerBytes += strlen($name) - 1 + strlen($value);
            }
        }
        $forwardedFor = self::str($server, 'HTTP_X_FORWARDED_FOR');
        $forwardedProto = self::str($server, 'HTTP_X_FORWARDED_PROTO');
        $forwardedHost = self::str($server, 'HTTP_X_FORWARDED_HOST');

        $peer = self::str($server, 'REMOTE_ADDR') ?? '';
        $trusted = $peer !== '' && $trustedProxies !== [] && IpAddress::inRanges($peer, $trustedProxies);

        $client = $peer;
        if ($trusted && $forwardedFor !== null) {
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

        $httpsVar = self::str($server, 'HTTPS') ?? '';
        $https = $httpsVar !== '' && strtolower($httpsVar) !== 'off';
        if ($trusted && $forwardedProto !== null) {
            $https = strtolower(trim(explode(',', $forwardedProto)[0])) === 'https';
        }

        $host = self::str($server, 'HTTP_HOST') ?? (self::str($server, 'SERVER_NAME') ?? '');
        if ($trusted && $forwardedHost !== null) {
            $host = trim(explode(',', $forwardedHost)[0]);
        }
        $host = strtolower(preg_replace('/:\d+$/', '', $host) ?? $host);

        $uri = self::str($server, 'REQUEST_URI') ?? '/';
        $hash = strpos($uri, '#');
        if ($hash !== false) {
            $uri = substr($uri, 0, $hash);
        }
        $q = strpos($uri, '?');
        $path = $q === false ? $uri : substr($uri, 0, $q);
        $query = $q === false ? '' : substr($uri, $q + 1);

        return new self(
            strtoupper(self::str($server, 'REQUEST_METHOD') ?? 'GET'),
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

    private ?string $matchPath = null;

    /**
     * The path as the application will route it: percent-decoded, with "//"
     * and "/./" collapsed -- what path rules that grant or refuse access are
     * matched against, so "//admin" or "/%61dmin" cannot slip past "/admin".
     * ("/../" never gets this far: PathSanityRule refuses it.)
     */
    public function matchPath(): string
    {
        if ($this->matchPath === null) {
            $p = rawurldecode($this->path);
            $p = preg_replace('#/(?:\.?/)+#', '/', $p) ?? $p;
            $this->matchPath = preg_replace('#/\.$#', '/', $p) ?? $p;
        }
        return $this->matchPath;
    }

    /** @param array<string, mixed> $server */
    private static function str(array $server, string $key): ?string
    {
        $v = $server[$key] ?? null;
        return is_string($v) ? $v : null;
    }

    /** A cookie of the request, read from the Cookie header (not $_COOKIE). */
    public function cookie(string $name): ?string
    {
        $header = $this->header('cookie');
        if ($header === null || strpos($header, $name . '=') === false) {
            return null;
        }
        foreach (explode(';', $header) as $pair) {
            $eq = strpos($pair, '=');
            if ($eq !== false && trim(substr($pair, 0, $eq)) === $name) {
                return rawurldecode(trim(substr($pair, $eq + 1)));
            }
        }
        return null;
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
