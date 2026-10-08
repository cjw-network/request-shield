<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Decision;
use CjwNetwork\RequestShield\Waf\LivePage;
use CjwNetwork\RequestShield\Request;
use CjwNetwork\RequestShield\Rules\FeedExport;
use CjwNetwork\RequestShield\Rules\Feeds;
use CjwNetwork\RequestShield\Rules\RuleFile;
use CjwNetwork\RequestShield\Rules\RuleFileException;
use CjwNetwork\RequestShield\Settings;
use CjwNetwork\RequestShield\Shield;
use CjwNetwork\RequestShield\Store\MemoryStore;

/** Public blocklists as feeds (proposal 0025). No network: the fetch is handed in, the fetched lists written as files. */

function feedsDir(): string
{
    $dir = sys_get_temp_dir() . '/rs-feeds-' . getmypid() . '-' . mt_rand();
    mkdir("$dir/store/feeds", 0750, true);
    return $dir;
}

/** A list as "feeds update" leaves it: the ranges, and when it was fetched. */
function feedFile(string $dir, string $name, array $ranges, ?int $checked = null): void
{
    file_put_contents("$dir/store/feeds/$name.txt", implode("\n", $ranges) . "\n");
    file_put_contents("$dir/store/feeds/$name.json", json_encode(['checked' => $checked ?? time(), 'count' => count($ranges)]));
}

function feedsSettings(string $dir, string $rules): Settings
{
    file_put_contents("$dir/site.rules", "set store-dir $dir/store\nrestrict /rs/** to 127.0.0.1 ::1\n" . $rules);
    return Settings::from(RuleFile::read(["$dir/site.rules"])['config']);
}

