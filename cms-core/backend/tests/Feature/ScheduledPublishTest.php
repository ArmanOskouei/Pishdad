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

/** WF-C3 — DoD: زمان‌بندی انتشار + انتشار خودکارِ موعدرسیده + لغو. */
class ScheduledPublishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
        Http::fake(['*' => Http::response(['message' => 'کش باطل شد.'], 200)]);
        config(['revalidate.url' => 'http://front.local/api/revalidate']);
    }

    private function user(): User
    {
        $user = User::query()->create([
            'name' => 'کاربر تست',
            'email' => 'sched'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);

        $user->givePermissionTo('pages.view', 'pages.edit');

        return $user->fresh();
    }

    private function page(User $user, string $slug = 'sched-page'): Page
    {
        $page = Page::query()->create([
            'user_id' => $user->id,
            'title' => 'صفحه زمان‌بندی',
            'slug' => $slug,
            'status' => Page::STATUS_DRAFT,
            'blocks' => [['type' => 'text', 'data' => ['body' => 'سلام']]],
        ]);
        $page->snapshot($page->blocks, null, $user->id, 'ایجاد');

        return $page;
    }

    public function test_schedule_sets_scheduled_at(): void
    {
        $user = $this->user();
        $page = $this->page($user);
        $when = now()->addHour()->toIso8601String();

        $res = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/schedule", ['scheduled_at' => $when]);

        $res->assertOk()->assertJsonPath('message', 'انتشار زمان‌بندی شد.');
        $this->assertNotNull($page->fresh()->scheduled_at);
        $this->assertSame(Page::STATUS_DRAFT, $page->fresh()->status);
    }

    public function test_schedule_rejects_past_datetime(): void
    {
        $user = $this->user();
        $page = $this->page($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/pages/{$page->id}/schedule", [
                'scheduled_at' => now()->subHour()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scheduled_at');

        $this->assertNull($page->fresh()->scheduled_at);
    }

    public function test_command_publishes_due_page_and_clears_schedule(): void
    {
        $user = $this->user();
        $page = $this->page($user, 'due-page');
        $page->forceFill(['scheduled_at' => now()->subMinute()])->save();

        $this->artisan('pages:publish-scheduled')->assertExitCode(0);

        $fresh = $page->fresh();
        $this->assertSame(Page::STATUS_PUBLISHED, $fresh->status);
        $this->assertNotNull($fresh->published_revision_id);
        $this->assertNull($fresh->scheduled_at);

        $log = RevalidateLog::query()->where('page_id', $page->id)->firstOrFail();
        $this->assertContains('page:due-page', $log->tags);
        $this->assertNotEmpty($log->signature);
    }

    public function test_command_does_not_publish_future_page(): void
    {
        $user = $this->user();
        $page = $this->page($user, 'future-page');
        $page->forceFill(['scheduled_at' => now()->addHour()])->save();

        $this->artisan('pages:publish-scheduled')->assertExitCode(0);

        $fresh = $page->fresh();
        $this->assertSame(Page::STATUS_DRAFT, $fresh->status);
        $this->assertNull($fresh->published_revision_id);
        $this->assertNotNull($fresh->scheduled_at);
    }

    public function test_cancel_clears_scheduled_at(): void
    {
        $user = $this->user();
        $page = $this->page($user);
        $page->forceFill(['scheduled_at' => now()->addHour()])->save();

        $res = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/admin/pages/{$page->id}/schedule");

        $res->assertOk()->assertJsonPath('message', 'زمان‌بندی انتشار لغو شد.');
        $this->assertNull($page->fresh()->scheduled_at);
    }
}
