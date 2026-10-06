<?php

namespace App\Services\Subscription;

use App\Models\User;
use App\Services\Plugins\PluginReleaseManager;
use App\Services\Plugins\ServiceProviderRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * تک‌منبعِ وضعیتِ اشتراک.
 *
 * همهٔ مصرف‌کننده‌ها (داشبورد، `/site/status`، polling قدیمیِ
 * `subscription-status`) از همین‌جا می‌خوانند تا دو منبعِ حقیقت ساخته نشود.
 *
 * ## چرا دیگر کلاس‌های افزونهٔ مرکزی را صدا نمی‌زند — K7.2
 *
 * نسخهٔ قبلی مستقیم مدل‌های پلاگین را صدا می‌زد. با شبیه‌سازیِ یک انتشارِ
 * عمومی (پوشهٔ پلاگین را برداشتم) این نتیجه را داد:
 *
 * ```
 * /api/v1/site/status → 500
 *   Class "…\Subscription" not found
 * ```
 *
 * یعنی هسته بدون پلاگینِ مرکزی **سایت عمومی را می‌شکست**. و چون پلاگینِ مرکزی
 * قرار است خصوصی باشد و در انتشارِ عمومی نباشد، این یعنی هر مشتریِ تازه با
 * سایتِ ۵۰۰ می‌ماند.
 *
 * حالا هسته فقط `SubscriptionSource` را می‌شناسد. دو پیاده‌سازی هست:
 *  - `PluginSubscriptionSource` — وقتی پلاگین مرکزی حاضر است (نصبِ خودمان)
 *  - `NullSubscriptionSource` — نبودِ آن (هر نصبِ مشتری)
 *
 * و اگر افزونهٔ دیگری خواست، همان رابط را پیاده می‌کند و نیازی به دست‌زدن به
 * هسته نیست.
 */
final class ClientSubscriptionResolver
{
    /**
     * زنجیرهٔ منابع، به ترتیبِ اولویت.
     *
     * اولین منبعی که چیزی برگرداند برنده است. ترتیب مهم است: منبعی که افزونه
     * از راه `core.service_provider` (K5.0/K7.3) اعلام کرده **قبل** از افزونهٔ
     * مرکزی است، چون اعلامِ صریحِ یک بستهٔ فعال و تأییدشده از کدِ هاردشدهٔ
     * هسته قوی‌تر است؛ و افزونهٔ مرکزی هم **قبل** از پیش‌فرض، چون اگر پنلِ مرکزی
     * هست و اشتراکی را تعلیق کرده، وضعیتِ واقعی «معلق» است — و پیش‌فرض همیشه
     * `active` می‌گوید.
     *
     * @return list<SubscriptionSource>
     */
    private static function sources(): array
    {
        $sources = [];

        // K7.3 — منبعی که افزونه اعلام کرده. `resolveOverride()` فقط وقتی
        // رشته برمی‌گرداند که بسته هم `active` و هم `approved` باشد و کلاسش واقعاً
        // وجود داشته و رابط را پیاده کند؛ یعنی همین قاعده‌های fail-closedِ
        // `ServiceProviderRegistry` بدون تکرار اعمال می‌شود.
        $declared = self::declaredSourceClass();

        if ($declared !== null) {
            $sources[] = app($declared);
        }

        // ↓ J5 — بسته‌های همراه، خودشان منبع می‌دهند.
        //
        // قبلاً اینجا `new PluginSubscriptionSource` نوشته شده بود با شرطِ
        // `class_exists` روی مدلِ افزونهٔ مرکزی — یعنی هسته می‌دانست افزونهٔ مرکزی
        // چیست و منبعش کجاست. آن شرط تنها چیزی بود که اجازه می‌داد تعلیق از کار
        // بیفتد، و تنها دلیلِ ماندنِ آن فایل در `ALLOWED`.
        //
        // حالا هسته فقط **قاعده** را می‌داند: «هر بستهٔ همراه که کلاسی به نامِ
        // `Subscription\SubscriptionSource` دارد، منبع است». نمی‌داند `central`
        // چیست، نمی‌داند جدول‌هایش چیست، و اگر فردا بستهٔ دیگری هم منبع بدهد،
        // بدونِ یک خط تغییر در هسته کار می‌کند.
        foreach (self::bundledSourceClasses() as $class) {
            $sources[] = new $class;
        }

        $sources[] = new NullSubscriptionSource;

        return $sources;
    }

