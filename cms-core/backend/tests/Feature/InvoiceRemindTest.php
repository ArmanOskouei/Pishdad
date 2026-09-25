<?php

namespace Tests\Feature;

use App\Models\Central\Client;
use App\Models\Central\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** دسته ۱ (برابری UI/UX) — دکمه یادآوری per-row فاکتور پرداخت‌نشده. */
class InvoiceRemindTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::query()->create([
            'name' => 'اپراتور', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'operator',
        ]);
    }

    public function test_remind_logs_event_for_pending_invoice(): void
    {
        $op = $this->operator();
        $client = Client::query()->create([
            'name' => 'مشتری', 'tier' => 'budget', 'status' => 'active',
            'site_id' => 'site-'.uniqid(),
        ]);
        $invoice = Invoice::query()->create([
            'client_id' => $client->id, 'amount' => 100000,
            'status' => Invoice::PENDING, 'currency' => 'IRT',
        ]);

        $res = $this->actingAs($op, 'sanctum')
            ->postJson("/api/central/invoices/{$invoice->id}/remind");

        $res->assertCreated()->assertJsonPath('message', 'یادآوری فاکتور ثبت شد.');
        $this->assertDatabaseHas('central_billing_events', [
            'client_id' => $client->id, 'type' => 'remind',
        ]);
        // فاکتور دست‌نخورده می‌ماند (فقط رویداد ثبت می‌شود).
        $this->assertSame(Invoice::PENDING, $invoice->fresh()->status);
    }

    public function test_remind_rejects_paid_invoice(): void
    {
        $op = $this->operator();
        $client = Client::query()->create([
            'name' => 'مشتری', 'tier' => 'budget', 'status' => 'active',
            'site_id' => 'site-'.uniqid(),
        ]);
        $invoice = Invoice::query()->create([
            'client_id' => $client->id, 'amount' => 100000,
            'status' => Invoice::PAID, 'currency' => 'IRT', 'paid_at' => now(),
        ]);

        $this->actingAs($op, 'sanctum')
            ->postJson("/api/central/invoices/{$invoice->id}/remind")
            ->assertStatus(422)
            ->assertJsonPath('message', 'این فاکتور پرداخت شده و نیازی به یادآوری نیست.');
    }
}
