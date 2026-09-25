<?php

namespace App\Http\Controllers\Api\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\BillingEvent;
use App\Models\Central\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** فاکتورهای مرکزی (تسک ۱.۳): ساخت/لیست + تغییر وضعیت دستی. */
class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::query()->with('client:id,name,site_id');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($clientId = $request->query('client_id')) {
            $query->where('client_id', $clientId);
        }

        return response()->json(['data' => $query->latest()->paginate(20)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:central_clients,id',
            'subscription_id' => 'sometimes|nullable|exists:central_subscriptions,id',
            'amount' => 'required|integer|min:0',
            'currency' => 'sometimes|string|max:10',
            'due_at' => 'sometimes|nullable|date',
            'meta' => 'sometimes|nullable|array',
        ], [
            'client_id.required' => 'مشتری الزامی است.',
            'client_id.exists' => 'مشتری یافت نشد.',
            'amount.required' => 'مبلغ الزامی است.',
        ]);

        $invoice = Invoice::query()->create(array_merge(
            ['status' => Invoice::PENDING, 'currency' => 'IRT'],
            $validated
        ));

        return response()->json(['message' => 'فاکتور ساخته شد.', 'data' => $invoice], 201);
    }

    /** تغییر وضعیت دستی (مثلاً ثبت پرداخت آفلاین/ابطال). paid شدن دستی هم تعلیق را رفع می‌کند. */
    public function updateStatus(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:pending,paid,failed,cancelled',
        ], [
            'status.in' => 'وضعیت فاکتور معتبر نیست.',
        ]);

        $invoice->forceFill([
            'status' => $validated['status'],
            'paid_at' => $validated['status'] === Invoice::PAID ? now() : $invoice->paid_at,
        ])->save();

        $subscription = $invoice->subscription;
        if ($validated['status'] === Invoice::PAID && $subscription
            && $subscription->status === \App\Models\Central\Subscription::SUSPENDED) {
            $subscription->forceFill([
                'status' => \App\Models\Central\Subscription::ACTIVE,
                'suspended_at' => null,
            ])->save();
            $subscription->client?->forceFill(['status' => 'active'])->save();
        }

        BillingEvent::query()->create([
            'subscription_id' => $invoice->subscription_id,
            'client_id' => $invoice->client_id,
            'type' => 'payment',
            'message' => "تغییر دستی وضعیت فاکتور #{$invoice->id} به {$validated['status']}.",
        ]);

        return response()->json(['message' => 'وضعیت فاکتور به‌روزرسانی شد.', 'data' => $invoice->fresh()]);
    }

    /**
     * یادآوری دستی فاکتور پرداخت‌نشده (دسته ۱ برابری UI/UX — دکمه per-row).
     * فقط ثبت رویداد حسابرسی remind است؛ ارسال واقعی پیامک/ایمیل TODO مرحله ۵.
     */
    public function remind(Invoice $invoice): JsonResponse
    {
        if ($invoice->isPaid()) {
            return response()->json(['message' => 'این فاکتور پرداخت شده و نیازی به یادآوری نیست.'], 422);
        }

        $event = BillingEvent::query()->create([
            'subscription_id' => $invoice->subscription_id,
            'client_id' => $invoice->client_id,
            'type' => 'remind',
            'message' => "یادآوری دستی فاکتور #{$invoice->id} توسط اپراتور.",
        ]);

        return response()->json([
            'message' => 'یادآوری فاکتور ثبت شد.',
            'data' => $event,
        ], 201);
    }
}
