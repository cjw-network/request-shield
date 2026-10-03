<?php

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Tests;

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Handler;
use CjwNetwork\RequestShield\Plugin;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Response;
use CjwNetwork\RequestShield\Seen;
use CjwNetwork\RequestShield\Settings;

/**
 * A plugin with the Handler capability, for the tests (0031 C.4): it answers
 * /cached itself (200, a header of its own, the request's cache key in the
 * body) and leaves everything else to the application; with `set fail-at
 * handler` it throws -- the application must run as if it said nothing.
 */
final class HandlerPlugin implements Plugin, Handler
{
    public function __construct(private Settings $settings)
    {
    }

    public function decided(Request $request, Decision $decision, ?string $rule, Seen $seen, float $now, bool $continues, ?Decision $would = null): void
    {
    }

    public function ended(Request $request, int $status, array $headers, Seen $seen, float $now): void
    {
    }

    public function handle(Request $request, Decision $decision): ?Response
    {
        if (($this->settings->ext['rs-test']['failAt'] ?? null) === 'handler') {
            throw new \RuntimeException('the handler failed, as asked (' . $this->settings->storeDir . ')');
        }
        if ($request->matchPath() !== '/cached') {
            return null;
        }
        return new Response(200, ['Content-Type: text/plain; charset=utf-8', 'X-Handled: yes', 'X-Cacheable: ' . ($decision->cacheable() ? 'yes' : 'no')], 'from the handler: ' . $request->cacheKey());
    }
}
