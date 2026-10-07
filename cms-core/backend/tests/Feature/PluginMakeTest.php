<?php

namespace Tests\Feature;

use App\Services\Plugins\PluginPackageContract;
use App\Services\Plugins\PluginPackageValidator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * K1.4 — `pishdad-plugin:make`.
 *
 * تنها ادعایی که می‌سنجیم این است: **اسکلتی که می‌سازد، از اعتبارسنج بستهٔ واقعیِ
 * هسته رد می‌شود.** اگر روزی قرارداد عوض شود و راهنما به‌روز نشود، همین تست
 * قرمز می‌شود — دقیقاً همان چیزی که یک generator بدون تست ارزشش را از دست
 * می‌دهد.
 */
class PluginMakeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        // اعتبارسنجی امضا یک کلید عمومی می‌خواهد؛ بدون آن، خطای امضا تنها چیزی است
        // که می‌تواند تست را بی‌دلیل قرمز کند.
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            File::deleteDirectory($this->dir);
        }

        parent::tearDown();
    }

    public function test_it_generates_a_package_the_real_validator_accepts(): void
    {
        $this->dir = $this->workdir();

        $code = Artisan::call('pishdad-plugin:make', ['slug' => 'blog', '--name' => 'پلاگین بلاگ', '--path' => $this->dir]);

        // `Artisan::output()` بافر را **خالی می‌کند**، پس یک‌بار می‌خوانیمش.
        $output = Artisan::output();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('اعتبارسنجی بسته: OK', $output);

        $zip = $this->dir.'/blog-1.0.0.zip';
        $this->assertFileExists($zip);

        // دوباره‌سنجی مستقل: تست به خروجیِ فرمان اعتماد نکند.
        $result = app(PluginPackageValidator::class)->analyze($zip, ['allowUnsigned' => true]);
        $this->assertTrue(
            $result['ok'],
            'بستهٔ تولیدشده باید از اعتبارسنج رد شود: '.json_encode($result['errors'], JSON_UNESCAPED_UNICODE)
        );
        $this->assertSame('blog', $result['summary']['slug']);
        $this->assertSame([], $result['errors']);
    }

    public function test_the_package_contains_exactly_the_allowed_skeleton(): void
    {
        $this->dir = $this->workdir();
        Artisan::call('pishdad-plugin:make', ['slug' => 'blog', '--path' => $this->dir]);

        $entries = $this->entries($this->dir.'/blog-1.0.0.zip');

        $this->assertContains('manifest.json', $entries);
        $this->assertContains('Laravel/src/ServiceProvider.php', $entries);
        $this->assertContains('Laravel/src/Models/Item.php', $entries);
        $this->assertContains('Laravel/src/Http/ItemController.php', $entries);
        $this->assertContains('Laravel/routes/api.php', $entries);
        $this->assertContains('Laravel/config/plugin.php', $entries);

        $migrations = array_values(array_filter($entries, fn (string $e) => str_starts_with($e, 'Laravel/database/migrations/')));
        $this->assertCount(1, $migrations, 'اسکلت باید دقیقاً یک migration داشته باشد.');
        $this->assertMatchesRegularExpression('/create_blog_items\.php$/', $migrations[0]);

        // هر ورودی باید از فهرستِ مجازِ قرارداد رد شود؛ وگرنه بسته روی سرور واقعی
        // نصب نمی‌شود و توسعه‌دهنده فقط بعد از آپلود می‌فهمد.
        foreach ($entries as $entry) {
            $this->assertTrue(
                PluginPackageContract::isAllowedPath($entry),
                '«'.$entry.'» در بسته هست ولی مسیرِ مجاز نیست.'
            );
        }
    }

    public function test_the_manifest_declares_the_table_raw_and_the_migration_creates_it_prefixed(): void
    {
        $this->dir = $this->workdir();
        Artisan::call('pishdad-plugin:make', ['slug' => 'blog', '--path' => $this->dir]);

        $manifest = $this->manifestOf($this->dir.'/blog-1.0.0.zip');

        // نامِ خام: هسته خودش پیشوندِ افزونه را اضافه می‌کند.
        $this->assertSame('items', $manifest['db']['tables'][0]['name']);
        $this->assertSame('blog', $manifest['api']['prefix']);
        $this->assertSame(
            'Pishdad\\Plugins\\Blog\\Http\\ItemController@index',
            $manifest['api']['routes'][0]['handler']
        );

        $this->assertStringContainsString("Schema::create('blog_items'", $this->contentsOf(
            $this->dir.'/blog-1.0.0.zip',
            $this->migrationName($this->dir.'/blog-1.0.0.zip')
        ));
    }

    public function test_the_scaffold_is_left_on_disk_for_editing(): void
    {
        $this->dir = $this->workdir();
        Artisan::call('pishdad-plugin:make', ['slug' => 'blog', '--path' => $this->dir]);

        $this->assertDirectoryExists($this->dir.'/blog');
        $this->assertFileExists($this->dir.'/blog/manifest.json');
        $this->assertStringContainsString('blog', (string) file_get_contents($this->dir.'/blog/manifest.json'));
    }

    public function test_it_refuses_a_slug_the_contract_cannot_accept(): void
    {
        $this->dir = $this->workdir();

        $code = Artisan::call('pishdad-plugin:make', ['slug' => 'Blog_Page', '--path' => $this->dir]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('معتبر نیست', Artisan::output());
        $this->assertDirectoryDoesNotExist($this->dir.'/Blog_Page');
    }

    public function test_it_refuses_a_slug_with_a_dash_because_table_names_forbid_it(): void
    {
        $this->dir = $this->workdir();

        $code = Artisan::call('pishdad-plugin:make', ['slug' => 'my-blog', '--path' => $this->dir]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('زیرخل', Artisan::output());
    }

    public function test_it_refuses_to_overwrite_without_force(): void
    {
        $this->dir = $this->workdir();
        Artisan::call('pishdad-plugin:make', ['slug' => 'blog', '--path' => $this->dir]);

        $code = Artisan::call('pishdad-plugin:make', ['slug' => 'blog', '--path' => $this->dir]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--force', Artisan::output());

        $this->assertSame(0, Artisan::call('pishdad-plugin:make', [
            'slug' => 'blog', '--path' => $this->dir, '--force' => true,
        ]));
    }

    private function workdir(): string
    {
        return storage_path('app/testing/plugin-make-'.uniqid());
    }

    /** @return list<string> */
    private function entries(string $zipPath): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true, 'ZIP باز نشد: '.$zipPath);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();

        return $names;
    }

    /** @return array<string, mixed> */
    private function manifestOf(string $zipPath): array
    {
        return (array) json_decode($this->contentsOf($zipPath, 'manifest.json'), true);
    }

    private function contentsOf(string $zipPath, string $entry): string
    {
        $zip = new ZipArchive;
        $zip->open($zipPath);

        $contents = (string) $zip->getFromName($entry);
        $zip->close();

        return $contents;
    }

    private function migrationName(string $zipPath): string
    {
        foreach ($this->entries($zipPath) as $entry) {
            if (str_starts_with($entry, 'Laravel/database/migrations/')) {
                return $entry;
            }
        }

        $this->fail('هیچ migration‌ای داخل بسته نبود.');
    }
}
