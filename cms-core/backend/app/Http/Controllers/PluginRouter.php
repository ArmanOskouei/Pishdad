<?php

namespace App\Http\Controllers;

use App\Services\Plugins\PluginDispatcher;
use App\Services\Plugins\PluginRouteTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 *  - **500** برای استثنایی که از کد افزونه یا از guardهای `PluginDispatcher`
 *    برمی‌آید. جزئیات فقط در لاگ می‌رود.
 *
 * **405 هرگز.** متدِ نخواندن هم 404 است، چون نگفتنِ آن بهتر از گفتنِ آن است.
 *
 * **K7.11 — این‌جا ۵۰۱ نبود.** تا آن روز مسیر درست **۵۰۱** برمی‌گرداند چون
 * `PluginRouteTable` اعلان را می‌خواند ولی `PluginAutoloader` به هیچ چرخهٔ حیاتی
 * وصل نبود و `dispatch()` وجود نداشت. یعنی افزونه می‌توانست مسیر **اعلام** کند و
 * هیچ مسیری **اجرا** نمی‌شد — و K7.8/K7.9 (انتقال ۶۱ route مرکزی) روی زیرساختی
 * نوشته می‌شدند که هرگز اجرا نمی‌شد. اجرای واقعی حالا در
 * `PluginDispatcher::dispatch()` است و guardهای امنیتی‌اش همان‌جاست.
 *
 * بدنهٔ پاسخ ۴۰۴ قبلاً شامل هندلر/میدلورها/پارامترها بود تا مسیر قابل تشخیص
 * باشد؛ با اجرا شدن dispatch دیگر لازم نیست و **حذف شد** — چون دیگر پاسخی نیست
 * که بخواهد تشخیص را ممکن کند.
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

    public function __construct(
        private PluginRouteTable $routes,
        private PluginDispatcher $dispatcher,
    ) {}

    public function __invoke(Request $request, string $slug, ?string $any = null): mixed
    {
        $method = strtoupper($request->getMethod());
        $route = $this->routes->resolve($slug, (string) $any);

        if ($route === null || $route['method'] !== $method) {
            return response()->json(['message' => self::NOT_FOUND], 404);
        }

        // K7.11 — پیش از این، هر مسیر درست ۵۰۱ برمی‌گرداند چون
        // `PluginAutoloader` به هیچ چرخهٔ حیاتی وصل نبود.
        try {
            return $this->dispatcher->dispatch($request, $route);
        } catch (Throwable $e) {
            // جزئیات فقط در لاگ. یک استثنا می‌تواند connection string یا مسیر
            // فایل داشته باشد و مسیر فایل ساختار دیسک را لو می‌دهد.
            Log::error('plugin.dispatch_failed', [
                'slug' => $route['slug'],
                'path' => $route['path'],
                'method' => $route['method'],
                'handler' => $route['handler'],
                'exception' => $e::class,
                'reason' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'اجرای این مسیر افزونه ناموفق بود.',
            ], 500);
        }
    }
}
