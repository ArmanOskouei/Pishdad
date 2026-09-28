<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Billing\PaymentGatewayInterface;
use App\Services\Billing\ZarinpalStubDriver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pishdad\Plugins\Central\Models\Client;
use Pishdad\Plugins\Central\Models\Invoice;
use Pishdad\Plugins\Central\Models\Plan;
use Pishdad\Plugins\Central\Models\Subscription;

/**
 * تسک ۱.۲ — «اشتراک من» (سمت نصب مشتری).
 * خواندن فقط ردیف خود مشتری (تطبیق owner_user_id)؛ پرداخت = ساخت payment session
 * در درگاه مرکزی (Stub زرین‌پال، بدون شارژ واقعی) و بازگشت pay_url.
 */
class SubscriptionController extends Controller
{
    private function client(Request $request): Client
    {
        return Client::query()->firstOrCreate(
            ['site_id' => 'site-'.$request->user()->id],
            [
                'name' => $request->user()->name ?? 'سایت من',
                'tier' => 'budget',
                'status' => 'active',
                'owner_user_id' => $request->user()->id,
            ]
        );
    }

    private function subscription(Client $client): Subscription
    {
        return Subscription::query()->firstOrCreate(
            ['client_id' => $client->id],
            [
                'site_id' => $client->site_id,
                'status' => Subscription::ACTIVE,
                'due_at' => now()->addDays(30),
            ]
        );
    }

    public function show(Request $request): JsonResponse
    {
        $client = $this->client($request);
        $subscription = $this->subscription($client)->load('plan');

        return response()->json([
            'data' => [
                'plan' => $subscription->plan?->only(['id', 'name', 'slug', 'tier', 'price_monthly', 'price_yearly']),
                'tier' => $client->tier,
                'status' => $subscription->status,
                'due_at' => $subscription->due_at,
                'suspended_at' => $subscription->suspended_at,
                'days_remaining' => $subscription->daysRemaining(),
            ],
        ]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $client = $this->client($request);

        $invoices = Invoice::query()
            ->where('client_id', $client->id)
            ->latest()
            ->paginate((int) $request->query('per_page', 15));

        return response()->json($invoices);
    }

    /** فهرست پلن‌های فعال برای مقایسه و ارتقا (فقط خواندنی — قیمت‌ها از پلن مرکزی). */
    public function plans(): JsonResponse
    {
        $plans = Plan::query()
            ->where('active', true)
            ->orderBy('price_monthly')
            ->get(['id', 'name', 'slug', 'tier', 'price_monthly', 'price_yearly', 'currency', 'features']);

        return response()->json(['data' => $plans]);
    }

    /**
     * ارتقا پلن: ساخت فاکتور مابه‌التفاوت (یا فاکتور کامل دوره اگر پلن فعلی قیمت ندارد)
     * و بازگشت آن؛ پرداخت با همان مسیر pay موجود + Stepper فرانت انجام می‌شود.
     */
    public function changePlan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => 'required|integer|exists:central_plans,id',
        ], [
            'plan_id.required' => 'پلن مقصد الزامی است.',
            'plan_id.exists' => 'پلن مقصد یافت نشد.',
        ]);

        $client = $this->client($request);
        $subscription = $this->subscription($client)->load('plan');
        $target = Plan::query()->findOrFail($validated['plan_id']);

        if (! $target->active) {
            return response()->json(['message' => 'این پلن در حال حاضر فعال نیست.'], 422);
        }
        if ((int) $subscription->plan_id === (int) $target->id) {
            return response()->json(['message' => 'همین پلن در حال حاضر فعال است.'], 422);
        }

        $currentMonthly = (int) ($subscription->plan?->price_monthly ?? 0);
        $targetMonthly = (int) ($target->price_monthly ?? 0);

        if ($targetMonthly <= 0) {
            return response()->json(['message' => 'قیمت پلن مقصد نامعتبر است.'], 422);
        }

        // مابه‌التفاوت ماهانه؛ اگر پلن فعلی قیمت ندارد (یا ارزان‌تر است) همان اختلاف،
        // و اگر مقصد ارزان‌تر از فعلی است فقط اجازه ارتقا به بالاتر داده می‌شود.
        $diff = $targetMonthly - $currentMonthly;
        if ($diff <= 0) {
            return response()->json(['message' => 'ارتقا فقط به پلن بالاتر (گران‌تر) مجاز است.'], 422);
        }

        $invoice = Invoice::query()->create([
            'client_id' => $client->id,
            'subscription_id' => $subscription->id,
            'amount' => $diff,
            'currency' => $target->currency ?? 'IRT',
            'status' => Invoice::PENDING,
            'due_at' => now()->addDays(7),
            'meta' => [
                'type' => 'plan_change',
                'from_plan_id' => $subscription->plan_id,
                'to_plan_id' => $target->id,
                'from_tier' => $client->tier,
                'to_tier' => $target->tier,
            ],
        ]);

        return response()->json([
            'message' => "فاکتور مابه‌التفاوت ارتقا به «{$target->name}» ساخته شد؛ با همان Stepper پرداخت ادامه دهید.",
            'data' => $invoice,
        ], 201);
    }

    /** شروع پرداخت فاکتور خودی → session درگاه مرکزی (Stub، بدون شارژ واقعی). */
    public function pay(Request $request, Invoice $invoice, PaymentGatewayInterface $gateway): JsonResponse
    {
        $client = $this->client($request);

        if ((int) $invoice->client_id !== (int) $client->id) {
            return response()->json(['message' => 'این فاکتور متعلق به شما نیست.'], 403);
        }
        if ($invoice->isPaid()) {
            return response()->json(['message' => 'این فاکتور قبلاً پرداخت شده است.'], 422);
        }

        $session = $gateway->requestPayment(
            (int) $invoice->amount,
            "پرداخت فاکتور #{$invoice->id}",
            (string) config('billing.gateway.callback_url'),
            ['invoice_id' => $invoice->id, 'client_id' => $client->id]
        );

        $invoice->forceFill(['gateway_ref' => $session['authority']])->save();
        $invoice->payments()->create([
            'gateway' => $gateway->gatewayName(),
            'ref' => $session['authority'],
            'amount' => $invoice->amount,
            'status' => 'pending',
            'raw' => ['pay_url' => $session['pay_url']],
        ]);

        return response()->json([
            'message' => 'در حال انتقال به درگاه پرداخت...',
            'data' => [
                'authority' => $session['authority'],
                'pay_url' => $session['pay_url'],
            ],
        ]);
    }

    /** رزولو موقت درگاه Stub برای تزریق — جایگزین واقعی در AppServiceProvider می‌آید. */
    public static function gateway(): PaymentGatewayInterface
    {
        return new ZarinpalStubDriver;
    }
}
