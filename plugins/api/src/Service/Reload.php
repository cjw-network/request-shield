<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Api\Service;


use CjwNetwork\RequestShield\ApiProblem;
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Settings;

/** POST /reload: check, then mark the main rule file changed -- every server reads the rules on its next check; as request-shield reload. */
final class Reload implements ApiService
{
    public const SCHEMA = ['type' => 'object', 'required' => ['reloaded', 'warnings'], 'properties' => [
        'reloaded' => ['type' => 'boolean'], 'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $file = Params::ruleFile($ctx['ruleFile']);
        $check = Check::run($file);
        if (!$check['ok']) {
            throw new ApiProblem(409, 'Conflict', 'The rules do not compile, nothing reloaded: ' . $check['error']);
        }
        if (!@touch($file)) {
            throw new ApiProblem(500, 'Internal error', 'The rule file cannot be marked changed (the web server may not write it).');
        }
        return ['reloaded' => true, 'warnings' => $check['warnings']];
    }
}
