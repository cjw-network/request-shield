<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Api\Service;


use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Rules\Examples;
use CjwNetwork\RequestShield\Settings;

/** POST /test: every example next to the rules, decided on a fresh store -- as request-shield test; nothing is counted. */
final class Test implements ApiService
{
    public const PARAMS = [
        'only' => ['type' => 'string', 'about' => 'the examples of this rule ID only'],
        'asWritten' => ['type' => 'bool', 'about' => 'monitor as written, not as enforce'],
    ];

    public const SCHEMA = ['type' => 'object', 'required' => ['examples', 'pass', 'fail', 'skip', 'results', 'without'], 'properties' => [
        'examples' => ['type' => 'integer'], 'pass' => ['type' => 'integer'], 'fail' => ['type' => 'integer'], 'skip' => ['type' => 'integer'],
        'results' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['status', 'method', 'url', 'expected', 'got', 'at'], 'properties' => [
            'status' => ['type' => 'string', 'enum' => ['pass', 'fail', 'skip']], 'rule' => ['type' => ['string', 'null']], 'method' => ['type' => 'string'], 'url' => ['type' => 'string'],
            'expected' => ['type' => 'string'], 'got' => ['type' => 'string'], 'why' => ['type' => 'string'], 'at' => ['type' => 'string'],
        ]]],
        'without' => ['type' => 'array', 'items' => ['type' => 'string']],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $file = Params::ruleFile($ctx['ruleFile']);
        $only = Params::string($params, 'only', '', 64);
        $run = Examples::run([$file], Params::bool($params, 'asWritten'), $only !== '' ? $only : null);
        $counts = ['pass' => 0, 'fail' => 0, 'skip' => 0];
        $results = [];
        foreach ($run['results'] as $r) {
            $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
            $x = $r['example'];
            $results[] = ['status' => $r['status'], 'rule' => $r['about'], 'method' => $x['method'], 'url' => $x['url'],
                'expected' => $x['outcome'] . ($x['by'] !== null ? ' by ' . $x['by'] : ''), 'got' => $r['got'] . ($r['gotRule'] !== null ? ' by ' . $r['gotRule'] : ''),
                'why' => $r['why'], 'at' => basename((string) preg_replace('/:\d+$/', '', $x['at'])) . (preg_match('/:(\d+)$/', $x['at'], $m) === 1 ? ':' . $m[1] : '')];
        }
        return ['examples' => count($results)] + $counts + ['results' => $results, 'without' => $only !== '' ? [] : $run['without']];
    }
}
