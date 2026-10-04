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
 * An answer of the API that is no data: a problem, as RFC 9457 has it --
 * status, a short title, the detail in one sentence. Thrown by a service,
 * answered by the API plugin as application/problem+json.
 */
final class ApiProblem extends \RuntimeException
{
    public function __construct(
        /** @readonly */
        public int $status,
        /** @readonly */
        public string $title,
        string $detail = '',
    ) {
        parent::__construct($detail);
    }

    /** @return array{type: string, title: string, status: int, detail: string} the body */
    public function body(): array
    {
        return ['type' => 'about:blank', 'title' => $this->title, 'status' => $this->status, 'detail' => $this->getMessage()];
    }
}
