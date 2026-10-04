<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield;


/**
 * An extension that offers endpoints to the API (RSF06-05, 0031 G.0): the
 * API plugin (plugins/api) asks every offered extension that implements it
 * when the rules are compiled, and serves what they return below
 * <dashboard-path>/api/v1. Static, like Extension: nothing runs per request.
 */
interface ApiProvider
{
    /** @return list<Endpoint> */
    public static function api(): array;
}
