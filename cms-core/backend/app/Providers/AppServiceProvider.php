<?php

namespace App\Providers;

use App\Services\Billing\PaymentGatewayInterface;
use App\Services\Billing\ZarinpalStubDriver;
use App\Services\Chabokan\ChabokanClientInterface;
use App\Services\Chabokan\StubChabokanDriver;
use App\Services\Plugins\ServiceProviderRegistry;
use App\Services\RevalidateSigner;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
                    return response()->json(
                        ['message' => 'تلاش‌های ورود بیش از حد مجاز است. لطفاً دقایقی دیگر تلاش کنید.'],
                        429
                    );
                });
        });
    }
}
