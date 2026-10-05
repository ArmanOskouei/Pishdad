<?php

namespace Tests\Feature;

use App\Services\Plugins\PluginAutoloader;
use App\Services\Plugins\PluginLifecycle;
use App\Services\Plugins\PluginPackageContract;
use App\Services\Plugins\PluginReleaseManager;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * K5.0-W — چرخهٔ عمرِ بارگذارِ کلاس.
 *
 * ## چرا این تست جدا از `PluginsTest` است
 *
 * `PluginsTest` مسیر HTTP را می‌سنجد: آپلود → تأیید → فعال‌سازی. ولی «کلاسِ
 * افزونه دیگر resolve نمی‌شود» اصلاً در آن دیده نمی‌شود، چون هیچ‌وقت یک **کلاسِ
 * واقعی** داخل بسته نمی‌گذارد — فقط یک stub بی‌کلاس.
 *
 * اینجا برعکس: رفتارِ بارگذاری موضوع است، پس کلاس واقعی ساخته و واقعاً include
 * می‌شود، از روی **بستهٔ واقعی** روی دیسک (نه مسیر تزریق‌شده).
 *
 * ## چرا namespace یکتا
 *
 * PHP کلاسِ load‌شده را از حافظه خارج نمی‌کند. اگر دو تست همین کلاس را load
 * کنند، تست دوم بدون هیچ ثبتی `class_exists() === true` می‌گیرد و سبز می‌ماند
 * در حالی که بارگذار خراب است. namespace یکتا این را غیرممکن می‌کند — همان
 * قاعده‌ای که `PluginAutoloaderTest` دارد.
 */
class PluginLifecycleTest extends TestCase
{
    private PluginAutoloader $loader;

    private PluginLifecycle $lifecycle;

    private string $token;

    private string $base;

    private string $psr4;

    private string $slug;

    private string $namespace;

    protected function setUp(): void
    {
        parent::setUp();

        // حرفِ اول `x` است: `Str::studly()` روی segmentِ شروع‌شونده با رقم یک
        // identifier نمی‌سازد و `normalizePrefix()` آن را رد می‌کند.
        $this->token = 'x'.bin2hex(random_bytes(5));

        $this->base = storage_path('framework/testing/plugin-lifecycle/'.$this->token);
        $this->slug = 'lifecycle-probe-'.$this->token;
        $this->namespace = PluginAutoloader::NAMESPACE_ROOT.Str::studly($this->slug);

        // بستهٔ واقعی: یک نسخهٔ استخراج‌شده با اشاره‌گرِ فعال.
        //
        // نامِ پوشهٔ نسخه را خودِ `releaseLabel()` می‌سازد (`version-hash8`)،
        // پس اینجا همان را صدا می‌زنیم وگرنه `activate()` نسخه را «ناموجود»
        // اعلام می‌کند.
        $hash = str_repeat('a', 64);

        $label = (new PluginReleaseManager($this->base))->releaseLabel('1.0.0', $hash);
        $release = $this->base.'/'.$this->slug.'/releases/'.$label;
        $this->psr4 = $release.'/'.PluginReleaseManager::PSR4_SUBPATH;

        mkdir($this->psr4, 0777, true);

        file_put_contents(
            $release.'/'.PluginPackageContract::MANIFEST,
            json_encode(['name' => 'پروب', 'slug' => $this->slug, 'version' => '1.0.0'], JSON_UNESCAPED_UNICODE)
        );

        $this->writeClass('Svc');

        $this->loader = new PluginAutoloader;
        $this->lifecycle = new PluginLifecycle($this->loader, $this->installedReleases($hash));
    }

    protected function tearDown(): void
    {
        foreach (array_keys($this->loader->registeredSlugs()) as $slug) {
            $this->loader->unregister($slug);
        }

        $this->removeTree($this->base);

        parent::tearDown();
    }

    // ── رفتار ─────────────────────────────────────────────────────────────

    /**
     * مسیر اصلیِ ردیف: فعال ⇒ کلاس‌ها resolve می‌شوند، غیرفعال ⇒ دیگر نمی‌شوند.
     */
    public function test_a_deactivated_plugin_stops_resolving_its_classes(): void
    {
        $this->assertFalse(
            class_exists($this->namespace.'\\Svc'),
            'پیش از فعال‌سازی، کلاسِ افزونه نباید اصلاً در دسترس باشد.'
        );

        $this->assertTrue($this->lifecycle->activate($this->slug), 'بسته روی دیسک است، پس باید ریشه‌ای پیدا شود.');
        $this->assertTrue($this->loader->isRegistered($this->slug));

        $this->assertTrue(
            class_exists($this->namespace.'\\Svc'),
            'فعال‌سازی باید ریشهٔ PSR-4 نسخهٔ فعال را ثبت کند.'
        );

        $this->assertSame(
            PluginAutoloader::NAMESPACE_ROOT.Str::studly($this->slug).'\\',
            $this->loader->registeredSlugs()[$this->slug],
            'پیشوند باید از slug مشتق شود، نه از ورودیِ caller — وگرنه دو نفر برای یک بسته دو namespace می‌سازند.'
        );

        // کلاسِ بالا الان در حافظه است و PHP نمی‌تواند آن را بیرون بیندازد. پس یک
        // کلاسِ **دیگر** از همان بسته می‌آید تا چیزی که می‌سنجیم واقعاً
        // «resolve شدن» باشد، نه «از قبل در حافظه بود».
        $this->writeClass('Late');
        $this->assertTrue(class_exists($this->namespace.'\\Late'), 'پیش‌نیاز: کلاس دوم باید قبل از غیرفعال‌سازی resolve شود.');

        $this->lifecycle->deactivate($this->slug);

        $this->assertFalse($this->loader->isRegistered($this->slug));

        $this->writeClass('AfterDeactivate');
        $this->assertFalse(
            class_exists($this->namespace.'\\AfterDeactivate'),
            'کلاسِ بارگذاری‌نشدهٔ یک افزونهٔ غیرفعال نباید resolve شود — وگرنه «غیرفعال» فقط یک پرچم در DB است.'
        );
    }

