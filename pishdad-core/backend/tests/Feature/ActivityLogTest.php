<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Page;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** WF-H9 — DoD: ثبت رویداد ساخت/ویرایش/انتشار/حذف + فیلتر + بازگردانی نسخهٔ قبلی. */
class ActivityLogTest extends TestCase
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
            'name' => 'کاربر تست',
            'email' => 'u'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ], $overrides));

        $user->givePermissionTo('pages.view', 'pages.edit', 'pages.delete', 'users.view');

        return $user->fresh();
    }

    private function blocks(string $body = 'متن نمونه'): array
    {
        return [
            ['type' => 'hero', 'data' => ['title' => 'سلام']],
            ['type' => 'text', 'data' => ['body' => $body]],
        ];
    }

    private function createPage(User $user, string $slug): Page
    {
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/pages', [
            'title' => 'درباره ما',
            'slug' => $slug,
            'blocks' => $this->blocks(),
        ])->assertCreated()->json('data.id');

        return Page::query()->findOrFail($id);
    }

    public function test_create_page_records_activity(): void
    {
        $user = $this->user();
        $page = $this->createPage($user, 'act-create');

        $log = ActivityLog::query()->where('action', ActivityLog::ACTION_PAGE_CREATE)->firstOrFail();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(ActivityLog::SUBJECT_PAGE, $log->subject_type);
        $this->assertSame($page->id, $log->subject_id);
        $this->assertSame(1, $log->diff['version']);
        $this->assertSame(2, $log->diff['blocks_count']);
        $this->assertStringContainsString('درباره ما', $log->summary);
    }

    public function test_update_page_records_changed_fields_and_previous_revision(): void
    {
        $user = $this->user();
        $page = $this->createPage($user, 'act-update');
        $v1 = $page->revisions()->firstOrFail();

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/admin/pages/{$page->id}", [
            'title' => 'عنوان تازه',
            'blocks' => $this->blocks('متن تازه'),
        ])->assertOk();

        $log = ActivityLog::query()->where('action', ActivityLog::ACTION_PAGE_UPDATE)->firstOrFail();
        $this->assertContains('title', $log->diff['changed']);
        $this->assertContains('blocks', $log->diff['changed']);
        $this->assertSame($v1->id, $log->diff['previous_revision_id']);
        $this->assertSame(2, $log->diff['version']);
        // هرگز HTML/محتوای بلوک‌ها در diff ذخیره نمی‌شود.
        $this->assertArrayNotHasKey('blocks', $log->diff);
    }

    public function test_publish_page_records_activity(): void
    {
        $user = $this->user();
        $page = $this->createPage($user, 'act-publish');

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/admin/pages/{$page->id}/publish")->assertOk();

        $log = ActivityLog::query()->where('action', ActivityLog::ACTION_PAGE_PUBLISH)->firstOrFail();
        $this->assertSame($page->id, $log->subject_id);
        $this->assertSame('published', $log->diff['status']);
        $this->assertNotNull($log->diff['previous_revision_id']);
    }

    public function test_delete_page_records_activity(): void
    {
        $user = $this->user();
        $page = $this->createPage($user, 'act-delete');

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/admin/pages/{$page->id}")->assertOk();

        $log = ActivityLog::query()->where('action', ActivityLog::ACTION_PAGE_DELETE)->firstOrFail();
        $this->assertSame($page->id, $log->subject_id);
        $this->assertSame('act-delete', $log->diff['slug']);
        // رویداد حذف نسخهٔ قابل بازگردانی ندارد (بازگردانی از سطل زباله مسیر جداست).
        $this->assertNull($log->restorableRevisionId());
    }

    public function test_activities_list_filters_by_user_action_and_range(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $this->createPage($owner, 'flt-owner');
        $this->createPage($other, 'flt-other');

        $auth = $this->actingAs($owner, 'sanctum');

        $all = $auth->getJson('/api/v1/admin/activities')->assertOk();
        $this->assertSame(2, $all->json('total'));
        $this->assertSame('کاربر تست', $all->json('data.0.user.name'));
        $this->assertNull($all->json('data.0.restorable_revision_id'));

        $byUser = $auth->getJson("/api/v1/admin/activities?user_id={$owner->id}")->assertOk();
        $this->assertSame(1, $byUser->json('total'));

        $byAction = $auth->getJson('/api/v1/admin/activities?action=page.create')->assertOk();
        $this->assertSame(2, $byAction->json('total'));

        $none = $auth->getJson('/api/v1/admin/activities?action=page.publish')->assertOk();
        $this->assertSame(0, $none->json('total'));

        $today = $auth->getJson('/api/v1/admin/activities?from='.now()->toDateString().'&to='.now()->toDateString())->assertOk();
        $this->assertSame(2, $today->json('total'));

        $past = $auth->getJson('/api/v1/admin/activities?to=2000-01-01')->assertOk();
        $this->assertSame(0, $past->json('total'));
    }

    public function test_restore_previous_version_from_activity(): void
    {
        $user = $this->user();
        $page = $this->createPage($user, 'act-restore');
        $v1 = $page->revisions()->firstOrFail();

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/admin/pages/{$page->id}", [
            'blocks' => $this->blocks('نسخه دو'),
        ])->assertOk();

        $updateLog = ActivityLog::query()->where('action', ActivityLog::ACTION_PAGE_UPDATE)->firstOrFail();
        $this->assertSame($v1->id, $updateLog->restorableRevisionId());

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/activities/{$updateLog->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.blocks.1.data.body', 'متن نمونه');

        $fresh = $page->fresh();
        $this->assertSame('متن نمونه', $fresh->blocks[1]['data']['body']);
        // تاریخچه حفظ شده (append-only): نسخهٔ سوم ساخته شد.
        $this->assertSame(3, $fresh->revisions()->max('version'));
        $this->assertSame(1, ActivityLog::query()->where('action', ActivityLog::ACTION_PAGE_RESTORE)->count());
    }

    public function test_restore_rejects_activity_without_previous_revision(): void
    {
        $user = $this->user();
        $this->createPage($user, 'act-norestore');

        $createLog = ActivityLog::query()->where('action', ActivityLog::ACTION_PAGE_CREATE)->firstOrFail();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/activities/{$createLog->id}/restore")
            ->assertStatus(422);
    }

    public function test_media_delete_records_activity(): void
    {
        $user = $this->user();
        $media = Media::query()->create([
            'user_id' => $user->id, 'disk' => 's3', 'path' => 'media/shared/x.png',
            'original_name' => 'x.png', 'mime' => 'image/png', 'size' => 10,
        ]);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/admin/media/{$media->id}")->assertOk();

        $log = ActivityLog::query()->where('action', ActivityLog::ACTION_MEDIA_DELETE)->firstOrFail();
        $this->assertSame(ActivityLog::SUBJECT_MEDIA, $log->subject_type);
        $this->assertSame($media->id, $log->subject_id);
    }

    public function test_viewer_cannot_restore_activities_and_stranger_cannot_read(): void
    {
        $owner = $this->user();
        $page = $this->createPage($owner, 'act-viewer');
        $this->actingAs($owner, 'sanctum')->putJson("/api/v1/admin/pages/{$page->id}", [
            'blocks' => $this->blocks('دیگر'),
        ])->assertOk();
        $updateLog = ActivityLog::query()->where('action', ActivityLog::ACTION_PAGE_UPDATE)->firstOrFail();

        // بیننده `users.view` دارد (همهٔ *.view) پس فهرست را می‌بیند...
        $viewer = User::query()->create([
            'name' => 'بیننده', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Viewer!1234'), 'role' => 'admin',
        ]);
        $viewer->assignRole('viewer');
        $this->actingAs($viewer->fresh(), 'sanctum')
            ->getJson('/api/v1/admin/activities')
            ->assertOk();

        // ...ولی `pages.edit` ندارد پس بازگردانی ممنوع است.
        $this->actingAs($viewer->fresh(), 'sanctum')
            ->postJson("/api/v1/admin/activities/{$updateLog->id}/restore")
            ->assertForbidden();

        // کاربر بدون هیچ پرمیشنی حتی فهرست را نمی‌بیند.
        $stranger = User::query()->create([
            'name' => 'غریبه', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Stranger!1234'), 'role' => 'admin',
        ]);
        $this->actingAs($stranger, 'sanctum')
            ->getJson('/api/v1/admin/activities')
            ->assertForbidden();
    }
}
