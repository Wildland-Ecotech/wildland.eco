<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Proof-of-work spam protection for public forms.
 *
 * The server issues a signed, random challenge with the form. Before the form
 * is submitted, JavaScript in the browser (resources/js/proof-of-work.js)
 * searches for a nonce such that SHA-256("<challenge>:<nonce>") starts with
 * DIFFICULTY copies of the hex digit CHARACTER ("dddd"). This takes a
 * person's browser about a second, while making bulk spam expensive. Each
 * challenge can be used only once and expires after LIFETIME seconds.
 *
 * Challenge format: "<random hex>.<issued at>.<prefix>.<signature>", where the
 * prefix is what the hash must start with. The signature is an HMAC with the
 * application key, so the prefix and issue time cannot be changed, and no
 * challenge needs to be stored until it is used.
 */
class ProofOfWork
{
    /**
     * The hex digit the hash must start with. The choice is arbitrary: every
     * digit is equally likely, so any prefix of the same length is equally hard.
     */
    public const CHARACTER = 'd';

    /**
     * How many times the hash must start with CHARACTER. Each one makes the
     * work 16 times harder: 4 takes about 65,000 hashes on average.
     */
    public const DIFFICULTY = 4;

    /**
     * How long a challenge stays valid, in seconds: long enough to fill in a
     * long form at an unhurried pace.
     */
    public const LIFETIME = 2 * 60 * 60;

    /**
     * Create a new challenge for a form.
     */
    public function issue(): string
    {
        $payload = implode('.', [bin2hex(random_bytes(16)), now()->getTimestamp(), str_repeat(self::CHARACTER, self::DIFFICULTY)]);

        return $payload . '.' . $this->sign($payload);
    }

    /**
     * Check a solved challenge, and mark it as used so it cannot be submitted again.
     */
    public function verify(mixed $challenge, mixed $nonce): bool
    {
        if (! is_string($challenge) || ! is_string($nonce) || ! preg_match('/^\d{1,16}$/', $nonce)) {
            return false;
        }

        $parts = explode('.', $challenge);

        if (count($parts) !== 4) {
            return false;
        }

        [$random, $issuedAt, $prefix, $signature] = $parts;

        if (! hash_equals($this->sign("{$random}.{$issuedAt}.{$prefix}"), $signature)) {
            return false;
        }

        if (now()->getTimestamp() - (int) $issuedAt > self::LIFETIME) {
            return false;
        }

        if (! str_starts_with(hash('sha256', "{$challenge}:{$nonce}"), $prefix)) {
            return false;
        }

        // Fails if the challenge was already used
        return Cache::add('proof-of-work:' . hash('sha256', $challenge), true, self::LIFETIME);
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', 'proof-of-work|' . $payload, config('app.key'));
    }
}
