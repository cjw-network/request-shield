<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Challenge;

/**
 * An ALTCHA-compatible proof of work (https://altcha.org, protocol v1).
 *
 * The server picks a secret number n up to maxnumber and sends
 * challenge = sha256(salt . n) with an HMAC of it; the browser tries every n
 * until the hash matches. That costs the browser maxnumber/2 hashes on
 * average -- a fraction of a second for one visitor, real work for a bot
 * that has to do it for every address it rotates through -- and the server
 * one hash and one HMAC to check it.
 *
 * The salt carries the expiry and a tag of the client's bucket
 * ("<random>?expires=<ts>&c=<tag>"), both covered by the challenge's hash and
 * signature, so a solution is neither reusable later nor from elsewhere.
 */
final class ProofOfWork
{
    public const ALGORITHM = 'SHA-256';

    public function __construct(private readonly string $secret)
    {
    }

    /**
     * @return array{algorithm: string, challenge: string, maxnumber: int, salt: string, signature: string}
     */
    public function create(string $bucket, int $maxNumber, int $expires): array
    {
        $maxNumber = max(1000, $maxNumber);
        $salt = bin2hex(random_bytes(12)) . '?expires=' . $expires . '&c=' . $this->tag($bucket);
        $challenge = hash('sha256', $salt . random_int(0, $maxNumber));
        return [
            'algorithm' => self::ALGORITHM,
            'challenge' => $challenge,
            'maxnumber' => $maxNumber,
            'salt' => $salt,
            'signature' => hash_hmac('sha256', $challenge, $this->secret),
        ];
    }

    /**
     * Whether $payload (base64 of the widget's JSON: algorithm, challenge,
     * number, salt, signature) solves a challenge this server made for
     * $bucket that has not expired.
     */
    public function verify(string $payload, string $bucket, float $now): bool
    {
        $b64 = strtr($payload, '-_', '+/');
        $json = base64_decode($b64 . str_repeat('=', (4 - strlen($b64) % 4) % 4), true);
        $data = is_string($json) && strlen($json) < 2048 ? json_decode($json, true) : null;
        if (!is_array($data) || ($data['algorithm'] ?? '') !== self::ALGORITHM) {
            return false;
        }
        $challenge = $data['challenge'] ?? null;
        $salt = $data['salt'] ?? null;
        $signature = $data['signature'] ?? null;
        $number = $data['number'] ?? null;
        if (!is_string($challenge) || !is_string($salt) || !is_string($signature)
            || !(is_int($number) || (is_string($number) && ctype_digit($number)))) {
            return false;
        }
        if (!hash_equals(hash_hmac('sha256', $challenge, $this->secret), $signature)
            || !hash_equals($challenge, hash('sha256', $salt . $number))) {
            return false;
        }
        $query = strpos($salt, '?');
        if ($query === false) {
            return false;
        }
        parse_str(substr($salt, $query + 1), $params);
        return isset($params['expires'], $params['c'])
            && is_string($params['expires']) && ctype_digit($params['expires'])
            && (int) $params['expires'] >= $now
            && is_string($params['c']) && hash_equals($this->tag($bucket), $params['c']);
    }

    /** The challenge a payload answers (to use a solution only once), or null. */
    public static function challengeOf(string $payload): ?string
    {
        $b64 = strtr($payload, '-_', '+/');
        $json = base64_decode($b64 . str_repeat('=', (4 - strlen($b64) % 4) % 4), true);
        $data = is_string($json) && strlen($json) < 2048 ? json_decode($json, true) : null;
        $challenge = is_array($data) ? ($data['challenge'] ?? null) : null;
        return is_string($challenge) && preg_match('/^[0-9a-f]{64}$/', $challenge) ? $challenge : null;
    }

    private function tag(string $bucket): string
    {
        return substr(hash_hmac('sha256', 'client|' . $bucket, $this->secret), 0, 16);
    }
}
