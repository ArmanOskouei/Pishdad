<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageShareLink;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** WF-H2 — لینک اشتراک پیش‌نویس: ساخت/انقضا/دست‌کاری/لغو + مجوز. */
class PageShareLinkTest extends TestCase
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
            'email' => 'share'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);

        $user->givePermissionTo($canEdit ? ['pages.view', 'pages.edit'] : ['pages.view']);

        return $user->fresh();
    }

    private function draftPage(User $user): array
    {
        $page = Page::query()->create([
            'user_id' => $user->id,
            'title' => 'پیش‌نویس اشتراکی',
            'slug' => 'share-draft-'.uniqid(),
            'status' => Page::STATUS_DRAFT,
            'blocks' => [],
        ]);
        $revision = $page->snapshot(
            [['type' => 'text', 'data' => ['body' => 'محتوای مخفی پیش‌نویس']]],
            ['robots' => ''],
            $user->id,
            'ایجاد',
        );

        return [$page, $revision];
    }

    public function test_create_share_link_returns_signed_url_and_stores_hash(): void
    {
        $user = $this->user();
        [$page, $revision] = $this->draftPage($user);

        $res = $this->actingAs($user, 'sanctum')->postJson("/api/v1/admin/pages/{$page->id}/share");

        $res->assertCreated()->assertJsonPath('message', 'لینک اشتراک پیش‌نویس ساخته شد.');
        $token = $res->json('data.token');
        $this->assertIsString($token);
        $this->assertStringContainsString('/preview/share/', (string) $res->json('data.url'));
        $this->assertNotEmpty($res->json('data.expires_at'));

        $link = PageShareLink::query()->where('page_id', $page->id)->firstOrFail();
        $this->assertSame($revision->id, $link->revision_id);
        $this->assertSame(hash('sha256', $token), $link->token_hash);
        $this->assertNotSame($token, $link->token_hash);
        $this->assertTrue($link->expires_at->isFuture());
    }

    public function test_valid_token_returns_the_revision_payload_with_noindex(): void
    {
        $user = $this->user();
        [$page] = $this->draftPage($user);
        $token = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/share")
            ->json('data.token');

        $res = $this->getJson('/api/v1/site/share/'.$token);

        $res->assertOk()
            ->assertJsonPath('data.title', 'پیش‌نویس اشتراکی')
            ->assertJsonPath('data.blocks.0.data.body', 'محتوای مخفی پیش‌نویس')
            ->assertJsonPath('data.meta.noindex', true);

        $this->assertSame('noindex, nofollow', $res->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    public function test_expired_token_is_rejected_with_410(): void
    {
        config(['revalidate.share_ttl' => -10]);

        $user = $this->user();
        [$page] = $this->draftPage($user);
        $token = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/share")
            ->json('data.token');

        $this->getJson('/api/v1/site/share/'.$token)->assertStatus(410);
    }

    public function test_tampered_token_is_rejected_with_403(): void
    {
        $user = $this->user();
        [$page] = $this->draftPage($user);
        $token = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/share")
            ->json('data.token');

        // تغییرِ آخرین نویسهٔ امضا ⇒ hash_equals می‌شکند.
        $tampered = substr($token, 0, -1).(str_ends_with($token, 'a') ? 'b' : 'a');
        $this->getJson('/api/v1/site/share/'.$tampered)->assertStatus(403);

        $this->getJson('/api/v1/site/share/not-a-real-token')->assertStatus(403);
    }

    public function test_revoke_invalidates_the_link(): void
    {
        $user = $this->user();
        [$page] = $this->draftPage($user);
        $auth = $this->actingAs($user, 'sanctum');
        $token = $auth->postJson("/api/v1/admin/pages/{$page->id}/share")->json('data.token');

        $this->getJson('/api/v1/site/share/'.$token)->assertOk();

        $auth->deleteJson("/api/v1/admin/pages/{$page->id}/share")
            ->assertOk()
            ->assertJsonPath('message', 'لینک اشتراک پیش‌نویس لغو شد.');

        $this->assertSame(0, PageShareLink::query()->where('page_id', $page->id)->count());
        $this->getJson('/api/v1/site/share/'.$token)->assertStatus(403);
    }

    public function test_creating_a_new_link_rotates_the_previous_one(): void
    {
        $user = $this->user();
        [$page] = $this->draftPage($user);
        $auth = $this->actingAs($user, 'sanctum');

        $first = $auth->postJson("/api/v1/admin/pages/{$page->id}/share")->json('data.token');
        $second = $auth->postJson("/api/v1/admin/pages/{$page->id}/share")->json('data.token');

        $this->assertNotSame($first, $second);
        $this->assertSame(1, PageShareLink::query()->where('page_id', $page->id)->count());
        $this->getJson('/api/v1/site/share/'.$first)->assertStatus(403);
        $this->getJson('/api/v1/site/share/'.$second)->assertOk();
    }

    public function test_create_and_revoke_require_pages_edit_permission(): void
    {
        $editor = $this->user();
        $viewer = $this->user(false);
        [$page] = $this->draftPage($editor);

        $viewerAuth = $this->actingAs($viewer, 'sanctum');
        $viewerAuth->postJson("/api/v1/admin/pages/{$page->id}/share")->assertForbidden();
        $viewerAuth->deleteJson("/api/v1/admin/pages/{$page->id}/share")->assertForbidden();
    }

    public function test_share_without_any_revision_returns_422(): void
    {
        $user = $this->user();
        $page = Page::query()->create([
            'user_id' => $user->id,
            'title' => 'بدون نسخه',
            'slug' => 'no-revision-'.uniqid(),
            'status' => Page::STATUS_DRAFT,
            'blocks' => [],
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/share")
            ->assertStatus(422);
    }
}
