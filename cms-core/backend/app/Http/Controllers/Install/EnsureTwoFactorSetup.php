<?php

namespace App\Http\Controllers\Install;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * پیش‌تر: اجبارِ راه‌اندازی 2FA در اولین ورودِ سوپرادمینِ مخفی.
 *
 * با حذفِ کاملِ سوپرادمینِ مخفی، این نگهبان دیگر موضوعیتی ندارد و به یک
 * passthrough تبدیل شده است (کلاس و اتصالِ route دست‌نخورده می‌ماند تا ثبتِ
 * middleware نشکند). هر مدیرِ عادی می‌تواند از مسیرِ پروفایل 2FA را خودش
 * فعال کند.
 */
class EnsureTwoFactorSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
