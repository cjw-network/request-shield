<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Report\Inspector;
use CjwNetwork\RequestShield\Rule\Step;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\MemoryStore;

/**
 * The rule chain (0031 C.1): Shield::chain() is the one list of what is
 * checked and in which order; the inspector walks it. Every step has a key
 * and a stage; a step whose settings are not in use has no rule.
 */

return [
    'chain(): every step in order, with key and stage; inactive steps have no rule; the hot path runs exactly the active rules' => function (): void {
        $s = Settings::from(['hosts' => ['a.example'], 'restricted' => [['paths' => ['#^/admin#'], 'ips' => ['192.0.2.0/24']]],
            'budgets' => ['requests' => ['limit' => 100, 'window' => 60], 'searches' => ['limit' => 5, 'window' => 60, 'onDemand' => true]]]);
        $chain = (new Shield($s, new MemoryStore()))->chain();
        $keys = array_map(static fn (Step $st): string => $st->key, $chain);
        same(['deny', 'feed', 'ban', 'method', 'limits', 'path', 'host', 'blocked', 'method-path', 'post-origin', 'restricted', 'crawlers', 'query', 'content', 'cache', 'budget:requests'], $keys,
            'the order the shield checks; an on-demand budget is no step');
        foreach ($chain as $st) {
            truthy(in_array($st->stage, Step::STAGES, true), "$st->key: a known stage, not $st->stage");
            truthy($st->describe !== '', "$st->key: described");
        }
        $active = array_values(array_map(static fn (Step $st): string => $st->key, array_filter($chain, static fn (Step $st): bool => $st->active())));
        same(['method', 'limits', 'path', 'host', 'blocked', 'restricted', 'cache', 'budget:requests'], $active, 'what this installation checks: no deny list, no feeds, no bans, no post-origin, no refused crawler, no parameters, no patterns');
        same(['lists', 'lists', 'lists', 'identity', 'shape', 'shape', 'identity', 'paths', 'paths', 'origin', 'paths', 'crawlers', 'query', 'content', 'cache', 'budgets'],
            array_map(static fn (Step $st): string => $st->stage, $chain), 'the stages');
        same([], (new Shield(Settings::from(['mode' => 'off']), new MemoryStore()))->chain(), 'switched off: no chain');
        // The table's order is the constructor's: the request path and the chain agree, rule for rule.
        $shield = new Shield($s, new MemoryStore());
        same(array_map('get_class', $shield->rules()), array_map(static fn (Step $st): string => get_class($st->rule), array_values(array_filter($shield->chain(), static fn (Step $st): bool => $st->active()))),
            'the active steps are the rules the request path runs, in the same order');
        foreach (array_values(array_filter($shield->chain(), static fn (Step $st): bool => $st->active())) as $i => $st) {
            truthy($st->rule === $shield->rules()[$i], "$st->key: the very same rule object");
        }
    },
    'the inspector walks the chain: one row per step with its key, then the browser check -- the same for an installation with everything on' => function (): void {
        $s = Settings::from(['hosts' => ['a.example'], 'budgets' => ['requests' => ['limit' => 100, 'window' => 60]]]);
        $trace = (new Inspector($s, new MemoryStore()))->trace(Inspector::request('GET', 'https://a.example/page', '203.0.113.7'));
        $chainKeys = array_map(static fn (Step $st): string => $st->key, (new Shield($s, new MemoryStore()))->chain());
        same(array_merge($chainKeys, ['always']), array_column($trace['steps'], 'key'), 'every step of the chain, in its order, and the browser check last');
        same('pass', $trace['steps'][0]['state']);
        truthy(strpos($trace['steps'][0]['text'], 'no address is kept out') !== false, 'an inactive step is named and explained: ' . $trace['steps'][0]['text']);
        same('allow', $trace['decision']->action);
        // A refusal: the step stops, the ones after it are skipped.
        $trace = (new Inspector($s, new MemoryStore()))->trace(Inspector::request('GET', 'https://a.example/.env', '203.0.113.7'));
        $states = array_combine(array_column($trace['steps'], 'key'), array_column($trace['steps'], 'state'));
        same('stop', $states['blocked'] ?? null, 'refused at the blocked paths');
        same('skip', $states['cache'] ?? null, 'the steps after it: not checked');
        same('SCAN-HIDDEN', $trace['rule'], 'the rule behind it');
    },
];
