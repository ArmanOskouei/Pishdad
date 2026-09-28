<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Pishdad\Plugins\Central\Models\Client;
use Pishdad\Plugins\Central\Models\Invoice;
use Pishdad\Plugins\Central\Models\Payment;
use Pishdad\Plugins\Central\Models\Plan;
use Pishdad\Plugins\Central\Models\Subscription;
use Tests\TestCase;

/** تسک ۳.۲ — DoD: آمار مرکزی (فعال/معلق/MRR/سررسیده) + سری ۱۲ ماهه درآمد/مشتری. */
class CentralDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::query()->create([
            'name' => 'اپراتور', 'email' => 'op'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'operator',
        ]);
    }

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'مشتری', 'email' => 'c'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'admin',
        ]);
    }

    private function client(string $status = 'active', ?Plan $plan = null): Client
    {
        $client = Client::query()->create([
            'name' => 'مشتری '.uniqid(), 'tier' => 'budget', 'status' => $status,
            'site_id' => 'site-'.uniqid(),
        ]);
        Subscription::query()->create([
            'client_id' => $client->id, 'site_id' => $client->site_id,
            'plan_id' => $plan?->id,
            'status' => $status === 'suspended' ? Subscription::SUSPENDED : Subscription::ACTIVE,
            'due_at' => now()->addDays(30),
            'suspended_at' => $status === 'suspended' ? now() : null,
        ]);

        return $client;
    }

    public function test_stats_counts_mrr_and_overdue(): void
    {
        $op = $this->operator();
        $plan = Plan::query()->create([
            'name' => 'استاندارد', 'slug' => 'standard-'.uniqid(), 'tier' => 'standard',
            'price_monthly' => 500000, 'price_yearly' => 5000000,
        ]);

        $this->client('active', $plan);
        $this->client('active', $plan);
        $this->client('suspended');

        $overdueClient = $this->client('active');
        Invoice::query()->create([
            'client_id' => $overdueClient->id, 'amount' => 200000,
            'status' => Invoice::PENDING, 'due_at' => now()->subDays(3),
        ]);

        $res = $this->actingAs($op, 'sanctum')->getJson('/api/v1/p/central/dashboard/stats');

        $res->assertOk()
            ->assertJsonPath('data.active_clients', 3)
            ->assertJsonPath('data.suspended_clients', 1)
            ->assertJsonPath('data.mrr', 1000000)
            ->assertJsonPath('data.overdue_invoices', 1);
    }

    public function test_charts_returns_twelve_month_series(): void
    {
        $op = $this->operator();
        $client = $this->client('active');
        $invoice = Invoice::query()->create([
            'client_id' => $client->id, 'amount' => 300000, 'status' => Invoice::PAID,
        ]);
        Payment::query()->create([
            'invoice_id' => $invoice->id, 'gateway' => 'zarinpal-stub',
            'ref' => 'ref-'.uniqid(), 'amount' => 300000, 'status' => 'paid',
        ])->forceFill(['created_at' => now()->subMonths(2)])->save();

        $res = $this->actingAs($op, 'sanctum')->getJson('/api/v1/p/central/dashboard/charts');

        $res->assertOk();
        $this->assertCount(12, $res->json('data.revenue_12m'));
        $this->assertCount(12, $res->json('data.customers_12m'));

        $targetMonth = now()->subMonths(2)->format('Y-m');
        $row = collect($res->json('data.revenue_12m'))->firstWhere('month', $targetMonth);
        $this->assertNotNull($row);
        $this->assertSame(300000, $row['total']);
    }

    public function test_non_operator_is_forbidden(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/p/central/dashboard/stats')
            ->assertForbidden();
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/p/central/dashboard/charts')
            ->assertForbidden();
    }
}
