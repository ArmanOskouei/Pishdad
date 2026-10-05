<?php

use App\Http\Controllers\Api\V1\Admin\AdminSearchController;
use App\Http\Controllers\Api\V1\Admin\BlockSchemaController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\LayoutController;
use App\Http\Controllers\Api\V1\Admin\ManagerController;
use App\Http\Controllers\Api\V1\Admin\MediaController;
use App\Http\Controllers\Api\V1\Admin\NotificationController;
use App\Http\Controllers\Api\V1\Admin\NotificationInboxController;
use App\Http\Controllers\Api\V1\Admin\PageController;
use App\Http\Controllers\Api\V1\Admin\PluginController;
use App\Http\Controllers\Api\V1\Admin\PluginMenuController;
use App\Http\Controllers\Api\V1\Admin\PluginSettingsController;
use App\Http\Controllers\Api\V1\Admin\ProfileController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SidePresetController;
use App\Http\Controllers\Api\V1\Admin\SiteSettingsController;
use App\Http\Controllers\Api\V1\Admin\SiteThemeController;
use App\Http\Controllers\Api\V1\Admin\ThemeController;
use App\Http\Controllers\Api\V1\Admin\TicketController;
use App\Http\Controllers\Api\V1\Admin\WidgetSchemaController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContactTicketController;
use App\Http\Controllers\Api\V1\Site\PageController as SitePageController;
use App\Http\Controllers\Api\V1\Site\SearchController as SiteSearchController;
use App\Http\Controllers\Api\V1\Site\SiteChromeController;
use App\Http\Controllers\Api\V1\Site\SiteStatusController;
use App\Http\Controllers\Api\V1\PushSubscriptionController;
use App\Http\Controllers\Api\V1\SubscriptionStatusController;
use App\Http\Controllers\Api\V1\UiSettingsController;
use App\Http\Controllers\PluginRouter;
use Illuminate\Http\Request;
use App\Services\Plugins\PluginRouteTable;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — contract-first (see openapi/openapi.yaml)
| Paths here are the contract of DEV-TASKS tasks 0.1 / 0.2 / 2.1 / 2.2 /
| 2.3 / 2.4 / 3.2 / 3.3 / 4.1 / 4.2 / 4.3.
| Do NOT add endpoints outside the task scope.
|--------------------------------------------------------------------------
*/

/**
 * L-B12 — بازآدایهٔ قدیمیِ اشتراک → catch-all افزونه.
 *
 * ⚠️ عمداً در **سطحِ فایل** تعریف شده، نه داخل `Route::group`. چون گروه‌های
 * تودرتو هر closure را در scope تازه اجرا می‌کنند و متغیری که در scopeِ بیرون
 * تعریف شده باشد، خودکار به آن‌ها نمی‌رسد. برای پاس دادن باید `use` صریح نوشت
 * شود و در فایل route این کار شکننده است — پس یک‌بار تعریف می‌کنیم و
 * همه‌جا `use` می‌کنیم.
 *
 * ## چرا `PluginRouter` را مستقیم صدا نمی‌زنیم
 *
 * `PluginRouter` گارد و middleware را از **مانیفست** می‌خواند. وقتی از پشتِ یک
 * closure در فایل route صدا زده می‌شود، هیچ middleware استاندارد لاروالی اجرا
 * نمی‌شود — پس بدون بررسیِ دستی، endpoint بدون توکن جواب می‌داد.
 * اینجا صریح fail-closed می‌مانیم.
 *
 * @param  string  $action  مسیر داخلی افزونه: `show`، `invoices`، `invoices/12/pay`
 */
$forwardToPlugin = function (Request $request, string $action) {
    abort_unless($request->user() !== null, 401, 'وارد حساب کاربری نشده‌اید.');

    // L-B12/رفع ۴۰۴ — اگر افزونهٔ «اشتراک» نصب/فعال نیست، پاسخِ صادقانه
    // «اشتراکی وجود ندارد» است، نه ۴۰۴. UI قدیمی این مسیرها را در هر صفحهٔ
    // پنل می‌خواند و ۴۰۴ فقط نویزِ کنسول می‌ساخت (نصبِ رایگانِ بدون اشتراک).
    /** @var \App\Http\Controllers\PluginRouter $router */
    $router = app(\App\Http\Controllers\PluginRouter::class);

    $response = $router($request, 'subscription', $action);

    // خواندن‌ها: ۴۰۴ِ «افزونه نیست» ⇒ دادهٔ خالی (صادقانه). نوشتن‌ها دست‌نخورده.
    if ($response->getStatusCode() === 404
        && $request->isMethod('GET')
        && in_array($action, ['show', 'invoices', 'plans'], true)) {
        $empty = in_array($action, ['invoices', 'plans'], true) ? [] : null;
        $response = response()->json(['data' => $empty], 200);
    }

    // مسیر قدیمی است ولی UI فعلی هنوز همین را صدا می‌زند ⇒ نمی‌شکند.
    $response->headers->set('Deprecation', 'true');
    $response->headers->set(
        'Link',
        '</api/v1/p/subscription/'.$action.'>; rel="successor-version"',
    );

    return $response;
};

/**
 * B36 — بایندینگ {plugin}: شناسهٔ عددی یا slug.
 *
 * مدل Plugin ستون id را به‌عنوان route key دارد، پس slug مستقیم ۵۰۰ می‌داد
 * (invalid input syntax for bigint). راه‌حل getRouteKeyName نیست چون
 * PluginRouteTable و releases به id عددی تکیه می‌کنند؛ bind صریح با
 * fallback عددی هر دو مصرف‌کننده (فرانت با id، اسکریپت/curl با slug) را
 * پوشش می‌دهد و ناشناس ⇒ ۴۰۴ تمیز، نه ۵۰۰.
 */
