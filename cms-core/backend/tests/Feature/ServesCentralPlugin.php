<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Services\Plugins\PluginMigrationRunner;
use App\Services\Plugins\PluginPackageContract;
use App\Services\Plugins\PluginRouteTable;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * K7.8 — ابزارهای مشترک تست‌های افزونهٔ مرکزی.
 *
 * ## چرا trait
 *
 * هر تستِ مرکزی به سه چیز نیاز دارد: افزونه در جدول باشد، جدول route پاک
 * شود، و مسیر درست از مانیفست خوانده شود. این‌ها قبلاً در هر فایل تکرار
 * می‌شدند — ۲۲ نسخهٔ یکسان که هیچ‌کدام دیده نمی‌شوند.
 *
 * ## چرا trait هیچ `setUp` ندارد
 *
 * قلاب در `Tests\TestCase::setUp()` است و **شرطی** اجرا می‌شود: فقط اگر متنِ
 * فایل تست رشتهٔ `/api/v1/p/central` را داشته باشد. اگر اینجا `setUp` می‌بود،
 * trait با `setUp` خودِ تست تداخل می‌کرد (`Cannot redeclare setUp`) — همان
 * خطایی که `PluginsTest` گرفت.
 *
 * @see TestCase::setUp()
 */
trait ServesCentralPlugin
{
    private ?Plugin $centralPluginRow = null;

    private bool $centralTablesInstalled = false;

    protected function serveCentralPlugin(): Plugin
    {
        // J11 — بستهٔ مرکزی حالا زیر `plugins/_quarantine/` قرنطینه است. هستهٔ
        // اصلی آن را نمی‌بیند (اسکن فقط `plugins/{slug}` را می‌خواند)؛ ولی تست‌های
        // خصوصیِ خودمان باید بتوانند مانیفست/مهاجرت/کلاس‌هایش را از همان‌جا
        // بارگذاری کنند تا مرز و رفتارش را بسنجند.
        $this->registerQuarantinedPluginAutoload('central', $this->centralDir());

        // J11 — بستهٔ مرکزی معمولاً منبعِ وضعیتِ اشتراک را با **قراردادِ نام‌گذاری**
        // (`bundledSourceClasses()`) اعلام می‌کرد که روی دیسکِ `plugins/` اسکن
        // می‌کند. حالا که قرنطینه است، صریحاً در مانیفستِ ردیف اعلامش می‌کنیم تا
        // `ClientSubscriptionResolver::declaredSourceClass()` پیدایش کند.
        $manifest = $this->centralManifest();
        $manifest['panel']['extensions'] = array_merge(
            $manifest['panel']['extensions'] ?? [],
            [[
                'point' => 'core.service_provider',
                'interface' => \App\Services\Subscription\SubscriptionSource::class,
                'class' => 'Pishdad\\Plugins\\Central\\Subscription\\SubscriptionSource',
            ]],
        );

        $plugin = Plugin::query()->firstOrCreate(
            ['slug' => 'central'],
            [
                'name' => 'مرکز',
                'version' => '1.0.0',
                'active' => true,
                'system' => true,
                'review_status' => Plugin::REVIEW_APPROVED,
                'source' => Plugin::SOURCE_CORE,
                'path' => 'plugins/central',
                'manifest' => $manifest,
            ],
        );

        $this->centralPluginRow = $plugin;

        PluginRouteTable::flush();

        // J5 — جدول‌های `central_*` دیگر در core نیستند، پس بدون این خط
        // `setUp` هر تستِ مرکزی روی schemaای اجرا می‌شد که اصلاً جدول ندارد.
        $this->installCentralPluginTables();

        return $plugin;
    }

    /**
     * ⭐ ساختِ جدول‌های افزونهٔ مرکزی، از **بستهٔ واقعی روی دیسک**.
     *
     * ## چرا `PluginMigrator` را صدا نمی‌زنیم
     *
     * `PluginMigrator` روی اتصالِ جدا (`plugin_ddl`) کار می‌کند و تراکنشش را
     * خودش `commit` می‌کند. یعنی DDLاش **بیرون** از تراکنشِ `RefreshDatabase`
     * می‌افتد و بعد از هر تست در `cms_test` باقی می‌ماند. آن وقت:
     *
     *  · `PluginBoundaryTableSweepTest` و تست‌های مرکزی از تست‌های قبلی ارث
     *    می‌برند و «جدول ساخته شد» دیگر چیزی را نمی‌سنجد؛
     *  · `PublicInstallWithoutCentralPluginTest` — که ادعای «نصبِ تازه صفر
     *    جدولِ مرکزی دارد» را می‌سنجد — قرمز می‌شد، در حالی که install سالم بود.
     *
     * اینجا DDL روی اتصالِ **پیش‌فرض** و درونِ تراکنشِ تست اجرا می‌شود، پس
     * `RefreshDatabase` آن را برمی‌گرداند و هر تست از صفر شروع می‌شود.
     *
     * ## چرا `PluginMigrationRunner` را صدا می‌زنیم
     *
     * چون **بازنویسی** باید واقعاً راستی‌آزمایی شود. جدول‌های افزونه با نامِ خام
     * در migration نوشته شده‌اند و `central_` را هسته اضافه می‌کند. اگر اینجا
     * فایل را خام `require` می‌کردیم، جدول‌ها بی‌صدا `clients` می‌شدند و تست‌ها
     * سبز می‌ماندند در حالی که مسیرِ واقعیِ فعال‌سازی می‌شکست. `verify()` +
     * `rewrite()` همان دو کاری است که `PluginMigrator` می‌کند.
     *
     * @see \App\Services\Plugins\PluginMigrator::run()
     */
    protected function installCentralPluginTables(): void
    {
        if ($this->centralTablesInstalled) {
            return;
        }

        $this->centralTablesInstalled = true;

        $runner = new PluginMigrationRunner;
        $declared = $this->centralDeclaredTables();
        $dir = $this->centralDir().'/'.PluginPackageContract::BACKEND_ROOT.'/database/migrations';

        $staged = sys_get_temp_dir().'/pishdad-central-test-'.getmypid();
        @mkdir($staged, 0o700, true);

        try {
            foreach ($this->centralMigrationFiles() as $file) {
                $source = (string) file_get_contents($dir.'/'.$file);

                $errors = $runner->verify($source, $declared);

                if ($errors !== []) {
                    $this->fail(
                        'migration «'.$file.'» بستهٔ مرکزی رد شد: '.implode('، ', $errors)
                    );
                }

                $path = $staged.'/'.$file;

                file_put_contents($path, $runner->rewrite($source, 'central'));

                $migration = require $path;

                if (! $migration instanceof \Illuminate\Database\Migrations\Migration) {
                    $this->fail('migration «'.$file.'» شیء Migration برنگرداند.');
                }

                $migration->up();
            }
        } finally {
            foreach (glob($staged.'/*') ?: [] as $leftover) {
                @unlink($leftover);
            }

            @rmdir($staged);
        }
    }

