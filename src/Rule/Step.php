<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Rule;

/**
 * One step of the rule chain (0031 C.1): what the shield checks, in order.
 * Shield::chain() is the single source -- the request path runs the steps'
 * rules, the inspector (`trace`) and the rules page walk the same list. A
 * step whose settings are not in use has no rule: the inspector still names
 * it ("no address is kept out"), the request path skips it for free.
 */
final class Step
{
    /** The stages, in their order: what a step is about. A rule provider (0031 C.3) adds its steps after a stage's. */
    public const STAGES = ['lists', 'identity', 'shape', 'paths', 'origin', 'crawlers', 'query', 'content', 'cache', 'budgets'];

    public function __construct(
        /** @readonly a short name: deny, feed, ban, method, limits, path, host, blocked, method-path, post-origin, restricted, crawlers, query, content, cache, budget:<name> */
        public string $key,
        /** @readonly one of STAGES */
        public string $stage,
        /** @readonly the rule that checks (null: the step's settings are not in use) */
        public ?Rule $rule,
        /** @readonly what is checked, in words (English; the inspector translates) */
        public string $describe,
    ) {
    }

    /** Whether the step checks anything on this installation. */
    public function active(): bool
    {
        return $this->rule !== null;
    }

    /**
     * The chain's steps in their order: key, stage, the rule class that
     * checks, what is checked -- the one table; the shield builds its rules in
     * this order (ChainTest guards it), chain() derives the steps from them.
     * Budgets are one step each, keyed budget:<name>.
     *
     * @return list<array{0: string, 1: string, 2: class-string<Rule>, 3: string}>
     */
    public static function table(): array
    {
        return [
            ['deny', 'lists', DenyRule::class, 'Kept out'],
            ['feed', 'lists', FeedRule::class, 'Public lists'],
            ['ban', 'lists', BanRule::class, 'Banned'],
            ['method', 'identity', MethodRule::class, 'Kind of request'],
            ['limits', 'shape', LimitsRule::class, 'Size'],
            ['path', 'shape', PathSanityRule::class, 'Disguised address'],
            ['host', 'identity', HostRule::class, 'Website name'],
            ['blocked', 'paths', BlockedPathRule::class, 'Addresses only attackers ask for'],
            ['method-path', 'paths', MethodPathRule::class, 'Where forms may be sent'],
            ['post-origin', 'origin', PostOriginRule::class, 'Where forms come from'],
            ['restricted', 'paths', RestrictedPathRule::class, 'Areas for certain visitors'],
            ['crawlers', 'crawlers', CrawlerRule::class, 'Known crawlers'],
            ['query', 'query', QueryRule::class, 'Known parameters'],
            ['content', 'content', ContentRule::class, 'Attack patterns'],
            ['cache', 'cache', CacheableRule::class, 'May a cache keep the answer?'],
            ['budget', 'budgets', BudgetRule::class, 'Pace'],
        ];
    }

    /**
     * The steps for a shield's rules: every row of the table, with the rule of
     * that class when the shield has one (null when its settings are not in
     * use), the budgets one step each. Built when asked for -- a request never
     * is: the shield runs the rules themselves.
     *
     * @param list<Rule> $rules in the shield's order
     * @return list<self>
     */
    public static function fromRules(array $rules): array
    {
        $byClass = [];
        foreach ($rules as $rule) {
            $byClass[get_class($rule)][] = $rule;
        }
        $steps = [];
        foreach (self::table() as [$key, $stage, $class, $describe]) {
            if ($class === BudgetRule::class) {
                foreach ($byClass[$class] ?? [] as $budget) {
                    if ($budget instanceof BudgetRule) {
                        $steps[] = new self('budget:' . $budget->name(), $stage, $budget, 'Pace: "' . $budget->name() . '"');
                    }
                }
                continue;
            }
            $steps[] = new self($key, $stage, $byClass[$class][0] ?? null, $describe);
        }
        return $steps;
    }
}
