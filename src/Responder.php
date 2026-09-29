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
    /** @param array<string, string> $texts in the visitor's language (Texts::all()) */
    public function send(Decision $decision, Request $request, bool $debugHeader = false, ?string $page = null, ?string $rule = null, array $texts = []): void
    {
        $this->headers($decision, $debugHeader, $rule);
        if ($request->method !== 'HEAD') {
            echo $this->body($decision, $page, $texts);
        }
    }

    public function headers(Decision $decision, bool $debugHeader = false, ?string $rule = null): void
    {
        if (headers_sent()) {
            return;
        }
        http_response_code($decision->status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        header('Vary: Accept-Language');     // the texts are in the visitor's language
        if ($decision->retryAfter > 0) {
            header('Retry-After: ' . $decision->retryAfter);
        }
        if ($decision->status === 405) {
            header('Allow: GET, HEAD, POST');
        }
        if ($debugHeader) {
            header('X-Request-Shield: ' . $decision->action . ' ' . $decision->reason . ($rule !== null ? '; rule=' . $rule : ''));
        }
    }

    /**
     * The page: the challenge page when there is one, else a status page.
     *
     * @param array<string, string> $texts
     */
    public function body(Decision $decision, ?string $page = null, array $texts = []): string
    {
        if ($page !== null) {
            return $page;
        }
        $texts += Texts::all('en');
        $text = Texts::status($decision->status, $texts);
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $h = '<!doctype html><html lang="' . $e($texts['lang'] ?? 'en') . '"><meta charset="utf-8"><title>' . $decision->status . ' ' . $e($text) . '</title><h1>' . $e($text) . '</h1>';
        if ($decision->retryAfter > 0) {
            // str_replace, not sprintf: a site's text may hold a "%" of its own.
            $h .= '<p>' . $e(str_replace('%s', (string) $decision->retryAfter, $texts['try-again'])) . '</p>';
        }
        return $h;
    }
}