Route::bind('plugin', function (string $value) {
    if (ctype_digit($value)) {
        return \App\Models\Plugin::query()->findOrFail((int) $value);
    }

    return \App\Models\Plugin::query()->where('slug', $value)->firstOrFail();
});

Route::prefix('v1')->group(function () use ($forwardToPlugin) {
    Route::prefix('auth')->group(function () use ($forwardToPlugin) {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
        Route::post('2fa/verify', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:auth-login');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1');

    Route::middleware('auth:sanctum')->group(function () use ($forwardToPlugin) {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('refresh', [AuthController::class, 'refresh']);
        });
    });

    Route::middleware('auth:sanctum')->group(function () use ($forwardToPlugin) {
        Route::get('ui-settings', [UiSettingsController::class, 'show']);
        Route::put('ui-settings', [UiSettingsController::class, 'update']);

        // تسک ۱.۱ — صفحات + فایل (مشترک نصب: همه مدیران همه داده را می‌بینند).
        // F0.1: مسیرهای نوشتن `perm:pages.edit` گرفتند. قبلاً فقط `destroy` محافظت
        // داشت، یعنی هر مدیر لاگین‌کرده (حتی viewer) می‌توانست عنوان و بلوک‌های
        // صفحهٔ منتشرشده را بازنویسی کند — و عنوان همان ورودیِ JSON-LD صفحهٔ
        // عمومی است (XSS ذخیره‌شده). «مشترک بودن نصب» یعنی همه مدیران *همه
        // داده را می‌بینند*، نه اینکه همه بتوانند *بنویسند*.
        Route::prefix('admin')->group(function () use ($forwardToPlugin) {
            Route::get('pages', [PageController::class, 'index']);
            Route::post('pages', [PageController::class, 'store'])->middleware('perm:pages.edit');
            Route::get('pages/{page}', [PageController::class, 'show']);
            Route::put('pages/{page}', [PageController::class, 'update'])->middleware('perm:pages.edit');
            Route::delete('pages/{page}', [PageController::class, 'destroy'])->middleware('perm:pages.delete');
            Route::post('pages/{page}/publish', [PageController::class, 'publish'])->middleware('perm:pages.edit');
            Route::post('pages/{page}/restore/{revision}', [PageController::class, 'restore'])->middleware('perm:pages.edit');
            Route::get('pages/{page}/revisions', [PageController::class, 'revisions']);
            Route::get('blocks/schema', [BlockSchemaController::class, 'index']);

            Route::get('media', [MediaController::class, 'index']);
            Route::post('media/presign', [MediaController::class, 'presign']);
            // NOTE: مسیرهای ثابت قبل از media/{id} تا id آن‌ها را نبلعد (دسته ۱).
            Route::get('media/usage', [MediaController::class, 'usage']);
            Route::post('media/restore-all', [MediaController::class, 'restoreAll']);
            Route::post('media/{id}/content', [MediaController::class, 'content']);
            Route::get('media/{id}', [MediaController::class, 'show']);
            Route::put('media/{id}', [MediaController::class, 'update']);
            Route::delete('media/{id}', [MediaController::class, 'destroy']);
            Route::post('media/{id}/restore', [MediaController::class, 'restore']);
            Route::delete('media/{id}/permanent', [MediaController::class, 'permanent']);

            // L-B12 — «اشتراک من» یک افزونهٔ **خصوصی و جدا** است.
            //
            // قبلاً این پنج route مستقیم به یک کنترلرِ داخل core اشاره می‌کردند،
            // یعنی هم کدش در repo عمومی بود و هم در نبودِ افزونه ۵۰۴ می‌داد.
            //
            // این‌ها فقط **بازآدایه** هستند و کارشان فرستادن به catch-all
            // افزونه‌هاست: `/api/v1/p/subscription/…`.
            //
            // ⭐ چرا `PluginRouter` را مستقیم صدا نمی‌زنیم؟ چون `PluginRouter`
            // guard و middleware را از **مانیفست** می‌خواند و پشتِ یک closure
            // در `routes/api.php` هیچ‌کدام از میان‌افزارهای لاراول اجرا نمی‌شوند.
            // نتیجه می‌شد `/admin/subscription` **بدون توکن** جواب بدهد — یک نشت
            // داده، نه صرفاً یک باگ زیبایی. `abort_unless` همان بررسیِ
            // احراز هویت را اینجا صریح تکرار می‌کند تا fail-closed بماند.
            //
            // مسیرهای قدیمی با هدر `Deprecation` علامت‌گذاری شده‌اند تا UI فعلی
            // نشکند؛ وقتی UI به بلوک اعلانی رفت، این‌ها حذف می‌شوند.
            foreach ([
                'subscription' => 'show',
                'subscription/invoices' => 'invoices',
                'subscription/plans' => 'plans',
                'subscription/change-plan' => 'change-plan',
            ] as $path => $action) {
                Route::match(['GET', 'POST'], $path, function (Request $request) use ($action, $forwardToPlugin) {
                    return $forwardToPlugin($request, $action);
                });
            }

            Route::post('subscription/invoices/{invoice}/pay', function (Request $request, $invoice) use ($forwardToPlugin) {
                return $forwardToPlugin($request, "invoices/{$invoice}/pay");
            });

            // تسک ۲.۲ — داشبورد مشتری.
            Route::get('dashboard/stats', [DashboardController::class, 'stats']);
            Route::get('dashboard/alerts', [DashboardController::class, 'alerts']);
            Route::get('dashboard/widgets', [DashboardController::class, 'widgets']);

            // تسک ۲.۳ — تنظیمات سایت + شبکه‌ها.
            Route::get('settings/site', [SiteSettingsController::class, 'showSite']);
            Route::put('settings/site', [SiteSettingsController::class, 'updateSite']);
            Route::get('settings/socials', [SiteSettingsController::class, 'showSocials']);
            Route::put('settings/socials', [SiteSettingsController::class, 'updateSocials']);

            // تسک ۴.۱ — هدر/فوتر + بلوک‌های انواع صفحه.
            // NOTE: مسیرهای خاص قبل از layouts/{area} ثبت می‌شوند تا area آن‌ها را نبلعد.
            Route::get('layouts/page-types', [LayoutController::class, 'pageTypes']);
            Route::get('layouts/page-type/{type}/blocks', [LayoutController::class, 'showBlocks']);
            Route::put('layouts/page-type/{type}/blocks', [LayoutController::class, 'updateBlocks']);
            Route::get('layouts/{area}', [LayoutController::class, 'show']);
            Route::put('layouts/{area}', [LayoutController::class, 'update']);

            // تسک ۶ — schema تنظیمات ویجت‌ها (هسته + مانیفست پلاگین/قالب فعال).
            Route::get('widgets/schema', [WidgetSchemaController::class, 'index']);

            // ستون‌های کناری — پریست‌های مشترک با ارجاع زنده.
            Route::get('side-presets', [SidePresetController::class, 'index']);
            Route::post('side-presets', [SidePresetController::class, 'store']);
            Route::get('side-presets/{preset}', [SidePresetController::class, 'show']);
            Route::put('side-presets/{preset}', [SidePresetController::class, 'update']);
            Route::delete('side-presets/{preset}', [SidePresetController::class, 'destroy']);

            // تسک ۴.۲ — قالب‌های سایت.
            Route::get('themes', [ThemeController::class, 'index']);
            Route::post('themes/upload', [ThemeController::class, 'upload']);
            Route::post('themes/{theme}/activate', [ThemeController::class, 'activate']);
            Route::post('themes/{theme}/preview', [ThemeController::class, 'preview']);
            Route::delete('themes/{theme}', [ThemeController::class, 'destroy']);

            // F4.1.B — سه‌قالبه: پوسته × رنگ‌بندی (جدول‌های `site_themes` /
            // `site_theme_presets` / `site_theme_settings`).
            //
            // عمداً `site-theme` و نه `themes`: مسیر `themes/*` بالا مالکیتِ بستهٔ
            // ZIP و امضای Ed25519 است (`Q5` — قالب‌ها از CODE seed می‌شوند، نه از
            // استخراجِ ZIP) و قاطی‌کردنشان یعنی بستهٔ بیرونی بتواند رنگِ قالبِ
            // درون‌ساخت را عوض کند.
            Route::get('site-theme', [SiteThemeController::class, 'index']);
            Route::post('site-theme/select', [SiteThemeController::class, 'select']);
            Route::put('site-theme/preset', [SiteThemeController::class, 'preset']);
            Route::put('site-theme/overrides', [SiteThemeController::class, 'overrides']);
            Route::post('site-theme/reset', [SiteThemeController::class, 'reset']);

            // تسک ۵.۱ — مدیران + نقش‌ها (سوپرادمین همیشه مخفی).
            Route::get('managers', [ManagerController::class, 'index'])->middleware('perm:users.view');
            Route::post('managers', [ManagerController::class, 'store'])->middleware('perm:users.edit');
            Route::get('managers/{manager}', [ManagerController::class, 'show'])->middleware('perm:users.view');
            Route::put('managers/{manager}', [ManagerController::class, 'update'])->middleware('perm:users.edit');
            Route::delete('managers/{manager}', [ManagerController::class, 'destroy'])->middleware('perm:users.delete');
            Route::get('permissions', [ManagerController::class, 'permissionsMatrix'])->middleware('perm:users.view');
            Route::get('roles', [RoleController::class, 'index'])->middleware('perm:users.view');
            Route::post('roles', [RoleController::class, 'store'])->middleware('perm:users.edit');
            Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('perm:users.view');
            Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('perm:users.edit');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('perm:users.delete');

            // تسک ۵.۲ — پروفایل مدیر.
            Route::get('profile', [ProfileController::class, 'show']);
            Route::put('profile', [ProfileController::class, 'update']);
            Route::post('profile/password', [ProfileController::class, 'changePassword']);
            Route::get('profile/2fa/methods', [ProfileController::class, 'twoFactorMethods']);
            Route::post('profile/2fa/enable', [ProfileController::class, 'enableTwoFactor']);
            Route::post('profile/2fa/disable', [ProfileController::class, 'disableTwoFactor']);
            Route::post('profile/2fa/recovery-codes', [ProfileController::class, 'regenerateRecoveryCodes']);
            Route::get('profile/sections', [ProfileController::class, 'sections']);
            // دسته UIUX (افزودنی): نشست‌های فعال + تأیید پیامکی.
            Route::get('profile/sessions', [ProfileController::class, 'sessions']);
            Route::delete('profile/sessions/{id}', [ProfileController::class, 'destroySession']);
            Route::post('profile/sessions/revoke-all', [ProfileController::class, 'revokeOtherSessions']);
            Route::post('profile/sms/request', [ProfileController::class, 'smsRequest']);
            Route::post('profile/sms/verify', [ProfileController::class, 'smsVerify']);
            Route::delete('profile/sms', [ProfileController::class, 'smsDisconnect']);

            // تسک ۵.۳ — تیکت‌ها.
            Route::get('tickets', [TicketController::class, 'index'])->middleware('perm:tickets.view');
            Route::post('tickets', [TicketController::class, 'store'])->middleware('perm:tickets.edit');
            Route::get('tickets/{ticket}', [TicketController::class, 'show'])->middleware('perm:tickets.view');
            Route::put('tickets/{ticket}', [TicketController::class, 'update'])->middleware('perm:tickets.edit');
            Route::delete('tickets/{ticket}', [TicketController::class, 'destroy'])->middleware('perm:tickets.delete');
            Route::post('tickets/{ticket}/messages', [TicketController::class, 'reply'])->middleware('perm:tickets.edit');

            // تسک ۵.۴ — پلاگین‌ها (امضای Ed25519 اجباری).
            // B5: جستجوی پنل سمت سرور. افزونه‌ها پیاده‌سازی SearchableProvider را از راه
            // نقطهٔ core.service_provider اعلام می‌کنند، نه با fetch از مرورگر.
            Route::get('search', AdminSearchController::class)->middleware('perm:plugins.view');

            // K6.1 — منوی افزونه‌ها برای سایدبار پنل.
            //
            // عمداً بدون `perm:*`: آیتم‌ها باید برای **همهٔ** مدیران برگردند تا
            // فرانت بتواند فیلترشان کند. فیلتر واقعی در `mergePluginMenu`
            // (fail-closed) و دوباره هنگام اجرای route افزونه اعمال می‌شود.
            // اگر اینجا `perm:plugins.view` می‌گذاشتیم، منوی هر کسی که آن
            // پرمیشن را ندارد خالی می‌شد و دروازهٔ فرانت هرگز امتحان نمی‌شد.
            //
            // قبل از `plugins/{plugin}` لازم است، وگرنه `show` این دو را
            // به‌عنوان اسلاگ می‌گیرد و ۴۰۴ می‌دهد.
            Route::get('plugins/menu', [PluginMenuController::class, 'index']);
            Route::get('plugins/menu/me', [PluginMenuController::class, 'me']);
            // K6.5 — رجیستری صفحه‌های اختصاصی، برای catch-all زیر /admin/.
            Route::get('plugins/pages', [PluginMenuController::class, 'pages']);

            // K6.2 — ابزارهای افزونه برای drawer هدر.
            Route::get('plugins/tools', [PluginMenuController::class, 'tools']);

            // K5.8 — سرویس اعلان.
            //
            // عمداً **بدون** `perm:*`: اعلان برای خودِ کاربر جاری است و هیچ
            // داده‌ای از کس دیگری نمی‌دهد. گیت‌کردنش یعنی کاربری که پرمیشنش
            // نیست زنگ اعلانش را نمی‌بیند و نمی‌فهمد چرا.
            //
            // مسیر کامل `read-all` است نه `read`، چون بعدش `{id}` می‌آید و با
            // هر route پارامتری برخورد می‌کرد.
            Route::get('notifications', [NotificationController::class, 'index']);
            Route::post('notifications', [NotificationController::class, 'store']);
            Route::post('notifications/read-all', [NotificationController::class, 'readAll']);

            // F4.2.B — صندوق و ترجیحات. ⭐ عمداً `notification-*` و نه همان
            // `notifications`: مسیرِ بالا مالکیتِ `K5.8` (سرویسِ افزونه) است و
            // `done`. دو کنترلر روی یک URI یعنی یکی بی‌سروصدا دیگری را بازنویسی
            // می‌کند. اینجا فقط خواندنِ صندوق و نوشتنِ ترجیح است.
            Route::get('notification-inbox', [NotificationInboxController::class, 'index']);
            Route::get('notification-preferences', [NotificationInboxController::class, 'preferences']);
            Route::put('notification-preferences', [NotificationInboxController::class, 'updatePreferences']);

            // K6.7 — تنظیمات افزونه‌ها: اسکیما + مقادیر.
            //
            // `perm:*` دارد، برخلاف `plugins/menu` و `plugins/pages`. دلیل فرق
            // این است که منو و صفحه فقط **نام** دارند و فیلترشان در فرانت
            // fail-closed است، ولی اینجا **مقدار** خوانده و نوشته می‌شود — پس
            // همان مجوزی که روی خودِ فرم در UI ادعا می‌شود باید در مسیر هم
            // اجبار باشد، وگرنه یک مدیر بدون `settings.edit` می‌توانست با یک
            // درخواست دستی تنظیمات را عوض کند.
            Route::get('plugins/settings', [PluginSettingsController::class, 'index'])
                ->middleware('perm:settings.view');
            Route::put('plugins/settings/{slug}', [PluginSettingsController::class, 'update'])
                ->middleware('perm:settings.edit');

            Route::get('plugins', [PluginController::class, 'index'])->middleware('perm:plugins.view');
            Route::post('plugins/validate', [PluginController::class, 'validatePackage'])->middleware('perm:plugins.edit');
            Route::post('plugins/upload', [PluginController::class, 'upload'])->middleware(['perm:plugins.edit', 'devmode.gate']);
            // I2: قرارداد نقاط اتصال، بدون آپلود ZIP. عمداً پیش از
            // `plugins/{plugin}` تا با آن قورچه نشود. فرانت از همین یکی می‌خواند
            // و فهرست نقاط را hardcode نمی‌کند.
            Route::get('plugins/contract', [PluginController::class, 'contract'])->middleware('perm:plugins.view');
            Route::get('plugins/{plugin}', [PluginController::class, 'show'])->middleware('perm:plugins.view');
            Route::post('plugins/{plugin}/activate', [PluginController::class, 'activate'])->middleware('perm:plugins.edit');
            Route::post('plugins/{plugin}/deactivate', [PluginController::class, 'deactivate'])->middleware('perm:plugins.edit');
            Route::post('plugins/{plugin}/upgrade', [PluginController::class, 'upgrade'])->middleware(['perm:plugins.edit', 'devmode.gate']);
            Route::post('plugins/{plugin}/uninstall', [PluginController::class, 'uninstall'])->middleware('perm:plugins.delete');
        });
    });

    // تسک ۵.۳ — فرم تماس عمومی (rate-limit سفت + honeypot).
    Route::post('contact/tickets', [ContactTicketController::class, 'store'])->middleware('throttle:contact');

    // تسک ۱.۱ — رندر عمومی ISR (فقط published).
    // فهرست سبک صفحات منتشرشده (sitemap.xml و llms.txt) — قبل از {path} تا سایه نیفتد.
    Route::get('site/pages', [SitePageController::class, 'index']);
    // صفحه خانه عمومی (homepage_page_id → home → جدیدترین) — قبل از {path}.
    Route::get('site/homepage', [SitePageController::class, 'homepage']);
    Route::get('site/pages/{path}', [SitePageController::class, 'show'])->where('path', '.*');

    // کروم عمومی سایت (هدر/فوتر واقعی + برند) — سبک، rate-limit ملایم، بدون داده حساس.
    Route::get('site/chrome', [SiteChromeController::class, 'show'])->middleware('throttle:60,1');

    // جستجوی عمومی سایت (فقط منتشرشده + throttle سفت + q حداقل ۲ نویسه).
    Route::get('site/search', [SiteSearchController::class, 'index'])->middleware('throttle:site-search');

    // تسک ۴.۳ — وضعیت عمومی سایت (سبک، بدون احراز هویت).
    Route::get('site/status', [SiteStatusController::class, 'show']);

    // تسک ۱.۲ — polling سبک نصب مشتری (عمومی).
    Route::get('subscription-status', [SubscriptionStatusController::class, 'show']);

    // ── Web Push ──────────────────────────────────────────────────────────
    //
    // ثبت اشتراک **عمومی** است: بازدیدکننده حساب ندارد ولی می‌خواهد
    // اعلان بگیرد. این کار فقط روی دستگاه خودِ او اثر دارد، چون
    // `endpoint` همان کلیدِ یکتای مقصد است و کسی جز صاحبش آن را ندارد.
    //
    // ‎⚠️ به همین دلیل `endpoint` هرگز در پاسخ برنمی‌گردد و `destroy` همیشه
    // `ok` جواب می‌دهد — وگرنه یک مهاجم می‌توانست endpointهای موجود را
    // کشف کند.

    // کلید عمومی VAPID — فرانت برای `pushManager.subscribe()` لازمش دارد.
    Route::get('push/public-key', [PushSubscriptionController::class, 'show'])
        ->middleware('throttle:60,1');

    // ثبت/به‌روزرسانی — rate-limit سفت چون endpoint یکتای گران است.
    Route::post('push/subscribe', [PushSubscriptionController::class, 'store'])
        ->middleware('throttle:10,1');

    // لغو — همان rate-limit، چون یک payload بزرگ است.
    Route::post('push/unsubscribe', [PushSubscriptionController::class, 'destroy'])
        ->middleware('throttle:10,1');

    // لیست اشتراک‌های خودِ کاربر — **فقط** احراز هویت‌شده.
    Route::get('push/subscriptions', [PushSubscriptionController::class, 'index'])
        ->middleware('auth:sanctum');

    // همان `store` ولی پشت Sanctum.
    //
    // ‎⚠️ چرا لازم است: کاربر ادمین از فرانت با cookie احراز هویت می‌آید،
    // ولی `push/subscribe` عمومی است و middleware ندارد، پس
    // `$request->user()` همیشه `null` بود و `user_id` بی‌صدا `null`
    // می‌شد — یعنی مدیر opt-in می‌کرد ولی هیچ اعلانی برایش (که در
    // `ContentPublishedNotifier` به `user_id IS NOT NULL` فیلتر می‌شود)
    // هرگز نمی‌رسید. همان handler، ولی اینجا با کاربرِ واقعی.
    Route::post('push/subscribe/auth', [PushSubscriptionController::class, 'store'])
        ->middleware(['auth:sanctum', 'throttle:10,1']);

    // K5.4 + K7.11 — catch-all مالک هسته برای routeهای افزونه.
    //
    // این **تنها** route واقعی هسته برای افزونه‌هاست و بقیه داخل PluginRouter
    // dispatch می‌شوند. ->where عمداً سست است و قاعدهٔ واقعی slug داخل
    // PluginRouteTable است — کنترلر باید خودش مرز امنیتی باشد، نه اینکه به
    // تنظیم route تکیه کند؛ قاعدهٔ سفت‌تر یعنی تست نمی‌تواند مسیر بد را تا
    // کنترلر دنبال کند.
    //
    // 	hrottle:plugin معماری را دنبال نکرد چون چنین limiterای ثبت نشده؛ عددی‌اش
    // همان کار را می‌کند و حتماً resolve می‌شود.
    Route::match(PluginRouteTable::METHODS, 'p/{slug}/{any?}', PluginRouter::class)
        ->where('slug', '[^/]+')
        ->where('any', '.*')
        ->name(PluginRouter::ROUTE_NAME)
        ->middleware('throttle:60,1');

    // K5.4 + K7.11 — catch-all مالک هسته برای routeهای افزونه.
    //
    // این **تنها** route واقعی هسته برای افزونه‌هاست و بقیه داخل `PluginRouter`
    // dispatch می‌شوند. `->where` عمداً سست است و قاعدهٔ واقعی slug داخل
    // `PluginRouteTable` است — کنترلر باید خودش مرز امنیتی باشد، نه اینکه به
    // تنظیم route تکیه کند؛ قاعدهٔ سفت‌تر یعنی تست نمی‌تواند مسیر بد را تا
    // کنترلر دنبال کند.
    //
    // `throttle:plugin` معماری را دنبال نکرد چون چنین limiterای ثبت نشده؛ عددی‌اش
    // همان کار را می‌کند و حتماً resolve می‌شود.

});

