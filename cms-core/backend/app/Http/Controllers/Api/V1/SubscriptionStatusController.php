<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pishdad\Plugins\Central\Models\Subscription;

/**
 * وضعیت اشتراک برای polling نصب مشتری (عمومی، سبک، بدون احراز هویت).
 * فقط status + due_at + suspended برمی‌گردد — بدون هیچ داده حساس.
 */
class SubscriptionStatusController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $request->validate([
            'site_id' => 'required|string|max:100',
        ], [
            'site_id.required' => 'شناسه سایت الزامی است.',
        ]);

        $subscription = Subscription::query()
            ->where('site_id', $request->query('site_id'))
            ->with('plan:id,name,slug,tier')
            ->first();

        if (! $subscription) {
            return response()->json(['message' => 'اشتراکی برای این سایت یافت نشد.'], 404);
        }

        return response()->json([
            'data' => [
                'status' => $subscription->status,
                'due_at' => $subscription->due_at,
                'suspended' => $subscription->status === Subscription::SUSPENDED,
                'days_remaining' => $subscription->daysRemaining(),
                'plan' => $subscription->plan?->only(['name', 'slug', 'tier']),
            ],
        ])->header('Cache-Control', 'public, max-age=60');
    }
}
