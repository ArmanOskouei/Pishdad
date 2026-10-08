<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** دسته ۱ (برابری UI/UX) — ستون «نسخه‌ها» + WF-M2 ستون «سلامت سئو» لیست صفحات. */
class PageListExtrasTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'مشتری', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'admin',
        ]);
    }

    public function test_index_includes_revisions_count(): void
    {
        $user = $this->admin();

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

    /** WF-M2 — هر ردیف وضعیت سئو با ایرادهای دقیق دارد؛ عنوان از meta با fallback صفحه. */
    public function test_index_includes_seo_health_flags(): void
    {
        $user = $this->admin();

        $goodTitle = str_repeat('ا', 40);
        $goodDesc = str_repeat('ب', 90);
        Page::query()->create([
            'user_id' => $user->id, 'title' => 'عنوان صفحه', 'slug' => 'seo-good',
            'status' => 'draft', 'blocks' => [],
            'meta' => ['title' => $goodTitle, 'description' => $goodDesc, 'og_image_media_id' => 5],
        ]);

        // عنوان صفحه کوتاه + بدون توضیح/OG و بدون meta.
        Page::query()->create([
            'user_id' => $user->id, 'title' => 'کوتاه', 'slug' => 'seo-bad',
            'status' => 'draft', 'blocks' => [], 'meta' => null,
        ]);

        // نه meta.title و نه عنوان صفحه ⇒ title_empty (بدون title_len هم‌زمان).
        Page::query()->create([
            'user_id' => $user->id, 'title' => '', 'slug' => 'seo-empty',
            'status' => 'draft', 'blocks' => [], 'meta' => ['description' => $goodDesc, 'og_image_media_id' => 5],
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/pages');
        $res->assertOk();

        $rows = collect($res->json('data'))->keyBy('slug');

        $good = $rows->get('seo-good');
        $this->assertTrue($good['seo']['healthy']);
        $this->assertSame([], $good['seo']['issues']);
        $this->assertSame(40, $good['seo']['title_length']);
        $this->assertSame(90, $good['seo']['description_length']);

        $bad = $rows->get('seo-bad');
        $this->assertFalse($bad['seo']['healthy']);
        $this->assertSame(['title_len', 'description_empty', 'no_og'], $bad['seo']['issues']);

        $empty = $rows->get('seo-empty');
        $this->assertFalse($empty['seo']['healthy']);
        $this->assertSame(['title_empty'], $empty['seo']['issues']);
    }

    /** WF-M2 — فیلتر `seo=unhealthy` فقط ردیف‌های دارای ایراد را برمی‌گرداند. */
    public function test_index_seo_unhealthy_filter(): void
    {
        $user = $this->admin();

        Page::query()->create([
            'user_id' => $user->id, 'title' => 'سالم', 'slug' => 'filter-good',
            'status' => 'draft', 'blocks' => [],
            'meta' => [
                'title' => str_repeat('ا', 40),
                'description' => str_repeat('ب', 90),
                'og_image_media_id' => 5,
            ],
        ]);
        Page::query()->create([
            'user_id' => $user->id, 'title' => 'ناسالم', 'slug' => 'filter-bad',
            'status' => 'draft', 'blocks' => [], 'meta' => null,
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/pages?seo=unhealthy');

        $res->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.slug', 'filter-bad')
            ->assertJsonPath('data.0.seo.healthy', false);
    }
}
