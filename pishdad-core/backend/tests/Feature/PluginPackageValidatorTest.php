<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\SystemPlugin;
use App\Models\User;
use App\Services\Plugins\PluginPackageContract;
use App\Services\Plugins\PluginPackageValidator;
use App\Services\Plugins\PluginReleaseManager;
use App\Services\Plugins\PluginSignatureVerifier;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;
use ZipArchive;

/**
 * فاز ۱.۵ — تحلیل بستهٔ پلاگین پیش از نصب.
 *
 * این تست‌ها عمداً هیچ‌چیز را استخراج نمی‌کنند و هیچ رکوردی نمی‌سازند: کل نقطهٔ
 * قوت این لایه همین است که کاربر بدون اجرای کد پلاگین، ساختار بسته‌اش را می‌بیند.
 */
class PluginPackageValidatorTest extends TestCase
{
    use RefreshDatabase;

    /** یک آیتم منوی معتبر — شکل واقعی `MenuItem` در `lib/menu.ts:3`. */
    private const MENU_ITEM = [
        'point' => 'admin.menu',
        'key' => 'blog',
        'label' => 'نوشته‌ها',
        'href' => '/admin/blog',
        'icon' => '✎',
        'permission' => 'plugin:blog:posts.view',
    ];

    private string $secretKey;

    protected function setUp(): void
    {
        parent::setUp();

        // K7.8 — چند تست این فایل کل جدول `plugins` را می‌سنجند
        // (`assertDatabaseCount('plugins', 0)`). رکوردِ بستهٔ مرکزی از
        // migration باقی است، پس باید صریح برداشته شود.

        $kp = sodium_crypto_sign_keypair();
        config(['plugins.public_key' => base64_encode(sodium_crypto_sign_publickey($kp))]);
        $this->secretKey = base64_encode(sodium_crypto_sign_secretkey($kp));
    }

