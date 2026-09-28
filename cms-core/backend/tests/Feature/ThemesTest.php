<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * تسک ۴.۲ — DoD: لیست + آپلود ZIP (manifest + پذیرش signature_valid:false) +
 * فعال‌سازی/پیش‌نمایش/حذف (محافظت از قالب فعال).
 */
class ThemesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local'); // آپلودها به دیسک واقعی نرسند.
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مشتری تست',
            'email' => 'u'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
    }

    private function zipWithManifest(?array $manifest): string
    {
        $path = tempnam(sys_get_temp_dir(), 'theme').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($manifest !== null) {
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));
        }
        $zip->addFromString('index.html', '<html></html>');
        $zip->close();

        return $path;
    }

    private function uploadedTheme(?array $manifest = null): UploadedFile
    {
        $manifest ??= ['name' => 'قالب سپیده', 'slug' => 'sepideh-'.uniqid(), 'version' => '1.2.0'];
        $path = $this->zipWithManifest($manifest);

        return new UploadedFile($path, 'theme.zip', 'application/zip', null, true);
    }

    public function test_index_autoprovides_default_theme(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/themes');

        $res->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('default', $res->json('data.0.slug'));
        $this->assertTrue($res->json('data.0.active'));
    }

    public function test_upload_accepts_zip_with_manifest_and_marks_signature_unverified(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user, 'sanctum')->withHeader('Accept', 'application/json')->post('/api/v1/admin/themes/upload', [
            'file' => $this->uploadedTheme(),
        ]);

        $res->assertCreated();
        $this->assertFalse($res->json('data.signature_valid'));
        $this->assertSame('قالب سپیده', $res->json('data.name'));
        $this->assertSame('1.2.0', $res->json('data.version'));
        $this->assertFalse($res->json('data.active'));
        // مورد ۶: آپلود مشتری → pending.
        $this->assertSame('pending', $res->json('data.review_status'));
    }

    public function test_upload_rejects_non_zip_and_missing_manifest(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $txt = tempnam(sys_get_temp_dir(), 't').'.txt';
        file_put_contents($txt, 'hello');
        $auth->withHeader('Accept', 'application/json')->post('/api/v1/admin/themes/upload', [
            'file' => new UploadedFile($txt, 'theme.txt', 'text/plain', null, true),
        ])->assertStatus(422);

        $auth->withHeader('Accept', 'application/json')->post('/api/v1/admin/themes/upload', [
            'file' => new UploadedFile($this->zipWithManifest(null), 'theme.zip', 'application/zip', null, true),
        ])->assertStatus(422)->assertJsonPath('message', 'فایل manifest.json معتبر در ZIP یافت نشد.');
    }

    public function test_activate_switches_and_preview_returns_url(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');
        $auth->getJson('/api/v1/admin/themes')->assertOk();

        $upload = $auth->withHeader('Accept', 'application/json')->post('/api/v1/admin/themes/upload', [
            'file' => $this->uploadedTheme(),
        ])->assertCreated()->json('data');

        // مورد ۶: فعال‌سازی قبل از تأیید = 422؛ بعد از تأیید اپراتور = OK.
        $auth->postJson("/api/v1/admin/themes/{$upload['id']}/activate")
            ->assertStatus(422);

        $this->approveAsOperator('theme', (int) $upload['id']);

        $auth->postJson("/api/v1/admin/themes/{$upload['id']}/activate")
            ->assertOk()
            ->assertJsonPath('message', 'قالب فعال شد.');

        $list = $auth->getJson('/api/v1/admin/themes')->assertOk()->json('data');
        $active = collect($list)->where('active', true)->values();
        $this->assertCount(1, $active);
        $this->assertSame($upload['id'], $active->first()['id']);

        $auth->postJson("/api/v1/admin/themes/{$upload['id']}/preview")
            ->assertOk()
            ->assertJsonStructure(['data' => ['preview_url']]);
    }

    public function test_delete_protects_active_theme(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');
        $auth->getJson('/api/v1/admin/themes')->assertOk();
        $default = Theme::query()->where('slug', 'default')->firstOrFail();

        $auth->deleteJson("/api/v1/admin/themes/{$default->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'قالب فعال را نمی‌توان حذف کرد. ابتدا قالب دیگری را فعال کنید.');

        $upload = $auth->withHeader('Accept', 'application/json')->post('/api/v1/admin/themes/upload', [
            'file' => $this->uploadedTheme(),
        ])->assertCreated()->json('data');

        $auth->deleteJson("/api/v1/admin/themes/{$upload['id']}")
            ->assertOk()
            ->assertJsonPath('message', 'قالب حذف شد.');
    }

    public function test_theme_survives_creator_deletion(): void
    {
        $creator = $this->user();
        $theme = Theme::query()->create([
            'user_id' => $creator->id, 'name' => 'قالب ماندگار', 'slug' => 'persistent-theme',
            'version' => '1.0.0', 'active' => false,
        ]);

        $creator->delete();

        $this->assertDatabaseHas('themes', ['id' => $theme->id, 'user_id' => null]);
    }

    /** مشترک نصب: قالب نصب‌شده توسط یک مدیر برای همه مدیران قابل فعال‌سازی است. */
    public function test_themes_are_shared_across_managers(): void
    {
        $a = $this->user();
        $b = $this->user();

        $upload = $this->actingAs($a, 'sanctum')->withHeader('Accept', 'application/json')->post('/api/v1/admin/themes/upload', [
            'file' => $this->uploadedTheme(),
        ])->assertCreated()->json('data');

        // تأیید مرکزی یک‌بار؛ سپس مدیر دوم (نه آپلودکننده) فعال می‌کند.
        $this->approveAsOperator('theme', (int) $upload['id']);

        $this->actingAs($b, 'sanctum')->postJson("/api/v1/admin/themes/{$upload['id']}/activate")
            ->assertOk()
            ->assertJsonPath('message', 'قالب فعال شد.');

        $list = $this->actingAs($b, 'sanctum')->getJson('/api/v1/admin/themes')->assertOk()->json('data');
        $this->assertNotEmpty(collect($list)->where('id', $upload['id'])->all());
    }

    private function approveAsOperator(string $type, int $id): void
    {
        $operator = User::query()->create([
            'name' => 'اپراتور', 'email' => 'op'.uniqid().'@example.com',
            'password' => Hash::make('Operator!1234'), 'role' => 'operator',
        ]);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/p/central/review/{$type}/{$id}/approve")
            ->assertOk();

        // actingAs سراسری است؛ به اولین مشتری برمی‌گردیم تا ادامه تست با مالک باشد.
        $owner = User::query()->orderBy('id')->firstOrFail();
        $this->actingAs($owner->fresh(), 'sanctum');
    }
}
