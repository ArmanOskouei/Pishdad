<?php

namespace App\Services\Plugins;

use App\Models\Setting;
use App\Services\Settings\CachedSettings;

/**
 * K6.7 — ذخیرهٔ مقادیر تنظیمات افزونه‌ها.
 *
 * ## چرا جدول `settings` و نه جدول تازه
 *
 * جدول `settings` یک EAV با کلید `(group, key)` است و **همین حالا** برای
 * چیزی در همین شکل استفاده می‌شود: `group = 'ui'`, `key = 'user_{id}'` در
 * `Setting`. ساختن جدول تازه برای همین الگو یعنی یک منبع حقیقت دوم برای
 * «یک مقدار کوچک برای یک مالک مشخص» — و بعد باید cache، migration و
 * بازیابی را دوباره نوشت.
 *
 * `group` عمداً `plugin:{slug}` است، نه خودِ `slug`:
 *  ۱) اسلاگ افزونه و گروه‌های موجود (`site`, `ui`, `socials`, `layout`) در یک
 *     فضای کلید مشترک‌اند. بدون پیشوند، افزونه‌ای با اسلاگ `ui` تنظیماتش را
 *     روی تنظیمات ظاهری کاربر می‌نوشت.
 *  ۲) پیشوند یک مرز نوشتاری می‌دهد: هر کدی که `Setting::get` می‌زند باید
 *     بداند مالک آن کیست.
 *
 * ## چرا `key = 'global'`
 *
 * تنظیمات افزونه در این نسخه نصب‌محور است نه کاربرمحور — برای همهٔ مدیران یک
 * مقدار، مثل تنظیمات سایت. اگر روزی per-user شد، کافی است کلید از `global` به
 * `user_{id}` عوض شود و بقیهٔ کد دست‌نخورده می‌ماند.
 */
final class PluginSettingsStore
{
    public const GROUP_PREFIX = 'plugin:';

    /** سقف طول slug — هم‌تراز با `string('slug', 100)` در جدول `plugins`. */
    private const SLUG_MAX = 100;

    public static function group(string $slug): string
    {
        return self::GROUP_PREFIX.$slug;
    }

    /**
     * مقادیر ذخیره‌شدهٔ یک افزونه.
     *
     * @return array<string, mixed>
     */
    public static function read(string $slug): array
    {
        $cached = CachedSettings::remember('plugin', self::validSlug($slug), 300, function () use ($slug) {
            $value = Setting::get(self::group($slug), 'global', []);

            return is_array($value) ? $value : [];
        });

        return is_array($cached) ? $cached : [];
    }

    /**
     * ذخیرهٔ مقادیر. ورودی باید **از قبل** اعتبارسنجی شده باشد.
     *
     * @param  array<string, mixed>  $values
     */
    public static function write(string $slug, array $values): void
    {
        $slug = self::validSlug($slug);

        Setting::set(self::group($slug), 'global', $values);
        CachedSettings::forget('plugin', $slug);
    }

    /**
     * اسلاگ باید از شکل مجاز باشد، وگرنه `group` می‌تواند به هر رشته‌ای تبدیل
     * شود و مرز نوشتنی‌ای که بالا توضیح داده شد از بین برود.
     *
     * @throws \InvalidArgumentException
     */
    public static function validSlug(string $slug): string
    {
        if ($slug === '' || strlen($slug) > self::SLUG_MAX) {
            throw new \InvalidArgumentException('اسلاگ افزونه معتبر نیست.');
        }

        if (! preg_match('/^[a-z0-9][a-z0-9._-]*$/', $slug)) {
            throw new \InvalidArgumentException('اسلاگ افزونه معتبر نیست.');
        }

        return $slug;
    }
}
