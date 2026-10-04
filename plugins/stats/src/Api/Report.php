<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Stats\Api;


use CjwNetwork\RequestShield\ApiService;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Stats\Report\StatsReport;

/**
 * GET /stats/report: what the counters say about a period -- as `request-shield
 * stats --json` and the statistics pages: requests, what the shield did, the
 * answers, pages, sections, not found, crawlers, bots, sitemaps, forms, the
 * sentences. A customer gets its group only, and not the rules that decided.
 */
final class Report implements ApiService
{
    public const PARAMS = Period::PARAMS + [
        'path' => ['type' => 'string', 'about' => 'only pages below it: /news/'],
        'crawler' => ['type' => 'string', 'about' => 'one known crawler: CRAWL-GOOGLE'],
        'sort' => ['type' => 'string', 'about' => 'views (default), blocked, refused, checked or throttled'],
    ];

    public const SCHEMA = ['type' => 'object', 'required' => ['from', 'to', 'days', 'by', 'totals', 'periods', 'rules', 'crawlers', 'pages', 'notFound', 'sentences'], 'properties' => [
        'from' => ['type' => 'string'], 'to' => ['type' => 'string'], 'days' => ['type' => 'integer'], 'by' => ['type' => 'string'],
        'totals' => ['type' => 'object'], 'periods' => ['type' => 'object'], 'rules' => ['type' => 'object'], 'crawlers' => ['type' => 'object'],
        'pages' => ['type' => 'object'], 'notFound' => ['type' => 'object'], 'sentences' => ['type' => 'array', 'items' => ['type' => 'string']],
    ]];

    public static function handle(Settings $s, array $params, array $ctx): array
    {
        Period::on($s);
        [$from, $to, $by, $days] = Period::of($params, $ctx['now']);
        $o = ['from' => $from, 'to' => $to, 'by' => $by, 'lang' => $ctx['lang']];
        $site = Period::site($s, $params, $ctx['who']);
        if ($site !== null) {
            $o['site'] = $site;
        }
        $crawler = is_string($params['crawler'] ?? null) ? $params['crawler'] : '';
        if ($crawler !== '' && isset($s->crawlers[$crawler])) {
            $o['crawler'] = $crawler;
        }
        $path = is_string($params['path'] ?? null) ? $params['path'] : '';
        if ($path !== '') {
            $o['path'] = preg_match('#^[a-z0-9*+()][a-z0-9.*+()-]*/#i', $path) === 1 ? $path : '/' . ltrim($path, '/');
        }
        $sort = is_string($params['sort'] ?? null) ? $params['sort'] : '';
        if (in_array($sort, ['views', 'blocked', 'refused', 'checked', 'throttled'], true)) {
            $o['sort'] = $sort;
        }
        $report = StatsReport::build($s, null, $days, $ctx['now'], $o);
        // Objects, not lists, where the keys are names: an empty one is {} in JSON too.
        foreach (['periods', 'totals', 'monitor', 'daily', 'hourly', 'rules', 'crawlers', 'bots', 'statuses', 'notFound', 'sitemaps', 'pages', 'folders', 'stopped', 'forms', 'backend'] as $k) {
            if (array_key_exists($k, $report) && $report[$k] === []) {
                $report[$k] = new \stdClass();
            }
        }
        if ($ctx['who'] !== '*') {
            $report['rules'] = new \stdClass();       // a customer: the protection's numbers, not the rules behind them
            $report['monitor'] = new \stdClass();
        }
        return $report;
    }
}
