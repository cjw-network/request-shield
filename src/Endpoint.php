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
 * One endpoint of the API (RSF06-05): what it answers, for whom, and the
 * service that makes its data. The same service answers the HTTP request
 * and Api::call() in the same process, so both give the same array.
 */
final class Endpoint
{
    /**
     * @param string $method GET or POST (a write is a POST: DELETE is not among the methods a site accepts by default)
     * @param string $path below /api/v1, such as /lists/remove: fixed, the parameters come in the query (GET) or the JSON body (POST)
     * @param string $role reader (a customer's token may read it; the service shows that group only) or admin
     * @param bool $write changes something: only with set api-write on, and only for the administrator
     * @param class-string<ApiService> $service
     * @param array<string, mixed> $schema the shape of "data" in the answer (a JSON Schema subset: type, properties, items, required, enum)
     * @param string $feature the feature it belongs to (RSF<gg>-<nn>): its docs page
     * @param string $summary what it answers, in one sentence (OpenAPI, the reference)
     * @param array<string, array{type: string, required?: bool, about: string}> $params what it takes: name => type (string, int, bool), whether it must be there, what it is
     */
    public function __construct(
        /** @readonly */
        public string $method,
        /** @readonly */
        public string $path,
        /** @readonly */
        public string $role,
        /** @readonly */
        public bool $write,
        /** @readonly */
        public string $service,
        /** @readonly */
        public array $schema,
        /** @readonly */
        public string $feature,
        /** @readonly */
        public string $summary,
        /** @readonly */
        public array $params = [],
    ) {
    }
}
