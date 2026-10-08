<?php

namespace App\Search;

use App\Services\Plugins\ServiceProviderRegistry;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/**
 * ⭐ I7 — رجیستری زندهٔ providerهای جستجو.
 *
 * ## چرا دیگر `config('search.providers')` کافی نیست
 *
 * آن کانفیگ **ایستا** است: فقط در زمان build/deploy خوانده می‌شود و نصب یا
 * ارتقای یک افزونه هیچ اثری روی آن ندارد. یعنی برای هر دامنهٔ تازه باید فایل
 * کانفیگ را دستی ویرایش کرد و ایمیل شد — یعنی عملاً افزونه نمی‌تواند دامنهٔ
 * جستجوی خودش را اضافه کند و تنها راه، «هسته را برایم عوض کنید» است.
 *
 * بدتر: همان کانفیگ یک **hole** در برابر پلاگین بود. نسخهٔ قبلی B5 ثبت سمت
 * مرورگر را حذف کرد، ولی جای خالی را با یک لیست ایستا پر کرد؛ یعنی «افزونه
 * ثبت می‌شود» فقط وقتی کسی فایل کانفیگ هسته را دستی عوض کند.
 *
 * ## اینجا چه چیزی پویاست
 *
 * سه منبع، به این ترتیبِ **حقوقی**:
 *
 *  ۱. `register()` — ثبت در زمان اجرا. هر provider خودش (یا service provider
 *     افزونه) خودش را صدا می‌زند. این تنها راهی است که واقعاً «پویا» است.
 *  ۲. اعلان مانیفست: `panel.extensions` با `point === 'core.service_provider'`
 *     و `interface === SearchableProvider::class` — از راه
 *     `ServiceProviderRegistry`، یعنی با همان قاعدهٔ سخت‌گیرانهٔ «فعال **و**
 *     تأییدشده» و همان بررسی ریشهٔ اجباریِ `Pishdad\Plugins\`.
 *  ۳. `config('search.providers')` / `config('search.admin_providers')` —
 *     providerهای خودِ هسته. اینها پیش‌فرض‌اند، نه منبعِ حقیقت.
 *
 * ## ⭐ چرا برای جستو **افزایشی** است، نه جایگزینی
 *
 * `ServiceProviderRegistry` برای درگاه پرداخت و چابکان *override* است: یکی
 * بیاید جایگزینِ هسته شود. برای جستو این غلط است — دو provider باید **با هم**
 * اجرا شوند و نتیجه‌ها ادغام شوند. پس اعلانِ `SearchableProvider` در این
 * رجیستری به یک فهرست اضافه می‌شود، نه یک تک‌خانه. همین رجیستری اگر روزی
 * جستو را «یکی برنده شود» تعریف کند، افزونهٔ دوم بی‌سروصدا حذف می‌شود؛
 * هر رد ساخته‌شدن provider در `providers()` لاگ می‌شود.
 *
 * ## fail-soft، ولی نه بی‌صدا
 *
 * provider خراب (کلاس نیست، رابط را پیاده نکرد، constructor شکست خورد) هرگز
 * جستو را نمی‌شکند — ولی لاگ می‌شود. «افزونه نصب شد و جستو کار نمی‌کند» بدترین
 * حالت است چون نویسنده هیچ پیامی نمی‌بیند.
 */
final class SearchProviderRegistry
{
    /** کانال عمومی سایت: بازدیدکننده، بدون احراز هویت. */
    public const CHANNEL_SITE = 'site';

    /** کانال پنل: احراز هویت و پرمیشن لازم دارد. */
    public const CHANNEL_ADMIN = 'admin';

    /**
     * ثبت‌های زمان اجرا.
     *
     * ایستا است چون providerها در `boot`/`register` هر request ثبت می‌شوند و
     * باید بین درخواست‌ها بمانند (وگرنه هر جستو همه را دوباره می‌ساخت).
     *
     * @var array<string, array<string, class-string>> channel => slug|class => class
     */
    private static array $runtime = [];

