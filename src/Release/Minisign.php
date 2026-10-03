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
 * A minisign signature checked in pure PHP with sodium (0031 E.6): what
 * `verify` and `self-update` trust a download by. Never on a request path.
 *
 * The formats (https://jedisct1.github.io/minisign/): the public key is the
 * base64 of "Ed" + key id (8 bytes) + Ed25519 key (32); a signature file has
 * an untrusted comment, the base64 of algorithm ("ED": the BLAKE2b-512 of the
 * file is signed, "Ed": the file itself) + key id + signature (64), a trusted
 * comment, and the base64 of the global signature over signature + trusted
 * comment -- so the trusted comment ("request-shield v1.2.0 sha256:…") cannot
 * be changed either.
 */
final class Minisign
{
    /** Whether signatures can be checked here: sodium is part of PHP since 7.2, but a build may leave it out. */
    public static function available(): bool
    {
        return function_exists('sodium_crypto_sign_verify_detached') && function_exists('sodium_crypto_generichash');
    }

    /**
     * The trusted comment of a signature over $data by $publicKey; a
     * RuntimeException saying what is wrong when it is not one.
     */
    public static function verify(string $data, string $signature, string $publicKey): string
    {
        [$alg, $sig, $comment, $key] = self::parts($signature, $publicKey);
        $message = $alg === 'ED' ? sodium_crypto_generichash($data, '', 64) : $data;
        if (!sodium_crypto_sign_verify_detached($sig, $message, $key)) {
            throw new \RuntimeException('the signature does not match the file: it was changed, or signed by another key');
        }
        return $comment;
    }

    /**
     * The trusted comment alone, its global signature checked -- what a
     * signature file says about a file before the file is downloaded (the
     * version and the checksum of the next release).
     */
    public static function trustedComment(string $signature, string $publicKey): string
    {
        return self::parts($signature, $publicKey)[2];
    }

    /**
     * The signature's algorithm, its signature, its trusted comment and the
     * Ed25519 key, the key id and the global signature checked.
     *
     * @return array{0: string, 1: non-empty-string, 2: string, 3: non-empty-string}
     */
    private static function parts(string $signature, string $publicKey): array
    {
        if (!self::available()) {
            throw new \RuntimeException('signatures cannot be checked here: PHP has no sodium');
        }
        $pk = base64_decode(trim($publicKey), true);
        if (!is_string($pk) || strlen($pk) !== 42 || substr($pk, 0, 2) !== 'Ed') {
            throw new \RuntimeException('the public key is not a minisign key');
        }
        /** @var non-empty-string $key 32 bytes: the length is checked above */
        $key = substr($pk, 10, 32);
        $lines = preg_split('/\r\n|\n/', trim($signature)) ?: [];
        if (count($lines) !== 4 || strncmp($lines[0], 'untrusted comment:', 18) !== 0 || strncmp($lines[2], 'trusted comment: ', 17) !== 0) {
            throw new \RuntimeException('not a minisign signature file');
        }
        $sig = base64_decode($lines[1], true);
        $global = base64_decode($lines[3], true);
        if (!is_string($sig) || strlen($sig) !== 74 || !in_array(substr($sig, 0, 2), ['Ed', 'ED'], true) || !is_string($global) || strlen($global) !== 64) {
            throw new \RuntimeException('not a minisign signature file');
        }
        if (!hash_equals(substr($pk, 2, 8), substr($sig, 2, 8))) {
            throw new \RuntimeException('signed with another key (key id ' . strtoupper(bin2hex(strrev(substr($sig, 2, 8)))) . ')');
        }
        $comment = substr($lines[2], 17);
        /** @var non-empty-string $signed 64 bytes: the length is checked above */
        $signed = substr($sig, 10);
        if (!sodium_crypto_sign_verify_detached($global, $signed . $comment, $key)) {
            throw new \RuntimeException('the trusted comment was changed');
        }
        return [substr($sig, 0, 2), $signed, $comment, $key];
    }
}
