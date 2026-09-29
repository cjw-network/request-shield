<?php
/**
 * Settings for the demo site. Low limits on purpose, so every reaction can be
 * seen within a few clicks; a real site uses the defaults or more.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

return [
    // The demo answers any host. A real site lists its own:
    // 'hosts' => ['www.example.org', 'example.org'],
    'hosts' => [],

    // What may be cached: the front page and /page/<name>, with ?page=<n>.
    // Anything else is answered, but marked "allow-uncached".
    'cacheable' => [
        'paths' => ['#^/(page/[a-z0-9-]+)?$#'],
        'query' => ['page'],
    ],

    // Per visitor and minute: past 20 requests the invisible browser check,
    // past 60 a pause (429). Reload the page 20 times to see the check.
    'budgets' => [
        'requests' => ['limit' => 60, 'window' => 60, 'challengeAt' => 20],
    ],

    // The demo runs on your own machine: count localhost too (a real site
    // exempts its monitoring and its own servers here).
    'exempt' => ['ips' => []],

    'challenge' => [
        // Always checked, whatever the budget says -- as a login page would be.
        'alwaysPaths' => ['#^/challenge$#'],
        'difficulty' => ['min' => 50000, 'max' => 300000],
        'searchEngines' => false,
    ],

    // Counters and the generated secret next to the demo (or where the tests say).
    'storeDir' => getenv('REQUEST_SHIELD_DEMO_VAR') ?: __DIR__ . '/var',

    // X-Request-Shield: <decision> <reason> on every response, to watch it work.
    'debugHeader' => true,
];
