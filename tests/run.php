<?php
/**
 * Runs every tests/*Test.php. Each returns an array of name => closure; a
 * closure passes when it returns without an exception.
 *
 *   php tests/run.php [filter]      a part of "File > test name", or a feature id (RSF02-06, the group RSF02)
 */

declare(strict_types=1);

require dirname(__DIR__) . '/testkit/src/testkit.php';   // the runner, same(), truthy(), skip(), freePort() (0031 H.1)
require __DIR__ . '/helpers.php';
require rsEntry();                                   // bootstrap.php, or the built single file (REQUEST_SHIELD_ENTRY, 0031 E.3)
require __DIR__ . '/support/RsTestExtension.php';   // the test extension (0031 B.2); an E2E server loads it from its prepend file
require __DIR__ . '/support/CountingPlugin.php';    // a plugin with the RuleCounts capability (0031 B.8)
require __DIR__ . '/support/SinkPlugin.php';        // a plugin with the Sink capability (0031 B.9)
require __DIR__ . '/support/PagesPlugin.php';       // a plugin with the Pages capability (0031 B.10)
require __DIR__ . '/support/RulesPlugin.php';       // a plugin with the RuleProvider capability (0031 C.3)
require __DIR__ . '/support/HandlerPlugin.php';     // a plugin with the Handler capability (0031 C.4)

// Against the core single file a plugin's feature is skipped: the core only (needsPlugins()).
exit(testkitRun(__FILE__, array_values(array_map('strval', $argv)), glob(__DIR__ . '/*Test.php') ?: [], static function (string $name): void {
    if (pluginFeature($name)) {
        needsPlugins();
    }
}));
