<?php

namespace Tests\Feature;

use App\Services\Plugins\PluginReleaseManager;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * رگرسیونِ بن‌بستِ فعال‌سازیِ پلاگینِ داخلی.
 *
 * ## باگ
 *
 * افزونه‌های داخلی (مرکز، اشتراک) با migration ثبت می‌شوند و `checksum`
 * آن‌ها `null` است — چون بستهٔ ZIPای در کار نبوده که `hash_file` بخورد.
 * ولی `activate()` هش را `string` می‌گرفت، پس `(string) null` می‌شد `''` و
 * `releaseLabel()` با «هش نسخه نامعتبر است» می‌پرید.
 *
 * نتیجه در محیط واقعی: **هر دو پلاگینِ داخلی از پنل قابل فعال‌سازی نبودند**
 * و مدیر سایت یک خطای بی‌ربط به امنیت می‌دید.
 *
 * ## چرا ریشه فقط یک نوعِ ناسازگار بود و یک ضعف
 *
 * ضعف نیست: هش خالی یعنی «از دیسکِ منبع آمده»، نه «بسته‌ای بی‌هش». اگر
 * فایل‌هایش نبود یا `Laravel/src` نداشت، همچنان باید رد شود — و این تست
 * هر دو شاخه را قفل می‌کند تا راه‌حل به «رد نکردنِ همه‌چیز» تبدیل نشود.
 */
class PluginInternalActivateTest extends TestCase
{
    private PluginReleaseManager $releases;

    private string $token;

    private string $slug;

    private string $sourceBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = 'x'.bin2hex(random_bytes(5));
        $this->slug = 'internal-'.$this->token;
        $this->sourceBase = base_path('plugins');

        $this->releases = app(PluginReleaseManager::class);

        $this->makeSource(['Laravel/src' => true, 'manifest' => true]);
    }

    protected function tearDown(): void
    {
        $dir = $this->sourceBase.'/'.$this->slug;

        if (is_dir($dir)) {
            $this->deleteTree($dir);
        }

        parent::tearDown();
    }

    private function makeSource(array $shape): void
    {
        $dir = $this->sourceBase.'/'.$this->slug;
        $this->deleteTree($dir);

        // پوشهٔ والد همیشه باید باشد، وگرنه نوشتنِ `manifest.json` وقتی
        // `Laravel/src` غایب است شکست می‌خورد و به‌جای «نبودِ کد» خطای
        // بی‌ربطِ مسیر می‌بینیم.
        @mkdir($dir, 0777, true);

        if (($shape['Laravel/src'] ?? false) === true) {
            @mkdir($dir.'/Laravel/src', 0777, true);
            file_put_contents($dir.'/Laravel/src/Placeholder.php', "<?php\nnamespace Pishdad\\".Str::studly($this->slug)."\\Src;\nclass Placeholder {}\n");
        }

        if (($shape['manifest'] ?? false) === true) {
            file_put_contents($dir.'/manifest.json', json_encode([
                'slug' => $this->slug,
                'name' => 'Internal Probe',
                'version' => '1.0.0',
            ], JSON_UNESCAPED_SLASHES));
        }
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

    public function test_an_internal_plugin_with_a_null_checksum_activates(): void
    {
        // این دقیقاً همان چیزی است که رکوردِ دیتابیس داشت.
        $this->releases->activate($this->slug, '1.0.0', '');

        $this->assertTrue(true, 'هش خالی نباید فعال‌سازی پلاگینِ داخلی را رد کند.');
    }

    public function test_the_real_migration_cast_of_null_is_also_accepted(): void
    {
        // `PluginController` مقدار را از `?string` می‌گیرد و به `string`
        // تبدیل می‌کند؛ اینجا همان تبدیلِ واقعی را می‌سنجیم.
        $checksum = null;
        $this->releases->activate($this->slug, '1.0.0', (string) $checksum);

        $this->assertTrue(true);
    }

    public function test_a_nonempty_but_malformed_hash_is_still_rejected(): void
    {
        // ⚠️ گارد امنیتی نباید شل شود: هشِ خالی معنا دارد، هشِ «نامعتبر» نه.
        $this->expectException(\InvalidArgumentException::class);

        $this->releases->activate($this->slug, '1.0.0', 'zzz-not-hex');
    }

    public function test_an_internal_source_without_manifest_is_rejected(): void
    {
        $this->makeSource(['Laravel/src' => true, 'manifest' => false]);

        $this->expectException(\RuntimeException::class);
        $this->releases->activate($this->slug, '1.0.0', '');
    }

    public function test_an_internal_source_without_loadable_code_is_rejected(): void
    {
        // پوشه هست ولی `Laravel/src` ندارد ⇒ کدی برای بارگذاری نیست.
        $this->makeSource(['Laravel/src' => false, 'manifest' => true]);

        $this->expectException(\RuntimeException::class);
        $this->releases->activate($this->slug, '1.0.0', '');
    }

    public function test_a_missing_source_is_rejected(): void
    {
        $this->deleteTree($this->sourceBase.'/'.$this->slug);

        $this->expectException(\RuntimeException::class);
        $this->releases->activate($this->slug, '1.0.0', '');
    }
}
