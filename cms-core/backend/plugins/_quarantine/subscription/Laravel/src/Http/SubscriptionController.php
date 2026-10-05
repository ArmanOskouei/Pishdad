<?php

namespace Pishdad\Plugins\Subscription\Http;

use App\Http\Controllers\Controller;
use App\Services\Billing\PaymentGatewayInterface;
use App\Services\Subscription\SubscriptionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «اشتراک من» — سمتِ نصبِ مشتری.
 *
 * ## چرا این کنترلر در افزونهٔ خودش است و نه در core (L-B12)
 *
 * کاربر خواسته بود «اشتراک من» یک افزونهٔ **کاملاً جدا** از پنل مرکزی باشد و
 * هیچ‌کدام عمومی منتشر نشوند. این کنترلر قبلاً در
 * `app/Http/Controllers/Api/V1/Admin/SubscriptionController.php` بود — یعنی در
 * هستهٔ منتشرشده.
 *
 * ## نکتهٔ معماری: داده کجاست؟
 *
 * از تصمیم L-B12 داده‌های اشتراک (`subscription_plans` /
 * `subscription_subscriptions` / `subscription_invoices`) **متعلق به همین
 * افزونه** هستند و migration خودشان را دارد. هیچ ارجاعی به جدول یا مدلِ
 * `central_*` باقی نمانده — پس این افزونه بدون نصبِ مرکز هم کامل کار می‌کند.
 *
 * کنترلر فقط از `SubscriptionResolver` (یک قراردادِ core) استفاده می‌کند، پس
 * هستهٔ منتشرشده هیچ نامِ کلاسِ خصوصی نمی‌داند.
 */
class SubscriptionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        // ⭐ خودساخت: اولین بازدید نباید ۴۰۴ بدهد. رکوردِ فعالِ پیش‌فرض ساخته
        // می‌شود تا کاربر بلافاصله وضعیتش را ببیند (و بعداً بتواند پلن عوض کند).
        SubscriptionResolver::ensureFor($request->user());

        $view = SubscriptionResolver::forUser($request->user());

        return response()->json(['data' => $view]);
    }

    public function invoices(Request $request): JsonResponse
    {
        return response()->json(
            SubscriptionResolver::invoicesFor(
                $request->user(),
                (int) $request->query('per_page', 15),
            ),
        );
    }

    /** فهرست پلن‌های فعال برای مقایسه و ارتقا. */
    public function plans(): JsonResponse
    {
        return response()->json(['data' => SubscriptionResolver::activePlans()]);
    }

    /**
     * ارتقای پلن — مابه‌التفاوت به‌صورت فاکتور.
     *
     * منطقِ قیمت‌گذاری در resolver است، نه اینجا؛ اینجا فقط اعتبارسنجیِ ورودی و
     * بسته‌بندیِ پاسخ انجام می‌شود.
     */
    public function changePlan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer'],
        ], [
            'plan_id.required' => 'پلن مقصد الزامی است.',
            'plan_id.integer' => 'شناسهٔ پلن باید عدد باشد.',
        ]);

        $result = SubscriptionResolver::changePlan(
            $request->user(),
            (int) $validated['plan_id'],
        );

        // ⭐ `$result['body']` و نه خودِ `$result`.
        //
        // resolver یک **قراردادِ داخلی** برمی‌گرداند: `['ok' => …, 'body' => […]]`،
        // چون `pay` به `status` عددی هم نیاز دارد. ولی پاسخِ HTTP باید شکلِ
        // REST داشته باشد: `{"message": …, "data": {…}}`. اگر خودِ `$result`
        // برگردد، کلاینت باید `body.data.amount` بخواند — و UI و تست هر دو
        // `data.amount` می‌خواهند. `pay` هم از اول همین‌طور باز می‌کرد؛ اینجا
        // ناهماهنگ بود.
        return response()->json($result['body'], $result['ok'] ? 201 : 422);
    }

    /**
     * شروع پرداخت فاکتور خودی → session درگاه.
     *
     * ⚠️ درگاه از `app()` می‌آید، نه از پارامترِ متد.
     *
     * دلیل: `PluginDispatcher` فقط `Request` و پارامترهای مسیر (`{invoice}`)
     * را inject می‌کند. اگر `PaymentGatewayInterface` پارامتر باشد، `null`
     * می‌رسد و `TypeError` می‌دهد. ضمناً این همان مسیری است که مرکز استفاده
     * می‌کند — یعنی **نامِ درایور** انتخاب را هسته انجام می‌دهد و افزونه
     * نمی‌تواند درگاه دلخواهش را تحمیل کند.
     */
    public function pay(Request $request, string $invoice): JsonResponse
    {
        $result = SubscriptionResolver::startPayment(
            $request->user(),
            (int) $invoice,
            app(PaymentGatewayInterface::class),
        );

        return response()->json($result['body'], $result['status']);
    }

    /**
     * وضعیت عمومی — بدون احراز هویت.
     *
     * ⚠️ عمداً **بدون** احراز هویت است: سایت عمومی باید بتواند وضعیتش را بخواند.
     * ولی فقط یک پاسخ `status|message` برمی‌گرداند، نه دادهٔ مشتری.
     */
    public function status(Request $request): JsonResponse
    {
        $siteId = $request->query('site_id');

        return response()->json([
            'data' => SubscriptionResolver::publicStatus(
                $siteId !== null ? (string) $siteId : null,
            ),
        ])->header('Cache-Control', 'public, max-age=60');
    }
}
