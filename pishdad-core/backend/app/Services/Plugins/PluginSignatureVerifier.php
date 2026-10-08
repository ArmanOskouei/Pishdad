<?php

namespace App\Services\Plugins;

use Illuminate\Support\Facades\Log;

/**
 * تسک ۵.۴ — راستی‌آزمایی امضای Ed25519 مانیفست (پلاگین + قالب).
 *
 * قرارداد امضا: فیلد `signature` مانیفست = base64 امضای detached روی
 * بایت‌های canonical مانیفست بدون فیلد signature (JSON با کلیدهای
 * مرتب‌شده، بدون فاصله اضافی). libsodium (یا polyfill
 * paragonie/sodium_compat) برای verify استفاده می‌شود.
 */
class PluginSignatureVerifier
{
    public const CANONICAL_EXCLUDE = 'signature';

    /** ساخت بایت canonical از مانیفست (بدون فیلد signature). */
    public function canonical(array $manifest): string
    {
        $payload = $manifest;
        unset($payload[self::CANONICAL_EXCLUDE]);
        $this->sortRecursive($payload);

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** امضا برای تست/ابزار: خروجی base64. */
    public function sign(array $manifest, string $secretKeyBase64): string
    {
        $secret = base64_decode($secretKeyBase64, true);
        $sig = sodium_crypto_sign_detached($this->canonical($manifest), $secret);

        return base64_encode($sig);
    }

    /**
     * نتیجه: ['valid' => bool, 'reason' => string].
     * هیچ‌وقت exception بیرون نمی‌دهد — خطا = invalid + لاگ امنیتی.
     */
    public function verify(array $manifest, ?string $publicKeyBase64 = null): array
    {
        $publicKeyBase64 ??= (string) config('plugins.public_key', '');

        if (! isset($manifest['signature']) || ! is_string($manifest['signature']) || $manifest['signature'] === '') {
            return ['valid' => false, 'reason' => 'امضای مانیفست یافت نشد.'];
        }
        if ($publicKeyBase64 === '') {
            Log::warning('plugin.signature_no_pubkey', ['slug' => $manifest['slug'] ?? null]);

            return ['valid' => false, 'reason' => 'کلید عمومی تأیید امضا پیکربندی نشده است.'];
        }

        try {
            $sig = base64_decode($manifest['signature'], true);
            $pub = base64_decode($publicKeyBase64, true);

            if (! is_string($sig) || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
                return ['valid' => false, 'reason' => 'قالب امضا معتبر نیست.'];
            }
            if (! is_string($pub) || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                Log::warning('plugin.signature_bad_pubkey');

                return ['valid' => false, 'reason' => 'کلید عمومی نامعتبر است.'];
            }

            $ok = sodium_crypto_sign_verify_detached($sig, $this->canonical($manifest), $pub);
        } catch (\Throwable $e) {
            Log::warning('plugin.signature_exception', ['error' => $e->getMessage()]);

            return ['valid' => false, 'reason' => 'خطا در راستی‌آزمایی امضا.'];
        }

        if (! $ok) {
            Log::warning('plugin.signature_invalid', ['slug' => $manifest['slug'] ?? null]);

            return ['valid' => false, 'reason' => 'امضای پلاگین معتبر نیست.'];
        }

        return ['valid' => true, 'reason' => ''];
    }

    private function sortRecursive(array &$data): void
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->sortRecursive($value);
            }
        }
        unset($value);
    }
}
