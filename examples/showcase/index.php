<?php
/**
 * request-shield's showcase: one page that shows what the shield does, in
 * plain words, and lets you try it -- every try a real request to this
 * server, decided by the real shield with the rules in showcase.rules.
 *
 *   php -S 127.0.0.1:8090 examples/showcase/router.php    then open http://127.0.0.1:8090/
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

// ── The integration: these two lines, first in the front controller ─────────
define('REQUEST_SHIELD_CONFIG', __DIR__ . '/showcase.rules');
// lib/ next to this file in a standalone copy (build/showcase.php), the repository's otherwise.
require is_file(__DIR__ . '/lib/bootstrap.php') ? __DIR__ . '/lib/bootstrap.php' : __DIR__ . '/../../bootstrap.php';
// ─────────────────────────────────────────────────────────────────────────────
// Below runs only for requests the shield lets through.

require __DIR__ . '/site.php';
