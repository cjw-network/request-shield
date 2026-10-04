<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\MemoryStore;

/**
 * The use case "an accessible website, hardened"
 * (docs/use-cases/accessible-hardening.md): its rules, as written there, do
 * what the page says -- a person's form goes through, a form from another
 * website is refused, the sixth in an hour from one address waits.
 */

return [
    'RSF03-03 the accessible use case: five forms an hour from one address go through, the sixth waits; a form from another website is refused' => function (): void {
        $page = (string) file_get_contents(dirname(__DIR__) . '/docs/use-cases/accessible-hardening.md');
        preg_match('/^   ```text\n((?:   .*\n)*?)   ```/m', $page, $m);
        truthy(isset($m[1]) && strpos($m[1], '[FORM-LIMIT]') !== false, 'the rules on the page');
        $rules = (string) preg_replace('/^   /m', '', $m[1] ?? '');
        $dir = sys_get_temp_dir() . '/rs-a11y-' . getmypid() . '-' . mt_rand();
        mkdir($dir);
        try {
            file_put_contents("$dir/site.rules", "set store memory\n" . $rules);
            $s = Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
            same(10800, $s->challenge->passTtl, 'a pass for three hours');
            $shield = new Shield($s, new MemoryStore());
            $t = 1790800000.0;
            $send = static function (string $origin) use ($shield, &$t): string {
                $r = Request::fromServer(['REQUEST_URI' => '/contact/send', 'REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'www.example.org',
                    'REMOTE_ADDR' => '203.0.113.9', 'HTTP_ORIGIN' => $origin, 'HTTPS' => 'on']);
                $t += 60;
                $d = $shield->settle($shield->decide($r, $t), $r, $t)['decision'];
                return $d->passes() ? 'sent' : $d->status . ' ' . $d->reason;
            };
            $out = [];
            for ($i = 0; $i < 6; $i++) {
                $out[] = $send('https://www.example.org');
            }
            same(['sent', 'sent', 'sent', 'sent', 'sent', '429 contact'], $out, 'five an hour, then a pause');
            same('403 cross-site', $send('https://evil.example'), 'a form from another website');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
