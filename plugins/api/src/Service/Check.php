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
use CjwNetwork\RequestShield\Cli;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Tier;

/** POST /check: the rule files read again and compiled -- the first mistake, or the warnings and the tier; as request-shield check. Nothing is changed. */
final class Check implements ApiService
{
    public const SCHEMA = ['type' => 'object', 'required' => ['ok', 'error', 'warnings', 'tier'], 'properties' => [
        'ok' => ['type' => 'boolean'], 'error' => ['type' => ['string', 'null']],
        'warnings' => ['type' => 'array', 'items' => ['type' => 'string']], 'tier' => ['type' => ['string', 'null']],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        return self::run(Params::ruleFile($ctx['ruleFile']));
    }

    /** @return array{ok: bool, error: ?string, warnings: list<string>, tier: ?string} */
    public static function run(string $file): array
    {
        try {
            $read = RuleFile::read([$file]);
            $settings = Settings::from($read['config']);
        } catch (\InvalidArgumentException $e) {
            // The mistake by the file's name and line: never the server's paths.
            return ['ok' => false, 'error' => Params::relative($e->getMessage(), $file), 'warnings' => [], 'tier' => null];
        }
        $tier = Tier::of($settings, Settings::cacheDirFor($file));
        return ['ok' => true, 'error' => null, 'warnings' => array_map(static fn (string $w): string => Params::relative($w, $file), array_merge(Cli::warnings($settings, $read, $file), $tier['off'])),
            'tier' => $tier['tier']];
    }
}
