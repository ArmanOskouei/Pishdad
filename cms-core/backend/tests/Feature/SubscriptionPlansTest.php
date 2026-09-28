<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Pishdad\Plugins\Central\Models\Client;
use Pishdad\Plugins\Central\Models\Invoice;
use Pishdad\Plugins\Central\Models\Plan;
use Tests\TestCase;

/** دسته ۱ (برابری UI/UX) — مقایسه پلن‌ها + فاکتور مابه‌التفاوت ارتقا. */
class SubscriptionPlansTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مشتری تست',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
    }

    private function plan(string $slug, string $tier, int $monthly, bool $active = true): Plan
    {
        return Plan::query()->create([
            'name' => $slug, 'slug' => $slug, 'tier' => $tier,
            'price_monthly' => $monthly, 'price_yearly' => $monthly * 10,
            'currency' => 'IRT', 'features' => ['الف', 'ب'], 'active' => $active,
        ]);
    }

    public function test_plans_lists_only_active(): void
    {
        $user = $this->user();
        $this->plan('budget', 'budget', 100000);
        $this->plan('standard', 'standard', 300000);
        $this->plan('legacy', 'budget', 50000, false);

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/subscription/plans');

        $res->assertOk();
        $this->assertSame(2, count($res->json('data')));
        $this->assertSame('budget', $res->json('data.0.slug'));
        $this->assertSame('standard', $res->json('data.1.slug'));
    }

    public function test_change_plan_creates_diff_invoice(): void
    {
        $user = $this->user();
        $budget = $this->plan('budget', 'budget', 100000);
        $standard = $this->plan('standard', 'standard', 300000);

        $client = Client::query()->create([
            'name' => 'من', 'tier' => 'budget', 'status' => 'active',
            'owner_user_id' => $user->id, 'site_id' => 'site-'.$user->id,
        ]);
        $client->subscription()->create([
            'site_id' => $client->site_id, 'plan_id' => $budget->id,
            'status' => 'active', 'due_at' => now()->addDays(20),
        ]);

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/subscription/change-plan', [
            'plan_id' => $standard->id,
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.amount', 200000)
            ->assertJsonPath('data.status', Invoice::PENDING)
            ->assertJsonPath('data.meta.type', 'plan_change')
            ->assertJsonPath('data.meta.to_plan_id', $standard->id);
        $this->assertDatabaseHas('central_invoices', [
            'client_id' => $client->id, 'amount' => 200000, 'status' => 'pending',
        ]);
    }

    public function test_change_plan_rejects_same_or_cheaper_or_inactive(): void
    {
        $user = $this->user();
        $budget = $this->plan('budget', 'budget', 100000);
        $standard = $this->plan('standard', 'standard', 300000);
        $legacy = $this->plan('legacy', 'budget', 50000, false);

        $client = Client::query()->create([
            'name' => 'من', 'tier' => 'budget', 'status' => 'active',
            'owner_user_id' => $user->id, 'site_id' => 'site-'.$user->id,
        ]);
        $client->subscription()->create([
            'site_id' => $client->site_id, 'plan_id' => $standard->id,
            'status' => 'active', 'due_at' => now()->addDays(20),
        ]);
        $auth = $this->actingAs($user, 'sanctum');

        // همین پلن فعال
        $auth->postJson('/api/v1/admin/subscription/change-plan', ['plan_id' => $standard->id])
            ->assertStatus(422)->assertJsonPath('message', 'همین پلن در حال حاضر فعال است.');
        // پلن ارزان‌تر (نزول)
        $auth->postJson('/api/v1/admin/subscription/change-plan', ['plan_id' => $budget->id])
            ->assertStatus(422)->assertJsonPath('message', 'ارتقا فقط به پلن بالاتر (گران‌تر) مجاز است.');
        // پلن غیرفعال
        $auth->postJson('/api/v1/admin/subscription/change-plan', ['plan_id' => $legacy->id])
            ->assertStatus(422)->assertJsonPath('message', 'این پلن در حال حاضر فعال نیست.');
    }
}
