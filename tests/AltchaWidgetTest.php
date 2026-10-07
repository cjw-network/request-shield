<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Challenge\ProofOfWork;

/**
 * ALTCHA's own widget (v3) against the shield's task, run in Node: the
 * widget turns a v1 task into its own format (createChallengeFromV1), solves
 * it with its SHA worker and answers in v1 (createPayloadV1). The widget is
 * not part of this repository -- ALTCHA_WIDGET names an unpacked npm package
 * (`npm pack altcha && tar xzf altcha-*.tgz` gives package/); without it, or
 * without a Node that has WebCrypto, the test is skipped.
 */

/** Runs the widget's v1 path on $challenge; returns the payload, or the reason it could not run. */
function solveInAltchaWidget(string $package, array $challenge): array
{
    $file = sys_get_temp_dir() . '/rshield-altcha-' . getmypid() . '-' . mt_rand() . '.js';
    file_put_contents($file, <<<'JS'
var fs = require('fs'), path = require('path'), dir = process.argv[2], challenge = JSON.parse(process.argv[3]);
if (!globalThis.crypto || !globalThis.crypto.subtle) { try { globalThis.crypto = require('crypto').webcrypto; } catch (e) {} }
if (!globalThis.crypto || !globalThis.crypto.subtle) { console.log(JSON.stringify({ skip: 'Node ' + process.version + ' has no WebCrypto' })); process.exit(0); }
if (typeof performance === 'undefined') { globalThis.performance = require('perf_hooks').performance; }
if (typeof btoa === 'undefined') { globalThis.btoa = function (s) { return Buffer.from(s, 'binary').toString('base64'); }; }
// The widget's own functions, cut from its bundle as they are: a function up to the next one.
var main = fs.readFileSync(path.join(dir, 'dist/main/altcha.js'), 'utf8');
function cut(name) {
  var start = main.search(new RegExp('^[ \\t]*function ' + name + '\\(', 'm'));
  if (start < 0) { throw new Error('the widget has no ' + name + '()'); }
  var body = main.indexOf('\n', start) + 1, end = main.slice(body).search(/^[ \t]*(async )?function /m);
  return main.slice(start, body + end);
}
var version = JSON.parse(fs.readFileSync(path.join(dir, 'package.json'), 'utf8')).version;
eval(cut('bufferToHex') + cut('isChallengeV1') + cut('createChallengeFromV1') + cut('createPayloadV1'));
if (!isChallengeV1(challenge)) { throw new Error('the widget does not take the task for v1'); }
var task = createChallengeFromV1(challenge);
// The SHA worker, as a browser would run it: it sets self.onmessage and answers with postMessage.
globalThis.self = { postMessage: function (solution) {
  if (!solution || solution.error) { console.log(JSON.stringify({ payload: null, version: version, error: String(solution && solution.error) })); return; }
  console.log(JSON.stringify({ payload: btoa(JSON.stringify(createPayloadV1(task, solution))), version: version }));
} };
eval(fs.readFileSync(path.join(dir, 'dist/workers/sha.js'), 'utf8'));
self.onmessage({ data: { type: 'work', challenge: task, counterMode: 'string', counterStart: 0, counterStep: 1, timeout: 20000 } });
JS);
    $out = shell_exec(escapeshellarg((string) nodeBinary()) . ' ' . escapeshellarg($file) . ' '
        . escapeshellarg($package) . ' ' . escapeshellarg((string) json_encode($challenge)) . ' 2>&1');
    unlink($file);
    $r = json_decode(trim((string) $out), true);
    if (!is_array($r)) {
        throw new TestFailure('node: ' . trim((string) $out));
    }
    return $r;
}

return [
    'RSF03-03 ALTCHA\'s widget v3 solves the shield\'s task, and the shield accepts its answer' => function (): void {
        $package = (string) getenv('ALTCHA_WIDGET');
        if ($package === '' || !is_file($package . '/dist/main/altcha.js')) {
            skip('ALTCHA_WIDGET does not name an unpacked altcha npm package');
        }
        if (nodeBinary() === null) {
            skip('no node on this machine');
        }
        $pow = new ProofOfWork('js-secret-0123456789abcdef0123456789abcdef');
        $c = $pow->create('203.0.113.7', 5000, 5000, 'login');
        $r = solveInAltchaWidget($package, $c);
        if (isset($r['skip'])) {
            skip((string) $r['skip']);
        }
        truthy(is_string($r['payload'] ?? null), 'widget ' . ($r['version'] ?? '?') . ': solved (' . ($r['error'] ?? '') . ')');
        $payload = (string) $r['payload'];
        truthy($pow->verify($payload, '203.0.113.7', 1000.0), 'the shield accepts the widget\'s answer (standard base64, with "took")');
        truthy(!$pow->verify($payload, '203.0.113.8', 1000.0), 'not for another client');
        truthy(!$pow->verify($payload, '203.0.113.7', 5001.0), 'not after it expired');
        same('login', ProofOfWork::budgetOf($payload), 'the budget the task was made for stays in the salt');
        same($c['challenge'], ProofOfWork::challengeOf($payload), 'the challenge, to count the answer once');
    },
];
