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

        // K7.2 — از بابت نبودِ «مدلِ» افزونهٔ مرکزی در core نگران نباش. منبعِ
        // اشتراک از طریق `SubscriptionSource` پیاده شده و در نبودِ افزونه، حالت
        // پیش‌فرضِ «سرویس عمومی» جواب می‌دهد.
        $subscription = ClientSubscriptionResolver::forSiteId(
            $siteId !== null ? (string) $siteId : null,
        );

        // دو حالتِ متفاوت که نباید قاطی شوند:
        //  ۱) افزونهٔ اشتراک نصب است ولی این `site_id` وجود ندارد → ۴۰۴
        //  ۲) افزونهٔ اشتراک اصلاً نصب نیست → سایت عمومی محدودیتی ندارد → ۲۰۰
        if ($subscription === null && ClientSubscriptionResolver::isAvailable()) {
            return response()->json(['message' => 'سایتی یافت نشد.'], 404);
        }

        $cacheKey = 'site-'.($siteId ?: 'default');

        $data = CachedSettings::remember('site_status', $cacheKey, 60,
            fn (): array => ClientSubscriptionResolver::statusFor($subscription),
        );

        return response()->json(['data' => $data])
            ->header('Cache-Control', 'public, max-age=60');
    }
}