    public function test_accepts_a_conforming_package(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson([
                'panel' => ['extensions' => [self::MENU_ITEM]],
            ]),
            'Laravel/src/Models/Post.php' => '<?php',
            'Laravel/routes/api.php' => '<?php',
            'Laravel/database/migrations/2026_01_01_000001_create_blog_posts.php' => '<?php',
            'Next.js/blocks/hero.json' => '{}',
        ]));

        $this->assertTrue($result['ok'], 'بستهٔ سازگار باید معتبر باشد: '.json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('blog', $result['summary']['slug']);
        $this->assertSame(['admin.menu'], $result['summary']['extension_points']);
    }

    /**
     * تصمیم C2: `Next.js/panel.json` حذف شد. مانیفست تنها منبع حقیقت است و آن
     * فایل کپی دومی بود که فقط می‌توانست drift کند.
     */
    public function test_panel_json_is_no_longer_part_of_the_allowed_bundle(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson(),
            'Next.js/panel.json' => json_encode(['extensions' => [['point' => 'admin.menu']]], JSON_UNESCAPED_UNICODE),
        ]));

        $this->assertFalse($result['ok']);
        $issue = $this->firstWithCode($result['errors'], 'path.not_allowed');
        $this->assertNotNull($issue);
        $this->assertSame('Next.js/panel.json', $issue['path']);
        $this->assertFalse(PluginPackageContract::isAllowedPath('Next.js/panel.json'));
    }

    /** هر مقصدی که `describeAllowedPaths()` وعده می‌دهد باید واقعاً مجاز باشد. */
    public function test_every_described_target_is_actually_allowed(): void
    {
        $described = array_column(PluginPackageContract::describeAllowedPaths(), 'path');
        $this->assertCount(
            count(PluginPackageContract::ALLOWED_PATHS),
            $described,
            'تعداد مقصدهای توصیف‌شده باید با ALLOWED_PATHS یکی باشد وگرنه راهنما دربارهٔ چیزی دروغ می‌گوید.'
        );

        foreach ($described as $path) {
            $probe = str_ends_with($path, '/') ? $path.'probe.php' : $path;
            $this->assertTrue(
                PluginPackageContract::isAllowedPath($probe),
                '«'.$path.'» وعده داده شده ولی مسیرش مجاز نیست.'
            );
        }

        $this->assertNotContains('Next.js/panel.json', $described);
    }

    /**
     * همان invariantی که کارفرما پرسید: هر پیشنهادِ محل، خودش باید داخل بستهٔ
     * مجاز باشد — وگرنه راهنما کاربر را به جایی می‌فرستد که باز هم خطا می‌خورد.
     */
    public function test_every_suggestion_lands_inside_the_allowed_bundle(): void
    {
        $misplaced = [
            'Laravel/PostService.php',
            'Laravel/2026_01_01_000001_create_blog_posts.php',
            'Laravel/migrations/create_posts.php',
            'Laravel/hero.png',
            'Laravel/Model.png',
            'Laravel/theme.css',
            'Laravel/logo.svg',
        ];

        foreach ($misplaced as $path) {
            $suggest = PluginPackageContract::suggestLocation($path);
            $this->assertNotNull($suggest, 'برای «'.$path.'» باید محل درست پیشنهاد شود.');
            $this->assertTrue(
                PluginPackageContract::isAllowedPath($suggest['path']),
                'پیشنهاد «'.$suggest['path'].'» برای «'.$path.'» خودش خارج از بستهٔ مجاز است.'
            );
        }

        // جایی که هیچ نشانه‌ای نیست، سکوت بهتر از حدسِ الکی است.
        $this->assertNull(PluginPackageContract::suggestLocation('Laravel/app/Http/Kernel.php'));
    }

    /**
     * K5.6 — اعلام `db.tables` باید واقعاً به validator وصل باشد.
     *
     * `PluginDbContract` به‌تنهایی کد مرده است اگر `analyze()` آن را صدا نزند. این
     * تست از مسیر واقعی (بستهٔ ZIP) عبور می‌کند، پس اگر اتصال بعداً حذف شود قرمز
     * می‌شود — برخلاف تست‌های خودِ `PluginDbContract` که مستقیم کلاس را صدا می‌زنند.
     */
    public function test_db_declaration_is_enforced_through_the_real_validator(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson([
                'db' => ['tables' => [['name' => 'users']]],
            ]),
            'Laravel/src/ServiceProvider.php' => '<?php',
        ]));

        $codes = array_column($result['errors'], 'code');

        $this->assertContains(
            'db.table_collides_core',
            $codes,
            'اعلام جدول هسته باید از مسیر واقعی validator خطا بدهد.'
        );
        $this->assertFalse($result['ok']);
    }

    public function test_a_valid_db_declaration_does_not_block_the_package(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson([
                'db' => ['tables' => [['name' => 'posts', 'indexes' => ['author_id']]]],
            ]),
            'Laravel/src/ServiceProvider.php' => '<?php',
        ]));

        $this->assertTrue(
            $result['ok'],
            'اعلام درست نباید بسته را رد کند: '.json_encode($result['errors'], JSON_UNESCAPED_UNICODE)
        );
    }

    /** بند ۲ خواستهٔ کارفرما: `Laravel/` و `Next.js/` باید شناسایی شوند. */
    public function test_recognizes_laravel_and_nextjs_roots(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson(),
            'Laravel/src/ServiceProvider.php' => '<?php',
            'Next.js/blocks/hero.json' => '{}',
        ]));

        $this->assertTrue($result['ok'], json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
    }

    /**
     * `ZipArchive::locateName()` حتی با `FL_NODIR` بر اساس **basename** جست‌وجو
     * می‌کند. پس بسته‌ای که فقط `evil/manifest.json` دارد، با نگاه کورکورانه
     * «مانیفست پیدا شد» و بقیهٔ اعتبارسنجی روی یک فایل نامعتبر اجرا می‌شد.
     *
     * مانیفست فقط در ریشه معتبر است.
     */
    public function test_nested_manifest_is_not_accepted_as_the_manifest(): void
    {
        $result = $this->analyze($this->zip([
            'evil/manifest.json' => $this->manifestJson(),
        ]));

        $this->assertFalse($result['ok']);
        $this->assertNull($result['manifest'], 'مانیفست اعماق نباید به‌عنوان مانیفست بسته پذیرفته شود.');
        $this->assertContains('manifest.missing', $this->codes($result['errors']));
    }

    /**
     * رگرسیون: بسته‌ای که **دو** کپی مانیفست دارد — یکی در ریشه (معتبر) و یکی
     * اعماق. ریشه باید برنده شود، نه اعماق.
     */
    public function test_root_manifest_wins_over_a_nested_duplicate(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson(),
            'deep/nested/manifest.json' => json_encode([
                'slug' => 'attacker',
                'name' => 'attacker',
                'version' => '9.9.9',
            ], JSON_UNESCAPED_UNICODE),
        ]));

        $this->assertNotNull($result['manifest']);
        $this->assertSame('blog', $result['manifest']['slug'] ?? null);
        $this->assertContains('manifest.misplaced', $this->codes($result['errors']));
    }

    /**
     * zip-slip باید **قبل از استخراج** گرفته شود. `ZipArchive::extractTo()` در
     * برابر مسیرهای `..` محافظت کامل ندارد.
     */
    public function test_rejects_path_traversal(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson(),
            'Laravel/src/../../../etc/passwd' => 'pwn',
        ]));

        $this->assertFalse($result['ok']);
        $this->assertContains('path.unsafe', $this->codes($result['errors']));
    }

    /** مسیر مطلق با حرف درایو (ویندوز) هم باید رد شود، نه فقط `/`. */
    public function test_rejects_absolute_path(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson(),
            'C:/Windows/system32/drivers/etc/hosts' => 'pwn',
        ]));

        $this->assertFalse($result['ok']);
        $this->assertContains('path.unsafe', $this->codes($result['errors']));
    }

    public function test_rejects_windows_reserved_name(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson(),
            'Laravel/src/CON.php' => 'x',
        ]));

        $this->assertFalse($result['ok']);
        $this->assertContains('path.unsafe', $this->codes($result['errors']));
    }

    public function test_rejects_symlink_entry(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson(),
            'Laravel/src/evil.php' => '/etc/passwd',
        ], symlink: 'Laravel/src/evil.php'));

        $this->assertFalse($result['ok']);
        $this->assertContains('path.symlink', $this->codes($result['errors']));
    }

    /**
     * L-B10 — FIFO/socket/char/block باید همان‌جا در اعتبارسنجی رد شوند، نه
     * اینکه validator سبز بدهد و installer بعداً «install.special_entry» بزند.
     */
    public function test_rejects_fifo_socket_and_device_entries(): void
    {
        foreach (['fifo' => 0x1000, 'socket' => 0xC000, 'chardev' => 0x2000, 'blockdev' => 0x6000] as $label => $mode) {
            $path = 'Laravel/src/Dev-'.$label;

            $result = $this->analyze($this->zip([
                'manifest.json' => $this->manifestJson(),
                'Laravel/src/Ok.php' => '<?php',
                $path => '',
            ], modes: [$path => $mode]));

            $this->assertFalse($result['ok'], 'ورودیِ '.$label.' باید رد شود.');
            $this->assertContains(
                'path.special_entry',
                $this->codes($result['errors']),
                'کدِ خطا برای '.$label
            );
        }
    }

    /**
     * خواستهٔ کارفرما: اگر فایل جای اشتباه است، بگو **کجا** باید باشد.
     */
    public function test_suggests_correct_location_for_misplaced_file(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson(),
            'Laravel/src/PostController.php' => '<?php',
            'Laravel/PostService.php' => '<?php',
        ]));

        $this->assertFalse($result['ok']);
        $issue = $this->firstWithCode($result['errors'], 'path.not_allowed');
        $this->assertNotNull($issue);
        $this->assertSame('Laravel/PostService.php', $issue['path']);
        $this->assertSame(
            'Laravel/src/Services/PostService.php',
            $issue['suggest']['path'],
            'باید بتواند محل درست را پیشنهاد بدهد'
        );
        $this->assertSame('plugins.contract', $issue['guide'], 'باید به بخش راهنما ارجاع دهد');
    }

    /**
     * B8: پسوند باید **قبل از** نام کلاس بررسی شود. نسخهٔ قبلی `Model.png` را به
     * `Laravel/src/Models/Model.png` می‌فرستاد که هم بی‌معنی است هم یک باگ واقعی.
     */
    public function test_extension_beats_class_name_when_suggesting_location(): void
    {
        $suggest = PluginPackageContract::suggestLocation('Laravel/Model.png');

        $this->assertNotNull($suggest);
        $this->assertSame('Next.js/panel/Model.png', $suggest['path']);
    }

    /**
     * B8 (بخش امنیتی): پسوندهای اسکریپتی **هیچ** پیشنهادی نمی‌گیرند.
     *
     * `Next.js/panel/` هرگز اجرا نمی‌شود، پس پیشنهادش فقط کاربر را به یک پوشهٔ
     * بی‌مصرف می‌فرستد. بدتر: اگر روزی `public/` سرو شود، اسکریپت same-origin
     * روی origin ادمین می‌نشیند و K1.5.8 دور زده می‌شود.
     */
    public function test_never_suggests_a_location_for_script_files(): void
    {
        foreach (['hero.tsx', 'hero.ts', 'hero.js', 'hero.jsx', 'Laravel/hero.tsx'] as $path) {
            $this->assertNull(
                PluginPackageContract::suggestLocation($path),
                'برای «'.$path.'» نباید محلی پیشنهاد شود.'
            );
        }

        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson(),
            'Laravel/hero.tsx' => 'x',
        ]));

        $issue = $this->firstWithCode($result['errors'], 'path.not_allowed');
        $this->assertNotNull($issue);
        $this->assertNull($issue['suggest'], 'پیشنهاد دادن به پوشهٔ اجرانشده بدتر از سکوت است.');
    }

    /**
     * بند ۴ مشخصات کارفرما: ریشهٔ ZIP دو پوشه می‌شود، ولی `manifest.json` باید
     * در ریشه بماند وگرنه امضا معتبر نمی‌ماند.
     */
    public function test_rejects_manifest_inside_a_subfolder(): void
    {
        $result = $this->analyze($this->zip([
            'Laravel/manifest.json' => $this->manifestJson(),
        ]));

        $this->assertFalse($result['ok']);
        $issue = $this->firstWithCode($result['errors'], 'manifest.misplaced');
        $this->assertNotNull($issue);
        $this->assertSame(
            'manifest.json',
            $issue['suggest']['path'],
        );
    }

    /** فاز ۰: `system` از مانیفست پذیرفته نمی‌شود — و راهنما باید بگوید چرا. */
    public function test_rejects_system_flag_in_manifest(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson(['system' => true]),
        ]));

        $this->assertFalse($result['ok']);
        $this->assertContains('manifest.system_forbidden', $this->codes($result['errors']));
    }

    public function test_rejects_private_key_in_package(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson(['secret_key' => 'deadbeef']),
        ]));

        $this->assertFalse($result['ok']);
        $this->assertContains('manifest.leaks_key', $this->codes($result['errors']));
    }

    /**
     * B1: مانیفست **تودرتو** است، پس بررسی سطح‌بالا کافی نبود —
     * `{"publisher":{"private_key":…}}` از validator رد می‌شد در حالی که
     * `PluginTrustStore` کلید را از همان‌جا می‌خواند.
     */
    public function test_rejects_nested_private_key(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson([
                'publisher' => ['name' => 'استودیو', 'key_id' => 'abc123', 'private_key' => 'deadbeef'],
            ]),
        ]));

        $this->assertFalse($result['ok']);
        $issue = $this->firstWithCode($result['errors'], 'manifest.leaks_key');
        $this->assertNotNull($issue);
        $this->assertSame('publisher.private_key', $issue['path'], 'مسیر تودرتو باید گزارش شود.');
    }

    /**
     * `key_id` شناسهٔ عمومی ناشر است و `PluginTrustStore` دقیقاً از آن می‌خواند،
     * پس نباید به‌عنوان نشت کلید گرفته شود. (خودِ اعتماد ناشر جداست.)
     */
    public function test_accepts_publisher_key_id(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson(['publisher' => ['key_id' => 'abc123']]),
        ]));

        $this->assertNotContains('manifest.leaks_key', $this->codes($result['errors']));
        $this->assertNotContains('manifest.leaks_key', $this->codes($result['warnings']));
    }

    public function test_rejects_the_wider_secret_key_list(): void
    {
        $names = ['api_key', 'token', 'password', 'salt', 'passphrase', 'webhook_secret'];

        foreach ($names as $name) {
            $result = $this->analyze($this->zip([
                'manifest.json' => $this->manifestJson(['settings' => [$name => 'leaked']]),
            ]));

            $this->assertFalse($result['ok'], '«'.$name.'» باید رد شود.');
            $issue = $this->firstWithCode($result['errors'], 'manifest.leaks_key');
            $this->assertNotNull($issue, '«'.$name.'» باید تشخیص داده شود.');
            $this->assertSame('settings.'.$name, $issue['path']);
        }
    }

    /**
     * B2: `Str::slug('پنل')` رشتهٔ خالی می‌دهد ⇒ ردیف با `slug=''` و در فاز ۱ یک
     * prefix بی‌نام. پایهٔ کاربر ایرانی است، پس این محتمل است نه نظری.
     */
    public function test_rejects_persian_slug_as_an_error_not_a_warning(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson(['slug' => 'پنل']),
        ]));

        $this->assertFalse($result['ok'], 'اسلاگ فارسی باید نصب را رد کند، نه فقط هشدار بدهد.');
        $issue = $this->firstWithCode($result['errors'], 'manifest.bad_slug');
        $this->assertNotNull($issue);
        $this->assertStringContainsString('انگلیسی', $issue['message']);
        $this->assertSame('^[a-z0-9][a-z0-9._-]{1,39}$', $issue['pattern']);
    }

    public function test_rejects_slug_that_is_too_short(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson(['slug' => 'a']),
        ]));

        $this->assertFalse($result['ok']);
        $this->assertContains('manifest.bad_slug', $this->codes($result['errors']));
    }

    public function test_rejects_case_insensitive_collision(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson(),
            'Laravel/src/Service.php' => '<?php',
            'Laravel/src/service.php' => '<?php',
        ]));

        $this->assertFalse($result['ok']);
        $this->assertContains('path.case_collision', $this->codes($result['errors']));
    }

    /** فهرست نقاط اتصال بسته است (K1.5.6). */
    public function test_rejects_unknown_extension_point(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson([
                'panel' => ['extensions' => [['point' => 'admin.anything']]],
            ]),
        ]));

        $this->assertFalse($result['ok']);
        $issue = $this->firstWithCode($result['errors'], 'panel.unknown_point');
        $this->assertStringContainsString('admin.menu', $issue['message'], 'باید فهرست معتبر را نشان دهد');
    }

    /** کامپوننت React در v1 رد شد؛ باید دلیلش گفته شود نه اینکه بی‌صدا رد شود. */
    public function test_rejects_deferred_extension_point_with_reason(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson([
                'panel' => ['extensions' => [['point' => 'admin.component']]],
            ]),
        ]));

        $this->assertFalse($result['ok']);
        $issue = $this->firstWithCode($result['errors'], 'panel.deferred_point');
        $this->assertNotNull($issue);
        $this->assertStringContainsString('React', $issue['message']);
    }

    /**
     * نقطه‌ای که در قرارداد `status => 'deferred'` دارد نباید اعلام شود: نصب
     * موفق می‌شود و بعد هیچی رندر نمی‌شود — بدترین حالت بی‌صدا.
     */
    public function test_rejects_point_that_is_deferred_in_the_contract(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson([
                'panel' => ['extensions' => [['point' => 'admin.dashboard_slot', 'key' => 'sales']]],
            ]),
        ]));

        $this->assertFalse($result['ok']);
        $issue = $this->firstWithCode($result['errors'], 'panel.deferred_point');
        $this->assertNotNull($issue);
        $this->assertStringContainsString('deferred', $issue['message']);
    }

    /**
     * B10: یک شکل برای یک مفهوم. رشتهٔ ساده در مقایسه نامرئی بود ⇒ اختلاف کاذب.
     */
    public function test_rejects_string_extension_point(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson([
                'panel' => ['extensions' => ['admin.menu']],
            ]),
        ]));

        $this->assertFalse($result['ok']);
        $issue = $this->firstWithCode($result['errors'], 'panel.bad_point');
        $this->assertNotNull($issue);
        $this->assertSame('panel.extensions[0]', $issue['path']);
        $this->assertSame([], $result['summary']['extension_points'], 'رشتهٔ ساده نباید نقطهٔ اعلام‌شده شمرده شود.');
    }

    // ── اعتبارسنجی اعلان نقطهٔ اتصال (K3.4) ────────────────────────────────

    /**
     * مسیر خطا باید دقیقاً بگوید کدام فیلد و چه چیزی مجاز بود — کاربر
     * برنامه‌نویس است و باید بتواند درست کند.
     */
    public function test_reports_the_exact_field_and_what_was_allowed(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson([
                'panel' => ['extensions' => [[
                    'point' => 'admin.menu', 'key' => 'blog', 'label' => 'نوشته‌ها',
                    'href' => 'javascript:alert(1)',
                ]]],
            ]),
        ]));

        $this->assertFalse($result['ok']);
        $issue = $this->firstWithCode($result['errors'], 'admin.menu.bad_pattern');
        $this->assertNotNull($issue);
        $this->assertSame('error', $issue['severity'], 'نقض schema همیشه خطاست، نه هشدار.');
        $this->assertSame('panel.extensions[0].href', $issue['path']);
        $this->assertSame('^/admin(/[a-z0-9._-]+)*$', $issue['pattern']);
    }

    public function test_rejects_external_and_protocol_relative_href(): void
    {
        foreach (['http://evil.com', '//evil.com', 'javascript:alert(1)'] as $href) {
            $result = $this->analyze($this->zip([
                'manifest.json' => $this->manifestJson([
                    'panel' => ['extensions' => [[
                        'point' => 'admin.menu', 'key' => 'blog', 'label' => 'نوشته‌ها', 'href' => $href,
                    ]]],
                ]),
            ]));

            $this->assertFalse($result['ok'], 'href «'.$href.'» باید رد شود.');
            $this->assertNotNull(
                $this->firstWithCode($result['errors'], 'admin.menu.bad_pattern'),
                'href «'.$href.'» باید با الگو گرفته شود.'
            );
        }
    }

    /**
     * الگوی مصوب `[a-z0-9._-]` نقطه را هم می‌پذیرد، پس `..` از آن رد می‌شود.
     * بدون قاعدهٔ جدا، `/admin/../x` یک آیتم منوی معتبر می‌شد که از پیشوند
     * `/admin` بیرون می‌زند.
     */
    public function test_rejects_dot_segments_in_href(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson([
                'panel' => ['extensions' => [[
                    'point' => 'admin.menu', 'key' => 'blog', 'label' => 'نوشته‌ها',
                    'href' => '/admin/../x',
                ]]],
            ]),
        ]));

        $this->assertFalse($result['ok']);
        $this->assertNotNull($this->firstWithCode($result['errors'], 'admin.menu.unsafe_value'));
    }

    /** B26: قرارداد قبلاً به برنامه‌نویس `title`/`path` یاد می‌داد؛ واقعیت `MenuItem` است. */
    public function test_rejects_the_old_wrong_menu_field_names(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson([
                'panel' => ['extensions' => [[
                    'point' => 'admin.menu', 'title' => 'نوشته‌ها', 'path' => '/admin/blog',
                    'icon' => 'pencil', 'permission' => 'posts.view',
                ]]],
            ]),
        ]));

        $this->assertFalse($result['ok']);
        $this->assertNotNull($this->firstWithCode($result['errors'], 'admin.menu.bad_field'));
        $this->assertNotNull($this->firstWithCode($result['errors'], 'admin.menu.forbidden_field'));
        $this->assertNotNull($this->firstWithCode($result['errors'], 'admin.menu.missing_field'));
    }

    /** `component` در همهٔ نقاط ممنوع است (K1.5.8). */
    public function test_rejects_forbidden_capability_in_any_point(): void
    {
        foreach (['component', 'render', 'js', 'endpoint', 'html'] as $field) {
            $result = $this->analyze($this->zip([
                'manifest.json' => $this->manifestJson([
                    'panel' => ['extensions' => [[
                        'point' => 'admin.menu', 'key' => 'blog', 'label' => 'نوشته‌ها',
                        'href' => '/admin/blog', $field => 'x',
                    ]]],
                ]),
            ]));

            $this->assertFalse($result['ok'], '«'.$field.'» باید رد شود.');
            $issue = $this->firstWithCode($result['errors'], 'admin.menu.forbidden_field');
            $this->assertNotNull($issue);
            $this->assertSame(PluginPackageContract::FORBIDDEN, $issue['allowed']);
        }
    }

    /** برخورد با نام هسته باید خطا بدهد، نه اینکه بی‌صدا نادیده گرفته شود. */
    public function test_rejects_core_type_collision(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson([
                'panel' => ['extensions' => [[
                    'point' => 'site.block_type', 'type' => 'hero', 'title_fa' => 'هیروی من',
                ]]],
            ]),
        ]));

        $this->assertFalse($result['ok']);
        $issue = $this->firstWithCode($result['errors'], 'site.block_type.reserved_name');
        $this->assertNotNull($issue);
        $this->assertSame('panel.extensions[0].type', $issue['path']);
    }

    /** واژگان باز `site.page_type` باز است ولی گرامر بسته: آبجکت تودرتو ممنوع. */
    public function test_open_vocabulary_still_forbids_nested_objects(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson([
                'panel' => ['extensions' => [[
                    'point' => 'site.page_type', 'slug' => 'product', 'title_fa' => 'محصول',
                    'specs' => ['weight' => 10],
                ]]],
            ]),
        ]));

        $this->assertFalse($result['ok']);
        $this->assertNotNull($this->firstWithCode($result['errors'], 'site.page_type.nested_value'));
    }

    /** واژگان باز یعنی نام فیلد دلخواه — و مقدار همچنان اسکالر. */
    public function test_open_vocabulary_accepts_arbitrary_field_names(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson([
                'panel' => ['extensions' => [[
                    'point' => 'site.page_type', 'slug' => 'product', 'title_fa' => 'محصول',
                    'price_label' => 'قیمت', 'badge' => 'تخفیف', 'count' => 12, 'active' => true,
                ]]],
            ]),
        ]));

        $this->assertTrue($result['ok'], json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
    }

    public function test_rejects_markup_and_script_schemes(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson([
                'panel' => ['extensions' => [[
                    'point' => 'site.page_type', 'slug' => 'product',
                    'title_fa' => '<img src=x onerror=alert(1)>محصول',
                ]]],
            ]),
        ]));

        $this->assertFalse($result['ok']);
        $this->assertNotNull($this->firstWithCode($result['errors'], 'site.page_type.markup_forbidden'));
    }

    /** پرمیشن باید namespaced باشد — و prefix‌اش باید **همین** اسلاگ باشد. */
    public function test_permission_prefix_must_match_the_manifest_slug(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson([
                'panel' => ['extensions' => [[
                    'point' => 'admin.menu', 'key' => 'blog', 'label' => 'نوشته‌ها',
                    'href' => '/admin/blog', 'permission' => 'plugin:shop:posts.view',
                ]]],
            ]),
        ]));

        $this->assertFalse($result['ok']);
        $issue = $this->firstWithCode($result['errors'], 'admin.menu.bad_permission');
        $this->assertNotNull($issue);
        $this->assertSame('plugin:blog:…', $issue['pattern']);
    }

    public function test_entity_must_be_prefixed_with_the_plugin_slug(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson([
                'panel' => ['extensions' => [[
                    'point' => 'admin.data_collection', 'entity' => 'post', 'title_fa' => 'نوشته‌ها',
                ]]],
            ]),
        ]));

        $this->assertFalse($result['ok']);

        // دو لایه: شکل prefix در متد خالص، تطبیق با اسلاگ واقعی در validator.
        $patterns = array_values(array_filter(array_map(
            fn ($i) => $i['pattern'] ?? null,
            array_filter($result['errors'], fn ($i) => $i['code'] === 'admin.data_collection.bad_entity')
        )));

        $this->assertContains('{slug}_{x}', $patterns);
        $this->assertContains('blog_{x}', $patterns);
    }

    public function test_accepts_entity_prefixed_with_the_plugin_slug(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson([
                'panel' => ['extensions' => [[
                    'point' => 'admin.data_collection', 'entity' => 'blog_post', 'title_fa' => 'نوشته‌ها',
                ]]],
            ]),
        ]));

        $this->assertNotContains('admin.data_collection.bad_entity', $this->codes($result['errors']));
    }

    /** سقف تعداد اعلان هر نقطه. */
    public function test_rejects_more_declarations_than_the_point_allows(): void
    {
        $decls = [];
        for ($i = 0; $i < 11; $i++) {
            $decls[] = ['point' => 'admin.menu', 'key' => 'k'.$i, 'label' => 'آیتم', 'href' => '/admin/k'.$i];
        }

        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson(['panel' => ['extensions' => $decls]]),
        ]));

        $this->assertFalse($result['ok']);
        $this->assertNotNull($this->firstWithCode($result['errors'], 'admin.menu.too_many_declarations'));
    }

    /**
     * اعلام `hooks` نه خطاست نه ساکت — فیلدی بی‌اثر است و باید گفته شود.
     *
     * K5.5 «هنوز» را از این متن برداشت: وعدهٔ ساخت dispatcher لغو شده، پس پیام باید
     * بگوید فیلد در قرارداد نیست، نه اینکه فعلاً اجرا نمی‌شود. کد `not_yet_dispatched`
     * عمداً ثابت ماند چون قرارداد عمومی مصرف‌شده توسط فرانت است.
     */
    public function test_warns_that_declared_hooks_field_is_inert(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson(['hooks' => ['order.placed']]),
        ]));

        $this->assertTrue($result['ok']);
        $this->assertContains('hooks.not_yet_dispatched', $this->codes($result['warnings']));

        $message = $this->firstWithCode($result['warnings'], 'hooks.not_yet_dispatched')['message'];
        $this->assertStringContainsString('در قرارداد پلاگین نیست', $message);
        $this->assertStringNotContainsString(
            'هنوز',
            $message,
            'متن نباید وعدهٔ ساخت dispatcher بدهد؛ K5.5 آن را لغو کرد.'
        );
    }

    /** امضای معتبر ولی ناشر ناشناس ⇒ هشدار، نه خطا. این تفکیک کل فاز ۰ است. */
    public function test_warns_when_publisher_is_anonymous(): void
    {
        $result = $this->analyze($this->zip(['manifest.json' => $this->manifestJson()]));

        $this->assertTrue($result['ok']);
        $this->assertContains('signature.anonymous_publisher', $this->codes($result['warnings']));
    }

    public function test_rejects_bad_signature(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson(signature: 'bm90LWEtc2lnbmF0dXJl'),
        ]));

        $this->assertFalse($result['ok']);
        $this->assertContains('signature.invalid', $this->codes($result['errors']));
    }

    public function test_reports_missing_manifest(): void
    {
        $result = $this->analyze($this->zip(['Laravel/src/Thing.php' => '<?php']));

        $this->assertFalse($result['ok']);
        $this->assertContains('manifest.missing', $this->codes($result['errors']));
    }

    /**
     * B32: روی ZIP باز‌نشدنی هیچ `summary`ای ساخته نمی‌شود، پس دست‌زدن به
     * `$summary['slug']` یک undefined-index بود.
     */
    public function test_handles_an_unreadable_zip_without_crashing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'broken').'.zip';
        file_put_contents($path, 'این اصلاً یک ZIP نیست');

        try {
            $result = $this->analyze($path);

            $this->assertFalse($result['ok']);
            $this->assertSame(['zip.unreadable'], $this->codes($result['errors']));
            $this->assertNull($result['summary']['slug']);
            $this->assertNull($result['summary']['version']);
            $this->assertSame([], $result['summary']['extension_points']);
        } finally {
            @unlink($path);
        }
    }

    /**
     * B17: `result()` روی ZIP باز‌نشدنی با `emptySummary()` صدا زده می‌شود، ولی
     * هر فراخوانیِ دیگر هم باید نرمال‌سازی را تحمل کند. این تست عمداً کلیدها را
     * به ترتیب می‌سنجد نه فقط وجودشان را، چون کلیدِ جابه‌جاشده یعنی قراردادِ
     * مصرف‌کنندهٔ فرانت شکسته است.
     */
    public function test_result_normalizes_a_completely_empty_summary(): void
    {
        $result = $this->callResult(false, [], [], null, []);

        $this->assertSame(
            ['errors', 'warnings', 'slug', 'version', 'extension_points'],
            array_keys($result['summary'])
        );
        $this->assertNull($result['summary']['slug']);
        $this->assertNull($result['summary']['version']);
        $this->assertSame([], $result['summary']['extension_points']);
    }

    /** summary نیمه‌پر نباید بقیهٔ کلیدها را حذف کند. */
    public function test_result_fills_the_gaps_of_a_partial_summary(): void
    {
        $result = $this->callResult(false, [], [], null, ['slug' => 'blog']);

        $this->assertSame('blog', $result['summary']['slug']);
        $this->assertArrayHasKey('version', $result['summary']);
        $this->assertArrayHasKey('extension_points', $result['summary']);
        $this->assertArrayHasKey('errors', $result['summary']);
        $this->assertArrayHasKey('warnings', $result['summary']);
    }

    /**
     * B21: عدد ۲۰۰۰ کنار `MAX_FILES` یک آستانهٔ **پیشنهادی** است، نه سقف سخت.
     * باید نام‌دار باشد تا تغییرش یک تصمیم قابل بازبینی باشد نه ویرایش خاموش
     * یک عدد در بدنهٔ متد.
     */
    public function test_recommended_allowed_file_cap_is_a_named_constant(): void
    {
        $constant = (new ReflectionClass(PluginPackageValidator::class))
            ->getConstant('RECOMMENDED_ALLOWED_FILES');

        $this->assertSame(2000, $constant, 'آستانهٔ هشدار باید یک ثابت نام‌دار باشد.');
    }

    /**
     * رفتارِ آستانه جدا از نام‌گذاریِ آن سنجیده می‌شود، پس عدد اینجا عمداً خام
     * است تا نبودِ ثابت باعث نشود این تست هم بی‌صدا رد شود.
     */
    public function test_warns_past_the_recommended_file_cap(): void
    {
        $files = ['manifest.json' => $this->manifestJson()];
        for ($i = 0; $i <= 2000; $i++) {
            $files['Laravel/src/F'.$i.'.php'] = '<?php';
        }

        $result = $this->analyze($this->zip($files));

        $issue = $this->firstWithCode($result['warnings'], 'package.large');
        $this->assertNotNull($issue, 'عبور از سقف پیشنهادی باید هشدار بدهد.');
        // فقط عدد داخل پیام سنجیده می‌شود؛ متن فارسیِ پیام عمداً مقایسه نمی‌شود
        // چون نشانه‌های نامرئی RTL آن را شکننده می‌کنند.
        $this->assertStringContainsString('2000', $issue['message']);
        $this->assertNotContains('zip.too_many_files', $this->codes($result['errors']));
    }

    // ── endpoint ────────────────────────────────────────────────────────────

    public function test_validate_endpoint_returns_200_even_for_broken_package(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $res = $auth->postJson('/api/v1/admin/plugins/validate', [
            'file' => new UploadedFile(
                $this->zip(['Laravel/stray.php' => 'x']), 'p.zip', 'application/zip', null, true
            ),
        ]);

        $res->assertOk()
            ->assertJsonPath('analysis.ok', false)
            ->assertJsonPath('analysis.severity', 'error');

        // قرارداد باید همراه نتیجه بیاید تا فرانت بتواند «بستهٔ مجاز» را نشان دهد.
        // مقایسه روی `path` انجام می‌شود نه `label_fa` — رشتهٔ فارسی نباید در تست
        // مقایسه شود چون نشانه‌های نامرئی RTL می‌توانند آن را شکننده کنند.
        $this->assertSame('manifest.json', $res->json('analysis.contract.allowed.0.path'));
        $this->assertNotEmpty($res->json('analysis.contract.extension_points'));
    }

    public function test_validate_endpoint_exposes_contract_and_extension_points(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $res = $auth->postJson('/api/v1/admin/plugins/validate', [
            'file' => new UploadedFile($this->zip(['manifest.json' => $this->manifestJson()]), 'p.zip', 'application/zip', null, true),
        ])->assertOk();

        $points = array_column($res->json('analysis.contract.extension_points'), 'key');
        $this->assertSame([
            'admin.menu', 'admin.dashboard_slot', 'admin.settings_schema',
            'admin.data_collection', 'site.header_widget', 'site.footer_widget',
            'site.page_type', 'site.block_type', 'core.service_provider',
            'admin.plugin_tools', 'admin.page_registry',
        ], $points);
    }

    /**
 * K1.6.3 — `POST /v1/admin/plugins/validate` باید یک **تابعِ خالص** روی فهرستِ
 * ZIP باشد: نه استخراج، نه نوشتنِ DB، نه نوشتنِ دیسک.
 *
 * ## چرا این تست لازم است وقتی کد ظاهراً درست است
 *
 * `analyze()` امروز فقط `statIndex()` می‌خواند. ولی «امروز» تضمین نیست: یک
 * refactorِ آینده می‌تواند `verifyStaging` را اینجا هم صدا بزند و هیچ تستِ
 * رفتاریِ دیگری **نریزد** — چون هیچ‌کدام به دیسک نگاه نمی‌کنند. این تست دقیقاً
 * همان چیزی را می‌سنجد که نباید عوض شود.
 *
* ## چرا سنجش «کلِ درخت» را رها کردیم
 *
 * نسخهٔ اول این تست کلِ درختِ `storage/app/plugins` را قبل و بعد مقایسه می‌کرد.
 * در اجرای انفرادی درست بود، ولی در این مخزن **همیشه** شکست می‌خورد: چند سشن
 * روی یک کانتینر و یک فایل‌سیستم تست می‌کنند، پس تستِ `ManagersRolesTest` در
 * همان لحظه می‌تواند `storage/app/plugins/demo-blog/…` بسازد و این تست آن را
 * «اثرِ جانبیِ خودش» گزارش کند.
 *
 * یعنی آن شکل، نه یک نگهبان — یک **مزاحم** بود. جایگزینش سنجشِ **منتسب به همین
 * بسته** است: هر نشانه‌ای که فقط از این slug می‌تواند ساخته شود. این هم
 * سخت‌گیرانه‌تر است (اشاره‌گر، مهر، staging، فایلِ دائمی — همه جدا) و هم در
 * برابرِ تستِ موازی مصون.
 */
