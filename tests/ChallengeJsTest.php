<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Challenge\ProofOfWork;

/**
 * The challenge page's own script, run in Node: it must solve what PHP
 * created, and PHP must accept its payload. Skipped where there is no node.
 */

return [
    'the page script solves a PHP challenge, and PHP accepts it' => function (): void {
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
    'the page script reports an unsolvable challenge instead of looping' => function (): void {
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
];
