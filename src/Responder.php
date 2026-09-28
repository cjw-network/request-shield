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
    private const TEXT = [
        400 => 'Bad Request', 404 => 'Not Found', 405 => 'Method Not Allowed',
        414 => 'URI Too Long', 429 => 'Too Many Requests', 431 => 'Request Header Fields Too Large',
    ];

    public function send(Decision $decision, Request $request, bool $debugHeader = false): void
    {
        $text = self::TEXT[$decision->status] ?? 'Error';
        if (!headers_sent()) {
            http_response_code($decision->status);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex');
            if ($decision->retryAfter > 0) {
                header('Retry-After: ' . $decision->retryAfter);
            }
            if ($decision->status === 405) {
                header('Allow: GET, HEAD, POST');
            }
            if ($debugHeader) {
                header('X-Request-Shield: ' . $decision->action . ' ' . $decision->reason);
            }
        }
        if ($request->method !== 'HEAD') {
            echo '<!doctype html><title>', $decision->status, ' ', $text, '</title><h1>', $text, '</h1>';
            if ($decision->retryAfter > 0) {
                echo '<p>Please try again in ', $decision->retryAfter, ' seconds.</p>';
            }
        }
    }
}
