<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WF-M5 — عملیات گروهی صفحات: انتشار/لغو انتشار/انتقال به سطل زباله روی چند صفحه.
 *
 * قرارداد: هر شناسه از همان هستهٔ مسیرِ تکی رد می‌شود (قواعد یکی‌اند)، خطای یک
 * شناسه کلِ دسته را نمی‌اندازد و نتیجهٔ هر ردیف جدا برمی‌گردد. پرمیشن بر پایهٔ
 * اکشن است: `pages.edit` برای انتشار/لغو انتشار و `pages.delete` برای سطل زباله.
 */
class PageBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // `givePermissionTo` روی پرمیشنِ ناموجود خطا می‌دهد ⇒ سیدر لازم است.
        $this->seed(RolesPermissionsSeeder::class);
    }

    /** @param list<string> $permissions */
    private function user(array $permissions = ['pages.view', 'pages.edit']): User
    {
        $user = User::query()->create([
            'name' => 'کاربر گروهی',
            'email' => 'bulk'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);

        $user->givePermissionTo($permissions);

        return $user->fresh();
    }

    /** صفحهٔ پیش‌نویس با یک نسخهٔ آغازین (بدون عبور از اندپوینت تا رکورد شبکه تمیز بماند). */
    private function draft(User $user, string $slug): Page
    {
        $page = Page::query()->create([
            'user_id' => $user->id,
            'title' => 'صفحهٔ '.$slug,
            'slug' => $slug,
            'status' => Page::STATUS_DRAFT,
            'blocks' => [['type' => 'text', 'data' => ['body' => 'محتوای '.$slug]]],
        ]);
        $page->snapshot($page->blocks, null, $user->id, 'ایجاد');

        return $page->fresh();
    }

    /** صفحهٔ منتشرشدهٔ آماده با تاریخچهٔ انتشار. @return array{0: Page, 1: int} */
    private function published(User $user, string $slug): array
    {
        $page = $this->draft($user, $slug);
        $revision = $page->revisions()->firstOrFail();

        $page->forceFill([
            'status' => Page::STATUS_PUBLISHED,
            'published_revision_id' => $revision->id,
            'published_at' => now()->subDay(),
        ])->save();

        return [$page->fresh(), (int) $revision->id];
    }

    public function test_bulk_publish_publishes_every_selected_page(): void
    {
        $user = $this->user();
        $ids = [
            $this->draft($user, 'bulk-1')->id,
            $this->draft($user, 'bulk-2')->id,
            $this->draft($user, 'bulk-3')->id,
        ];

        $res = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/pages/bulk', ['ids' => $ids, 'action' => 'publish']);

        $res->assertOk()
            ->assertJsonPath('succeeded', 3)
            ->assertJsonPath('failed', 0)
            ->assertJsonCount(3, 'results');

        foreach ($ids as $id) {
            $page = Page::query()->findOrFail($id);
            $this->assertSame(Page::STATUS_PUBLISHED, $page->status);
            $this->assertNotNull($page->published_revision_id);
            $this->assertNotNull($page->published_at);
            $this->assertTrue($page->isPublished());
            // نسخهٔ انتشار = نسخهٔ تازه (۱ آغازین + ۱ انتشار).
            $this->assertSame(2, $page->revisions()->count());
        }

        $byId = collect($res->json('results'))->keyBy('id');
        foreach ($ids as $id) {
            $this->assertTrue($byId[$id]['ok']);
            $this->assertSame('صفحه منتشر شد.', $byId[$id]['message']);
        }
    }

    public function test_bulk_publish_clears_scheduled_at_like_single_publish(): void
    {
        $user = $this->user();
        $page = $this->draft($user, 'bulk-scheduled');
        $page->forceFill(['scheduled_at' => now()->addDay()])->save();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/pages/bulk', ['ids' => [$page->id], 'action' => 'publish'])
            ->assertOk()
            ->assertJsonPath('results.0.ok', true);

        $fresh = $page->fresh();
        $this->assertSame(Page::STATUS_PUBLISHED, $fresh->status);
        $this->assertNull($fresh->scheduled_at);
    }

    public function test_bulk_unpublish_returns_pages_to_draft_but_keeps_published_data(): void
    {
        $user = $this->user();
        [$a, $revA] = $this->published($user, 'bulk-unpub-a');
        [$b, $revB] = $this->published($user, 'bulk-unpub-b');
        $publishedAts = [$a->published_at?->toIso8601String(), $b->published_at?->toIso8601String()];

        $res = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/pages/bulk', ['ids' => [$a->id, $b->id], 'action' => 'unpublish']);

        $res->assertOk()
            ->assertJsonPath('succeeded', 2)
            ->assertJsonPath('results.0.message', 'انتشار صفحه لغو شد.');

        $freshA = $a->fresh();
        $freshB = $b->fresh();
        foreach ([$freshA, $freshB] as $fresh) {
            $this->assertSame(Page::STATUS_DRAFT, $fresh->status);
            $this->assertNotNull($fresh->published_revision_id);
            $this->assertFalse($fresh->isPublished());
        }
        $this->assertSame($revA, $freshA->published_revision_id);
        $this->assertSame($revB, $freshB->published_revision_id);
        $this->assertSame($publishedAts, [$freshA->published_at?->toIso8601String(), $freshB->published_at?->toIso8601String()]);
        $this->assertSame(1, $freshA->revisions()->count());
    }

    public function test_bulk_unpublish_invalidates_cache_tags_for_each_page(): void
    {
        Http::fake(['*' => Http::response(['message' => 'کش باطل شد.'], 200)]);
        config(['revalidate.url' => 'http://front.local/api/revalidate']);

        $user = $this->user();
        [$a] = $this->published($user, 'bulk-cache-a');
        [$b] = $this->published($user, 'bulk-cache-b');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/pages/bulk', ['ids' => [$a->id, $b->id], 'action' => 'unpublish'])
            ->assertOk();

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/api/revalidate')
            && in_array("page:{$a->slug}", $req['tags'], true));
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/api/revalidate')
            && in_array("page:{$b->slug}", $req['tags'], true));
    }

    public function test_bulk_trash_soft_deletes_pages_and_keeps_them_recoverable(): void
    {
        // سطل زباله `pages.delete` می‌خواهد (`pages.edit` هم لازم است: بازگردانیِ
        // پایانی با `pages.edit` گارد شده) ⇒ مثل `PageDeleteTest` هر دو داده می‌شود.
        $user = $this->user(['pages.view', 'pages.edit', 'pages.delete']);
        $a = $this->draft($user, 'bulk-trash-a');
        $b = $this->draft($user, 'bulk-trash-b');

        $res = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/pages/bulk', ['ids' => [$a->id, $b->id], 'action' => 'trash']);

        $res->assertOk()
            ->assertJsonPath('succeeded', 2)
            ->assertJsonPath('results.0.message', 'صفحه به سطل زباله منتقل شد.');

        foreach ([$a, $b] as $page) {
            $this->assertSoftDeleted('pages', ['id' => $page->id]);
            $this->assertNull(Page::query()->find($page->id));
        }

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$a->id}/restore")
            ->assertOk();
        $this->assertNotNull(Page::query()->find($a->id));
    }

    /** L-B9 — سطل زبالهٔ گروهی هم تگِ خانه را باطل می‌کند (وگرنه `/` کهنه می‌ماند). */
    public function test_bulk_trash_invalidates_homepage_tag(): void
    {
        Http::fake(['*' => Http::response(['message' => 'کش باطل شد.'], 200)]);
        config(['revalidate.url' => 'http://front.local/api/revalidate']);

        $user = $this->user(['pages.view', 'pages.delete']);
        [$home] = $this->published($user, 'home');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/pages/bulk', ['ids' => [$home->id], 'action' => 'trash'])
            ->assertOk();

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/api/revalidate')
            && in_array('site-homepage', $req['tags'], true)
            && in_array('page:home', $req['tags'], true));
    }

    public function test_bulk_publish_requires_pages_edit_permission(): void
    {
        $owner = $this->user();
        $page = $this->draft($owner, 'bulk-perm-edit');
        $viewer = $this->user(['pages.view']);

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/v1/admin/pages/bulk', ['ids' => [$page->id], 'action' => 'publish'])
            ->assertForbidden()
            ->assertJsonPath('message', 'دسترسی مجاز نیست.');

        $this->assertSame(Page::STATUS_DRAFT, $page->fresh()->status);
    }

    public function test_bulk_unpublish_requires_pages_edit_permission(): void
    {
        $owner = $this->user();
        [$page] = $this->published($owner, 'bulk-perm-unpub');
        $viewer = $this->user(['pages.view']);

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/v1/admin/pages/bulk', ['ids' => [$page->id], 'action' => 'unpublish'])
            ->assertForbidden();

        $this->assertSame(Page::STATUS_PUBLISHED, $page->fresh()->status);
    }

    /** سطل زباله `pages.delete` می‌خواهد: ویرایشگر نباید بتواند کل نصب را پاک کند. */
    public function test_bulk_trash_requires_pages_delete_permission(): void
    {
        $owner = $this->user();
        $page = $this->draft($owner, 'bulk-perm-trash');
        $editor = $this->user(['pages.view', 'pages.edit']);

        $this->actingAs($editor, 'sanctum')
            ->postJson('/api/v1/admin/pages/bulk', ['ids' => [$page->id], 'action' => 'trash'])
            ->assertForbidden()
            ->assertJsonPath('message', 'دسترسی مجاز نیست.');

        $this->assertNull($page->fresh()->deleted_at);

        $deleter = $this->user(['pages.view', 'pages.delete']);
        $this->actingAs($deleter, 'sanctum')
            ->postJson('/api/v1/admin/pages/bulk', ['ids' => [$page->id], 'action' => 'trash'])
            ->assertOk()
            ->assertJsonPath('results.0.ok', true);
        $this->assertNotNull($page->fresh()->deleted_at);
    }

    public function test_bulk_requires_authentication(): void
    {
        $this->postJson('/api/v1/admin/pages/bulk', ['ids' => [1], 'action' => 'publish'])
            ->assertUnauthorized();
    }

    /** شناسهٔ ناموجود/سطل‌zbاله‌شده شکستِ موضعی است، نه شکستِ کلِ دسته. */
    public function test_bulk_reports_per_id_failure_map_without_aborting_the_batch(): void
    {
        $user = $this->user(['pages.view', 'pages.edit', 'pages.delete']);
        $ok = $this->draft($user, 'bulk-partial-ok');
        $trashed = $this->draft($user, 'bulk-partial-trashed');
        $trashed->delete();

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/pages/bulk', [
            'ids' => [$ok->id, 999999, $trashed->id],
            'action' => 'publish',
        ]);

        $res->assertOk()
            ->assertJsonPath('succeeded', 1)
            ->assertJsonPath('failed', 2)
            ->assertJsonCount(3, 'results');

        $byId = collect($res->json('results'))->keyBy('id');
        $this->assertTrue($byId[$ok->id]['ok']);
        $this->assertFalse($byId[999999]['ok']);
        $this->assertSame('صفحه یافت نشد.', $byId[999999]['message']);
        $this->assertFalse($byId[$trashed->id]['ok']);
        $this->assertSame('صفحه یافت نشد.', $byId[$trashed->id]['message']);

        // صفحهٔ معتبر واقعاً منتشر شد ⇒ شکستِ بقیه دسته را متوقف نکرد.
        $this->assertSame(Page::STATUS_PUBLISHED, $ok->fresh()->status);
    }

    /** شناسهٔ تکراری یک بار پردازش می‌شود (دو نسخهٔ تازه برای یک صفحه ساخته نشود). */
    public function test_bulk_deduplicates_repeated_ids(): void
    {
        $user = $this->user();
        $page = $this->draft($user, 'bulk-dup');

        $res = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/pages/bulk', ['ids' => [$page->id, $page->id, $page->id], 'action' => 'publish']);

        $res->assertOk()
            ->assertJsonPath('succeeded', 1)
            ->assertJsonCount(1, 'results');

        $this->assertSame(Page::STATUS_PUBLISHED, $page->fresh()->status);
        $this->assertSame(2, $page->revisions()->count());
    }

    public function test_bulk_rejects_empty_or_non_integer_ids(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $auth->postJson('/api/v1/admin/pages/bulk', ['ids' => [], 'action' => 'publish'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');

        $auth->postJson('/api/v1/admin/pages/bulk', ['action' => 'publish'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');

        $auth->postJson('/api/v1/admin/pages/bulk', ['ids' => 'not-an-array', 'action' => 'publish'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');

        $auth->postJson('/api/v1/admin/pages/bulk', ['ids' => ['abc', 0, -3], 'action' => 'publish'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ids.0', 'ids.1', 'ids.2']);
    }

    public function test_bulk_rejects_unknown_action(): void
    {
        $user = $this->user();
        $page = $this->draft($user, 'bulk-bad-action');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/pages/bulk', ['ids' => [$page->id], 'action' => 'forceDelete'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');

        $this->assertSame(Page::STATUS_DRAFT, $page->fresh()->status);
    }

    /** سقفِ ۱۰۰ شناسه: جلوی درخواستِ غول‌پیکر (که هر شناسه یک کار سنگین است). */
    public function test_bulk_caps_number_of_ids(): void
    {
        $user = $this->user();
        $ids = range(1, 101);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/pages/bulk', ['ids' => $ids, 'action' => 'publish'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/admin/pages/bulk', ['ids' => range(1, 100), 'action' => 'publish'])
            ->assertOk()
            ->assertJsonPath('succeeded', 0)
            ->assertJsonPath('failed', 100);
    }
}
