<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Admin\PluginController;
use App\Http\Middleware\GateDevModeUpload;
use App\Models\Plugin;
use App\Models\PublisherKey;
use App\Models\Setting;
use App\Models\SystemPlugin;
use App\Models\User;
use App\Services\Plugins\CoreRequirementChecker;
use App\Services\Plugins\DevMode;
use App\Services\Plugins\ManifestRegistry;
use App\Services\Plugins\PluginSignatureVerifier;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/** تسک ۵.۴ — DoD: نصب امضاشده → فعال → هوک‌ها ثبت → rollback؛ امضای نامعتبر = 422. */
class PluginsTest extends TestCase
{
    use RefreshDatabase;

    private string $publicKey;

    private string $secretKey;

    protected function setUp(): void
    {
        parent::setUp();
        $kp = sodium_crypto_sign_keypair();
        $this->publicKey = base64_encode(sodium_crypto_sign_publickey($kp));
        $this->secretKey = base64_encode(sodium_crypto_sign_secretkey($kp));
        config(['plugins.public_key' => $this->publicKey]);
    }

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

    /** ساخت ZIP پلاگین با مانیفست امضاشده/نشده/دست‌کاری‌شده. */
    private function pluginZip(array $manifest, string $mode = 'signed'): UploadedFile
    {
        $verifier = app(PluginSignatureVerifier::class);

        if ($mode === 'signed') {
            $manifest['signature'] = $verifier->sign($manifest, $this->secretKey);
        } elseif ($mode === 'tampered') {
            $manifest['signature'] = $verifier->sign($manifest, $this->secretKey);
            $manifest['name'] .= ' دست‌کاری‌شده';
        }

        return $this->rawZip($manifest);
    }

