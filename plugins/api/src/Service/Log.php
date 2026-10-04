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
use CjwNetwork\RequestShield\Report\LogTail;
use CjwNetwork\RequestShield\Settings;

/** GET /log: the log's lines since the cursor, parsed -- what was refused or checked, and why. */
final class Log implements ApiService
{
    public const PARAMS = ['cursor' => ['type' => 'string', 'about' => 'where the last call ended (its answer\'s cursor); none: the end of the log']];

    public const SCHEMA = ['type' => 'object', 'required' => ['cursor', 'rows', 'skipped'], 'properties' => [
        'cursor' => ['type' => 'string'], 'skipped' => ['type' => 'integer'],
        'rows' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['time', 'client', 'action', 'status', 'reason', 'rule', 'method', 'url'], 'properties' => [
            'time' => ['type' => 'integer'], 'client' => ['type' => 'string'], 'action' => ['type' => 'string'], 'status' => ['type' => 'integer'],
            'reason' => ['type' => 'string'], 'rule' => ['type' => ['string', 'null']], 'claimed' => ['type' => ['string', 'null']],
            'method' => ['type' => 'string'], 'url' => ['type' => 'string'], 'agent' => ['type' => 'string'],
        ]]],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        if ($s->logFile === null) {
            throw new ApiProblem(409, 'Conflict', 'There is no log: set log <file> in the rule file.');
        }
        $cursor = Params::string($params, 'cursor', '', 64);
        return LogTail::read($s->logFile, $cursor === '' ? null : $cursor);
    }
}
