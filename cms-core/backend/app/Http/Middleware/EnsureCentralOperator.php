<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * نگهبان پنل مرکزی (تسک‌های ۱.۳ / ۱.۴): فقط role=operator یا سوپرادمین مخفی.
 * مشتری (role=admin) حتی با توکن معتبر 403 می‌گیرد — پیام فارسی، بدون افشا.
 */
class EnsureCentralOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $allowed = $user
            && ($user->is_super_admin || ($user->role ?? null) === 'operator');

        if (! $allowed) {
            return response()->json(['message' => 'دسترسی مجاز نیست. این بخش فقط برای اپراتور مرکزی است.'], 403);
        }

        return $next($request);
    }
}
