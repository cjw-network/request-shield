<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 *
 * Names the shipped extensions -- the statistics, the API and the HTTP cache --
 * for the rule compiler (Rules\Vocabulary offers them on its first lookup), so "set stats
 * on" and "set api-write on" are known without a plugin line. Composer loads this file (autoload "files"); bootstrap.php, for sites
 * without Composer, defines the same constant itself. Only a constant: a
 * passing request loads no class for it.
 */

declare(strict_types=1);

if (!defined('REQUEST_SHIELD_EXTENSIONS')) {
    define('REQUEST_SHIELD_EXTENSIONS', ['CjwNetwork\\RequestShield\\Stats\\StatsExtension', 'CjwNetwork\\RequestShield\\Api\\ApiExtension', 'CjwNetwork\\RequestShield\\Cache\\CacheExtension']);
}
