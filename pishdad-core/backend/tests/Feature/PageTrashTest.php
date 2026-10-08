<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** WF-H4 — سطل زبالهٔ صفحات: سافت‌دیلیت، لیست، بازیابی، حذف دائمی و مجوزها. */
class PageTrashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['revalidate.url' => '']);
    }

    private function user(string $role): User
    {
        $this->seed(RolesPermissionsSeeder::class);
        $user = User::query()->create([
            'name' => 'کاربر تست',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
        $user->assignRole($role);

        return $user->fresh();
    }

    private function page(User $user, string $slug, string $status = Page::STATUS_DRAFT): Page
    {
        $page = Page::query()->create([
            'user_id' => $user->id,
            'title' => 'صفحه سطل زباله',
            'slug' => $slug,
            'status' => $status,
            'blocks' => [['type' => 'text', 'data' => ['body' => 'متن']]],
        ]);
        $page->snapshot($page->blocks, null, $user->id, 'ایجاد');

        return $page;
    }

    public function test_soft_delete_hides_page_from_admin_list_and_public_site(): void
    {
        $owner = $this->user('owner');
        $page = $this->page($owner, 'trash-hide');

        $auth = $this->actingAs($owner, 'sanctum');
        $auth->postJson("/api/v1/admin/pages/{$page->id}/publish")->assertOk();
        $this->getJson('/api/v1/site/pages/trash-hide')->assertOk();
        $auth->getJson('/api/v1/admin/pages')->assertJsonPath('total', 1);

        $auth->deleteJson("/api/v1/admin/pages/{$page->id}")
            ->assertOk()
            ->assertJsonPath('message', 'صفحه به سطل زباله منتقل شد.');

        $this->assertSoftDeleted('pages', ['id' => $page->id]);
        $auth->getJson('/api/v1/admin/pages')->assertJsonPath('total', 0);
        $auth->getJson("/api/v1/admin/pages/{$page->id}")->assertNotFound();
        $this->getJson('/api/v1/site/pages/trash-hide')->assertNotFound();
    }

    public function test_trash_endpoint_lists_only_trashed_pages(): void
    {
        $owner = $this->user('owner');
        $kept = $this->page($owner, 'kept-page');
        $trashed = $this->page($owner, 'trashed-page');

        $auth = $this->actingAs($owner, 'sanctum');
        $auth->deleteJson("/api/v1/admin/pages/{$trashed->id}")->assertOk();

        $res = $auth->getJson('/api/v1/admin/pages/trash');
        $res->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $trashed->id)
            ->assertJsonPath('data.0.slug', 'trashed-page');

        $auth->getJson('/api/v1/admin/pages')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $kept->id);
    }

    public function test_restore_brings_page_back_from_trash(): void
    {
        $owner = $this->user('owner');
        $page = $this->page($owner, 'restore-page');

        $auth = $this->actingAs($owner, 'sanctum');
        $auth->deleteJson("/api/v1/admin/pages/{$page->id}")->assertOk();
        $auth->getJson('/api/v1/admin/pages/trash')->assertJsonPath('total', 1);

        $auth->postJson("/api/v1/admin/pages/{$page->id}/restore")
            ->assertOk()
            ->assertJsonPath('message', 'صفحه بازگردانده شد.');

        $this->assertNotSoftDeleted('pages', ['id' => $page->id]);
        $auth->getJson('/api/v1/admin/pages')->assertJsonPath('total', 1);
        $auth->getJson('/api/v1/admin/pages/trash')->assertJsonPath('total', 0);
        $auth->getJson("/api/v1/admin/pages/{$page->id}")->assertOk();
    }

    public function test_force_delete_removes_page_and_revisions_permanently(): void
    {
        $owner = $this->user('owner');
        $page = $this->page($owner, 'force-page');
        $this->assertDatabaseHas('page_revisions', ['page_id' => $page->id]);

        $auth = $this->actingAs($owner, 'sanctum');
        $auth->deleteJson("/api/v1/admin/pages/{$page->id}")->assertOk();
        $this->assertDatabaseHas('pages', ['id' => $page->id]);

        $auth->deleteJson("/api/v1/admin/pages/{$page->id}/force")
            ->assertOk()
            ->assertJsonPath('message', 'صفحه برای همیشه حذف شد.');

        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
        $this->assertDatabaseMissing('page_revisions', ['page_id' => $page->id]);
    }

    public function test_editor_can_restore_but_cannot_force_delete(): void
    {
        $owner = $this->user('owner');
        $editor = $this->user('editor');

        $restorable = $this->page($owner, 'editor-restore');
        $permanent = $this->page($owner, 'editor-force');

        $ownerAuth = $this->actingAs($owner, 'sanctum');
        $ownerAuth->deleteJson("/api/v1/admin/pages/{$restorable->id}")->assertOk();
        $ownerAuth->deleteJson("/api/v1/admin/pages/{$permanent->id}")->assertOk();

        $editorAuth = $this->actingAs($editor, 'sanctum');
        $editorAuth->postJson("/api/v1/admin/pages/{$restorable->id}/restore")
            ->assertOk()
            ->assertJsonPath('message', 'صفحه بازگردانده شد.');

        $this->assertNotSoftDeleted('pages', ['id' => $restorable->id]);

        $editorAuth->deleteJson("/api/v1/admin/pages/{$permanent->id}/force")->assertForbidden();
        $this->assertSoftDeleted('pages', ['id' => $permanent->id]);
    }

    public function test_viewer_cannot_restore_or_force_delete(): void
    {
        $owner = $this->user('owner');
        $viewer = $this->user('viewer');
        $page = $this->page($owner, 'viewer-page');

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/admin/pages/{$page->id}")
            ->assertOk();

        $viewerAuth = $this->actingAs($viewer, 'sanctum');
        $viewerAuth->postJson("/api/v1/admin/pages/{$page->id}/restore")->assertForbidden();
        $viewerAuth->deleteJson("/api/v1/admin/pages/{$page->id}/force")->assertForbidden();

        $this->assertSoftDeleted('pages', ['id' => $page->id]);
    }
}