// F6.1 — فهرستِ curated به‌صورت endpoint عمومی (بدون احراز هویت).
//
// **این مسیر نصب نمی‌کند.** آنچه برمی‌گرداند «قصدِ نصب» است: یعنی تنها راهِ
// رسمی چیست. بازار (`v1/market/*`) تنها مسیرِ نصب است و چهار دروازه دارد —
// ثبتِ ناشر، امضای Ed25519، تأییدِ مرکزی، لایسنس. هیچ‌کدام اینجا دور زده
// نمی‌شود و tier تازه‌ای هم ساخته نمی‌شود: آداپتور نازک است.
//
// عمومی بودن عمدی است، مثل `v1/market/publishers`: هر کسی باید بتواند ببیند
// چه چیزی وجود دارد. داده‌ای هم در آن نیست که افشایی باشد.
Route::get('v1/registry', function () {
    try {
        $registry = app(\App\Services\Registry\CuratedRegistry::class)->all();
    } catch (\Throwable $e) {
        // فهرستِ curated یک ورودیِ اختیاری است، نه یک وابستگیِ حیاتی. خرابیِ
        // آن نباید کل پنل را ۵۰۰ کند — ولی **پیامش هم عمومی نمی‌شود** چون
        // جزئیاتِ فایل، ساختارِ دیسک را لو می‌دهد.
        \Illuminate\Support\Facades\Log::error('registry.curated_unreadable', ['error' => $e->getMessage()]);

        return response()->json([
            'message' => 'فهرستِ افزونه‌های منتخب در حال حاضر در دسترس نیست.',
            'code' => 'registry.unavailable',
        ], 503);
    }

    return response()->json([
        'data' => $registry,
        'meta' => [
            'install_intent' => [
                'available' => false,
                'why_fa' => 'این فهرست در K8 نسخهٔ ۱ فقط پیوند است؛ نصب از این مسیر انجام نمی‌شود.',
            ],
        ],
    ]);
})->middleware('throttle:60,1');

