<?php
/**
 * What a query full of tags costs against a plain one of the same length,
 * with all attack rules (AttacksRulesTest): the ratios as JSON. Run by the
 * test with and without PCRE's JIT (-d pcre.jit=0) -- a host may not allow
 * the JIT, and a pattern that scans to the end from every "<" is quadratic
 * there.
 *
 * Usage: php [-d pcre.jit=0] attack-cost.php <bootstrap.php or the single file>
 */

declare(strict_types=1);

use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\MemoryStore;

require $argv[1];
$dir = sys_get_temp_dir() . '/rshield-atkcost-' . getmypid();
@mkdir($dir, 0700, true);
file_put_contents("$dir/site.rules", "include @attacks\n");
$shield = new Shield(Settings::from(RuleFile::read(["$dir/site.rules"])['config']), new MemoryStore());
exec('rm -rf ' . escapeshellarg($dir));
$time = static function (string $q) use ($shield): float {
    $best = INF;
    for ($i = 0; $i < 5; $i++) {
        $t = hrtime(true);
        for ($j = 0; $j < 10; $j++) {
            $shield->decide(Request::fromServer(['REQUEST_URI' => '/?q=' . $q, 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'www.example.org', 'REMOTE_ADDR' => '198.51.100.7']), 1000.0);
        }
        $best = min($best, hrtime(true) - $t);
    }
    return (float) $best;
};
// Each ends in "&x=1": an expression that needs an "=" is not even tried on a query without one.
$plain = $time(substr(str_repeat('ax%20', 900), 0, 3790) . '%26x%3D1');
$out = ['jit' => (bool) ini_get('pcre.jit')];
foreach (['%3Ca%3Dx%20', '%3Ca%3D%22', '%3Ca%20x%3D%22y%22%20', '%3Ca%3D', '%3C', '%3Conerro', '%3Ca%20%3C1%20formactio'] as $unit) {
    $out[rawurldecode($unit)] = round($time(substr(str_repeat($unit, 900), 0, 3790) . '%26x%3D1') / $plain, 1);
}
echo json_encode($out), "\n";