function feedReq(string $ip, string $path = '/', string $ua = 'Mozilla/5.0 Firefox/136.0'): Request
{
    return Request::fromServer(['REQUEST_URI' => $path, 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'www.example.org', 'REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $ua]);
}

return [
    'RSF01-03 feed <name> from <file>: a list of the site\'s own beside the rules -- read when they compile, watched, never fetched, never too old (0031 F.6)' => function (): void {
        $dir = ruleDir(['site.rules' => "[S-OWN] feed own-list from lists/own.txt deny\n", 'lists/own.txt' => "# ours\n203.0.113.0/28\n198.51.100.9   ; a single one\nnot an address\n"]);
        try {
            $read = RuleFile::read(["$dir/site.rules"]);
            same("$dir/lists/own.txt", $read['config']['feeds'][0]['file'] ?? null, 'the file, relative to the rule file');
            truthy(isset($read['seen']["$dir/lists/own.txt"]), 'watched like a rule file: a change compiles the rules again');
            $s = Settings::from($read['config']);
            same(['in force', 2, "$dir/lists/own.txt"], [$s->feeds[0]['state'], $s->feeds[0]['count'], $s->feeds[0]['file']], 'in force at once, the comment and the line that is no address left out');
            $shield = new Shield($s, new MemoryStore());
            $d = $shield->decide(Request::fromServer(['REQUEST_URI' => '/', 'REMOTE_ADDR' => '203.0.113.9']), 1000.0);
            same([403, 'S-OWN'], [$d->status, $shield->explain($d, Request::fromServer(['REQUEST_URI' => '/', 'REMOTE_ADDR' => '203.0.113.9']))], 'an address in the range: kept out by the rule');
            truthy($shield->decide(Request::fromServer(['REQUEST_URI' => '/', 'REMOTE_ADDR' => '203.0.113.99']), 1000.0)->passes(), 'outside it: through');
            $report = Feeds::update($s->feeds, "$dir/store-feeds", static function (): ?array {
                throw new TestFailure('a file is never fetched');
            });
            truthy(strpos($report['own-list'] ?? '', 'nothing to fetch') !== false, 'feeds update leaves it alone: ' . json_encode($report));
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        // Watched (count, monitor): the file is watched all the same.
        $dir = ruleDir(['site.rules' => "[S-TRY] feed own-list from lists/own.txt count\n", 'lists/own.txt' => "203.0.113.0/28\n"]);
        try {
            $read = RuleFile::read(["$dir/site.rules"]);
            truthy(isset($read['seen']["$dir/lists/own.txt"]), 'a counted (watched) list\'s file is watched too');
            same('in force', Settings::from($read['config'])->monitor->feeds[0]['state'] ?? null, 'and counted');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        foreach (["feed own-list from nothing.txt deny\n" => 'no file', "feed own-list from\n" => 'the file is missing'] as $text => $why) {
            try {
                rulesFrom($text);
                throw new TestFailure("accepted: $text");
            } catch (RuleFileException $e) {
                truthy(strpos($e->getMessage(), $why) !== false && strpos($e->getMessage(), 'site.rules:1') !== false, $e->getMessage());
            }
        }
    },
    'RSF01-03 the formats: plain (comments), DShield\'s columns, JSON lines, a JSON document\'s fields anywhere' => function (): void {
        same(['45.1.2.3', '45.2.0.0/16', '2a01:4f8::1'], Feeds::parse("# a header\n45.1.2.3   # a scanner\n; another comment\n\n45.2.0.0/16 extra words\n2a01:4f8::1\n", 'plain'));
        same(['185.12.59.0/24', '45.3.4.0/24'], Feeds::parse("#\n#   DShield\n185.12.59.0\t185.12.59.255\t24\t323\tBLIX\n45.3.4.0\t45.3.4.255\t24\nbroken line\n", 'dshield'));
        same(['1.10.16.0/20', '2a0e:8f00::/29'], Feeds::parse("{\"cidr\":\"1.10.16.0/20\",\"sblid\":\"SBL1\"}\n{\"cidr\":\"2a0e:8f00::/29\"}\n{\"type\":\"metadata\",\"records\":2}\n", 'jsonl:cidr'));
        same(['3.4.0.0/16', '3.5.0.0/16', '2600:1f00::/24'], Feeds::parse(json_encode(['prefixes' => [['ip_prefix' => '3.4.0.0/16', 'region' => 'x'], ['other' => ['ip_prefix' => '3.5.0.0/16']]],
            'ipv6_prefixes' => [['ipv6_prefix' => '2600:1f00::/24']]]) ?: '', 'json:ip_prefix,ipv6_prefix'));
        same([true, true, true, true, false, false], [Feeds::isFormat('plain'), Feeds::isFormat('dshield'), Feeds::isFormat('jsonl:cidr'), Feeds::isFormat('json:a,b'), Feeds::isFormat('xml'), Feeds::isFormat('json:')]);
    },
    'RSF01-03 what is kept: valid, normalised, once each -- never the site\'s own network, wide ranges only for lists meant to hold them' => function (): void {
        $c = Feeds::clean(['45.1.2.3', '45.1.2.3/32', '45.1.2.77/24', 'nonsense', '10.1.2.3', '0.0.0.0/8', '224.0.0.0/3', '172.16.5.0/24', '192.168.1.1', '100.64.0.1',
            'fe80::1', 'fd00::/8', '::1', '45.0.0.0/8', '2a01::/16', '2a01:4f8::/32', '127.0.0.0/8', '8.0.0.0/7'], false);
        same(['2a01:4f8::/32', '45.1.2.0/24', '45.1.2.3'], $c['ranges'], 'normalised, sorted, once each');
        same([1, 6, 7], [$c['invalid'], $c['special'], $c['wide']], 'what was dropped, counted (a wide range as wide, before its network is looked at)');
        same(['2a01::/16', '45.0.0.0/8'], Feeds::clean(['45.0.0.0/8', '2a01::/16', '10.0.0.0/8'], true)['ranges'], 'wide-ok: whole networks kept, the own network still not');
    },
    'RSF01-03 fetching: due or not, the validators (304: unchanged), a broken or much shorter list kept, https only, several addresses with a part each' => function (): void {
        $dir = feedsDir();
        try {
            $f = static fn (array $over = []): array => [$over + ['name' => 'test', 'urls' => ['https://lists.example/a.txt'], 'format' => 'plain', 'every' => 3600, 'wideOk' => false]];
            $calls = [];
            $answer = ['status' => 200, 'body' => "45.1.2.3\n45.1.2.4\n45.1.2.5\n45.1.2.6\n10.0.0.1\n", 'headers' => ['etag' => '"v1"', 'last-modified' => 'Wed, 30 Sep 2026 10:00:00 GMT']];
            $fetch = static function (string $url, array $headers) use (&$calls, &$answer): ?array {
                $calls[] = [$url, $headers];
                return $answer;
            };
            $d = "$dir/store/feeds";
            same(['test' => 'updated: 4 entries'], Feeds::update($f(), $d, $fetch, false, 100000));
            same("45.1.2.3\n45.1.2.4\n45.1.2.5\n45.1.2.6\n", file_get_contents("$d/test.txt"), 'the list, the own network taken out');
            same('0600', substr(sprintf('%o', (int) fileperms("$d/test.txt")), -4));
            truthy(strpos(Feeds::update($f(), $d, $fetch, false, 101000)['test'], 'not due') === 0 && count($calls) === 1, 'not again within its time');
            $answer = ['status' => 304, 'body' => '', 'headers' => []];
            same(['test' => 'unchanged: 4 entries'], Feeds::update($f(), $d, $fetch, false, 104000), 'due: asked with the validators, 304');
            same(['If-None-Match' => '"v1"', 'If-Modified-Since' => 'Wed, 30 Sep 2026 10:00:00 GMT'], $calls[1][1]);
            same(104000, Feeds::meta($d, 'test')['checked'], 'checked now -- not too old');
            $answer = ['status' => 200, 'body' => "45.1.2.3\n", 'headers' => []];
            truthy(strpos(Feeds::update($f(), $d, $fetch, true, 108000)['test'], 'refused') === false, 'forced: taken');
            $answer = ['status' => 200, 'body' => "45.9.9.9\n45.9.9.8\n45.9.9.7\n45.9.9.6\n45.9.9.5\n45.9.9.4\n", 'headers' => []];
            Feeds::update($f(), $d, $fetch, true, 112000);
            $answer = ['status' => 200, 'body' => "45.9.9.9\n", 'headers' => []];
            same(['test' => 'refused: 1 entries instead of 6 (kept the old list; --force to take it)'], Feeds::update($f(), $d, $fetch, false, 120000), 'much shorter: a broken download');
            $answer = ['status' => 500, 'body' => 'oops', 'headers' => []];
            truthy(strpos(Feeds::update($f(), $d, $fetch, false, 130000)['test'], 'failed: https://lists.example/a.txt answered 500') === 0, 'an error: kept');
            same(6, substr_count((string) file_get_contents("$d/test.txt"), "\n"), 'the old list still there');
            truthy(strpos(Feeds::update($f(['name' => 'plainhttp', 'urls' => ['http://lists.example/a.txt']]), $d, $fetch)['plainhttp'], 'is not https') !== false, 'https only');
            // Two addresses (IPv4, IPv6): a 304 for one keeps its part.
            $two = $f(['name' => 'two', 'urls' => ['https://l.example/v4', 'https://l.example/v6'], 'format' => 'jsonl:cidr', 'wideOk' => true]);
            $answer = null;
            $parts = ['https://l.example/v4' => ['status' => 200, 'body' => "{\"cidr\":\"45.10.0.0/16\"}\n", 'headers' => ['etag' => 'a']],
                'https://l.example/v6' => ['status' => 200, 'body' => "{\"cidr\":\"2a0e:8f00::/29\"}\n", 'headers' => ['etag' => 'b']]];
            $byUrl = static function (string $url, array $h) use (&$parts): ?array {
                return $parts[$url];
            };
            Feeds::update($two, $d, $byUrl, false, 100000);
            $parts['https://l.example/v6'] = ['status' => 304, 'body' => '', 'headers' => []];
            $parts['https://l.example/v4'] = ['status' => 200, 'body' => "{\"cidr\":\"45.11.0.0/16\"}\n", 'headers' => []];
            Feeds::update($two, $d, $byUrl, false, 200000);
            same("45.11.0.0/16\n2a0e:8f00::/29\n", file_get_contents("$d/two.txt"), 'the new IPv4 part, the kept IPv6 part');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF01-03 the rule: names from the catalog or a list of one\'s own (https), the actions, count = watched, at <paths> -- mistakes named' => function (): void {
        $dir = feedsDir();
        try {
            $s = feedsSettings($dir, "[F-DROP] feed spamhaus-drop deny\n[F-OWN] feed own-list https://lists.example/bad.txt check at /login/** format dshield\n"
                . "[F-F2B] feed blocklist-de ban-signal 5\n[F-AWS] feed aws count\n");
            same(['spamhaus-drop', 'own-list', 'blocklist-de'], array_column($s->feeds, 'name'), 'the enforced lists');
            same(['deny', 'check', 'signal'], array_column($s->feeds, 'action'));
            same([['https://www.spamhaus.org/drop/drop_v4.json', 'https://www.spamhaus.org/drop/drop_v6.json'], ['https://lists.example/bad.txt']], array_column(array_slice($s->feeds, 0, 2), 'urls'));
            same(['jsonl:cidr', 'dshield', 'plain'], array_column($s->feeds, 'format'));
            same(['F-F2B' => 5], $s->feedWeights);
            same(1, count($s->feeds[1]['paths']), 'at <paths>');
            same(['aws'], array_column($s->monitor !== null ? $s->monitor->feeds : [], 'name'), 'count: only in the watched rules\' settings');
            same(['not fetched', 'not fetched', 'not fetched'], array_column($s->feeds, 'state'));
            $cases = [
                "feed spamhous-drop deny\n" => 'no feed "spamhous-drop" in the catalog',
                "feed mine http://x.example/l.txt deny\n" => 'is https://',
                "feed spamhaus-drop https://x.example/l.txt deny\n" => 'a list of your own takes a name of its own',
                "feed spamhaus-drop block\n" => 'feed <name>',
                "feed blocklist-de ban-signal 0\n" => 'ban-signal <n>',
                "feed mine https://x.example/l.txt deny format xml\n" => 'format plain, dshield',
                "site a.de {\n  feed spamhaus-drop deny\n}\n" => 'feed is about the server',
                "match /x/** {\n  feed spamhaus-drop deny\n}\n" => 'does not go inside a match block',
                "set feeds-max-age 10m\n" => 'feedsMaxAge',
            ];
            foreach ($cases as $text => $says) {
                try {
                    file_put_contents("$dir/site.rules", "set store-dir $dir/store\n" . $text);
                    Settings::from(RuleFile::read(["$dir/site.rules"], strpos($text, 'site ') === 0 ? 'a.de' : null)['config']);
                    throw new TestFailure('accepted: ' . json_encode($text));
                } catch (RuleFileException | InvalidArgumentException $e) {
                    truthy(strpos($e->getMessage(), $says) !== false, $e->getMessage());
                }
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF01-03 deciding: deny 403 right after the deny list, check = the browser check, at <paths>, ban-signal weighs -- never exempt, a trusted proxy or a verified crawler' => function (): void {
        $dir = feedsDir();
        try {
            feedFile($dir, 'spamhaus-drop', ['45.10.0.0/16']);
            feedFile($dir, 'blocklist-de', ['45.20.1.1', '66.249.66.1']);
            feedFile($dir, 'tor-exits', ['45.30.1.1']);
            feedFile($dir, 'et-compromised', ['45.40.1.1']);
            $s = feedsSettings($dir, "exempt 45.10.9.9\ntrust 45.10.8.8\n[F-DROP] feed spamhaus-drop deny\n[F-F2B] feed blocklist-de check\n[F-TOR] feed tor-exits check at /login/**\n"
                . "[F-ET] feed et-compromised ban-signal 3\n[B-SCAN] ban after 3 refusals in 5m for 10m\n");
            $store = new MemoryStore();
            $shield = new Shield($s, $store);
            $d = $shield->decide($r = feedReq('45.10.1.2', '/anything'), 1000.0);
            same(['reject', 403, 'feed', 'F-DROP'], [$d->action, $d->status, $d->reason, $shield->explain($d, $r)], 'on a deny list: 403');
            $d = $shield->decide(feedReq('45.10.1.2', '/.env'), 1000.0);
            same('feed', $d->reason, 'before the blocked paths');
            same(['allow', 'allow'], [$shield->decide(feedReq('45.10.9.9'), 1000.0)->action, $shield->decide(feedReq('45.10.8.8'), 1000.0)->action], 'never exempt, never a trusted proxy');
            $d = $shield->decide($r = feedReq('45.20.1.1'), 1000.0);
            same(['challenge', 'feed', 'F-F2B'], [$d->action, $d->reason, $shield->explain($d, $r)], 'on a check list: the browser check');
            $google = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
            same('allow', $shield->decide(feedReq('66.249.66.1', '/', $google), 1000.0)->action, 'a crawler that proved who it is: never by a list');
            same(['allow', 'challenge'], [$shield->decide(feedReq('45.30.1.1', '/'), 1000.0)->action, $shield->decide(feedReq('45.30.1.1', '/login/x'), 1000.0)->action], 'at <paths>: only there');
            // ban-signal 3: one refusal counts three times -- banned at once.
            $signals = new ReflectionMethod(Shield::class, 'signals');
            $signals->setAccessible(true);
            $r = feedReq('45.40.1.1', '/.env');
            $signals->invoke($shield, $shield->decide($r, 1000.0), $r, 1000.0);
            truthy($store->marked('ban:45.40.1.1', 1001.0) > 0, 'banned after its first refusal');
            $r = feedReq('45.41.1.1', '/.env');
            $signals->invoke($shield, $shield->decide($r, 1000.0), $r, 1000.0);
            same(0, $store->marked('ban:45.41.1.1', 1001.0), 'not on the list: one signal of three');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF01-03 a list too old is not used -- and the settings are built again when it grows too old; "count" refuses nobody' => function (): void {
        $dir = feedsDir();
        try {
            $now = time();
            feedFile($dir, 'spamhaus-drop', ['45.10.0.0/16'], $now - 4 * 86400);
            feedFile($dir, 'dshield-top20', ['45.50.0.0/24'], $now - 3600);
            feedFile($dir, 'aws', ['45.60.0.0/16'], $now);
            $s = feedsSettings($dir, "[F-DROP] feed spamhaus-drop deny\n[F-DS] feed dshield-top20 deny\n[F-AWS] feed aws count\nset feeds-max-age 3d\n");
            same(['too old', 'in force'], array_column($s->feeds, 'state'));
            same($now - 3600 + 3 * 86400 + 1, $s->listsUntil, 'built again the moment the in-force list grows too old');
            $shield = new Shield($s, new MemoryStore());
            same(['allow', 'reject', 'allow'], [$shield->decide(feedReq('45.10.1.1'), 1000.0)->action, $shield->decide(feedReq('45.50.0.9'), 1000.0)->action,
                $shield->decide(feedReq('45.60.1.1'), 1000.0)->action], 'too old: not used; count: refuses nobody');
            $watch = new Shield($s->monitor ?? $s, new MemoryStore());
            same(['reject', 'feed'], [($d = $watch->decide(feedReq('45.60.1.1'), 1000.0))->action, $d->reason], 'count: what the watched rules would do (logged as monitor-reject)');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF01-03 the export: the deny list and the deny feeds as the fewest blocks -- not what touches a trusted proxy or an address let in, not "at" lists; four formats' => function (): void {
        $dir = feedsDir();
        try {
            feedFile($dir, 'spamhaus-drop', ['45.10.0.0/24', '45.10.1.0/24', '45.70.0.0/16', '2a0e:8f00::/29']);
            feedFile($dir, 'tor-exits', ['45.80.1.1']);
            feedFile($dir, 'blocklist-de', ['45.90.1.1']);
            $s = feedsSettings($dir, "trust 45.70.1.1\n[F-DROP] feed spamhaus-drop deny\n[F-TOR] feed tor-exits deny at /login/**\n[F-F2B] feed blocklist-de check\n[L-1] deny 45.10.2.0/23\n");
            $x = FeedExport::cidrs($s);
            same(['45.10.0.0/22', '2a0e:8f00::/29'], $x['cidrs'], 'merged with the deny list into one block; not the at-list, not the check list');
            same(['45.70.0.0/16'], $x['left'], 'it holds a trusted proxy: left out');
            same("45.10.0.0/22\n2a0e:8f00::/29\n", FeedExport::render($x['cidrs'], 'plain'));
            truthy(strpos(FeedExport::render($x['cidrs'], 'nginx'), "deny 45.10.0.0/22;\ndeny 2a0e:8f00::/29;\n") !== false, 'nginx');
            $nft = FeedExport::render($x['cidrs'], 'nftables');
            truthy(strpos($nft, "set deny4 {\n        type ipv4_addr\n        flags interval") !== false && strpos($nft, '45.10.0.0/22') !== false && strpos($nft, 'ip6 saddr @deny6 drop') !== false, 'nftables');
            $ipset = FeedExport::render($x['cidrs'], 'ipset');
            truthy(strpos($ipset, "create request-shield-4 hash:net family inet -exist\nflush request-shield-4\nadd request-shield-4 45.10.0.0/22\n") !== false
                && strpos($ipset, 'add request-shield-6 2a0e:8f00::/29') !== false, 'ipset');
            try {
                FeedExport::render([], 'htaccess');
                throw new TestFailure('htaccess accepted');
            } catch (InvalidArgumentException $e) {
                truthy(strpos($e->getMessage(), 'not "htaccess"') !== false, 'not .htaccess: measured far too slow for lists');
            }
            $bin = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli());
            exec("$bin feeds " . escapeshellarg("$dir/site.rules") . ' export --format=nginx --write=' . escapeshellarg("$dir/deny.conf") . ' 2>&1', $out, $code);
            truthy($code === 0 && strpos((string) file_get_contents("$dir/deny.conf"), 'deny 45.10.0.0/22;') !== false, 'the command line writes it: ' . implode(' ', $out));
            exec("$bin feeds " . escapeshellarg("$dir/site.rules") . ' 2>&1', $list, $code);
            truthy($code === 0 && strpos(implode("\n", $list), 'spamhaus-drop   deny') !== false && strpos(implode("\n", $list), 'terms: https://www.spamhaus.org/drop/terms/') !== false, implode("\n", $list));
            exec("$bin check " . escapeshellarg("$dir/site.rules") . ' 2>&1', $chk, $code);
            same(0, $code, 'all fetched: no warning ' . implode(' ', $chk));
            unlink("$dir/store/feeds/tor-exits.txt");
            exec("$bin check " . escapeshellarg("$dir/site.rules") . ' 2>&1', $chk2, $code);
            truthy($code === 3 && strpos(implode(' ', $chk2), 'the feed tor-exits is not fetched yet') !== false, 'check warns: ' . implode(' ', $chk2));
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF01-03 ranges back to CIDR blocks: the fewest, exactly covering -- neighbours joined, IPv6, everything' => function (): void {
        $t = \CjwNetwork\RequestShield\IpTable::class;
        same(['10.0.0.1/32', '10.0.0.2/31', '10.0.0.4/31', '10.0.0.6/32'], $t::blocks((string) inet_pton('10.0.0.1'), (string) inet_pton('10.0.0.6')));
        same(['10.0.0.0/23', '10.0.2.5/32', '2001:db8::/47'], $t::cidrs($t::build([[['10.0.0.0/24', '10.0.1.0/24', '10.0.2.5'], 'A'], [['2001:db8::/48', '2001:db8:1::/48'], 'B']])));
        same(['0.0.0.0/0', '::/0'], $t::cidrs($t::build([[['0.0.0.0/0', '::/0'], 'Z']])), 'everything, without running past the end');
        same(['0a000000', '0affffff'], $t::bounds('10.1.2.3/8'));
        same([null, null], [$t::bounds('10.0.0.0/33'), $t::bounds('nonsense')]);
    },
    'RSF01-03 the live view and the rules page name the list' => function (): void {
        needsPlugins();         // the shipped plugins' pages (the WAF's, the statistics'): not in the core single file
        $dir = feedsDir();
        try {
            feedFile($dir, 'spamhaus-drop', ['45.10.0.0/16']);
            $s = feedsSettings($dir, "set log $dir/shield.log\n[F-DROP] feed spamhaus-drop deny\n");
            $shield = new Shield($s, new MemoryStore());
            $r = feedReq('45.10.1.2');
            $d = $shield->decide($r, 1000.0);
            \CjwNetwork\RequestShield\Log::write($s, $r, $d, $shield->explain($d, $r), 1000.0);
            $row = \CjwNetwork\RequestShield\Api\LiveRows::json($s, null)['rows'][0];
            same(['feed', 'on the public list Spamhaus DROP (Don\'t Route Or Peer)', 'F-DROP'], [$row['source'], $row['why'], $row['rule']]);
            $groups = \CjwNetwork\RequestShield\Waf\RulesPage::groups($s, [], null, 'en');
            $feeds = array_values(array_filter($groups, static fn (array $g): bool => $g[0] === 'Public blocklists'));
            truthy($feeds !== [] && strpos(json_encode($feeds[0]) ?: '', '1 entries, fetched') !== false, 'the rules page: the list, how many, when fetched');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
