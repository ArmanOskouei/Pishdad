<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * نگهبانِ «این route فقط برای یک نقش» — نسخهٔ عمومی.
 *
 * ## L-B12 — چرا این عمومی شد
 *
 * قبلاً این کلاس `EnsureCentralOperator` بود و دو چیز را در نام و بدنه لو
 * می‌داد که نباید: نامِ افزونهٔ خصوصی، و اینکه فقط یک نقشِ ثابت را می‌شناسد.
 * با اینکه فقط ۲۲ خط بود، در build عمومی نامِ «مرکز» را افشا می‌کرد.
 *
 * حالا middleware **خودش هیچ نقشی نمی‌داند**. نقش از مانیفست افزونه می‌آید و
 * `PluginRouteTable` آن را با `:role=` تزریق می‌کند — پس افزونهٔ بعدی بدون
 * ویرایش هسته خودش را محافظت می‌کند.
 *
 * ## چرا ساختاری، و نه یک شرط ساده
 *
 * `@deprecated alias` در مانیفست (`central.operator`) در `MIDDLEWARE_ALIASES`
 * نگاشت می‌شود. نگاشت باید یک **تعریف واحد** داشته باشد، وگرنه نقطهٔ تعریف و
 * نقطهٔ استفاده دو نسخه می‌شوند و یکی یواشکی تنظیم می‌شود. پس alias فقط یک
 * رشتهٔ نگاشت است و همهٔ منطق اینجاست.
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
                'message' => 'دسترسی مجاز نیست. این بخش فقط برای اپراتور است.',
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
