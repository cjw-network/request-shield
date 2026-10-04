<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Api\Service;

use CjwNetwork\RequestShield\Api\OpenApi as Document;
use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Settings;

/** GET /openapi.json: this API described as OpenAPI 3.1 -- the endpoints the compiled settings have, the plugins' included. */
final class OpenApi implements ApiService
{
    public static function handle(Settings $s, array $params, array $ctx): array
    {
        return Document::document($s->dashboardPath);
    }
}
