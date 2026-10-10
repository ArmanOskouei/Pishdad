<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * تسک ۵.۱ — نگهبان دسترسی spatie برای پنل مشتری.
 * دسترسی فقط با پرمیشنِ صریحِ نقش؛ پیام فارسی بدون افشای جزئیات (OWASP).
 *
 * E87/S-05 — پلِ سازگاریِ نقشِ قدیمی.
 *
 * ستونِ `users.role` پیش از ورودِ spatie تنها منبعِ دسترسی بود و هنوز در
 * نصب‌های موجود (و در تست‌ها) پر می‌شود. بدون این پل، هر کاربری که ستونش
 * `owner`/`admin` است ولی نقشِ spatie ندارد روی مسیرهای perm‌دار 403 می‌گرفت.
 * نگاشت عیناً همان چیزی است که `RolesPermissionsSeeder` می‌سازد: owner و
 * admin هر دو «همهٔ پرمیشن‌ها» دارند — پس این پل هیچ دسترسیِ تازه‌ای باز
 * نمی‌کند، فقط 병عتِ بین دو مکانیسم را حذف می‌کند. نقش‌های گرانشی (editor/
 * viewer/سفارشی) همچنان فقط از spatie می‌آیند.
 */
class EnsurePermission
{
    /** نقش‌های ستونِ قدیمی که معادلِ «همهٔ پرمیشن‌ها»اند. */
    private const LEGACY_FULL_ACCESS = ['owner', 'admin'];

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        $legacyFull = $user !== null && in_array((string) $user->getAttribute('role'), self::LEGACY_FULL_ACCESS, true);

        if ($user && ($legacyFull || $user->can($permission))) {
            return $next($request);
        }

        return response()->json(['message' => 'دسترسی مجاز نیست.'], 403);
    }
}
