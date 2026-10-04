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

/** POST /lists/remove: an entry taken out, by its ID. */
final class ListsRemove implements ApiService
{
    public const PARAMS = ['id' => ['type' => 'string', 'required' => true, 'about' => 'the entry\'s ID: LIST-D3']];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        Params::string($params, 'id', null, 64);
        return ListsChange::change($s, 'remove', $params, $ctx);
    }
}
