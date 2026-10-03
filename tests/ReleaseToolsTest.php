<?php

declare(strict_types=1);

use CjwNetwork\RequestShield\Release\Minisign;
use CjwNetwork\RequestShield\Release\SelfUpdate;
use CjwNetwork\RequestShield\Release\Verify;
use CjwNetwork\RequestShield\Rules\Shipped;
use CjwNetwork\RequestShield\Rules\Starter;

/**
 * verify, self-update and init (0031 E.6). The signatures are minisign's
 * format, made here with a key of the test's own (sodium); the release key
 * itself comes with step H.2a.
 */

/** @return array{pub: string, sk: string, id: string} a minisign key pair of the test's own */
function msKey(): array
{
    if (!Minisign::available()) {
        skip('PHP has no sodium here');
    }
    $kp = sodium_crypto_sign_keypair();
    $id = random_bytes(8);
    return ['pub' => base64_encode('Ed' . $id . sodium_crypto_sign_publickey($kp)), 'sk' => sodium_crypto_sign_secretkey($kp), 'id' => $id];
}

/** A minisign signature file for $data, as `minisign -S -t <comment>` writes it ("ED": prehashed; "Ed": the legacy form). */
function msSign(string $data, array $key, string $comment, string $alg = 'ED'): string
{
    $sig = sodium_crypto_sign_detached($alg === 'ED' ? sodium_crypto_generichash($data, '', 64) : $data, $key['sk']);
    return "untrusted comment: signature from the test's key\n" . base64_encode($alg . $key['id'] . $sig) . "\ntrusted comment: $comment\n"
        . base64_encode(sodium_crypto_sign_detached($sig . $comment, $key['sk'])) . "\n";
}

/** @return array{0: int, 1: string, 2: string} exit code, stdout, stderr of a command class run with memory streams */
function runStreams(callable $run): array
{
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    $code = $run($out, $err);
    rewind($out);
    rewind($err);
    return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
}

function releaseDir(): string
{
    $dir = sys_get_temp_dir() . '/rs-rel-' . getmypid() . '-' . mt_rand();
    mkdir($dir, 0700, true);
    return $dir;
}

/**
 * A release on a pretend server: the files of version $v (valid PHP), each
 * with its signature naming the version and the checksum.
 *
 * @return array<string, string> URL => body
 */
function fakeRelease(array $key, string $v, string $tagPath = 'latest/download'): array
{
    $out = [];
    foreach (['request-shield.php', 'request-shield-stats.php'] as $name) {
        $data = "<?php\n// $name $v\n";
        $out[SelfUpdate::RELEASES . "/$tagPath/$name"] = $data;
        $out[SelfUpdate::RELEASES . "/$tagPath/$name.minisig"] = msSign($data, $key, "request-shield v$v sha256:" . hash('sha256', $data));
    }
    return $out;
}

/**
 * Made with minisign 0.12 itself (a throwaway key; only its public half is
 * here): the PHP check must accept what the real tool signs.
 */
const MINISIGN_PUB = 'RWQz/E//c/IHeTLEnBo3THLyQwRNnDQT/nRNZwYWvu1Bm4Y12eB1tDgD';
const MINISIGN_FILE = '<?php // file
';
const MINISIGN_SIG = 'untrusted comment: signature from minisign secret key
RUQz/E//c/IHeaAOkVb/BcacXbVh/TktcaXYATUVj9LKkfqY9/qmEt/LXDH5FHCAbaJLy/EzgQqI5M357tRS9pY8NtZAatKGXgY=
trusted comment: request-shield v1.0.0 sha256:770721e079286028e72a2b21643bef3994def52edffc6b26b10eeeaa9342e3f4
H11VSUlySIUCn6i94vElYV4xxMwAthtJkM4BrEyoYvZLsYHUDTWFzf1cYS7lX/bx0up3VicebId970rS6RlnCQ==
';
const MINISIGN_LEGACY = 'untrusted comment: signature from minisign secret key
RWQz/E//c/IHeQsjNmCdW0Ly6wtZol6+wQN/JhRws+2DAr+XnOkoPAjhalEtiyA09MFBpCg5vX0BL1GD22gxBcH6XqEwrGX/vAM=
trusted comment: timestamp:1791065650	file:f.php
TfD/PoaO2r/SSDaMTXu6zmLkII3GB07szkXESeujwS/5wYQT9YhcOMxBixLwXPAJIlXgJ3pWXVr0K4toblc8DA==
';

