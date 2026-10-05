<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Subscription\SubscriptionResolver;
use App\Services\Subscription\SubscriptionStatusMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * وضعیت اشتراک برای polling نصب مشتری (عمومی، سبک، بدون احراز هویت).
 *
 * ## L-B12 — چرا دیگر مدل مرکز را import نمی‌کند
 *
 * قبلاً مدل Subscription افزونهٔ مرکزی را مستقیم import می‌کرد. یعنی:
 * (۱) هستهٔ منتشرشده به افزونهٔ خصوصی وابسته بود، (۲) در نبودِ افزونه ۵۰۴ می‌داد.
 *
 * حالا از `SubscriptionResolver` می‌گیرد که خودش تصمیم می‌گیرد بین
 * `PluginSubscriptionResolver` و `NullSubscriptionResolver` کدام حاضر است.
 *
 * ## نکتهٔ رفتاریِ عمدی
 *
 * ⭐ **نصبِ تازه «یافت نشد» نمی‌گیرد.** اگر افزونه نصب است ولی هنوز مشترکی
 * ساخته نشده، پاسخ `active` است نه ۴۰۴. دلیل: مسیرهای عمومی سایت این را صدا
 * می‌زنند و ۴۰۴ یعنی سایتِ خودِ کاربر هم باز نمی‌شود. `404` فقط برای حالتی است
 * که افزونه هست و این `site_id` واقعاً وجود ندارد.
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

        $siteId = (string) $request->query('site_id');

        // J12 — تفکیکِ «سرویسِ اشتراک نصب نیست» از «این سایتِ مشخص در سرویس نیست».
        //
        // نسخهٔ قبلی با هر `missing` یک ۴۰۴ با پیامِ «اشتراکِ فعال نداری» می‌داد،
        // حتی وقتی هیچ سرویسِ اشتراکی روی نصب نبود — یعنی یک نصبِ رایگانِ کاملاً
        // سالم به کاربر دروغ می‌گفت که اشتراکش نیست. حالا:
        //
        //   • `available === false` ⇒ سرویسی نیست؛ نه ۴۰۴، نه پیامِ گمراه‌کننده.
        //   • `available === true`  ⇒ سرویس هست؛ `missing` واقعاً یعنی این
        //     `site_id` ناشناس است و ۴۰۴ صادقانه است.
        $status = SubscriptionResolver::publicStatus($siteId);
        $available = SubscriptionResolver::isAvailable();

        // سرویسِ اشتراک روی این نصب نیست ⇒ متن را **هسته** می‌سازد تا هیچ
        // resolverی نتواند پیامِ گمراه‌کننده («اشتراک نداری») برگرداند. این همان
        // «صفر اثر برای نصبِ رایگان» است که J12 می‌خواست.
        if (! $available) {
            $status = [
                'status' => SubscriptionStatusMessage::ACTIVE,
                'message' => SubscriptionStatusMessage::make(SubscriptionStatusMessage::ACTIVE),
            ];
        }

        if ($status['status'] === 'missing' && $available) {
            return response()->json([
                'message' => 'اشتراکی برای این سایت یافت نشد.',
                'available' => true,
            ], 404);
        }

        return response()->json([
            'data' => array_merge(['available' => $available], $status),
        ])->header('Cache-Control', 'public, max-age=60');
    }
}
