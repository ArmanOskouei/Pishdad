<?php

namespace App\Services\Plugins;

use App\Models\Plugin;
use App\Models\PublisherKey;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

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
     * گروه تنظیماتی که کلید مهر در آن می‌نشیند.
     *
     * `public` است چون مسیر حذف پلاگین — که مال فایل دیگری است — باید بتواند
     * ردیف کلید را پاک کند. کلید یتیم یعنی پلاگینی که حذف شده و دوباره با
     * همان slug نصب می‌شود و کلید مرده را به ارث می‌برد.
     */
    public const SEAL_GROUP = 'plugin';

    /**
     * کلید مهر یکپارچگی per-install (تصمیم K0.4).
     *
     * **چرا جدول `settings` و نه cache (B19).** نسخهٔ قبلی کلید را با
     * `Cache::forever` می‌نوشت. یک `cache:clear` یا flush ریدایس کلید را بی‌صدا
     * نابود می‌کرد و آن‌وقت همهٔ بسته‌های قبلی «مهرشان نامعتبر» می‌شد بدون اینکه
     * کسی بفهمد چرا. حالا جدول `settings` تنها منبع حقیقت است — نه cache جلویش
     * هست و نه fallback، چون دو منبع حقیقت یعنی بعداً معلوم نیست باید کدام را
     * خواند و کدام را می‌شود دور انداخت.
     *
     * مهر واقعی زمانی زده می‌شود که فایل‌ها استخراج شده باشند (فاز ۱)، چون
     * digest روی فایل‌های روی دیسک حساب می‌شود نه روی ZIP آپلودی.
     *
     * کلید عمداً بیرون از آرتیفکت ذخیره می‌شود. اگر داخل ZIP بود، uninstall و
     * نصب مجدد همان ZIP آن را بی‌اثر می‌کرد.
     *
     * @throws InvalidArgumentException اگر slug به چیز قابل استفاده‌ای تبدیل نشود
     */
    public function ensureSealKey(string $slug): string
    {
        $slug = $this->normalizeSlug($slug);

        $stored = $this->readSealKey($slug);
        if ($stored !== null) {
            return $stored;
        }

        // کلید از `keypair()` ساخته می‌شود، نه از `random_bytes` با طول ثابت.
        // `ext-sodium` روی این پروژه نصب نیست و polyfill اندازهٔ secret را
        // متفاوت اعلام می‌کند (۶۴ به‌جای ۳۲). نوشتن `random_bytes(32)` کلیدی
        // می‌داد که همین runtime آن را نمی‌پذیرد — یعنی ساخت مهر در محیط
        // واقعی کرش می‌کرد ولی چون متد بی‌caller بود، کسی متوجه نبود.
        $pair = sodium_crypto_sign_keypair();
        $secret = base64_encode(sodium_crypto_sign_secretkey($pair));

        // `insertOrIgnore` و نه `updateOrCreate`: دو درخواستِ همزمانِ یک
        // پلاگین نباید کلید برنده را بی‌صدا عوض کنند، وگرنه مهری که با کلید
        // قبلی زده شده برای همیشه نامعتبر می‌ماند. اگر درج بازنده بود، مقدار
        // ذخیره‌شده را می‌خوانیم؛ اگر برنده بود، همان کلید خودمان است.
        Setting::query()->insertOrIgnore([
            'group' => self::SEAL_GROUP,
            'key' => $this->sealKeyName($slug),
            // درج دسته‌ای cast مدل را اعمال نمی‌کند، پس JSON دستی نوشته می‌شود.
            'value' => (string) json_encode(['secret' => $secret], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stored = $this->readSealKey($slug);
        if ($stored !== null) {
            return $stored;
        }

        // رسیدن به اینجا یعنی یک ردیف هست که درج را خفه کرده ولی کلیدِ
        // قابل استفاده‌ای ندارد. تنها جایی که مجاز به بازنویسی هستیم.
        // فقط slug ثبت می‌شود، نه خود کلید.
        Log::warning('plugin.seal_key_rebuilt', ['slug' => $slug]);

        return $this->replaceSealKey($slug, $secret);
    }

    /**
     * نقطهٔ اتصال مهر یکپارچگی در مسیر نصب (K5.9).
     *
     * `ensureSealKey()` رشته می‌گیرد تا در آزمون و ابزار راحت باشد، ولی مسیر
     * واقعی نصب یک شیء `Plugin` دارد. این متد همان تبدیل را انجام می‌دهد تا
     * فراخوانی در `PluginController` حدس‌وبرگو نباشد.
     */
    public function ensureSealKeyFor(Plugin $plugin): string
    {
        return $this->ensureSealKey($plugin->slug);
    }

    /**
     * حذف کلید مهر — همراهِ حذف افزونه.
     *
     * بدون این، ردیف settings یتیم می‌ماند و نصب دوبارهٔ افزونه‌ای با همان slug
     * به کلید **قبلی** می‌خورد؛ یعنی مهرهایی که با کلید قبلی ساخته شده بودند
     * دوباره معتبر شمرده می‌شدند.
     *
     * برگشت‌پذیر نیست و عمداً: حذف افزونه یعنی پاک کردن مهرش.
     */
    public function forgetSealKeyFor(string $slug): void
    {
        Setting::query()
            ->where('group', self::SEAL_GROUP)
            ->where('key', $this->sealKeyName($this->normalizeSlug($slug)))
            ->delete();
    }

    private function sealKeyName(string $slug): string
    {
        return 'seal_key_'.$slug;
    }

    /**
     * همان نرمال‌سازی‌ای که `PluginController::upload()` روی slug انجام می‌دهد.
     *
     * اگر اینجا چیز دیگری بود، کلید زیر نامی می‌نشست که کدِ استخراج در فاز ۱
     * پیدایش نمی‌کرد — یعنی همان بازسازی بی‌صدایی که این متد برای جلوگیری از
     * آن نوشته شده.
     */
    private function normalizeSlug(string $slug): string
    {
        $normalized = substr(Str::slug($slug), 0, 100);

        // slug تهی همه به یک ردیف مشترک می‌ریخت و یک پلاگین می‌توانست کلیدِ
        // پلاگین دیگر را برودارد.
        if ($normalized === '') {
            throw new InvalidArgumentException('شناسهٔ پلاگین برای ساخت کلید مهر قابل استفاده نیست.');
        }

        return $normalized;
    }

    /** کلید ذخیره‌شده، یا null اگر نبود / ناقص بود / base64 نبود. */
    private function readSealKey(string $slug): ?string
    {
        $raw = Setting::get(self::SEAL_GROUP, $this->sealKeyName($slug));

        if (! is_array($raw) || ! isset($raw['secret']) || ! is_string($raw['secret'])) {
            return null;
        }

        $decoded = base64_decode($raw['secret'], true);
        if (! is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            return null;
        }

        return $raw['secret'];
    }

    private function replaceSealKey(string $slug, string $secret): string
    {
        Setting::query()
            ->where('group', self::SEAL_GROUP)
            ->where('key', $this->sealKeyName($slug))
            ->update([
                'value' => (string) json_encode(['secret' => $secret], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

        return $secret;
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
