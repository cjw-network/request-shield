<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield;

use CjwNetwork\RequestShield\Store\ApcuStore;

/**
 * What this installation can do (ADR 0013): PHP is the only requirement;
 * a writable directory and APCu are tiers the shield detects. For the CLI
 * (check, version) -- it stats directories, so never on a request path.
 *
 *   S0 stateless: no writable directory, no APCu -- stateless rules only;
 *      settings compiled on every request; nothing counted.
 *   S1 file: a writable store directory, no APCu -- everything, in files.
 *   S2 APCu: counters in shared memory.
 */
final class Tier
{
    /**
     * @return array{tier: string, store: string, why: list<string>, off: list<string>, chosen: list<string>} the tier,
     *   the store in use, what decided it, what the environment leaves inactive (worth a warning), and what
     *   the settings chose to leave inactive (worth a note, not a warning)
     */
    public static function of(Settings $s, string $cacheDir): array
    {
        $apcu = ApcuStore::usable();
        $store = $s->store === 'auto' ? ($apcu ? 'apcu' : 'file') : $s->store;
        $why = [];
        $off = [];
        $chosen = [];
        $why[] = $apcu ? 'APCu is available' : 'APCu is not available' . (PHP_SAPI === 'cli' ? ' to the CLI (the web server may differ; apc.enable_cli=1 switches it on here)' : '');
        $storeOk = self::writable($s->storeDir);
        $cacheOk = self::writable($cacheDir);
        $why[] = "store-dir $s->storeDir is " . ($storeOk ? 'writable' : 'not writable');
        $why[] = "the compiled settings' directory $cacheDir is " . ($cacheOk ? 'writable' : 'not writable');
        if (!$cacheOk) {
            $off[] = 'the settings are compiled on every request (slow, but correct)';
        }
        if ($store === 'memory') {
            $tier = 'S0';
            $chosen[] = 'set store memory: nothing is counted beyond this request (budgets, bans), no secret is kept (the pass cookie does not outlast a request)';
        } elseif ($store === 'apcu') {
            $tier = 'S2';
            if (!$storeOk) {
                $off[] = 'the secret is not kept (store-dir not writable): a pass cookie does not outlast the APCu memory; lists, feeds, crawler updates and statistics have nowhere to go';
            }
        } elseif ($storeOk) {
            $tier = 'S1';
        } else {
            $tier = 'S0';
            $off[] = 'nothing is counted (budgets, bans, the pace), no secret is kept (the pass cookie does not outlast a request), lists, feeds, crawler updates and statistics have nowhere to go -- stateless rules decide as usual';
        }
        return ['tier' => $tier, 'store' => $store, 'why' => $why, 'off' => $off, 'chosen' => $chosen];
    }

    /** Whether the directory can be written, or made (its nearest existing parent can be written). */
    private static function writable(string $dir): bool
    {
        $d = $dir;
        for ($i = 0; $i < 8 && !is_dir($d); $i++) {
            $parent = dirname($d);
            if ($parent === $d) {
                return false;
            }
            $d = $parent;
        }
        return is_dir($d) && is_writable($d);
    }
}
