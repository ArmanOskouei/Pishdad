<?php

namespace App\Services\Billing;

use App\Models\Central\BillingEvent;
use App\Models\Central\Invoice;
use App\Models\Central\Payment;
use App\Models\Central\Subscription;
use Illuminate\Support\Facades\DB;

/**
 * تنها نقطه «paid شدن» فاکتور — هم وب‌هوک درگاه و هم (در آینده) verify دستی
 * از همین‌جا می‌گذرند تا idempotency و رفع تعلیق خودکار یکسان بماند.
 */
class InvoiceService
{
    /**
     * ثبت پرداخت موفق با ref درگاه؛ تکراری (همان ref) بدون اثر برمی‌گردد.
     * اثر: invoice=paid + paid_at، payment ثبت/تکمیل، اشتراک معلق → active.
     */
    public function markPaid(Invoice $invoice, string $gatewayRef, string $gateway, array $raw = []): array
    {
        return DB::transaction(function () use ($invoice, $gatewayRef, $gateway, $raw) {
            $invoice = $invoice->lockForUpdate()->first();

            $existing = Payment::query()->where('ref', $gatewayRef)->first();
            if ($existing) {
                return ['invoice' => $existing->invoice()->first() ?? $invoice, 'duplicate' => true];
            }

            $invoice->forceFill([
                'status' => Invoice::PAID,
                'paid_at' => now(),
                'gateway_ref' => $gatewayRef,
            ])->save();

            Payment::query()->create([
                'invoice_id' => $invoice->id,
                'gateway' => $gateway,
                'ref' => $gatewayRef,
                'amount' => $invoice->amount,
                'status' => 'paid',
                'raw' => $raw,
            ]);

            $subscription = $invoice->subscription;
            if ($subscription && $subscription->status === Subscription::SUSPENDED) {
                $subscription->forceFill([
                    'status' => Subscription::ACTIVE,
                    'suspended_at' => null,
                ])->save();

                if ($subscription->client) {
                    $subscription->client->forceFill(['status' => 'active'])->save();
                }

                BillingEvent::query()->create([
                    'subscription_id' => $subscription->id,
                    'client_id' => $subscription->client_id,
                    'type' => 'resume',
                    'message' => 'رفع تعلیق خودکار پس از پرداخت موفق.',
                ]);
            }

            BillingEvent::query()->create([
                'subscription_id' => $invoice->subscription_id,
                'client_id' => $invoice->client_id,
                'type' => 'payment',
                'message' => "پرداخت موفق فاکتور #{$invoice->id} (ref: {$gatewayRef}).",
            ]);

            return ['invoice' => $invoice->fresh(), 'duplicate' => false];
        });
    }
}
