<?php

declare(strict_types=1);

namespace App\Services\Plugins;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * K5.0-W — چرخهٔ عمرِ بارگذارِ کلاس، هم‌قدم با تغییر وضعیتِ افزونه.
 *
 * ## چرا این کلاس وجود دارد و چرا در `PluginAutoloader` نیست
 *
 * `PluginAutoloader` عمداً **بدون facade و بدون DB** نگه داشته شده تا با یک
 * `new` ساده تست‌شدنی بماند (همین قید در `registerPackages()` نوشته شده).
 * برای همین نمی‌تواند خودش `autoloadRoot()` را صدا بزند: آن روی دیسک و اشاره‌گر
 * نسخهٔ فعال می‌خواند و یعنی وابستگی به `PluginReleaseManager`.
 *
 * پس تصمیمِ «کدام ریشهٔ PSR-4 برای این slug» اینجا می‌افتد، و آن‌جا که هر دو
 * سرویس در دسترس‌اند.
 *
 * ## شکافی که این کلاس می‌بندد
 *
 * پیش از این، `PluginAutoloader` فقط در `boot()` — و آن هم فقط برای افزونه‌های
 * **داخلی** (`plugins/{slug}/`) — وصل بود. `PluginReleaseManager::autoloadPackages()`
 * و `PluginAutoloader::registerPackages()` هر دو نوشته و تست شده بودند ولی
 * **هیچ caller نداشتند**: یعنی افزونهٔ بازاریِ فعال هیچ‌وقت کلاسش بارگذاری
 * نمی‌شد. نتیجه `class_exists()` روی کلاسِ خودِ افزونه `false` بود و هر
 * نقطهٔ اتصالی که به کد افزونه تکیه می‌کرد می‌مرد.
 *
 * حالا `activate()` ریشه را ثبت می‌کند و `deactivate()` ثبت را برمی‌دارد، پس
 * قراردادِ «افزونهٔ غیرفعال هیچ مسیری ندارد» فقط روی DB نیست — روی کلاس‌ها هم
 * هست.
 *
 * ⚠️ PHP کلاسِ بارگذاریده‌شده را از حافظه خارج نمی‌کند. پس «کلاس‌ها متوقف
 * شدند» یعنی **کلاس‌هایی که هنوز load نشده‌اند** دیگر resolve نمی‌شوند. این هم
 * همان چیزی است که تست می‌سنجد و همان چیزی که در کد واقعی فرق دارد: فراخوانیِ
 * بعدیِ کد افزونه.
 *
 * @see PluginAutoloader
 * @see PluginReleaseManager::autoloadRoot()
 */
class PluginLifecycle
{
    public function __construct(
        private PluginAutoloader $autoloader,
        private PluginReleaseManager $releases,
    ) {}

    /**
     * افزونه فعال شد ⇒ ریشهٔ PSR-4 نسخهٔ فعالش باید بارگذار شود.
     *
     * **fail-soft، عمداً.** نبودِ ریشه یعنی یکی از این دو:
     * بستهٔ فقط-فرانت‌اند (`Laravel/src` ندارد ⇒ هیچ کلاسی هم ندارد)، یا
     * افزونه‌ای که هنوز روی دیسک نیست. هیچ‌کدام خطای فعال‌سازی نیستند.
     *
     * ولی یک نکتهٔ ظریف: در آن حالت **ثبت قبلی هم برداشته می‌شود**. وگرنه ارتقای
     * یک بسته به نسخه‌ای که `Laravel/src` ندارد ریشهٔ نسخهٔ قبلی را زنده
     * می‌گذاشت و کدِ نسخهٔ قبلی اجرا می‌شد — دقیقاً همان باگی که K5.11
     * (ارتقا فقط DB را عوض می‌کرد) برای `PluginRouter` درست کرد.
     *
     * @return bool آیا ریشه‌ای برای بارگذاری پیدا شد؟
     */
    public function activate(string $slug): bool
    {
        $slug = trim($slug);

        if ($slug === '') {
            return false;
        }

        $root = $this->releases->autoloadRoot($slug);

        if ($root === null) {
            $this->autoloader->unregister($slug);

            return false;
        }

        try {
            $this->autoloader->register(
                $slug,
                $root,
                PluginAutoloader::NAMESPACE_ROOT.Str::studly($slug).'\\',
            );
        } catch (InvalidArgumentException) {
            // ریشه یا prefix بی‌اعتبار است. ثبت نیمه‌کاره بهتر از ثبتِ هیچ چیز
            // نیست، ولی نباید یک slug ناسالم را در بارگذار جا بگذارد.
            $this->autoloader->unregister($slug);

            return false;
        }

        return true;
    }

    /**
     * افزونه غیرفعال شد ⇒ کلاس‌هایش دیگر نباید resolve شوند.
     *
     * ثبت را برمی‌دارد ولی فایل‌ها را **نمی‌خواند**: حذف فایل کارِ deinstall است،
     * نه غیرفعال‌سازی. اینجا فقط دسترسی به کد قطع می‌شود.
     */
    public function deactivate(string $slug): void
    {
        if (trim($slug) === '') {
            return;
        }

        $this->autoloader->unregister($slug);
    }

    /**
     * وضعیت ثبتِ یک slug — برای صفحهٔ تشخیص عیب و تست.
     */
    public function isLoaded(string $slug): bool
    {
        return $this->autoloader->isRegistered($slug);
    }
}