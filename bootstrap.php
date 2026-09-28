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
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 25)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

(static function (): void {
    if (PHP_SAPI === 'cli' || defined('REQUEST_SHIELD_DONE')) {
        return;
    }
    define('REQUEST_SHIELD_DONE', true);
    $file = defined('REQUEST_SHIELD_CONFIG') ? (string) constant('REQUEST_SHIELD_CONFIG')
        : (getenv('REQUEST_SHIELD_CONFIG') ?: __DIR__ . '/config/request-shield.php');
    if (!is_file($file)) {
        return;
    }
    $config = require $file;
    if (is_array($config)) {
        \CjwNetwork\RequestShield\Shield::protect($config);
    }
})();