    private function rawZip(array $manifest): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'plg').'.zip';
        $zip = new ZipArchive;
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            $this->fail('ساخت ZIP آزمایشی ناموفق بود.');
        }
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));
        $zip->addFromString('Laravel/src/Plugin.php', '<?php // stub');
        $zip->close();

        return new UploadedFile($path, 'plugin.zip', 'application/zip', null, true);
    }

    private function manifest(array $over = []): array
    {
        return array_merge([
            'name' => 'پلاگین نمونه', 'slug' => 'sample-plugin', 'version' => '1.0.0',
            'hooks' => ['dashboard.widget', ['hook' => 'ticket.created', 'handler' => 'NotifyAdmin']],
        ], $over);
    }

    /** ساخت ZIP امضاشده با کلیدی دلخواه (برای تست trust store چندناشره). */
    private function pluginZipSignedWith(array $manifest, string $secretKeyBase64): UploadedFile
    {
        $manifest['signature'] = app(PluginSignatureVerifier::class)->sign($manifest, $secretKeyBase64);

        return $this->rawZip($manifest);
    }

    public function test_upload_rejects_missing_or_invalid_signature(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $auth->postJson('/api/v1/admin/plugins/upload', ['file' => $this->pluginZip($this->manifest(), 'unsigned')])
            ->assertStatus(422);

        $auth->postJson('/api/v1/admin/plugins/upload', ['file' => $this->pluginZip($this->manifest(), 'tampered')])
            ->assertStatus(422)->assertJsonPath('message', 'امضای پلاگین معتبر نیست.');

        $this->assertDatabaseCount('plugins', 0);
    }

    /**
     * B3 — middleware واقعاً روی route هست و توکن نامعتبر هم راهی باز نمی‌کند.
     *
     * این تست عمداً «یکپارچه» است نه واحد: هدفش این است که اگر کسی بعداً
     * `devmode.gate` را از آرایهٔ middleware هر دو route برداشت، این تست
     * قرمز شود. تست واحدِ `DevMode` چنین چیزی را نمی‌گیرد.
     */
    public function test_dev_mode_gate_is_wired_and_wrong_token_grants_nothing(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        // حالت توسعه‌دهنده باز است، ولی توکن غلط است.
        app(DevMode::class)->enable('tok-right');
        app(DevMode::class)->forget();

        $auth->withHeader(GateDevModeUpload::HEADER, 'tok-wrong')
            ->post('/api/v1/admin/plugins/upload', ['file' => $this->pluginZip($this->manifest(), 'unsigned')])
            ->assertStatus(422);

        $this->assertSame(0, Plugin::query()->count(), 'توکن نادرست نباید بستهٔ بی‌امضا را نصب کند.');

        // حالت توسعه‌دهنده بسته است، ولی توکن درست ارائه شده.
        app(DevMode::class)->disable();
        app(DevMode::class)->forget();

        $auth->withHeader(GateDevModeUpload::HEADER, 'tok-right')
            ->post('/api/v1/admin/plugins/upload', ['file' => $this->pluginZip($this->manifest(), 'unsigned')])
            ->assertStatus(422);

        $this->assertSame(0, Plugin::query()->count(), 'توکن درست بدون حالت باز نباید چیزی را باز کند.');
    }

    /**
     * تنها مسیر مجاز: حالت باز + توکن درست.
     *
     * و نکتهٔ مهم این تست: حالت توسعه‌دهنده **تأیید نمی‌کند**. بسته با
     * وضعیت `pending` ثبت می‌شود، نه `approved` — چون `isReviewExempt()` فقط
     * سوپرادمین و اپراتور را از بازبینی معاف می‌کند. حالت توسعه‌دهنده
     * راه تست را باز می‌کند، نه راه انتشار.
     */
    public function test_dev_mode_allows_unsigned_upload_when_open_with_valid_token(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        app(DevMode::class)->enable('tok-right');
        app(DevMode::class)->forget();

        $res = $auth->withHeader(GateDevModeUpload::HEADER, 'tok-right')
            ->post('/api/v1/admin/plugins/upload', ['file' => $this->pluginZip($this->manifest(), 'unsigned')]);

        $this->assertSame(201, $res->getStatusCode(), 'پاسخ: '.json_encode($res->json(), JSON_UNESCAPED_UNICODE));

        $id = $res->json('data.id');

        $this->assertNotNull($id);
        $this->assertDatabaseHas('plugins', ['id' => $id, 'review_status' => 'pending']);
    }

    /**
     * K5.9 — کلید مهر باید هنگام **نصب** ساخته شود.
     *
     * این تست از مسیر واقعی endpoint رد می‌شود، چون تست واحدِ `ensureSealKey`
     * فقط ثابت می‌کند متد کار می‌کند — نه اینکه کسی صدایش می‌زند. قبل از این
     * متد هیچ callerی نداشت و K0.4 عملاً پیاده نشده بود.
     */
    public function test_upload_creates_the_seal_key(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $auth->postJson('/api/v1/admin/plugins/upload', ['file' => $this->pluginZip($this->manifest())])
            ->assertStatus(201);

        $this->assertSame(
            1,
            Setting::query()->where('key', 'seal_key_sample-plugin')->count(),
            'نصب باید کلید مهر بسازد.'
        );
    }

    /**
     * حذف افزونه باید کلید مهر را هم پاک کند، وگرنه نصب دوبارهٔ همان slug به
     * کلید قبلی می‌خورد و مهرهایی که با آن زده شده بودند دوباره معتبر می‌شدند.
     */
    public function test_uninstall_removes_the_seal_key(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $id = $auth->postJson('/api/v1/admin/plugins/upload', ['file' => $this->pluginZip($this->manifest())])
            ->assertStatus(201)->json('data.id');

        $this->assertSame(1, Setting::query()->where('key', 'seal_key_sample-plugin')->count());

        $auth->postJson("/api/v1/admin/plugins/{$id}/uninstall")->assertStatus(200);

        $this->assertSame(
            0,
            Setting::query()->where('key', 'seal_key_sample-plugin')->count(),
            'حذف افزونه باید کلید مهر را هم پاک کند.'
        );
    }

    public function test_signed_install_activate_registers_hooks_and_upgrade(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $id = $auth->postJson('/api/v1/admin/plugins/upload', ['file' => $this->pluginZip($this->manifest())])
            ->assertCreated()
            ->assertJsonPath('data.signature_valid', true)
            ->assertJsonPath('data.review_status', 'pending')
            ->json('data.id');

        // مورد ۶: فعال‌سازی قبل از تأیید مرکزی = 422.
        $auth->postJson("/api/v1/admin/plugins/{$id}/activate")
            ->assertStatus(422);

        $this->approveAsOperator('plugin', $id);

        $auth->postJson("/api/v1/admin/plugins/{$id}/activate")->assertOk()
            ->assertJsonPath('data.active', true);

        $this->assertDatabaseCount('plugin_hooks', 2);

        $show = $auth->getJson("/api/v1/admin/plugins/{$id}")->assertOk()->json('data');
        $this->assertEquals(64, strlen($show['checksum']));
        $this->assertCount(2, $show['hooks']);

        // ارتقا به نسخه بالاتر + rollback نسخه قبلی ثبت می‌شود.
        $auth->postJson("/api/v1/admin/plugins/{$id}/upgrade", [
            'file' => $this->pluginZip($this->manifest(['version' => '2.0.0'])),
        ])->assertOk()
            ->assertJsonPath('data.version', '2.0.0')
            ->assertJsonPath('data.previous_version', '1.0.0');

        // نسخه پایین‌تر رد می‌شود.
        $auth->postJson("/api/v1/admin/plugins/{$id}/upgrade", [
            'file' => $this->pluginZip($this->manifest(['version' => '1.5.0'])),
        ])->assertStatus(422);

        $auth->postJson("/api/v1/admin/plugins/{$id}/deactivate")->assertOk();
        $auth->postJson("/api/v1/admin/plugins/{$id}/uninstall")->assertOk();
        $this->assertDatabaseCount('plugins', 0);
    }

    /**
     * فعال‌سازی باید بلافاصله در رجیستری دیده شود، نه بعد از پایان TTL کش.
     * رگرسیون: کش `activeManifests()` اضافه شد و اگر flush نشود، پلاگین
     * تازه‌فعال‌شده ۵ دقیقه نامرئی می‌ماند.
     */
    public function test_activating_plugin_invalidates_manifest_cache(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $id = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest([
                'slug' => 'cache-probe',
                'permissions' => [['module' => 'probe', 'title_fa' => 'پروب', 'actions' => ['view']]],
            ])),
        ])->assertCreated()->json('data.id');

        // قبل از فعال‌سازی نباید در ماژول‌های پرمیشن باشد.
        ManifestRegistry::flushCache();
        $this->assertNotContains(
            'probe',
            array_column(ManifestRegistry::permissionModules(), 'name')
        );

        $this->approveAsOperator('plugin', (int) $id);
        $auth->postJson("/api/v1/admin/plugins/{$id}/activate")->assertOk();

        // بلافاصله بعد از فعال‌سازی باید دیده شود ⇒ کش باطل شده است.
        $this->assertContains(
            'probe',
            array_column(ManifestRegistry::permissionModules(), 'name')
        );
    }

    /** پلاگین در allowlist، حتی با مانیفست غیرسیستمی، محافظت می‌شود. */
    public function test_system_plugin_is_protected(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        // فاز ۰: مانیفست دیگر نمی‌تواند پرچم system را اعطا کند. فقط allowlist.
        SystemPlugin::ensureMany(['core-plugin']);

        $id = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest(['slug' => 'core-plugin'])),
        ])->assertCreated()->json('data.id');

        $this->approveAsOperator('plugin', $id);

        $auth->postJson("/api/v1/admin/plugins/{$id}/activate")->assertOk();
        $auth->postJson("/api/v1/admin/plugins/{$id}/deactivate")->assertStatus(422);
        $auth->postJson("/api/v1/admin/plugins/{$id}/uninstall")->assertStatus(422);
    }

    /**
     * رگرسیون امنیتی فاز ۰: `system: true` در مانیفست نباید پلاگین را
     * غیرقابل‌حذف کند.
     *
     * با اضافه شدن لایهٔ اعتبارسنجی، این مانیفست **قبل از نصب** رد می‌شود چون
     * فیلد `system` اصلاً در مانیفست پذیرفته نیست. این از آنچه قبلاً انتظار
     * داشتیم قوی‌تر است: کاربر حتی نمی‌تواند چنین بسته‌ای نصب کند.
     */
    public function test_manifest_cannot_grant_system_flag(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest(['slug' => 'sneaky', 'system' => true])),
        ])->assertStatus(422)
            ->assertJsonPath('analysis.errors.0.code', 'manifest.system_forbidden');

        $this->assertDatabaseCount('plugins', 0);
        $this->assertFalse(SystemPlugin::allows('sneaky'));
    }

    /** سه مفهوم اعتماد باید در پاسخ API جدا و صریح باشند. */
    public function test_upload_reports_separated_trust_facts(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        // این مانیفست key_id ناشر ندارد، پس فقط با کلید config تأیید می‌شود.
        $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest(['slug' => 'trust-check'])),
        ])->assertCreated()
            ->assertJsonPath('data.source', 'local')
            // امضا راست است، ولی ناشر برای ما ناشناس است ⇒ publisher_verified=false.
            // این دقیقاً همان تفکیکی است که پیش از فاز ۰ گم بود.
            ->assertJsonPath('data.signature_valid', true)
            ->assertJsonPath('data.trust.publisher_verified', false)
            ->assertJsonPath('data.trust.review_approved', false)
            ->assertJsonPath('data.trust.badge', 'pending')
            // یکپارچگی تا فاز ۱ تأیید نمی‌شود ⇒ fail-closed.
            ->assertJsonPath('data.trust.integrity', 'unverified')
            ->assertJsonPath('data.is_system', false);
    }

    /** کلید ابطال‌شده نباید امضا را معتبر کند (fail-closed). */
    public function test_revoked_publisher_key_fails_verification(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $secret = base64_encode(sodium_crypto_sign_secretkey($keyPair));
        $public = base64_encode(sodium_crypto_sign_publickey($keyPair));
        $fingerprint = PublisherKey::fingerprintOf($public);

        PublisherKey::query()->create([
            'name' => 'ناشر ابطال‌شده',
            'slug' => 'revoked-publisher',
            'public_key' => $public,
            'key_fingerprint' => $fingerprint,
            'status' => PublisherKey::STATUS_REVOKED,
        ]);

        $auth = $this->actingAs($this->owner(), 'sanctum');
        $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZipSignedWith(
                $this->manifest(['slug' => 'from-revoked', 'publisher' => ['key_id' => $fingerprint]]),
                $secret
            ),
        ])->assertStatus(422);

        $this->assertDatabaseCount('plugins', 0);
    }

    /** کلید فعال در trust store باید پذیرفته و key_id ثبت شود. */
    public function test_active_publisher_key_in_trust_store_is_recorded(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $secret = base64_encode(sodium_crypto_sign_secretkey($keyPair));
        $public = base64_encode(sodium_crypto_sign_publickey($keyPair));
        $fingerprint = PublisherKey::fingerprintOf($public);

        PublisherKey::query()->create([
            'name' => 'ناشر معتبر',
            'slug' => 'trusted-publisher',
            'public_key' => $public,
            'key_fingerprint' => $fingerprint,
            'status' => PublisherKey::STATUS_ACTIVE,
            'verified_at' => now(),
        ]);

        $auth = $this->actingAs($this->owner(), 'sanctum');
        $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZipSignedWith(
                $this->manifest(['slug' => 'from-trusted', 'publisher' => ['key_id' => $fingerprint]]),
                $secret
            ),
        ])->assertCreated()
            ->assertJsonPath('data.publisher_key_id', $fingerprint)
            ->assertJsonPath('data.publisher_verified', true)
            // تأیید اداری هنوز انجام نشده ⇒ تأییدنشده، نه تأییدشده.
            ->assertJsonPath('data.trust.review_approved', false);
    }

    /** تأیید مرکزی یک مورد به‌عنوان اپراتور و بازگشت به کاربر مالک (مورد ۶). */
    private function approveAsOperator(string $type, int $id): void
    {
        $operator = User::query()->create([
            'name' => 'اپراتور', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Operator!1234'), 'role' => 'operator',
        ]);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/central/review/{$type}/{$id}/approve")
            ->assertOk();

        // actingAs سراسری است؛ به اولین مالک برمی‌گردیم تا ادامه تست با مالک باشد.
        $owner = User::query()->where('role', 'admin')->orderBy('id')->firstOrFail();
        $this->actingAs($owner->fresh(), 'sanctum');
    }

    /** مشترک نصب: پلاگین نصب‌شده توسط یک مدیر برای همه مدیران قابل مشاهده است. */
    public function test_plugin_is_shared_across_managers(): void
    {
        $me = $this->owner();
        $id = $this->actingAs($me, 'sanctum')
            ->postJson('/api/v1/admin/plugins/upload', ['file' => $this->pluginZip($this->manifest())])
            ->json('data.id');

        $stranger = User::query()->create([
            'name' => 'مدیر دوم', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Other!1234'), 'role' => 'admin',
        ]);
        $stranger->assignRole('owner');

        $this->actingAs($stranger->fresh(), 'sanctum')
            ->getJson("/api/v1/admin/plugins/{$id}")->assertOk()
            ->assertJsonPath('data.id', $id);
        $this->actingAs($stranger->fresh(), 'sanctum')
            ->getJson('/api/v1/admin/plugins')->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->approveAsOperator('plugin', (int) $id);
        $this->actingAs($stranger->fresh(), 'sanctum')
            ->postJson("/api/v1/admin/plugins/{$id}/activate")
            ->assertOk();
    }

    public function test_theme_with_invalid_signature_is_rejected(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $manifest = ['name' => 'قالب خراب', 'slug' => 'bad-theme', 'signature' => 'invalid'];
        $path = tempnam(sys_get_temp_dir(), 'thm').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));
        $zip->close();

        $auth->postJson('/api/v1/admin/themes/upload', [
            'file' => new UploadedFile($path, 'theme.zip', 'application/zip', null, true),
        ])->assertStatus(422);
    }

    // ── K3.0 / B25 — اجباری کردن `requires.core` ─────────────────────────────

    /** ادعای ناسازگار ⇒ نصب اصلاً انجام نمی‌شود: نه ردیف، نه فایل. */
    public function test_upload_rejects_plugin_requiring_a_newer_core(): void
    {
        Storage::fake('local');
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest([
                'slug' => 'future-plugin',
                'requires' => ['core' => '>=99.0.0'],
            ])),
        ])->assertStatus(422)
            ->assertJsonPath('requires.code', 'requires.core.unsatisfied')
            ->assertJsonPath('requires.required', '>=99.0.0')
            ->assertJsonPath('requires.current', CoreRequirementChecker::coreVersion());

        $this->assertDatabaseCount('plugins', 0);
        $this->assertSame([], Storage::disk('local')->files('plugins/shared') ?: []);
    }

    /**
     * اعلامِ ناخوانا هم باید رد شود. سکوت کردن یعنی «سازگار فرض شد» و دقیقاً همان
     * باگی است که `readManifest` (B9) به آن وابسته بود.
     */
    public function test_upload_rejects_unreadable_core_requirement(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        foreach (['totally-bogus', '1.4.0 || 2.0.0', ''] as $core) {
            $auth->postJson('/api/v1/admin/plugins/upload', [
                'file' => $this->pluginZip($this->manifest([
                    'slug' => 'garbled-requirement',
                    'requires' => ['core' => $core],
                ])),
            ])->assertStatus(422)->assertJsonPath('requires.code', 'requires.core.invalid');
        }

        $this->assertDatabaseCount('plugins', 0);
    }

    public function test_upload_accepts_plugin_whose_core_requirement_matches(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest([
                'slug' => 'compatible-plugin',
                'requires' => ['core' => '*'],
            ])),
        ])->assertCreated();
    }

    /** ارتقا به نسخه‌ای که با هسته نمی‌خواند ⇒ 422 و رکورد دست‌نخورده. */
    public function test_upgrade_rejects_incompatible_core_requirement(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $id = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest(['slug' => 'upgrade-core'])),
        ])->assertCreated()->json('data.id');

        $auth->postJson("/api/v1/admin/plugins/{$id}/upgrade", [
            'file' => $this->pluginZip($this->manifest([
                'slug' => 'upgrade-core',
                'version' => '2.0.0',
                'requires' => ['core' => '>=99.0.0'],
            ])),
        ])->assertStatus(422)->assertJsonPath('requires.code', 'requires.core.unsatisfied');

        $this->assertDatabaseHas('plugins', ['id' => $id, 'version' => '1.0.0']);
    }

    // ── B9 — فقط مانیفست ریشهٔ ZIP ───────────────────────────────────────────

    /**
     * رگرسیون B9: `locateName('manifest.json', FL_NODIR)` روی **نام پایه** تطابق
     * می‌دهد، نه مسیر کامل — پس اولین `manifest.json` هر جای ZIP برنده می‌شود.
     *
     * اینجا تودرتو عمداً اول اضافه می‌شود تا فرق دو روش خواندن قابل تشخیص باشد.
     * مانیفست تودرتو همان چیزی است که `slug` و امضا و کل ردیف DB را تعیین می‌کند.
     */
    public function test_read_manifest_ignores_nested_manifest(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'plg').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('Laravel/manifest.json', json_encode(
            ['name' => 'جاسوس', 'slug' => 'nested-imposter', 'version' => '9.9.9'],
            JSON_UNESCAPED_UNICODE
        ));
        $zip->addFromString('manifest.json', json_encode(
            ['name' => 'ریشه', 'slug' => 'root-one', 'version' => '1.0.0'],
            JSON_UNESCAPED_UNICODE
        ));
        $zip->close();

        // اطمینان از غیربنیان بودن تست: روش قدیمی واقعاً مانیفست تودرتو را
        // برمی‌گرداند، پس تست فقط با تغییر رفتار معنادار می‌شود.
        $probe = new ZipArchive;
        $probe->open($path);
        $this->assertSame(0, $probe->locateName('manifest.json', ZipArchive::FL_NODIR));
        $probe->close();

        $controller = app(PluginController::class);
        $method = new \ReflectionMethod($controller, 'readManifest');
        $method->setAccessible(true);

        $this->assertSame('root-one', $method->invoke($controller, $path)['slug'] ?? null);
    }

    /** لایهٔ دفاعی اول: validator بستهٔ دومنیفستی را اصلاً نصب نمی‌کند. */
    public function test_upload_rejects_package_with_nested_manifest(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $path = tempnam(sys_get_temp_dir(), 'plg').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('Laravel/manifest.json', json_encode(
            ['name' => 'جاسوس', 'slug' => 'nested-imposter'], JSON_UNESCAPED_UNICODE
        ));
        $zip->addFromString('manifest.json', json_encode(
            $this->manifest(['slug' => 'root-one']), JSON_UNESCAPED_UNICODE
        ));
        $zip->close();

        $response = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => new UploadedFile($path, 'plugin.zip', 'application/zip', null, true),
        ])->assertStatus(422);

        $codes = array_column($response->json('analysis.errors') ?? [], 'code');
        $this->assertContains('manifest.misplaced', $codes);

        $this->assertDatabaseCount('plugins', 0);
    }

    // ── B11 — ترتیب ذخیره/به‌روزرسانی/حذف ────────────────────────────────────

    /**
     * رگرسیون B11: حذفِ زودهنگام + نوشتنِ ناموفق = پلاگین فعال با `path` که به
     * فایل ناموجود اشاره می‌کند.
     *
     * شکستِ نوشتن با یک دیسکِ ریشه‌ file-به‌جای-پوشه شبیه‌سازی می‌شود، چون
     * Flysystem برای «نشدن ساخت پوشه» exception می‌دهد نه `false`.
     */
    public function test_failed_upgrade_store_keeps_the_installed_version_intact(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $id = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest(['slug' => 'brick-me'])),
        ])->assertCreated()->json('data.id');

        $installed = Plugin::query()->findOrFail($id);
        $oldPath = $installed->path;
        $oldAbsolute = Storage::disk('local')->path($oldPath);
        $this->assertFileExists($oldAbsolute);

        $blocker = tempnam(sys_get_temp_dir(), 'blk');
        config(['filesystems.disks.local' => ['driver' => 'local', 'root' => $blocker]]);
        Storage::forgetDisk('local');

        $auth->postJson("/api/v1/admin/plugins/{$id}/upgrade", [
            'file' => $this->pluginZip($this->manifest(['slug' => 'brick-me', 'version' => '2.0.0'])),
        ])->assertStatus(422);

        $after = $installed->fresh();
        $this->assertSame('1.0.0', $after->version);
        $this->assertNull($after->previous_version);
        $this->assertSame($oldPath, $after->path);
        $this->assertFileExists($oldAbsolute, 'فایل قبلی نباید پیش از نوشتن موفق حذف می‌شد.');

        @unlink($blocker);
    }

    /** مسیر سالم: فایل جدید هست، فایل قبلی پاک شده. */
    public function test_successful_upgrade_replaces_the_stored_file(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $id = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest(['slug' => 'swap-me'])),
        ])->assertCreated()->json('data.id');

        $oldPath = Plugin::query()->findOrFail($id)->path;
        $oldAbsolute = Storage::disk('local')->path($oldPath);

        $auth->postJson("/api/v1/admin/plugins/{$id}/upgrade", [
            'file' => $this->pluginZip($this->manifest(['slug' => 'swap-me', 'version' => '2.0.0'])),
        ])->assertOk()->assertJsonPath('data.version', '2.0.0');

        $newPath = Plugin::query()->findOrFail($id)->path;
        $this->assertNotSame($oldPath, $newPath);
        $this->assertFileExists(Storage::disk('local')->path($newPath));
        $this->assertFileDoesNotExist($oldAbsolute, 'فایل نسخهٔ قبلی باید بعد از ثبت ردیف حذف شود.');
    }

    // ── B20 — پاسخ صادقانهٔ فعال‌سازی ────────────────────────────────────────

    /**
     * `hooks.not_yet_dispatched` هشدار است چون هوک‌ها dispatch نمی‌شوند؛ پس پاسخ
     * فعال‌سازی نباید طوری باشد که UI فکر کند هوک‌ها ثبت و اجرا شدند.
     */
    public function test_activate_response_is_honest_about_hooks(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $id = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest(['slug' => 'hook-claimer'])),
        ])->assertCreated()->json('data.id');

        $this->approveAsOperator('plugin', (int) $id);

        $auth->postJson("/api/v1/admin/plugins/{$id}/activate")->assertOk()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('hooks_dispatched', false);

        $warning = $auth->postJson("/api/v1/admin/plugins/{$id}/activate")->assertOk()
            ->json('warning');

        $this->assertIsString($warning);
        $this->assertStringContainsString('هنوز اجرا نمی‌شوند', $warning);
    }

    /** پلاگین بی‌هوک هشدار نمی‌گیرد — ولی `hooks_dispatched` همچنان صادقانه `false` است. */
    public function test_activate_without_hooks_has_no_warning(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $id = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest(['slug' => 'hookless', 'hooks' => []])),
        ])->assertCreated()->json('data.id');

        $this->approveAsOperator('plugin', (int) $id);

        $auth->postJson("/api/v1/admin/plugins/{$id}/activate")->assertOk()
            ->assertJsonPath('hooks_dispatched', false)
            ->assertJsonPath('warning', null);
    }
}
