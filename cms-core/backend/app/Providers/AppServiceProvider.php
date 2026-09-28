<?php

namespace App\Providers;

use App\Services\Billing\PaymentGatewayInterface;
use App\Services\Billing\ZarinpalStubDriver;
use App\Services\Chabokan\ChabokanClientInterface;
use App\Services\Chabokan\StubChabokanDriver;
// بدون این `use`، PHP این نام را داخل `App\Providers` حل می‌کرد و کل اپ
// با BindingResolutionException بالا نمی‌آمد (کلاس در `App\Services\Plugins` است).
use App\Services\Plugins\PluginAutoloader;
use App\Services\Plugins\PluginReleaseManager;
use App\Services\Plugins\ServiceProviderRegistry;
use App\Services\RevalidateSigner;
use App\Validation\NoMarkup;
use App\Validation\SafeUrl;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            RevalidateSigner::class,
            fn () => new RevalidateSigner(
                (string) config('revalidate.secret'),
                (int) config('revalidate.leeway', 300),
            )
        );

        // K5.0 — این سه binding دیگر مستقیم به درایور وصل نمی‌شوند. رجیستری
        // نقطهٔ `core.service_provider` است: اگر افزونهٔ فعال و تأییدشده‌ای
        // پیاده‌سازی معتبری اعلام کرده باشد، همان بارگذاری می‌شود؛ وگرنه
        // درایور پیش‌فرض هسته. یعنی هسته دیگر نام درایور را انتخاب نمی‌کند و
        // افزونه می‌تواند بدون دست‌زدن به هسته تعویضش کند.
        $registry = $this->app->make(ServiceProviderRegistry::class);

        // درگاه پرداخت مرکزی: در MVP درایور Stub زرین‌پال (بدون شارژ واقعی).
        $registry->bind(
            PaymentGatewayInterface::class,
            fn () => new ZarinpalStubDriver
        );

        // چابکان مرکزی (تسک ۳.۳): در MVP درایور Stub (خواندن از کانفیگ).
        // TODO مرحله ۵: ApiChabokanDriver (اتصال واقعی با CHABOKAN_API_TOKEN).
        $registry->bind(
            ChabokanClientInterface::class,
            fn () => new StubChabokanDriver
        );
    }

    public function boot(): void
    {
        $this->bootBundledPlugins();

        // F0.1 / F0.2 — قاعده‌های اعتبارسنجی سراسری.
        //
        // اینها «پیش‌فرض امن» را در لحظهٔ نوشتن می‌سازند، نه در لحظهٔ رندر. دلیل:
        // هر رندرر تازه‌ای که اضافه شود (بلوک پلاگینی، ویجت، ایمیل) به همان
        // فیلد می‌رسد؛ اگر فقط فرانت پاک‌سازی کند، آن رندرر یا باید sanitize
        // را به یاد بیاورد یا سوراخ است — و این در بازبینی قابل تشخیص نیست.
        //
        // ⚠️ `extend` یک `bool` می‌خواهد. برگرداندن یک شیء `ValidationRule`
        // یعنی truthy ⇒ **همه‌چیز رد می‌شود** و قاعده بی‌اثر ولی ظاهراً فعال
        // می‌ماند. برای همین منطق در متدهای استاتیک است و closure فقط صدایش
        // می‌زند.
        Validator::extend(
            'no_markup',
            fn (string $attribute, mixed $value): bool => NoMarkup::passes($value)
        );
        Validator::extend(
            'safe_href',
            fn (string $attribute, mixed $value): bool => SafeUrl::passes($value, 'href')
        );
        Validator::extend(
            'safe_src',
            fn (string $attribute, mixed $value): bool => SafeUrl::passes($value, 'src')
        );
        Validator::replacer('no_markup', fn (string $message, string $attribute) => 'این مقدار نباید شامل علامت «<» باشد.');
        Validator::replacer('safe_href', fn (string $message, string $attribute) => SafeUrl::message('href'));
        Validator::replacer('safe_src', fn (string $message, string $attribute) => SafeUrl::message('src'));

        // API-only: password-reset links go to the Next.js page, not a Blade route.
        // (Default ResetPassword notification needs a `password.reset` named route.)
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $base = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');

            return "{$base}/reset-password?token={$token}&email=".urlencode($user->getEmailForPasswordReset());
        });

        // فرم تماس عمومی (تسک ۵.۳): سفت — ۵ تلاش در دقیقه برای هر IP.
        RateLimiter::for('contact', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->ip())
                ->response(function () {
                    return response()->json(
                        ['message' => 'درخواست‌های شما بیش از حد مجاز است. لطفاً دقایقی دیگر تلاش کنید.'],
                        429
                    );
                });
        });
        // جستجوی عمومی سایت: سفت — ۳۰ درخواست در دقیقه برای هر IP.
        RateLimiter::for('site-search', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->ip())
                ->response(function () {
                    return response()->json(
                        ['message' => 'درخواست‌های شما بیش از حد مجاز است. لطفاً دقایقی دیگر تلاش کنید.'],
                        429
                    );
                });
        });
        // Brute-force lock for auth: 10 attempts / 15 min per (email + ip),
        // identical Persian 429 message (no information leak).
        RateLimiter::for('auth-login', function (Request $request) {
            return Limit::perMinutes(15, 10)
                ->by($request->input('email', '').'|'.$request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'تلاش‌های ورود بیش از حد مجاز است. لطفاً دقایقی دیگر تلاش کنید.',
                    ], 429);
                });
        });
    }

    /**
     * K7.8 — ثبت PSR-4 افزونه‌های داخلی، در هر درخواست.
     *
     * ## چرا اینجا و نه فقط کنار dispatcher
     *
     * `PluginDispatcher` بارگذار را **تنبل** صدا می‌زند: فقط وقتی خواسته‌ای به
     * مسیر افزونه می‌رسد. ولی پس از انتقال مرکز، کدِ هسته هم مدل‌های مرکزی را
     * صدا می‌زند — `PaymentWebhookController` روی جدول فاکتور می‌نویسد،
     * `HeartbeatController` به جدول مشتریان می‌خواند، و
     * `SubscriptionStatusController` به جدول اشتراک‌ها.
     *
     * آن مسیرها هرگز از `PluginDispatcher` رد نمی‌شوند، پس namespace افزونه
     * هنوز ثبت نشده بود و کلاس پیدا نمی‌شد. نتیجه یک `Error: Class not found`
     * در **heartbeat نصب‌ها** و **callback درگاه** بود — یعنی دقیقاً دو جایی که
     * نباید بشکنند.
     *
     * ثبت زودهنگام این مشکل را حل می‌کند و برای افزونهٔ داخلی **درست** است: این
     * بسته با `git pull` می‌آید، قابل غیرفعال‌سازی نیست، و هرگز در جدول
     * `system_plugins` هم نمی‌نشیند چون اصلاً نصب نمی‌شود.
     *
     * ## چرا فقط `source` و نه بسته‌های بازاری
     *
     * بستهٔ بازاری همچنان تنبل بارگذاری می‌شود. زودهنگام‌کردنش هم هزینهٔ راه‌اندازیِ
     * هر request را برای افزونه‌هایی می‌پردازد که شاید اصلاً در آن درخواست
     * استفاده نشوند، و هم بسته‌ای را که کاربر می‌تواند غیرفعال کند عملاً بی‌اختیار
     * بارگذاری می‌کند — که خلاف قرارداد «افزونهٔ غیرفعال هیچ مسیری ندارد» است.
     *
     * @see PluginReleaseManager::sourceAutoloadRoot()
     */
    private function bootBundledPlugins(): void
    {
        $releases = $this->app->make(PluginReleaseManager::class);
        $autoloader = $this->app->make(PluginAutoloader::class);

        foreach ($releases->sourceSlugs() as $slug) {
            $root = $releases->sourceAutoloadRoot($slug);
            if ($root === null) {
                continue;
            }

            try {
                $autoloader->register(
                    $slug,
                    $root,
                    PluginAutoloader::NAMESPACE_ROOT.Str::studly($slug).'\\',
                );
            } catch (InvalidArgumentException $e) {
                // ثبتِ یک بستهٔ داخلی نباید کل سایت را بالا نیاورد. بستهٔ
                // ناسالم یک هشدار می‌ماند نه یک صفحهٔ ۵۰۰ برای همه.
                Log::warning('bundled_plugin_register_failed', [
                    'slug' => $slug,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }
}
