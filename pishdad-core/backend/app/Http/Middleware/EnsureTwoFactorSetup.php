<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * S-11 — اجبارِ راه‌اندازی ۲FA برای نقش‌های ممتاز.
 *
 * ## مشکلِ قبلی
 *
 * `requires_2fa` روی نقش فقط یک **فلگِ UI** بود: `AuthController` آن را
 * به‌عنوان `requires_2fa_setup` در پاسخِ ورود برمی‌گرداند و کلاینت کاربر را
 * به پروفایل هدایت می‌کرد — ولی هیچ مانعی در مسیر نبود. کاربری با رمزِ
 * لو‌رفته و نقشی که ۲FA اجباری دارد، توکن کامل و تمام پnل را می‌گرفت.
 *
 * ## تصمیم (و Auditor امنیتی)
 *
 * میدل‌ور سخت با فهرستِ استثنای کوچک و صریح. چرا اینجا و نه در ورود:
 * میدل‌ور روی گروه `api` ثبت است ⇒ پوشش کامل `/api/*` از جمله مسیرهای
 * افزونه. چرا استثنا لازم است: کارتِ ۲FA در پروفایل برای نمایش به
 * `/v1/admin/profile` و `/2fa/methods` نیاز دارد؛ ۴۰۳ شدنشان یعنی کاربر
 * هیچ‌وقت راهِ درمان را نمی‌بیند و نصب قفل می‌شود.
 *
 * ⚠️ برعکسِ `$request->user()` از `Auth::guard('sanctum')->user()` استفاده
 * می‌شود: روی مسیرهای عمومی guard `web` برمی‌گردد و همیشه null است.
 */
class EnsureTwoFactorSetup
{
    /**
     * تنها مسیرهایی که برای یک کاربرِ «۲FA اجباری ولی فعال‌نکرده» باز می‌مانند.
     *
     * کلید = الگوی مسیر، مقدار = متدهای مجاز (`*` یعنی هر متد).
     *
     * `PUT profile` و `POST profile/password` و `POST profile/2fa/disable`
     * عمداً **نیستند**: تغییرِ ایمیل ۲FA را صفر می‌کند و غیرفعال‌کردنِ ۲FA
     * همان چیزی است که این میدل‌ور می‌خواهد ببندد.
     */
    private const ALLOWED = [
        'api/v1/admin/profile' => ['GET'],
        'api/v1/admin/profile/*' => ['GET'],
        'api/v1/admin/profile/2fa/enable' => ['POST'],
        'api/v1/admin/profile/2fa/recovery-codes' => ['GET', 'POST'],
        'api/v1/auth/logout' => ['POST'],
        'api/v1/auth/refresh' => ['POST'],
        'api/v1/ui-settings' => ['GET', 'PUT'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // کلید فقط env است، بدون کش: مسیرِ اضطراری باید وقتی پnل قفل است هم
        // کار کند (کشِ config همان چیزی است که ممکن است قفل شده باشد).
        if (config('security.two_factor_enforcement') !== true) {
            return $next($request);
        }

        $user = \Illuminate\Support\Facades\Auth::guard('sanctum')->user();
        if ($user === null) {
            // مهمان: هرگز ۴۰۳نبود؛ πόρتاب‌های عمومی و خودِ ورود از راه می‌روند.
            return $next($request);
        }

        if ($user->google2fa_enabled) {
            return $next($request);
        }

        // چراغِ قفلِ جراحی (pishdad:2fa:unlock): یک نفر معاف، سیاست برای بقیه.
        if ($user->two_factor_exempt) {
            return $next($request);
        }

        if (! $user->rolesRequireTwoFactor()) {
            // نقشِ عادی بدون فلگ: رفتارِ پیشین (سازگاریِ کامل).
            return $next($request);
        }

        $path = ltrim((string) $request->path(), '/');
        $method = $request->getMethod();

        foreach (self::ALLOWED as $pattern => $methods) {
            if ($methods !== ['*'] && ! in_array($method, $methods, true)) {
                continue;
            }
            if ($request->fullUrlIs($pattern) || $request->is($pattern)) {
                return $next($request);
            }
        }

        Log::warning('auth.2fa_setup_required_blocked', [
            'user_id' => $user->id,
            'path' => $path,
            'method' => $method,
        ]);

        return response()->json([
            'message' => 'برای نقش شما فعال‌سازی ورود دومرحله‌ای الزامی است. لطفاً از صفحهٔ پروفایل آن را فعال کنید.',
            'code' => 'two_factor_setup_required',
        ], 403);
    }
}
