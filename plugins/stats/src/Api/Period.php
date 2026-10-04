<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Stats\Api;


use CjwNetwork\RequestShield\ApiProblem;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Stats\StatsExtension;

/** The parameters the statistics' endpoints share: the period, the website, a path, a crawler. */
final class Period
{
    public const PARAMS = [
        'days' => ['type' => 'int', 'about' => 'the last days: 1 to 400 (default 7)'],
        'from' => ['type' => 'string', 'about' => 'a period instead: from YYYY-MM-DD (with to)'],
        'to' => ['type' => 'string', 'about' => 'to YYYY-MM-DD'],
        'by' => ['type' => 'string', 'about' => 'day (default), week, month or year'],
        'site' => ['type' => 'string', 'about' => 'one website (stats-hosts), or group:<id>; a customer sees its group only'],
    ];

    /** The statistics are on, or a 409 that says how to switch them on. */
    public static function on(Settings $s): void
    {
        if (StatsExtension::of($s)['enabled'] !== true) {
            throw new ApiProblem(409, 'Conflict', 'The statistics are off: set stats on in the rule file.');
        }
    }

    /**
     * The period asked for: [from, to] as YYYYMMDD, and how it is grouped.
     *
     * @param array<string, mixed> $p
     * @return array{0: string, 1: string, 2: string, 3: int}
     */
    public static function of(array $p, int $now): array
    {
        $days = $p['days'] ?? 7;
        if (!is_numeric($days) || (int) $days < 1 || (int) $days > 400) {
            throw new ApiProblem(400, 'Bad request', 'days is a whole number from 1 to 400.');
        }
        $by = is_string($p['by'] ?? null) && $p['by'] !== '' ? $p['by'] : 'day';
        if (!in_array($by, ['day', 'week', 'month', 'year'], true)) {
            throw new ApiProblem(400, 'Bad request', 'by is day, week, month or year.');
        }
        $from = is_string($p['from'] ?? null) ? $p['from'] : '';
        $to = is_string($p['to'] ?? null) ? $p['to'] : '';
        if ($from !== '' || $to !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1 || $from > $to
                || (int) round(((int) strtotime($to . ' UTC') - (int) strtotime($from . ' UTC')) / 86400) > 3660) {
                throw new ApiProblem(400, 'Bad request', 'from and to are days (YYYY-MM-DD), from not after to, at most ten years apart.');
            }
            $f = str_replace('-', '', $from);
            $t = str_replace('-', '', $to);
            return [$f, $t, $by, (int) round(((int) strtotime($t . ' UTC') - (int) strtotime($f . ' UTC')) / 86400) + 1];
        }
        $t = gmdate('Ymd', $now);
        return [gmdate('Ymd', $now - ((int) $days - 1) * 86400), $t, $by, (int) $days];
    }

    /** @param array<string, mixed> $p */
    public static function site(Settings $s, array $p, string $who): ?string
    {
        $asked = is_string($p['site'] ?? null) && $p['site'] !== '' ? $p['site'] : null;
        return StatsExtension::siteFor($s, $who, $asked);
    }
}
