<?php

namespace Tests\Feature;

use App\Models\Central\Client;
use App\Models\Central\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** تسک ۴.۳ — DoD: وضعیت عمومی سبک (active|suspended + پیام برند) از تک‌منبع اشتراک مرحله ۱. */
class SiteStatusTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مشتری تست',
            'email' => 'u'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
    }

    private function subscription(string $status = Subscription::ACTIVE): Subscription
    {
        $client = Client::query()->create([
            'name' => 'سایت تست', 'tier' => 'budget', 'status' => $status === Subscription::SUSPENDED ? 'suspended' : 'active',
            'site_id' => 'site-'.uniqid(),
        ]);

        return Subscription::query()->create([
            'client_id' => $client->id, 'site_id' => $client->site_id,
            'status' => $status, 'due_at' => now()->addDays(30),
            'suspended_at' => $status === Subscription::SUSPENDED ? now() : null,
        ]);
    }

    public function test_public_status_is_active_without_auth(): void
    {
        $sub = $this->subscription();

        $res = $this->getJson("/api/v1/site/status?site_id={$sub->site_id}");

        $res->assertOk()
            ->assertJsonPath('data.status', 'active');
        $this->assertNotEmpty($res->json('data.message'));
        $this->assertStringContainsString('max-age=60', $res->headers->get('Cache-Control'));
    }

    public function test_suspended_subscription_reports_suspended(): void
    {
        $sub = $this->subscription(Subscription::SUSPENDED);

        $this->getJson("/api/v1/site/status?site_id={$sub->site_id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');
    }

    public function test_default_resolves_single_install_without_site_id(): void
    {
        $this->subscription();

        $this->getJson('/api/v1/site/status')
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_unknown_site_is_404(): void
    {
        $this->getJson('/api/v1/site/status?site_id=nope')->assertNotFound();
    }

    public function test_brand_message_uses_site_title(): void
    {
        $user = $this->user();
        $sub = Subscription::query()->create([
            'client_id' => Client::query()->create([
                'name' => 'من', 'tier' => 'budget', 'status' => 'suspended',
                'owner_user_id' => $user->id, 'site_id' => 'site-'.$user->id,
            ])->id,
            'site_id' => 'site-'.$user->id,
            'status' => Subscription::SUSPENDED,
            'suspended_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/admin/settings/site', [
            'title' => 'فروشگاه آرمان',
        ])->assertOk();

        $res = $this->getJson("/api/v1/site/status?site_id={$sub->site_id}")->assertOk();
        $this->assertStringContainsString('فروشگاه آرمان', $res->json('data.message'));
    }
}
