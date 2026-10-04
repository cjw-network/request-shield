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
use CjwNetwork\RequestShield\Inspector;
use CjwNetwork\RequestShield\Settings;

/** POST /trace: what happens to a request, check by check -- as request-shield trace; nothing is counted. */
final class Trace implements ApiService
{
    public const PARAMS = [
        'url' => ['type' => 'string', 'required' => true, 'about' => 'a full address or a path: https://www.example.org/wp-login.php'],
        'method' => ['type' => 'string', 'about' => 'GET (default), POST, …'],
        'ip' => ['type' => 'string', 'about' => 'the visitor\'s address (default 198.51.100.7)'],
        'ua' => ['type' => 'string', 'about' => 'the User-Agent'],
    ];

    public const SCHEMA = ['type' => 'object', 'required' => ['method', 'url', 'ip', 'steps', 'verdict', 'rule', 'status', 'passes'], 'properties' => [
        'method' => ['type' => 'string'], 'url' => ['type' => 'string'], 'ip' => ['type' => 'string'],
        'steps' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['check', 'key', 'state', 'text', 'rule'], 'properties' => [
            'check' => ['type' => 'string'], 'key' => ['type' => 'string'], 'state' => ['type' => 'string', 'enum' => ['pass', 'note', 'stop', 'skip']],
            'text' => ['type' => 'string'], 'rule' => ['type' => ['string', 'null']],
        ]]],
        'verdict' => ['type' => 'string'], 'rule' => ['type' => ['string', 'null']], 'watched' => ['type' => ['string', 'null']],
        'status' => ['type' => 'integer'], 'passes' => ['type' => 'boolean'],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        $method = strtoupper(Params::string($params, 'method', 'GET', 10));
        $url = Params::string($params, 'url');
        $ip = Params::string($params, 'ip', '198.51.100.7', 64);
        $ua = Params::string($params, 'ua', '', 512);
        if (preg_match('/^[A-Z]{1,10}$/', $method) !== 1 || @inet_pton($ip) === false) {
            throw new ApiProblem(400, 'Bad request', 'method is a word such as GET, ip an IP address.');
        }
        $t = (new Inspector($s))->trace(Inspector::request($method, $url, $ip, $ua !== '' ? ['User-Agent' => $ua] : []));
        return ['method' => $method, 'url' => $url, 'ip' => $ip, 'steps' => $t['steps'], 'verdict' => $t['verdict'], 'rule' => $t['rule'], 'watched' => $t['watched'],
            'status' => $t['decision']->status, 'passes' => $t['decision']->passes()];
    }
}
