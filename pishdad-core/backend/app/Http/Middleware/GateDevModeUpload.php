<?php

namespace App\Http\Middleware;

use App\Services\Plugins\DevMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * دروازهٔ سمت سرور حالت توسعه‌دهنده برای مسیرهای آپلود و ارتقا (B3/K4.6).
 *
 * تا وقتی این middleware نبود، `dev_mode` فقط یک ایده در سند بود و
 * `upload()`/`upgrade()` هیچ بررسی سمت سروری نداشتند. حالا هر درخواستی که
 * بخواهد از امضای ناشر عبور کند باید از این دروازه رد شود.
 *
 * نکتهٔ مهم: این middleware درخواست‌های عادی را **نمی‌بندد**. مسیر عادی
 * (بستهٔ امضاشده) باید مثل قبل کار کند. چیزی که اینجا کنترل می‌شود ادعای
 * «دسترسی توسعه‌دهنده» است، نه آپلود به‌طور کلی.
 *
 * به همین دلیل رد شدن به این شکل است: اگر هدر توکن نیاید یا نادرست باشد،
 * درخواست **همان‌طور که پیش از این کار می‌شد** به کنترلر می‌رود و کنترلر
 * تصمیم می‌گیرد. اگر توکن درست باشد، درخواست اجازهٔ عبور از بررسی امضا را
 * می‌گیرد. پس دریافت توکن به‌خودی‌خود هیچ چیزی را باز نمی‌کند — باز کردن
 * بستهٔ بدون امضا کار `DevMode` در کنترلر است.
 */
class GateDevModeUpload
{
    public const HEADER = 'X-Pishdad-Dev-Token';

    public function __construct(private readonly DevMode $devMode) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header(self::HEADER);

        // توکن نامعتبر یا غایب ⇒ هیچ امتیازی اضافه نمی‌شود و منطق موجود
        // کنترلر (که فقط بستهٔ امضاشده را قبول می‌کند) دست‌نخورده می‌ماند.
        $request->attributes->set('dev_mode_granted', $this->devMode->allows(is_string($token) ? $token : null));

        return $next($request);
    }
}
