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
 * One HTTPS GET for the updates (feeds, crawler lists): with
 * file_get_contents where allow_url_fopen is on, else with curl, else
 * nothing -- and offline() says why, so the CLI can tell the operator what
 * to do instead. On a request path only for the HTTP cache's user hash
 * (http-cache-user-context: once per session for the hash's max-age, 2 s at
 * most).
 */
final class Http
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}|null null: no answer (no network,
     *   no way to fetch -- offline() -- or the server did not answer)
     */
    public static function get(string $url, array $headers = [], int $timeout = 30, int $maxBytes = 0, string $userAgent = 'request-shield'): ?array
    {
        if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return self::viaStream($url, $headers, $timeout, $maxBytes, $userAgent);
        }
        if (function_exists('curl_init')) {
            return self::viaCurl($url, $headers, $timeout, $maxBytes, $userAgent);
        }
        return null;
    }

    /**
     * Why nothing can be fetched here, or null when something can: a shared
     * host may switch allow_url_fopen off and leave curl out.
     */
    public static function offline(): ?string
    {
        if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN) || function_exists('curl_init')) {
            return null;
        }
        return 'this PHP cannot fetch anything: allow_url_fopen is off and curl is not loaded -- run the update on a machine '
            . 'that can (the same rules, request-shield feeds|crawlers <main.rules> update) and copy its store-dir/feeds and store-dir/crawlers here';
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}|null
     */
    private static function viaStream(string $url, array $headers, int $timeout, int $maxBytes, string $userAgent): ?array
    {
        $h = "User-Agent: $userAgent\r\n";
        foreach ($headers as $k => $v) {
            $h .= "$k: $v\r\n";
        }
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'header' => $h, 'follow_location' => 1, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $http_response_header = [];
        $body = $maxBytes > 0 ? @file_get_contents($url, false, $ctx, 0, $maxBytes) : @file_get_contents($url, false, $ctx);
        $status = 0;
        $out = [];
        /** @var list<string> $http_response_header filled by file_get_contents() */
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
                $out = [];                                  // after a redirect: the last answer's headers
            } elseif (($c = strpos($line, ':')) !== false) {
                $out[strtolower(trim(substr($line, 0, $c)))] = trim(substr($line, $c + 1));
            }
        }
        return $status === 0 ? null : ['status' => $status, 'body' => is_string($body) ? $body : '', 'headers' => $out];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}|null
     */
    private static function viaCurl(string $url, array $headers, int $timeout, int $maxBytes, string $userAgent): ?array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "$k: $v";
        }
        $out = [];
        $buf = '';
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => $userAgent === '' ? 'request-shield' : $userAgent, CURLOPT_HTTPHEADER => $lines, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$out): int {
                if (preg_match('#^HTTP/\S+\s+\d{3}#', $line) === 1) {
                    $out = [];                              // after a redirect: the last answer's headers
                } elseif (($c = strpos($line, ':')) !== false) {
                    $out[strtolower(trim(substr($line, 0, $c)))] = trim(substr($line, $c + 1));
                }
                return strlen($line);
            },
            // The body, collected here: past $maxBytes the download stops (a
            // short return aborts it) and what came is kept, cut -- as the
            // stream path reads at most $maxBytes.
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buf, $maxBytes): int {
                $buf .= $chunk;
                return $maxBytes > 0 && strlen($buf) > $maxBytes ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status === 0) {
            return null;
        }
        return ['status' => $status, 'body' => $maxBytes > 0 ? substr($buf, 0, $maxBytes) : $buf, 'headers' => $out];
    }
}
