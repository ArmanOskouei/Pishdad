<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\SidePreset;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * WF-M4 — تکثیر صفحه: کلونِ بلوک‌ها، متا/سئو و تنظیماتِ ستون‌های کناری در یک
 * پیش‌نویسِ تازه با اسلاگِ `{slug}-copy` (یکتا در همان زبان).
 *
 * قرارداد: کپی همیشه `draft` است و چیزی از صفحهٔ مبدأ (انتشار، موعد، تاریخچه)
 * را به ارث نمی‌برد؛ تنها محتوا و چیدمانِ ستون‌ها کپی می‌شود.
 */
class PageDuplicateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // `givePermissionTo` روی پرمیشنِ ناموجود خطا می‌دهد ⇒ سیدر لازم است.
        $this->seed(RolesPermissionsSeeder::class);
    }

    private function user(bool $canEdit = true): User
    {
        $user = User::query()->create([
            'name' => 'کاربر تکثیر',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);

        $user->givePermissionTo('pages.view');
        if ($canEdit) {
            $user->givePermissionTo('pages.edit');
        }

        return $user->fresh();
    }

    /** صفحهٔ مبدأ با متا/سئو و هر دو ستونِ کناریِ پریست‌دار. */
    private function page(User $owner, string $slug = 'about', string $locale = Page::LOCALE_DEFAULT): Page
    {
        $left = SidePreset::query()->create([
            'user_id' => $owner->id, 'name' => 'ستون چپ', 'side' => SidePreset::SIDE_LEFT,
            'blocks' => [['type' => 'text', 'data' => ['body' => 'چپ']]],
        ]);
        $right = SidePreset::query()->create([
            'user_id' => $owner->id, 'name' => 'ستون راست', 'side' => SidePreset::SIDE_RIGHT,
            'blocks' => [['type' => 'text', 'data' => ['body' => 'راست']]],
        ]);

        $blocks = [
            ['type' => 'hero', 'data' => ['title' => 'سلام']],
            ['type' => 'text', 'data' => ['body' => 'متن نمونه']],
        ];
        $meta = ['title' => str_repeat('ا', 35), 'description' => str_repeat('ب', 80), 'noindex' => false];

        $page = Page::query()->create([
            'user_id' => $owner->id,
            'title' => 'درباره ما',
            'slug' => $slug,
            'locale' => $locale,
            'status' => Page::STATUS_DRAFT,
            'is_single' => true,
            'blocks' => $blocks,
            'meta' => $meta,
            'left_preset_id' => $left->id,
            'right_preset_id' => $right->id,
            'left_enabled' => true,
            'right_enabled' => false,
        ]);
        $page->snapshot($blocks, $meta, $owner->id, 'ایجاد');

        return $page->fresh();
    }

    public function test_duplicate_creates_draft_copy_with_copy_slug(): void
    {
        $user = $this->user();
        $page = $this->page($user);

        $res = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/duplicate");

        $res->assertCreated()
            ->assertJsonPath('message', 'نسخهٔ تکثیرشده ساخته شد.')
            ->assertJsonPath('data.slug', 'about-copy')
            ->assertJsonPath('data.status', Page::STATUS_DRAFT)
            ->assertJsonPath('data.locale', Page::LOCALE_DEFAULT)
            ->assertJsonPath('data.is_single', true)
            ->assertJsonPath('data.published_revision_id', null)
            ->assertJsonPath('data.published_at', null)
            ->assertJsonPath('data.scheduled_at', null);

        $copy = Page::query()->where('slug', 'about-copy')->firstOrFail();
        $this->assertNotSame($page->id, $copy->id);
        $this->assertSame($user->id, (int) $copy->user_id);
        // عنوان از همه یکسان نباشد تا دو ردیفِ هم‌عنوان در لیست جا نشوند.
        $this->assertSame('درباره ما (کپی)', $copy->title);
    }

    public function test_duplicate_copies_blocks_meta_and_side_presets(): void
    {
        $user = $this->user();
        $page = $this->page($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/duplicate")
            ->assertCreated();

        $copy = Page::query()->where('slug', 'about-copy')->firstOrFail();

        $this->assertSame($page->blocks, $copy->blocks);
        $this->assertSame($page->meta, $copy->meta);
        $this->assertSame((int) $page->left_preset_id, (int) $copy->left_preset_id);
        $this->assertSame((int) $page->right_preset_id, (int) $copy->right_preset_id);
        $this->assertTrue($copy->left_enabled);
        $this->assertFalse($copy->right_enabled);

        // نسخهٔ آغازین از محتوای کپی‌شده اسنپ‌شات می‌شود (نسخهٔ ۱، تاریخچهٔ تازه).
        $revision = $copy->revisions()->firstOrFail();
        $this->assertSame(1, $revision->version);
        $this->assertSame($page->blocks, $revision->blocks);
        $this->assertSame('تکثیر صفحه', $revision->note);
        $this->assertSame(1, $copy->revisions()->count());
    }

    /** `-copy`، `-copy-2`، `-copy-3` … تا وقتی اسلاگِ آزاد پیدا شود. */
    public function test_duplicate_slug_gets_numeric_suffix_when_copy_exists(): void
    {
        $user = $this->user();
        $page = $this->page($user);
        $auth = $this->actingAs($user, 'sanctum');

        $auth->postJson("/api/v1/admin/pages/{$page->id}/duplicate")->assertCreated();
        $auth->postJson("/api/v1/admin/pages/{$page->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.slug', 'about-copy-2')
            ->assertJsonPath('data.title', 'درباره ما (کپی 2)');
        $auth->postJson("/api/v1/admin/pages/{$page->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.slug', 'about-copy-3');

        $slugs = Page::query()->orderBy('id')->pluck('slug')->all();
        $this->assertSame(['about', 'about-copy', 'about-copy-2', 'about-copy-3'], $slugs);
    }

    /**
     * یکتایی per-locale است (F4.5): صفحهٔ `en` با همان `-copy` مزاحمِ کپیِ
     * `fa` نیست، چون یکتاییِ دیتابیس روی `slug+locale` است.
     */
    public function test_duplicate_slug_uniqueness_is_scoped_to_same_locale(): void
    {
        $user = $this->user();
        $fa = $this->page($user, 'about', Page::LOCALE_DEFAULT);
        $this->page($user, 'about-copy', 'en');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$fa->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.slug', 'about-copy')
            ->assertJsonPath('data.locale', Page::LOCALE_DEFAULT);

        $this->assertSame(2, Page::query()->where('slug', 'about-copy')->count());
    }

    /** صفحهٔ سطل‌زباله هنوز اسلاگ را نگه می‌دارد ⇒ کپی نباید همان اسلاگ را بگیرد. */
    public function test_duplicate_avoids_slug_held_by_trashed_page(): void
    {
        $user = $this->user();
        $page = $this->page($user);
        $this->page($user, 'about-copy')->delete();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.slug', 'about-copy-2');
    }

    /** کپی هیچ‌چیزِ صفحهٔ مبدأ را تغییر نمی‌دهد و خودش منتشر نمی‌شود. */
    public function test_duplicate_of_published_page_leaves_source_untouched(): void
    {
        $user = $this->user();
        $page = $this->page($user);
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/admin/pages/{$page->id}/publish")->assertOk();
        $sourceRevisions = $page->fresh()->revisions()->count();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/duplicate")
            ->assertCreated();

        $source = $page->fresh();
        $this->assertSame(Page::STATUS_PUBLISHED, $source->status);
        $this->assertNotNull($source->published_revision_id);
        $this->assertSame($sourceRevisions, $source->revisions()->count());

        $copy = Page::query()->where('slug', 'about-copy')->firstOrFail();
        $this->assertSame(Page::STATUS_DRAFT, $copy->status);
        $this->assertNull($copy->published_revision_id);
        $this->assertNull($copy->published_at);
        $this->assertFalse($copy->isPublished());
    }

    public function test_duplicate_requires_pages_edit_permission(): void
    {
        $owner = $this->user();
        $page = $this->page($owner);
        $viewer = $this->user(canEdit: false);

        $this->actingAs($viewer, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/duplicate")
            ->assertStatus(403)
            ->assertJsonPath('message', 'دسترسی مجاز نیست.');

        $this->assertNull(Page::query()->where('slug', 'about-copy')->first());
    }

    /** تکثیر از هر مدیری مجاز (مشترکِ نصب) کار می‌کند، نه فقط صاحبِ صفحه. */
    public function test_duplicate_is_shared_across_managers(): void
    {
        $owner = $this->user();
        $page = $this->page($owner);
        $other = $this->user();

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.slug', 'about-copy');

        $this->assertSame($other->id, (int) Page::query()->where('slug', 'about-copy')->firstOrFail()->user_id);
    }
}