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
        return response()->json([
            'data' => [
                'items' => ManifestRegistry::menuItems(),

                // ⭐ فهرستِ افزونه‌های **فعال**.
                //
                // لازم است چون منوی هسته آیتم‌هایی دارد که *متعلق به* افزونه‌اند
                // (مثل «اشتراک من» که صفحه‌اش را خودِ افزونه سرو می‌کند). گزارشِ
                // کاربر دقیقاً همین بود: بعد از غیرفعال‌کردنِ افزونهٔ اشتراک،
                // آیتمش در منو ماند و به مسیری لینک می‌کرد که وجود نداشت.
                //
                // این لیست از همان کشِ `activeManifests()` خوانده می‌شود، پس
                // هم‌قدم با رجیستری است و هزینهٔ اضافه ندارد. فرانت با این
                // فهرست آیتم‌هایِ افزونه‌ایِ منوی هسته را فیلتر می‌کند و اگر
                // نتوانست آن را بگیرد، همه را پنهان می‌کند (fail-closed).
                'active_slugs' => array_keys(ManifestRegistry::activeManifests()),
            ],
        ]);
    }

    /**
     * K6.5 — رجیستری صفحه‌های اختصاصی افزونه‌ها.
     *
     * فرانت این فهرست را می‌گیرد و catch-all زیر `/admin/` با آن تصمیم می‌گیرد که
     * مسیر متعلق به کدام افزونه است. بدون این مسیر، `/admin/xyz` به رندرکنندهٔ
     * **سایت عمومی** می‌افتاد و با کروم سایت رندر می‌شد.
     *
     * مجوزی ندارد، همان دلیل `index()`: catch-all باید بداند مسیر وجود دارد تا
     * صفحهٔ «دسترسی ندارید» بدهد، نه صفحهٔ «پیدا نشد».
     */
    public function pages(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'pages' => ManifestRegistry::pageRegistry(),
            ],
        ]);
    }

    /**
     * K6.2 — ابزارهای افزونه برای drawer هدر.
     *
     * فقط **فهرست** ابزارها برمی‌گردد، نه `href`. چرا: هسته خودش
     * `notification_id` را به مسیر تبدیل می‌کند و آن مسیر باید در
     * `pageRegistry()` ثبت شده باشد. اگر تبدیل اینجا انجام می‌شد، یک افزونه
     * می‌توانست با یک عدد دلخواه به هر مسیری لینک بدهد — دقیقاً همان چیزی که
     * قرارداد با `no_href` می‌بندد.
     *
     * مجوز ندارد (همان دلیل `index()`): فیلتر نهایی در فرانت و در خود سرور هنگام
     * اجرای route افزونه است.
     */
    public function tools(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'tools' => ManifestRegistry::pluginTools(),
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
                'can_view_plugins' => Gate::allows('plugins.view'),
            ],
        ]);
    }
}
