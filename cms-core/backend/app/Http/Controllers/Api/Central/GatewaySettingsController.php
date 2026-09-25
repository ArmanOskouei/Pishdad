<?php

namespace App\Http\Controllers\Api\Central;

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

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'driver' => 'sometimes|string|in:zarinpal-stub,zarinpal,idpay',
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
     * تست اتصال درگاه (دسته ۱ برابری UI/UX — دکمه «تست اتصال» دمو).
     * در Stub همیشه موفق نمایشی است (بدون تراکنش واقعی)؛ درایور واقعی TODO مرحله ۵.
     */
    public function test(): JsonResponse
    {
        $driver = Setting::get(self::GROUP, 'driver', config('billing.gateway.driver'));

        return response()->json([
            'message' => "اتصال به درگاه «{$driver}» موفق است (تست نمایشی — بدون تراکنش واقعی).",
            'data' => ['driver' => $driver, 'ok' => true, 'checked_at' => now()->toIso8601String()],
        ]);
    }
}
