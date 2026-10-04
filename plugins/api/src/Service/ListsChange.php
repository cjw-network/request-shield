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
use CjwNetwork\RequestShield\Report\ListsPage;
use CjwNetwork\RequestShield\Settings;

/**
 * POST /lists, /lists/update, /lists/remove, /lists/lift: a change to the
 * lists, as the dashboard's lists page makes it (the same checks: no trusted
 * proxy, not the caller's own address, a wide range only with confirm) --
 * every server reads it on its next check. A write: set api-write on.
 */
final class ListsChange implements ApiService
{
    public const PARAMS_ADD = [
        'kind' => ['type' => 'string', 'about' => 'deny (keep out, default) or exempt (let in)'],
        'address' => ['type' => 'string', 'required' => true, 'about' => 'an address or a range: 203.0.113.7, 198.51.100.0/24'],
        'for' => ['type' => 'string', 'about' => '1h, 1d, 7d (default), 30d, date (with until), good (for good: a note is needed)'],
        'until' => ['type' => 'string', 'about' => 'with for=date: 2026-10-07 or 2026-10-07T15:30'],
        'note' => ['type' => 'string', 'about' => 'why: shown in the lists and the live view'],
        'confirm' => ['type' => 'bool', 'about' => 'a wide range is meant'],
    ];

    public const SCHEMA = ['type' => 'object', 'required' => ['ok', 'message'], 'properties' => ['ok' => ['type' => 'boolean'], 'message' => ['type' => 'string']]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        return self::change($s, 'add', $params, $ctx);
    }

    /**
     * @param array<string, mixed> $params
     * @param array{who: string, ruleFile: ?string, now: int, ip: string, lang: string} $ctx
     * @return array{ok: bool, message: string}
     */
    public static function change(Settings $s, string $do, array $params, array $ctx): array
    {
        $post = ['do' => $do];
        foreach ($params as $k => $v) {
            if (is_scalar($v)) {
                $post[$k] = $k === 'confirm' ? (Params::bool($params, 'confirm') ? '1' : '') : (string) $v;
            }
        }
        $post['do'] = $do;
        $done = ListsPage::handle($s, $post, ['ip' => $ctx['ip'], 'user' => 'api', 'ruleFile' => $ctx['ruleFile'], 'csrfChecked' => true, 'lang' => $ctx['lang'], 'now' => $ctx['now']]);
        if (!$done['ok']) {
            throw new ApiProblem(409, 'Conflict', $done['message']);
        }
        return $done;
    }
}
