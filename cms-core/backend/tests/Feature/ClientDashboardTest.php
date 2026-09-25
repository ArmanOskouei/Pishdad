<?php

namespace Tests\Feature;

use App\Models\Central\Client;
use App\Models\Central\Invoice;
use App\Models\Central\Subscription;
use App\Models\Media;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** تسک ۲.۲ — DoD: آمار داشبورد + هشدارها + رجیستری خالی ویجت‌ها (تیکت = ۰ تا مرحله ۵). */
class ClientDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'مشتری تست',
            'email' => 'u'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ], $overrides));
    }

    /** مشترک نصب: آمار همه ردیف‌های نصب را می‌شمارد (نه فقط ردیف خود کاربر). */
    public function test_stats_counts_shared_rows(): void
    {
        $mine = $this->user();
        $theirs = $this->user();

        Page::query()->create(['user_id' => $mine->id, 'title' => 'الف', 'slug' => 'a', 'status' => 'draft', 'blocks' => []]);
        Page::query()->create(['user_id' => $mine->id, 'title' => 'ب', 'slug' => 'b', 'status' => 'draft', 'blocks' => []]);
        Page::query()->create(['user_id' => $theirs->id, 'title' => 'ج', 'slug' => 'c', 'status' => 'draft', 'blocks' => []]);
        Media::query()->create(['user_id' => $mine->id, 'disk' => 'local', 'path' => 'x', 'original_name' => 'x.jpg', 'mime' => 'image/jpeg', 'size' => 10]);

        $res = $this->actingAs($mine, 'sanctum')->getJson('/api/v1/admin/dashboard/stats');

        $res->assertOk()
            ->assertJsonPath('data.pages_count', 3)
            ->assertJsonPath('data.media_count', 1)
            ->assertJsonPath('data.open_tickets', 0)
            ->assertJsonPath('data.subscription.status', 'active');
    }

    public function test_stats_requires_auth(): void
    {
        $this->getJson('/api/v1/admin/dashboard/stats')->assertUnauthorized();
    }

    public function test_alerts_reports_overdue_invoice(): void
    {
        $user = $this->user();
        $client = Client::query()->create([
            'name' => 'من', 'tier' => 'budget', 'status' => 'active',
            'owner_user_id' => $user->id, 'site_id' => 'site-'.$user->id,
        ]);
        $sub = Subscription::query()->create([
            'client_id' => $client->id, 'site_id' => $client->site_id,
            'status' => Subscription::ACTIVE, 'due_at' => now()->addDays(30),
        ]);
        Invoice::query()->create([
            'client_id' => $client->id, 'subscription_id' => $sub->id,
            'amount' => 100000, 'status' => Invoice::PENDING, 'due_at' => now()->subDay(),
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/dashboard/alerts');

        $res->assertOk();
        $types = collect($res->json('data'))->pluck('type')->all();
        $this->assertContains('overdue_invoice', $types);
    }

    public function test_alerts_reports_suspension(): void
    {
        $user = $this->user();
        $client = Client::query()->create([
            'name' => 'من', 'tier' => 'budget', 'status' => 'suspended',
            'owner_user_id' => $user->id, 'site_id' => 'site-'.$user->id,
        ]);
        Subscription::query()->create([
            'client_id' => $client->id, 'site_id' => $client->site_id,
            'status' => Subscription::SUSPENDED, 'due_at' => now()->subDays(2),
            'suspended_at' => now()->subDays(2),
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/dashboard/alerts');

        $res->assertOk();
        $types = collect($res->json('data'))->pluck('type')->all();
        $this->assertContains('suspended', $types);
    }

    public function test_widgets_returns_empty_registry_with_ready_structure(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/dashboard/widgets');

        $res->assertOk()
            ->assertJsonPath('data.registry_version', '1.0')
            ->assertJsonPath('data.widgets', []);
        $this->assertNotEmpty($res->json('data.slots'));
    }
}
