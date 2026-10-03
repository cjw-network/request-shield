<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield;

/**
 * Asks the plugins with the Pages capability for a page of a kind: the first
 * answer wins; none, or one that throws (noted once a minute), means the
 * core's page. Called only where the shield answers a request itself.
 */
final class PageHook
{
    /**
     * @param array<string, mixed> $ctx
     */
    public static function ask(Settings $s, string $kind, array $ctx): ?string
    {
        foreach ($s->hooks['pages'] ?? [] as $class) {
            try {
                if (!class_exists($class) || !is_subclass_of($class, Pages::class)) {
                    continue;
                }
                $page = (new $class($s))->page($kind, $ctx);
                if ($page !== null) {
                    return $page;
                }
            } catch (\Throwable $e) {
                Shield::failed('pages', "$class failed to draw the $kind page, the shield's own went out: " . $e->getMessage());
            }
        }
        return null;
    }

    /**
     * The hook as a callable for the classes that have no settings of their own (Gate, Responder).
     *
     * @return callable(string, array<string, mixed>): ?string
     */
    public static function asker(Settings $s): callable
    {
        return static function (string $kind, array $ctx) use ($s): ?string {
            /** @var array<string, mixed> $ctx */
            return self::ask($s, $kind, $ctx);
        };
    }
}
