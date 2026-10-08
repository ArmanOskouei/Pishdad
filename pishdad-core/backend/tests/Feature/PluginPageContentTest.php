<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\SystemPlugin;
use App\Services\Plugins\ManifestRegistry;
use App\Services\Plugins\PluginPackageValidator;
use App\Services\Plugins\PluginPageContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * K7.18 — محتوای اعلانی صفحهٔ افزونه: `pages[key].blocks` و `layout`.
 *
 * این تست دوتاست که با هم یک ادعا را می‌سنجند: «پوستهٔ نازک، هیچ کد پلاگینی را
 * در مرورگر اجرا نمی‌کند و هیچ صفحهٔ خرابی بی‌صدا از بین نمی‌رود».
 *
 * ## چرا `RefreshDatabase` باید **بدون** transaction باشد
 *
 * `PluginDdlTestCase` یک پایهٔ جدا دارد چون provisioning نقش DDL در محیط واقعی
 * transaction ندارد. اینجا چنین چیزی نیست، پس پایهٔ معمولی درست است.
 *
 * ## چرا mutation اینجا مهم‌تر از حد معمول است
 *
 * یک تستِ «فقط ورودی خوب را می‌پذیرد» تقریباً بی‌ارزش است: کدِی که همه‌چیز را
 * رد می‌کند هم آن را سبز می‌کند. پس هر قاعده یک تستِ «ورودی بد رد می‌شود» دارد
 * و اگر آن را از پیاده‌سازی حذف کنیم تست باید قرمز شود — نه اینکه بی‌صدا
 * سبز بماند.
 */
class PluginPageContentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    private function codes(mixed $pages): array
    {
        return array_column(PluginPageContract::check($pages), 'code');
    }

    private function goodPage(array $overrides = []): array
    {
        return array_merge([
            'title_fa' => 'صورتحساب',
            'blocks' => [
                ['type' => 'text', 'data' => ['body' => 'سلام']],
            ],
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // پایه
    // ------------------------------------------------------------------

    /** نبودن `pages` خطا نیست: بیشتر افزونه‌ها صفحهٔ پنل ندارند. */
    public function test_absent_pages_is_not_an_error(): void
    {
        $this->assertSame([], $this->codes(null));
        $this->assertSame([], $this->codes([]));
    }

    /** ورودی درست، بی‌خطا — و هر هشت نوع بلوک هسته پذیرفته می‌شود. */
    public function test_a_well_formed_page_is_accepted(): void
    {
        $pages = [];
        foreach (PluginPageContract::allowedBlockTypes() as $type) {
            $pages[$type] = $this->goodPage(['blocks' => [['type' => $type, 'data' => []]]]);
        }

        $this->assertSame([], $this->codes($pages));
    }

    public function test_the_allowed_block_types_are_the_core_registry(): void
    {
        $this->assertSame(
            array_keys(config('blocks', [])),
            PluginPageContract::allowedBlockTypes()
        );
        $this->assertContains('text', PluginPageContract::allowedBlockTypes());
    }

    // ------------------------------------------------------------------
    // `layout` — allowlist بسته
    // ------------------------------------------------------------------

    public function test_layout_accepts_the_only_allowed_value(): void
    {
        $this->assertSame([], $this->codes(['x' => $this->goodPage(['layout' => 'default'])]));
    }

    /** layout آزاد نبود: مقدار ناشناخته خطاست، نه بی‌اثر. */
    public function test_layout_rejects_anything_outside_the_allowlist(): void
    {
        $codes = $this->codes(['x' => $this->goodPage(['layout' => 'wide'])]);

        $this->assertContains('pages.layout_not_allowed', $codes);
    }

    public function test_the_layout_allowlist_starts_with_exactly_one_value(): void
    {
        $this->assertSame(['default'], PluginPageContract::LAYOUTS);
        $this->assertSame(PluginPageContract::DEFAULT_LAYOUT, PluginPageContract::LAYOUTS[0]);
    }

    // ------------------------------------------------------------------
    // `blocks` — قلب ماجرا
    // ------------------------------------------------------------------

    /** ⭐ نوع بلوک ناشناس رد می‌شود. این همان چیزی است که گزینهٔ ۲ را می‌بندد. */
    public function test_an_unknown_block_type_is_rejected(): void
    {
        $codes = $this->codes([
            'x' => $this->goodPage(['blocks' => [['type' => 'evil_widget', 'data' => []]]]),
        ]);

        $this->assertContains('blocks.type_not_allowed', $codes);
    }

    /** رجیستری هسته خالی ⇒ خطا، نه سکوت. سکوت یعنی منشأ نامرئی. */
    public function test_when_the_core_registry_is_empty_blocks_are_an_error(): void
    {
        config(['blocks' => []]);

        $codes = $this->codes(['x' => $this->goodPage()]);

        $this->assertContains('blocks.no_core_types', $codes);
    }

    public function test_data_must_be_an_object(): void
    {
        $codes = $this->codes([
            'x' => $this->goodPage(['blocks' => [['type' => 'text', 'data' => 'string']]]),
        ]);

        $this->assertContains('blocks.data_not_object', $codes);
    }

    public function test_an_unknown_key_inside_a_block_is_rejected(): void
    {
        $codes = $this->codes([
            'x' => $this->goodPage(['blocks' => [[
                'type' => 'text', 'data' => [], 'component' => 'evil',
            ]]]),
        ]);

        $this->assertContains('blocks.unknown_key', $codes);
    }

    public function test_the_block_cap_is_enforced(): void
    {
        $blocks = array_fill(0, PluginPageContract::MAX_BLOCKS_PER_PAGE + 1, ['type' => 'text', 'data' => []]);

        $codes = $this->codes(['x' => $this->goodPage(['blocks' => $blocks])]);

        $this->assertContains('blocks.too_many', $codes);
    }

    // ------------------------------------------------------------------
    // `path` و کلید و `title_fa`
    // ------------------------------------------------------------------

    public function test_a_path_outside_admin_is_rejected(): void
    {
        $codes = $this->codes(['x' => $this->goodPage(['path' => '/outside/billing'])]);

        $this->assertContains('pages.path_not_allowed', $codes);
    }

    public function test_a_traversal_in_the_path_is_rejected(): void
    {
        $codes = $this->codes(['x' => $this->goodPage(['path' => '/admin/../outside'])]);

        $this->assertContains('pages.path_not_allowed', $codes);
    }

    public function test_two_pages_cannot_claim_the_same_path(): void
    {
        $codes = $this->codes([
            'a' => $this->goodPage(['path' => '/admin/same']),
            'b' => $this->goodPage(['path' => '/admin/same']),
        ]);

        $this->assertContains('pages.duplicate_path', $codes);
    }

    public function test_title_is_required(): void
    {
        $page = $this->goodPage();
        unset($page['title_fa']);

        $this->assertContains('pages.no_title', $this->codes(['x' => $page]));
    }

    public function test_an_unknown_top_level_key_is_rejected(): void
    {
        $codes = $this->codes(['x' => $this->goodPage(['render' => 'custom'])]);

        $this->assertContains('pages.unknown_key', $codes);
    }

    // ------------------------------------------------------------------
    // دروازهٔ نصب (گزینهٔ b) — fail-closed
    // ------------------------------------------------------------------

    /** ⭐ مانیفست بد باید **نصب** را رد کند، نه اینکه بعداً بی‌صدا حذف شود. */
    public function test_the_installer_rejects_a_bad_blocks_list(): void
    {
        $validator = $this->app->make(PluginPackageValidator::class);

        $issues = $this->runManifestChecks($validator, [
            'slug' => 'demo',
            'name' => 'دمو',
            'pages' => ['x' => [
                'title_fa' => 'صفحه',
                'blocks' => [['type' => 'evil', 'data' => []]],
            ]],
        ]);

        $codes = array_column($issues, 'code');
        $this->assertContains('blocks.type_not_allowed', $codes);
    }

    public function test_the_installer_rejects_a_freeform_layout(): void
    {
        $validator = $this->app->make(PluginPackageValidator::class);

        $issues = $this->runManifestChecks($validator, [
            'slug' => 'demo',
            'name' => 'دمو',
            'pages' => ['x' => ['title_fa' => 'صفحه', 'layout' => 'wide']],
        ]);

        $this->assertContains('pages.layout_not_allowed', array_column($issues, 'code'));
    }

    /**
     * `manifestChecks` خصوصی است، پس از راه اصلی خود validator (ساخت ZIP و
     * `analyze()`) می‌رویم — تستِ دروازه باید از همان مسیری بیاید که نصب واقعی
     * می‌آید، وگرنه چیزی را می‌سنجد که اجرا نمی‌شود.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<array<string, mixed>>
     */
    private function runManifestChecks(PluginPackageValidator $validator, array $manifest): array
    {
        $result = $validator->analyze($this->zipWithManifest($manifest));

        $issues = $result['errors'] ?? [];

        return is_array($issues) ? $issues : [];
    }

    /**
     * ZIP بسته‌ای می‌سازد که فقط `manifest.json` دارد. `analyze()` برای رد کردن
     * به دروازهٔ صفحه اصلاً نیازی به فایل PHP ندارد.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function zipWithManifest(array $manifest): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ppc').'.zip';

        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));
        $zip->close();

        return $path;
    }

    // ------------------------------------------------------------------
    // لایهٔ خواندن — افزونهٔ نصب‌شدهٔ قبلی
    // ------------------------------------------------------------------

    /**
     * ⭐ افزونه‌ای که **قبل از** این قاعده نصب شده، `layout` نامعتبر دارد. نباید
     * بی‌صدا از رجیستری بیفتد — و اگر بیفتد، منشأش باید قابل تشخیص باشد.
     *
     * نرمالایزر `null` برمی‌گرداند (صفحه رندر نمی‌شود) چون برگرداندن مقدار
     * پیش‌فرض یعنی رندر چیزی که نویسنده درخواستش نکرده بود.
     */
    public function test_a_legacy_invalid_layout_drops_the_page_from_the_registry(): void
    {
        $this->installPlugin('legacy', [
            'pages' => ['billing' => ['title_fa' => 'صورتحساب', 'layout' => 'wide']],
        ]);

        $paths = array_column(ManifestRegistry::pageRegistry(), 'path');

        $this->assertNotContains('/admin/billing', $paths);
    }

    /** بلوک‌های افزونهٔ قدیمی که نوعشان مجاز است، سالم عبور می‌کنند. */
    public function test_legacy_pages_with_valid_blocks_keep_them(): void
    {
        $this->installPlugin('legacy', [
            'pages' => ['billing' => [
                'title_fa' => 'صورتحساب',
                'blocks' => [['type' => 'text', 'data' => ['body' => 'خوب']]],
            ]],
        ]);

        $entry = collect(ManifestRegistry::pageRegistry())->firstWhere('slug', 'legacy');

        $this->assertNotNull($entry);
        $this->assertSame([['type' => 'text', 'data' => ['body' => 'خوب']]], $entry['blocks']);
    }

    /** بلوک ناشناس در افزونهٔ قدیمی فیلتر می‌شود — بقیهٔ صفحه می‌ماند. */
    public function test_legacy_pages_have_unknown_blocks_filtered_not_the_whole_page(): void
    {
        $this->installPlugin('legacy', [
            'pages' => ['billing' => [
                'title_fa' => 'صورتحساب',
                'blocks' => [
                    ['type' => 'text', 'data' => ['body' => 'خوب']],
                    ['type' => 'evil', 'data' => []],
                ],
            ]],
        ]);

        $entry = collect(ManifestRegistry::pageRegistry())->firstWhere('slug', 'legacy');

        $this->assertNotNull($entry, 'یک بلوک بد نباید کل صفحه را بیندازد.');
        $this->assertSame([['type' => 'text', 'data' => ['body' => 'خوب']]], $entry['blocks']);
    }

    // ------------------------------------------------------------------
    // مهاجرت
    // ------------------------------------------------------------------

    /** ⭐ مهاجرت نباید ارتقا را بشکند و باید `layout` بد را درست کند. */
    public function test_the_migration_repairs_a_legacy_layout_in_place(): void
    {
        if (! Schema::hasTable('plugins')) {
            $this->markTestSkipped('جدول plugins در این پایه نیست.');
        }

        $this->installPlugin('legacy', [
            'pages' => [
                'good' => ['title_fa' => 'خوب', 'layout' => 'default'],
                'bad' => [
                    'title_fa' => 'بد',
                    'layout' => 'wide',
                    'blocks' => [
                        ['type' => 'text', 'data' => ['body' => 'متن']],
                        ['type' => 'evil', 'data' => []],
                    ],
                ],
            ],
        ]);

        // اجرای دستی migration: خودش را صدا می‌زنیم چون در این پایه قبلاً اجرا شده.
        $migration = require database_path('migrations/2026_10_09_000001_normalize_plugin_page_blocks.php');
        $migration->up();

        ManifestRegistry::flushCache();

        $pages = json_decode((string) DB::table('plugins')->where('slug', 'legacy')->value('manifest'), true)['pages'];

        $this->assertSame('default', $pages['bad']['layout'], 'layout نامعتبر باید به مقدار مجاز برگرود.');
        $this->assertSame('default', $pages['good']['layout'], 'مقدار از قبل درست نباید دست بخورد.');

        // فقط بلوک نوع‌غیرمجاز می‌رود و دادهٔ معتبر می‌ماند. ترتیب کلیدها را
        // عمداً نمی‌سنجیم: `LayoutController.php:262-265` هم `data` را قبل از
        // `type` می‌نویسد، پس ترتیب در این پروژه قراردادی نیست.
        $this->assertCount(1, $pages['bad']['blocks']);
        $this->assertSame('text', $pages['bad']['blocks'][0]['type']);
        $this->assertSame(['body' => 'متن'], $pages['bad']['blocks'][0]['data']);
    }

    /**
     * idempotent: دو بار اجرا، همان نتیجه.
     *
     * نکتهٔ مهم این تست: اجرای دوم روی داده‌ای است که **هنوز** یک ایراد دارد.
     * اگر اجرای اول همه‌چیز را درست کند، اجرای دوم هیچ کاری نمی‌کند و تست
     * هرگز فرق «idempotent» با «بی‌اثر چون کاری نکرد» را نمی‌فهمد. پس اینجا دو
     * چیز را عمداً نگه می‌داریم: یک `layout` که با هر اجرا به `default` تبدیل
     * می‌شود، و یک `blocks` که باید **پاک‌سازی** شود ولی نه بازنویسی‌گردد.
     */
    public function test_the_migration_is_idempotent(): void
    {
        if (! Schema::hasTable('plugins')) {
            $this->markTestSkipped('جدول plugins در این پایه نیست.');
        }

        $this->installPlugin('legacy', [
            'pages' => ['x' => [
                'title_fa' => 'الف',
                'layout' => 'wide',
                // کلید ناشناخته هم عمدی است: هر اجرا باید همان خروجی را بدهد.
                'blocks' => [['type' => 'text', 'data' => ['k' => 'v']]],
            ]],
        ]);

        $migration = require database_path('migrations/2026_10_09_000001_normalize_plugin_page_blocks.php');

        $migration->up();
        $first = DB::table('plugins')->where('slug', 'legacy')->value('manifest');
        $firstTouched = DB::table('plugins')->where('slug', 'legacy')->value('updated_at');

        // یک ثانیه صبر: `updated_at` ثانیه‌ای ذخیره می‌شود، پس بدون فاصلهٔ زمانی
        // «دست نخورده بود» از «دست خورده بود» قابل تشخیص نیست.
        sleep(1);

        $migration->up();
        $second = DB::table('plugins')->where('slug', 'legacy')->value('manifest');
        $secondTouched = DB::table('plugins')->where('slug', 'legacy')->value('updated_at');

        sleep(1);

        $migration->up();
        $third = DB::table('plugins')->where('slug', 'legacy')->value('manifest');

        $this->assertSame($first, $second, 'اجرای دوم نباید محتوا را عوض کند.');
        $this->assertSame($second, $third, 'اجرای سوم هم باید بی‌اثر باشد.');

        // ⭐ این سخت‌گیرانه‌ترین سنجهٔ idempotency است: اجرای دوم نباید حتی سطر
        // را لمس کند. یک مهاجرت غیر-idempotent که هر بار `update()` می‌زند، محتوا
        // را شاید عوض نکند ولی سطر را بازنویسی می‌کند — و در نصب‌های بزرگ یعنی
        // بازنویسی بی‌دلیل هزار سطر در هر deploy.
        $this->assertSame(
            (string) $firstTouched,
            (string) $secondTouched,
            'اجرای دوم نباید سطر را دوباره بنویسد.'
        );

        $pages = json_decode((string) $first, true)['pages'];
        $this->assertSame('default', $pages['x']['layout']);

        // ترتیب کلیدها قراردادی نیست: `LayoutController.php:262-265` هم `data` را
        // قبل از `type` می‌نویسد. پس ساختار را می‌سنجیم، نه ترتیب را.
        $this->assertCount(1, $pages['x']['blocks']);
        $this->assertSame('text', $pages['x']['blocks'][0]['type']);
        $this->assertSame(['k' => 'v'], $pages['x']['blocks'][0]['data']);
    }

    // ------------------------------------------------------------------
    // کمکی
    // ------------------------------------------------------------------

    /**
     * افزونهٔ «نصب‌شدهٔ قدیمی» را می‌سازد: رکورد دارد، مانیفست خام دارد، ولی از
     * دروازهٔ جدید رد نشده — که دقیقاً وضعیتی است که مهاجرت برایش هست.
     *
     * @param  array<string, mixed>  $extra
     */
    private function installPlugin(string $slug, array $extra): void
    {
        if (! Schema::hasTable('plugins')) {
            $this->markTestSkipped('جدول plugins در این پایه نیست.');
        }

        $manifest = array_merge([
            'slug' => $slug,
            'name' => $slug,
            'version' => '1.0.0',
        ], $extra);

        DB::table('plugins')->updateOrInsert(
            ['slug' => $slug],
            [
                'name' => $slug,
                'version' => '1.0.0',
                'active' => true,
                'signature_valid' => false,
                'checksum' => null,
                'manifest' => json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'path' => 'plugins/'.$slug,
                'source' => Plugin::SOURCE_LOCAL,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        SystemPlugin::flush();
        ManifestRegistry::flushCache();
    }
}
