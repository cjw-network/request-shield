<?php

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Tests;

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Plugin;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Seen;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Sink;

/**
 * A plugin with the Sink capability, for the tests (0031 B.9): it keeps what
 * it hears in a static list; with `set fail-at sink` (the test extension) it
 * throws instead -- the log must still be written and the request answered.
 */
final class SinkPlugin implements Plugin, Sink
{
    /** @var list<array{action: string, status: int, rule: ?string, monitor: bool, path: string}> */
    public static array $heard = [];

    public function __construct(private Settings $settings)
    {
    }

    public function decided(Request $request, Decision $decision, ?string $rule, Seen $seen, float $now, bool $continues, ?Decision $would = null): void
    {
    }

    public function ended(Request $request, int $status, array $headers, Seen $seen, float $now): void
    {
    }

    public function note(Request $request, Decision $decision, ?string $rule, float $now, bool $monitor): void
    {
        if (($this->settings->ext['rs-test']['failAt'] ?? null) === 'sink') {
            // The store directory in the message: the once-a-minute throttle (Failure::note) keys on
            // the message, and every test run has a directory of its own.
            throw new \RuntimeException('the sink failed, as asked (' . $this->settings->storeDir . ')');
        }
        self::$heard[] = ['action' => $decision->action, 'status' => $decision->status, 'rule' => $rule, 'monitor' => $monitor, 'path' => $request->path];
    }
}
