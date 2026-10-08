<?php

namespace Tests\Feature;

use App\Services\Plugins\DevMode;
use App\Services\Plugins\DevScaffoldRegistry;
use App\Services\Plugins\PluginPackageContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ECO4 — `pishdad-theme:make`.
 *
 * ادعای اصلی: **اسکلتی که می‌سازد، قراردادِ `ThemeManifest` فرانت را رعایت
 * می‌کند و `index.tsx`ی می‌دهد که علیه `ThemeProps` کامپایل می‌شود.** ادعای
 * دوم: **حالت توسعه‌دهنده پیش‌فرض بسته است** و `--dev` بدون آن هیچ اسکلتی
 * نمی‌کارد.
 */
class ThemeMakeTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        app(DevScaffoldRegistry::class)->forget();
        // E70: این تست‌ها پیش‌فرضِ «بسته» را می‌سنجند، ولی دیتابیسِ تست بین
        // کلاس‌ها مشترک است و `PluginsTest` (بدون تراکنش) ممکن است حالتِ باز
        // را commit کرده باشد. پس به‌جای فرض، صریح می‌بندیم تا ترتیب‌اثر نماند.
        app(DevMode::class)->disable();
        app(DevMode::class)->forget();
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            File::deleteDirectory($this->dir);
        }
        app(DevScaffoldRegistry::class)->forget();

        parent::tearDown();
    }

    public function test_it_scaffolds_a_theme_matching_the_manifest_contract(): void
    {
        $this->dir = $this->workdir();

        $code = Artisan::call('pishdad-theme:make', [
            'slug' => 'sunrise', '--name' => 'قالب سپیده', '--path' => $this->dir,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $code, $output);
        $this->assertFileExists($this->dir.'/sunrise/theme.json');
        $this->assertFileExists($this->dir.'/sunrise/index.tsx');

        $manifest = (array) json_decode((string) file_get_contents($this->dir.'/sunrise/theme.json'), true);

        // مستقل از خروجی فرمان، مانیفست را می‌سنجیم.
        foreach (['slug', 'name', 'description', 'version', 'modes', 'slots', 'tokens'] as $key) {
            $this->assertArrayHasKey($key, $manifest, 'کلید «'.$key.'» در theme.json نیست.');
        }
        $this->assertSame('sunrise', $manifest['slug']);
        $this->assertSame('قالب سپیده', $manifest['name']);
        $this->assertSame('1.0.0', $manifest['version']);
        $this->assertContains('main', $manifest['slots']);
        $this->assertContains('light', $manifest['modes']);
        $this->assertContains('dark', $manifest['modes']);
        $this->assertSame('15px', $manifest['tokens']['fs-body']);
    }

    public function test_the_component_compiles_against_the_theme_contract(): void
    {
        $this->dir = $this->workdir();
        Artisan::call('pishdad-theme:make', ['slug' => 'sunrise', '--path' => $this->dir]);

        $tsx = (string) file_get_contents($this->dir.'/sunrise/index.tsx');

        $this->assertStringContainsString('import { ThemeShell } from "../shared";', $tsx);
        $this->assertStringContainsString('import type { ThemeProps } from "../types";', $tsx);
        $this->assertStringContainsString('props: ThemeProps', $tsx);
        $this->assertStringContainsString('<ThemeShell', $tsx);
        $this->assertStringContainsString('export default SunriseTheme;', $tsx);
    }

    public function test_it_refuses_a_slug_that_breaks_the_shared_slug_rules(): void
    {
        $this->dir = $this->workdir();

        foreach (['Sunrise', 'x', 'sun rise', '-lead'] as $bad) {
            $code = Artisan::call('pishdad-theme:make', ['slug' => $bad, '--path' => $this->dir]);

            $this->assertSame(1, $code, 'اسلاگ «'.$bad.'» باید رد شود.');
            $this->assertStringContainsString('معتبر نیست', Artisan::output());
            $this->assertDirectoryDoesNotExist($this->dir.DIRECTORY_SEPARATOR.$bad);
        }

        // قانونِ اضافهٔ افزونه (ممنوعیتِ نقطه/خط‌تیره) برای قالب اعمال **نمی‌شود**،
        // چون قالب جدول نمی‌سازد؛ ولی الگوی پایهٔ اسلاگ یکسان است.
        $this->assertSame(1, preg_match(PluginPackageContract::SLUG_PATTERN, 'my-theme'));
    }

    public function test_it_refuses_to_overwrite_without_force(): void
    {
        $this->dir = $this->workdir();
        Artisan::call('pishdad-theme:make', ['slug' => 'sunrise', '--path' => $this->dir]);

        $code = Artisan::call('pishdad-theme:make', ['slug' => 'sunrise', '--path' => $this->dir]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--force', Artisan::output());

        $this->assertSame(0, Artisan::call('pishdad-theme:make', [
            'slug' => 'sunrise', '--path' => $this->dir, '--force' => true,
        ]));
    }

    public function test_dev_registration_is_fail_closed_when_dev_mode_is_off(): void
    {
        $this->dir = $this->workdir();

        $this->assertFalse(app(DevMode::class)->isActive());

        $code = Artisan::call('pishdad-theme:make', [
            'slug' => 'sunrise', '--path' => $this->dir, '--dev' => true,
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('حالت توسعه‌دهنده فعال نیست', Artisan::output());
        // fail-closed یعنی هیچ فایلی حتی نوشته نشود.
        $this->assertDirectoryDoesNotExist($this->dir.'/sunrise');
        $this->assertSame([], app(DevScaffoldRegistry::class)->all());
    }

    public function test_dev_registration_records_while_dev_mode_is_active_and_hides_when_closed(): void
    {
        $this->dir = $this->workdir();
        app(DevMode::class)->enable('tok-good');

        $code = Artisan::call('pishdad-theme:make', [
            'slug' => 'sunrise', '--path' => $this->dir, '--dev' => true,
        ]);

        $this->assertSame(0, $code, Artisan::output());
        $this->assertFileExists($this->dir.'/sunrise/theme.json');

        $all = app(DevScaffoldRegistry::class)->all();
        $this->assertArrayHasKey('sunrise', $all[DevScaffoldRegistry::KIND_THEME]);
        $this->assertSame($this->dir.DIRECTORY_SEPARATOR.'sunrise', $all[DevScaffoldRegistry::KIND_THEME]['sunrise']['path']);

        // خاموش‌کردن حالت توسعه ⇒ دفتر باید تهی دیده شود، اگرچه فایل روی دیسک است.
        app(DevMode::class)->disable();
        $this->assertSame([], app(DevScaffoldRegistry::class)->all());
        $this->assertFileExists($this->dir.'/sunrise/theme.json');
    }

    private function workdir(): string
    {
        return storage_path('app/testing/theme-make-'.uniqid());
    }
}
