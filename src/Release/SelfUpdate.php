<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Release;

/**
 * `request-shield self-update [--check] [--to=vX.Y.Z] [--major]` (0031 E.6,
 * proposal 0002): the single file replaces itself with a signed release.
 * Command line only -- the web has no way here -- and never automatic.
 *
 * What it trusts: the release key embedded in the running file. The release's
 * signature file is read first; its trusted comment ("request-shield vX.Y.Z
 * sha256:<hex>") names the version and the checksum, its global signature
 * proves the comment. Then the file is downloaded, its checksum and its
 * signature checked, `php -l` run on it; a downgrade, the same version, or a
 * new major version without --major is refused. Every edition file beside it
 * (request-shield-stats.php) is updated the same way and to the same version,
 * all checked before any is replaced; each old one is kept as <file>.prev,
 * and the new one moved in with rename(). Exit 0: up to date or updated;
 * 1: refused or failed (nothing replaced); 2: not the single file; 10 (with
 * --check): a newer version exists.
 */
final class SelfUpdate
{
    public const RELEASES = 'https://github.com/cjw-network/request-shield/releases';

    /** The add-on editions that are updated with the file when they lie beside it. */
    public const EDITIONS = ['request-shield-stats.php'];

    /** @var callable(string): ?string */
    private $fetch;

    /**
     * @param string $target the running single file
     * @param string $key the release key (Shipped::PUBKEY)
     * @param string $version the running version (Shield::VERSION)
     * @param ?callable(string): ?string $fetch a URL's body, null when it could not be fetched (default: Http::get, 200 only)
     */
    public function __construct(private string $target, private string $key, private string $version, ?callable $fetch = null)
    {
        $this->fetch = $fetch ?? static function (string $url): ?string {
            $r = \CjwNetwork\RequestShield\Http::get($url, [], 60, 0, 'request-shield self-update');
            return $r !== null && $r['status'] === 200 ? $r['body'] : null;
        };
    }

