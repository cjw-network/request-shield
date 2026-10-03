<?php

declare(strict_types=1);

/**
 * examples/exponential: the proposal for an Exponential site decides as its
 * examples say -- the expect lines next to each rule (proposal 0029), decided
 * as request-shield test does, with the rules switched on (the files ship in
 * monitor mode).
 */

use CjwNetwork\RequestShield\Rules\Examples;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Settings;

/** The main file for an admin as /admin ('uri') or on a host of its own ('host'). */
function exponentialMain(string $admin): string
{
    putenv('EXP_VAR=' . sys_get_temp_dir() . '/rshield-exp-' . getmypid());
    return dirname(__DIR__) . "/examples/exponential/exponential-admin-$admin.rules";
}

/** Every example of a main file passes, none is skipped, and every rule has one. */
function exponentialExamplesPass(string $admin): void
{
    $run = Examples::run([exponentialMain($admin)]);
    $bad = array_filter($run['results'], static fn (array $r): bool => $r['status'] !== 'pass');
    same([], array_map(static fn (array $r): string => $r['example']['at'] . ' ' . $r['example']['method'] . ' ' . $r['example']['url'] . ': ' . $r['why'], array_values($bad)), "admin-$admin: every example");
    same([], $run['without'], "admin-$admin: every rule has an example");
    truthy(count($run['results']) >= 60, "admin-$admin: the examples, " . count($run['results']));
}

return [
    'RSF05-01 both main files are valid as shipped (monitor mode): check finds no error' => function (): void {
        foreach (['uri', 'host'] as $admin) {
            $read = RuleFile::read([exponentialMain($admin)]);
            same('monitor', $read['config']['mode'] ?? null, "admin-$admin ships in monitor mode: log first, refuse nobody");
            truthy(!isset($read['config']['examples']), 'the examples are never part of the settings a request loads');
        }
    },

    'RSF05-01 the admin as the siteaccess /admin: every example next to the rules passes, switched on' => static fn () => exponentialExamplesPass('uri'),

    'RSF05-01 the admin on a host of its own: every example passes -- its block picked by the server name' => static fn () => exponentialExamplesPass('host'),

    'RSF05-01 as written (monitor mode): nobody is refused -- the examples that expect a refusal say so' => function (): void {
        $run = Examples::run([exponentialMain('uri')], true);
        $failed = array_filter($run['results'], static fn (array $r): bool => $r['status'] === 'fail');
        truthy(count($failed) > 20, 'refusals expected, none given: ' . count($failed));
        foreach ($failed as $r) {
            same('uncached', $r['got'], $r['example']['url'] . ': watched, let through, never cached');
        }
    },

    'RSF05-01 pass lifetimes: editors on their own host one check a working day, visitors two hours' => function (): void {
        $dir = sys_get_temp_dir() . '/rshield-exp-cache-' . getmypid();
        try {
            same(28800, Settings::loadFor(exponentialMain('host'), ['SERVER_NAME' => 'admin.example.org'], $dir)->challenge->passTtl, 'editors on their own host');
            same(7200, Settings::loadFor(exponentialMain('host'), ['SERVER_NAME' => 'www.example.org'], $dir)->challenge->passTtl, 'visitors');
            same(7200, Settings::loadFor(exponentialMain('uri'), ['SERVER_NAME' => 'www.example.org'], $dir)->challenge->passTtl, 'one host for both: two hours');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
