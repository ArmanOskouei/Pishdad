<?php

namespace Tests\Feature;

use App\Models\PublisherKey;
use App\Models\SystemPlugin;
use App\Models\User;
use App\Services\Plugins\ManifestRegistry;
use App\Services\Plugins\PluginSignatureVerifier;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
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
        $zip->addFromString('bootstrap.php', '<?php // stub');
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
     * غیرقابل‌حذف کند. پیش از این مهاجرت، هر کاربری با پرمیشن plugins.edit
     * می‌توانست بدافزاری نصب کند که هرگز نتواند آن را پاک کند.
     */
    public function test_manifest_cannot_grant_system_flag(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $id = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest(['slug' => 'sneaky', 'system' => true])),
        ])->assertCreated()->json('data.id');

        $this->approveAsOperator('plugin', $id);

        $auth->postJson("/api/v1/admin/plugins/{$id}/activate")->assertOk();
        $auth->postJson("/api/v1/admin/plugins/{$id}/deactivate")->assertOk();
        $auth->postJson("/api/v1/admin/plugins/{$id}/uninstall")->assertOk();
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
}