// J5 — heartbeat نصب‌ها از هسته رفت.
//
// کنترلر روی توکنِ نصبِ مشتری احراز می‌کرد و در جدولِ ضربانِ نصب می‌نوشت، یعنی
// ** صددرصد کارِ مرکز** بود ولی endpointش در `routes/api.php` هسته تعریف می‌شد.
// نتیجه: یک نصبِ عمومی که افزونهٔ مرکز ندارد، این مسیر را در جدول routeهایش
// نگه می‌داشت و یک کنترلرِ ناظر بر افزونه در هسته داشت.
//
// ⚠️ اسمِ جدول‌ها عمداً اینجا نیامده: `PluginBoundaryTableSweepTest` هر رشتهٔ
// شبیه نامِ جدولِ مرکزی را در فایل‌های هسته «ارجاع» می‌شمارد — حتی داخل کامنت.
// پس نامِ واقعی فقط در یک جا می‌ماند: مانیفست و مهاجرت‌های افزونه.
//
// حالا در بسته است و از راه `api.routes` مانیفست سرو می‌شود:
//   `POST /api/v1/p/central/internal/heartbeat`
// تغییر مسیر یعنی ** Central باید آدرس تازه را صدا بزند** — که درست است، چون
// endpoint متعلق به افزونه است و باید زیر prefix خودِ افزونه باشد.

