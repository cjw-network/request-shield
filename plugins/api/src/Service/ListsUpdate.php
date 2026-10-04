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
use CjwNetwork\RequestShield\Settings;

/** POST /lists/update: another end or another note for an entry, by its ID. */
final class ListsUpdate implements ApiService
{
    public const PARAMS = [
        'id' => ['type' => 'string', 'required' => true, 'about' => 'the entry\'s ID: LIST-D3'],
        'for' => ['type' => 'string', 'about' => '1h, 1d, 7d, 30d, date (with until), good'],
        'until' => ['type' => 'string', 'about' => 'with for=date: 2026-10-07 or 2026-10-07T15:30'],
        'note' => ['type' => 'string', 'about' => 'the new note'],
    ];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        Params::string($params, 'id', null, 64);
        return ListsChange::change($s, 'update', $params, $ctx);
    }
}
