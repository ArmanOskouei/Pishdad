<?php

namespace Tests\Feature;

use App\Models\Central\BillingEvent;
use App\Models\Central\Client;
use App\Models\Central\Invoice;
use App\Models\Central\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * تسک ۱.۳ — DoD: فاکتور → پرداخت → paid → رفع تعلیق خودکار + Cron در مرز D-0/D+7.
 */
class CentralBillingTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::query()->create([
            'name' => 'اپراتور', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'operator',
        ]);
    }

    private function client(string $tier = 'budget'): Client
    {
        return Client::query()->create([
            'name' => 'مشتری '.uniqid(), 'tier' => $tier, 'status' => 'active',
            'site_id' => 'site-'.uniqid(),
        ]);
    }

    private function webhook(string $ref, string $status): \Illuminate\Testing\TestResponse
    {
        $payload = ['ref' => $ref, 'status' => $status, 'gateway' => 'zarinpal-stub'];
        $body = json_encode($payload);
        $sig = hash_hmac('sha256', $body, (string) config('billing.webhook_secret'));

        return $this->call(
            'POST', '/api/webhooks/payment', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => $sig],
            $body
        );
    }

    public function test_plans_crud(): void
    {
        $op = $this->operator();
        $auth = $this->actingAs($op, 'sanctum');

        $created = $auth->postJson('/api/central/plans', [
            'name' => 'استاندارد', 'slug' => 'standard', 'tier' => 'standard',
            'price_monthly' => 500000,
        ])->assertCreated();
        $id = $created->json('data.id');

        $auth->getJson('/api/central/plans')->assertOk();
        $auth->putJson("/api/central/plans/{$id}", ['price_monthly' => 600000])
            ->assertOk()->assertJsonPath('data.price_monthly', 600000);
        $auth->deleteJson("/api/central/plans/{$id}")->assertOk()
            ->assertJsonPath('message', 'پلن حذف شد.');
    }

    public function test_plan_with_subscription_cannot_be_deleted(): void
    {
        $op = $this->operator();
        $auth = $this->actingAs($op, 'sanctum');

        $planId = $auth->postJson('/api/central/plans', [
            'name' => 'پایه', 'slug' => 'basic', 'tier' => 'budget',
        ])->json('data.id');

        $client = $this->client();
        Subscription::query()->create([
            'client_id' => $client->id, 'site_id' => $client->site_id,
            'plan_id' => $planId, 'status' => 'active', 'due_at' => now()->addDays(5),
        ]);

        $auth->deleteJson("/api/central/plans/{$planId}")->assertStatus(422);
    }

    public function test_webhook_paid_marks_invoice_and_auto_resumes(): void
    {
        $op = $this->operator();
        $client = $this->client();
        $sub = Subscription::query()->create([
            'client_id' => $client->id, 'site_id' => $client->site_id,
            'status' => Subscription::SUSPENDED, 'suspended_at' => now()->subDay(),
            'due_at' => now()->subDay(),
        ]);
        $client->forceFill(['status' => 'suspended'])->save();

        $invoice = Invoice::query()->create([
            'client_id' => $client->id, 'subscription_id' => $sub->id,
            'amount' => 300000, 'status' => Invoice::PENDING,
            'gateway_ref' => 'STUB-TEST-1',
        ]);

        $res = $this->webhook('STUB-TEST-1', 'paid');

        $res->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertTrue($invoice->fresh()->isPaid());
        $this->assertSame(Subscription::ACTIVE, $sub->fresh()->status);
        $this->assertSame('active', $client->fresh()->status);
        $this->assertDatabaseHas('central_payments', ['ref' => 'STUB-TEST-1', 'status' => 'paid']);
        $this->assertDatabaseHas('central_billing_events', [
            'subscription_id' => $sub->id, 'type' => 'resume',
        ]);

        // وب‌هوک تکراری (همان ref) idempotent است.
        $again = $this->webhook('STUB-TEST-1', 'paid');
        $again->assertOk()->assertJsonPath('message', 'این پرداخت قبلاً ثبت شده است.');
        $this->assertSame(1, \App\Models\Central\Payment::query()->where('ref', 'STUB-TEST-1')->count());

        $this->actingAs($op, 'sanctum')->getJson('/api/central/payments')
            ->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_webhook_rejects_bad_signature(): void
    {
        $body = json_encode(['ref' => 'X', 'status' => 'paid']);

        $this->call('POST', '/api/webhooks/payment', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => 'wrong'],
            $body
        )->assertStatus(401)->assertJsonPath('message', 'امضای وب‌هوک معتبر نیست.');
    }

    public function test_central_invoice_status_change_resumes_suspended(): void
    {
        $op = $this->operator();
        $client = $this->client();
        $sub = Subscription::query()->create([
            'client_id' => $client->id, 'site_id' => $client->site_id,
            'status' => Subscription::SUSPENDED, 'suspended_at' => now()->subDay(),
            'due_at' => now()->subDay(),
        ]);
        $invoice = Invoice::query()->create([
            'client_id' => $client->id, 'subscription_id' => $sub->id, 'amount' => 100,
        ]);

        $this->actingAs($op, 'sanctum')
            ->putJson("/api/central/invoices/{$invoice->id}/status", ['status' => 'paid'])
            ->assertOk();

        $this->assertSame(Subscription::ACTIVE, $sub->fresh()->status);
    }

    public function test_suspended_list_reports_days_remaining(): void
    {
        $op = $this->operator();
        $client = $this->client();
        Subscription::query()->create([
            'client_id' => $client->id, 'site_id' => $client->site_id,
            'status' => Subscription::SUSPENDED, 'suspended_at' => now()->subDays(3),
            'due_at' => now()->subDays(3),
        ]);

        $res = $this->actingAs($op, 'sanctum')->getJson('/api/central/subscriptions/suspended');

        $res->assertOk()->assertJsonPath('data.total', 1);
        $this->assertSame(4, $res->json('data.data.0.days_remaining'));
    }

    public function test_billing_cron_boundaries_with_frozen_time(): void
    {
        $op = $this->operator();

        // اشتراک A: سررسید فردا → فقط یادآوری، بدون تعلیق.
        $clientA = $this->client();
        $subA = Subscription::query()->create([
            'client_id' => $clientA->id, 'site_id' => $clientA->site_id,
            'status' => 'active', 'due_at' => Carbon::parse('2026-03-10 12:00'),
        ]);
        Invoice::query()->create(['client_id' => $clientA->id, 'subscription_id' => $subA->id, 'amount' => 100]);

        // اشتراک B: سررسید دقیقاً «الان» (مرز D-0) → تعلیق.
        $clientB = $this->client();
        $subB = Subscription::query()->create([
            'client_id' => $clientB->id, 'site_id' => $clientB->site_id,
            'status' => 'active', 'due_at' => Carbon::parse('2026-03-10 12:00'),
        ]);
        Invoice::query()->create(['client_id' => $clientB->id, 'subscription_id' => $subB->id, 'amount' => 100]);

        // اشتراک C: ۷ روز معلق (مرز D+7) → حذف.
        $clientC = $this->client();
        $subC = Subscription::query()->create([
            'client_id' => $clientC->id, 'site_id' => $clientC->site_id,
            'status' => 'suspended', 'suspended_at' => Carbon::parse('2026-03-03 08:00'),
            'due_at' => Carbon::parse('2026-03-03 08:00'),
        ]);

        // روز قبل از سررسید: A یادآوری می‌گیرد، B تعلیق نمی‌شود.
        Carbon::setTestNow(Carbon::parse('2026-03-09 10:00'));
        $this->artisan('billing:remind')->assertSuccessful();
        $this->artisan('billing:suspend')->assertSuccessful();
        $this->assertSame('active', $subB->fresh()->status);
        $this->assertTrue(BillingEvent::query()
            ->where('subscription_id', $subA->id)->where('type', 'remind')->exists());

        // لحظه سررسید B (مرز D-0): تعلیق می‌شود.
        Carbon::setTestNow(Carbon::parse('2026-03-10 12:00'));
        $this->artisan('billing:suspend')->assertSuccessful();
        $this->assertSame('suspended', $subB->fresh()->status);

        // مرز D+7 برای C: دقیقاً ۷ روز بعد از تعلیق → حذف.
        Carbon::setTestNow(Carbon::parse('2026-03-10 08:00'));
        $this->artisan('billing:purge')->assertSuccessful();
        $this->assertSame('deleted', $subC->fresh()->status);

        // معلق ۶ روزه حذف نمی‌شود (بازنشانی مستقیم روی سطر DB، بدون تکیه بر state حافظه).
        Carbon::setTestNow(Carbon::parse('2026-03-09 08:00'));
        Subscription::query()->where('id', $subC->id)->update(['status' => 'suspended', 'deleted_at' => null]);
        $this->artisan('billing:purge')->assertSuccessful();
        $this->assertSame('suspended', $subC->fresh()->status);

        Carbon::setTestNow();
        $this->assertNotNull($op->id);
    }

    public function test_gateway_settings_mask_secret(): void
    {
        $op = $this->operator();
        $auth = $this->actingAs($op, 'sanctum');

        $auth->putJson('/api/central/gateway-settings', [
            'driver' => 'zarinpal-stub',
            'merchant_id' => 'SECRET-123456',
            'sandbox' => true,
        ])->assertOk()->assertJsonPath('message', 'تنظیمات درگاه ذخیره شد.');

        $res = $auth->getJson('/api/central/gateway-settings');
        $res->assertOk()->assertJsonPath('data.merchant_id_masked', 'SECR****');
        $this->assertStringNotContainsString('SECRET-123456', $res->getContent());
    }

    public function test_manual_suspend_resume_delete_cycle(): void
    {
        $op = $this->operator();
        $auth = $this->actingAs($op, 'sanctum');
        $client = $this->client();
        $sub = Subscription::query()->create([
            'client_id' => $client->id, 'site_id' => $client->site_id,
            'status' => 'active', 'due_at' => now()->addDays(5),
        ]);

        $auth->postJson("/api/central/subscriptions/{$sub->id}/suspend")
            ->assertOk()->assertJsonPath('message', 'اشتراک معلق شد.');
        $auth->postJson("/api/central/subscriptions/{$sub->id}/resume")
            ->assertOk()->assertJsonPath('message', 'اشتراک بازگشایی شد.');
        $auth->postJson("/api/central/subscriptions/{$sub->id}/delete")
            ->assertOk()->assertJsonPath('message', 'اشتراک حذف شد.');
        $this->assertSame('deleted', $sub->fresh()->status);
    }
}
