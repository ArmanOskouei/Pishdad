<?php

namespace App\Services\Settings;

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

    public static function remember(string $group, string $key, int $ttl, callable $fallback): mixed
    {
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

        try {
            Cache::tags([self::TAG])->forget($cacheKey);
        } catch (\BadMethodCallException) {
            Cache::forget($cacheKey);
        }
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
