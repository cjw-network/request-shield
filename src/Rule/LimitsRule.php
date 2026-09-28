<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Rule;

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Request;

/** Sizes no page request needs: a very long URI, very many parameters, huge headers. */
final class LimitsRule implements Rule
{
    public function __construct(
        private readonly int $maxUri,
        private readonly int $maxQueryParameters,
        private readonly int $maxHeaderBytes,
    ) {
    }

    public function check(Request $request, float $now): ?Decision
    {
        if ($this->maxUri > 0 && strlen($request->rawUri) > $this->maxUri) {
            return Decision::reject(414, 'uri length');
        }
        if ($this->maxQueryParameters > 0 && substr_count($request->query, '&') + 1 > $this->maxQueryParameters
            && count($request->queryNames()) > $this->maxQueryParameters) {
            return Decision::reject(400, 'query parameters');
        }
        if ($this->maxHeaderBytes > 0 && $request->headerBytes > $this->maxHeaderBytes) {
            return Decision::reject(431, 'header size');
        }
        return null;
    }
}
