<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Plugins\ManifestRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * K6.1 — آیتم‌های منوی افزونه‌ها برای منوی کناری پنل.
 *
 * ## چرا این مسیر جدا است و به `/v1/admin/plugins` وصل نیست
 *
 * سه دلیل، هر کدام به‌تنهایی کافی:
 *
 *  ۱) `/v1/admin/plugins` پشت `perm:plugins.view` است. اگر منو از آن خوانده
 *     می‌شد، مدیری که این پرمیشن را ندارد ۴۰۳ می‌گرفت و **منویش بی‌صدا
 *     خالی می‌شد** — در حالی که بقیهٔ منو و صفحه‌ها سالم بودند.
 *  ۲) آن مسیر `paginate(20)` و `latest()` دارد، پس بالای ۲۰ افزونه اینکه کدام
 *     آیتم منو اصلاً دیده شود به **تاریخ نصب** بستگی می‌داشت، نه به داده.
 *  ۳) `present()` کل `manifest` را می‌فرستاد — امضا، کلید عمومی و جدول‌های
 *     اعلام‌شدهٔ هر افزونه به مرورگر *هر* مدیری می‌رفت.
 *
 * اینجا فقط هفت فیلد لازم برای یک آیتم منو بیرون می‌رود و هیچ چیز دربارهٔ
 * افزونه‌هایی که آیتمی اعلام نکرده‌اند لو نمی‌رود.
 *
 * ## چرا مجوزی روی خودِ مسیر نیست
 *
 * مسیر فقط `auth:sanctum` دارد و عمداً `perm:*` ندارد: آیتم‌های منو باید برای
 * **همهٔ** مدیران برگردند تا فرانت بتواند فیلترشان کند. فیلتر واقعی در فرانت
 * `mergePluginMenu` است (fail-closed) و **دوباره** در خود سرور هنگام اجرای
 * route افزونه اعمال می‌شود. اگر اینجا هم `perm:plugins.view` می‌گذاشتیم،
 * منوی هر کسی که آن پرمیشن را ندارد خالی می‌شد و دروازهٔ فرانت هرگز
 * امتحان نمی‌شد.
 */
class PluginMenuController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // چیزی که فرانت لازم دارد، و نه بیشتر: فهرست آیتم‌ها + پرمیشن‌های
        // خودِ همین کاربر تا دروازهٔ سمت کلاینت بتواند تصمیم بگیرد.
        //
        // پرمیشن‌ها دوباره اینجا محاسبه نمی‌شوند چون `/v1/admin/profile` همین
        // را می‌دهد و `admin/layout.tsx` هر دو را موازی می‌خواند — محاسبهٔ
        // دوباره یعنی دو نقطهٔ حقیقت که با هم می‌روند.
        return response()->json([
            'data' => [
                'items' => ManifestRegistry::menuItems(),
            ],
        ]);
    }

    /**
     * فقط برای خوانایی مسیرها: superadmin چه می‌بیند.
     *
     * این متد دروازهٔ جدیدی معرفی نمی‌کند — `Gate::allows` همان چیزی است که
     * `EnsurePermission` صدا می‌زند، پس اگر اینجا رد شود آنجا هم رد می‌شود.
     * تنها فایده‌اش این است که فرانت بتواند بدون حدس، وضعیت superadmin را
     * از یک منبع بگیرد.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'is_super_admin' => (bool) $user->is_super_admin,
                'can_view_plugins' => Gate::allows('plugins.view') || (bool) $user->is_super_admin,
            ],
        ]);
    }
}
