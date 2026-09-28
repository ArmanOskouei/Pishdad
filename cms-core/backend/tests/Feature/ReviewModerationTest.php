<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\User;
use App\Services\Plugins\PluginSignatureVerifier;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use ZipArchive;

/**
 * مورد ۶ — گردش تایید پلاگین/قالب:
 * آپلود→pending، فعال‌سازی pending=422، approve→فعال‌سازی OK،
 * reject+note→نمایش note، حذف، استثنای اپراتور.
 */
class ReviewModerationTest extends TestCase
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
        $this->seed(RolesPermissionsSeeder::class);
    }

    private function owner(): User
    {
        $user = User::query()->create([
            'name' => 'مالک', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Owner!1234'), 'role' => 'admin',
        ]);
        $user->assignRole('owner');

        return $user->fresh();
    }

    private function operator(): User
    {
        return User::query()->create([
            'name' => 'اپراتور', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Operator!1234'), 'role' => 'operator',
        ]);
    }

    private function pluginZip(array $manifest): UploadedFile
    {
        $manifest['signature'] = app(PluginSignatureVerifier::class)->sign($manifest, $this->secretKey);
        $path = tempnam(sys_get_temp_dir(), 'plg').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));
        $zip->addFromString('Laravel/src/Plugin.php', '<?php // stub');
        $zip->close();

        return new UploadedFile($path, 'plugin.zip', 'application/zip', null, true);
    }

    private function themeZip(array $manifest): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'thm').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));
        $zip->addFromString('index.html', '<html></html>');
        $zip->close();

        return new UploadedFile($path, 'theme.zip', 'application/zip', null, true);
    }

    public function test_plugin_upload_is_pending_and_activate_blocked_until_approve(): void
    {
        $me = $this->actingAs($this->owner(), 'sanctum');

        $id = $me->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip(['name' => 'پلاگین صف', 'slug' => 'queue-plugin', 'version' => '1.0.0']),
        ])->assertCreated()
            ->assertJsonPath('data.review_status', 'pending')
            ->json('data.id');

        $me->postJson("/api/v1/admin/plugins/{$id}/activate")
            ->assertStatus(422);

        $op = $this->actingAs($this->operator(), 'sanctum');
        $op->getJson('/api/v1/p/central/review/queue')->assertOk();

        $queue = $op->getJson('/api/v1/p/central/review/queue')->json('data');
        $this->assertTrue(collect($queue)->contains(fn ($i) => $i['type'] === 'plugin' && (int) $i['id'] === $id));

        $op->postJson("/api/v1/p/central/review/plugin/{$id}/approve")->assertOk();

        $me = $this->actingAs(User::query()->where('role', 'admin')->first(), 'sanctum');
        $me->postJson("/api/v1/admin/plugins/{$id}/activate")->assertOk()
            ->assertJsonPath('data.active', true);
    }

    public function test_plugin_reject_requires_note_and_note_is_visible(): void
    {
        $owner = $this->owner();
        $me = $this->actingAs($owner, 'sanctum');

        $id = $me->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip(['name' => 'پلاگین مردود', 'slug' => 'rejected-plugin', 'version' => '1.0.0']),
        ])->assertCreated()->json('data.id');

        $op = $this->actingAs($this->operator(), 'sanctum');

        // رد بدون note = 422.
        $op->postJson("/api/v1/p/central/review/plugin/{$id}/reject", [])->assertStatus(422);

        $op->postJson("/api/v1/p/central/review/plugin/{$id}/reject", ['note' => 'کد ناامن: اجرای مستقیم ورودی کاربر.'])
            ->assertOk()
            ->assertJsonPath('data.review_status', 'rejected');

        // مشتری دلیل رد را می‌بیند.
        $this->actingAs($owner->fresh(), 'sanctum')
            ->getJson("/api/v1/admin/plugins/{$id}")
            ->assertOk()
            ->assertJsonPath('data.review_status', 'rejected')
            ->assertJsonPath('data.review_note', 'کد ناامن: اجرای مستقیم ورودی کاربر.');

        // فعال‌سازی مردود = 422.
        $this->actingAs($owner->fresh(), 'sanctum')
            ->postJson("/api/v1/admin/plugins/{$id}/activate")
            ->assertStatus(422);

        // حذف برای آزادسازی فضا آزاد است.
        // K7.8 — slug را **قبل** از uninstall می‌خوانیم، چون بعدش ردیفی نیست.
        $slug = Plugin::query()->findOrFail($id)->slug;

        $this->actingAs($owner->fresh(), 'sanctum')
            ->postJson("/api/v1/admin/plugins/{$id}/uninstall")
            ->assertOk();

        // به‌جای «جدول خالی»، فقط همان بسته باید رفته باشد. این تست مسیرِ
        // افزونهٔ مرکزی را هم می‌زند، پس رکوردش فعال است و «۰ ردیف» معنی ندارد.
        $this->assertNotContains($slug, $this->pluginSlugs());
    }

    public function test_theme_moderation_flow_and_delete(): void
    {
        $owner = $this->owner();
        $me = $this->actingAs($owner, 'sanctum');
        $me->getJson('/api/v1/admin/themes')->assertOk(); // ensureDefault

        $upload = $me->post('/api/v1/admin/themes/upload', [
            'file' => $this->themeZip(['name' => 'قالب صف', 'slug' => 'queue-theme-'.uniqid(), 'version' => '1.0.0']),
        ])->assertCreated()
            ->assertJsonPath('data.review_status', 'pending')
            ->json('data');

        $me->postJson("/api/v1/admin/themes/{$upload['id']}/activate")->assertStatus(422);

        $op = $this->actingAs($this->operator(), 'sanctum');
        $op->postJson("/api/v1/p/central/review/theme/{$upload['id']}/reject", ['note' => 'استایل‌ها با توکن‌های طراحی هم‌خوان نیست.'])
            ->assertOk();

        $this->actingAs($owner->fresh(), 'sanctum')
            ->getJson('/api/v1/admin/themes')->assertOk();

        $list = $this->actingAs($owner->fresh(), 'sanctum')->getJson('/api/v1/admin/themes')->json('data');
        $row = collect($list)->firstWhere('id', $upload['id']);
        $this->assertSame('rejected', $row['review_status']);
        $this->assertSame('استایل‌ها با توکن‌های طراحی هم‌خوان نیست.', $row['review_note']);

        // حذف قالب ردشده (غیرفعال) آزاد است.
        $this->actingAs($owner->fresh(), 'sanctum')
            ->deleteJson("/api/v1/admin/themes/{$upload['id']}")
            ->assertOk();
    }

    public function test_operator_upload_is_auto_approved(): void
    {
        // سوپرادمین از قفل بازبینی مستثناست (سیدر/داخلی‌ها approved) و از گیت پرمیشن هم عبور می‌کند.
        $super = User::query()->create([
            'name' => 'سوپر', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Super!1234'), 'role' => 'operator',
            'is_super_admin' => true, 'is_hidden' => true,
        ]);

        $op = $this->actingAs($super->fresh(), 'sanctum');

        // آپلود مستقیم approved.
        $id = $op->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip(['name' => 'پلاگین داخلی', 'slug' => 'internal-plugin', 'version' => '1.0.0']),
        ])->assertCreated()
            ->assertJsonPath('data.review_status', 'approved')
            ->json('data.id');

        $op->postJson("/api/v1/admin/plugins/{$id}/activate")->assertOk();

        // صف مرکزی برای مشتری عادی خالی از این مورد است (approved).
        $queue = $op->getJson('/api/v1/p/central/review/queue')->assertOk()->json('data');
        $this->assertFalse(collect($queue)->contains(fn ($i) => $i['type'] === 'plugin' && (int) $i['id'] === $id));
    }

    public function test_review_queue_requires_operator(): void
    {
        $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/p/central/review/queue')
            ->assertForbidden();
    }
}
