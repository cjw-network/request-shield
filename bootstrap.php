<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 *
 * For sites without Composer, or to run before Composer's autoloader:
 *
 *   ; .user.ini (PHP-FPM, LiteSpeed LSAPI) or php.ini
 *   auto_prepend_file = /path/to/request-shield/bootstrap.php
 *
 *   # .htaccess (Apache mod_php, LiteSpeed)
 *   php_value auto_prepend_file /path/to/request-shield/bootstrap.php
 *
 * It finds the settings -- REQUEST_SHIELD_CONFIG (a constant or an environment
 * variable), else request-shield.rules next to this file, else
 * config/request-shield.rules, else config/request-shield.php -- and runs the
 * shield. Without a settings file it does nothing.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'CjwNetwork\\RequestShield\\', 25) === 0) {
        // The core (src/); the shipped plugins each in its own namespace (plugins/<name>/src, 0031 D.2, G.0).
        $rest = substr($class, 25);
        $dir = __DIR__ . '/src/';
        foreach (['Stats\\' => '/plugins/stats/src/', 'Api\\' => '/plugins/api/src/', 'Cache\\' => '/plugins/cache/src/', 'Waf\\' => '/plugins/waf/src/'] as $ns => $path) {
            if (strncmp($rest, $ns, strlen($ns)) === 0) {
                $dir = __DIR__ . $path;
                $rest = substr($rest, strlen($ns));
                break;
            }
        }
        $file = $dir . str_replace('\\', '/', $rest) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// The shipped extensions: their words (set stats on, stats-skip …) are known to
// the rule file without a plugin line. Only named here -- Rules\Vocabulary loads
// and offers them the first time the rules are compiled (class_exists(), so a
// build without plugins/stats has none); a passing request loads no class for it.
if (!defined('REQUEST_SHIELD_EXTENSIONS')) {
    define('REQUEST_SHIELD_EXTENSIONS', ['CjwNetwork\\RequestShield\\Stats\\StatsExtension', 'CjwNetwork\\RequestShield\\Api\\ApiExtension', 'CjwNetwork\\RequestShield\\Cache\\CacheExtension', 'CjwNetwork\\RequestShield\\Waf\\WafExtension']);
}

(static function (): void {
    if (PHP_SAPI === 'cli' || defined('REQUEST_SHIELD_DONE')) {
        return;
    }
    define('REQUEST_SHIELD_DONE', true);
    $named = defined('REQUEST_SHIELD_CONFIG') ? constant('REQUEST_SHIELD_CONFIG') : getenv('REQUEST_SHIELD_CONFIG');
    if (is_string($named) && $named !== '') {
        if (is_file($named)) {
            \CjwNetwork\RequestShield\Shield::protectFile($named);
        }
        return;                 // named, and not there: nothing -- not another file by surprise
    }
    // Next to this file, as the single-file build is installed; else config/.
    // Up to three is_file() -- naming the file (above) saves them.
    foreach ([__DIR__ . '/request-shield.rules', __DIR__ . '/config/request-shield.rules', __DIR__ . '/config/request-shield.php'] as $file) {
        if (is_file($file)) {
            \CjwNetwork\RequestShield\Shield::protectFile($file);
            return;
        }
    }
})();
