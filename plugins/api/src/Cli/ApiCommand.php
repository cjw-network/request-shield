<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Api\Cli;

use CjwNetwork\RequestShield\Api\Api;
use CjwNetwork\RequestShield\Api\OpenApi;
use CjwNetwork\RequestShield\Cli\Command;
use CjwNetwork\RequestShield\Cli\Context;

/**
 * `request-shield api <main.rules> GET /status` calls an endpoint in this
 * process, as the administrator, and prints its answer (what a CMS's backend
 * gets from Api::call()); `--openapi` prints the API's description.
 */
final class ApiCommand implements Command
{
    public static function usage(): string
    {
        return 'request-shield api <main.rules> "GET|POST </path>" [--<param>=<value>]... | --openapi';
    }

    public static function run(Context $c): int
    {
        // Its own options from the arguments as given: --openapi, --<param>=<value>.
        $openapi = false;
        $params = [];
        $words = [];
        foreach (array_slice($c->args, 2) as $a) {
            if ($a === '--openapi') {
                $openapi = true;
            } elseif (preg_match('/^--([a-z][a-zA-Z0-9_-]*)=(.*)$/s', $a, $m) === 1) {
                $params[$m[1]] = $m[2];
            } elseif (strncmp($a, '--', 2) !== 0) {
                $words[] = $a;
            }
        }
        if ($openapi) {
            echo json_encode(OpenApi::document($c->settings->dashboardPath), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            return 0;
        }
        $parts = preg_split('/\s+/', trim(implode(' ', $words))) ?: [];
        if (count($parts) !== 2 || !in_array(strtoupper($parts[0]), ['GET', 'POST'], true)) {
            fwrite(STDERR, 'usage: ' . self::usage() . "\n");
            return 2;
        }
        $answer = Api::call($c->settings, $parts[0], $parts[1], $params, '*', ['ruleFile' => $c->file, 'ip' => '127.0.0.1']);
        echo json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        return isset($answer['data']) ? 0 : 1;
    }
}