// J10 — دورهٔ آزمایشی بدون درگاه: وب‌هوک پرداخت حذف شد. پرداختِ خودکار
// نیست؛ مدیر مرکزی تیر/مدت را دستی تنظیم می‌کند (upgrade-tier/suspend/resume
// در `/api/v1/p/central/*`). نقطهٔ پرداختِ stub در افزونهٔ اشتراک
// (`subscription/invoices/{invoice}/pay`) سر جایش است و تستش سبز می‌ماند.

// K8 — market: publisher trust store (K8.1), upload-to-review + approve/reject
// (K8.2), yank (K8.3), install-from-market (K8.4), order + license + settlement
// ledger + MANUAL payout (K8.5). No gateway anywhere (K8.6 deferred).
// NOTE (K8 boundary): only this block was appended; nothing else in this file
// was touched, and frozen files (PluginController, ManifestRegistry,
// PluginPackageValidator) are only *called*, never edited.
Route::prefix('v1/market')->group(function () {
    $k8 = function (callable $fn) {
        try {
            return $fn();
        } catch (\App\Services\Market\MarketException $e) {
            $payload = ['message' => $e->getMessage()];
            if ($e->details !== null) {
                $payload['details'] = $e->details;
            }

            return response()->json($payload, $e->status);
        }
    };

    // K8.1 — public trust store (no auth: anyone can verify publisher keys).
    Route::get('publishers', function () use ($k8) {
        return $k8(fn () => response()->json(['data' => app(\App\Services\Market\PublisherService::class)->publicStore()]));
    });

    // Public market listing: approved, non-yanked plugins only.
    Route::get('plugins', function () use ($k8) {
        return $k8(fn () => response()->json(['data' => \App\Models\Plugin::query()
            ->where('source', \App\Models\Plugin::SOURCE_MARKET)
            ->where('review_status', \App\Models\Plugin::REVIEW_APPROVED)
            ->where('yanked', false)
            ->latest()->paginate(20)]));
    });

    // K8.3 — public security notices (yank/…).
    Route::get('notices', function () use ($k8) {
        return $k8(fn () => response()->json(['data' => \App\Models\MarketNotice::query()
            ->latest()->paginate(20)]));
    });

    Route::middleware('auth:sanctum')->group(function () use ($k8) {
        // K8.1 — publisher self-registration.
        Route::post('publishers', function (\Illuminate\Http\Request $request) use ($k8) {
            return $k8(fn () => response()->json(
                ['data' => app(\App\Services\Market\PublisherService::class)->register($request->only(['name', 'slug', 'public_key']))],
                201
            ));
        });

        // K8.2 — upload-to-review (validator errors => 422 per K1.7-A).
        Route::post('submissions', function (\Illuminate\Http\Request $request) use ($k8) {
            return $k8(function () use ($request) {
                $validated = $request->validate(['file' => 'required|file|mimes:zip|max:20480']);
                $plugin = app(\App\Services\Market\ReviewService::class)->submit($validated['file'], $request->user());

                return response()->json(['data' => $plugin], 201);
            });
        })->middleware('perm:plugins.edit');

        // K8.2 — review queue (central operator).
        Route::get('submissions', function (\Illuminate\Http\Request $request) use ($k8) {
            return $k8(fn () => response()->json(['data' => \App\Models\Plugin::query()
                ->where('source', \App\Models\Plugin::SOURCE_MARKET)
                ->where('review_status', $request->query('status', \App\Models\Plugin::REVIEW_PENDING))
                ->latest()->paginate(20)]));
        })->middleware('role:operator');

        Route::post('plugins/{plugin}/approve', function (\Illuminate\Http\Request $request, \App\Models\Plugin $plugin) use ($k8) {
            return $k8(fn () => response()->json(
                ['data' => app(\App\Services\Market\ReviewService::class)->approve($plugin, $request->input('note'))]
            ));
        })->middleware('role:operator');

        Route::post('plugins/{plugin}/reject', function (\Illuminate\Http\Request $request, \App\Models\Plugin $plugin) use ($k8) {
            return $k8(fn () => response()->json(
                ['data' => app(\App\Services\Market\ReviewService::class)->reject($plugin, $request->input('note'))]
            ));
        })->middleware('role:operator');

        // K8.3 — yank / unyank (central operator).
        Route::post('plugins/{plugin}/yank', function (\Illuminate\Http\Request $request, \App\Models\Plugin $plugin) use ($k8) {
            return $k8(fn () => response()->json(
                ['data' => app(\App\Services\Market\ReviewService::class)->yank($plugin, (string) $request->input('reason', ''))]
            ));
        })->middleware('role:operator');

        Route::post('plugins/{plugin}/unyank', function (\App\Models\Plugin $plugin) use ($k8) {
            return $k8(fn () => response()->json(
                ['data' => app(\App\Services\Market\ReviewService::class)->unyank($plugin)]
            ));
        })->middleware('role:operator');

        // K8.4 — install from market (no money).
        Route::post('plugins/{plugin}/install', function (\Illuminate\Http\Request $request, \App\Models\Plugin $plugin) use ($k8) {
            return $k8(fn () => response()->json(
                ['data' => app(\App\Services\Market\ReviewService::class)->install($plugin, $request->user())],
                201
            ));
        });

        // K8.5 — orders (manual payment, no gateway).
        Route::post('orders', function (\Illuminate\Http\Request $request) use ($k8) {
            return $k8(function () use ($request) {
                $validated = $request->validate(['plugin_id' => 'required|integer|exists:plugins,id']);
                $plugin = \App\Models\Plugin::query()->findOrFail($validated['plugin_id']);
                $order = app(\App\Services\Market\BillingService::class)->createOrder($plugin, $request->user());

                return response()->json(['data' => $order], 201);
            });
        });

        Route::get('orders', function (\Illuminate\Http\Request $request) use ($k8) {
            return $k8(fn () => response()->json(['data' => \App\Models\MarketOrder::query()
                ->where('user_id', $request->user()->id)->latest()->paginate(20)]));
        });

        Route::post('orders/{order}/pay', function (\Illuminate\Http\Request $request, \App\Models\MarketOrder $order) use ($k8) {
            return $k8(fn () => response()->json(
                ['data' => app(\App\Services\Market\BillingService::class)->payOrder($order, $request->user())]
            ));
        });

        // K8.5 — my licenses (K0.9 validity via coversCore()).
        Route::get('licenses', function (\Illuminate\Http\Request $request) use ($k8) {
            return $k8(function () use ($request) {
                $licenses = \App\Models\MarketLicense::query()
                    ->where('user_id', $request->user()->id)->with('plugin')->latest()->get()
                    ->map(fn (\App\Models\MarketLicense $l) => array_merge($l->toArray(), [
                        'covers_current_core' => $l->coversCore(),
                        'core_version' => \App\Services\Market\CoreMajor::currentVersion(),
                    ]));

                return response()->json(['data' => $licenses]);
            });
        });

        // K8.5 — settlement ledger (central operator).
        Route::get('ledger', function () use ($k8) {
            return $k8(fn () => response()->json(['data' => \App\Models\MarketLedgerEntry::query()
                ->latest()->paginate(50)]));
        })->middleware('role:operator');

        // K8.5 — MANUAL payouts (central operator; execution happens off-system).
        Route::post('payouts', function (\Illuminate\Http\Request $request) use ($k8) {
            return $k8(function () use ($request) {
                $validated = $request->validate([
                    'publisher_key_id' => 'required|integer|exists:publisher_keys,id',
                    'amount' => 'required|integer|min:1',
                    'currency' => 'nullable|string|max:10',
                    'note' => 'nullable|string|max:1000',
                ]);
                $payout = app(\App\Services\Market\BillingService::class)->createPayout(
                    $validated['publisher_key_id'], $validated['amount'],
                    $validated['note'] ?? null, $validated['currency'] ?? 'IRT'
                );

                return response()->json(['data' => $payout], 201);
            });
        })->middleware('role:operator');

        Route::post('payouts/{payout}/pay', function (\App\Models\MarketPayout $payout) use ($k8) {
            return $k8(fn () => response()->json(
                ['data' => app(\App\Services\Market\BillingService::class)->payPayout($payout)]
            ));
        })->middleware('role:operator');

        // ═══════════════════════════════════════════════════════════════════
        // ECO6 — فروشگاه: کاتالوگ + checkout + تحویل ZIP
        // ═══════════════════════════════════════════════════════════════════
        //
        // تک‌خرید، بدون لایسنس/اشتراک (تصمیم قفل‌شدهٔ ECO6). درگاه پرداخت
        // بیرون از دامنه است (K8.6 معلق) ⇒ این مسیر **صادق** است: سفارش
        // ساخته می‌شود، پرداخت دستی تأیید می‌شود، بعد فایل یک‌بار تحویل
        // داده می‌شود. هیچ حالتِ «paid» جعلی ساخته نمی‌شود.

        // Catalog (خریدار): افزونه‌های تأییدشده و غیرِ yank، همراه وضعیت خرید/تحویل.
        Route::get('catalog', function (\Illuminate\Http\Request $request) use ($k8) {
            return $k8(function () use ($request) {
                $user = $request->user();
                $plugins = \App\Models\Plugin::query()
                    ->where('source', \App\Models\Plugin::SOURCE_MARKET)
                    ->where('review_status', \App\Models\Plugin::REVIEW_APPROVED)
                    ->where('yanked', false)
                    ->latest()->paginate(20);

                $delivery = app(\App\Services\Market\DeliveryService::class);

                $plugins->getCollection()->transform(fn (\App\Models\Plugin $p) => array_merge(
                    $p->toArray(),
                    [
                        'price' => app(\App\Services\Market\ReviewService::class)->priceOf($p),
                        'currency' => app(\App\Services\Market\ReviewService::class)->currencyOf($p),
                        'delivery' => $delivery->deliveryStatus($p, $user),
                    ]
                ));

                return response()->json(['data' => $plugins]);
            });
        });

        // Item detail + وضعیت تحویل به‌ازای همین کاربر.
        Route::get('catalog/{plugin}', function (\Illuminate\Http\Request $request, \App\Models\Plugin $plugin) use ($k8) {
            return $k8(function () use ($request, $plugin) {
                $delivery = app(\App\Services\Market\DeliveryService::class);

                return response()->json(['data' => array_merge($plugin->toArray(), [
                    'price' => app(\App\Services\Market\ReviewService::class)->priceOf($plugin),
                    'currency' => app(\App\Services\Market\ReviewService::class)->currencyOf($plugin),
                    'delivery' => $delivery->deliveryStatus($plugin, $request->user()),
                ])]);
            });
        });

        // Checkout — ساخت سفارش تک‌خرید. همان `BillingService::createOrder` که
        // K8.5 دارد؛ این مسیر فقط «خرید از فروشگاه» را روی همان سرویس می‌نشاند.
        Route::post('catalog/{plugin}/checkout', function (\Illuminate\Http\Request $request, \App\Models\Plugin $plugin) use ($k8) {
            return $k8(function () use ($request, $plugin) {
                $order = app(\App\Services\Market\BillingService::class)->createOrder($plugin, $request->user());

                return response()->json([
                    'data' => $order,
                    // صراحتِ صادقانه: درگاه نیست. خریدار نباید منتظر «پرداخت شد» بماند.
                    'gateway' => ['available' => false, 'mode' => 'manual'],
                    'note_fa' => 'پرداخت در این نسخه دستی است؛ پس از تأیید، سفارش به وضعیت paid می‌رود و بعد فایل تحویل داده می‌شود.',
                ], 201);
            });
        });

        // تحویل — دانلود ZIP دقیقاً یک‌بار پس از پرداخت.
        Route::get('catalog/{plugin}/download', function (\Illuminate\Http\Request $request, \App\Models\Plugin $plugin) use ($k8) {
            return $k8(function () use ($request, $plugin) {
                $license = app(\App\Services\Market\DeliveryService::class)->deliver($plugin, $request->user());

                $path = $plugin->path;
                $filename = $plugin->slug.'-'.$plugin->version.'.zip';

                return response()->download(
                    \Illuminate\Support\Facades\Storage::disk('local')->path($path),
                    $filename,
                    ['X-Delivered-Checksum' => (string) $license->delivered_checksum]
                );
            });
        });
    });
});
