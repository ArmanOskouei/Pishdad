<?php

declare(strict_types=1);

namespace App\Services\Subscription;

use App\Models\Setting;
use App\Services\Settings\CachedSettings;

/**
 * منبعِ پیش‌فرضِ وضعیت — یعنی **نصبِ مشتری بدون پلاگین مرکزی**.
 *
 * ## چرا این وجود دارد و نه فقط یک `if` در resolver
 *
 * چون نبودِ پلاگین مرکزی **حالتِ عادی و پرتکرار** است، نه حالتِ خطا. مشتریِ
 * تازه هیچ پلاگین مرکزی ندارد و نباید هم داشته باشد. پس این یک پیاده‌سازیِ
 * واقعی از `SubscriptionSource` است، نه یک استثنا.
 *
 * ## چه چیزی را برمی‌گرداند
 *
 * `active` همیشه. چون بدون پنلِ مرکزی چیزی وجود ندارد که بتواند اشتراکی را
 * تعلیق کند — تعلیق فقط از راه پنل مرکزی می‌آید. پس «معلق» در نبودِ پنل
 * معنا ندارد، و برگرداندنش دروغ می‌گفت.
 *
 * نکتهٔ ظریف: اگر روزی پلاگین مرکزی **حاضر بود**، `PluginSubscriptionSource`
 * زودتر در زنجیره می‌آید و همین کلاس اصلاً صدا زده نمی‌شود. پس این «پیش‌فرض»
 * بودنش یک تصمیمِ امنیتی نیست — فقط یعنی «وقتی هیچ منبعِ دیگری نیست».
 */
final class NullSubscriptionSource implements SubscriptionSource
{
    /**
     * J5 — سایتِ ناشناخته یعنی «نمی‌دانیم»، نه «فعال».
     *
     * تفاوتش با `status()` عمدی است: آن یکی برای سایتِ عمومی `active` می‌دهد چون
     * بستنِ بی‌دلیلِ سایت بدتر است. این یکی `null` می‌دهد تا مصرف‌کننده بتواند
     * «نصبِ مشترک نیست» را از «فعال» جدا کند.
     */
    public function statusForSite(?string $siteId = null): ?array
    {
        return null;
    }

    /**
     * J5 — بدونِ افزونه، کاربر **کارتِ اشتراک ندارد**.
     *
     * نه `null` کامل و نه یک کارتِ ساختگی: `null` یعنی «این قابلیت برای این
     * کاربر نیست» و داشبورد بخش را پنهان می‌کند — که همان رفتارِ درست است.
     */
    public function forUser(?int $ownerUserId): ?array
    {
        return null;
    }

    public function status(?int $ownerUserId = null): ?array
    {
        // برند از تنظیمات می‌آید تا پیام هم مثل بقیهٔ سایت، نامِ خودِ سایت را
        // بگوید نه یک رشتهٔ ثابت.
        $brand = 'وب‌سایت ما';

        $site = CachedSettings::remember('site', 'global', 300, fn () => Setting::get('site', 'global', []));
        if (is_array($site) && ! empty($site['title'])) {
            $brand = (string) $site['title'];
        }

        return [
            'status' => 'active',
            'message' => "{$brand} فعال است.",
        ];
    }
}
