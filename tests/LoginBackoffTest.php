<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\MemoryStore;

/**
 * The use case "the sign-in, hardened" (docs/use-cases/login-backoff.md): its
 * rules, as written there, do what the page says -- three wrong passwords go
 * through, the fourth gets a pause and a ban of a minute, every ban after it
 * twice as long; a right password counts nothing; the office never.
 */

/**
 * One address trying wrong passwords; it waits out each refusal when $waits.
 *
 * @return list<string> what each try got: "tried", "budget", "banned <seconds>"
 */
function loginTries(Settings $s, int $tries, bool $waits, string $ip = '203.0.113.9', bool $right = false): array
{
    $shield = new Shield($s, new MemoryStore());
    $t = 1790800000.0;
    $out = [];
    for ($i = 0; $i < $tries; $i++) {
        $r = Request::fromServer(['REQUEST_URI' => '/login', 'REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'www.example.org', 'REMOTE_ADDR' => $ip, 'HTTP_ORIGIN' => 'https://www.example.org', 'HTTPS' => 'on']);
        $d = $shield->settle($shield->decide($r, $t), $r, $t)['decision'];
        if ($d->passes()) {
            $c = $right ? $d : $shield->consume('logins', $r, $t);
            $out[] = $c->passes() ? 'tried' : 'budget';
        } else {
            $out[] = 'banned ' . $d->retryAfter;
        }
        $t += $waits && !$d->passes() ? $d->retryAfter + 1 : 10;
    }
    return $out;
}

return [
    'RSF01-02 the sign-in use case: three tries, the fourth a pause and a minute\'s ban, every ban after it twice as long; a right password and the office count nothing' => function (): void {
        $page = (string) file_get_contents(dirname(__DIR__) . '/docs/use-cases/login-backoff.md');
        // The rules of step 1 (indented under it): the block that holds the ban.
        preg_match('/^   ```text\n((?:   .*\n)*?)   ```/m', $page, $m);
        truthy(isset($m[1]) && strpos($m[1], '[LOGIN-BAN]') !== false, 'the rules on the page');
        $m[1] = (string) preg_replace('/^   /m', '', $m[1]);
        $dir = sys_get_temp_dir() . '/rs-login-' . getmypid() . '-' . mt_rand();
        mkdir($dir);
        try {
            file_put_contents("$dir/site.rules", "set store memory\n" . $m[1]);
            $s = Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
            same(['tried', 'tried', 'tried', 'budget', 'banned 50'], loginTries($s, 5, false), 'every 10 seconds');
            same(['tried', 'tried', 'tried', 'budget', 'banned 50', 'budget', 'banned 110', 'budget', 'banned 230'], loginTries($s, 9, true),
                'waiting out each ban: 1, 2, 4 minutes (each first refusal comes 10 s into it)');
            same(['tried', 'tried', 'tried', 'tried', 'tried'], loginTries($s, 5, false, '203.0.113.9', true), 'a right password counts nothing');
            same(['tried', 'tried', 'tried', 'tried', 'tried'], loginTries($s, 5, false, '192.0.2.10'), 'the office: never counted, never banned');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