    /**
     * @param resource $out
     * @param resource $err
     */
    public function run(bool $check, ?string $to, bool $major, $out, $err): int
    {
        if ($this->key === '') {
            fwrite($err, "request-shield: self-update needs the release key, and this build has none yet -- download the new file by hand and check it with verify\n");
            return 1;
        }
        if (!Minisign::available()) {
            fwrite($err, "request-shield: self-update checks the release's signature, and PHP has no sodium here -- download by hand and check it with minisign\n");
            return 1;
        }
        if ($to !== null && preg_match('/^v\d+\.\d+\.\d+$/', $to) !== 1) {
            fwrite($err, "request-shield: --to takes a release such as v1.2.0, not \"$to\"\n");
            return 1;
        }
        $base = $to === null ? self::RELEASES . '/latest/download/' : self::RELEASES . "/download/$to/";
        $dir = dirname($this->target);
        $files = [basename($this->target)];
        foreach (self::EDITIONS as $edition) {
            if (is_file("$dir/$edition")) {
                $files[] = $edition;
            }
        }
        // What the release says about itself, signed: the version and the checksum of each file.
        $plan = [];
        foreach ($files as $name) {
            $asset = $name === basename($this->target) ? 'request-shield.php' : $name;
            $signature = ($this->fetch)("$base$asset.minisig");
            if ($signature === null) {
                fwrite($err, "request-shield: cannot fetch $base$asset.minisig" . (($why = \CjwNetwork\RequestShield\Http::offline()) !== null ? " -- $why" : '') . "\n");
                return 1;
            }
            try {
                $comment = Minisign::trustedComment($signature, $this->key);
            } catch (\RuntimeException $e) {
                fwrite($err, "request-shield: $asset.minisig: " . $e->getMessage() . "\n");
                return 1;
            }
            if (preg_match('/^request-shield v(\d+\.\d+\.\d+) sha256:([0-9a-f]{64})$/', $comment, $m) !== 1) {
                fwrite($err, "request-shield: $asset.minisig: not a release comment: \"$comment\"\n");
                return 1;
            }
            $plan[$name] = ['asset' => $asset, 'version' => $m[1], 'sha256' => $m[2], 'signature' => $signature];
        }
        $new = $plan[basename($this->target)]['version'];
        foreach ($plan as $name => $p) {
            if ($p['version'] !== $new) {
                fwrite($err, "request-shield: $name is released as {$p['version']}, the file as $new -- try again in a while\n");
                return 1;
            }
        }
        $current = (string) strtok($this->version, '-');
        $cmp = version_compare($new, $current);
        if ($cmp === 0 && strpos($this->version, '-') === false) {
            fwrite($out, "request-shield $this->version is the " . ($to === null ? 'latest' : 'requested') . " release: nothing to do\n");
            return 0;
        }
        if ($cmp < 0) {
            if ($to === null) {
                fwrite($out, "request-shield $this->version is newer than the latest release ($new): nothing to do\n");
                return 0;
            }
            fwrite($err, "request-shield: $to is older than $this->version -- a downgrade is not done here; keep the .prev file, or download it by hand\n");
            return 1;
        }
        $newMajor = (int) $new !== (int) $current;
        if ($check) {
            // --check replaces nothing: a new major version is news like any other (exit 10).
            fwrite($out, "request-shield $new is available (installed: $this->version) -- request-shield self-update" . ($newMajor ? ' --major, after reading its changelog' : '') . "\n");
            return 10;
        }
        if ($newMajor && !$major) {
            fwrite($err, "request-shield: $new is a new major version (from $this->version): read its changelog first, then run self-update --major\n");
            return 1;
        }
        // Everything downloaded and checked before anything is replaced.
        $ready = [];
        foreach ($plan as $name => $p) {
            $data = ($this->fetch)($base . $p['asset']);
            if ($data === null) {
                fwrite($err, "request-shield: cannot fetch $base{$p['asset']}\n");
                self::remove($ready);
                return 1;
            }
            try {
                Minisign::verify($data, $p['signature'], $this->key);
            } catch (\RuntimeException $e) {
                fwrite($err, "request-shield: {$p['asset']}: " . $e->getMessage() . "\n");
                self::remove($ready);
                return 1;
            }
            if (!hash_equals($p['sha256'], hash('sha256', $data))) {
                fwrite($err, "request-shield: {$p['asset']} does not match the checksum its signature names\n");
                self::remove($ready);
                return 1;
            }
            $tmp = "$dir/.$name." . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, $data) === false) {
                fwrite($err, "request-shield: cannot write into $dir\n");
                self::remove($ready);
                return 1;
            }
            $ready[$name] = $tmp;
            if (function_exists('exec')) {
                $lint = [];
                exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $lint, $code);
                if ($code !== 0) {
                    fwrite($err, "request-shield: {$p['asset']} is not valid PHP for this PHP (" . PHP_VERSION . "): " . implode(' ', $lint) . "\n");
                    self::remove($ready);
                    return 1;
                }
            }
        }
        foreach ($ready as $name => $tmp) {
            @chmod($tmp, (@fileperms("$dir/$name") ?: 0644) & 0777);
            if (!@copy("$dir/$name", "$dir/$name.prev") || !@rename($tmp, "$dir/$name")) {
                fwrite($err, "request-shield: cannot replace $dir/$name" . ($name !== basename($this->target) ? ' (the files before it are updated already)' : '') . "\n");
                self::remove($ready);
                return 1;
            }
            unset($ready[$name]);
            fwrite($out, "updated: $name $this->version -> $new (the old one is $name.prev)\n");
        }
        fwrite($out, 'What changed: ' . self::RELEASES . "/tag/v$new\n"
            . "PHP-FPM uses the new file after opcache.revalidate_freq, or at once after a reload.\n");
        return 0;
    }

    /** @param array<string, string> $files name => temporary file */
    private static function remove(array $files): void
    {
        foreach ($files as $tmp) {
            @unlink($tmp);
        }
    }
}
