<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * تسک ۵.۱ — نگهبان دسترسی spatie برای پنل مشتری.
 * دسترسی فقط با پرمیشنِ صریحِ نقش؛ هیچ نقشِ مستثنا/مخفی‌ای وجود ندارد.
 * پیام فارسی بدون افشای جزئیات (OWASP).
 *
 * E87 — چرا **پلِ سازگاریِ ستونِ `role`** اینجا نیست.
 *
 * ستونِ `users.role` پیش از ورودِ spatie تنها منبعِ دسترسی بود. یک نسخهٔ
 * میانیِ این کلاس مقدارهای `owner`/`admin` ستون را «دسترسیِ کامل» تلقی
 * می‌کرد تا تست‌های قدیمی و نصب‌های legacy کار کنند — ولی ۲۳ تستِ
 * موجودِ ماتریسِ دسترسی با آن قرمز شدند: آن تست‌ها عمداً کاربری با ستونِ
 * `admin` ولی پرمیشنِ محدودِ spatie می‌سازند و انتظارِ ۴۰۳ دارند. یعنی پل
 * کلِ ماتریسِ گرانشی (editor/viewer/سفارشی) را برای هر کاربری که ستونش
 * `admin` است بی‌اثر می‌کرد — از جمله مدیرانِ ساخته‌شده از پنل.
 *
 * تک‌منبعِ حقیقت: spatie. نصب‌های موجود باید نقشِ spatie خودشان را دارند
 * (سیدر و نصب‌کننده هر دوsyncRoles/assignRole می‌زنند)؛ اگر نصبِ قدیمی
 * بدونِ نقش مانده، راهِ درست یک مهاجرتِ یک‌بار مصرف است، نه سوراخِ دائم
 * در میدل‌ور.
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
