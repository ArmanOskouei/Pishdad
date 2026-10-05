<?php

declare(strict_types=1);

namespace App\Services\Subscription;

use App\Models\Setting;
use App\Services\Settings\CachedSettings;

/**
 * متنِ وضعیتِ اشتراک — **مالِ هسته**.
 *
 * ## چرا از `ClientSubscriptionResolver` بیرون آمد
 *
 * این متن هیچ چیزِ افزونه‌ای نمی‌داند: یک جملهٔ فارسی با نامِ سایت، بر اساسِ
 * وضعیت. ولی تا وقتی داخلِ `ClientSubscriptionResolver` بود، هر فایلی که آن
 * متن را می‌خواست ناچار بود همان کلاس را صدا بزند — و آن کلاس `Central\Models\*`
 * را import می‌کرد.
 *
 * یعنی یک متنِ ساده، بارِ یک وابستگیِ سخت را روی همهٔ مصرف‌کننده‌ها می‌گذاشت.
 * حالا متن اینجاست و مصرف‌کنندهٔ واقعی‌اش (`SiteStatusController`) دیگر به
 * افزونه چیزی نمی‌گوید.
 *
 * @see ClientSubscriptionResolver — وضعیت از کجا می‌آید (این متن را می‌سازد)
 * @see SubscriptionSource      — افزونه‌ها از این راه حرف می‌زنند
 */
final class SubscriptionStatusMessage
{
    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    /**
     * متنِ وضعیت. عیناً همان چیزی که `ClientSubscriptionResolver` می‌ساخت.
     *
     * ⚠️ این جمله‌ها **عمداً عوض نشده‌اند**. هرچند برای افزونهٔ اشتراک «فعال
     * است/معلق است» از `SubscriptionData` هم می‌آید، متنِ هسته راهِ خودش را دارد
     * و تست‌های رابط روی همین واژه‌ها حساب می‌کنند.
     *
     * @param  string|null  $brand  نامِ سایت؛ `null` یعنی از تنظیمات بخوان.
     */
    public static function make(string $status, ?string $brand = null): string
    {
        $brand = $brand ?: self::siteBrand();

        if ($status === self::SUSPENDED) {
            return "دسترسی به {$brand} موقتاً معلق شده است. لطفاً با پشتیبانی تماس بگیرید.";
        }

        return "{$brand} فعال است.";
    }

    /**
     * نامِ سایت از تنظیمات، با کشِ کوتاه.
     *
     * ۳۰۰ ثانیه چون این متن در هر بازدیدِ عمومی ساخته می‌شود و نامِ سایت تقریباً
     * هرگز عوض نمی‌شود.
     */
    public static function siteBrand(): string
    {
        $site = CachedSettings::remember('site', 'global', 300, fn () => Setting::get('site', 'global', []));

        return is_array($site) && ! empty($site['title']) ? (string) $site['title'] : 'وب‌سایت ما';
    }
}