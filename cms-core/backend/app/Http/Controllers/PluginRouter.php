<?php

namespace App\Http\Controllers;

use App\Services\Plugins\PluginRouteTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * K5.4 — catch-all مالکِ هسته برای routeهای افزونه.
 *
 * هسته **یک** route واقعی دارد و بقیه داخلش dispatch می‌شوند (بخش ۴ معماری):
 *
 *     Route::match(PluginRouteTable::METHODS, 'v1/p/{slug}/{any?}', PluginRouter::class)
 *         ->where('slug', '[^/]+')
 *         ->where('any', '.*')
 *         ->name(self::ROUTE_NAME)
 *         ->middleware('throttle:60,1');
 *
 * این خط در `routes/api.php` نوشته نشده: آن فایل در مالکیت این تسک نبود.
 * `->where('slug', '[^/]+')` عمداً سست است و قاعدهٔ واقعی slug داخل
 * `PluginRouteTable` است — کنترلر باید **خودش** مرز امنیتی باشد، نه اینکه به
 * تنظیم route تکیه کند؛ قاعدهٔ سفت‌تر یعنی تست نمی‌تواند مسیر بد را تا کنترلر
 * دنبال کند.
 *
 * **`throttle:plugin` معماری را دنبال نکرد** چون چنین limiterای در
 * `AppServiceProvider` ثبت نشده (`contact`، `site-search`، `auth-login` تنها سه
 * موردند) و ساختنش مالکیت این تسک نبود. عددی‌اش همان کار را می‌کند و حتماً
 * resolve می‌شود. اگر بعداً limiter مشترک ساخته شد، جایگزینی‌اش یک عدد است.
 *
 * **دو پاسخ، هر دو بسته:**
 *
 *  - **404** برای slug ناشناس/غیرفعال/ردشده، مسیر بدشکل (traversal، عمق زیاد،
 *    نویسهٔ خارج از الفبا)، متد نخواند، یا مسیر نخواند. یک پیام واحد و بی‌طرف:
 *    گفتن اینکه «افزونه هست ولی مسیر نه» یک طاقهٔ شمارش می‌سازد. به همین دلیل
 *    پیام یک ثابت است و نه دو رشتهٔ هم‌معنی.
 *  - **501** برای مسیری که resolve شد ولی هنوز قابل اجرا نیست.
 *
 * **405 هرگز.** متدِ نخواندن هم 404 است، چون نگفتنِ آن بهتر از گفتنِ آن است.
 *
 * **چرا 501 و نه اجرا.** `PluginRouteTable` عمداً کدی از افزونه اجرا نمی‌کند
 * و `PluginAutoloader` هنوز به هیچ‌جای چرخهٔ حیات وصل نیست. گفتن 501 دروغ نمی‌گوید
 * (هسته مسیر را می‌شناسد و قصد اجرا دارد) ولی وانمود هم نمی‌کند که اجرا شده —
 * برخلاف 200ِ خالی یا 404ای که پیامش «پلاگین خراب است» است و هیچ‌کس برای
 * تشخیص مسیرش ابزاری ندارد. تصمیم dispatch در بدنهٔ پاسخ برمی‌گردد تا روزی که
 * `dispatch()` اضافه شد دقیقاً همان چیزی باشد که مصرف می‌کند.
 *
 * بدنهٔ پاسخ عمداً شامل هندلر، میدلورها و پارامترهاست: این‌ها را فقط کسی
 * می‌بیند که همین حالا slug یک افزونهٔ **فعال** را می‌داند، پس طاقهٔ شمارش نمی‌سازد
 * و در عوض مسیر را قابل تشخیص می‌کند.
 */
class PluginRouter extends Controller
{
    /**
     * نام route، طبق جدول تداخل نام معماری: `plugin.` + یک نام ثابت، چون فقط
     * **یک** route وجود دارد و نامش از slug ساخته نمی‌شود.
     *
     * نام ثابت یعنی `route:cache` معتبر می‌ماند و هیچ‌کس نمی‌تواند با ثبت یک
     * نام تکراری سایت را بشکند.
     */
    public const ROUTE_NAME = 'plugin.dispatch';

    /**
     * تنها پیامِ رد. یک رشته، نه یکی به‌ازای هر دلیل: تفاوتِ متن میان «افزونه
     * نیست» و «مسیر نیست» خودش یک API شمارش است.
     */
    private const NOT_FOUND = 'این مسیر وجود ندارد.';

    public function __construct(private PluginRouteTable $routes) {}

    public function __invoke(Request $request, string $slug, ?string $any = null): JsonResponse
    {
        $method = strtoupper($request->getMethod());
        $route = $this->routes->resolve($slug, (string) $any);

        if ($route === null || $route['method'] !== $method) {
            return response()->json(['message' => self::NOT_FOUND], 404);
        }

        return response()->json([
            'message' => 'این route شناسایی شد اما هنوز اجرا نمی‌شود؛ اجرای کد افزونه در چرخهٔ حیات افزونه سیم‌کشی نشده است.',
            'dispatch' => [
                'plugin' => $route['slug'],
                'method' => $route['method'],
                'path' => $route['path'],
                'params' => $route['params'],
                'handler' => $route['handler'],
                'middleware' => $route['middleware'],
            ],
        ], 501);
    }
}
