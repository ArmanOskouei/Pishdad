<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Page;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** دسته ۱ (برابری UI/UX) — دلتای واقعی «جدید در ۷ روز» آمار داشبورد مشتری. */
class ClientDashboardExtrasTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_includes_real_7d_deltas(): void
    {
        $user = User::query()->create([
            'name' => 'مشتری', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'admin',
        ]);

        Page::query()->create([
            'user_id' => $user->id, 'title' => 'تازه', 'slug' => 'fresh',
            'status' => 'draft', 'blocks' => [],
        ]);
        $oldPage = Page::query()->create([
            'user_id' => $user->id, 'title' => 'قدیمی', 'slug' => 'old',
            'status' => 'draft', 'blocks' => [],
        ]);
        // created_at در fillable نیست — با forceFill به گذشته می‌بریم.
        $oldPage->forceFill(['created_at' => now()->subDays(30)])->save();
        Media::query()->create([
            'user_id' => $user->id, 'disk' => 's3', 'path' => 'media/a.bin',
            'original_name' => 'a.bin', 'mime' => 'application/octet-stream',
            'size' => 10,
        ]);
        Ticket::query()->create([
            'user_id' => $user->id, 'subject' => 'سؤال', 'status' => 'open',
            'priority' => 'normal', 'source' => 'panel',
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/dashboard/stats');

        $res->assertOk()
            ->assertJsonPath('data.pages_count', 2)
            ->assertJsonPath('data.media_count', 1)
            ->assertJsonPath('data.open_tickets', 1)
            ->assertJsonPath('data.recent.pages_new_7d', 1)
            ->assertJsonPath('data.recent.media_new_7d', 1)
            ->assertJsonPath('data.recent.tickets_new_7d', 1);
    }
}
