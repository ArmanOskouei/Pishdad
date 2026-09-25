<?php

use App\Http\Controllers\Api\Central\ClientController;
use App\Http\Controllers\Api\Central\CentralDashboardController;
use App\Http\Controllers\Api\Central\ChabokanController;
use App\Http\Controllers\Api\Central\GatewaySettingsController;
use App\Http\Controllers\Api\Central\InvoiceController as CentralInvoiceController;
use App\Http\Controllers\Api\Central\MonitoringController;
use App\Http\Controllers\Api\Central\OperatorController;
use App\Http\Controllers\Api\Central\PaymentController as CentralPaymentController;
use App\Http\Controllers\Api\Central\PlanController;
use App\Http\Controllers\Api\Central\ReviewController;
use App\Http\Controllers\Api\Central\SubscriptionAdminController;
use App\Http\Controllers\Api\Central\UpdateController;
use App\Http\Controllers\Api\Internal\HeartbeatController;
use App\Http\Controllers\Api\V1\Admin\BlockSchemaController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\LayoutController;
use App\Http\Controllers\Api\V1\Admin\ManagerController;
use App\Http\Controllers\Api\V1\Admin\MediaController;
use App\Http\Controllers\Api\V1\Admin\PageController;
use App\Http\Controllers\Api\V1\Admin\PluginController;
use App\Http\Controllers\Api\V1\Admin\ProfileController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SiteSettingsController;
use App\Http\Controllers\Api\V1\Admin\SidePresetController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionController;
use App\Http\Controllers\Api\V1\Admin\ThemeController;
use App\Http\Controllers\Api\V1\Admin\TicketController;
use App\Http\Controllers\Api\V1\Admin\WidgetSchemaController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContactTicketController;
use App\Http\Controllers\Api\V1\Site\PageController as SitePageController;
use App\Http\Controllers\Api\V1\Site\SearchController as SiteSearchController;
use App\Http\Controllers\Api\V1\Site\SiteChromeController;
use App\Http\Controllers\Api\V1\Site\SiteStatusController;
use App\Http\Controllers\Api\V1\SubscriptionStatusController;
use App\Http\Controllers\Api\V1\UiSettingsController;
use App\Http\Controllers\Api\Webhooks\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — contract-first (see openapi/openapi.yaml)
| Paths here are the contract of DEV-TASKS tasks 0.1 / 0.2 / 2.1 / 2.2 /
| 2.3 / 2.4 / 3.2 / 3.3 / 4.1 / 4.2 / 4.3.
| Do NOT add endpoints outside the task scope.
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
        Route::post('2fa/verify', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:auth-login');
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
        Route::prefix('admin')->group(function () {
            Route::get('pages', [PageController::class, 'index']);
            Route::post('pages', [PageController::class, 'store']);
            Route::get('pages/{page}', [PageController::class, 'show']);
            Route::put('pages/{page}', [PageController::class, 'update']);
            Route::delete('pages/{page}', [PageController::class, 'destroy'])->middleware('perm:pages.delete');
            Route::post('pages/{page}/publish', [PageController::class, 'publish']);
            Route::post('pages/{page}/restore/{revision}', [PageController::class, 'restore']);
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

            // تسک ۱.۲ — اشتراک من.
            Route::get('subscription', [SubscriptionController::class, 'show']);
            Route::get('subscription/invoices', [SubscriptionController::class, 'invoices']);
            Route::post('subscription/invoices/{invoice}/pay', [SubscriptionController::class, 'pay']);
            // دسته ۱ (برابری UI/UX): مقایسه پلن‌ها + فاکتور مابه‌التفاوت ارتقا.
            Route::get('subscription/plans', [SubscriptionController::class, 'plans']);
            Route::post('subscription/change-plan', [SubscriptionController::class, 'changePlan']);

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
Route::get('plugins', [PluginController::class, 'index'])->middleware('perm:plugins.view');
Route::post('plugins/validate', [PluginController::class, 'validatePackage'])->middleware('perm:plugins.edit');
Route::post('plugins/upload', [PluginController::class, 'upload'])->middleware('perm:plugins.edit');
            Route::get('plugins/{plugin}', [PluginController::class, 'show'])->middleware('perm:plugins.view');
            Route::post('plugins/{plugin}/activate', [PluginController::class, 'activate'])->middleware('perm:plugins.edit');
            Route::post('plugins/{plugin}/deactivate', [PluginController::class, 'deactivate'])->middleware('perm:plugins.edit');
            Route::post('plugins/{plugin}/upgrade', [PluginController::class, 'upgrade'])->middleware('perm:plugins.edit');
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
});

// تسک ۱.۳/۱.۴ — پنل مرکزی: فقط operator (+ سوپرادمین مخفی).
Route::prefix('central')->middleware(['auth:sanctum', 'central.operator'])->group(function () {
    Route::apiResource('plans', PlanController::class);
    Route::get('invoices', [CentralInvoiceController::class, 'index']);
    Route::post('invoices', [CentralInvoiceController::class, 'store']);
    Route::put('invoices/{invoice}/status', [CentralInvoiceController::class, 'updateStatus']);
    // دسته ۱ (برابری UI/UX): دکمه یادآوری per-row فاکتور پرداخت‌نشده.
    Route::post('invoices/{invoice}/remind', [CentralInvoiceController::class, 'remind']);
    Route::get('payments', [CentralPaymentController::class, 'index']);
    Route::get('subscriptions/suspended', [SubscriptionAdminController::class, 'suspended']);
    Route::post('subscriptions/{subscription}/suspend', [SubscriptionAdminController::class, 'suspend']);
    Route::post('subscriptions/{subscription}/resume', [SubscriptionAdminController::class, 'resume']);
    Route::post('subscriptions/{subscription}/delete', [SubscriptionAdminController::class, 'destroy']);
    Route::get('gateway-settings', [GatewaySettingsController::class, 'show']);
    Route::put('gateway-settings', [GatewaySettingsController::class, 'update']);
    // دسته ۱ (برابری UI/UX): تست اتصال درگاه (Stub نمایشی).
    Route::post('gateway-settings/test', [GatewaySettingsController::class, 'test']);

    Route::get('clients', [ClientController::class, 'index']);
    Route::post('clients', [ClientController::class, 'store']);
    Route::get('clients/{client}', [ClientController::class, 'show']);
    Route::post('clients/{client}/upgrade-tier', [ClientController::class, 'upgradeTier']);
    Route::post('clients/{client}/support-token', [ClientController::class, 'supportToken']);
    Route::post('clients/{client}/support-token/consume', [ClientController::class, 'consumeSupportToken']);
    Route::post('clients/{client}/suspend', [ClientController::class, 'suspend']);
    Route::post('clients/{client}/resume', [ClientController::class, 'resume']);
    Route::post('clients/{client}/delete', [ClientController::class, 'destroy']);
    Route::get('clients/{client}/backups', [ClientController::class, 'backups']);
    Route::post('clients/{client}/backup', [ClientController::class, 'backup']);
    Route::post('clients/{client}/restore', [ClientController::class, 'restore']);

    // تسک ۳.۲ — داشبورد مرکزی.
    Route::get('dashboard/stats', [CentralDashboardController::class, 'stats']);
    Route::get('dashboard/charts', [CentralDashboardController::class, 'charts']);
    // دسته ۱ (برابری UI/UX): پرونده‌های باز قابل اکشن.
    Route::get('dashboard/open-cases', [CentralDashboardController::class, 'openCases']);

    // تسک ۳.۳ — مدیریت چابکان (Stub بی‌خطر + لاگ audit).
    Route::get('chabokan/wallet', [ChabokanController::class, 'wallet']);
    // دسته UIUX (افزودنی): شارژ کیف پول (ثبت درخواست) + ساخت سرویس آماده.
    Route::post('chabokan/wallet/charge', [ChabokanController::class, 'charge']);
    Route::post('chabokan/services/provision', [ChabokanController::class, 'provision']);
    Route::get('chabokan/services', [ChabokanController::class, 'services']);
    Route::post('chabokan/services/{name}/start', [ChabokanController::class, 'start']);
    Route::post('chabokan/services/{name}/stop', [ChabokanController::class, 'stop']);
    Route::post('chabokan/services/{name}/restart', [ChabokanController::class, 'restart']);
    Route::post('chabokan/services/{name}/resize', [ChabokanController::class, 'resize']);
    Route::get('chabokan/deployments', [ChabokanController::class, 'deployments']);
    Route::post('chabokan/redeploy', [ChabokanController::class, 'redeploy']);
    Route::get('chabokan/reconciliation', [ChabokanController::class, 'reconciliation']);

    // تسک ۵.۳ — پاسخ اپراتور روی تیکت مشتری.
    Route::post('tickets/{ticket}/reply', [TicketController::class, 'operatorReply']);

    // تسک ۵.۵ — مانیتورینگ مرکزی.
    Route::get('monitoring/health', [MonitoringController::class, 'health']);
    Route::get('monitoring/logs', [MonitoringController::class, 'logs']);
    // دسته UIUX (افزودنی): آپتایم روزانه هر نصب.
    Route::get('monitoring/uptime', [MonitoringController::class, 'uptime']);

    // تسک ۵.۶ — آپدیت‌های پلکانی.
    Route::get('updates', [UpdateController::class, 'index']);
    // دسته UIUX (افزودنی): رول‌اوت جاری + توقف/ادامه (قبل از installs تا سایه نیفتد).
    Route::get('updates/rollouts/current', [UpdateController::class, 'current']);
    Route::post('updates/rollouts/{rollout}/pause', [UpdateController::class, 'pause']);
    Route::post('updates/rollouts/{rollout}/resume', [UpdateController::class, 'resume']);
    Route::post('updates/rollout', [UpdateController::class, 'rollout']);
    Route::post('updates/installs/{install}/rollback', [UpdateController::class, 'rollback']);

    // مورد ۶ — صف بازبینی پلاگین/قالب.
    Route::get('review/queue', [ReviewController::class, 'queue']);
    Route::post('review/{type}/{id}/approve', [ReviewController::class, 'approve']);
    Route::post('review/{type}/{id}/reject', [ReviewController::class, 'reject']);

    // تسک ۵.۷ — پروفایل اپراتور.
    // دسته UIUX (افزودنی): صدور secret برای ثبت کلید با QR.
    Route::get('operator/2fa/hardware/setup', [OperatorController::class, 'hardwareSetup']);
    Route::post('operator/2fa/hardware', [OperatorController::class, 'registerHardwareKey']);
    Route::post('operator/setup-tokens', [OperatorController::class, 'createSetupToken']);
    Route::post('operator/kill-switch', [OperatorController::class, 'killSwitch']);
    Route::get('operator/activity', [OperatorController::class, 'activity']);
});

// تسک ۵.۵ — heartbeat نصب‌ها (توکن per-install + HMAC، بدون سشن کاربری).
Route::post('internal/heartbeat', [HeartbeatController::class, 'store'])->middleware('throttle:60,1');

// تسک ۱.۳ — وب‌هوک درگاه (HMAC، بدون احراز هویت کاربری).
Route::post('webhooks/payment', [PaymentWebhookController::class, 'handle']);
