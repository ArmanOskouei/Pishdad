<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageRevision;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** WF-H5 — لغو انتشار: status → draft با حفظ published_revision_id/published_at + ابطال کش. */
class PageUnpublishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    private function user(bool $canEdit = true): User
    {
        $user = User::query()->create([
            'name' => 'کاربر تست',
            'email' => 'unpub'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);

        $user->givePermissionTo($canEdit ? ['pages.view', 'pages.edit'] : ['pages.view']);

        return $user->fresh();
    }

    /** صفحهٔ منتشرشدهٔ آماده (بدون عبور از اندپوینت انتشار تا رکورد شبکه تمیز بماند). */
    private function publishedPage(User $user, string $slug): array
    {
        $page = Page::query()->create([
            'user_id' => $user->id,
            'title' => 'صفحهٔ منتشرشده',
            'slug' => $slug,
            'status' => Page::STATUS_DRAFT,
            'blocks' => [['type' => 'text', 'data' => ['body' => 'محتوای منتشرشده']]],
        ]);
        $revision = $page->snapshot($page->blocks, null, $user->id, 'ایجاد');

        $page->forceFill([
            'status' => Page::STATUS_PUBLISHED,
            'published_revision_id' => $revision->id,
            'published_at' => now()->subDay(),
        ])->save();

        return [$page->fresh(), $revision];
    }

    public function test_unpublish_sets_draft_but_keeps_published_revision_and_date(): void
    {
        $user = $this->user();
        [$page, $revision] = $this->publishedPage($user, 'unpub-keep');
        $publishedAt = $page->published_at?->toIso8601String();

        $res = $this->actingAs($user, 'sanctum')->postJson("/api/v1/admin/pages/{$page->id}/unpublish");

        $res->assertOk()
            ->assertJsonPath('message', 'انتشار صفحه لغو شد.')
            ->assertJsonPath('data.status', 'draft');

        $fresh = $page->fresh();
        $this->assertSame(Page::STATUS_DRAFT, $fresh->status);
        $this->assertSame($revision->id, $fresh->published_revision_id);
        $this->assertSame($publishedAt, $fresh->published_at?->toIso8601String());
        $this->assertFalse($fresh->isPublished());

        // صفحه حذف نشده و تاریخچه هم دست‌نخورده مانده.
        $this->assertNull($fresh->deleted_at);
        $this->assertSame(1, $fresh->revisions()->count());
    }

    public function test_unpublish_dispatches_revalidate_tags(): void
    {
        Http::fake(['*' => Http::response(['message' => 'کش باطل شد.'], 200)]);
        config(['revalidate.url' => 'http://front.local/api/revalidate']);

        $user = $this->user();
        [$page] = $this->publishedPage($user, 'unpub-cache');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/unpublish")
            ->assertOk();

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/api/revalidate')
            && in_array('pages', $req['tags'], true)
            && in_array("page:{$page->slug}", $req['tags'], true)
            && preg_match('/^[0-9a-f]{64}$/', (string) $req['signature']) === 1);
    }

    public function test_unpublish_homepage_invalidates_homepage_tags(): void
    {
        Http::fake(['*' => Http::response(['message' => 'کش باطل شد.'], 200)]);
        config(['revalidate.url' => 'http://front.local/api/revalidate']);

        $user = $this->user();
        [$page] = $this->publishedPage($user, 'home');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/unpublish")
            ->assertOk();

        Http::assertSent(fn ($req) => in_array('site-homepage', $req['tags'], true)
            && in_array('page:home', $req['tags'], true));
    }

    public function test_republish_after_unpublish_works(): void
    {
        $user = $this->user();
        [$page, $firstRevision] = $this->publishedPage($user, 'unpub-republish');

        $auth = $this->actingAs($user, 'sanctum');
        $auth->postJson("/api/v1/admin/pages/{$page->id}/unpublish")->assertOk();
        $this->assertSame(Page::STATUS_DRAFT, $page->fresh()->status);

        // نسخه عمومی قبل از انتشار دوباره دیده نمی‌شود.
        $this->getJson('/api/v1/site/pages/unpub-republish')->assertNotFound();

        $auth->postJson("/api/v1/admin/pages/{$page->id}/publish")
            ->assertOk()
            ->assertJsonPath('message', 'صفحه منتشر شد.');

        $fresh = $page->fresh();
        $this->assertSame(Page::STATUS_PUBLISHED, $fresh->status);
        $this->assertNotNull($fresh->published_revision_id);
        $this->assertNotSame($firstRevision->id, $fresh->published_revision_id);
        $this->assertSame(2, $fresh->revisions()->count());

        $this->getJson('/api/v1/site/pages/unpub-republish')
            ->assertOk()
            ->assertJsonPath('data.slug', 'unpub-republish');
    }

    public function test_unpublish_requires_pages_edit_permission(): void
    {
        $editor = $this->user();
        $viewer = $this->user(false);
        [$page] = $this->publishedPage($editor, 'unpub-perm');

        $this->actingAs($viewer, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/unpublish")
            ->assertForbidden();

        $this->assertSame(Page::STATUS_PUBLISHED, $page->fresh()->status);
    }
}