    /**
     * نام‌های خام از `db.tables` — همان چیزی که `PluginMigrator` می‌خواند.
     *
     * ⚠️ عمداً **همان** تجزیه‌گر `PluginMigrator::declaredTables()` را داریم، نه
     * یک نسخهٔ ساده‌تر: آن یکی فقط آبجکتِ `['name' => …]` را می‌خواند و رشتهٔ
     * خام را **نادیده** می‌گیرد. یعنی یک مانیفست با `["central_clients"]` (مثل
     * آنچه قبل از J5 در دیسک بود) صفر جدول اعلام می‌کرد و بی‌صدا از guard
     * فعال‌سازی رد می‌شد. اگر اینجا ساده‌تر بود، تست‌ها با همان باگ سبز می‌شدند.
     *
     * @return list<string>
     */
    private function centralDeclaredTables(): array
    {
        $tables = $this->centralManifest()['db']['tables'] ?? [];

        if (! is_array($tables)) {
            return [];
        }

        $names = [];

        foreach ($tables as $entry) {
            if (is_array($entry) && is_string($entry['name'] ?? null)) {
                $names[] = $entry['name'];
            }
        }

        return $names;
    }

    /** @return list<string> نام فایل، به ترتیبِ اجرا */
    private function centralMigrationFiles(): array
    {
        $files = glob($this->centralDir().'/'.PluginPackageContract::BACKEND_ROOT.'/database/migrations/*.php') ?: [];

        sort($files, SORT_STRING);

        return array_map('basename', $files);
    }

    /**
     * مانیفست از **بستهٔ واقعی روی دیسک** خوانده می‌شود، نه از آرایهٔ دستی.
     *
     * آرایهٔ دستی یعنی تست‌ها به یک نسخهٔ کهنهٔ ۶۳ مسیر می‌چسبند و تغییرِ
     * manifest هیچ تستی را نمی‌شکند — همان «نگهبانی که چیزی را تضمین نمی‌کند»
     * که K7.8 در `CentralBoundaryTest` پیدا کرد.
     */
    private function centralManifest(): array
    {
        $path = $this->centralDir().'/manifest.json';

        if (! is_file($path)) {
            throw new \RuntimeException("بستهٔ مرکزی روی دیسک نیست: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('مانیفست بستهٔ مرکزی JSON معتبر نیست.');
        }

        return $decoded;
    }

    /**
     * J11 — ریشهٔ بستهٔ مرکزی روی دیسک، چه در جای اصلی و چه در قرنطینه.
     *
     * ترتیبِ اولویت: اگر کسی بسته را برگرداند (`plugins/central`)، همان؛ وگرنه
     * مسیر قرنطینه. این باعث می‌شود restore و quarantine هر دو کار کنند و تست به
     * مکانِ فیزیکی گره نخورد.
     */
    protected function centralDir(): string
    {
        foreach ([
            base_path('plugins/central'),
            base_path('plugins/_quarantine/central'),
        ] as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }

        throw new \RuntimeException('پوشهٔ بستهٔ مرکزی نه در plugins/central است و نه در plugins/_quarantine/central.');
    }

    /**
     * مسیر API مرکزی، از **مانیفست**.
     *
     * اگر prefix عوض شود ولی تست‌ها رشتهٔ ثابت داشته باشند، همه سبز می‌مانند و
     * هیچ‌کدام مسیر واقعی را نمی‌زنند.
     */
    protected function centralUrl(string $path = ''): string
    {
        $prefix = Str::slug($this->centralManifest()['api']['prefix'] ?? 'central');
        $path = ltrim($path, '/');

        return '/api/v1/p/'.$prefix.($path === '' ? '' : '/'.$path);
    }
}
