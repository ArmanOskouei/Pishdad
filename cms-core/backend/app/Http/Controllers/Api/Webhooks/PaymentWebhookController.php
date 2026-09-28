<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Billing\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pishdad\Plugins\Central\Models\Invoice;

/**
 * وب‌هوک درگاه پرداخت (تسک ۱.۳): تأیید HMAC روی بدنه خام + idempotent با ref.
 * موفق → invoice=paid + رفع تعلیق خودکار (داخل InvoiceService).
 */
class PaymentWebhookController extends Controller
{
    public function handle(Request $request, InvoiceService $invoices): JsonResponse
    {
        $secret = (string) config('billing.webhook_secret');
        $signature = (string) $request->header('X-Signature', '');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'امضای وب‌هوک معتبر نیست.'], 401);
        }

        $validated = $request->validate([
            'ref' => 'required|string|max:100',
            'status' => 'required|string|in:paid,failed',
        ], [
            'ref.required' => 'شناسه پرداخت الزامی است.',
            'status.in' => 'وضعیت پرداخت معتبر نیست.',
        ]);

        $invoice = Invoice::query()->where('gateway_ref', $validated['ref'])->first();

        // ref ناشناس ولی امضای درست = payment یتیم؛ 404 با پیام فارسی (نه 401).
        if (! $invoice) {
            return response()->json(['message' => 'فاکتوری با این شناسه یافت نشد.'], 404);
        }

        if ($validated['status'] === 'failed') {
            $invoice->forceFill(['status' => Invoice::FAILED])->save();
            $invoice->payments()->where('ref', $validated['ref'])->update(['status' => 'failed']);

            return response()->json(['message' => 'پرداخت ناموفق ثبت شد.']);
        }

        $result = $invoices->markPaid(
            $invoice,
            $validated['ref'],
            (string) ($request->input('gateway', 'zarinpal-stub')),
            $request->input('raw', [])
        );

        return response()->json([
            'message' => $result['duplicate'] ? 'این پرداخت قبلاً ثبت شده است.' : 'پرداخت با موفقیت ثبت شد؛ تعلیق (در صورت وجود) رفع شد.',
            'data' => ['invoice_id' => $result['invoice']->id, 'status' => $result['invoice']->status],
        ]);
    }
}
