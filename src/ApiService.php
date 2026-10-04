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
 * What answers an endpoint of the API (RSF06-05): it gets the settings in
 * force, the parameters (the query of a GET, the JSON body of a POST) and
 * who asks, and returns the "data" of the answer -- or throws an ApiProblem.
 * It never writes output; it counts nothing unless it is a write.
 */
interface ApiService
{
    /**
     * @param array<string, mixed> $params
     * @param array{who: string, ruleFile: ?string, now: int, ip: string, lang: string} $ctx who: '*' (the administrator) or a group's id;
     *   ruleFile: the main rule file the shield runs from (null: settings from a PHP array)
     * @return array<mixed>
     */
    public static function handle(Settings $s, array $params, array $ctx): array;
}
