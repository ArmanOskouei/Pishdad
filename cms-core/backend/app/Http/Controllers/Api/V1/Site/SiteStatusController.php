<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Services\Settings\CachedSettings;
use App\Services\Subscription\ClientSubscriptionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * تسک ۴.۳ — وضعیت عمومی سایت (عمومی، سبک، بدون احراز هویت).
 * منطق وضعیت از همان سرویس اشتراک مرحله ۱ می‌آید (تک‌منبع):
 * active|suspended + پیام برند. بدون هیچ داده حساس.
 */
class SiteStatusController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $siteId = $request->query('site_id');

        $subscription = $siteId
            ? ClientSubscriptionResolver::bySiteId((string) $siteId)
            : ClientSubscriptionResolver::default();

        if (! $subscription) {
            return response()->json(['message' => 'سایتی یافت نشد.'], 404);
        }

        $cacheKey = 'site-'.($siteId ?: 'default-'.$subscription->id);

        $data = CachedSettings::remember('site_status', $cacheKey, 60, function () use ($subscription) {
            return [
                'status' => ClientSubscriptionResolver::publicStatus($subscription),
                'message' => ClientSubscriptionResolver::statusMessage($subscription),
            ];
        });

        return response()->json(['data' => $data])
            ->header('Cache-Control', 'public, max-age=60');
    }
}
