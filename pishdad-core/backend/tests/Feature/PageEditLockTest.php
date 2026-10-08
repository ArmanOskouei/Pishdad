<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageEditLock;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * WF-H3 — قفلِ نرمِ ویرایشِ هم‌زمان + کنترل همروندیِ خوش‌بینانه.
 *
 * پوشش: گرفتن/heartbeat، تعارض با دارنده، آزادسازی (فقط دارنده)، تصاحبِ قفلِ
 * کهنه پس از TTL، تصاحبِ عمدی با force، و مسیرِ 409 وقتی صفحه از `updated_at`
 * اعلامیِ کلاینت جلوتر رفته باشد.
 */
class PageEditLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    private function user(array $overrides = []): User
    {
        $user = User::query()->create(array_merge([
            'name' => 'مدیر تست',
            'email' => 'u'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ], $overrides));

        $user->givePermissionTo('pages.view', 'pages.edit');

        return $user->fresh();
    }

    private function page(User $user, ?string $slug = null): Page
    {
        $page = Page::query()->create([
            'user_id' => $user->id,
            'title' => 'صفحه تست',
            'slug' => $slug ?? 'p'.uniqid(),
            'status' => Page::STATUS_DRAFT,
            'blocks' => [],
        ]);
        $page->snapshot([], null, $user->id, 'ایجاد');

        return $page->fresh();
    }

    public function test_manager_acquires_lock_and_refreshes_heartbeat(): void
    {
        $user = $this->user(['name' => 'سارا']);
        $page = $this->page($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/lock")
            ->assertOk()
            ->assertJsonPath('data.owned', true)
            ->assertJsonPath('data.lock.user_id', $user->id)
            ->assertJsonPath('data.lock.user_name', 'سارا');

        $lock = PageEditLock::query()->where('page_id', $page->id)->firstOrFail();
        $this->assertSame($user->id, (int) $lock->user_id);
        $firstHeartbeat = $lock->heartbeat_at->getTimestamp();

        $this->travel(10)->seconds();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/lock")
            ->assertOk()
            ->assertJsonPath('data.owned', true);

        $this->assertGreaterThan(
            $firstHeartbeat,
            PageEditLock::query()->where('page_id', $page->id)->firstOrFail()->heartbeat_at->getTimestamp()
        );
    }

    public function test_second_manager_gets_conflict_with_holder_details(): void
    {
        $owner = $this->user(['name' => 'مالک قفل']);
        $other = $this->user(['name' => 'مدیر دیگر']);
        $page = $this->page($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/lock")->assertOk();

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/lock")
            ->assertStatus(409)
            ->assertJsonPath('data.owned', false)
            ->assertJsonPath('data.lock.user_id', $owner->id)
            ->assertJsonPath('data.lock.user_name', 'مالک قفل');

        // قفل جابه‌جا نشده است.
        $this->assertSame($owner->id, (int) PageEditLock::query()->where('page_id', $page->id)->firstOrFail()->user_id);
    }

    public function test_other_manager_cannot_release_lock_but_holder_can(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $page = $this->page($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/lock")->assertOk();

        $this->actingAs($other, 'sanctum')
            ->deleteJson("/api/v1/admin/pages/{$page->id}/lock")
            ->assertStatus(403);
        $this->assertDatabaseHas('page_edit_locks', ['page_id' => $page->id]);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/admin/pages/{$page->id}/lock")
            ->assertOk();
        $this->assertDatabaseMissing('page_edit_locks', ['page_id' => $page->id]);
    }

    public function test_release_without_lock_is_ok(): void
    {
        $user = $this->user();
        $page = $this->page($user);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/admin/pages/{$page->id}/lock")
            ->assertOk();
    }

    public function test_stale_lock_is_taken_over_without_force(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $page = $this->page($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/lock")->assertOk();

        $this->travel(PageEditLock::TTL_SECONDS + 10)->seconds();

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/lock")
            ->assertOk()
            ->assertJsonPath('data.owned', true)
            ->assertJsonPath('data.lock.user_id', $other->id);

        $this->assertSame($other->id, (int) PageEditLock::query()->where('page_id', $page->id)->firstOrFail()->user_id);
    }

    public function test_force_takes_over_an_active_lock(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $page = $this->page($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/lock")->assertOk();

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/lock", ['force' => true])
            ->assertOk()
            ->assertJsonPath('data.owned', true)
            ->assertJsonPath('data.lock.user_id', $other->id);

        $this->assertSame($other->id, (int) PageEditLock::query()->where('page_id', $page->id)->firstOrFail()->user_id);
    }

    public function test_update_rejects_stale_updated_at_with_409(): void
    {
        $user = $this->user();
        $page = $this->page($user);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/admin/pages/{$page->id}", [
                'title' => 'عنوان تازه',
                'updated_at' => now()->subDay()->toIso8601String(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('conflict', true);

        $this->assertNotSame('عنوان تازه', $page->fresh()->title);
    }

    public function test_update_accepts_matching_updated_at(): void
    {
        $user = $this->user();
        $page = $this->page($user);
        $known = $page->updated_at->toIso8601String();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/admin/pages/{$page->id}", [
                'title' => 'عنوان هم‌زمان',
                'updated_at' => $known,
            ])
            ->assertOk();

        $this->assertSame('عنوان هم‌زمان', $page->fresh()->title);
    }

    public function test_update_without_updated_at_stays_backward_compatible(): void
    {
        $user = $this->user();
        $page = $this->page($user);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/admin/pages/{$page->id}", ['title' => 'بدون کنترل نسخه'])
            ->assertOk();

        $this->assertSame('بدون کنترل نسخه', $page->fresh()->title);
    }
}
