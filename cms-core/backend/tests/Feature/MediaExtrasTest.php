<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** دسته ۱ (برابری UI/UX) — کارت مصرف فضای پلن + «بازیابی همه» سطل زباله. */
class MediaExtrasTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مشتری', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'admin',
        ]);
    }

    private function file(User $user, int $size, bool $trashed = false): Media
    {
        $media = Media::query()->create([
            'user_id' => $user->id, 'disk' => 's3', 'path' => 'media/'.uniqid().'.bin',
            'original_name' => 'f.bin', 'mime' => 'application/octet-stream', 'size' => $size,
        ]);
        if ($trashed) {
            $media->delete();
        }

        return $media;
    }

    public function test_usage_reports_used_quota_and_percent(): void
    {
        $user = $this->user();
        $this->file($user, 50_000_000);
        $this->file($user, 60_000_000);
        $this->file($user, 5000, true); // سطل زباله در مصرف حساب نمی‌شود.

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/media/usage');

        // نصبِ مستقل تیرِ کاربر ندارد ⇒ سقفِ ثابتِ خودِ هسته (`budget` = ۱ گیگابایت).
        // مصرف اما همچنان مشترک است: جمعِ همهٔ فایل‌های همهٔ مدیران.
        $res->assertOk()
            ->assertJsonPath('data.used_bytes', 110_000_000)
            ->assertJsonPath('data.quota_bytes', 1024 * 1024 * 1024)
            ->assertJsonPath('data.tier', 'budget');
        $this->assertEquals(10.2, $res->json('data.percent'));
    }

    /** مشترک نصب: «بازیابی همه» همه فایل‌های سطل زباله نصب را برمی‌گرداند. */
    public function test_restore_all_restores_all_trashed(): void
    {
        $user = $this->user();
        $other = $this->user();
        $this->file($user, 100, true);
        $this->file($user, 100, true);
        $this->file($user, 100); // سالم — دست‌نخورده.
        $this->file($other, 100, true); // مدیر دیگر — مشترک است، برمی‌گردد.

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/media/restore-all');

        $res->assertOk()->assertJsonPath('data.restored', 3);
        $this->assertSame(0, Media::query()->onlyTrashed()->count());
    }
}
