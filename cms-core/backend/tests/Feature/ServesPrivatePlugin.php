<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Services\Plugins\PluginPackageContract;
use App\Services\Plugins\PluginRouteTable;
use App\Services\Subscription\SubscriptionResolverInterface;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * L-B12 — یک بستهٔ **خصوصی** را در تست‌ها فعال می‌کند.
 *
 * ## چرا این trait لازم شد
 *
 * «اشتراک من» دیگر در core نیست؛ یک افزونهٔ جدا با پوشهٔ خودش و مانیفستِ خودش
 * است. ولی `RefreshDatabase` جدول `plugins` را خالی می‌کند و migrationها در
 * محیط تست اجرا نمی‌شوند (یا اگر اجرا شوند، جدول را از نو می‌سازند بی‌ردیف).
 * پس هر تستی که به افزونهٔ فعال نیاز دارد باید خودش ردیفش را بسازد — وگرنه
 * dispatch ۴۰۴ می‌دهد و تست شکست می‌خورد.
 *
 * ## چرا عمومی و نه یک helper مخصوصِ مرکز
 *
 * هر بستهٔ خصوصیِ دیگری هم همین نیاز را دارد. یک تابعِ عمومی یعنی افزودن
 * افزونهٔ سوم **ویرایش هسته نمی‌خواهد** — همان چیزی که L-B12 برای نگهبانِ نقش
 * و پروکسی انجام داد.
 *
 * @see TestCase::withoutBundledCentralPlugin()
 */
trait ServesPrivatePlugin
{
    /**
     * بستهٔ `slug` را فعال می‌کند، با مانیفستِ واقعی از دیسک اگر باشد.
     *
     * اگر پوشه نباشد (یعنی این repo عمومی است و آن افزونه اینجا نیست)، یک
     * مانیفستِ **حداقلی** می‌سازد تا تست بتواند dispatch را بسنجد بدون اینکه به
     * کدِ خصوصی نیاز داشته باشد.
     *
     * ⭐ پیش از فعال‌سازی، migrationهای خودِ افزونه هم اجرا می‌شوند (اگر بسته
     * پوشه داشته باشد). بدون این، `RefreshDatabase` جدول‌های `subscription_*` را
     * نمی‌سازد و تست با «relation does not exist» می‌میرد — درحالی‌که در محیط
     * واقعی کاملاً سالم است.
     */
    protected function servePrivatePlugin(string $slug, array $routes = []): Plugin
    {
        $dir = $this->privateDir($slug);

        if ($dir !== null) {
            $this->registerQuarantinedPluginAutoload($slug, $dir);
        }

        $this->migratePrivatePlugin($slug);

        $manifest = $this->privateManifest($slug, $routes);

        // J12 — پیش‌فرضِ عملیاتی `NullSubscriptionResolver` است (نسخهٔ رایگان).
        // این تستِ خصوصی عمداً binding را به پیاده‌سازیِ خودِ افزونه وصل می‌کند،
        // چون در این مخزن بسته حاضر است و می‌خواهیم رفتارش را بسنجیم. این دقیقاً
        // همان «فقط binding عوض می‌شود» است که J12 می‌خواست.
        $resolverClass = $manifest['services']['subscription_resolver'] ?? null;

        if (is_string($resolverClass) && $resolverClass !== ''
            && class_exists($resolverClass)
            && is_a($resolverClass, SubscriptionResolverInterface::class, true)) {
            $this->app->bind(SubscriptionResolverInterface::class, fn () => new $resolverClass);
        }

        $plugin = Plugin::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $manifest['name'] ?? $slug,
                'version' => $manifest['version'] ?? '1.0.0',
                'active' => true,
                'system' => false,
                'review_status' => Plugin::REVIEW_APPROVED,
                'source' => Plugin::SOURCE_LOCAL,
                'path' => 'plugins/'.$slug,
                'manifest' => $manifest,
            ],
        );

        // اگر از قبل بود ولی غیرفعال، فعالش کن — تست «اگر نصب است کار می‌کند»
        // نباید به وضعیتِ یک تستِ دیگر وابسته باشد.
        if (! $plugin->active) {
            $plugin->forceFill(['active' => true])->save();
        }

        PluginRouteTable::flush();

        return $plugin;
    }

    /**
     * migrationهای بستهٔ خصوصی را اجرا می‌کند، اگر دارد.
     *
     * ## چرا `artisan migrate` با مسیر دستی
     *
     * مکانیزم خودِ لاراول (`migrations` table + مسیر) را دست‌نخورده می‌گذاریم تا
     * همان رفتاری رخ دهد که در محیط واقعی رخ می‌دهد. `migrate` فایل‌ها را
     * alphabetically می‌خواند، پس ترتیب `تبلیغ ثبت ← ساخت جدول` حفظ می‌شود.
     */
    private function migratePrivatePlugin(string $slug): void
    {
        $dir = $this->privateDir($slug);

        if ($dir === null) {
            return;
        }

        $path = str_replace('\\', '/', substr($dir, strlen(base_path()) + 1)).'/'.PluginPackageContract::BACKEND_ROOT.'/database/migrations';

        if (! is_dir(base_path($path))) {
            return;
        }

        // اگر قبلاً اجرا شده (تست دوم به بعد در همان کلاس) دوباره اجرا نکن —
        // ولی جدول‌ها با `RefreshDatabase` هر بار از نو ساخته می‌شوند، پس
        // اجرای مجدد امن است چون `hasTable` در خودِ migrationها guard دارد.
        Artisan::call('migrate', [
            '--path' => $path,
            '--realpath' => false,
            '--force' => true,
        ]);
    }

    /**
     * J11 — مسیر بستهٔ خصوصی: جای اصلی یا قرنطینه.
     *
     * اگر هیچ‌کدام نبود (repo عمومی بدون بستهٔ خصوصی)، `null` برمی‌گردد و مسیر
     * مانیفستِ حداقلی ادامه پیدا می‌کند.
     */
    private function privateDir(string $slug): ?string
    {
        foreach ([
            base_path('plugins/'.$slug),
            base_path('plugins/_quarantine/'.$slug),
        ] as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function privateManifest(string $slug, array $routes): array
    {
        $dir = $this->privateDir($slug);
        $path = $dir === null ? null : $dir.'/manifest.json';

        if ($path !== null && is_file($path) && is_readable($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);

            if (is_array($decoded)) {
                // مانیفستِ روی دیسک ممکن است routeهایی داشته باشد که تست
                // نمی‌خواهد؛ اگر تست route داده، بر آن غلبه می‌کند.
                if ($routes !== []) {
                    $decoded['api'] = ['prefix' => $slug, 'routes' => $routes];
                }

                return $decoded;
            }
        }

        // بسته غایب است: مانیفستِ حداقلی که فقط برای dispatch کافی است.
        return [
            'name' => $slug,
            'slug' => $slug,
            'version' => '1.0.0',
            'api' => ['prefix' => $slug, 'routes' => $routes],
            'db' => ['tables' => []],
            // ⭐ resolver باید از مانیفست خوانده شود، نه از رشتهٔ ثابت در core.
            // نامِ کلاس در مانیفست است تا هسته هیچ اسمِ خصوصی‌ای نداشته باشد.
            'services' => [
                'subscription_resolver' => 'Pishdad\\Plugins\\'
                    .str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)))
                    .'\\SubscriptionData',
            ],
        ];
    }
}
