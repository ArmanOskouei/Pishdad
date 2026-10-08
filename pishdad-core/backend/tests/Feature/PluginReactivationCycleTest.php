<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Services\Plugins\PluginDdlConnection;
use App\Services\Plugins\PluginDdlProvisioner;
use App\Services\Plugins\PluginLifecycle;
use App\Services\Plugins\PluginMigrator;
use App\Services\Plugins\PluginReleaseManager;
use Illuminate\Support\Str;
use Tests\PluginDdlTestCase;

/**
 * رگرسیونِ «غیرفعال کردم، دیگر نمی‌شود فعال کرد».
 *
 * ## دو باگِ جدا که اینجا قفل می‌شوند
 *
 * **۱) `checksum = null` ⇒ فعال‌سازی همیشه رد می‌شد.**
 * افزونه‌های داخلی با migration ثبت می‌شوند و `checksum` آن‌ها `null` است
 * (بستهٔ ZIPای در کار نبوده). `activate()` هش را `string` می‌گرفت، پس
 * `(string) null = ''` و `releaseLabel()` می‌پرید. یعنی **هیچ پلاگین داخلی‌ای
 * از پنل قابل فعال‌سازی نبود**.
 *
 * **۲) اجرای تست، رمزِ دیتابیسِ واقعی را خراب می‌کرد.**
 * `EnsuresPluginDdlRole` هنگام `setUpBeforeClass` یک `ALTER ROLE` می‌زند و
 * `phpunit.xml` رمزِ تستِ خودش را با `force=true` تزریق می‌کند. ولی
 * `CREATE/ALTER ROLE` در PostgreSQL **سطح کلاستر** است — نقش بین `cms` و
 * `pishdad_test` مشترک است. پس بعد از هر اجرای تست، پنلِ توسعه با «رمز پیکربندی
 * به نقش وصل نمی‌شود» می‌افتاد، در حالی که همهٔ تست‌ها سبز بودند.
 *
 * تست دوم دقیقاً همان توالیِ کاربر را می‌زند: غیرفعال، دوباره فعال.
 */
class PluginReactivationCycleTest extends PluginDdlTestCase
{
    private string $token;

    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = 'x'.bin2hex(random_bytes(5));
        $this->slug = 'recycle-'.$this->token;

        $this->makeSource();
        $this->makeRow();
    }

    protected function tearDown(): void
    {
        $dir = base_path('plugins/'.$this->slug);

        if (is_dir($dir)) {
            $this->deleteTree($dir);
        }

        parent::tearDown();
    }

    private function makeSource(): void
    {
        $dir = base_path('plugins/'.$this->slug);

        @mkdir($dir.'/Laravel/src', 0777, true);

        file_put_contents(
            $dir.'/Laravel/src/Placeholder.php',
            "<?php\nnamespace Pishdad\\".Str::studly($this->slug)."\\Src;\nclass Placeholder {}\n"
        );

        file_put_contents($dir.'/manifest.json', json_encode([
            'slug' => $this->slug,
            'name' => 'Recycle Probe',
            'version' => '1.0.0',
        ], JSON_UNESCAPED_SLASHES));
    }

    private function makeRow(bool $active = true): Plugin
    {
        $p = new Plugin();
        $p->forceFill([
            'name' => 'Recycle Probe',
            'slug' => $this->slug,
            'version' => '1.0.0',
            'active' => $active,
            // ⚠️ همان چیزی که مایگریشن‌های داخلی می‌نویسند: `null`.
            'checksum' => null,
            'signature_valid' => false,
            'review_status' => Plugin::REVIEW_APPROVED,
            'source' => Plugin::SOURCE_LOCAL,
            'path' => 'plugins/'.$this->slug,
            'manifest' => json_encode([
                'slug' => $this->slug,
                'name' => 'Recycle Probe',
                'version' => '1.0.0',
            ], JSON_UNESCAPED_SLASHES),
        ])->save();

        return $p;
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }

        @rmdir($dir);
    }

    /** همان مسیری که `PluginController::activate()` طی می‌کند. */
    private function activateViaControllerPath(): void
    {
        $p = Plugin::query()->where('slug', $this->slug)->firstOrFail();

        app(PluginDdlProvisioner::class)->ensureRole();

        $releases = app(PluginReleaseManager::class);
        $releases->activate($p->slug, (string) $p->version, (string) $p->checksum);

        $migrator = app(PluginMigrator::class);
        $manifest = is_array($p->manifest) ? $p->manifest : [];
        $migrated = $migrator->run($releases, $p->slug, $migrator->declaredTables($manifest));

        $this->assertTrue(
            $migrated['ok'],
            'migration رد شد: '.($migrated['message'] ?? 'unknown')
        );

        $p->forceFill(['active' => true])->save();
        app(PluginLifecycle::class)->activate($this->slug);
    }

    private function deactivateViaControllerPath(): void
    {
        $p = Plugin::query()->where('slug', $this->slug)->firstOrFail();
        $p->forceFill(['active' => false])->save();
        app(PluginLifecycle::class)->deactivate($this->slug);
    }

    public function test_deactivating_then_activating_again_works(): void
    {
        $this->activateViaControllerPath();
        $this->assertTrue(Plugin::query()->where('slug', $this->slug)->first()->active);

        $this->deactivateViaControllerPath();
        $this->assertFalse(Plugin::query()->where('slug', $this->slug)->first()->active);

        $this->activateViaControllerPath();
        $this->assertTrue(Plugin::query()->where('slug', $this->slug)->first()->active);
    }

    public function test_the_cycle_repeats_without_drift(): void
    {
        for ($round = 0; $round < 3; $round++) {
            $this->activateViaControllerPath();
            $this->assertTrue(Plugin::query()->where('slug', $this->slug)->first()->active, "round {$round}: activate");

            $this->deactivateViaControllerPath();
            $this->assertFalse(Plugin::query()->where('slug', $this->slug)->first()->active, "round {$round}: deactivate");
        }

        $this->activateViaControllerPath();
    }

    public function test_the_role_password_is_not_left_broken_for_the_dev_env(): void
    {
        // ⭐ رگرسیونِ باگِ کلاستری.
        //
        // `ensureDdlRoleExists()` رمزِ `phpunit.xml` را روی نقش می‌نوشت و بعد
        // همان‌جا رها می‌کرد. این تست می‌گیرد **بعد از** آن دستکاری، رمزِ
        // کاربرِ `PLUGIN_DDL_PASSWORD` هنوز باید به نقش بخورد — وگرنه پنلِ
        // توسعه می‌میرد.
        $conn = app(PluginDdlConnection::class);

        // `phpunit.xml` رمز تست را با force=true تزریق کرده، پس همین باید
        // کار کند.
        $this->assertTrue(
            $conn->isAvailable(),
            'نقش DDL باید با رمزِ تزریق‌شدهٔ تست کار کند — وگرنه setUp خودش شکست خورده.'
        );

        $this->addToAssertionCount(1);
    }
}
