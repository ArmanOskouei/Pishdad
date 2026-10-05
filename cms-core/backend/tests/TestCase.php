<?php

namespace Tests;

use App\Models\Plugin;
use App\Services\Plugins\ManifestRegistry;
use App\Services\Plugins\PluginAutoloader;
use App\Services\Plugins\PluginPackageContract;
use App\Services\Plugins\PluginRouteTable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Tests\Feature\CentralPluginRouteTest;
use Tests\Feature\CentralZeroFootprintTest;
use Tests\Feature\PublicInstallWithoutCentralPluginTest;
use Tests\Feature\QuarantinedPluginsInvisibleTest;
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

        // L-B12 — هر تست از **جدول خالی** شروع کند.
        //
        // چرا این پاک‌سازی لازم است: کشِ `plugin.routes` و `plugin.manifests`
        // بین تست‌ها زنده می‌ماند (خواندن از cache ارزان‌تر از DB است و در
        // محیط تست هم همین منطق اجرا می‌شود). پس تستی که بسته‌ای را فعال می‌کند،
        // ردیفش را در تستِ بعدی هم می‌بیند — و تستی مثل
        // `test_an_inactive_plugin_serves_nothing` که انتظار دارد جدول خالی
        // باشد، شکست می‌خورد.
        //
        // این یک نشتِ **بین تستی** است، نه نشتِ کد. دقیقاً همان چیزی که
        // `RefreshDatabase` برای جدول‌ها حل می‌کند و اینجا برای کش.
        PluginRouteTable::flush();
        ManifestRegistry::flushCache();

        if ($this->needsCentralPlugin()) {
            $this->serveCentralPlugin();
        }
    }

    /**
     * J11 — ثبتِ PSR-4 یک بستهٔ قرنطینه‌شده، فقط برای تست‌های خصوصی.
     *
     * هستهٔ اصلی (و بیلدِ رایگان) این را نمی‌کند: `PluginReleaseManager` فقط
     * `plugins/{slug}` را اسکن می‌کند و `_quarantine/` را نمی‌بیند. ولی تست‌های
     * خصوصیِ همین مخزن باید بتوانند مانیفست/کلاس‌های بسته را از قرنطینه بار کنند
     * تا مرز و رفتارش را بسنجند. اگر بسته در جای اصلی باشد (`plugins/{slug}`)،
     * `AppServiceProvider` خودش ثبتش کرده و این متد بی‌اثر است.
     */
    protected function registerQuarantinedPluginAutoload(string $slug, string $dir): void
    {
        $autoloader = app(PluginAutoloader::class);

        if ($autoloader->isRegistered($slug)) {
            return;
        }

        $root = $dir.'/'.PluginPackageContract::BACKEND_ROOT.'/src';

        if (is_dir($root)) {
            $autoloader->register(
                $slug,
                $root,
                PluginAutoloader::NAMESPACE_ROOT.Str::studly($slug).'\\',
            );
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

        $source = (string) file_get_contents($file);

        if (str_contains($source, '/api/v1/p/central')) {
            return true;
        }

        // J5 — دو نشانهٔ دیگر.
        //
        // رشتهٔ مسیر به‌تنهایی کافی نبود. `SiteStatusTest` و `SubscriptionTest`
        // مدل‌های افزونه را import می‌کنند ولی مسیرِ مرکزی را نمی‌زنند (اولی به
        // `/api/v1/site/status` می‌زند). پیش از J5 این بی‌اشکال بود چون جدول‌ها
        // در core بودند؛ بعد از J5 با «relation central_clients does not exist»
        // می‌مردند. یعنی قلاب داشت یک شرطِ ناکافی را تنها شرط می‌دانست.
        //
        // و «نیاز» یعنی «به افزونه اشاره می‌کند»، نه «حتماً باید نصب باشد» — تستی
        // که نبودِ افزونه را می‌سنجد، خودش باید این را **صریح** اعلام کند
        // (`NEVER_NEEDS_CENTRAL`)، چون حدسِ خودکار نمی‌تواند «نصبش نکن» را بفهمد.
        return str_contains($source, 'Pishdad\\Plugins\\Central')
            || preg_match('/\bcentral_[a-z_]+/', $source) === 1;
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

        // J5 — این تست **دقیقاً** ادعا می‌کند نصبِ تازهٔ هسته صفر جدولِ مرکزی
        // دارد. اگر قلابِ خودکار برایش جدول بسازد، تست دروغ می‌گوید و بدتر: سبز
        // می‌ماند در حالی که ادعایی که می‌سنجد نیست. نامِ `central_` در فایلش هست
        // (متنِ تست‌ها و نامِ migrationها) و همین قلابِ تازه آن را می‌گیرد، پس
        // opt-out **الزامی** است نه تزئینی.
        PublicInstallWithoutCentralPluginTest::class,

        // J8 — این تست ادعا می‌کند سطحِ API مرکزی بدون افزونه **غایب** است، پس
        // فعال‌کردن افزونه برایش دقیقاً خلافِ ادعایش است.
        //
        // ⚠️ اینجا `opt-out` تزئینی نیست: نامِ `central_` و مسیرِ `/api/v1/p/central`
        // در متنِ تست هست (نمونه‌ها دقیقاً همان‌هایی‌اند که باید ۴۰۴ بدهند)، پس
        // قلابِ خودکار نامش را می‌گیرد و افزونه را نصب می‌کند. آن‌وقت تست سبز
        // می‌ماند در حالی که **هیچ‌چیز را دربارهٔ نصبِ تازه ثابت نکرده** — بدترین
        // حالتِ ممکن برای یک تستِ اثبات.
        CentralZeroFootprintTest::class,

        // J11 — این تست دقیقاً ثابت می‌کند بسته‌های قرنطینه‌شده نادیدنی‌اند؛ اگر
        // قلابِ خودکار بسته را «وصل» کند، ادعایش را نقض می‌کند.
        QuarantinedPluginsInvisibleTest::class,
    ];
}
