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
    'explain(): each rule names its own decisions and no other (0031 C.2); the shield asks the chain, and names what no rule produces' => function (): void {
        $s = Settings::from(['hosts' => ['a.example'], 'methods' => ['GET', 'POST'], 'restricted' => [['paths' => ['#^/admin#'], 'ips' => ['192.0.2.0/24']]],
            'budgets' => ['requests' => ['limit' => 100, 'window' => 60]], 'challenge' => ['alwaysPaths' => ['#^/login$#']]]);
        $shield = new Shield($s, new MemoryStore());
        $req = Inspector::request('GET', 'https://a.example/.env', '203.0.113.7');
        $blocked = \CjwNetwork\RequestShield\Decision::reject(404, 'blocked path');
        $own = [];
        foreach ($shield->rules() as $rule) {
            $name = $rule->explain($blocked, $req, $s);
            if ($name !== null) {
                $own[] = get_class($rule);
            }
        }
        same([\CjwNetwork\RequestShield\Rule\BlockedPathRule::class], $own, 'exactly one rule claims "blocked path"');
        same('SCAN-HIDDEN', $shield->explain($blocked, $req), 'and names the shipped rule');
        same('built-in', $shield->explain(\CjwNetwork\RequestShield\Decision::reject(414, 'uri length'), $req), 'a fixed check: built-in');
        same('methods', $shield->explain(\CjwNetwork\RequestShield\Decision::reject(405, 'method'), $req), 'a refused method: the methods setting');
        same('built-in', $shield->explain(\CjwNetwork\RequestShield\Decision::allowUncached('method'), $req), 'a POST never cached: built-in (the cache rule\'s, not the method rule\'s)');
        same('restricted[0]', $shield->explain(\CjwNetwork\RequestShield\Decision::reject(403, 'restricted'), Inspector::request('GET', 'https://a.example/admin/x', '203.0.113.7')), 'the restricted area');
        same('budgets.requests', $shield->explain(\CjwNetwork\RequestShield\Decision::throttle('requests', 10), $req), 'a budget: by its name');
        same('challenge.alwaysPaths[0]', $shield->explain(\CjwNetwork\RequestShield\Decision::challenge('always'), Inspector::request('GET', 'https://a.example/login', '203.0.113.7')), 'the always-checked path: no rule\'s, the shield\'s own');
        same('application', $shield->explain(\CjwNetwork\RequestShield\Decision::challenge('app'), $req), 'the site asked');
        same(null, $shield->explain(\CjwNetwork\RequestShield\Decision::allowUncached('unknown url'), $req), 'nothing to name');
    },
    'a rule provider (0031 C.3): its step after its stage, its rule decides and names itself, the trace shows it; a throwing rule says nothing; a wrong step is left out' => function (): void {
        \CjwNetwork\RequestShield\Rules\Vocabulary::forget();
        $dir = ruleDir([]);
        try {
            \CjwNetwork\RequestShield\Rules\Vocabulary::offer(\CjwNetwork\RequestShield\Tests\RsTestExtension::class);
            @mkdir($dir, 0700, true);
            $compile = static function (string $rules) use ($dir): Settings {
                file_put_contents("$dir/site.rules", "set store file\nset store-dir $dir/store\nhost a.example\n" . $rules);
                return Settings::from(\CjwNetwork\RequestShield\Rules\RuleFile::read(["$dir/site.rules"])['config']);
            };
            $s = $compile("plugin CjwNetwork\\RequestShield\\Tests\\RulesPlugin\n");
            same(['ruleProvider' => [\CjwNetwork\RequestShield\Tests\RulesPlugin::class]], $s->hooks, 'recorded as a provider');
            $shield = new Shield($s, new MemoryStore());
            $keys = array_map(static fn (Step $st): string => $st->key, $shield->chain());
            same(array_search('restricted', $keys, true) + 1, array_search('test-provider', $keys, true), 'after the last step of its stage (paths)');
            same('paths', $shield->chain()[array_search('test-provider', $keys, true)]->stage);
            truthy($shield->chain()[array_search('test-provider', $keys, true)]->rule instanceof \CjwNetwork\RequestShield\Rule\Guarded, 'behind the guard');
            $forbidden = Inspector::request('GET', 'https://a.example/forbidden', '203.0.113.7');
            $d = $shield->decide($forbidden, 1000.0);
            same([403, 'test-provider'], [$d->status, $d->reason], 'its rule decides');
            same('TEST-PROVIDER', $shield->explain($d, $forbidden), 'and names itself (Rule::explain through the guard)');
            same('allow', $shield->decide(Inspector::request('GET', 'https://a.example/page', '203.0.113.7'), 1000.0)->action, 'any other address: nothing to say');
            $trace = (new Inspector($s, new MemoryStore()))->trace($forbidden);
            $row = array_values(array_filter($trace['steps'], static fn (array $r): bool => $r['key'] === 'test-provider'))[0] ?? null;
            same(['stop', 'TEST-PROVIDER', 'The test provider\'s rule'], [$row['state'] ?? null, $row['rule'] ?? null, $row['check'] ?? null], 'the trace: its row, stopped, with its name');
            // A rule that throws: nothing said, the request passes, one line in PHP's error log.
            $failing = $compile("plugin CjwNetwork\\RequestShield\\Tests\\RulesPlugin\nset fail-at rules\n");
            $log = ini_set('error_log', "$dir/php-errors.log");
            try {
                $d = (new Shield($failing, new MemoryStore()))->decide($forbidden, 1000.0);
            } finally {
                ini_set('error_log', (string) $log);
            }
            same('allow', $d->action, 'the throwing rule says nothing: the request passes');
            truthy(strpos((string) @file_get_contents("$dir/php-errors.log"), 'the rule test-provider failed and said nothing: the provided rule failed, as asked (') !== false, 'noted: ' . (string) @file_get_contents("$dir/php-errors.log"));
            // A step at the lists stage, or a provider that throws: left out, noted; the chain is the core's.
            foreach (["rs-test-mark stage:lists\n", "rs-test-mark provider:throws\n"] as $bad) {
                $log = ini_set('error_log', "$dir/php-errors-$bad.log");
                try {
                    $out = new Shield($compile("plugin CjwNetwork\\RequestShield\\Tests\\RulesPlugin\n" . $bad), new MemoryStore());
                } finally {
                    ini_set('error_log', (string) $log);
                }
                same(false, in_array('test-provider', array_map(static fn (Step $st): string => $st->key, $out->chain()), true), "left out: $bad");
                same(16, count($out->chain()), 'the core\'s steps only');
            }
        } finally {
            \CjwNetwork\RequestShield\Rules\Vocabulary::forget();
            \CjwNetwork\RequestShield\Rules\Vocabulary::offer(\CjwNetwork\RequestShield\StatsExtension::class);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
