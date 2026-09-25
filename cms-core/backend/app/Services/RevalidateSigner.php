<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Signs the future Next.js `revalidate` webhook calls.
 *
 * Format: signature = HMAC-SHA256("ts|nonce|tag1,tag2,...", secret).
 * Verification enforces a timestamp leeway and single-use nonces
 * (replay cache) — never a bare shared key (PLAN.md constraint #5).
 */
class RevalidateSigner
{
    public function __construct(
        private readonly string $secret,
        private readonly int $leewaySeconds = 300,
    ) {}

    /** Build a signed payload for the given cache tags. */
    public function sign(array $tags, ?int $timestamp = null, ?string $nonce = null): array
    {
        sort($tags);

        $payload = [
            'tags' => array_values($tags),
            'timestamp' => $timestamp ?? time(),
            'nonce' => $nonce ?? Str::random(32),
        ];
        $payload['signature'] = $this->signatureFor($payload);

        return $payload;
    }

    /** Verify a payload produced by sign(). Single-use nonce. */
    public function verify(array $payload): bool
    {
        foreach (['tags', 'timestamp', 'nonce', 'signature'] as $field) {
            if (! array_key_exists($field, $payload)) {
                return false;
            }
        }

        if (! is_array($payload['tags']) || ! is_numeric($payload['timestamp']) || ! is_string($payload['nonce'])) {
            return false;
        }

        // Freshness window (absolute to tolerate small clock skew both ways).
        if (abs(time() - (int) $payload['timestamp']) > $this->leewaySeconds) {
            return false;
        }

        // Constant-time compare first, then claim the nonce (single use).
        $expected = $this->signatureFor($payload);
        if (! hash_equals($expected, (string) $payload['signature'])) {
            return false;
        }

        $cacheKey = 'revalidate:nonce:'.$payload['nonce'];
        if (! Cache::add($cacheKey, 1, $this->leewaySeconds)) {
            return false; // already seen → replay
        }

        return true;
    }

    private function signatureFor(array $payload): string
    {
        $tags = $payload['tags'];
        sort($tags);

        $message = implode('|', [
            (int) $payload['timestamp'],
            (string) $payload['nonce'],
            implode(',', $tags),
        ]);

        return hash_hmac('sha256', $message, $this->secret);
    }
}
