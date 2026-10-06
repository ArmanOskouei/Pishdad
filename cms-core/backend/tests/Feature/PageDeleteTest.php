<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PageDeleteTest extends TestCase
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

    private function page(User $user, string $slug): Page
    {
        $page = Page::query()->create([
            'user_id' => $user->id,
            'title' => 'صفحه قابل حذف',
            'slug' => $slug,
            'status' => Page::STATUS_DRAFT,
            'blocks' => [['type' => 'text', 'data' => ['body' => 'متن']]],
        ]);
        $page->snapshot($page->blocks, null, $user->id, 'ایجاد');

        return $page;
    }

    public function test_user_with_pages_delete_permission_can_delete_page(): void
    {
        $user = $this->user('owner');
        $page = $this->page($user, 'delete-page');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/admin/pages/{$page->id}")
            ->assertOk()
            ->assertJsonPath('message', 'صفحه به سطل زباله منتقل شد.');

        $this->assertSoftDeleted('pages', ['id' => $page->id]);
        // سافت‌دیلیت نسخه‌ها را پاک نمی‌کند؛ فقط حذف دائمی آن‌ها را cascade می‌کند.
        $this->assertDatabaseHas('page_revisions', ['page_id' => $page->id]);
    }

    public function test_user_without_pages_delete_permission_cannot_delete_page(): void
    {
        $user = $this->user('editor');
        $page = $this->page($user, 'protected-page');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/admin/pages/{$page->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('pages', ['id' => $page->id]);
    }
}
