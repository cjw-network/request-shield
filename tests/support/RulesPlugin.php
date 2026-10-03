<?php

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Tests;

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Plugin;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rule\Rule;
use CjwNetwork\RequestShield\Rule\Step;
use CjwNetwork\RequestShield\RuleProvider;
use CjwNetwork\RequestShield\Seen;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Store\Store;

/**
 * A plugin with the RuleProvider capability, for the tests (0031 C.3): one
 * rule at the "paths" stage that refuses /forbidden (403, reason
 * "test-provider", rule TEST-PROVIDER). `set fail-at rules` makes the rule
 * throw -- the request must pass as if the rule said nothing; `rs-test-mark
 * stage:lists` makes the provider claim the lists stage, which the shield
 * refuses; `rs-test-mark provider:throws` makes rules() itself throw.
 */
final class RulesPlugin implements Plugin, RuleProvider
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

    public function rules(Settings $s, Store $store): array
    {
        $test = is_array($this->settings->ext['rs-test'] ?? null) ? $this->settings->ext['rs-test'] : [];      // the plugin's own settings: the same as $s
        $marks = is_array($test['marks'] ?? null) ? $test['marks'] : [];
        if (in_array('provider:throws', $marks, true)) {
            throw new \RuntimeException('the provider failed, as asked (' . $s->storeDir . ')');
        }
        $fail = ($test['failAt'] ?? null) === 'rules';
        $rule = new class($fail, $s->storeDir) implements Rule {
            public function __construct(private bool $fail, private string $dir)
            {
            }

            public function check(Request $request, float $now): ?Decision
            {
                if ($this->fail) {
                    throw new \RuntimeException('the provided rule failed, as asked (' . $this->dir . ')');
                }
                return $request->matchPath() === '/forbidden' ? Decision::reject(403, 'test-provider') : null;
            }

            public function explain(Decision $d, Request $request, Settings $s): ?string
            {
                return $d->reason === 'test-provider' ? 'TEST-PROVIDER' : null;
            }
        };
        return [new Step('test-provider', in_array('stage:lists', $marks, true) ? 'lists' : 'paths', $rule, 'The test provider\'s rule')];
    }
}
