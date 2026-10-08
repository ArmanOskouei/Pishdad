<?php

use App\Http\Controllers\Install\EnsureTwoFactorSetup;
use App\Http\Controllers\Install\InstallGuard;
use App\Http\Middleware\EnsureAdminIpAllowed;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\GateDevModeUpload;
use App\Http\Middleware\RequireUserRole;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // F1.1.F — مسیرهای نصب عمداً بیرون از گروه `web` لود می‌شوند تا با
        // APP_KEY خالی هم کار کنند (InstallGuard هیچ‌چیز رمزنگاری‌شده‌ای نمی‌خواهد).
        then: function () {
            require __DIR__.'/../routes/install.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            // این alias عمومی است: هر افزونه‌ای می‌تواند بگوید «این route فقط برای
            // نقشِ X»، بدون اینکه هسته را ویرایش کند.
            //
            // ⚠️ نامِ alias هم عمداً عمومی است، چون رشتهٔ alias در مانیفست افزونه
            // می‌آید و همان در build عمومی دیده می‌شود.
            'role' => RequireUserRole::class,
            'perm' => EnsurePermission::class,
            'devmode.gate' => GateDevModeUpload::class,
            // WF-M7 — فهرست IPهای مجاز پنل (خالی = همه). روی گروه admin اعمال می‌شود.
            'admin.ip' => EnsureAdminIpAllowed::class,
        ]);

        // U90. Laravel's default is `redirectGuestsTo(fn () => route('login'))`
        // (ApplicationBuilder.php:291). This backend has no `login` route —
        // sign-in lives in the Next.js app — so the default closure itself
        // threw RouteNotFoundException from inside Authenticate::unauthenticated()
        // and the request hung until the client gave up. Overriding it to null
        // makes Authenticate throw a proper AuthenticationException, which the
        // handler below turns into a 401 for API routes.
        $middleware->redirectGuestsTo(fn () => null);

        // F1.1.B/F — نگهبان نصب (سراسری، بدون وابستگی به web) + اجبار راه‌اندازی
        // 2FA سوپرادمین در اولین ورود (فقط گروه api). هر دو در محیط تست
        // پیش‌فرض خاموش‌اند تا تست‌های موجود نشکنند (config/installer.*).
        $middleware->append(InstallGuard::class);
        $middleware->appendToGroup('api', EnsureTwoFactorSetup::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // U90: this backend is token-based (Sanctum) and has no `login` route
        // — the sign-in page lives in the Next.js app. Laravel's default
        // handler, on an unauthenticated request, redirects to `route('login')`
        // whenever the client does not send `Accept: application/json`. That
        // route does not exist, so the redirect threw RouteNotFoundException
        // and nginx turned it into a 504 after holding the worker for its
        // whole timeout. The Next.js frontend always sends the JSON Accept
        // header, so this only hit curl/wget/the address bar — but a 504 is a
        // far worse answer than a 401, and each attempt wrote an exception plus
        // stack trace to the log.
        //
        // API routes answer 401 JSON regardless of the Accept header. Scoped to
        // `api/*` so any web route keeps whatever behaviour it has today.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    })->create();
