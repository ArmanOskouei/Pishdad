<?php

namespace Pishdad\Plugins\Central\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Pishdad\Plugins\Central\Models\BillingEvent;
use Pishdad\Plugins\Central\Models\Subscription;

/** کنترل تعلیق مرکزی (تسک ۱.۳): لیست معلق‌ها + suspend/resume/delete دستی. */
class SubscriptionAdminController extends Controller
{
    public function suspended(): JsonResponse
    {
        $items = Subscription::query()
            ->where('status', Subscription::SUSPENDED)
            ->with(['client:id,name,site_id,domain', 'plan:id,name,slug'])
            ->latest('suspended_at')
            ->paginate(20);

        $items->getCollection()->transform(fn (Subscription $s) => array_merge($s->toArray(), [
            'days_remaining' => $s->purgeDaysRemaining(),
        ]));

        return response()->json(['data' => $items]);
    }

    public function suspend(Subscription $subscription): JsonResponse
    {
        $subscription->forceFill([
            'status' => Subscription::SUSPENDED,
            'suspended_at' => now(),
        ])->save();
        $subscription->client?->forceFill(['status' => 'suspended'])->save();

        BillingEvent::query()->create([
            'subscription_id' => $subscription->id,
            'client_id' => $subscription->client_id,
            'type' => 'suspend',
            'message' => 'تعلیق دستی توسط اپراتور.',
        ]);

        return response()->json(['message' => 'اشتراک معلق شد.', 'data' => $subscription->fresh()]);
    }

    public function resume(Subscription $subscription): JsonResponse
    {
        $subscription->forceFill([
            'status' => Subscription::ACTIVE,
            'suspended_at' => null,
        ])->save();
        $subscription->client?->forceFill(['status' => 'active'])->save();

        BillingEvent::query()->create([
            'subscription_id' => $subscription->id,
            'client_id' => $subscription->client_id,
            'type' => 'resume',
            'message' => 'بازگشایی دستی توسط اپراتور.',
        ]);

        return response()->json(['message' => 'اشتراک بازگشایی شد.', 'data' => $subscription->fresh()]);
    }

    public function destroy(Subscription $subscription): JsonResponse
    {
        $subscription->forceFill([
            'status' => Subscription::DELETED,
            'deleted_at' => now(),
        ])->save();
        $subscription->client?->forceFill(['status' => 'deleted'])->save();

        BillingEvent::query()->create([
            'subscription_id' => $subscription->id,
            'client_id' => $subscription->client_id,
            'type' => 'purge',
            'message' => 'حذف دستی توسط اپراتور.',
        ]);

        return response()->json(['message' => 'اشتراک حذف شد.']);
    }
}