    public function __construct(
        private readonly Container $app,
        private readonly ServiceProviderRegistry $serviceProviders,
    ) {}

    /**
     * ثبت provider از زمان اجرا — راه پویا.
     *
     * کلاس بارگذاری نمی‌شود تا لحظهٔ استفاده، چون همین ثبت در `register()`
     * اتفاق می‌افتد و آن‌وقت کلاس افزونه ممکن است هنوز PSR-4 بارگذاری
     * نشده باشد (K5.3). بررسی‌ها در `providers()` انجام می‌شود.
     */
    public function register(string $class, string $channel = self::CHANNEL_ADMIN, string $key = ''): void
    {
        if (! is_a($class, SearchableProvider::class, true)) {
            Log::warning('search.provider.register_rejected', [
                'class' => $class,
                'reason' => 'رابط SearchableProvider را پیاده نمی‌کند.',
            ]);

            return;
        }

        // کلید پایدار: کلاس. `$slug` فقط برای خوانایی لاگ و تشخیص «دو بار
        // ثبت شد» است؛ برخورد با کلید یکسان یعنی ثبت دوبارهٔ همان provider.
        self::$runtime[$channel][$key !== '' ? $key : $class] = $class;
    }

    /**
     * providerهای اعلام‌شدهٔ افزونه‌ها — فقط فعال و تأییدشده.
     *
     * @return list<class-string>
     */
    public function pluginProviders(): array
    {
        try {
            return $this->serviceProviders->declaredSearchProviders();
        } catch (\Throwable $e) {
            // نبودِ جدول/مهاجرت‌نرفته = «افزونه‌ای نیست»، نه خطا.
            Log::notice('search.provider.registry_unavailable', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * providerهای خودِ هسته از کانفیگ (پیش‌فرض، نه منبعِ حقیقت).
     *
     * @return list<class-string>
     */
    public function coreProviders(string $channel): array
    {
        $key = $channel === self::CHANNEL_SITE ? 'providers' : 'admin_providers';

        return array_values(array_filter(
            (array) config('search.'.$key, []),
            fn ($class) => is_string($class) && is_a($class, SearchableProvider::class, true)
        ));
    }

    /**
     * providerهای زندهٔ یک کانال — هسته، بعد ثبت زمان اجرا، بعد افزونه.
     *
     * ترتیب فقط روی «کدام اول ساخته شود» اثر دارد؛ `SearchManager` و
     * `AdminSearchController` هر دو خروجی را با `updated_at` نزولی مرتب
     * می‌کنند، پس ترتیب ثبت به کیفیت نتیجه دست نمی‌زند.
     *
     * @return list<SearchableProvider>
     */
    public function providers(string $channel = self::CHANNEL_SITE): array
    {
        $classes = array_merge(
            $this->coreProviders($channel),
            array_values(self::$runtime[$channel] ?? []),
            $this->pluginProviders(),
        );

        $out = [];
        foreach (array_unique($classes) as $class) {
            $provider = $this->make($class);

            if ($provider !== null) {
                $out[] = $provider;
            }
        }

        return $out;
    }

    /**
     * پاک‌سازی ثبت‌های زمان اجرا — فقط برای تست و برای مسیرهایی که رجیستری را
     * از نو می‌سازند. اعلان‌های مانیفست دست‌نخورده می‌مانند.
     */
    public static function flushRuntime(): void
    {
        self::$runtime = [];
    }

    private function make(string $class): ?SearchableProvider
    {
        try {
            $provider = $this->app->make($class);
        } catch (\Throwable $e) {
            Log::error('search.provider.instantiation_failed', [
                'class' => $class,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $provider instanceof SearchableProvider) {
            Log::error('search.provider.not_a_provider', ['class' => $class]);

            return null;
        }

        return $provider;
    }
}