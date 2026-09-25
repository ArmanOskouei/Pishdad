<?php

namespace Tests\Feature;

use App\Models\Central\Client;
use App\Models\Central\Invoice;
use App\Models\Central\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** تسک ۱.۲ — DoD: دیدن اشتراک/فاکتور + پرداخت Stub + polling عمومی. */
class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'admin'): User
    {
        return User::query()->create([
            'name' => 'مشتری تست',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => $role,
        ]);
    }

    private function operator(): User
    {
        // گیت مرکزی ستون role را می‌خواند (spatie لازم نیست).
        return $this->user('operator');
    }

    public function test_show_autoprovides_own_subscription(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/subscription');

        $res->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.tier', 'budget');
        $this->assertNotEmpty($res->json('data.due_at'));
    }

    public function test_invoices_lists_only_own(): void
    {
        $mine = $this->user();
        $theirs = $this->user();
        $op = $this->operator();

        $myClient = Client::query()->create([
            'name' => 'من', 'tier' => 'budget', 'status' => 'active',
            'owner_user_id' => $mine->id, 'site_id' => 'site-'.$mine->id,
        ]);
        $otherClient = Client::query()->create([
            'name' => 'او', 'tier' => 'budget', 'status' => 'active',
            'owner_user_id' => $theirs->id, 'site_id' => 'site-'.$theirs->id,
        ]);

        $this->actingAs($op, 'sanctum')->postJson('/api/central/invoices', [
            'client_id' => $myClient->id, 'amount' => 100000,
        ])->assertCreated();
        $this->actingAs($op, 'sanctum')->postJson('/api/central/invoices', [
            'client_id' => $otherClient->id, 'amount' => 200000,
        ])->assertCreated();

        $res = $this->actingAs($mine, 'sanctum')->getJson('/api/v1/admin/subscription/invoices');
        $res->assertOk()->assertJsonPath('total', 1);
        $this->assertSame(100000, $res->json('data.0.amount'));
    }

    public function test_pay_creates_stub_session_without_real_charge(): void
    {
        $user = $this->user();

        $client = Client::query()->create([
            'name' => 'من', 'tier' => 'budget', 'status' => 'active',
            'owner_user_id' => $user->id, 'site_id' => 'site-'.$user->id,
        ]);
        $invoice = Invoice::query()->create([
            'client_id' => $client->id, 'amount' => 500000,
            'status' => Invoice::PENDING, 'currency' => 'IRT',
        ]);

        $res = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/subscription/invoices/{$invoice->id}/pay");

        $res->assertOk()->assertJsonPath('message', 'در حال انتقال به درگاه پرداخت...');
        $this->assertStringStartsWith('STUB-', $res->json('data.authority'));
        $this->assertStringContainsString('sandbox.zarinpal.com', $res->json('data.pay_url'));
        // فاکتور هنوز paid نشده — فقط session ساخته شده (بدون شارژ واقعی).
        $this->assertSame(Invoice::PENDING, $invoice->fresh()->status);
        $this->assertDatabaseHas('central_payments', [
            'invoice_id' => $invoice->id, 'status' => 'pending',
        ]);
    }

    public function test_pay_rejects_foreign_or_paid_invoice(): void
    {
        $mine = $this->user();
        $theirs = $this->user();

        $myClient = Client::query()->create([
            'name' => 'من', 'status' => 'active', 'owner_user_id' => $mine->id, 'site_id' => 'site-'.$mine->id,
        ]);
        $otherClient = Client::query()->create([
            'name' => 'او', 'status' => 'active', 'owner_user_id' => $theirs->id, 'site_id' => 'site-'.$theirs->id,
        ]);
        $foreign = Invoice::query()->create(['client_id' => $otherClient->id, 'amount' => 10]);
        $paid = Invoice::query()->create([
            'client_id' => $myClient->id, 'amount' => 10, 'status' => Invoice::PAID, 'paid_at' => now(),
        ]);

        $this->actingAs($mine, 'sanctum')
            ->postJson("/api/v1/admin/subscription/invoices/{$foreign->id}/pay")
            ->assertForbidden()->assertJsonPath('message', 'این فاکتور متعلق به شما نیست.');

        $this->actingAs($mine, 'sanctum')
            ->postJson("/api/v1/admin/subscription/invoices/{$paid->id}/pay")
            ->assertStatus(422);
    }

    public function test_public_status_polling_is_lightweight(): void
    {
        $user = $this->user();
        $client = Client::query()->create([
            'name' => 'من', 'status' => 'active', 'owner_user_id' => $user->id, 'site_id' => 'poll-site-1',
        ]);
        Subscription::query()->create([
            'client_id' => $client->id, 'site_id' => 'poll-site-1',
            'status' => Subscription::SUSPENDED, 'suspended_at' => now()->subDays(2),
            'due_at' => now()->subDays(2),
        ]);

        // بدون توکن هم پاسخ می‌دهد (عمومی).
        $res = $this->getJson('/api/v1/subscription-status?site_id=poll-site-1');

        $res->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.suspended', true)
            ->assertJsonPath('data.days_remaining', 5);

        $this->getJson('/api/v1/subscription-status?site_id=unknown')->assertNotFound();
    }

    public function test_central_routes_forbid_plain_admin(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin, 'sanctum')->getJson('/api/central/plans')
            ->assertForbidden()
            ->assertJsonPath('message', 'دسترسی مجاز نیست. این بخش فقط برای اپراتور مرکزی است.');
    }
}
