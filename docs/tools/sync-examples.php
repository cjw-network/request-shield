<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 *
 *   php docs/tools/sync-examples.php [--check]
 *
 * Puts each feature's examples into its page (0031 F.5): the demo's "# demo:"
 * group of the feature (examples/demo/request-shield.rules), as the table
 * `request-shield examples --markdown --feature=<id>` writes, between the
 * page's "<!-- examples … -->" and "<!-- /examples -->". A page with a group
 * but without the markers gets them, in a section of its own at its end.
 * The demo, the docs and `request-shield test` show the same lines.
 *
 * --check writes nothing and exits 1 when a page is not what it would write
 * (the tests run it). Exit 0: written or current.
 */

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use CjwNetwork\RequestShield\Report\DemoSite;
use CjwNetwork\RequestShield\Report\ExamplesPage;

$root = dirname(__DIR__, 2);
$check = in_array('--check', $argv, true);
$groups = DemoSite::groups("$root/examples/demo/request-shield.rules");
$ids = array_values(array_unique(array_column($groups, 'id')));
$open = '<!-- examples: docs/tools/sync-examples.php from examples/demo/request-shield.rules -- do not edit; run the tool. -->';

$stale = [];
foreach (glob("$root/docs/features/RSF*.md") ?: [] as $page) {
    $id = substr(basename($page), 0, 8);
    $text = (string) file_get_contents($page);
    $re = '/' . preg_quote($open, '/') . '\n.*?<!-- \/examples -->/s';
    $has = preg_match($re, $text) === 1;
    if (!in_array($id, $ids, true)) {
        if ($has) {
            fwrite(STDERR, "sync-examples: $id has markers but no \"# demo: $id\" group in the demo\n");
            exit(1);
        }
        continue;
    }
    $block = $open . "\n" . ExamplesPage::markdown($groups, $id) . "<!-- /examples -->";
    $new = $has ? (string) preg_replace_callback($re, static fn (): string => $block, $text)
        : rtrim($text) . "\n\n## Examples from the demo\n\nWhat the demo's rules decide for this feature -- the same lines `request-shield test` checks "
            . "and the demo's front page shows (`php -S 127.0.0.1:8080 examples/demo/router.php`).\n\n$block\n";
    if ($new !== $text) {
        $stale[] = basename($page);
        if (!$check) {
            file_put_contents($page, $new);
        }
    }
}
if ($check && $stale !== []) {
    fwrite(STDERR, 'sync-examples: not current -- php docs/tools/sync-examples.php: ' . implode(', ', $stale) . "\n");
    exit(1);
}
echo $stale === [] ? "sync-examples: current\n" : 'sync-examples: written ' . implode(', ', $stale) . "\n";
