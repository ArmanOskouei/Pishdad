<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** بخش فایل تسک ۱.۱ — DoD: presign → ویرایش → سطل زباله → بازگردانی/حذف دائم. */
class MediaTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مدیر تست',
            'email' => 'm'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
    }

    public function test_presign_returns_signed_upload_url_and_row(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/media/presign', [
            'filename' => 'photo.jpg',
            'mime' => 'image/jpeg',
            'size' => 1024,
        ]);

        $res->assertCreated()->assertJsonPath('message', 'آدرس آپلود آماده شد.');
        $this->assertNotEmpty($res->json('data.upload_url'));
        $this->assertSame('PUT', $res->json('data.method'));
        $this->assertDatabaseHas('media', [
            'id' => $res->json('data.id'),
            'user_id' => $user->id,
            'original_name' => 'photo.jpg',
        ]);
    }

    public function test_presign_rejects_oversize(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/media/presign', [
            'filename' => 'big.bin',
            'mime' => 'application/octet-stream',
            'size' => 60 * 1024 * 1024,
        ])->assertStatus(422);
    }

    public function test_update_alt_and_trash_restore_permanent_cycle(): void
    {
        $user = $this->user();
        $media = Media::query()->create([
            'user_id' => $user->id, 'disk' => 's3', 'path' => 'media/1/a.jpg',
            'original_name' => 'a.jpg', 'mime' => 'image/jpeg', 'size' => 10,
        ]);
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson("/api/v1/admin/media/{$media->id}", ['alt' => 'تصویر محصول'])
            ->assertOk()->assertJsonPath('message', 'فایل به‌روزرسانی شد.');

        // سافت‌دیلیت = سطل زباله: از لیست عادی می‌رود، در trashed=only هست.
        $auth->deleteJson("/api/v1/admin/media/{$media->id}")
            ->assertOk()->assertJsonPath('message', 'فایل به سطل زباله منتقل شد.');
        $this->assertSame(0, $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/media')->json('total'));
        $trash = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/media?trashed=only');
        $trash->assertOk()->assertJsonPath('total', 1);

        // بازگردانی.
        $auth->postJson("/api/v1/admin/media/{$media->id}/restore")
            ->assertOk()->assertJsonPath('message', 'فایل بازگردانده شد.');
        $this->assertSame(1, $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/media')->json('total'));

        // حذف دائم.
        $auth->deleteJson("/api/v1/admin/media/{$media->id}")->assertOk();
        $auth->deleteJson("/api/v1/admin/media/{$media->id}/permanent")
            ->assertOk()->assertJsonPath('message', 'فایل برای همیشه حذف شد.');
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    /** مشترک نصب: فایل هر مدیر برای همه مدیران قابل مشاهده است. */
    public function test_media_is_shared_across_managers(): void
    {
        $owner = $this->user();
        $other = $this->user();

        $mediaId = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/admin/media/presign', [
            'filename' => 'x.jpg',
            'mime' => 'image/jpeg',
            'size' => 5,
        ])->assertCreated()->json('data.id');

        $otherAuth = $this->actingAs($other, 'sanctum');
        $otherAuth->getJson('/api/v1/admin/media')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $mediaId);
        $otherAuth->getJson("/api/v1/admin/media/{$mediaId}")
            ->assertOk()
            ->assertJsonPath('data.id', $mediaId);
        $otherAuth->putJson("/api/v1/admin/media/{$mediaId}", ['alt' => 'ویرایش مشترک'])
            ->assertOk();
    }

    public function test_missing_media_returns_not_found(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/media/999999')
            ->assertNotFound();
    }

    /** فایل PNG واقعی (۱×۱) بدون نیاز به GD — finfo آن را image/png تشخیص می‌دهد. */
    private function pngFile(string $name = 't.png'): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid('t', true).'.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    public function test_content_upload_stores_bytes_and_updates_record(): void
    {
        Storage::fake('s3');
        $user = $this->user();
        $media = Media::query()->create([
            'user_id' => $user->id, 'disk' => 's3', 'path' => 'media/1/t.png',
            'original_name' => 't.png', 'mime' => 'image/png', 'size' => 70,
        ]);

        $this->actingAs($user, 'sanctum')
            ->post("/api/v1/admin/media/{$media->id}/content", ['file' => $this->pngFile()])
            ->assertOk()->assertJsonPath('message', 'فایل ذخیره شد.');

        Storage::disk('s3')->assertExists('media/1/t.png');
        $this->assertSame('image/png', $media->fresh()->mime);
        $this->assertGreaterThan(0, $media->fresh()->size);
    }

    /** مشترک نصب: هر مدیر می‌تواند محتوای هر فایل نصب را بارگذاری کند. */
    public function test_content_upload_is_shared_across_managers(): void
    {
        Storage::fake('s3');
        $owner = $this->user();
        $other = $this->user();
        $media = Media::query()->create([
            'user_id' => $owner->id, 'disk' => 's3', 'path' => 'media/1/x.png',
            'original_name' => 'x.png', 'mime' => 'image/png', 'size' => 5,
        ]);

        $this->actingAs($other, 'sanctum')
            ->post("/api/v1/admin/media/{$media->id}/content", ['file' => $this->pngFile('x.png')])
            ->assertOk()->assertJsonPath('message', 'فایل ذخیره شد.');
    }

    public function test_content_rejects_missing_file(): void
    {
        $user = $this->user();
        $media = Media::query()->create([
            'user_id' => $user->id, 'disk' => 's3', 'path' => 'media/1/y.png',
            'original_name' => 'y.png', 'mime' => 'image/png', 'size' => 5,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/media/{$media->id}/content", [])
            ->assertStatus(422)->assertJsonPath('errors.file.0', 'فایل الزامی است.');
    }
}
