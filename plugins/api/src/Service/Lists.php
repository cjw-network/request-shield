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
use CjwNetwork\RequestShield\Rules\Lists as ListFiles;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;

/** GET /lists: the addresses kept out and let in (the list files), newest first, searched; and the bans in force. */
final class Lists implements ApiService
{
    public const PARAMS = [
        'q' => ['type' => 'string', 'about' => 'only entries whose line holds this: an address, an ID, a word of the note'],
        'limit' => ['type' => 'int', 'about' => 'at most this many entries: 1 to 500 (default 100)'],
    ];

    public const SCHEMA = ['type' => 'object', 'required' => ['entries', 'total', 'bans'], 'properties' => [
        'entries' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['kind', 'id', 'addresses', 'until', 'note'], 'properties' => [
            'kind' => ['type' => 'string', 'enum' => ['deny', 'exempt']], 'id' => ['type' => 'string'], 'addresses' => ['type' => 'array', 'items' => ['type' => 'string']],
            'until' => ['type' => ['integer', 'null']], 'note' => ['type' => 'string'],
        ]]],
        'total' => ['type' => 'integer'],
        'bans' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['bucket', 'until'], 'properties' => ['bucket' => ['type' => 'string'], 'until' => ['type' => 'integer']]]],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        if ($s->listsDir === null) {
            throw new ApiProblem(409, 'Conflict', 'There is no lists directory: set lists-dir (or store-dir) in the rule file.');
        }
        $found = ListFiles::find($s->listsDir, Params::string($params, 'q', '', 128), Params::int($params, 'limit', 100, 1, 500));
        $bans = [];
        if ($s->bans !== []) {
            foreach (Shield::storeFor($s)->marks('ban:', (float) $ctx['now']) as $key => $until) {
                $bans[] = ['bucket' => substr((string) $key, 4), 'until' => (int) $until];
            }
        }
        return ['entries' => $found['entries'], 'total' => $found['total'], 'bans' => $bans];
    }
}
