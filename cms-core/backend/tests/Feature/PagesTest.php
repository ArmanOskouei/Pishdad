<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\RevalidateLog;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** تسک ۱.۱ — DoD: ساخت/ویرایش/انتشار/بازگشت صفحه + رندر عمومی فقط published. */
class PagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // F0.1: `givePermissionTo` روی پرمیشنِ ناموجود خطا می‌دهد ⇒ نقش‌ها و
        // پرمیشن‌ها باید ساخته شوند (`RefreshDatabase` سیدرها را اجرا نمی‌کند).
        $this->seed(RolesPermissionsSeeder::class);
    }

    /**
     * F0.1 — مسیرهای نوشتن صفحه `perm:pages.edit` گرفتند، پس fixture باید این
     * پرمیشن را داشته باشد. قبلاً هر کاربر لاگین‌کرده می‌توانست بنویسد (که خودِ
     * باگ بود) و تست‌ها ناخواسته همان رفتار را تثبیت کرده بودند.
     */
    private function user(array $overrides = []): User
    {
        $user = User::query()->create(array_merge([
            'name' => 'کاربر تست',
            'email' => 'u'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ], $overrides));

        $user->givePermissionTo('pages.view', 'pages.edit');

        return $user->fresh();
    }

    private function blocks(): array
    {
        return [
            ['type' => 'hero', 'data' => ['title' => 'سلام']],
            ['type' => 'text', 'data' => ['body' => 'متن نمونه']],
        ];
    }

    public function test_create_page_starts_draft_with_first_revision(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/pages', [
            'title' => 'درباره ما',
            'slug' => 'about',
            'blocks' => $this->blocks(),
        ]);

        $res->assertCreated()->assertJsonPath('message', 'صفحه ساخته شد.');
        $page = Page::query()->where('slug', 'about')->firstOrFail();
        $this->assertSame('draft', $page->status);
        $this->assertSame(1, $page->revisions()->count());
        $this->assertSame(1, $page->revisions()->first()->version);
    }

    public function test_single_page_flag_defaults_false_and_can_be_read_and_changed(): void
    {
        $user = $this->user();

        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/pages', [
            'title' => 'صفحه تک‌صفحه‌ای',
            'slug' => 'single-flag-default',
            'blocks' => $this->blocks(),
        ]);

        $created->assertCreated()->assertJsonPath('data.is_single', false);
        $page = Page::query()->where('slug', 'single-flag-default')->firstOrFail();
        $this->assertFalse($page->is_single);

        $auth = $this->actingAs($user, 'sanctum');
        $auth->getJson("/api/v1/admin/pages/{$page->id}")
            ->assertOk()
            ->assertJsonPath('data.is_single', false);
        $auth->getJson('/api/v1/admin/pages')
            ->assertOk()
            ->assertJsonPath('data.0.is_single', false);

        $auth->putJson("/api/v1/admin/pages/{$page->id}", ['is_single' => true])
            ->assertOk()
            ->assertJsonPath('data.is_single', true);
        $this->assertTrue($page->fresh()->is_single);

        $auth->putJson("/api/v1/admin/pages/{$page->id}", ['title' => 'عنوان تازه'])
            ->assertOk()
            ->assertJsonPath('data.is_single', true);
        $auth->putJson("/api/v1/admin/pages/{$page->id}", ['is_single' => false])
            ->assertOk()
            ->assertJsonPath('data.is_single', false);
    }

    public function test_single_page_flag_rejects_non_boolean(): void
    {
        $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/v1/admin/pages', [
                'title' => 'مقدار نامعتبر',
                'is_single' => 'yes',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_single');
    }

    public function test_update_creates_new_revision(): void
    {
        $user = $this->user();
        $page = Page::query()->create([
            'user_id' => $user->id, 'title' => 'ت', 'slug' => 't1',
            'status' => 'draft', 'blocks' => [],
        ]);
        $page->snapshot([], null, $user->id, 'ایجاد');

        $res = $this->actingAs($user, 'sanctum')->putJson("/api/v1/admin/pages/{$page->id}", [
            'blocks' => $this->blocks(),
        ]);

        $res->assertOk();
        $this->assertSame(2, $page->fresh()->revisions()->max('version'));
        $this->assertCount(2, $page->fresh()->blocks);
    }

    public function test_update_rejects_unknown_block_type_and_duplicate_slug(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/pages', [
            'title' => 'الف', 'slug' => 'same', 'blocks' => [],
        ])->assertCreated();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/pages', [
            'title' => 'ب', 'slug' => 'same', 'blocks' => [],
        ])->assertStatus(422);

        $bad = $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/pages', [
            'title' => 'ج', 'blocks' => [['type' => 'nope', 'data' => []]],
        ]);
        $bad->assertStatus(422)->assertJsonValidationErrors('blocks.0.type');
        $this->assertSame(
            'نوع بلوک پشتیبانی نمی‌شود.',
            $bad->json('errors')['blocks.0.type'][0]
        );
    }

    public function test_publish_sets_published_and_signed_revalidate_log(): void
    {
        $user = $this->user();
        $page = Page::query()->create([
            'user_id' => $user->id, 'title' => 'ت', 'slug' => 'pub1',
            'status' => 'draft', 'blocks' => $this->blocks(),
        ]);
        $page->snapshot($page->blocks, null, $user->id, 'ایجاد');

        $res = $this->actingAs($user, 'sanctum')->postJson("/api/v1/admin/pages/{$page->id}/publish");

        $res->assertOk()->assertJsonPath('message', 'صفحه منتشر شد.');
        $fresh = $page->fresh();
        $this->assertSame('published', $fresh->status);
        $this->assertNotNull($fresh->published_revision_id);

        $log = RevalidateLog::query()->where('page_id', $page->id)->firstOrFail();
        $this->assertContains("page:{$page->slug}", $log->tags);
        $this->assertNotEmpty($log->signature);

        // ساختار HMAC-SHA256 (hex ۶۴ کاراکتری) + nonce تصادفی.
        $this->assertSame(32, strlen($log->nonce));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $log->signature);
    }

    public function test_publish_dispatches_signed_revalidate_webhook(): void
    {
        Http::fake(['*' => Http::response(['message' => 'کش باطل شد.'], 200)]);
        config(['revalidate.url' => 'http://front.local/api/revalidate']);

        $user = $this->user();
        $page = Page::query()->create([
            'user_id' => $user->id, 'title' => 'ت', 'slug' => 'pub2',
            'status' => 'draft', 'blocks' => $this->blocks(),
        ]);
        $page->snapshot($page->blocks, null, $user->id, 'ایجاد');

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/admin/pages/{$page->id}/publish")->assertOk();

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/api/revalidate')
            && in_array('pages', $req['tags'], true)
            && in_array("page:{$page->slug}", $req['tags'], true)
            && preg_match('/^[0-9a-f]{64}$/', (string) $req['signature']) === 1);
    }

    public function test_publish_succeeds_when_frontend_is_down(): void
    {
        Http::fake(['*' => Http::response('err', 500)]);
        config(['revalidate.url' => 'http://front.local/api/revalidate']);

        $user = $this->user();
        $page = Page::query()->create([
            'user_id' => $user->id, 'title' => 'ت', 'slug' => 'pub3',
            'status' => 'draft', 'blocks' => $this->blocks(),
        ]);
        $page->snapshot($page->blocks, null, $user->id, 'ایجاد');

        // خرابی فرانت هرگز انتشار را نمی‌شکند (fallback همان ISR است).
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/admin/pages/{$page->id}/publish")
            ->assertOk()->assertJsonPath('message', 'صفحه منتشر شد.');
        $this->assertSame('published', $page->fresh()->status);
    }

    public function test_restore_appends_new_revision_preserving_history(): void
    {
        $user = $this->user();
        $page = Page::query()->create([
            'user_id' => $user->id, 'title' => 'ت', 'slug' => 'rst1',
            'status' => 'draft', 'blocks' => [['type' => 'text', 'data' => ['body' => 'قدیمی']]],
        ]);
        $v1 = $page->snapshot($page->blocks, null, $user->id, 'ایجاد');
        $page->snapshot([['type' => 'text', 'data' => ['body' => 'جدید']]], null, $user->id, 'ویرایش');

        $res = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/restore/{$v1->id}");

        $res->assertOk();
        $fresh = $page->fresh();
        $this->assertSame(3, $fresh->revisions()->max('version'));
        $this->assertSame('قدیمی', $fresh->blocks[0]['data']['body']);
        $this->assertSame(3, $fresh->revisions()->count()); // تاریخچه حفظ شده
    }

    public function test_site_renders_only_published_with_cache_header(): void
    {
        $user = $this->user();
        $draft = Page::query()->create([
            'user_id' => $user->id, 'title' => 'پیش‌نویس', 'slug' => 'draft-only',
            'status' => 'draft', 'is_single' => true, 'blocks' => [],
        ]);

        // پیش‌نویس عمومی دیده نمی‌شود.
        $this->getJson('/api/v1/site/pages/draft-only')->assertNotFound();

        // انتشار → عمومی با هدر کش.
        $draft->snapshot([['type' => 'text', 'data' => ['body' => 'سلام دنیا']]], null, $user->id, 'ایجاد');
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/admin/pages/{$draft->id}/publish")->assertOk();

        $res = $this->getJson('/api/v1/site/pages/draft-only');
        $res->assertOk()
            ->assertJsonPath('data.title', 'پیش‌نویس')
            ->assertJsonPath('data.is_single', true)
            ->assertJsonPath('data.blocks.0.data.body', 'سلام دنیا');
        $this->assertStringContainsString('s-maxage=300', $res->headers->get('Cache-Control'));
    }

    /**
     * مشترک نصب: هر مدیری که دسترسی دارد همه صفحات را می‌بیند و ویرایش می‌کند.
     * RBAC تعیین می‌کند چه کاری مجاز است، نه چه داده‌ای دیده می‌شود.
     */
    public function test_pages_are_shared_across_managers(): void
    {
        $owner = $this->user();
        $other = $this->user();

        $pageId = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/admin/pages', [
            'title' => 'صفحه مشترک',
            'slug' => 'shared1',
            'blocks' => $this->blocks(),
        ])->assertCreated()->json('data.id');

        $otherAuth = $this->actingAs($other, 'sanctum');
        $otherAuth->getJson('/api/v1/admin/pages')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $pageId);
        $otherAuth->getJson("/api/v1/admin/pages/{$pageId}")
            ->assertOk()
            ->assertJsonPath('data.title', 'صفحه مشترک');
        $otherAuth->putJson("/api/v1/admin/pages/{$pageId}", ['title' => 'ویرایش مشترک'])
            ->assertOk();
        $otherAuth->postJson("/api/v1/admin/pages/{$pageId}/publish")->assertOk();

        $this->assertSame('ویرایش مشترک', Page::query()->findOrFail($pageId)->title);
    }

    public function test_missing_side_preset_returns_not_found_without_owner_filter(): void
    {
        $user = $this->user();
        $page = Page::query()->create([
            'user_id' => $user->id, 'title' => 'ت', 'slug' => 'missing-preset',
            'status' => 'draft', 'blocks' => [],
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/admin/pages/{$page->id}", ['left_preset_id' => 999999])
            ->assertNotFound()
            ->assertJsonPath('message', 'پریست یافت نشد.');
    }

    public function test_blocks_schema_lists_active_blocks_with_json_schema(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/blocks/schema');

        $res->assertOk();
        $types = collect($res->json('data'))->pluck('type')->all();
        foreach (['hero', 'text', 'image', 'cta', 'gallery'] as $expected) {
            $this->assertContains($expected, $types);
        }
        $hero = collect($res->json('data'))->firstWhere('type', 'hero');
        $this->assertSame('object', $hero['schema']['type']);
    }

    public function test_pages_list_supports_status_filter(): void
    {
        $user = $this->user();
        Page::query()->create(['user_id' => $user->id, 'title' => 'الف', 'slug' => 'f1', 'status' => 'draft', 'blocks' => []]);
        Page::query()->create(['user_id' => $user->id, 'title' => 'ب', 'slug' => 'f2', 'status' => 'published', 'blocks' => []]);

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/pages?status=draft');

        $res->assertOk()->assertJsonPath('total', 1);
    }
}
