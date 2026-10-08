<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * WF-H14 — همزادِ ترجمهٔ محتوای چندزبانه + نشان وضعیت
 * «ترجمه‌شده / نیازمند به‌روزرسانی / بدون ترجمه» بر پایهٔ مقایسهٔ revision.
 *
 * قرارداد: هر زبان ردیفِ صفحهٔ خودش است (F4.5)؛ همزاد با همان `slug` در
 * localeِ مقابل پیدا می‌شود و پیوندِ «مبدأ → نسخه» در `meta` همزاد می‌نشیند.
 */
class PageTranslationTest extends TestCase
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
            'name' => 'کاربر ترجمه',
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

    private function page(User $owner, string $slug, string $locale): Page
    {
        $blocks = [['type' => 'text', 'data' => ['body' => 'محتوای '.$locale]]];
        $page = Page::query()->create([
            'user_id' => $owner->id,
            'title' => 'صفحه '.$slug.' '.$locale,
            'slug' => $slug,
            'locale' => $locale,
            'status' => Page::STATUS_DRAFT,
            'blocks' => $blocks,
        ]);
        $page->snapshot($blocks, null, $owner->id, 'ایجاد');

        return $page->fresh();
    }

    private function sibling(string $slug, string $locale): ?Page
    {
        return Page::query()->where('slug', $slug)->where('locale', $locale)->first();
    }

    public function test_get_reports_none_when_no_sibling_exists(): void
    {
        $user = $this->user();
        $fa = $this->page($user, 'about', 'fa');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/admin/pages/{$fa->id}/translation")
            ->assertOk()
            ->assertJsonPath('data.status', 'none')
            ->assertJsonPath('data.target_locale', 'en')
            ->assertJsonPath('data.sibling', null);
    }

    public function test_post_creates_draft_sibling_with_same_slug_and_source_link(): void
    {
        $user = $this->user();
        $fa = $this->page($user, 'about', 'fa');
        $latest = $fa->revisions()->reorder('version', 'desc')->first();

        $res = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$fa->id}/translation")
            ->assertCreated();

        $res->assertJsonPath('data.status', 'translated')
            ->assertJsonPath('data.sibling.slug', 'about')
            ->assertJsonPath('data.sibling.locale', 'en')
            ->assertJsonPath('data.sibling.status', 'draft')
            ->assertJsonPath('data.sibling.source_revision_id', $latest->id);

        $en = $this->sibling('about', 'en');
        $this->assertNotNull($en);
        $this->assertSame(Page::STATUS_DRAFT, $en->status);
        $this->assertSame([], $en->blocks ?? []);
        $this->assertSame($fa->id, $en->meta['translation_of']);
        $this->assertSame($latest->id, $en->meta['source_revision_id']);
        $this->assertSame(1, $en->revisions()->count());
    }

    public function test_status_transitions_translated_then_needs_update_then_translated(): void
    {
        $user = $this->user();
        $fa = $this->page($user, 'contact', 'fa');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$fa->id}/translation")
            ->assertCreated()
            ->assertJsonPath('data.status', 'translated');

        $auth = $this->actingAs($user, 'sanctum');
        $auth->getJson("/api/v1/admin/pages/{$fa->id}/translation")
            ->assertOk()
            ->assertJsonPath('data.status', 'translated');

        // ویرایش مبدأ ⇒ نسخهٔ تازه‌تر از آخرین همگام‌سازی ترجمه.
        $auth->putJson("/api/v1/admin/pages/{$fa->id}", [
            'blocks' => [['type' => 'text', 'data' => ['body' => 'نسخهٔ دوم']]],
        ])->assertOk();

        $auth->getJson("/api/v1/admin/pages/{$fa->id}/translation")
            ->assertOk()
            ->assertJsonPath('data.status', 'needs_update');

        // همگام‌سازی دوباره ⇒ ترجمه‌شده.
        $newLatest = $fa->fresh()->revisions()->reorder('version', 'desc')->first();
        $auth->postJson("/api/v1/admin/pages/{$fa->id}/translation")
            ->assertOk()
            ->assertJsonPath('data.status', 'translated')
            ->assertJsonPath('data.sibling.source_revision_id', $newLatest->id);

        $this->assertSame($newLatest->id, $this->sibling('contact', 'en')->meta['source_revision_id']);
    }

    public function test_sync_keeps_existing_translation_blocks_and_bumps_revision(): void
    {
        $user = $this->user();
        $fa = $this->page($user, 'terms', 'fa');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$fa->id}/translation")
            ->assertCreated();

        $en = $this->sibling('terms', 'en');
        $en->snapshot([['type' => 'text', 'data' => ['body' => 'Terms body']]], $en->meta, $user->id, 'ترجمه');
        $before = $en->fresh()->revisions()->count();

        // مبدأ جلو می‌رود، سپس همگام‌سازی.
        $fa->snapshot([['type' => 'text', 'data' => ['body' => 'متن تازه']]], null, $user->id, 'ویرایش');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$fa->id}/translation")
            ->assertOk()
            ->assertJsonPath('data.status', 'translated');

        $fresh = $this->sibling('terms', 'en');
        $this->assertSame('Terms body', $fresh->blocks[0]['data']['body']);
        $this->assertSame($before + 1, $fresh->revisions()->count());
    }

    public function test_sync_from_translation_side_does_not_corrupt_source_metadata(): void
    {
        $user = $this->user();
        $fa = $this->page($user, 'privacy', 'fa');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$fa->id}/translation")
            ->assertCreated();

        $en = $this->sibling('privacy', 'en');

        // مبدأ جلو می‌رود ⇒ ترجمه نیازمند به‌روزرسانی.
        $fa->snapshot([['type' => 'text', 'data' => ['body' => 'نسخهٔ تازه']]], null, $user->id, 'ویرایش');

        $auth = $this->actingAs($user, 'sanctum');
        $auth->getJson("/api/v1/admin/pages/{$en->id}/translation")
            ->assertOk()
            ->assertJsonPath('data.status', 'needs_update');

        // همگام‌سازی از سمتِ خودِ ترجمه ⇒ اشاره‌گرش جلو می‌رود، نه فرادادهٔ مبدأ.
        $latestFa = $fa->fresh()->revisions()->reorder('version', 'desc')->first();
        $auth->postJson("/api/v1/admin/pages/{$en->id}/translation")
            ->assertOk()
            ->assertJsonPath('data.status', 'translated');

        $this->assertSame($latestFa->id, $en->fresh()->meta['source_revision_id']);
        $this->assertArrayNotHasKey('translation_of', $fa->fresh()->meta ?? []);
    }

    public function test_translation_endpoints_require_pages_edit_permission(): void
    {
        $owner = $this->user();
        $fa = $this->page($owner, 'about', 'fa');
        $viewer = $this->user(canEdit: false);

        $auth = $this->actingAs($viewer, 'sanctum');
        $auth->getJson("/api/v1/admin/pages/{$fa->id}/translation")
            ->assertStatus(403)
            ->assertJsonPath('message', 'دسترسی مجاز نیست.');
        $auth->postJson("/api/v1/admin/pages/{$fa->id}/translation")
            ->assertStatus(403)
            ->assertJsonPath('message', 'دسترسی مجاز نیست.');

        $this->assertNull($this->sibling('about', 'en'));
    }
}
