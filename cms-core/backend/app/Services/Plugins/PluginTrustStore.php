<?php

namespace App\Services\Plugins;

use App\Models\PublisherKey;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * فاز ۰ — trust store ناشران.
 *
 * دو تغییر رفتاری نسبت به `PluginSignatureVerifier::verify()` قبلی:
 *
 *  ۱. **چندناشره.** قبلاً فقط `config('plugins.public_key')` — یک کلید برای
 *     کل پلتفرم. حالا مانیفست می‌تواند `publisher.key_id` را اعلام کند و
 *     کلید از `publisher_keys` خوانده می‌شود. کلید config به‌عنوان fallback
 *     باقی می‌ماند تا نصب‌های موجود نشکنند.
 *
 *  ۲. **تفکیک اصالت از تأیید.** خروجی این سرویس فقط می‌گوید «امضا معتبر است».
 *     هیچ چیز درباره اینکه ناشر قابل‌اعتماد است یا پلتفرم کد را بررسی کرده
 *     نمی‌گوید. آن دو در `publisher_verified` و `review_status` جدا نگهداری
 *     می‌شوند. اگر این دو را دوباره یکی کنیم، بازار به «امضا = تأیید» برمی‌گردد
 *     که دقیقاً همان اشتباهی است که فاز ۰ در حال رفع آن است.
 *
 * هشدار صادقانه: امضای Ed25519 فقط ثابت می‌کند مانیفست دست‌نخورده است. ثابت
 * نمی‌کند کد امن است، یا ناشر نیت خوب داشته. تنها راه اجرای امن کد ناشناس،
 * جداسازی process است که در فاز ۱ انجام نمی‌شود.
 */
class PluginTrustStore
{
    /** @return array{valid: bool, reason: string, key_id: ?string, publisher_verified: bool} */
    public function verifyPublisherSignature(array $manifest): array
    {
        $slug = $manifest['slug'] ?? null;

        if (! isset($manifest['signature']) || ! is_string($manifest['signature']) || $manifest['signature'] === '') {
            return $this->fail($slug, 'امضای ناشر در مانیفست موجود نیست.');
        }

        $keyId = $this->resolveKeyId($manifest);

        // نکته امنیتی: وقتی key_id اعلام شده ولی کلیدش در trust store نیست،
        // نباید به کلید config برگردیم. آن کلید متعلق به ناشر دیگری است و
        // جایگزینی‌اش یعنی fail-open. بنابراین null صریحاً خطاست، نه fallback.
        if ($keyId !== null) {
            $resolved = $this->publicKeyFor($keyId);
            if ($resolved === null) {
                Log::warning('plugin.publisher_key_unknown', ['slug' => $slug, 'key_id' => $keyId]);

                return $this->fail($slug, 'کلید ناشر اعلام‌شده در مانیفست شناخته نشد یا ابطال شده است.', $keyId);
            }
            $publicKeyBase64 = $resolved;
        } else {
            $publicKeyBase64 = (string) config('plugins.public_key', '');
        }

        if ($publicKeyBase64 === '') {
            Log::warning('plugin.no_publisher_key', ['slug' => $slug, 'key_id' => $keyId]);

            return $this->fail($slug, 'کلید عمومی ناشر برای این پلاگین پیدا نشد.');
        }

        $check = $this->verifier()->verify($manifest, $publicKeyBase64);
        if (! $check['valid']) {
            Log::warning('plugin.publisher_signature_invalid', [
                'slug' => $slug, 'key_id' => $keyId, 'reason' => $check['reason'],
            ]);

            return $this->fail($slug, $check['reason'], $keyId);
        }

        return [
            'valid' => true,
            'reason' => '',
            'key_id' => $keyId,
            'publisher_verified' => $keyId !== null,
        ];
    }

    /**
     * مهر یکپارچگی per-install (تصمیم K0.4).
     *
     * فاز ۰ فقط کلید را تولید و ذخیره می‌کند. مهر واقعی زمانی زده می‌شود که
     * فایل‌ها استخراج شده باشند (فاز ۱)، چون digest روی فایل‌های روی دیسک
     * حساب می‌شود نه روی ZIP آپلودی.
     *
     * کلید عمداً بیرون از آرتیفکت ذخیره می‌شود. اگر داخل ZIP بود، uninstall
     * و نصب مجدد همان ZIP آن را بی‌اثر می‌کرد.
     */
    public function ensureSealKey(string $slug): string
    {
        $cacheKey = "plugin:seal:{$slug}";

        $existing = Cache::get($cacheKey);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $key = base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES));
        Cache::forever($cacheKey, $key);

        return $key;
    }

    /** key_id از مانیفست، یا null اگر ناشر اعلام نکرده باشد. */
    private function resolveKeyId(array $manifest): ?string
    {
        $keyId = $manifest['publisher']['key_id'] ?? $manifest['publisher_key_id'] ?? null;

        // سقف ۶۴ هم‌راستا با ستون publisher_key_id. کوتاه‌کردن این مقدار یعنی
        // جست‌وجوی یک کلید ناقص در trust store، که همیشه null می‌دهد و نصب را
        // بی‌دلیل رد می‌کند.
        return is_string($keyId) && $keyId !== '' ? substr($keyId, 0, 64) : null;
    }

    private function publicKeyFor(string $keyId): ?string
    {
        return Cache::remember("plugin:trust:key:{$keyId}", 300, function () use ($keyId): ?string {
            $key = PublisherKey::query()
                ->where('key_fingerprint', $keyId)
                ->first();

            // کلید ابطال‌شده عمداً null برمی‌گرداند تا امضا fail-closed شود.
            return $key?->isActive() ? (string) $key->public_key : null;
        });
    }

    private function verifier(): PluginSignatureVerifier
    {
        return app(PluginSignatureVerifier::class);
    }

    private function fail(?string $slug, string $reason, ?string $keyId = null): array
    {
        return [
            'valid' => false,
            'reason' => $reason,
            'key_id' => $keyId,
            'publisher_verified' => false,
        ];
    }
}
