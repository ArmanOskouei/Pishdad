<?php

namespace App\Http\Controllers;

use App\Services\Plugins\PluginDispatcher;
use App\Services\Plugins\PluginRouteTable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException as SymfonyNotFoundHttpException;
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

    /**
     * پیام ۴۰۱ افزونه.
     *
     * همان متنی که میان‌افزارِ `Authenticate` هسته برمی‌گرداند. یک رشتهٔ ثابت
     * است چون پاسخِ افزونه نباید فرقی با هسته داشته باشد — وگرنه یک رابط
     * می‌سازیم که از روی متن پاسخ می‌شود تشخیص داد مسیر از افزونه آمده.
     */
    private const UNAUTHENTICATED = 'Unauthenticated.';

    /**
     * `central.operator` → کلاس واقعیِ میان‌افزار.
     *
     * رشتهٔ مانیفست **alias** است نه کلاس. `Container::make('central.operator')`
     * خطای `Target class [central.operator] does not exist` می‌دهد.
     *
     * نگاشت از رجیستررِ `Illuminate\Routing\Router` خوانده می‌شود، نه از یک
     * آرایهٔ محلی — وگرنه دو تعریفِ alias داشتیم و تغییر یکی جای دیگر را
     * بی‌سروصدا خراب می‌کرد.
     *
     * اگر alias در رجیسترر نبود، **کلاس** فرض می‌شود — چون مانیفستِ یک بستهٔ
     * بازاری ممکن است نام کلاس بدهد و `compileMiddleware` اجازه‌اش می‌دهد.
     * هر دو حالت به همان `Container::make` می‌رسند، پس تفاوتی در مسیر ندارد.
     */
    private function resolvePipe(string $name): object
    {
        $router = $this->container->make(Router::class);

        $resolved = method_exists($router, 'getMiddleware')
            ? ($router->getMiddleware()[$name] ?? null)
            : null;

        return $this->container->make(is_string($resolved) ? $resolved : $name);
    }

    public function __construct(
        private PluginRouteTable $routes,
        private PluginDispatcher $dispatcher,
        private Container $container,
    ) {}

    public function __invoke(Request $request, string $slug, ?string $any = null): mixed
    {
        $method = strtoupper($request->getMethod());

        // K7.8 — متد به `resolve()` داده می‌شود. پیش از این `resolve()` اولین
        // regex هم‌خوان را برمی‌گرداند و مقایسهٔ متد **بعد** انجام می‌شد، پس هر
        // مسیری که هم `GET` و هم `POST` داشت فقط به متدِ اول جواب می‌داد:
        // `POST /clients` ۴۰۴ می‌داد در حالی که `GET /clients` کار می‌کرد.
        //
        // این باگ تا K7.8 پنهان بود چون تنها بستهٔ آزمایشیِ افزونه (K7.13) روی
        // هر مسیر **یک** متد داشت. اولین بستهٔ واقعی، اولین افزونهٔ کاملِ
        // REST-شکل، آن را لو داد.
        $route = $this->routes->resolve($slug, (string) $any, $method);

        if ($route === null) {
            return response()->json(['message' => self::NOT_FOUND], 404);
        }

        // K5.4 — میان‌افزارهای route **اجرا** می‌شوند.
        //
        // ⚠️ این تا K7.8 اجرا **نمی‌شدند**. `PluginRouteTable::compileMiddleware()`
        // رشته‌ها را اعتبارسنجی و در جدول ذخیره می‌کرد، ولی هیچ‌کس آن‌ها را به
        // pipeline نمی‌داد. یعنی هر افزونه‌ای که `auth:sanctum` اعلام می‌کرد
        // **بدون احراز هویت** سرو می‌شد.
        //
        // چرا پنهان بود: تنها افزونهٔ آزمایشیِ آن زمان (K7.13) هیچ middleware
        // اعلام نمی‌کرد و خودش دستی `$request->user()` را چک می‌کرد. اولین
        // افزونهٔ واقعی که `auth:sanctum` + `central.operator` اعلام کرد
        // (`test_non_operator_is_forbidden`: انتظار ۴۰۳، دریافت ۲۰۰) آن را لو داد.
        //
        // این یعنی یک افزونهٔ بازاری می‌توانست با اعلام `auth:sanctum` فکر کند
        // خودش را محافظت کرده، و در واقع بی‌احراز هویت سرو می‌شد.
        //
        // ⚠️ **guard و middleware دو چیز متفاوت‌اند.**
        //
        // `auth:sanctum` یک **guard** است: یعنی «احراز هویت کاربر را با این
        // driver بررسی کن و اگر نشد ۴۰۱ بده». در لاراول guard یک **middleware
        // نیست** و نمی‌شود در `through()` گذاشت — تلاش اول همین کار را کرد و
        // `Error: Object of type Illuminate\Auth\AuthManager is not callable`
        // داد، یعنی هر مسیرِ دارای middleware به ۵۰۰ می‌افتاد.
        //
        // پس به ترتیب درست:
        // ۱. `auth:*` → بررسیِ guard، و ساختِ پاسخِ خودمان.
        //
        // ⚠️ **`Guard::authenticate()` پاسخ نمی‌دهد — کاربر را برمی‌گرداند.**
        //
        // امضایش `?Authenticatable` است. تلاش اول آن را مثل `Authenticate`ِ
        // لاراول فرض کرد و `if ($failed) return $failed;` نوشت. نتیجه: هر
        // مسیرِ دارای `auth:sanctum` **بدنهٔ مدلِ کاربرِ احراز‌هویت‌شده** را
        // به‌جای پاسخ خودش برمی‌گرداند — JSON کاربر با ۲۰۰.
        //
        // این فقط یک تستِ قرمز نبود: یک **نشت داده** بود، روی هر مسیرِ
        // احراز‌هویت‌شده، برای هر افزونه‌ای که `auth:*` اعلام می‌کرد.
        //
        // پس اینجا خودمان پاسخ ۴۰۱ می‌سازیم. متن از `Authenticate` هسته
        // گرفته شده تا رفتار یکسان بماند.
        foreach ($route['middleware'] as $entry) {
            if (! str_starts_with($entry, 'auth:')) {
                continue;
            }

            $guard = substr($entry, strlen('auth:'));

            if ($this->container->make(AuthFactory::class)->guard($guard)->guest()) {
                return response()->json(['message' => self::UNAUTHENTICATED], 401);
            }
        }

        //
        // ⚠️ **`Pipeline` اینجا غلط است و تلاش اول همین اشتباه را کرد.**
        //
        // `Illuminate\Pipeline\Pipeline` مخصوصِ زنجیره‌ای است که هر میان‌افزار
        // `$next($request)` می‌گیرد و **Request** را برمی‌گرداند. ولی
        // `EnsureCentralOperator::handle` نوعِ بازگشتی‌اش `Response` است و
        // `return $next($request)` می‌کند.
        //
        // نتیجه: `Pipeline` مقدارِ `$request` را با آن Response جایگزین کرد و
        // گام بعدی — یعنی `dispatch()` — به‌جای درخواست، **کاربر
        // احراز‌هویت‌شده** را گرفت. پاسخ ۲۰۰ بود با body که JSON کاربر بود.
        //
        // این یعنی هر مسیرِ دارای middleware بدنهٔ مدلِ کاربر را به‌جای پاسخِ
        // خودش برمی‌گرداند — یک نشت داده، نه فقط یک تست قرمز.
        //
        // راه درست: زنجیره را **دستی** می‌سازیم و در هر گام Request را از
        // Response جدا می‌کنیم. اگر چیزی غیر از Request برگشت، میان‌افزار
        // دارد پاسخ می‌دهد (مثل ۴۰۳) و باید همان برگردد.
        //
        // ⚠️ `Container::make('central.operator')` هم کار نمی‌کند: رشتهٔ
        // مانیفست **alias** است نه کلاس، و resolve‌اش
        // `Target class [central.operator] does not exist` می‌دهد. نگاشت
        // alias→کلاس از خودِ `Router` خوانده می‌شود تا تعریفِ دومی نسازیم.
        // ۲. بقیهٔ میان‌افزارها، به‌ترتیبِ اعلان.
        //
        // زنجیره **دستی** ساخته می‌شود نه با `Illuminate\Pipeline\Pipeline`:
        // آن مخصوصِ زنجیره‌ای است که هر میان‌افزار `$next($request)` می‌گیرد و
        // **Request** برمی‌گرداند، ولی `EnsureCentralOperator::handle` نوعِ
        // بازگشتی‌اش `Response` است و `return $next($request)` می‌کند.
        //
        // با `Pipeline` مقدارِ `$request` با آن Response جایگزین می‌شد و گام
        // بعدی به‌جای درخواست، پاسخ می‌گرفت — یعنی `dispatch()` روی یک
        // `JsonResponse` اجرا می‌شد.
        $pipes = array_values(array_filter(
            $route['middleware'],
            static fn (string $entry): bool => ! str_starts_with($entry, 'auth:'),
        ));

        foreach ($pipes as $pipe) {
            $result = $this->resolvePipe($pipe)->handle(
                $request,
                fn (Request $passed) => $this->dispatcher->dispatch($passed, $route),
            );

            if (! $result instanceof Request) {
                return $result;
            }

            $request = $result;
        }

        // K7.11 — پیش از این، هر مسیر درست ۵۰۱ برمی‌گرداند چون
        // `PluginAutoloader` به هیچ چرخهٔ حیاتی وصل نبود.
        try {
            return $this->dispatcher->dispatch($request, $route);
        } catch (ValidationException $e) {
            // K7.13 — استثناهای «پاسخ‌دادنی» هسته باید برسند، نه لاگ و نه ۵۰۰.
            //
            // `ValidationException` یعنی هندلر `validate()` را صدا زد و شکست
            // خورد. این یعنی **ورودی بد بود، نه افزونهٔ خراب** — و پاسخ درست
            // ۴۲۲ با فهرست خطاهاست. وقتی این را نمی‌گرفتیم، کاربر برای یک
            // فیلد خالی ۵۰۰ می‌دید و نویسندهٔ افزونه هیچ پیامی برای اصلاح
            // نداشت.
            //
            // `HttpException` هم همین منطق را دارد: `abort(403)` در هندلر باید
            // ۴۰۳ بدهد نه ۵۰۰.
            throw $e;
        } catch (HttpExceptionInterface|SymfonyNotFoundHttpException $e) {
            throw $e;
        } catch (ModelNotFoundException $e) {
            // K7.8 — route model binding.
            //
            // هندلری که `Client $client` اعلام می‌کند، اگر رکوردی نباشد
            // `findOrFail` یک `ModelNotFoundException` می‌دهد. این **پاسخ‌دادنی**
            // است، نه خرابی افزونه — دقیقاً مثل `abort(404)`.
            //
            // بدون این شاخه، یک مشتریِ حذف‌شده یک ۵۰۰ عمومی می‌داد و پنل مرکزی
            // هر بار که روی لینکِ قدیمی کلیک می‌شد خطا نشان می‌داد. ضمناً جزئیات
            // مدل (`getMessage()`) شامل **نام جدول** است، پس پیام فارسی و کلی
            // می‌رود.
            throw new SymfonyNotFoundHttpException;
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
