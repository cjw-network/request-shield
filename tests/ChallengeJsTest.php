<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Challenge\ProofOfWork;

/**
 * The challenge page's own script, run in Node: it must solve what PHP
 * created, and PHP must accept its payload. Skipped where there is no node.
 */

return [
    'RSF3.2 the page script solves a PHP challenge, and PHP accepts it' => function (): void {
        if (nodeBinary() === null) {
            skip('no node on this machine');
        }
        $pow = new ProofOfWork('js-secret-0123456789abcdef0123456789abcdef');
        foreach ([1000, 50000] as $max) {
            $c = $pow->create('203.0.113.7', $max, 5000);
            [$payload, $ms, $n] = solveInNode($c);
            truthy(is_string($payload) && $payload !== '', "maxnumber $max: solved");
            truthy($pow->verify($payload, '203.0.113.7', 1000.0), "maxnumber $max: PHP accepts the script's payload");
            truthy(!$pow->verify($payload, '203.0.113.8', 1000.0), "maxnumber $max: not for another client");
            truthy(preg_match('/^[A-Za-z0-9_-]+$/', $payload) === 1, 'cookie-safe characters only (no +, /, =)');
        }
    },
    'RSF3.2 the page script reports an unsolvable challenge instead of looping' => function (): void {
        if (nodeBinary() === null) {
            skip('no node on this machine');
        }
        $pow = new ProofOfWork('js-secret-0123456789abcdef0123456789abcdef');
        $c = $pow->create('203.0.113.7', 1000, 5000);
        $c['challenge'] = str_repeat('0', 64);          // no number hashes to this
        [$payload, , $n] = solveInNode($c);
        same(null, $payload);
        same(-1, $n);
    },
    'RSF3.2 the check page stops a loop -- not checks passed before, other pages, time gone by' => function (): void {
        $node = nodeBinary();
        if ($node === null) {
            skip('no node on this machine');
        }
        $dir = sys_get_temp_dir() . '/rshield-tries-' . getmypid() . '-' . mt_rand();
        mkdir($dir);
        file_put_contents("$dir/page.js", \CjwNetwork\RequestShield\Challenge\ChallengePage::SCRIPT);
        file_put_contents("$dir/tries.js", <<<'JS'
// The check page's script four times in one "tab" (a shared sessionStorage).
var store = {};
global.sessionStorage = { getItem: function (k) { return k in store ? store[k] : null; }, setItem: function (k, v) { store[k] = String(v); }, removeItem: function (k) { delete store[k]; } };
if (typeof btoa === 'undefined') { global.btoa = function (s) { return Buffer.from(s, 'binary').toString('base64'); }; }
var runs = JSON.parse(process.argv[3]), script = require('fs').readFileSync(process.argv[2], 'utf8'), out = [], realNow = Date.now;
runs.forEach(function (r) {
  var m = { textContent: 'checking' };
  global.location = { pathname: r.path, search: '', reload: function () {} };
  global.document = { cookie: '', getElementById: function (id) { return id === 'm' ? m : { style: {}, hidden: true, submit: function () {} }; } };
  Date.now = function () { return r.at; };
  global.RS = { c: { algorithm: 'SHA-256', challenge: 'x', maxnumber: 100000000, salt: 's', signature: 'y' }, failed: 'FAILED', cookie: 'c' };
  eval(script);
  out.push(m.textContent === 'FAILED' ? 'failed' : 'runs');
});
Date.now = realNow;
console.log(out.join(' ')); process.exit(0);
JS);
        $run = static function (array $visits) use ($node, $dir): string {
            return trim((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg("$dir/tries.js") . ' ' . escapeshellarg("$dir/page.js") . ' ' . escapeshellarg(json_encode($visits))));
        };
        try {
            same('runs runs runs failed', $run([['path' => '/a', 'at' => 1000], ['path' => '/a', 'at' => 2000], ['path' => '/a', 'at' => 3000], ['path' => '/a', 'at' => 4000]]), 'a loop: the fourth time within a minute, it stops');
            same('runs runs runs runs', $run([['path' => '/a', 'at' => 1000], ['path' => '/b', 'at' => 2000], ['path' => '/challenge', 'at' => 3000], ['path' => '/contact', 'at' => 4000]]), 'four checks on four pages: each runs');
            same('runs runs runs runs', $run([['path' => '/a', 'at' => 1000], ['path' => '/a', 'at' => 70000], ['path' => '/a', 'at' => 140000], ['path' => '/a', 'at' => 210000]]), 'the same page, minutes apart: each runs');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
