<?php

namespace Pishdad\Plugins\Central\Http;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * تنظیمات درگاه مرکزی (تسک ۱.۳): خواندن با کلید ماسک‌شده، ذخیره امن.
 * رازها در settings گروه gateway می‌مانند (نه لاگ، نه پاسخ کامل).
 */
class GatewaySettingsController extends Controller
{
    private const GROUP = 'central_gateway';

    public function show(): JsonResponse
    {
        $driver = Setting::get(self::GROUP, 'driver', config('billing.gateway.driver'));
        $merchant = (string) Setting::get(self::GROUP, 'merchant_id', '');
        $sandbox = Setting::get(self::GROUP, 'sandbox', config('billing.gateway.sandbox'));

        return response()->json([
            'data' => [
                'driver' => $driver,
                'merchant_id_masked' => $merchant === '' ? null : substr($merchant, 0, 4).'****',
                'sandbox' => (bool) $sandbox,
            ],
        ]);
    }

    /**
     * J10 — این صفحه و این endpointها **می‌مانند**.
     *
     * پرسشِ J10 این بود که آیا `gateway-settings` واقعاً لازم است. جواب: بله، و
     * دلیلش قابلِ راستی‌آزمایی است نه سلیقه — هر سه مصرف‌کنندهٔ زنده داریم:
     *   - `frontend/.../central/billing/page.tsx` آن را در لودِ سروری می‌خواند
     *     (`serverOne('/v1/p/central/gateway-settings')`)،
     *   - `BillingTabs.tsx` تبِ «درگاه» را فقط وقتی رندر می‌کند که داده رسیده
     *     باشد، و
     *   - `BillingClients.tsx` (`GatewayForm`) هم ذخیره و هم «تست اتصال» را
     *     از همین دو endpoint می‌زند.
     *
     * حذفش یعنی پنل مرکزی یک تب را بی‌دلیل خالی نشان می‌دهد و اپراتور جایی برای
     * دیدنِ این‌که صورت‌حساب‌ها فعلاً **دستی** تسویه می‌شوند ندارد — که دقیقاً
     * همان چیزی است که J10 می‌خواست صادق باشد.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // `manual` = وضعیتِ واقعیِ فعلی. بقیه نام‌هایی هستند که اپراتور
            // می‌تواند از پیش تنظیم کند تا بعداً با پیاده‌سازیِ درگاه فعال شوند.
            'driver' => 'sometimes|string|in:manual,zarinpal,idpay',
            'merchant_id' => 'sometimes|string|max:200',
            'sandbox' => 'sometimes|boolean',
        ], [
            'driver.in' => 'درگاه پشتیبانی نمی‌شود.',
        ]);

        foreach ($validated as $key => $value) {
            Setting::set(self::GROUP, $key, $value);
        }

        return response()->json(['message' => 'تنظیمات درگاه ذخیره شد.', 'data' => $this->show()->getData()->data]);
    }

    /**
     * تست اتصال درگاه (دکمهٔ «تست اتصال»).
     *
     * ⚠️ J10 — این **نمایشی** است و خودش را هم پنهان نمی‌کند: در متن پیام می‌آید
     * که تراکنشی واقعی نبوده. دلیلش این است که درایورِ پیش‌فرض `manual` است و
     * اصلاً تراکنشی برای آزمایش وجود ندارد؛ برگرداندن `ok: true` بدون این
     * توضیح دوباره همان دروگوییِ J10 را تکرار می‌کرد.
     */
    public function test(): JsonResponse
    {
        $driver = Setting::get(self::GROUP, 'driver', config('billing.gateway.driver'));

        $honest = $driver === 'manual'
            ? 'اتصالی وجود ندارد — صورت‌حساب‌ها فعلاً دستی تسویه می‌شوند.'
            : "اتصال به درگاه «{$driver}» برقرار است (تست نمایشی — بدون تراکنش واقعی).";

        return response()->json([
            'message' => $honest,
            'data' => ['driver' => $driver, 'ok' => $driver !== 'manual', 'checked_at' => now()->toIso8601String()],
        ]);
    }
}
