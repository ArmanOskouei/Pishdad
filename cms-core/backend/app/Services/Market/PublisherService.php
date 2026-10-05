<?php

namespace App\Services\Market;

use App\Models\PublisherKey;
use Illuminate\Support\Str;

/**
 * K8.1 — publisher registration into the public multi-publisher trust store.
 * Reuses the existing PublisherKey model (trust store from the phase-0 rewrite).
 */
class PublisherService
{
    public function register(array $data): PublisherKey
    {
        $name = trim((string) ($data['name'] ?? ''));
        $slug = Str::slug((string) ($data['slug'] ?? ''));
        $publicKey = trim((string) ($data['public_key'] ?? ''));

        if ($name === '' || $slug === '' || $publicKey === '') {
            throw new MarketException('name, slug and public_key are required.', 422);
        }

        $fingerprint = PublisherKey::fingerprintOf($publicKey);
        if ($fingerprint === null) {
            throw new MarketException('public_key is not a valid base64 Ed25519 public key.', 422);
        }

        if (PublisherKey::query()->where('slug', $slug)->orWhere('key_fingerprint', $fingerprint)->exists()) {
            throw new MarketException('Publisher slug or key already registered.', 422);
        }

        return PublisherKey::query()->create([
            'name' => $name,
            'slug' => $slug,
            'public_key' => $publicKey,
            'key_fingerprint' => $fingerprint,
            'status' => PublisherKey::STATUS_ACTIVE,
        ]);
    }

    /** Public trust store: only active keys, no secret material. */
    public function publicStore(): array
    {
        return PublisherKey::query()->active()->latest()
            ->get(['id', 'name', 'slug', 'key_fingerprint', 'status', 'verified_at'])
            ->toArray();
    }
}
