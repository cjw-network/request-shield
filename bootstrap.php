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
 * It loads the settings from REQUEST_SHIELD_CONFIG (a constant or an
 * environment variable) or config/request-shield.php next to this file, and
 * runs the shield. Without a settings file it does nothing.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'CjwNetwork\\RequestShield\\', 25) === 0) {
        // The core, then the statistics plugin that comes with it (plugins/stats).
        $name = str_replace('\\', '/', substr($class, 25)) . '.php';
        foreach ([__DIR__ . '/src/', __DIR__ . '/plugins/stats/src/'] as $dir) {
            if (is_file($dir . $name)) {
                require $dir . $name;
                return;
            }
        }
    }
});

(static function (): void {
    if (PHP_SAPI === 'cli' || defined('REQUEST_SHIELD_DONE')) {
        return;
    }
    define('REQUEST_SHIELD_DONE', true);
    $named = defined('REQUEST_SHIELD_CONFIG') ? constant('REQUEST_SHIELD_CONFIG') : getenv('REQUEST_SHIELD_CONFIG');
    $file = is_string($named) && $named !== '' ? $named : __DIR__ . '/config/request-shield.php';
    if (!is_file($file)) {
        return;
    }
    \CjwNetwork\RequestShield\Shield::protectFile($file);
})();