public function test_validate_endpoint_is_pure_no_extract_no_db_write_no_disk_write(): void
{
    $auth = $this->actingAs($this->owner(), 'sanctum');

    $releases = app(PluginReleaseManager::class);
    $installRoot = $releases->basePath();
    $token = $this->slipName();

    // slugِ یکتا برای همین تست تا مسیرِ مشتق‌شده با تستِ دیگری قاطی نشود.
    $slug = 'validate-purity-'.$token;

    $manifest = $this->manifestJson(['slug' => $slug]);

    $res = $auth->postJson('/api/v1/admin/plugins/validate', [
        'file' => new UploadedFile(
            // بستهٔ **سالم** — اگر خراب باشد، رد شدنش به‌خاطر خطاست و اثرِ
            // جانبی را اصلاً امتحان نمی‌کند.
            $this->zip([
                'manifest.json' => $manifest,
                'Laravel/src/Models/Post.php' => '<?php',
                'Laravel/src/plugin_'.$token.'/Evil.php' => '<?php',
            ]),
            'p.zip', 'application/zip', null, true
        ),
    ])->assertOk();

    // پیش‌نیازِ همهٔ assertionهای بعدی: تحلیل واقعاً اجرا شد و بسته را معتبر یافت.
    // بدون این، یک ردِ زودهنگام همهٔ تست‌ها را سبز می‌کرد.
    $this->assertTrue($res->json('analysis.ok'), 'بسته باید معتبر باشد وگرنه این تست چیزی را ثابت نمی‌کند.');
    $this->assertSame($slug, $res->json('analysis.manifest.slug'));

    // ── اثرِ جانبی ۱: هیچ مسیرِ استخراجی برای این بسته ──
    //
    // `assertDirectoryDoesNotExist` روی هر سه نقطه‌ای که مسیرِ نصب می‌تواند
    // از این بسته بسازد. نبودنِ پوشهٔ بسته خودش کافی نیست: اگر لایه‌ای زودتر
    // staging بسازد، همین سه‌تا می‌مانند ولی `releaseDir` هنوز نیست.
    $this->assertDirectoryDoesNotExist(
        $releases->releaseDir($slug),
        'تحلیل نباید پوشهٔ نسخه بسازد — یعنی نباید استخراجی رخ داده باشد.'
    );
    $this->assertFileDoesNotExist($releases->pointerPath($slug), 'نباید اشاره‌گرِ نسخهٔ فعال نوشته شود.');
    $this->assertSame(
        [],
        glob($releases->slugDir($slug).'*') ?: [],
        'هیچ artifactِ مشتق‌شده از این slug نباید روی دیسک مانده باشد (staging، مهر، نسخه).'
    );

    // ── اثرِ جانبی ۲: نامِ ورودی نباید به مسیرِ نصب نشت کند ──
    //
    // ورودیِ `plugin_<token>` عمداً شبیه الگوی `mkdir plugin_{slug}` است: اگر
    // لایه‌ای slugِ **خامِ بسته** را بی‌پاک‌سازی به مسیر بدهد، همین پوشه ساخته
    // می‌شود. نشانی‌اش یکتایِ همین تست است.
    $this->assertDirectoryDoesNotExist($installRoot.'/plugin_'.$token);
    $this->assertFileDoesNotExist($installRoot.'/plugin_'.$token);

    // ── اثرِ جانبی ۳: فایلِ دائمی روی دیسکِ استوریج ──
    //
    // `upload` بسته را در `storage/app/{disk}/` نگه می‌دارد؛ `validate` نباید.
    // بررسی به slug محدود است تا تستِ موازیِ یک بستهٔ دیگر این را خراب نکند.
    $this->assertSame(
        [],
        glob(Storage::disk('local')->path('plugins/'.$slug.'*')) ?: [],
        'آپلودِ موقت باید پاک شود و فایلِ دائمیِ این بسته نباید بماند.'
    );

    // ── اثرِ جانبی ۴: هیچ نوشتنِ DB ──
    $this->assertSame(
        0,
        Plugin::query()->where('slug', $slug)->count(),
        'تحلیل نباید برای این بسته ردیفی بسازد.'
    );
    $this->assertSame(0, SystemPlugin::query()->where('slug', $slug)->count(), 'بستهٔ تحلیل‌شده نباید به `system_plugins` هم نشت کند.');
}

