<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Pishdad\Plugins\Central\Models\BillingEvent;
use Pishdad\Plugins\Central\Models\Client;
use Pishdad\Plugins\Central\Models\Invoice;
use Pishdad\Plugins\Central\Models\Payment;
use Pishdad\Plugins\Central\Models\Subscription;
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

    private function webhook(string $ref, string $status): TestResponse
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

        $created = $auth->postJson('/api/v1/p/central/plans', [
            'name' => 'استاندارد', 'slug' => 'standard', 'tier' => 'standard',
            'price_monthly' => 500000,
        ])->assertCreated();
        $id = $created->json('data.id');

        $auth->getJson('/api/v1/p/central/plans')->assertOk();
        $auth->putJson("/api/v1/p/central/plans/{$id}", ['price_monthly' => 600000])
            ->assertOk()->assertJsonPath('data.price_monthly', 600000);
        $auth->deleteJson("/api/v1/p/central/plans/{$id}")->assertOk()
            ->assertJsonPath('message', 'پلن حذف شد.');
    }

    public function test_plan_with_subscription_cannot_be_deleted(): void
    {
        $op = $this->operator();
        $auth = $this->actingAs($op, 'sanctum');

        $planId = $auth->postJson('/api/v1/p/central/plans', [
            'name' => 'پایه', 'slug' => 'basic', 'tier' => 'budget',
        ])->json('data.id');

        $client = $this->client();
        Subscription::query()->create([
            'client_id' => $client->id, 'site_id' => $client->site_id,
            'plan_id' => $planId, 'status' => 'active', 'due_at' => now()->addDays(5),
        ]);

        $auth->deleteJson("/api/v1/p/central/plans/{$planId}")->assertStatus(422);
    }

    /**
     * J10/E1/K1.7 — مسیر callback درگاه **حذف شده** (تصمیم: بدون پرداخت،
     * صورتحساب دستی توسط مدیر مرکزی). این تست عمداً به‌جای تست رفتار
     * گرفته شده: قبلاً دو تست «وب‌هوک paid» و «امضای بد» اینجا بودند و بعد
     * از حذف مسیر، ۴۰۴ می‌گرفتند و قرمز می‌شدند — یعنی تست‌ها دارند وعدهٔ
     * درگاهی می‌دهند که دیگر وجود ندارد.
     *
     * نگهبانِ لازم الان این است که مسیر **نمی‌آید**، نه اینکه چه پاسخی می‌دهد.
     */
    public function test_payment_webhook_route_is_gone(): void
    {
        $this->postJson('/api/webhooks/payment', ['ref' => 'X', 'status' => 'paid'])
            ->assertNotFound();

        $this->assertFalse(
            collect(app('router')->getRoutes())->contains(
                fn ($r) => str_contains((string) $r->uri(), 'webhooks/payment')
            ),
            'مسیر callback درگاه نباید دوباره ثبت شود؛ صورتحساب دستی است (J10).'
        );

        // `class_exists` با کلاس ناموجود، autoloader کامپوزر را به فایل حذف‌شده
        // می‌کشاند و ErrorException می‌دهد ⇒ وجود فایل بررسی می‌شود نه کلاس.
        $this->assertFileDoesNotExist(
            app_path('Http/Controllers/Api/Webhooks/PaymentWebhookController.php'),
            'کنترلر callback درگاه باید حذف‌شده بماند.'
        );
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
            ->putJson("/api/v1/p/central/invoices/{$invoice->id}/status", ['status' => 'paid'])
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

        $res = $this->actingAs($op, 'sanctum')->getJson('/api/v1/p/central/subscriptions/suspended');

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

        $auth->putJson('/api/v1/p/central/gateway-settings', [
            'driver' => 'zarinpal',
            'merchant_id' => 'SECRET-123456',
            'sandbox' => true,
        ])->assertOk()->assertJsonPath('message', 'تنظیمات درگاه ذخیره شد.');

        $res = $auth->getJson('/api/v1/p/central/gateway-settings');
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

        $auth->postJson("/api/v1/p/central/subscriptions/{$sub->id}/suspend")
            ->assertOk()->assertJsonPath('message', 'اشتراک معلق شد.');
        $auth->postJson("/api/v1/p/central/subscriptions/{$sub->id}/resume")
            ->assertOk()->assertJsonPath('message', 'اشتراک بازگشایی شد.');
        $auth->postJson("/api/v1/p/central/subscriptions/{$sub->id}/delete")
            ->assertOk()->assertJsonPath('message', 'اشتراک حذف شد.');
        $this->assertSame('deleted', $sub->fresh()->status);
    }
}
