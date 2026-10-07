<?php

use App\Http\Controllers\Api\V1\Admin\AdminSearchController;
use App\Http\Controllers\Api\V1\Admin\ActivityController;
use App\Http\Controllers\Api\V1\Admin\AnalyticsController as AdminAnalyticsController;
use App\Http\Controllers\Api\V1\Admin\BackupSettingsController;
use App\Http\Controllers\Api\V1\Admin\BlockSchemaController;
use App\Http\Controllers\Api\V1\Admin\CacheController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\ExportController;
use App\Http\Controllers\Api\V1\Admin\FormController;
use App\Http\Controllers\Api\V1\Admin\ImportController;
use App\Http\Controllers\Api\V1\Admin\LayoutController;
use App\Http\Controllers\Api\V1\Admin\MailSettingsController;
use App\Http\Controllers\Api\V1\Admin\ManagerController;
use App\Http\Controllers\Api\V1\Admin\MediaController;
use App\Http\Controllers\Api\V1\Admin\NotificationController;
use App\Http\Controllers\Api\V1\Admin\NotificationInboxController;
use App\Http\Controllers\Api\V1\Admin\PageController;
use App\Http\Controllers\Api\V1\Admin\PerformanceSettingsController;
use App\Http\Controllers\Api\V1\Admin\PluginController;
use App\Http\Controllers\Api\V1\Admin\PluginMenuController;
use App\Http\Controllers\Api\V1\Admin\PluginSettingsController;
use App\Http\Controllers\Api\V1\Admin\ProfileController;
use App\Http\Controllers\Api\V1\Admin\RedirectController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SampleContentController;
use App\Http\Controllers\Api\V1\Admin\SearchReportController;
use App\Http\Controllers\Api\V1\Admin\SecuritySettingsController;
use App\Http\Controllers\Api\V1\Admin\SidePresetController;
use App\Http\Controllers\Api\V1\Admin\SiteSettingsController;
use App\Http\Controllers\Api\V1\Admin\SiteThemeController;
use App\Http\Controllers\Api\V1\Admin\ThemeController;
use App\Http\Controllers\Api\V1\Admin\TicketController;
use App\Http\Controllers\Api\V1\Admin\WebhookSettingsController;
use App\Http\Controllers\Api\V1\Admin\WidgetSchemaController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContactTicketController;
use App\Http\Controllers\Api\V1\Market\MarketReviewController;
use App\Http\Controllers\Api\V1\Site\AnalyticsController as SiteAnalyticsController;
use App\Http\Controllers\Api\V1\Site\FormController as SiteFormController;
use App\Http\Controllers\Api\V1\Site\PageController as SitePageController;
use App\Http\Controllers\Api\V1\Site\RedirectController as SiteRedirectController;
use App\Http\Controllers\Api\V1\Site\SearchController as SiteSearchController;
use App\Http\Controllers\Api\V1\Site\SiteChromeController;
use App\Http\Controllers\Api\V1\Site\SiteCacheController;
use App\Http\Controllers\Api\V1\Site\SiteThemeController as SiteThemePublicController;
use App\Http\Controllers\Api\V1\Site\SiteStatusController;
use App\Http\Controllers\Api\V1\PushSubscriptionController;
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
Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->middleware(['throttle:auth-login', 'throttle:auth-login-ip']);
        Route::post('2fa/verify', [AuthController::class, 'verifyTwoFactor'])->middleware(['throttle:auth-login', 'throttle:auth-login-ip']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1');
    Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('refresh', [AuthController::class, 'refresh']);
        });
    });
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('ui-settings', [UiSettingsController::class, 'show']);
        Route::put('ui-settings', [UiSettingsController::class, 'update']);
        // تسک ۱.۱ — صفحات + فایل (مشترک نصب: همه مدیران همه داده را می‌بینند).
        // F0.1: مسیرهای نوشتن `perm:pages.edit` گرفتند. قبلاً فقط `destroy` محافظت
        // داشت، یعنی هر مدیر لاگین‌کرده (حتی viewer) می‌توانست عنوان و بلوک‌های
        // صفحهٔ منتشرشده را بازنویسی کند — و عنوان همان ورودیِ JSON-LD صفحهٔ
        // عمومی است (XSS ذخیره‌شده). «مشترک بودن نصب» یعنی همه مدیران *همه
        // داده را می‌بینند*، نه اینکه همه بتوانند *بنویسند*.
        Route::prefix('admin')->middleware('admin.ip')->group(function () {
            Route::get('pages', [PageController::class, 'index']);
            Route::post('pages', [PageController::class, 'store'])->middleware('perm:pages.edit');
            // NOTE: مسیر ثابت سطل زباله قبل از pages/{page} تا به‌عنوان id بلعیده نشود.
            Route::get('pages/trash', [PageController::class, 'trash']);
            // WF-M5 — عملیات گروهی (انتشار/لغو انتشار/سطل زباله). مسیر ثابت پیش از
            // `pages/{page}` تا به‌عنوان id بلعیده نشود. پرمیشن بسته به اکشن فرق
            // دارد (`pages.edit` در برابر `pages.delete`) و داخل کنترلر بررسی می‌شود.
            Route::post('pages/bulk', [PageController::class, 'bulk']);
            Route::get('pages/{page}', [PageController::class, 'show']);
            Route::put('pages/{page}', [PageController::class, 'update'])->middleware('perm:pages.edit');
            Route::delete('pages/{page}', [PageController::class, 'destroy'])->middleware('perm:pages.delete');
            Route::post('pages/{page}/publish', [PageController::class, 'publish'])->middleware('perm:pages.edit');
            // WF-M4 — تکثیر صفحه: کلونِ بلوک‌ها/متا/ستون‌ها در پیش‌نویس با اسلاگ `-copy`.
            Route::post('pages/{page}/duplicate', [PageController::class, 'duplicate'])->middleware('perm:pages.edit');
            // WF-H5 — لغو انتشار: status → draft با نگه‌داشتن published_revision_id/published_at.
            Route::post('pages/{page}/unpublish', [PageController::class, 'unpublish'])->middleware('perm:pages.edit');
            // WF-C3 — انتشار زمان‌بندی‌شده: ثبت/لغو موعد انتشار.
            Route::post('pages/{page}/schedule', [PageController::class, 'schedule'])->middleware('perm:pages.edit');
            Route::delete('pages/{page}/schedule', [PageController::class, 'cancelSchedule'])->middleware('perm:pages.edit');
            // WF-H2 — لینک اشتراک پیش‌نویس: ساخت (چرخشِ لینک قبلی) + لغو.
            Route::post('pages/{page}/share', [PageController::class, 'share'])->middleware('perm:pages.edit');
            Route::delete('pages/{page}/share', [PageController::class, 'revokeShare'])->middleware('perm:pages.edit');
            // WF-H3 — قفل نرم ویرایش هم‌زمان: گرفتن/heartbeat + آزادسازی.
            Route::post('pages/{page}/lock', [PageController::class, 'lock'])->middleware('perm:pages.edit');
            Route::delete('pages/{page}/lock', [PageController::class, 'unlock'])->middleware('perm:pages.edit');
            Route::post('pages/{page}/restore/{revision}', [PageController::class, 'restore'])->middleware('perm:pages.edit');
            Route::get('pages/{page}/revisions', [PageController::class, 'revisions']);
            // WF-H14 — همزادِ ترجمهٔ زبانِ مقابل + وضعیت ترجمه/نیازمند به‌روزرسانی.
            Route::get('pages/{page}/translation', [PageController::class, 'showTranslation'])->middleware('perm:pages.edit');
            Route::post('pages/{page}/translation', [PageController::class, 'storeTranslation'])->middleware('perm:pages.edit');
            Route::post('pages/{id}/restore', [PageController::class, 'restoreTrashed'])->middleware('perm:pages.edit');
            Route::delete('pages/{id}/force', [PageController::class, 'forceDelete'])->middleware('perm:pages.delete');
            Route::get('blocks/schema', [BlockSchemaController::class, 'index']);
            // WF-M21 — محتوای نمونهٔ یک‌کلیکی برای حالت خالیِ لیست‌ها. idempotent.
            Route::post('sample-content', [SampleContentController::class, 'store'])->middleware('perm:pages.edit');
            Route::get('media', [MediaController::class, 'index']);
            Route::post('media/presign', [MediaController::class, 'presign']);
            // NOTE: مسیرهای ثابت قبل از media/{id} تا id آن‌ها را نبلعد (دسته ۱).
            Route::get('media/usage', [MediaController::class, 'usage']);
            Route::post('media/restore-all', [MediaController::class, 'restoreAll']);
            // WF-H6 — پوشه/برچسب/انتقال گروهی. مسیرهای ثابت قبل از media/{id}.
            Route::get('media/folders', [MediaController::class, 'folders']);
            Route::post('media/folders', [MediaController::class, 'storeFolder'])->middleware('perm:media.edit');
            Route::put('media/folders/{id}', [MediaController::class, 'updateFolder'])->middleware('perm:media.edit');
            Route::delete('media/folders/{id}', [MediaController::class, 'destroyFolder'])->middleware('perm:media.edit');
            Route::get('media/tags', [MediaController::class, 'tags']);
            Route::post('media/tags', [MediaController::class, 'storeTag'])->middleware('perm:media.edit');
            Route::put('media/tags/{id}', [MediaController::class, 'updateTag'])->middleware('perm:media.edit');
            Route::delete('media/tags/{id}', [MediaController::class, 'destroyTag'])->middleware('perm:media.edit');
            Route::post('media/bulk/move', [MediaController::class, 'bulkMove'])->middleware('perm:media.edit');
            Route::post('media/bulk/tag', [MediaController::class, 'bulkTag'])->middleware('perm:media.edit');
            // WF-H7 — گزارش تصاویر بدون alt + ویرایش گروهی alt. مسیر ثابت قبل از media/{id}.
            Route::get('media/alt-report', [MediaController::class, 'altReport']);
            Route::post('media/bulk/alt', [MediaController::class, 'bulkAlt'])->middleware('perm:media.edit');
            Route::post('media/{id}/content', [MediaController::class, 'content']);
            // WF-M6 — جایگزینی بایت‌های فایل با حفظ مسیر/URL + باطل‌سازی کش.
            Route::post('media/{id}/replace', [MediaController::class, 'replace'])->middleware('perm:media.edit');
            Route::get('media/{id}', [MediaController::class, 'show']);
            Route::put('media/{id}', [MediaController::class, 'update']);
            Route::delete('media/{id}', [MediaController::class, 'destroy']);
            Route::post('media/{id}/restore', [MediaController::class, 'restore']);
            Route::delete('media/{id}/permanent', [MediaController::class, 'permanent']);
            // تسک ۲.۲ — داشبورد مشتری.
            Route::get('dashboard/stats', [DashboardController::class, 'stats']);
            Route::get('dashboard/alerts', [DashboardController::class, 'alerts']);
            Route::get('dashboard/widgets', [DashboardController::class, 'widgets']);
            // WF-H12 — تحلیل سایت عمومی (بازدید/پربازدیدترین/ارجاع/کشور).
            Route::get('analytics', [AdminAnalyticsController::class, 'index'])
                ->middleware('perm:analytics.view');
            // WF-M11 — گزارش جستجوی سایت (پرجستجوترین‌ها + بی‌نتیجه‌ها).
            Route::get('search-report', [SearchReportController::class, 'index'])
                ->middleware('perm:analytics.view');
            // تسک ۲.۳ — تنظیمات سایت + شبکه‌ها.
            Route::get('settings/site', [SiteSettingsController::class, 'showSite']);
            Route::put('settings/site', [SiteSettingsController::class, 'updateSite']);
            Route::get('settings/socials', [SiteSettingsController::class, 'showSocials']);
            Route::put('settings/socials', [SiteSettingsController::class, 'updateSocials']);
            // WF-M13 — تبِ «کارایی»: دامنهٔ دارایی/CDN + توکن پاک‌سازی + lazy/preload.
            Route::get('settings/performance', [PerformanceSettingsController::class, 'show'])
                ->middleware('perm:settings.view');
            Route::put('settings/performance', [PerformanceSettingsController::class, 'update'])
                ->middleware('perm:settings.edit');
            // WF-M7 — امنیت پنل: IPهای مجاز + سقف تلاش ورود.
            Route::get('settings/security', [SecuritySettingsController::class, 'show'])
                ->middleware('perm:settings.view');
            Route::put('settings/security', [SecuritySettingsController::class, 'update'])
                ->middleware('perm:settings.edit');
            // WF-H11 — SMTP اختصاصی هر نصب + قالب‌های ایمیل.
            Route::get('settings/mail', [MailSettingsController::class, 'show'])
                ->middleware('perm:settings.view');
            Route::put('settings/mail', [MailSettingsController::class, 'update'])
                ->middleware('perm:settings.edit');
            Route::put('settings/mail/templates', [MailSettingsController::class, 'updateTemplates'])
                ->middleware('perm:settings.edit');
            Route::post('settings/mail/test', [MailSettingsController::class, 'test'])
                ->middleware('perm:settings.edit');
            // WF-L2 — وب‌هوک خروجی: رویدادهای نصب به URL دلخواه با امضا.
            //
            // راز هرگز برنمی‌گردد: `GET` فقط `secret_set` (بولین) می‌دهد و متنِ
            // راز فقط در پاسخِ `POST settings/webhook/secret` دیده می‌شود — یک
            // بار، بلافاصله بعد از ساخت.
            Route::get('settings/webhook', [WebhookSettingsController::class, 'show'])
                ->middleware('perm:settings.view');
            Route::put('settings/webhook', [WebhookSettingsController::class, 'update'])
                ->middleware('perm:settings.edit');
            Route::post('settings/webhook/secret', [WebhookSettingsController::class, 'rotateSecret'])
                ->middleware('perm:settings.edit');
            // تست از صف بیرون است تا نتیجه‌اش همین حالا برگردد — وگرنه دکمهٔ
            // «ارسال آزمایشی» فقط می‌گفت «در صف نشست».
            Route::post('settings/webhook/test', [WebhookSettingsController::class, 'test'])
                ->middleware('perm:settings.edit');
            // WF-H13 — پاک‌سازی دستی کش سایت (سراسری + per-page).
            Route::post('cache/purge', [CacheController::class, 'purge'])
                ->middleware('perm:settings.edit');
            Route::post('cache/purge-page', [CacheController::class, 'purgePage'])
                ->middleware('perm:pages.edit');
            // WF-H20 — تبِ «پشتیبان»: دستی + دانلود + وضعیت زمان‌بندی.
            //
            // دانلود `settings.edit` است نه `view`: یک dump کاملِ دیتابیس مانند
            // برون‌بری محتوا یک عملیات ممتاز است، وگرنه هر بیننده می‌توانست کل
            // داده را دانلود کند.
            Route::get('settings/backups', [BackupSettingsController::class, 'index'])
                ->middleware('perm:settings.view');
            Route::post('settings/backups', [BackupSettingsController::class, 'store'])
                ->middleware('perm:settings.edit');
            Route::get('settings/backups/{backup}/download', [BackupSettingsController::class, 'download'])
                ->middleware('perm:settings.edit');
            // WF-C2 — ریدایرکت ۳۰۱/۳۰۲.
            Route::get('redirects', [RedirectController::class, 'index'])->middleware('perm:settings.view');
            Route::post('redirects', [RedirectController::class, 'store'])->middleware('perm:settings.edit');
            Route::put('redirects/{redirect}', [RedirectController::class, 'update'])->middleware('perm:settings.edit');
            Route::delete('redirects/{redirect}', [RedirectController::class, 'destroy'])->middleware('perm:settings.edit');
            // WF-C6 — برون‌بری/درون‌بری محتوا (کانال رشد = مهاجر وردپرس).
            //
            // `perm:settings.edit` برای هر سه: برون‌بری کل محتوا + تنظیمات یک
            // عملیاتِ ممتاز است، نه خواندنِ معمولی (وگرنه هر `settings.view`
            // می‌توانست کلِ سایت را دانلود کند). درون‌بری هم نوشتن است.
            Route::get('content/export/json', [ExportController::class, 'exportJson'])->middleware('perm:settings.edit');
            Route::get('content/export/wxr', [ExportController::class, 'exportWxr'])->middleware('perm:settings.edit');
            Route::post('content/import/wxr', [ImportController::class, 'importWxr'])->middleware('perm:settings.edit');
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
            // WF-H9 — گزارش فعالیت محتوا (audit log): فهرست با فیلتر + بازگردانی نسخهٔ قبلی.
            // خواندن با `users.view` (ماژول `users`، نه `managers`)، بازگردانی با `pages.edit`.
            Route::get('activities', [ActivityController::class, 'index'])->middleware('perm:users.view');
            Route::post('activities/{activity}/restore', [ActivityController::class, 'restore'])->middleware('perm:pages.edit');
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
            // WF-M9 — شمارش کدهای بازیابیِ باقی‌مانده (بدون برگرداندن خودِ کدها).
            Route::get('profile/2fa/recovery-codes', [ProfileController::class, 'recoveryCodeCount']);
            Route::get('profile/sections', [ProfileController::class, 'sections']);
            // WF-H8 — تاریخچهٔ ورود (مکملِ نشست‌های فعال).
            Route::get('profile/login-history', [ProfileController::class, 'loginHistory']);
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
            // WF-M10 — خروجی CSV قبل از `tickets/{ticket}` می‌آید تا «export» به‌عنوان شناسه بلعیده نشود.
            Route::get('tickets/export', [TicketController::class, 'exportCsv'])->middleware('perm:tickets.view');
            Route::get('tickets/{ticket}', [TicketController::class, 'show'])->middleware('perm:tickets.view');
            Route::put('tickets/{ticket}', [TicketController::class, 'update'])->middleware('perm:tickets.edit');
            Route::delete('tickets/{ticket}', [TicketController::class, 'destroy'])->middleware('perm:tickets.delete');
            Route::post('tickets/{ticket}/messages', [TicketController::class, 'reply'])->middleware('perm:tickets.edit');
            // WF-H10 — فرم‌ساز: فرم‌ها + پاسخ‌ها + خروجی CSV.
            // `forms/schema` قبل از `forms/{form}` می‌آید تا به‌عنوان id بلعیده نشود.
            Route::get('forms', [FormController::class, 'index'])->middleware('perm:forms.view');
            Route::post('forms', [FormController::class, 'store'])->middleware('perm:forms.edit');
            Route::get('forms/schema', [FormController::class, 'schema'])->middleware('perm:forms.view');
            Route::get('forms/{form}', [FormController::class, 'show'])->middleware('perm:forms.view');
            Route::put('forms/{form}', [FormController::class, 'update'])->middleware('perm:forms.edit');
            Route::delete('forms/{form}', [FormController::class, 'destroy'])->middleware('perm:forms.delete');
            Route::get('forms/{form}/submissions', [FormController::class, 'submissions'])->middleware('perm:forms.view');
            Route::get('forms/{form}/submissions.csv', [FormController::class, 'exportCsv'])->middleware('perm:forms.view');
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
            // WF-M15 — تنظیماتِ کانالِ کاربر (chat_id تلگرام + خلاصهٔ روزانه).
            Route::put('notification-preferences/settings', [NotificationInboxController::class, 'updateSettings']);
            // E73 — رباتِ تلگرامِ نصب (توکن). رازِ نصب است نه تنظیمِ شخصی، پس
            // مثل K6.7 همان مجوزی را می‌خواهد که UI ادعا می‌کند: settings.edit.
            // توکنِ کامل هیچ‌وقت به مرورگر نمی‌رود (فقط masked).
            Route::get('notification-preferences/telegram-bot', [NotificationInboxController::class, 'botSettings'])
                ->middleware('perm:settings.edit');
            Route::put('notification-preferences/telegram-bot', [NotificationInboxController::class, 'updateBotToken'])
                ->middleware('perm:settings.edit');
            Route::post('notification-preferences/telegram-bot/test', [NotificationInboxController::class, 'testBotToken'])
                ->middleware('perm:settings.edit');
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
    // WF-H10 — فرم‌ساز عمومی: تعریف فرم با اسلاگ + ثبت پاسخ.
    Route::get('site/forms/{slug}', [SiteFormController::class, 'show'])->middleware('throttle:60,1');
    Route::post('site/forms/{slug}/submit', [SiteFormController::class, 'submit'])->middleware('throttle:contact');
    // تسک ۱.۱ — رندر عمومی ISR (فقط published).
    // فهرست سبک صفحات منتشرشده (sitemap.xml و llms.txt) — قبل از {path} تا سایه نیفتد.
    Route::get('site/pages', [SitePageController::class, 'index']);
    // صفحه خانه عمومی (homepage_page_id → home → جدیدترین) — قبل از {path}.
    Route::get('site/homepage', [SitePageController::class, 'homepage']);
    // WF-C7 — بایگانی بلاگ: فهرست صفحهبندیشده + فید RSS 2.0 (فقط published).
    Route::get('site/blog', [SitePageController::class, 'blog']);
    Route::get('site/blog/feed', [SitePageController::class, 'blogFeed']);
    // WF-M19 — دادهٔ خامِ فید RSS از همهٔ محتوای منتشرشده (نه فقط بلاگ)؛ فرانت XML را می‌سازد.
    Route::get('site/feed', [SitePageController::class, 'feed'])->middleware('throttle:60,1');
    Route::get('site/pages/{path}', [SitePageController::class, 'show'])->where('path', '.*');
    // WF-C2 — فهرست ریدایرکت‌های فعال برای میدل‌ور فرانت + شمارش بازدید.
    Route::get('site/redirects', [SiteRedirectController::class, 'index']);
    Route::post('site/redirects/hit', [SiteRedirectController::class, 'hit'])->middleware('throttle:60,1');
    // WF-H12 — ثبت بازدیدِ سایت عمومی: بدون احراز هویت، throttle ملایم.
    Route::post('site/analytics/view', [SiteAnalyticsController::class, 'store'])
        ->middleware('throttle:60,1');
    // WF-M14 — ثبت Web Vitals (LCP/INP/CLS): بدون احراز هویت، بدون PII.
    Route::post('site/analytics/vitals', [SiteAnalyticsController::class, 'vitals'])
        ->middleware('throttle:60,1');
    // کروم عمومی سایت (هدر/فوتر واقعی + برند) — سبک، rate-limit ملایم، بدون داده حساس.
    Route::get('site/chrome', [SiteChromeController::class, 'show'])->middleware('throttle:60,1');
    // کلید سراسری «کش سایت» — بدون کش، تا فرانت بداند کش بکند یا نه.
    Route::get('site/cache', [SiteCacheController::class, 'show']);
    // F4.1.F — توکن‌های یک قالبِ مشخص برای صفحهٔ پیش‌نمایش (`/preview/{slug}`).
    Route::get('site/theme/{slug}', [SiteThemePublicController::class, 'show'])->middleware('throttle:60,1');
    // WF-H2 — پیش‌نمایشِ عمومیِ پیش‌نویس با توکن امضاشده (بدون ورود، noindex).
    Route::get('site/share/{token}', [SitePageController::class, 'showShare'])
        ->where('token', '[A-Za-z0-9._-]+')
        ->middleware('throttle:60,1');
    // جستجوی عمومی سایت (فقط منتشرشده + throttle سفت + q حداقل ۲ نویسه).
    Route::get('site/search', [SiteSearchController::class, 'index'])->middleware('throttle:site-search');
    // تسک ۴.۳ — وضعیت عمومی سایت (سبک، بدون احراز هویت).
    Route::get('site/status', [SiteStatusController::class, 'show']);
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
        // K8.2 — review queue (operator).
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
        // K8.3 — yank / unyank (operator).
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
        // K8.5 — settlement ledger (operator).
        Route::get('ledger', function () use ($k8) {
            return $k8(fn () => response()->json(['data' => \App\Models\MarketLedgerEntry::query()
                ->latest()->paginate(50)]));
        })->middleware('role:operator');
        // K8.5 — MANUAL payouts (operator; execution happens off-system).
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
        // WF-H19 — داشبورد ناشر («ناشر من»)
        // ═══════════════════════════════════════════════════════════════════
        //
        // ناشر = کاربری که پلاگین‌های `source=market` را ثبت کرده. پروفایل و
        // تجمیع فروش/سهم از ردیف‌های واقعی بازار می‌آید (هیچ سهمی ساخته نمی‌شود)
        // و درخواست تسویه فقط یک ردیف pending می‌سازد؛ اجرا دستی می‌ماند.
        // پروفایل ناشر + پلاگین‌های من + فروش/سهم + تسویه‌های من.
        Route::get('me/publisher', function (\Illuminate\Http\Request $request) use ($k8) {
            return $k8(fn () => response()->json([
                'data' => app(\App\Services\Market\PublisherDashboardService::class)->forUser($request->user()),
            ]));
        })->middleware('perm:plugins.edit');
        // درخواست تسویه — ردیف pending برای کلیدِ خودِ ناشر.
        Route::post('me/payouts', function (\Illuminate\Http\Request $request) use ($k8) {
            return $k8(function () use ($request) {
                $validated = $request->validate([
                    'amount' => 'required|integer|min:1',
                    'currency' => 'nullable|string|max:10',
                    'note' => 'nullable|string|max:1000',
                    'publisher_key_id' => 'nullable|integer',
                ]);
                return response()->json([
                    'data' => app(\App\Services\Market\PublisherDashboardService::class)->requestPayout(
                        $request->user(),
                        (int) $validated['amount'],
                        $validated['note'] ?? null,
                        $validated['currency'] ?? 'IRT',
                        isset($validated['publisher_key_id']) ? (int) $validated['publisher_key_id'] : null,
                    ),
                ], 201);
            });
        })->middleware('perm:plugins.edit');
        // آپلود نسخهٔ جدید برای پلاگینِ خودِ ناشر (اعتبارسنجی + امضای همان کلید).
        Route::post('me/plugins/{plugin}/versions', function (\Illuminate\Http\Request $request, \App\Models\Plugin $plugin) use ($k8) {
            return $k8(function () use ($request, $plugin) {
                $validated = $request->validate(['file' => 'required|file|mimes:zip|max:20480']);
                return response()->json([
                    'data' => app(\App\Services\Market\ReviewService::class)
                        ->submitVersion($plugin, $validated['file'], $request->user()),
                ], 201);
            });
        })->middleware('perm:plugins.edit');
        // ═══════════════════════════════════════════════════════════════════
        // ECO6 — فروشگاه: کاتالوگ + checkout + تحویل ZIP
        // ═══════════════════════════════════════════════════════════════════
        //
        // تک‌خرید، بدون لایسنس/اشتراک (تصمیم قفل‌شدهٔ ECO6). درگاه پرداخت
        // بیرون از دامنه است (K8.6 معلق) ⇒ این مسیر **صادق** است: سفارش
        // ساخته می‌شود، پرداخت دستی تأیید می‌شود، بعد فایل یک‌بار تحویل
        // داده می‌شود. هیچ حالتِ «paid» جعلی ساخته نمی‌شود.
        // Catalog (خریدار): افزونه‌های تأییدشده و غیرِ yank، همراه وضعیت خرید/تحویل.
        // WF-H16 — جستجو (`q`)، دسته (`category`) و فیلتر قیمت (`price=free|paid`).
        // `meta.categories` فهرستِ دسته‌های موجود را از خود مانیفست می‌دهد.
        Route::get('catalog', function (\Illuminate\Http\Request $request) use ($k8) {
            return $k8(function () use ($request) {
                $catalog = app(\App\Services\Market\CatalogService::class);
                $plugins = $catalog->paginate([
                    'q' => $request->query('q'),
                    'category' => $request->query('category'),
                    'price' => $request->query('price'),
                ]);
                $plugins->getCollection()->transform(
                    fn (\App\Models\Plugin $p) => $catalog->present($p, $request->user())
                );
                return response()->json([
                    'data' => $plugins,
                    'meta' => ['categories' => $catalog->categories()],
                ]);
            });
        });
        // Item detail + وضعیت تحویل به‌ازای همین کاربر. WF-H16 — توضیح کامل،
        // اسکرین‌شات‌ها و فهرست نسخه‌ها/changelog از خودِ مانیفست.
        Route::get('catalog/{plugin}', function (\Illuminate\Http\Request $request, \App\Models\Plugin $plugin) use ($k8) {
            return $k8(function () use ($request, $plugin) {
                if ($plugin->source !== \App\Models\Plugin::SOURCE_MARKET
                    || $plugin->review_status !== \App\Models\Plugin::REVIEW_APPROVED
                    || (bool) $plugin->yanked) {
                    throw new \App\Services\Market\MarketException('این افزونه در فروشگاه در دسترس نیست.', 404);
                }
                return response()->json(['data' => app(\App\Services\Market\CatalogService::class)->detail($plugin, $request->user())]);
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
        // ═══════════════════════════════════════════════════════════════════
        // WF-H18 — امتیاز/نظرات آیتم بازار + شمار نصب فعال
        // ═══════════════════════════════════════════════════════════════════
        //
        // ثبت نظر فقط برای خریدارانِ تأییدشده (لایسنس فعال یا سفارش پرداخت‌شده)
        // و یک‌بار برای هر افزونه. بازبینی مرکزی با همان الگوی `role:operator`
        // که صف پلاگین/قالب دارد. هیچ امتیاز/نصبی ساخته نمی‌شود.
        // فهرست نظرهای تأییدشده + میانگین/شمار + نصب فعال (خریدار).
        Route::get('catalog/{plugin}/reviews', [MarketReviewController::class, 'index']);
        // ثبت امتیاز (۱..۵) و نظر — گیتِ خریدار در سرویس.
        Route::post('catalog/{plugin}/reviews', [MarketReviewController::class, 'store']);
        // صف بازبینی نظرها (اپراتور مرکزی).
        Route::get('reviews', [MarketReviewController::class, 'queue'])
            ->middleware('role:operator');
        Route::post('reviews/{review}/approve', [MarketReviewController::class, 'approve'])
            ->middleware('role:operator');
        Route::post('reviews/{review}/reject', [MarketReviewController::class, 'reject'])
            ->middleware('role:operator');
    });
});
