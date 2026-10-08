<?php

namespace App\Http\Controllers\Install;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * F1.1.B — نگهبان نصب.
 *
 * عمداً هیچ وابستگی به middleware گروه `web` ندارد (نه session، نه cookie
 * رمزنگاری‌شده، نه CSRF) تا با `APP_KEY` خالی هم ۲۰۰ بدهد؛ فقط فایل می‌خواند.
 *
 * - نصب‌نشده + مسیر غیرنصب → ریدایرکت به /install (یا ۵۰۳ برای api/JSON).
 * - نصب‌شده + مسیر /install → ۴۰۴.
 * - `/up` همیشه آزاد است (health).
 *
 * در محیط تست به‌صورت پیش‌فرض خاموش است تا ۸۰۰ تست موجود نشکنند؛ تست‌های
 * نصب با `config('installer.guard_enabled', true)` مستقیم صدایش می‌زنند.
 *
 * ## ⭐ چرا `install.lock` تنها منبع حقیقت نیست
 *
 * نسخهٔ اول همین گارد فقط `install.lock` را می‌پرسید. نتیجه یک **خاموشی
 * خودساخته** بود: هر نصبی که با `git clone` + `docker compose` بالا آمده
 * (یعنی دقیقاً مسیری که README وعده می‌دهد) هرگز `install.lock` نمی‌سازد،
 * چون آن را فقط `pishdad:install` می‌نویسد. یعنی محصولِ سالم و کامل، به‌خاطر نبودِ
 * یک فایل، ۵۰۳ می‌داد و همه‌چیز را به `/install` می‌فرستاد.
 *
 * پس «نصب‌نشده» باید **واقعاً** سنجیده شود، نه از روی یک نشانهٔ اختیاری:
 * نصبِ ازپیش‌موجود یا `APP_KEY` دارد یا کاربر دارد یا مهاجرت‌ها اجرا شده‌اند.
 * نبودِ قفل، به‌تنهایی **دلیلِ نصب‌نبودن نیست**.
 */
class InstallGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $enabled = config('installer.guard_enabled');
        if ($enabled === null) {
            $enabled = ! app()->runningUnitTests();
        }
        if (! $enabled) {
            return $next($request);
        }

        $isInstallPath = $request->is('install') || $request->is('install/*');
        if ($request->is('up')) {
            return $next($request);
        }

        // E17 — نصبِ درحال‌انجام (ژورنالِ بدون‌قفل با گامِ ناتمام) هرگز
        // «نصب‌شده» نیست، حتی اگر گام ۳ قبلاً APP_KEY را در .env نوشته باشد.
        // بدون این استثنا، `looksAlreadyInstalled()` از ریکوئستِ بعد از گام ۳
        // به‌بعد کل `/install` را ۴۰۴ می‌کرد و نصب همان‌جا می‌مرد.
        // E21 — صفحهٔ موفقیتِ بعدِ قفل (`install/done`) باید باز بماند:
        // `done()` عمداً فقط *بعد* از نصب معنا دارد و ریدایرکتِ finalize
        // به آن می‌رود. سوییچر زبان هم روی همان صفحه باید کار کند (توکن
        // نمی‌خواهد و بی‌خطر است). بقیهٔ `/install` در حالتِ نصب‌شده ۴۰۴ می‌مانند.
        if (InstallJournal::isInstalled() || (! $this->installInProgress() && $this->looksAlreadyInstalled())) {
            if ($isInstallPath && ! $request->is('install/done', 'install/lang/*')) {
                abort(404);
            }

            return $next($request);
        }

        if ($isInstallPath) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'نصب هنوز انجام نشده است. اول نصب را کامل کنید.',
                'install_url' => '/install',
            ], 503);
        }

        return redirect('/install');
    }

    /**
     * آیا این محیط از قبل یک نصبِ واقعی دارد؟
     *
     * هر سه بررسی **fail-safe** است: اگر دیتابیس در دسترس نباشد (که در
     * preflight خودِ نصب طبیعی است) `false` می‌دهد تا مسیر نصب باز بماند.
     * عمداً `try/catch` دارد: گارد نباید خودش منشأ خطای ۵۰۰ باشد.
     *
     * `installer.installed_probe` یک درزِ عمدی است: کلوسر برمی‌گرداند. دلیلش
     * این است که «نصبِ واقعاً خالی» در یک تست با `RefreshDatabase` قابل ساخت
     * نیست (مهاجرت‌ها همیشه اجرا شده‌اند) و بدون این درز، تنها راهِ تست‌کردنِ
     * حالتِ «نصب‌نشده» این می‌شد که کل دیتابیسِ تست را خالی کنیم. اپراتور هم
     * می‌تواند وقتی probe اشتباه جواب داد (مثلاً DB در بوت در دسترس نیست)
     * آن را از config بازنویسی کند.
     */
    /**
     * آیا یک نصبِ وبِ ناتمام در جریان است؟
     *
     * ژورنالِ موجودِ بدون‌قفل که هنوز گامِ ناتمامی دارد یعنی کاربر وسط
     * `/install` است (یا نصب نیمه‌کاره مانده و resume می‌خواهد). در این
     * حالت هیوریستیکِ `looksAlreadyInstalled()` — که صرفِ ست‌بودن APP_KEY
     * را نصب‌شده می‌داند — نباید اعمال شود، چون خودِ نصب‌کننده APP_KEY را
     * در گام ۳ می‌سازد (E17).
     */
    private function installInProgress(): bool
    {
        if (InstallJournal::isInstalled()) {
            return false;
        }
        if (! is_file(InstallJournal::journalPath())) {
            return false;
        }

        return InstallJournal::firstIncompleteStep() !== null;
    }

    private function looksAlreadyInstalled(): bool
    {
        $probe = config('installer.installed_probe');
        if (is_callable($probe)) {
            return (bool) $probe();
        }

        if ((string) config('app.key') !== '') {
            return true;
        }

        try {
            if (! Schema::hasTable('migrations')) {
                return false;
            }

            // یک مهاجرتِ اجراشده یعنی این دیتابیس قبلاً راه‌اندازی شده.
            if (DB::table('migrations')->count() > 0) {
                return true;
            }

            return Schema::hasTable('users') && User::query()->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