    /**
     * J5 — منابعی که بسته‌های همراه اعلام کرده‌اند.
     *
     * از روی **دیسک** می‌پرسد (`PluginReleaseManager::sourceSlugs()`)، نه از جدولِ
     * `plugins`. دلیلش همان دلیلی است که `bootBundledPlugins` هم از دیسک
     * بارگذاری می‌کند: بسته‌ای که در دیسک هست و سالم است باید کار کند، حتی اگر
     * هنوز ردیفِ ثبت نداشته باشد. وگرنه مسیرها و فرمان‌ها کار می‌کنند ولی داده
     * نه — و این نیم‌بند از هر خرابیِ کامل بدتر است.
     *
     * @return list<class-string<SubscriptionSource>>
     */
    private static function bundledSourceClasses(): array
    {
        $found = [];

        try {
            $slugs = app(PluginReleaseManager::class)->sourceSlugs();
        } catch (Throwable $e) {
            Log::notice('plugin.bundled_source_lookup_failed', ['error' => $e->getMessage()]);

            return [];
        }

        foreach ($slugs as $slug) {
            $class = 'Pishdad\\Plugins\\'.Str::studly($slug).'\\Subscription\\SubscriptionSource';

            try {
                // `is_subclass_of` یعنی اگر کلاس نیست یا رابط را پیاده نکرده،
                // بی‌صدا رد می‌شود. یک بستهٔ ناقص نباید سایت را ۵۰۰ کند.
                if (class_exists($class) && is_subclass_of($class, SubscriptionSource::class)) {
                    $found[] = $class;
                }
            } catch (Throwable $e) {
                Log::notice('plugin.bundled_source_invalid', [
                    'slug' => $slug,
                    'class' => $class,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $found;
    }

    /**
     * کلاسی که یک افزونه برای `SubscriptionSource` اعلام کرده، یا null.
     *
     * ⛔ **استثنا خورده نمی‌شود، اما گم هم نمی‌شود.** نبودِ جدول یا خطای دیتابیس
     * یعنی «هیچ افزونه‌ای نیست» و همان مسیرِ پیشین ادامه پیدا می‌کند.
     */
    private static function declaredSourceClass(): ?string
    {
        try {
            return app(ServiceProviderRegistry::class)->resolveOverride(SubscriptionSource::class);
        } catch (\Throwable $e) {
            Log::notice('plugin.subscription_source_lookup_failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * وضعیتِ عمومی، از هر منبعی که پاسخ بدهد.
     *
     * @return array{status: string, message: string}
     */
    public static function publicStatus(?int $ownerUserId = null): array
    {
        foreach (self::sources() as $source) {
            $answer = $source->status($ownerUserId);

            if (is_array($answer) && isset($answer['status'], $answer['message'])) {
                return [
                    'status' => (string) $answer['status'],
                    'message' => (string) $answer['message'],
                ];
            }
        }

        // هیچ منبعی جواب نداد. نباید `suspended` باشد — سایتِ سالم نباید به
        // دلیل یک مشکل داخلی به کاربر بگوید «دسترسی‌ات معلق است».
        return ['status' => 'active', 'message' => 'وب‌سایت ما فعال است.'];
    }

    /**
     * اشتراکِ یک `site_id` مشخص، یا قدیمی‌ترینِ نصبِ تکی.
     *
     * فقط وقتی پلاگین مرکزی حاضر باشد؛ در نبودش `null`.
     *
     * دلیل وجودش: `PageController::homepage()` می‌خواهد بداند «آیا این یک نصبِ
     * مشترک است یا نه» تا صفحهٔ خانه را از تنظیماتِ مشترک بخواند یا پیش‌فرض.
     * این یک **تشخیص** است نه نمایشِ وضعیت — پس از رابطِ `SubscriptionSource`
     * نمی‌آید چون آن رابط عمداً فقط دو چیز برمی‌گرداند.
     */
    public static function forSiteId(?string $siteId): ?object
    {
        // J5 — این متد دیگر کوئری نمی‌زند.
        //
        // پیش‌تر `Subscription::query()->where('site_id', …)` می‌زد و وجودِ جدولِ
        // افزونه را چک می‌کرد: یعنی هسته هم **نامِ جدولِ افزونه** را می‌دانست و هم
        // نبودنش را فرض می‌کرد. با افزونه‌ای که جدولش جای دیگری باشد، همان guard
        // دروغ می‌گفت.
        //
        // ⚠️ این یک پرسشِ **تشخیصی** است، نه نمایشی: «آیا این یک نصبِ *مشترک*
        // است؟» برای `PageController::homepage()`.
        //
        // و از `SubscriptionResolver` **نمی‌آید** — عمداً. آن resolver داده را از
        // افزونهٔ *اشتراک* می‌خواند که جدول‌های خودش را دارد، و «نامعلوم» را
        // `active` جواب می‌دهد چون سایتِ عمومی نباید بی‌دلیل بسته شود. اینجا
        // «نمی‌دانیم» لازم است، نه «فعال».
        //
        // پس از زنجیرهٔ منبع می‌آید: هر بسته‌ای که `SubscriptionSource` دارد خودش
        // دادهٔ خودش را می‌دهد، و نبودِ منبع یعنی «نصبِ مشترک نیست».
        foreach (self::sources() as $source) {
            if ($source instanceof NullSubscriptionSource) {
                continue;
            }

            $answer = $source->statusForSite($siteId);

            if (! is_array($answer) || ! isset($answer['status'])) {
                continue;
            }

            return (object) [
                'status' => (string) $answer['status'],
                'due_at' => null,
            ];
        }

        return null;
    }

    /**
     * آیا منبع واقعی اشتراک در این نصب موجود است؟
     *
     * تفاوت کلیدی با `forSiteId()`: این متد نمی‌گوید «رکوردی پیدا شد یا نه» — می‌گوید
     * «آیا اصلاً سرویس اشتراکی نصب است». فراخوان باید از این دو برای تشخیص
     * «سایت ناشناست» (۴۰۴) در برابر «افزونهٔ اشتراک نصب نیست» (سایت عمومی آزاد) استفاده کند.
     */
public static function isAvailable(): bool
    {
        // J11 — یک افزونه می‌تواند منبع را به **دو** شکل اعلام کند: صریح از راه
        // `core.service_provider` (`declaredSourceClass`) یا با قراردادِ
        // نام‌گذاریِ بستهٔ همراه (`bundledSourceClasses`). نسخهٔ قبل فقط دومی را
        // می‌سنجید؛ با قرنطینه‌شدنِ بستهٔ همراه، حالتِ اول دست‌نخورده می‌ماند.
        return self::declaredSourceClass() !== null || self::bundledSourceClasses() !== [];
    }

    /**
     * وضعیت عمومی برای یک subscription مشخص.
     *
     * `$subscription === null` یعنی «افزونهٔ اشتراک نصب نیست» — در آن حالت سایت
     * عمومی محدودیتی ندارد و «فعال» امن‌ترین پاسخ است.
     */
    public static function statusFor(?object $subscription): array
    {
        if ($subscription === null) {
            return self::publicStatus();
        }

        $status = in_array(
            (string) $subscription->status,
            ['suspended', 'deleted'],
            true,
        ) ? 'suspended' : 'active';

        return [
            'status' => $status,
            'message' => self::statusMessage($status),
        ];
    }

    /**
     * J5 — کارتِ اشتراکِ یک کاربر، از زنجیرهٔ منبع.
     *
     * @return array{
     *     tier: string|null,
     *     status: string|null,
     *     due_at: string|null,
     *     days_remaining: int|null,
     *     overdue_invoice_count: int,
     *     is_due_soon: bool
     * }|null
     */
    public static function subscriptionCard(?int $ownerUserId): ?array
    {
        foreach (self::sources() as $source) {
            if ($source instanceof NullSubscriptionSource) {
                continue;
            }

            $card = $source->forUser($ownerUserId);

            if (is_array($card)) {
                return $card;
            }
        }

        return null;
    }

    /**
     * ردیف مشتری + اشتراک کاربر جاری.
     *
     * @return array{0: mixed, 1: mixed}|null
     */
    public static function forUser(User $user): ?array
    {
        // J5 — دیگر `firstOrCreate` روی جدول‌های افزونه نمی‌کنیم.
        //
        // نسخهٔ قبل در همین‌جا دو کار می‌کرد: هم می‌خواند، هم *می‌ساخت*. ساختن یعنی
        // هسته جدولِ افزونه را در خواستهٔ خودش تغییر می‌داد — و هر نصبِ تازه با
        // اولین بازدیدِ داشبورد دو ردیف در دیتابیسِ افزونه پیدا می‌کرد.
        //
        // حالا منبعِ داده خودش تصمیم می‌گیرد چه کند. شکلِ خروجی حفظ شد چون
        // `MediaController` هنوز `[0]` و `[1]` را می‌خواند.
        $card = self::subscriptionCard($user->id);

        if ($card === null) {
            return null;
        }

        $client = (object) ['tier' => $card['tier'] ?? null];
        $subscription = (object) [
            'status' => $card['status'] ?? null,
            'due_at' => $card['due_at'] ?? null,
        ];

        return [$client, $subscription];
    }

    /**
     * پیامِ برندِ وضعیت، مستقل از منبع.
     *
     * J5 — بدنه به `SubscriptionStatusMessage` واگذار شد. این متن هیچ چیزِ
     * افزونه‌ای نمی‌داند، ولی تا وقتی اینجا بود، هر مصرف‌کننده‌اش ناچار بود
     * کلاسی را صدا بزند که مدل‌های افزونه را import می‌کرد.
     *
     * متد نگه داشته شده چون `PluginSubscriptionSource` و مصرف‌کننده‌های بیرونی
     * هنوز این مسیر را می‌شناسند.
     *
     * @deprecated مستقیم `SubscriptionStatusMessage::make()` را صدا بزن.
     */
    public static function statusMessage(string $status, ?string $brand = null): string
    {
        return SubscriptionStatusMessage::make($status, $brand);
    }

    private static function siteBrand(): string
    {
        return SubscriptionStatusMessage::siteBrand();
    }
}
