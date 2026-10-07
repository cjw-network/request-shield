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

    /** @var array<string, string> */
    private array $content = [];

    /** What the attack patterns see of the query, when the known parameters said (QueryRule). */
    private ?string $scanQuery = null;

    /**
     * Only these pairs of the query ("a=1&b=2", raw) go to the attack
     * patterns (typed values cannot hold an attack). Set before they look.
     */
    public function scanQuery(string $pairs): void
    {
        $this->scanQuery = $pairs;
        unset($this->content['query'], $this->content['anywhere']);
    }

    /**
     * The key an HTTP cache keeps the answer under (0031 C.4): scheme and host
     * in lower case, the path as the application routes it, the parameters
     * sorted by name (a[b]=1&a=2 and a=2&a[b]=1 are one key), nothing of the
     * client. For GET and HEAD; the cache decides whether the answer may be
     * kept (Decision::cacheable()).
     */
    public function cacheKey(): string
    {
        $pairs = [];
        foreach ($this->queryPairs() as [$name, $value, $raw]) {
            $pairs[] = rawurlencode(urldecode(explode('=', $raw, 2)[0])) . '=' . rawurlencode($value);
        }
        sort($pairs, SORT_STRING);
        return strtolower($this->scheme . '://' . $this->host) . $this->matchPath() . ($pairs === [] ? '' : '?' . implode('&', $pairs));
    }

    /**
     * The query's parameters as PHP will see them: [name, value, raw pair],
     * a name such as "a[b]" as "a", the value decoded; the raw pair as it
     * stands in the query string.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public function queryPairs(): array
    {
        if ($this->pairs !== null) {
            return $this->pairs;
        }
        $out = [];
        foreach ($this->query === '' ? [] : explode('&', $this->query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $kv = explode('=', $pair, 2);
            $name = urldecode($kv[0]);
            $bracket = strpos($name, '[');
            $out[] = [$bracket === false ? $name : substr($name, 0, $bracket), urldecode($kv[1] ?? ''), $pair];
        }
        return $this->pairs = $out;
    }

    /** @var list<array{0: string, 1: string, 2: string}>|null parsed once, for every rule that asks */
    private ?array $pairs = null;

    /**
     * What attack rules look at, normalised so that disguises do not help:
     * decoded (twice: "%2527" is "'"), "+" as a space in the query, lower
     * case, SQL comments and runs of white space as one space.
     *
     *   "query"          the query string
     *   "header:<name>"  one header ("header:user-agent")
     *   "headers"        every header's value
     *   "anywhere"       path, query and every header
     */
    public function content(string $target): string
    {
        if (isset($this->content[$target])) {
            return $this->content[$target];
        }
        if ($target === 'query') {
            $v = self::normal(str_replace('+', ' ', $this->scanQuery ?? $this->query));
        } elseif (strncmp($target, 'header:', 7) === 0) {
            $v = self::normal((string) $this->header(substr($target, 7)));
        } elseif ($target === 'headers') {
            $all = [];
            foreach ($this->server as $name => $value) {
                if (is_string($value) && strncmp($name, 'HTTP_', 5) === 0 && $name !== 'HTTP_COOKIE') {
                    $all[] = $value;
                }
            }
            // Joined by a space: what a line break would have become anyway.
            $v = self::normal(implode(' ', $all));
        } else {
            $v = self::normal($this->path) . ' ' . $this->content('query') . ' ' . $this->content('headers');
        }
        return $this->content[$target] = $v;
    }

    /**
     * Whether the raw value of a target could hold one of $texts once
     * normalised: it holds one (any case), or a "%" that decoding could turn
     * into one. Cheaper than normalising.
     *
     * @param list<string> $texts lower case
     */
    public function mayHold(string $target, array $texts): bool
    {
        $raw = [];
        if ($target === 'query' || $target === 'anywhere') {
            $raw[] = $this->scanQuery ?? $this->query;
        }
        if ($target === 'anywhere') {
            $raw[] = $this->path;
        }
        if (strncmp($target, 'header:', 7) === 0) {
            $raw[] = (string) $this->header(substr($target, 7));
        } elseif ($target === 'headers' || $target === 'anywhere') {
            foreach ($this->server as $name => $value) {
                if (is_string($value) && strncmp($name, 'HTTP_', 5) === 0 && $name !== 'HTTP_COOKIE') {
                    $raw[] = $value;
                }
            }
        }
        foreach ($raw as $v) {
            if (strpos($v, '%') !== false) {
                return true;
            }
            foreach ($texts as $t) {
                if (stripos($v, $t) !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function normal(string $v): string
    {
        if ($v === '') {
            return '';
        }
        for ($i = 0; $i < 2 && strpos($v, '%') !== false; $i++) {
            $v = rawurldecode($v);
        }
        $v = strtolower($v);
        if (strpos($v, '/*') !== false) {
            // A comment is a space -- except MySQL's versioned one (/*!50000union*/,
            // MariaDB's /*m!100100 ...*/), whose body MySQL runs: that body stays.
            $v = (string) preg_replace(['#/\*m?!\d*(.*?)\*/#s', '#/\*.*?\*/#s'], [' $1 ', ' '], $v);
        }
        // Runs of white space as one space -- only when there are any: most
        // values have single spaces, and the expression is the costly part.
        // (One compiled expression is the quickest test here: faster than
        // strpbrk() and strcspn() on header-length strings.)
        if (preg_match('/[\t\n\r\x0B\x0C]|  /', $v) !== 1) {
            return $v;
        }
        return (string) preg_replace('/\s+/', ' ', $v);
    }

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
        return array_column($this->queryPairs(), 0);
    }
}
