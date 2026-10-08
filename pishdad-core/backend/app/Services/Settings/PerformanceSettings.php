<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * WF-M13 — تنظیمات کارایی نصب: دامنهٔ دارایی/CDN، توکن پاک‌سازی CDN و
 * کلیدهای lazy-load و preload فونت.
 *
 * همراستا با `docs/CDN-ASSESSMENT.md`: CDN روی HTML توصیه نمی‌شود (خاصیت
 * انتشار آنی را خراب می‌کند)؛ دامنهٔ دارایی اینجا فقط برای **فایل‌های ایستا**
 * است. توکن پاک‌سازی رمزنگاری می‌شود و هرگز به کلاینت برنمی‌گردد — خروجی API
 * فقط بولینِ `cdn_purge_token_set` می‌دهد. فونت هم همیشه محلی است و این
 * تنظیمات هرگز فونت را به CDN خارجی نمی‌فرستد.
 */
final class PerformanceSettings
{
    public const GROUP = 'performance';

    public const KEY = 'global';

    public const DEFAULTS = [
        'asset_domain' => null,
        'cdn_purge_token_encrypted' => null,
        'lazy_load_enabled' => true,
        'font_preload_enabled' => true,
    ];

    /** @return array<string, mixed> */
    public static function get(): array
    {
        try {
            $stored = CachedSettings::remember(
                self::GROUP,
                self::KEY,
                300,
                fn () => Setting::get(self::GROUP, self::KEY, []),
            );
        } catch (Throwable) {
            return self::DEFAULTS;
        }

        return array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
    }

    /** دامنهٔ دارایی نرمال‌شده (بدون اسلش پایانی)، یا null. */
    public static function assetDomain(): ?string
    {
        return self::normalizeDomain(self::get()['asset_domain'] ?? null);
    }

    public static function lazyLoadEnabled(): bool
    {
        return (bool) (self::get()['lazy_load_enabled'] ?? true);
    }

    public static function fontPreloadEnabled(): bool
    {
        return (bool) (self::get()['font_preload_enabled'] ?? true);
    }

    /** توکنِ رمزگشایی‌شده، یا null اگر ذخیره نشده/رمزگشایی نشد. */
    public static function cdnPurgeToken(): ?string
    {
        $encrypted = self::get()['cdn_purge_token_encrypted'] ?? null;

        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    public static function hasCdnPurgeToken(): bool
    {
        return self::cdnPurgeToken() !== null;
    }

    /**
     * ذخیرهٔ تنظیمات. `cdn_purge_token` ناموجود/خالی = «بدون تغییر» تا فرم
     * نتواند ناخواسته توکنِ ذخیره‌شده را پاک کند. `clear_cdn_purge_token`
     * پاک‌کردنِ صریح است.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function save(array $data): array
    {
        $current = self::get();

        $pick = fn (string $key, mixed $fallback): mixed => array_key_exists($key, $data) ? $data[$key] : $fallback;

        $tokenEncrypted = $current['cdn_purge_token_encrypted'] ?? null;

        if (! empty($data['clear_cdn_purge_token'])) {
            $tokenEncrypted = null;
        } elseif (isset($data['cdn_purge_token']) && is_string($data['cdn_purge_token']) && $data['cdn_purge_token'] !== '') {
            $tokenEncrypted = Crypt::encryptString($data['cdn_purge_token']);
        }

        $next = [
            'asset_domain' => self::normalizeDomain($pick('asset_domain', $current['asset_domain'] ?? null)),
            'cdn_purge_token_encrypted' => $tokenEncrypted,
            'lazy_load_enabled' => (bool) $pick('lazy_load_enabled', $current['lazy_load_enabled'] ?? true),
            'font_preload_enabled' => (bool) $pick('font_preload_enabled', $current['font_preload_enabled'] ?? true),
        ];

        Setting::set(self::GROUP, self::KEY, $next);
        CachedSettings::forget(self::GROUP, self::KEY);

        return $next;
    }

    private static function normalizeDomain(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $trimmed = rtrim(trim($raw), '/');

        return $trimmed === '' ? null : $trimmed;
    }
}
