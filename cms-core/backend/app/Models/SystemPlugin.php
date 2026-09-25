<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * allowlist پلاگین سیستمی.
 *
 * پلاگین سیستمی یعنی غیرقابل غیرفعال‌سازی و غیرقابل حذف. تا پیش از فاز ۰ این
 * پرچم از مانیفست آپلودشده خوانده می‌شد، یعنی هر کاربری با پرمیشن
 * `plugins.edit` می‌توانست پلاگینی بسازد که هرگز حذف نمی‌شد — و در نتیجه
 * بدافزاری که خودش نصب کرده بود را نمی‌توانست پاک کند.
 *
 * حالا مانیفست هرگز نمی‌تواند این پرچم را اعطا کند؛ فقط این جدول.
 */
class SystemPlugin extends Model
{
    protected $fillable = ['slug', 'notes'];

    protected static function booted(): void
    {
        static::saved(fn () => self::flush());
        static::deleted(fn () => self::flush());
    }

    public static function flush(): void
    {
        Cache::forget('system_plugins:slugs');
    }

    /** @return list<string> */
    public static function allowedSlugs(): array
    {
        return Cache::rememberForever('system_plugins:slugs', function (): array {
            return self::query()->pluck('slug')->map(fn ($s) => (string) $s)->all();
        });
    }

    public static function allows(?string $slug): bool
    {
        return $slug !== null && in_array($slug, self::allowedSlugs(), true);
    }

    /** allowlist را برای slugهای داده‌شده تضمین می‌کند (idempotent). */
    public static function ensureMany(iterable $slugs, ?string $notes = null): void
    {
        foreach ($slugs as $slug) {
            static::query()->firstOrCreate(
                ['slug' => $slug],
                ['notes' => $notes ?? 'قالب مرکزی'],
            );
        }
        self::flush();
    }
}
