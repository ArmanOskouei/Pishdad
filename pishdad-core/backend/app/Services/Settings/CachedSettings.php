<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * کش تگ‌دار تنظیمات (تسک‌های ۲.۳ / ۴.۱ / ۴.۳).
 *
 * تگ `settings` روی Redis/array کار می‌کند؛ روی درایورهایی که تگ ندارند
 * (مثل database) خودکار به کش ساده fallback می‌شود تا هیچ محیطی نترکد.
 * ابطال همیشه هنگام ذخیره (forget) انجام می‌شود.
 */
final class CachedSettings
{
    public const TAG = 'settings';

    public static function key(string $group, string $key): string
    {
        return "settings:{$group}:{$key}";
    }

    /** آیا کشِ سمتِ بک‌اند فعال است؟ کلید سراسری `site.cache_enabled` (پیش‌فرض: فعال). */
    public static function enabled(): bool
    {
        static $enabled = null;
        if ($enabled === null) {
            try {
                $site = Setting::get('site', 'global', []);
                $enabled = ! is_array($site) || (($site['cache_enabled'] ?? true) !== false);
            } catch (\Throwable) {
                $enabled = true;
            }
        }

        return $enabled;
    }

    public static function remember(string $group, string $key, int $ttl, callable $fallback): mixed
    {
        if (! self::enabled()) {
            return $fallback();
        }

        $cacheKey = self::key($group, $key);

        try {
            return Cache::tags([self::TAG])->remember($cacheKey, $ttl, $fallback);
        } catch (\BadMethodCallException) {
            return Cache::remember($cacheKey, $ttl, $fallback);
        }
    }

    public static function forget(string $group, string $key): void
    {
        $cacheKey = self::key($group, $key);

        /**
         * ⭐ هر دو مسیر پاک می‌شوند — و این فقط برای مرتب بودن نیست.
         *
         * نسخهٔ قبلی فقط `Cache::tags()->forget()` را صدا می‌زد و `BadMethodCallException`
         * را می‌گرفت. ولی روی درایوری که **تگِ واقعی ندارد** (`array` در تست، و
         * هر درایورِ فایلی) آن استثنا پرتاب نمی‌شود — `tags()` کار می‌کند ولی
         * `forget` کلیدِ دیگری را می‌زند. یعنی ابطال **بی‌صدا** شکست می‌خورد.
         *
         * و شکستِ بی‌صدای ابطال دقیقاً چه شکلی دارد؟ `site_chrome` ۱۲۰ ثانیه
         * کهنه می‌ماند و کاربر می‌بیند «ذخیره شد» ولی سایت عوض نشده — بدون خطا،
         * بدون لاگ. هر دو فراخوانی لازم‌اند تا حداقل یکی از آن‌ها واقعاً پاک کند.
         */
        try {
            Cache::tags([self::TAG])->forget($cacheKey);
        } catch (\BadMethodCallException) {
            // درایور بدون تگ: `Cache::forget` در پایین کار را می‌کند.
        }

        Cache::forget($cacheKey);
    }

    /** ابطال همه کلیدهای گروه تنظیمات (مثلاً بعد از تغییر برند مؤثر بر پیام تعلیق). */
    public static function flushTag(): void
    {
        try {
            Cache::tags([self::TAG])->flush();
        } catch (\BadMethodCallException) {
            // درایور بدون تگ: فقط کلیدهای شناخته‌شده پاک می‌شوند (forget تکی).
        }
    }
}