return [
    'RSF05-07 Minisign: signatures made by minisign 0.12 itself are accepted -- prehashed and legacy; the file changed by one byte is not' => function (): void {
        if (!Minisign::available()) {
            skip('PHP has no sodium here');
        }
        same('request-shield v1.0.0 sha256:' . hash('sha256', MINISIGN_FILE), Minisign::verify(MINISIGN_FILE, MINISIGN_SIG, MINISIGN_PUB));
        truthy(strpos(Minisign::verify(MINISIGN_FILE, MINISIGN_LEGACY, MINISIGN_PUB), 'file:f.php') !== false, 'the legacy form (minisign -l)');
        try {
            Minisign::verify(MINISIGN_FILE . ' ', MINISIGN_SIG, MINISIGN_PUB);
            throw new TestFailure('accepted a changed file');
        } catch (RuntimeException $e) {
            truthy(!($e instanceof TestFailure), $e->getMessage());
        }
    },
    'RSF05-07 Minisign: a signature by the key is checked -- prehashed and legacy; another file, another key, a changed comment, a broken file are refused' => function (): void {
        $key = msKey();
        $data = "<?php // the file\n";
        same('request-shield v1.0.0 sha256:x', Minisign::verify($data, msSign($data, $key, 'request-shield v1.0.0 sha256:x'), $key['pub']));
        same('legacy', Minisign::verify($data, msSign($data, $key, 'legacy', 'Ed'), $key['pub']), 'the legacy, not prehashed form');
        same('request-shield v1.0.0 sha256:x', Minisign::trustedComment(msSign($data, $key, 'request-shield v1.0.0 sha256:x'), $key['pub']), 'the comment alone');
        $sig = msSign($data, $key, 'c');
        $refused = static function (callable $f, string $why): void {
            try {
                $f();
                throw new TestFailure("accepted: $why");
            } catch (RuntimeException $e) {
                truthy(!($e instanceof TestFailure) && strpos($e->getMessage(), $why) !== false, $e->getMessage());
            }
        };
        $refused(static fn () => Minisign::verify($data . ' ', $sig, $key['pub']), 'does not match the file');
        $refused(static fn () => Minisign::verify($data, $sig, msKey()['pub']), 'signed with another key');
        $lines = explode("\n", $sig);
        $lines[2] = 'trusted comment: d';
        $refused(static fn () => Minisign::verify($data, implode("\n", $lines), $key['pub']), 'the trusted comment was changed');
        $refused(static fn () => Minisign::verify($data, "untrusted comment: x\nAAAA\ntrusted comment: c\nAAAA\n", $key['pub']), 'not a minisign signature');
        $refused(static fn () => Minisign::verify($data, $sig, 'bm90IGEga2V5'), 'not a minisign key');
    },
    'RSF05-07 verify: the checksum from SHA256SUMS; without a key it says the signature is not checked; with one, the signature and its checksum' => function (): void {
        $dir = releaseDir();
        try {
            $data = "<?php // released\n";
            file_put_contents("$dir/request-shield.php", $data);
            file_put_contents("$dir/SHA256SUMS", hash('sha256', 'other') . "  request-shield-stats.php\n" . hash('sha256', $data) . "  request-shield.php\n");
            [$code, $out] = runStreams(static fn ($o, $e): int => Verify::run("$dir/request-shield.php", null, null, '', $o, $e));
            same(0, $code, $out);
            truthy(strpos($out, 'checksum: ok') === 0 && strpos($out, 'signature: not checked -- this build has no release key yet') !== false, $out);
            $key = msKey();
            file_put_contents("$dir/request-shield.php.minisig", msSign($data, $key, 'request-shield v1.0.0 sha256:' . hash('sha256', $data)));
            [$code, $out] = runStreams(static fn ($o, $e): int => Verify::run("$dir/request-shield.php", null, null, $key['pub'], $o, $e));
            same(0, $code, $out);
            truthy(strpos($out, 'signature: ok (request-shield v1.0.0 sha256:') !== false, $out);
            // Refused: another key, a comment naming another checksum, a changed file, no signature, no sums.
            [$code, , $err] = runStreams(static fn ($o, $e): int => Verify::run("$dir/request-shield.php", null, null, msKey()['pub'], $o, $e));
            same([1, true], [$code, strpos($err, 'another key') !== false], $err);
            file_put_contents("$dir/request-shield.php.minisig", msSign($data, $key, 'request-shield v1.0.0 sha256:' . str_repeat('0', 64)));
            [$code, , $err] = runStreams(static fn ($o, $e): int => Verify::run("$dir/request-shield.php", null, null, $key['pub'], $o, $e));
            same([1, true], [$code, strpos($err, 'names another checksum') !== false], $err);
            unlink("$dir/request-shield.php.minisig");
            [$code] = runStreams(static fn ($o, $e): int => Verify::run("$dir/request-shield.php", null, null, $key['pub'], $o, $e));
            same(2, $code, 'no signature next to it');
            file_put_contents("$dir/request-shield.php", $data . '// changed');
            [$code, , $err] = runStreams(static fn ($o, $e): int => Verify::run("$dir/request-shield.php", null, null, '', $o, $e));
            same([1, true], [$code, strpos($err, 'does NOT match SHA256SUMS') !== false], $err);
            [$code] = runStreams(static fn ($o, $e): int => Verify::run("$dir/request-shield.php", "$dir/nothing", null, '', $o, $e));
            same(2, $code, 'no SHA256SUMS');
            same([null, hash('sha256', 'x')], [Verify::listed("abc  x\n", 'x'), Verify::listed(hash('sha256', 'x') . " *x\n", 'x')], 'the binary marker of sha256sum -b is read too');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF05-07 self-update: refused without the release key; --check says whether a newer release exists; the update checks everything first, keeps .prev, updates the statistics file too' => function (): void {
        $dir = releaseDir();
        try {
            file_put_contents("$dir/request-shield.php", "<?php\n// 1.0.0\n");
            file_put_contents("$dir/request-shield-stats.php", "<?php\n// stats 1.0.0\n");
            $key = msKey();
            $server = fakeRelease($key, '1.1.0');
            $fetched = [];
            $fetch = static function (string $url) use (&$server, &$fetched): ?string {
                $fetched[] = $url;
                return $server[$url] ?? null;
            };
            $update = static fn (string $version, string $k = '') => new SelfUpdate("$dir/request-shield.php", $k, $version, $fetch);
            [$code, , $err] = runStreams(static fn ($o, $e): int => $update('1.0.0')->run(false, null, false, $o, $e));
            same([1, true, []], [$code, strpos($err, 'needs the release key') !== false, $fetched], 'no key: refused before anything is fetched');
            [$code, $out] = runStreams(static fn ($o, $e): int => $update('1.0.0', $key['pub'])->run(true, null, false, $o, $e));
            same([10, true], [$code, strpos($out, 'request-shield 1.1.0 is available') === 0], $out);
            [$code, $out] = runStreams(static fn ($o, $e): int => $update('1.1.0', $key['pub'])->run(true, null, false, $o, $e));
            same([0, true], [$code, strpos($out, 'is the latest release') !== false], $out);
            same(0, runStreams(static fn ($o, $e): int => $update('1.2.0-dev', $key['pub'])->run(false, null, false, $o, $e))[0], 'newer than the latest: nothing to do');
            // A changed download: refused, nothing replaced.
            $good = $server;
            $server[SelfUpdate::RELEASES . '/latest/download/request-shield-stats.php'] .= '// changed';
            [$code, , $err] = runStreams(static fn ($o, $e): int => $update('1.0.0', $key['pub'])->run(false, null, false, $o, $e));
            same([1, true], [$code, strpos($err, 'request-shield-stats.php: the signature does not match') !== false], $err);
            same(["<?php\n// 1.0.0\n", "<?php\n// stats 1.0.0\n"], [file_get_contents("$dir/request-shield.php"), file_get_contents("$dir/request-shield-stats.php")], 'nothing replaced');
            same([], glob("$dir/.request-shield*") ?: [], 'no temporary file left');
            // The update.
            $server = $good;
            [$code, $out, $err] = runStreams(static fn ($o, $e): int => $update('1.0.0', $key['pub'])->run(false, null, false, $o, $e));
            same(0, $code, $out . $err);
            same(["<?php\n// request-shield.php 1.1.0\n", "<?php\n// request-shield-stats.php 1.1.0\n", "<?php\n// 1.0.0\n", "<?php\n// stats 1.0.0\n"],
                [file_get_contents("$dir/request-shield.php"), file_get_contents("$dir/request-shield-stats.php"), file_get_contents("$dir/request-shield.php.prev"), file_get_contents("$dir/request-shield-stats.php.prev")]);
            truthy(strpos($out, 'updated: request-shield.php 1.0.0 -> 1.1.0') !== false, $out);
            // --to: a downgrade refused; a new major only with --major.
            $server = fakeRelease($key, '0.9.0', 'download/v0.9.0') + fakeRelease($key, '2.0.0', 'download/v2.0.0');
            [$code, , $err] = runStreams(static fn ($o, $e): int => $update('1.1.0', $key['pub'])->run(false, 'v0.9.0', false, $o, $e));
            same([1, true], [$code, strpos($err, 'a downgrade is not done') !== false], $err);
            [$code, , $err] = runStreams(static fn ($o, $e): int => $update('1.1.0', $key['pub'])->run(false, 'v2.0.0', false, $o, $e));
            same([1, true], [$code, strpos($err, 'new major version') !== false], $err);
            [$code, $out] = runStreams(static fn ($o, $e): int => $update('1.1.0', $key['pub'])->run(true, 'v2.0.0', false, $o, $e));
            same([10, true], [$code, strpos($out, 'self-update --major') !== false], '--check: a new major version is news too (10), not a refusal: ' . $out);
            same(0, runStreams(static fn ($o, $e): int => $update('1.1.0', $key['pub'])->run(false, 'v2.0.0', true, $o, $e))[0], 'with --major');
            same(1, runStreams(static fn ($o, $e): int => $update('1.1.0', $key['pub'])->run(false, '2.0', false, $o, $e))[0], '--to takes vX.Y.Z');
            // Signed, but no PHP this PHP reads: refused by php -l, nothing replaced.
            if (function_exists('exec')) {
                file_put_contents("$dir/request-shield.php", "<?php\n// 2.0.0\n");
                unlink("$dir/request-shield-stats.php");
                $bad = "<?php\nthis is not php;\n";
                $server = [SelfUpdate::RELEASES . '/latest/download/request-shield.php' => $bad,
                    SelfUpdate::RELEASES . '/latest/download/request-shield.php.minisig' => msSign($bad, $key, 'request-shield v2.0.1 sha256:' . hash('sha256', $bad))];
                [$code, , $err] = runStreams(static fn ($o, $e): int => $update('2.0.0', $key['pub'])->run(false, null, false, $o, $e));
                same([1, true, "<?php\n// 2.0.0\n"], [$code, strpos($err, 'is not valid PHP') !== false, file_get_contents("$dir/request-shield.php")], $err);
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF05-07 init: a starter per application, printed or written; never into the document root, never over a file without --force; each starter compiles and its examples pass' => function (): void {
        same(['exponential', 'plain', 'symfony', 'wordpress'], Shipped::starters());
        $dir = releaseDir();
        try {
            mkdir("$dir/docroot");
            [$code, $out] = runStreams(static fn ($o, $e): int => Starter::run('plain', null, null, false, $o, $e));
            same([0, Shipped::starter('plain')], [$code, $out], 'without --out: printed');
            [$code, , $err] = runStreams(static fn ($o, $e): int => Starter::run('plain', "$dir/docroot", "$dir/docroot/site.rules", false, $o, $e));
            same([2, true, false], [$code, strpos($err, 'inside the document root') !== false, is_file("$dir/docroot/site.rules")], $err);
            [$code, , $err] = runStreams(static fn ($o, $e): int => Starter::run('plain', "$dir/docroot", "$dir/docroot/sub/../site.rules", false, $o, $e));
            same(2, $code, 'a path that leads back into it: ' . $err);
            [$code, , $err] = runStreams(static fn ($o, $e): int => Starter::run('drupal', null, null, false, $o, $e));
            same([2, true], [$code, strpos($err, 'no starter for "drupal"') !== false], $err);
            foreach (Shipped::starters() as $app) {
                [$code, $out, $err] = runStreams(static fn ($o, $e): int => Starter::run($app, "$dir/docroot", "$dir/$app.rules", false, $o, $e));
                same(0, $code, "$app: $out$err");
                truthy(strpos((string) file_get_contents("$dir/$app.rules"), 'set mode monitor') !== false, "$app: monitor mode");
                if (function_exists('exec')) {
                    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli()) . ' test ' . escapeshellarg("$dir/$app.rules") . ' 2>&1', $test, $exit);
                    same(0, $exit, "$app: its examples pass: " . implode(' | ', array_slice($test, -3)));
                    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli()) . ' check ' . escapeshellarg("$dir/$app.rules") . ' 2>&1', $check, $exit);
                    same([], array_values(preg_grep('/^warning:/', $check) ?: []), "$app: check has nothing to warn about");
                    unset($test, $check);
                }
            }
            [$code, , $err] = runStreams(static fn ($o, $e): int => Starter::run('plain', null, "$dir/plain.rules", false, $o, $e));
            same([2, true], [$code, strpos($err, 'exists') !== false], 'not over a file');
            same(0, runStreams(static fn ($o, $e): int => Starter::run('plain', null, "$dir/plain.rules", true, $o, $e))[0], 'with --force');
            same([], glob("$dir/.plain.*") ?: [], 'compiled beside it, then moved in: nothing left over');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'RSF05-07 the command line: init, verify, self-update -- self-update refuses a source checkout, and in the single file the missing key' => function (): void {
        if (!function_exists('exec')) {
            skip('no exec');
        }
        $dir = releaseDir();
        try {
            $cli = static function (string $args): array {
                exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli()) . " $args 2>&1", $out, $code);
                return [$code, implode("\n", $out)];
            };
            [$code, $out] = $cli('init --app=wordpress --out=' . escapeshellarg("$dir/site.rules"));
            same(0, $code, $out);
            truthy(strpos($out, 'note: without --docroot') !== false, $out);
            file_put_contents("$dir/SHA256SUMS", hash_file('sha256', "$dir/site.rules") . "  site.rules\n");
            [$code, $out] = $cli('verify ' . escapeshellarg("$dir/site.rules"));
            same(0, $code, $out);
            [$code, $out] = $cli('self-update --check');
            if (rsSingle() === null) {
                same([2, true], [$code, strpos($out, 'source checkout') !== false], $out);
            } else {
                same([1, true], [$code, strpos($out, 'needs the release key') !== false], $out);
            }
            [$code, $out] = $cli('init');
            truthy($code === 2 && strpos($out, 'init --app=exponential|plain|symfony|wordpress') !== false, $out);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
