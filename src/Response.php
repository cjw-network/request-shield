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
 * An answer the shield sends itself, complete: the status, the header lines
 * and the body. The dashboard's pages return one (RoutePage::serve()); the
 * Handler hook (0031 C.4) will too.
 */
final class Response
{
    /**
     * @param list<string> $headers complete header lines ("Content-Type: text/html; charset=utf-8")
     */
    public function __construct(
        /** @readonly */
        public int $status,
        /** @readonly */
        public array $headers,
        /** @readonly */
        public string $body,
    ) {
    }

    /**
     * An HTML page.
     *
     * @param list<string> $headers
     */
    public static function html(int $status, string $body, array $headers = []): self
    {
        return new self($status, array_merge(['Content-Type: text/html; charset=utf-8'], $headers), $body);
    }

    /**
     * A JSON answer.
     *
     * @param list<string> $headers
     */
    public static function json(int $status, mixed $data, array $headers = [], int $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE): self
    {
        return new self($status, array_merge(['Content-Type: application/json; charset=utf-8'], $headers), (string) json_encode($data, $flags | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * The same answer with header lines added in front (the shield's own: no-store, noindex, cookies).
     *
     * @param list<string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, array_merge($headers, $this->headers), $this->body);
    }

    /** Sends it; the caller ends the request. */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $h) {
                header($h, false);
            }
        }
        echo $this->body;
    }
}
