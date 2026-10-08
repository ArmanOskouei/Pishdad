<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * E7 — سقف فضای مدیا بر اساس تیرِ مشتری.
 *
 * این تست عمداً **افزوده** بودنِ کار را می‌سنجد: راه‌های قدیمی (presign، آپلود،
 * usage) هستند و کار می‌کنند؛ فقط جایی که از سقف رد می‌شود ۴۲۲ فارسی می‌گیرند.
 *
 * ## چرا رکوردهای مدیا را مستقیم می‌سازیم
 *
 * چون موضوعِ تست «سقف» است، نه «آپلود». ساختن ۱ گیگابایت فایل واقعی در تست
 * یعنی یا ۵۰ آپلودِ ۲۰ مگابایتی (کند و بی‌معنا) یا یک بایتِ ساختگی در جدول —
 * که دقیقاً همان چیزی است که `used` از آن می‌خواند.
 */
class MediaQuotaTest extends TestCase
{
    use RefreshDatabase;

    private const GIB = 1024 * 1024 * 1024;

    public function test_presign_still_works_below_the_quota(): void
    {
        Storage::fake('s3');
        $user = $this->user();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/media/presign', [
                'filename' => 'photo.jpg',
                'mime' => 'image/jpeg',
                'size' => 2048,
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'آدرس آپلود آماده شد.');
    }

    public function test_presign_is_rejected_with_a_persian_message_once_the_quota_is_full(): void
    {
        Storage::fake('s3');
        $user = $this->user();
        $this->fill((int) self::GIB + 1);

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/media/presign', [
            'filename' => 'photo.jpg',
            'mime' => 'image/jpeg',
            'size' => 1024,
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('data.quota_exceeded', true)
            ->assertJsonPath('data.tier', 'budget')
            ->assertJsonPath('data.quota_bytes', self::GIB);

        $this->assertStringContainsString('پلن', $res->json('message'));
        $this->assertStringContainsString('پر است', $res->json('message'));
        $this->assertStringContainsString('گیگابایت', $res->json('message'));

        // یک رکورد فیلر هست؛ رکوردِ آپلود نباید ساخته شده باشد.
        $this->assertDatabaseCount('media', 1);
    }

    public function test_the_guard_uses_the_same_numbers_usage_reports(): void
    {
        Storage::fake('s3');
        $user = $this->user();
        $this->fill(12345);

        $usage = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/media/usage');
        $usage->assertOk()
            ->assertJsonPath('data.used_bytes', 12345)
            ->assertJsonPath('data.quota_bytes', self::GIB)
            ->assertJsonPath('data.tier', 'budget');
    }

    public function test_an_upload_that_would_cross_the_quota_is_rejected(): void
    {
        Storage::fake('s3');
        $user = $this->user();
        $this->fill((int) self::GIB - 4096);

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/media/presign', [
            'filename' => 'photo.jpg',
            'mime' => 'image/jpeg',
            'size' => 1024,
        ]);
        $res->assertCreated();

        $upload = $this->actingAs($user, 'sanctum')->postJson(
            '/api/v1/admin/media/'.$res->json('data.id').'/content',
            ['file' => UploadedFile::fake()->create('photo.jpg', 1024)], // ۱ مگابایت
        );

        $upload->assertStatus(422)
            ->assertJsonPath('data.quota_exceeded', true);

        Storage::disk('s3')->assertMissing($res->json('data.path'));
    }

    public function test_an_upload_that_stays_inside_the_quota_is_allowed(): void
    {
        Storage::fake('s3');
        $user = $this->user();

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/media/presign', [
            'filename' => 'photo.jpg',
            'mime' => 'image/jpeg',
            'size' => 1024,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/media/'.$res->json('data.id').'/content', [
                'file' => UploadedFile::fake()->create('photo.jpg', 1),
            ])
            ->assertOk()
            ->assertJsonPath('message', 'فایل ذخیره شد.');
    }

    /**
     * سقف، مقدارِ ثابتِ همین نصب است — نه چیزی که از دادهٔ بیرونی بیاید.
     *
     * پیش‌تر اینجا تستی بود که می‌گفت تیرِ `enterprise` سقفِ ۲۰GB می‌گیرد.
     * آن تست با رفتنِ لایهٔ اشتراک حذف شد: دیگر تیرِ مشتری وجود ندارد، پس
     * سقف هم نمی‌تواند از بیرون بالا برود. این تست همان ادعا را از سمتِ
     * مخالف می‌سنجد و قرارداد را پین می‌کند.
     */
    public function test_the_cap_is_the_fixed_installation_default(): void
    {
        Storage::fake('s3');
        $user = $this->user();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/media/usage')
            ->assertOk()
            ->assertJsonPath('data.tier', 'budget')
            ->assertJsonPath('data.quota_bytes', self::GIB);
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مدیر تست',
            'email' => 'm'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
    }

    /**
     * پرکردن فضای مصرف‌شده با یک رکورد واقعیِ جدول `media`.
     *
     * یک رکورد با `size` دقیق، نه ۱۰۰۰ رکورد: سقف از `SUM(size)` می‌آید و
     * موضوعِ تست «سقف» است نه «آپلود».
     */
    private function fill(int $bytes): void
    {
        Media::query()->insert([[
            'user_id' => null,
            'disk' => 's3',
            'path' => 'media/shared/filler.bin',
            'original_name' => 'filler.bin',
            'mime' => 'application/octet-stream',
            'size' => max(0, $bytes),
            'created_at' => now(),
            'updated_at' => now(),
        ]]);
    }
}
