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
 * Sends the answer for a request the application does not get to see:
 * a few hundred bytes, never cacheable, nothing that reveals the rule.
 */
class Responder
{
    /**
     * @param array<string, string> $texts in the visitor's language (Texts::all())
     * @param (callable(string, array<string, mixed>): ?string)|null $pages the Pages hook (PageHook::asker()): the site's own refusal page, or null for the shield's
     */
    public function send(Decision $decision, Request $request, bool $debugHeader = false, ?string $page = null, ?string $rule = null, array $texts = [], ?string $home = null, ?callable $pages = null): void
    {
        $this->headers($decision, $debugHeader, $rule);
        if ($request->method !== 'HEAD') {
            echo $this->body($decision, $page, $texts, $home, $pages, $request);
        }
    }

    /**
     * The check for an API: 429, the task as JSON and in a header; the client
     * solves it and repeats the request with Request-Shield-Solution -- or
     * waits Retry-After, where there is one.
     *
     * @param array{algorithm: string, challenge: string, maxnumber: int, salt: string, signature: string} $challenge
     */
    public function api(Decision $decision, array $challenge, bool $debugHeader = false, ?string $rule = null): string
    {
        $json = (string) json_encode($challenge, JSON_UNESCAPED_SLASHES);
        if (!headers_sent()) {
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('Request-Shield-Challenge: ' . rtrim(strtr(base64_encode($json), '+/', '-_'), '='));
            if ($decision->retryAfter > 0) {
                header('Retry-After: ' . $decision->retryAfter);
            }
            if ($debugHeader) {
                header('X-RS: ' . $decision->action . ' ' . $decision->reason . ($rule !== null ? '; rule=' . $rule : ''));
            }
        }
        return (string) json_encode(['error' => $decision->spent ? 'rate_limited' : 'challenge', 'retryAfter' => $decision->retryAfter ?: null,
            'challenge' => $challenge, 'solution' => 'Request-Shield-Solution'], JSON_UNESCAPED_SLASHES);
    }

    public function headers(Decision $decision, bool $debugHeader = false, ?string $rule = null): void
    {
        if (headers_sent()) {
            return;
        }
        http_response_code($decision->status);
        foreach (self::headerLines($decision, $debugHeader, $rule) as $line) {
            header($line);
        }
    }

    /**
     * The header lines of a refusal's or a check's page, without sending them --
     * what headers() sends, and what `request-shield test` and the demo show
     * an example answers with (0031 F.3).
     *
     * @return list<string>
     */
    public static function headerLines(Decision $decision, bool $debugHeader = false, ?string $rule = null): array
    {
        $out = ['Content-Type: text/html; charset=utf-8', 'Cache-Control: no-store', 'X-Robots-Tag: noindex',
            'Vary: Accept-Language'];    // the texts are in the visitor's language
        if ($decision->retryAfter > 0) {
            $out[] = 'Retry-After: ' . $decision->retryAfter;
        }
        if ($decision->status === 405) {
            $out[] = 'Allow: GET, HEAD, POST';
        }
        if ($debugHeader) {
            $out[] = 'X-RS: ' . $decision->action . ' ' . $decision->reason . ($rule !== null ? '; rule=' . $rule : '');
        }
        return $out;
    }

    /**
     * The page: the one given (the check page), the site's own (the Pages hook, 0031 B.10),
     * else the shield's small one.
     *
     * @param array<string, string> $texts
     * @param (callable(string, array<string, mixed>): ?string)|null $pages
     */
    public function body(Decision $decision, ?string $page = null, array $texts = [], ?string $home = null, ?callable $pages = null, ?Request $request = null): string
    {
        if ($page !== null) {
            return $page;
        }
        $texts += Texts::all('en');
        if ($pages !== null) {
            $own = $pages(Pages::ERROR, ['status' => $decision->status, 'decision' => $decision, 'reason' => $decision->reason, 'retryAfter' => $decision->retryAfter,
                'texts' => $texts, 'lang' => $texts['lang'] ?? 'en', 'home' => $home, 'request' => $request]);
            if ($own !== null) {
                return $own;
            }
        }
        $text = Texts::status($decision->status, $texts);
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $h = '<!doctype html><html lang="' . $e($texts['lang'] ?? 'en') . '"><meta charset="utf-8"><title>' . $decision->status . ' ' . $e($text) . '</title><h1>' . $e($text) . '</h1>';
        if ($decision->retryAfter > 0) {
            // str_replace, not sprintf: a site's text may hold a "%" of its own.
            $h .= '<p>' . $e(str_replace('%s', (string) $decision->retryAfter, $texts['try-again'])) . '</p>';
        }
        if ($home !== null) {
            $h .= '<p><a href="' . $e($home) . '">' . $e($texts['home']) . '</a></p>';
        }
        return $h;
    }
}