/** نصب باید با بستهٔ نامعتبر رد شود، و بدنهٔ پاسخ باید جزئیات را داشته باشد. */
    public function test_upload_rejects_broken_package_with_analysis(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => new UploadedFile(
                $this->zip(['manifest.json' => $this->manifestJson(), 'Laravel/StrayController.php' => 'x']),
                'p.zip', 'application/zip', null, true
            ),
        ])->assertStatus(422)
            ->assertJsonPath('analysis.ok', false)
            // کاربر باید دقیقاً بداند فایل کجا باید برود.
            ->assertJsonPath('analysis.errors.0.suggest.path', 'Laravel/src/Http/Controllers/StrayController.php')
            ->assertJsonPath('analysis.errors.0.guide', 'plugins.contract');

        $this->assertDatabaseCount('plugins', 0);
    }

    // ── قرارداد ─────────────────────────────────────────────────────────────

    public function test_contract_is_deny_by_default(): void
    {
        $this->assertTrue(PluginPackageContract::isAllowedPath('Laravel/src/A.php'));
        $this->assertTrue(PluginPackageContract::isAllowedPath('Laravel/src/Http/Controllers/A.php'));
        $this->assertTrue(PluginPackageContract::isAllowedPath('Laravel/database/migrations/x.php'));
        $this->assertTrue(PluginPackageContract::isAllowedPath('Laravel/routes/api.php'));
        $this->assertTrue(PluginPackageContract::isAllowedPath('Laravel/config/plugin.php'));
        $this->assertTrue(PluginPackageContract::isAllowedPath('Next.js/panel/data.json'));
        $this->assertTrue(PluginPackageContract::isAllowedPath('Next.js/blocks/hero.json'));

        $this->assertFalse(PluginPackageContract::isAllowedPath('Laravel/routes/web.php'));
        $this->assertFalse(PluginPackageContract::isAllowedPath('Laravel/app/Http/Kernel.php'));
        $this->assertFalse(PluginPackageContract::isAllowedPath('resources/views/x.blade.php'));
        $this->assertFalse(PluginPackageContract::isAllowedPath('config/app.php'));
        $this->assertFalse(PluginPackageContract::isAllowedPath('Next.js/panel.json'));

        // L-B5 — زنجیرهٔ تأمین: `vendor/` زیرِ هر مقصدِ پوشه‌ای مجاز نیست.
        // پیش از رفع، `Laravel/src/vendor/**` به‌خاطر prefixِ پوشه‌ای ALLOWED
        // می‌شد و کدِ شخص‌ثالثِ بازبینی‌نشده وارد بسته می‌شد.
        $this->assertFalse(PluginPackageContract::isAllowedPath('Laravel/src/vendor/acme/lib/src/Evil.php'));
        $this->assertFalse(PluginPackageContract::isAllowedPath('Laravel/src/Http/vendor/x.php'));
        $this->assertFalse(PluginPackageContract::isAllowedPath('Next.js/panel/vendor/evil.js'));

        // نامِ فایلی که فقط شاملِ رشتهٔ vendor است، سگمنتِ vendor نیست.
        $this->assertTrue(PluginPackageContract::isAllowedPath('Laravel/src/vendor.php'));
    }

    public function test_manifest_path_cannot_be_used_as_a_directory_prefix(): void
    {
        // `manifest.json` مقصد فایلی است نه پوشه؛ نباید `manifest.json/evil.php` را بپذیرد.
        $this->assertFalse(PluginPackageContract::isAllowedPath('manifest.json/evil.php'));
    }

    /**
     * B27: نسخهٔ قبلی یک map دستی بود و هر فیلد تازهٔ قرارداد را بی‌صدا drop
     * می‌کرد — یعنی UI هرچه اضافه می‌شد نمی‌دید.
     */
    public function test_extension_points_expose_the_whole_structure(): void
    {
        $published = [];
        foreach (PluginPackageContract::extensionPoints() as $point) {
            $this->assertSame(PluginPackageContract::EXTENSION_POINTS[$point['key']], array_diff_key($point, ['key' => true, 'forbidden' => true]));
            foreach (array_keys(PluginPackageContract::EXTENSION_POINTS[$point['key']]) as $field) {
                $this->assertArrayHasKey($field, $point, 'فیلد «'.$field.'» از پاسخ API حذف شده است.');
            }
            $published[$point['key']] = $point;
        }

        $this->assertCount(count(PluginPackageContract::EXTENSION_POINTS), $published);
    }

    /** هر نقطه باید وضعیت runtime و درجهٔ باز بودن واقعی‌اش را بگوید. */
    public function test_every_point_declares_status_and_openness(): void
    {
        $expected = [
            'site.page_type' => ['live', 'open_vocabulary'],
            'site.header_widget' => ['declared_only', 'schema_defined'],
            'site.footer_widget' => ['declared_only', 'schema_defined'],
            'site.block_type' => ['declared_only', 'open_vocabulary'],
            'admin.menu' => ['declared_only', 'closed'],
            // K6.7 — `declared_only` بود و به `deprecated` رفت: میکرو-اسکیمای این
            // نقطه با `max_depth => 1` نمی‌تواند اسکیمای JSON تودرتو را حمل کند،
            // پس هیچ مانیفستی قانوناً نمی‌توانست آن را پر کند. جایگزینش کلید
            // سطح‌بالای `settings` است. حذف کامل از فهرست عمداً انجام نشد چون
            // راهنمای تولیدشده و ابزارهای خوانندهٔ این آرایه را می‌شکند.
            'admin.settings_schema' => ['deprecated', 'schema_defined'],
            'admin.data_collection' => ['declared_only', 'schema_defined'],
            'admin.dashboard_slot' => ['deferred', 'closed'],
            // K3.11 — `deferred` بود و به `declared_only` رفت، ولی **برخلاف
            // K6.7 این‌بار schema هم آمد** (فیلدهایش از `normalizePluginTool` و
            // `PluginToolsDrawer` بیرون کشیده شد). یعنی برخلاف
            // `admin.settings_schema`، اینجا «deferred بودن» نشانهٔ نبودِ مصرف‌کننده
            // نبود — مصرف‌کننده از K6.2 حاضر بود و فقط قرارداد عقب بود. `closed`
            // می‌ماند چون فهرست فیلدها بسته است.
            'admin.plugin_tools' => ['declared_only', 'closed'],
            'admin.page_registry' => ['deferred', 'schema_defined'],
            'core.service_provider' => ['declared_only', 'schema_defined'],
        ];

        foreach (PluginPackageContract::extensionPoints() as $point) {
            [$status, $openness] = $expected[$point['key']] ?? [null, null];
            $this->assertSame($status, $point['status'] ?? null, 'وضعیت «'.$point['key'].'»');
            $this->assertSame($openness, $point['openness'] ?? null, 'درجهٔ باز بودن «'.$point['key'].'»');
            $this->assertNotEmpty($point['openness_why'] ?? null, '«'.$point['key'].'» باید دلیلش را توضیح دهد.');
            $this->assertNotEmpty($point['since'] ?? null, '«'.$point['key'].'» باید نسخهٔ معرفی‌اش را داشته باشد.');
            $this->assertSame(1, $point['schema_version'] ?? null);
        }

        // `core.service_provider` در ۱.۵.۰ اضافه شد، نه ۱.۲.0. این تست قبلاً
        // نسخهٔ ثابت ۱.۲.0 را برای همه فرض می‌کرد که با نقطهٔ تازه نمی‌خواند.
        $this->assertSame(
            '1.5.0',
            PluginPackageContract::EXTENSION_POINTS['core.service_provider']['since'] ?? null
        );

        $this->assertCount(count($expected), $expected);
    }

    /**
     * B28: فلگ `json` سیگنال ضدّهمبسته با واقعیت بود — تنها نقطهٔ زنده آن را
     * نداشت ولی ۵ نقطهٔ مرده داشت. حذف شد و `openness` جایش را گرفت.
     */
    public function test_legacy_json_flag_is_gone(): void
    {
        foreach (PluginPackageContract::extensionPoints() as $point) {
            $this->assertArrayNotHasKey('json', $point);
            $this->assertArrayNotHasKey('json', PluginPackageContract::EXTENSION_POINTS[$point['key']]);
        }
    }

    /**
     * B29: کامنتِ نقطهٔ اتصال وعدهٔ کلید `builder` را می‌داد — «کد هسته‌ای که باید
     * merge/dispatch را انجام دهد» — ولی هیچ نقطه‌ای آن را نداشت.
     *
     * پاسخ آن سؤال حالا `status` است (live / declared_only / deferred) و رجیستری‌های
     * واقعی. نام‌بردن کد هسته از داخل مانیفست خودش K1.5.8 است، پس برنمی‌گردد.
     */
    public function test_legacy_builder_key_is_gone(): void
    {
        foreach (PluginPackageContract::extensionPoints() as $point) {
            $this->assertArrayNotHasKey('builder', $point);
            $this->assertArrayNotHasKey('builder', PluginPackageContract::EXTENSION_POINTS[$point['key']]);
        }
    }

    /** `forbidden` سراسری است وگرنه تکرارش یعنی drift. */
    public function test_forbidden_is_a_single_global_list(): void
    {
        foreach (PluginPackageContract::extensionPoints() as $point) {
            $this->assertSame(PluginPackageContract::FORBIDDEN, $point['forbidden']);
            $this->assertArrayNotHasKey('forbidden', PluginPackageContract::EXTENSION_POINTS[$point['key']]);
        }

        $this->assertContains('component', PluginPackageContract::FORBIDDEN);
        $this->assertContains('render', PluginPackageContract::FORBIDDEN);
    }

    /**
     * K3.8 + K3.9 — schema فقط برای نقاطی که **مصرف‌کنندهٔ واقعی** دارند.
     *
     * ⭐ K3.9 این فهرست را از سه به شش برد، ولی **سه** نقطه به آن اضافه شد و یکی
     * تغییر کرد. دلیلش یکی است و در خودِ قرارداد هم نوشته شده:
     *
     *  • `site.header_widget` / `site.footer_widget` — از K6.4 مصرف‌کنندهٔ واقعی
     *    دارند (`ManifestRegistry::widgetSchemas()` کانال `panel.extensions` را
     *    می‌خواند و فرانت رندرش می‌کند). تا K3.9 `fields => []` بود، یعنی **هر**
     *    اعلانی با `no_schema` رد می‌شد با اینکه رجیستری کاملش را می‌خواند.
     *  • `admin.plugin_tools` — از K6.2 مصرف‌کننده دارد
     *    (`ManifestRegistry::pluginTools()` + `PluginToolsDrawer`) ولی خودِ نقطه
     *    `deferred` مانده بود، یعنی validator آن را قبل از بررسی فیلدها رد می‌کرد.
     *
     * و پنج نقطهٔ دیگر **عمداً** اضافه نشدند، چون schema برایشان یا «دروغِ
     * ساختاریافته» است یا کانالِ سوم می‌سازد: `site.block_type` (عمداً رندر
     * نمی‌شود)، `admin.settings_schema` (`deprecated`، منتقل شده)،
     * `admin.page_registry` و `admin.data_collection` (هیچ‌کدام از کانال
     * `panel.extensions` خوانده نمی‌شوند)، `admin.dashboard_slot` (deferred). برای
     * هر کدام دلیلش بالای خودش در `PluginPackageContract` نوشته شده.
     */
    public function test_only_points_with_a_real_consumer_carry_a_schema(): void
    {
        $withSchema = [];
        foreach (PluginPackageContract::EXTENSION_POINTS as $key => $meta) {
            if (($meta['schema']['fields'] ?? []) !== []) {
                $withSchema[] = $key;
            }
        }

        $this->assertSame(
            ['admin.menu', 'site.header_widget', 'site.footer_widget', 'site.page_type', 'core.service_provider', 'admin.plugin_tools'],
            $withSchema,
            'فهرست نقاطِ دارای schema عوض شده. اگر نقطه‌ای اضافه شده، اول ثابت کن '
            .'مصرف‌کننده‌اش وجود دارد؛ اگر کم شده، دلیلش را بالای همان نقطه بنویس.'
        );

        $this->assertTrue(PluginPackageContract::EXTENSION_POINTS['site.page_type']['open_schema']);

        // بقیه میکرو-اسکیمای **بسته** دارند. اگر یکی‌شان باز شود، دیگر
        // `open_vocabulary` نیست ولی برچسبش هنوز همان است — همان شکافی که
        // `PluginContractExampleIntegrityTest` برای `site.block_type` نگهبانی می‌کند.
        foreach (['admin.menu', 'site.header_widget', 'site.footer_widget', 'core.service_provider', 'admin.plugin_tools'] as $closed) {
            $this->assertFalse(
                PluginPackageContract::EXTENSION_POINTS[$closed]['open_schema'],
                '«'.$closed.'» میکرو-اسکیمای بسته دارد پس `open_schema` باید false بماند.'
            );
        }
    }

    /**
     * هر نقطه باید مثال معتبر داشته باشد (B31) — با یک استثنای جدید در K6.7.
     *
     * قبلاً فرض این تست این بود که حتی نقطهٔ بدون schema هم باید مثال نشان دهد و
     * فقط اعتبارسنجی‌اش نکنیم. آن فرض غلط بود: `fields => []` با
     * `open_schema => false` یعنی validator هر اعلانی را با `no_schema` خطای سخت
     * می‌دهد، پس «مثال معتبر» عملاً مثالی بود که سیستم خودش ردش می‌کرد. کاربر
     * مثال را می‌دید، کپی می‌کرد، و خطا می‌گرفت.
     *
     * حالا سه دسته وجود دارد و هر سه باید صادق باشند:
     *   • نقطهٔ زنده یا با اسکیما ⇒ مثال معتبر دارد و باید واقعاً اعتبارسنجی شود
     *   • نقطهٔ `deferred` ⇒ هیچ مثال معتبری ندارد، نبودنش خودش پیام است
     *   • نقطهٔ بدون schema ⇒ مثال معبتَر ندارد، چون هیچ اعلانی معتبر نیست
     *
     * قاعدهٔ کلی این invariant در `PluginContractExampleIntegrityTest` است؛ اینجا
     * فقط بررسی می‌شود که مثال‌های موجود واقعاً اعتبارسنجی می‌شوند.
     */
    public function test_every_point_has_a_valid_example(): void
    {
        foreach (PluginPackageContract::EXTENSION_POINTS as $key => $meta) {
            $this->assertNotEmpty($meta['example_bad'] ?? [], '«'.$key.'» مثال نامعتبر ندارد.');

            $schemaless = ($meta['schema']['fields'] ?? []) === [] && ($meta['open_schema'] ?? false) === false;

            if (($meta['status'] ?? '') === 'deferred' || $schemaless) {
                $this->assertSame(
                    [],
                    $meta['example_ok'] ?? null,
                    '«'.$key.'» مثال معتبر ندارد — چون '.($schemaless ? 'اسکیمایی اعلام نشده و validator هر اعلانی را رد می‌کند' : 'نقطه deferred است').'.'
                );

                continue;
            }

            $this->assertNotEmpty($meta['example_ok'] ?? [], '«'.$key.'» مثال معتبر ندارد.');

            foreach ($meta['example_ok'] as $i => $example) {
                $decl = $example;
                unset($decl['point']);
                $issues = PluginPackageContract::validateDeclaration($key, $decl);
                $this->assertSame(
                    [],
                    array_column($issues, 'message'),
                    'مثال معتبر «'.$key.'['.$i.']» خودش رد می‌شود.'
                );
            }
        }
    }

    /** مثال نامعتبر باید واقعاً نامعتبر باشد، وگرنه راهنما دروغ می‌گوید. */
    public function test_every_bad_example_really_fails(): void
    {
        foreach (PluginPackageContract::EXTENSION_POINTS as $key => $meta) {
            if (($meta['status'] ?? '') === 'deferred') {
                continue;
            }
            foreach ($meta['example_bad'] as $example) {
                $this->assertNotEmpty($example['why'] ?? null, '«'.$key.'» دلیل ندارد.');
                $decl = $example['decl'];
                unset($decl['point']);
                $this->assertNotSame(
                    [],
                    PluginPackageContract::validateDeclaration($key, $decl),
                    'مثال نامعتبر «'.$key.'» در واقع معتبر است.'
                );
            }
        }
    }

    /** `free_form` درجهٔ باز بودن نیست: نبودنش یک تصمیم امنیتی است. */
    public function test_free_form_grade_does_not_exist(): void
    {
        foreach (PluginPackageContract::extensionPoints() as $point) {
            $this->assertNotSame('free_form', $point['openness']);
            $this->assertContains($point['openness'], ['closed', 'schema_defined', 'open_vocabulary']);

            // `deprecated` در K6.7 اضافه شد: نقطه‌ای که از راه micro-schema قابل
            // اعلام نبود و جایگزینش اعلام شده. رفتارش مثل `deferred` است — نه
            // زنده است و نه چیزی برای اعتبارسنجی دارد — ولی صریح می‌گوید نویسنده
            // باید برود سراغ جایگزینش.
            $this->assertContains($point['status'], ['live', 'declared_only', 'deferred', 'deprecated']);
        }

        $this->assertArrayNotHasKey('free_form', PluginPackageContract::EXTENSION_POINTS);
    }

    /**
     * فهرست دستیِ نام‌های رزروشده باید با واقعیت کانفیگ هسته یکی بماند، وگرنه
     * دوباره همان «پلاگین نصب شد ولی دیده نشد» برمی‌گردد.
     */
    public function test_reserved_type_names_match_core_config(): void
    {
        $core = array_merge(
            array_keys((array) config('blocks', [])),
            array_keys((array) config('widgets.header', [])),
            array_keys((array) config('widgets.footer', [])),
        );

        sort($core);
        $declared = PluginPackageContract::RESERVED_TYPE_NAMES;
        sort($declared);

        $this->assertSame(array_values(array_unique($core)), $declared);
    }

    // ── validateDeclaration: خالص و عمومی ───────────────────────────────────

    public function test_validate_declaration_is_pure_and_repeatable(): void
    {
        $decl = ['key' => 'blog', 'label' => 'نوشته‌ها', 'href' => 'javascript:x'];

        $first = PluginPackageContract::validateDeclaration('admin.menu', $decl);
        $second = PluginPackageContract::validateDeclaration('admin.menu', $decl);

        $this->assertSame($first, $second, 'متد خالص باید نتیجهٔ یکسان بدهد.');
        $this->assertNotEmpty($first);
        $this->assertSame('error', $first[0]['severity']);

        // ورودی نباید تغییر کند.
        $this->assertSame(['key' => 'blog', 'label' => 'نوشته‌ها', 'href' => 'javascript:x'], $decl);
    }

    public function test_validate_declaration_rejects_unknown_point_without_throwing(): void
    {
        $issues = PluginPackageContract::validateDeclaration('admin.nope', []);

        $this->assertCount(1, $issues);
        $this->assertSame('admin.nope.unknown_point', $issues[0]['code']);
        $this->assertNotEmpty($issues[0]['allowed']);
    }

    public function test_validate_declaration_enforces_the_byte_ceiling(): void
    {
        $issues = PluginPackageContract::validateDeclaration('admin.menu', [
            'key' => 'blog', 'label' => 'نوشته‌ها', 'href' => '/admin/blog',
            'icon' => str_repeat('ا', 5000),
        ]);

        $this->assertNotNull($this->firstWithCode($issues, 'admin.menu.too_large'));
    }

    public function test_validate_declaration_rejects_deeper_grammar(): void
    {
        $issues = PluginPackageContract::validateDeclaration('site.page_type', [
            'slug' => 'product', 'title_fa' => 'محصول', 'rows' => [['a' => 1]],
        ]);

        $this->assertNotNull($this->firstWithCode($issues, 'site.page_type.nested_value'));
    }

    public function test_validate_declaration_enforces_grammar_type_vocabulary(): void
    {
        $this->assertContains('string', PluginPackageContract::GRAMMAR_TYPES);
        $this->assertContains('media_id', PluginPackageContract::GRAMMAR_TYPES);
        $this->assertContains('richtext', PluginPackageContract::GRAMMAR_TYPES);
        $this->assertNotContains('object', PluginPackageContract::GRAMMAR_TYPES);
        $this->assertNotContains('oneOf', PluginPackageContract::GRAMMAR_TYPES);
        $this->assertNotContains('anyOf', PluginPackageContract::GRAMMAR_TYPES);
        $this->assertNotContains('$ref', PluginPackageContract::GRAMMAR_TYPES);

        $this->assertSame(1, PluginPackageContract::GRAMMAR_LIMITS['max_depth']);
        $this->assertSame(65536, PluginPackageContract::GRAMMAR_LIMITS['max_total_bytes']);
        $this->assertSame(50, PluginPackageContract::GRAMMAR_LIMITS['max_enum_members']);
        $this->assertSame(4000, PluginPackageContract::GRAMMAR_LIMITS['max_string_length']);
    }

    // ── کمکی ───────────────────────────────────────────────────────────────

    private function owner(): User
    {
        $this->seed(RolesPermissionsSeeder::class);
        $user = User::query()->create([
            'name' => 'مالک', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Owner!1234'), 'role' => 'admin',
        ]);
        $user->assignRole('owner');

        return $user->fresh();
    }

    private function analyze(string $zipPath): array
    {
        return app(PluginPackageValidator::class)->analyze($zipPath);
    }

    /** نامِ یکتا برای این تست — تا مسیرِ مشتق‌شده با تستِ دیگری قاطی نشود. */
    private function slipName(): string
    {
        return $this->slipName ??= bin2hex(random_bytes(4));
    }

    private ?string $slipName = null;

    /** `result()` خصوصی است، ولی نرمال‌سازی summary یک قرارداد داخلی است که باید محافظت شود. */
    private function callResult(bool $ok, array $errors, array $warnings, ?array $manifest, array $summary): array
    {
        return (new ReflectionMethod(PluginPackageValidator::class, 'result'))
            ->invoke(app(PluginPackageValidator::class), $ok, $errors, $warnings, $manifest, $summary);
    }

    private function manifestJson(array $over = [], ?string $signature = null): string
    {
        $manifest = array_merge([
            'name' => 'پلاگین بلاگ',
            'slug' => 'blog',
            'version' => '1.0.0',
        ], $over);

        $manifest['signature'] = $signature
            ?? app(PluginSignatureVerifier::class)->sign($manifest, $this->secretKey);

        return (string) json_encode($manifest, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, string>  $files
     * @param  string|null  $symlink  نام ورودی‌ای که باید به‌صورت symlink نوشته شود
     * @param  array<string, int>  $modes  نام ورودی ⇒ بیتِ نوعِ UNIX (fifo/socket/device)
     */
    private function zip(array $files, ?string $symlink = null, array $modes = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pkg').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        if ($symlink !== null) {
            $zip->addFromString($symlink, '/etc/passwd');
            // external_attributes: UNIX mode در ۱۶ بیت بالا. S_IFLNK = 0xA000.
            $zip->setExternalAttributesName(
                $symlink,
                ZipArchive::OPSYS_UNIX,
                (0120777 << 16) | 0xA000
            );
        }

        // L-B10 — بیتِ نوعِ fifo/socket/char/block در ۱۶ بیتِ بالا، مطابقِ
        // همان قراردادی که `readEntries()` می‌خواند.
        foreach ($modes as $name => $mode) {
            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, ($mode & 0xF000) << 16);
        }

        $zip->close();

        return $path;
    }

    /** @return list<string> */
    private function codes(array $issues): array
    {
        return array_column($issues, 'code');
    }

    private function firstWithCode(array $issues, string $code): ?array
    {
        foreach ($issues as $i) {
            if (($i['code'] ?? null) === $code) {
                return $i;
            }
        }

        return null;
    }
}
