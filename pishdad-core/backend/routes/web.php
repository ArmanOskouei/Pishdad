<?php

use Illuminate\Support\Facades\Route;

/*
 * ریشهٔ بک‌اند.
 *
 * پیش از نصب: `InstallGuard` (میدل‌ورِ سراسری) همین‌طور به `/install` می‌فرستد؛
 * شرطِ زیر برای وقتی است که گارد خاموش شده باشد.
 *
 * پس از نصب: یک صفحهٔ موفقیتِ **دوزبانه** (فارسی + انگلیسی) با نشانیِ سایت.
 *
 * ⚠️ نشانیِ **پنلِ ادمین عمداً اینجا نیست** (درخواستِ صریحِ کاربر و دلیلش
 * امنیتی است). این صفحه عمومی است؛ کسی که آدرسِ پنل را می‌خواهد نباید از
 * صفحهٔ پیش‌فرضِ سرور پیدایش کند. آن نشانی فقط در `/install/done` می‌آید —
 * آن هم تنها برای کسی که همین حالا نصب را انجام داده.
 */
Route::get('/', function () {
    if (! \App\Http\Controllers\Install\InstallJournal::isInstalled()) {
        return redirect('/install');
    }

    return view('installed', [
        'site' => \App\Http\Controllers\Install\InstallController::siteUrl(),
    ]);
});
