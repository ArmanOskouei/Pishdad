<?php

namespace App\Http\Middleware;

use App\Services\Settings\SecuritySettings;
use App\Support\IpAllowlist;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WF-M7 — محدودکردن پنل به IPهای مجاز.
 *
 * فهرست خالی = همه مجازند (fail-open عمدی برای نصبِ تازه). وقتی فهرست پُر
 * است، نشانی‌های بیرونِ فهرست با ۴۰۳ رد می‌شوند. پیام عمداً همان پیام کوتاه
 * بدون جزئیات است تا محدودهٔ شبکه لو نرود.
 */
class EnsureAdminIpAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = SecuritySettings::allowedAdminIps();

        if ($allowed === [] || IpAllowlist::matches((string) $request->ip(), $allowed)) {
            return $next($request);
        }

        return response()->json(['message' => 'دسترسی از این نشانی شبکه مجاز نیست.'], 403);
    }
}
