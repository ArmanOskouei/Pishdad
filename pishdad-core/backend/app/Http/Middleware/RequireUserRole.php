<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * نگهبانِ «این route فقط برای یک نقش».
 *
 * این middleware **خودش هیچ نقشی نمی‌داند**. نقش از مانیفست افزونه می‌آید و
 * `PluginRouteTable` آن را با `:role=` تزریق می‌کند — پس هر افزونه بدون
 * ویرایش هسته خودش را محافظت می‌کند.
 */
class RequireUserRole
{
    /**
     * نقش‌هایی که هرگز نباید از مسیرِ مانیفست تزریق شوند.
     *
     * fail-closed: یک مانیفجست می‌تواند alias دلخواه اعلام کند، ولی نمی‌تواند
     * بگوید «هر نقشی که داشتم قبول است» — چون آن‌وقت یک افزونه می‌توانست
     * نگهبان را دور بزند.
     */
    private const DENY = ['*', '', 'admin', 'user', 'guest'];

    public function handle(Request $request, Closure $next, string $role = ''): Response
    {
        $user = $request->user();

        $allowed = $user && ($user->role ?? null) === $role;

        if (! $allowed) {
            // پیام عمداً نامِ نقش یا افزونه را افشا نمی‌کند — فقط می‌گوید این
            // بخش از دسترسِ این کاربر خارج است.
            return response()->json([
                'message' => 'دسترسی مجاز نیست.',
            ], 403);
        }

        return $next($request);
    }

    /**
     * آیا این نقش برای تزریق در `:role=` معتبر است؟
     *
     * جدا از middleware است چون `PluginRouteTable` باید **هنگام ساخت جدولِ
     * route** تصمیم بگیرد، نه هنگام اجرای درخواست.
     */
    public static function isInjectableRole(string $role): bool
    {
        return $role !== ''
            && ! in_array(strtolower($role), self::DENY, true)
            && preg_match('/^[a-z0-9][a-z0-9_-]{1,39}$/i', $role) === 1;
    }
}
