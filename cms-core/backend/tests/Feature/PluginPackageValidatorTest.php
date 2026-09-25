<?php

namespace Tests\Feature;

use App\Services\Plugins\PluginPackageContract;
use App\Services\Plugins\PluginPackageValidator;
use App\Services\Plugins\PluginSignatureVerifier;
use App\Models\User;
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
                'panel' => ['extensions' => [['point' => 'admin.menu']]],
            ]),
            'Laravel/src/Models/Post.php' => '<?php',
            'Laravel/routes/api.php' => '<?php',
            'Laravel/database/migrations/2026_01_01_000001_create_blog_posts.php' => '<?php',
            'Next.js/panel.json' => json_encode(['extensions' => [['point' => 'admin.menu']]]),
        ]));

        $this->assertTrue($result['ok'], 'بستهٔ سازگار باید معتبر باشد: '.json_encode($result['errors']));
        $this->assertSame('blog', $result['summary']['slug']);
        $this->assertSame(['admin.menu'], $result['summary']['extension_points']);
    }

    /**
     * مانیفست امضا شده و مرجع است. اگر `panel.json` نقطهٔ اتصالی اعلام کند که در
     * مانیفست نیست، یعنی یکی از این دو امضا نشده و هسته نمی‌داند کدام را باور کند.
     */
    public function test_panel_file_must_agree_with_signed_manifest(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson([
                'panel' => ['extensions' => [['point' => 'admin.menu']]],
            ]),
            'Next.js/panel.json' => json_encode(['extensions' => [
                ['point' => 'admin.menu'],
                ['point' => 'admin.dashboard_slot'],
            ]]),
        ]));

        $this->assertFalse($result['ok']);
        $issue = $this->firstWithCode($result['errors'], 'panel.diverges_from_manifest');
        $this->assertNotNull($issue);
        $this->assertStringContainsString('admin.dashboard_slot', $issue['message']);
    }

    /** بند ۲ خواستهٔ کارفرما: `Laravel/` و `Next.js/` باید شناسایی شوند. */
    public function test_recognizes_laravel_and_nextjs_roots(): void
    {
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson(),
            'Laravel/src/ServiceProvider.php' => '<?php',
            'Next.js/blocks/hero.json' => '{}',
        ]));

        $this->assertTrue($result['ok'], json_encode($result['errors']));
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

    /** دارایی فرانت که جای اشتباه است — باید مسیر درست پیشنهاد شود. */
    public function test_suggests_frontend_for_frontend_asset(): void
    {
        // زیر `Laravel/src/` مجاز است، ولی فایل فرانت آنجا بی‌معنی است.
        // بنابراین باید بیرون از مقصدهای مجاز باشد تا اصلاً خطا بدهد.
        $result = $this->analyze($this->zip([
            'manifest.json' => $this->manifestJson(),
            'Laravel/hero.tsx' => 'x',
        ]));

        $this->assertFalse($result['ok']);
        $issue = $this->firstWithCode($result['errors'], 'path.not_allowed');
        $this->assertNotNull($issue);
        $this->assertSame('Next.js/panel/hero.tsx', $issue['suggest']['path']);
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
        // دو خطا هر دو درست‌اند: فایل در بستهٔ مجاز نیست، و manifest هم سر جای غلط است.
        $this->assertSame(
            ['path.not_allowed', 'manifest.misplaced'],
            $this->codes($result['errors'])
        );
        $this->assertSame(
            'manifest.json',
            $this->firstWithCode($result['errors'], 'manifest.misplaced')['suggest']['path'],
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
        $this->assertNotNull($this->firstWithCode($result['errors'], 'panel.deferred_point'));
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
            'site.page_type', 'site.block_type',
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

        $this->assertFalse(PluginPackageContract::isAllowedPath('Laravel/routes/web.php'));
        $this->assertFalse(PluginPackageContract::isAllowedPath('Laravel/app/Http/Kernel.php'));
        $this->assertFalse(PluginPackageContract::isAllowedPath('resources/views/x.blade.php'));
        $this->assertFalse(PluginPackageContract::isAllowedPath('config/app.php'));
    }

    public function test_manifest_path_cannot_be_used_as_a_directory_prefix(): void
    {
        // `manifest.json` مقصد فایلی است نه پوشه؛ نباید `manifest.json/evil.php` را بپذیرد.
        $this->assertFalse(PluginPackageContract::isAllowedPath('manifest.json/evil.php'));
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
