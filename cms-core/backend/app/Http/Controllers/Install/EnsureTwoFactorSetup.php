<?php

namespace App\Http\Controllers\Install;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * F1.1.F — اجبار راه‌اندازی 2FA در اولین ورود سوپرادمین.
 *
 * نصب‌کننده سوپرادمین را با `google2fa_enabled=false` می‌سازد (سکرت هرگز به
 * stdout چاپ نمی‌شود، برخلاف SuperAdminSeeder). توکنِ لاگینِ چنین کاربری فقط
 * برای همین مسیرها کار می‌کند؛ بقیه API با ۴۰۳ + پرچم برمی‌گردد:
 * - GET  api/v1/admin/profile/2fa/methods
 * - POST api/v1/admin/profile/2fa/enable
 * - POST api/v1/auth/logout
 *
 * در محیط تست پیش‌فرض خاموش است (تست‌های موجود سوپرادمین بدون 2FA می‌سازند).
 */
class EnsureTwoFactorSetup
{
    /** @var list<string> */
    public const ALLOWED_PATHS = [
        'api/v1/admin/profile/2fa/methods',
        'api/v1/admin/profile/2fa/enable',
        'api/v1/auth/logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $enabled = config('installer.enforce_2fa_setup');
        if ($enabled === null) {
            $enabled = ! app()->runningUnitTests();
        }
        if (! $enabled) {
            return $next($request);
        }

        // عمداً گارد sanctum را صریح می‌خوانیم: این middleware در گروه api
        // قبل از auth:sanctum (سطح route) اجرا می‌شود.
        $user = $request->user('sanctum');

        if (
            $user
            && (bool) ($user->is_super_admin ?? false)
            && ! (bool) ($user->google2fa_enabled ?? false)
            && ! in_array(trim($request->path(), '/'), self::ALLOWED_PATHS, true)
        ) {
            return response()->json([
                'message' => 'اول تأیید دومرحله‌ای را فعال کنید.',
                'two_factor_setup_required' => true,
            ], 403);
        }

        return $next($request);
    }
}
