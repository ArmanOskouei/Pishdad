<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Plugins\PluginPackageContract;
use App\Services\Plugins\PluginPackageValidator;
use App\Services\Plugins\PluginSignatureVerifier;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
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

    /** هوک‌ها هنوز dispatch نمی‌شوند — باید هشدار داده شود نه اینکه وعدهٔ بی‌صدا بدهند. */
    public function test_warns_that_hooks_are_not_dispatched_yet(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson(['hooks' => ['order.placed']]),
        ]));

        $this->assertTrue($result['ok']);
        $this->assertContains('hooks.not_yet_dispatched', $this->codes($result['warnings']));
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
            'admin.settings_schema' => ['declared_only', 'schema_defined'],
            'admin.data_collection' => ['declared_only', 'schema_defined'],
            'admin.dashboard_slot' => ['deferred', 'closed'],
            'admin.plugin_tools' => ['deferred', 'closed'],
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
     * K3.8: schema فقط برای نقاطی که **مصرف‌کنندهٔ واقعی** دارند — یکی بسته، یکی
     * باز، و `core.service_provider` که اعتبارسنج و رجیستری واقعی دارد.
     * schema برای نقطهٔ بدون مصرف‌کننده یعنی «دروغ ساختاریافته».
     */
    public function test_only_points_with_a_real_consumer_carry_a_schema(): void
    {
        $withSchema = [];
        foreach (PluginPackageContract::EXTENSION_POINTS as $key => $meta) {
            if (($meta['schema']['fields'] ?? []) !== []) {
                $withSchema[] = $key;
            }
        }

        $this->assertSame(['admin.menu', 'site.page_type', 'core.service_provider'], $withSchema);
        $this->assertTrue(PluginPackageContract::EXTENSION_POINTS['site.page_type']['open_schema']);
        $this->assertFalse(PluginPackageContract::EXTENSION_POINTS['admin.menu']['open_schema']);
        $this->assertFalse(PluginPackageContract::EXTENSION_POINTS['core.service_provider']['open_schema']);
    }

    /**
     * هر نقطه باید مثال معتبر داشته باشد (B31) — جز نقطه‌های `deferred` که هیچ
     * اعلان معتبری ندارند و خودِ نبودن مثال پیام است.
     */
    public function test_every_point_has_a_valid_example(): void
    {
        foreach (PluginPackageContract::EXTENSION_POINTS as $key => $meta) {
            $this->assertNotEmpty($meta['example_bad'] ?? [], '«'.$key.'» مثال نامعتبر ندارد.');

            if (($meta['status'] ?? '') === 'deferred') {
                $this->assertSame([], $meta['example_ok'], 'نقطهٔ deferred نباید مثال معتبر داشته باشد.');

                continue;
            }

            $this->assertNotEmpty($meta['example_ok'] ?? [], '«'.$key.'» مثال معتبر ندارد.');

            // نقطهٔ بدون schema نمی‌تواند مثال «معتبر» داشته باشد: اعتبارسنجی
            // هر چیزی را رد می‌کند. با این حال کاربر باید مثال ببیند.
            if (($meta['schema']['fields'] ?? []) === []) {
                continue;
            }

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
            $this->assertContains($point['status'], ['live', 'declared_only', 'deferred']);
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
     */
    private function zip(array $files, ?string $symlink = null): string
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