    /**
     * بسته‌ای که `Laravel/src` ندارد ریشه‌ای ندارد ⇒ چیزی ثبت نمی‌شود.
     *
     * ولی **ثبتِ قبلی هم برداشته می‌شود**: وگرنه ارتقا به نسخه‌ای بدون
     * `Laravel/src` کدِ نسخهٔ قبلی را زنده نگه می‌داشت — همان باگی که K5.11
     * برای `PluginRouter` بست.
     */
    public function test_activate_without_a_root_drops_a_stale_registration(): void
    {
        $this->lifecycle->activate($this->slug);
        $this->assertTrue($this->loader->isRegistered($this->slug));

        // بستهٔ تازه که نسخهٔ فعال ندارد ⇒ `autoloadRoot()` null می‌دهد.
        $stale = new PluginLifecycle($this->loader, new PluginReleaseManager($this->base.'/empty'));

        $this->assertFalse($stale->activate($this->slug));
        $this->assertFalse(
            $this->loader->isRegistered($this->slug),
            'بستهٔ بدون ریشه نباید ثبتِ نسخهٔ قبلی را زنده بگذارد.'
        );
    }

    /** slug تهی یک no-op است، نه exception — تا deactivate بی‌صدا بماند. */
    public function test_deactivate_with_an_empty_slug_is_a_no_op(): void
    {
        $this->lifecycle->deactivate('   ');

        $this->assertSame([], $this->loader->registeredSlugs());
    }

    // ── wiring ────────────────────────────────────────────────────────────

    /**
     * K5.0-W — بدون singleton، کل این ردیف بی‌اثر است.
     *
     * `register()` و `unregister()` روی وضعیتِ درونیِ یک شیء کار می‌کنند. اگر
     * `bootBundledPlugins()` روی یک نمونه ثبت کند و `deactivate()` روی نمونهٔ
     * دیگری بردارد، هر دو بی‌صدا «موفق»‌اند و افزونهٔ غیرفعال همچنان کلاسش را
     * load می‌کند. پس این نگهبانِ خودِ چرخهٔ عمر است.
     */
    public function test_the_autoloader_is_a_singleton_in_the_container(): void
    {
        $this->assertSame(
            app(PluginAutoloader::class),
            app()->make(PluginAutoloader::class),
            'بارگذارِ کلاس باید یک نمونه باشد وگرنه ثبت و برداشتن روی دو جدولِ جداست.'
        );
    }

    /**
     * مسیر HTTP هم باید همین سرویس را صدا بزند.
     *
     * یک نمونهٔ واقعی در کانتینر می‌گذاریم و وضعیتش را می‌سنجیم؛ چون
     * `PluginLifecycle` از کانتینر resolve می‌شود، همان نمونه‌ای است که
     * `activate()`/`deactivate()` را می‌راند.
     */
    public function test_the_container_resolves_the_lifecycle_over_the_container_autoloader(): void
    {
        $this->assertSame(
            app(PluginAutoloader::class),
            app(PluginLifecycle::class) instanceof PluginLifecycle
                ? (new \ReflectionProperty(PluginLifecycle::class, 'autoloader'))->getValue(app(PluginLifecycle::class))
                : null,
            'چرخهٔ عمر باید همان نمونهٔ بارگذار را بگیرد که `boot()` و کنترلر می‌بینند.'
        );
    }

    // ── کمپی ──────────────────────────────────────────────────────────────

    /** یک نسخهٔ واقعی با اشاره‌گرِ فعال، روی ریشهٔ اختصاصیِ این تست. */
    private function installedReleases(string $hash): PluginReleaseManager
    {
        $releases = new PluginReleaseManager($this->base);
        $releases->activate($this->slug, '1.0.0', $hash);

        return $releases;
    }

    private function writeClass(string $short): void
    {
        file_put_contents(
            $this->psr4.'/'.$short.'.php',
            '<?php namespace '.$this->namespace.'; class '.$short.' {}'
        );
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}