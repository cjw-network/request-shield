<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield;

use CjwNetwork\RequestShield\Rule\Step;
use CjwNetwork\RequestShield\Store\Store;

/**
 * A capability on a Plugin (0031 C.3): it adds rules of its own to the chain.
 * Each Step names a stage (Step::STAGES, never "lists": the deny list and the
 * bans stay the core's and come first); the shield puts it after that stage's
 * own steps. A provided rule can only tighten (the shield keeps the strictest
 * decision), a rule that throws says nothing for that request (noted once a
 * minute), a provider that throws here adds nothing. Recorded into the
 * compiled settings (`$s->hooks['ruleProvider']`); a shield without providers
 * pays nothing.
 */
interface RuleProvider
{
    /**
     * @return list<Step> steps with a rule each; the key names the step in the
     *   trace and the rules page ("acme-geo"), the stage says where it runs
     */
    public function rules(Settings $s, Store $store): array;
}
