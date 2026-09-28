<?php

namespace Tests;

use App\Models\Plugin;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Feature\CentralPluginRouteTest;
use Tests\Feature\ServesCentralPlugin;

abstract class TestCase extends BaseTestCase
{
    // ابزارِ افزونهٔ مرکزی اینجا در دسترسِ همهٔ تست‌هاست. خودِ trait هیچ
    // `setUp` ندارد؛ قلاب در `setUp()` پایین است و شرطی اجرا می‌شود.
    use ServesCentralPlugin;

    /**
     * K7.8 — فعال‌سازی افزونهٔ مرکزی، خودکار برای هر تستی که به آن نیاز دارد.
     *
     * ## چرا اینجا و نه در هر فایل تست
     *
     * ۲۲ فایلِ تستِ مرکزی به افزونه نیاز دارند و همه باید کاری بکنند: افزونه در
     * جدول باشد و جدول route پاک شود. اگر این در خودِ هر فایل بود، ۲۲ نسخهٔ
     * یکسان داشتیم که هیچ‌کدام دیده نمی‌شوند.
     *
     * اولین تلاش دقیقاً همین را ساخت و **۱۹ فایل از ۲۲ فایل اصلاً `setUp`
     * نداشتند** — یعنی ۱۹ جای ممکن برای یک فراخوانی که جا می‌افتاد، و هر
     * فراخوانیِ جاافتاده یعنی تستی که سبز است ولی مسیرِ افزونه را نمی‌زند.
     *
     * اینجا یک شرطِ صریح داریم: **فقط اگر آن فایل واقعاً مسیرِ افزونه را صدا
     * بزند**. نه حدس، نه قرارداد ضمنی — متنِ همان فایل نگاه می‌شود.
     *
     * ⚠️ همین شرط یک نقطهٔ شکننده است و عمداً این‌جا است: اگر روزی مسیر
     * افزونه‌ای از یک helper ساخته شود و رشتهٔ `/api/v1/p/` در فایل نباشد،
     * افزونه فعال نمی‌شود. به همین دلیل `assertServesCentralPlugin()` هم هست
     * که تست بتواند خودش را وارسی کند.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->needsCentralPlugin()) {
            $this->serveCentralPlugin();
        }
    }

    /**
     * آیا این تست خاص واقعاً به افزونهٔ مرکزی نیاز دارد؟
     *
     * با متنِ همین فایل تصمیم می‌گیرد، نه با اینکه trait استفاده کرده باشد —
     * چون یک تست ممکن است trait را داشته باشد و باز هم مسیرش اشتباه باشد
     * (مثلاً یک تستِ قدیمی که مسیرش دستی عوض نشده).
     */
    /**
     * رکوردِ بستهٔ مرکزی را از جدول `plugins` برمی‌دارد.
     *
     * ## چرا لازم است
     *
     * از K7.8 یک migration هست که `central` را در جدول `plugins` ثبت می‌کند
     * (`2026_10_08_000001_register_bundled_central_plugin`). پس هر تستی که
     * **کل جدول** را می‌سنجد — مثل `assertDatabaseCount('plugins', 0)` یا
     * «فقط `demo` بماند» — یک ردیفِ اضافه می‌بیند.
     *
     * این یک تصمیم است نه یک مانع: آن migration **باید** در هر نصب اجرا شود،
     * چون نبودِ ردیف یعنی پنل مرکزی ۴۰۴ می‌دهد و heartbeat نصب‌ها هم می‌میرند.
     * پس راه درست پاک‌کردنِ موقت در تست است، نه برگرداندنِ migration.
     *
     * ⚠️ صدا زدنش **قبل** از `parent::setUp()` ممنوع است: `RefreshDatabase`
     * بعد از هر تست تراکنش را برمی‌گرداند و ردیفی که قبل از آن حذف شده باشد
     * دوباره برمی‌گردد.
     */
    protected function withoutBundledCentralPlugin(): static
    {
        Plugin::query()->where('slug', 'central')->delete();

        return $this;
    }

    /**
     * تست‌هایی که **فقط** کلید داشته باشد.
     *
     * با ۹۳ ردیف در جدول، «همه چیز پاک شد» و «فقط `demo` باقی ماند» دو
     * assertion متفاوت‌اند و این دومی قبلاً شکست می‌خورد.
     *
     * @return list<string>
     */
    protected function pluginSlugs(): array
    {
        return Plugin::query()
            ->orderBy('slug')
            ->pluck('slug')
            ->map(static fn (mixed $s): string => (string) $s)
            ->all();
    }

    private function needsCentralPlugin(): bool
    {
        if (in_array(static::class, self::NEVER_NEEDS_CENTRAL, true)) {
            return false;
        }

        $file = (new \ReflectionClass($this))->getFileName();

        if ($file === false || ! is_file($file)) {
            return false;
        }

        return str_contains((string) file_get_contents($file), '/api/v1/p/central');
    }

    /**
     * تست‌هایی که رشتهٔ مسیر دارند ولی **عمداً** افزونه را نمی‌خواهند.
     *
     * `CentralPluginRouteTest` خودش افزونه را با مانیفست کوچکِ خودش
     * می‌سازد تا dispatch را جداگانه بسنجد؛ اگر اینجا هم فعالش می‌کردیم،
     * `firstOrCreate` رکوردِ خودش را پیدا می‌کرد و تست دیگر چیزی نمی‌سنجید.
     */
    private const NEVER_NEEDS_CENTRAL = [
        CentralPluginRouteTest::class,
    ];
}
