<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** دسته ۱ (برابری UI/UX) — ستون «نسخه‌ها» لیست صفحات (revisions_count افزودنی). */
class PageListExtrasTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_includes_revisions_count(): void
    {
        $user = User::query()->create([
            'name' => 'مشتری', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'admin',
        ]);

        $page = Page::query()->create([
            'user_id' => $user->id, 'title' => 'خانه', 'slug' => 'home',
            'status' => 'draft', 'blocks' => [],
        ]);
        $page->snapshot([['type' => 'text', 'data' => []]], null, $user->id, 'ویرایش ۱');
        $page->snapshot([['type' => 'text', 'data' => []]], null, $user->id, 'ویرایش ۲');

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/pages');

        $res->assertOk();
        // ساخت مستقیم + ۲ snapshot = ۲ بازبینی.
        $this->assertSame(2, $res->json('data.0.revisions_count'));
        $this->assertSame('خانه', $res->json('data.0.title'));
    }
}
