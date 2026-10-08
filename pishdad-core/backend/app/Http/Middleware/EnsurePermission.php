<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * تسک ۵.۱ — نگهبان دسترسی spatie برای پنل مشتری.
 * دسترسی فقط با پرمیشنِ صریحِ نقش؛ هیچ نقشِ مستثنا/مخفی‌ای وجود ندارد.
 * پیام فارسی بدون افشای جزئیات (OWASP).
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if ($user && $user->can($permission)) {
            return $next($request);
        }

        return response()->json(['message' => 'دسترسی مجاز نیست.'], 403);
    }
}
