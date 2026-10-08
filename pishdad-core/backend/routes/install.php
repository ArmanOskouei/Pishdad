<?php

use App\Http\Controllers\Install\InstallController;
use Illuminate\Support\Facades\Route;

/*
 * F1.1.F — مسیرهای نصب.
 *
 * عمداً بیرون از گروه `web` لود می‌شود (bootstrap/app.php → then:) تا بدون
 * APP_KEY هم کار کند: نه EncryptCookies، نه session، نه CSRF. به‌جای CSRF،
 * هر POST به `install_token` سمت‌سرور نیاز دارد (InstallJournal).
 */

Route::prefix('install')->name('install.')->group(function () {
    Route::get('/', [InstallController::class, 'start'])->name('start');

    // E20 — سوییچر زبان نصب (fa/en). بیرون از ترتیب گام‌هاست و توکن نمی‌خواهد.
    Route::get('lang/{lang}', [InstallController::class, 'switchLang'])->name('lang');

    Route::get('preflight', [InstallController::class, 'preflight'])->name('preflight');
    Route::post('preflight', [InstallController::class, 'preflightConfirm'])->name('preflight.confirm');

    Route::get('database', [InstallController::class, 'database'])->name('database');
    Route::post('database', [InstallController::class, 'databaseSave'])->name('database.save');

    // E18 — آماده‌سازی خودکار (گام ۳ + ۴) بلافاصله بعد از اتصال موفق.
    Route::get('prepare', [InstallController::class, 'prepare'])->name('prepare');
    Route::post('prepare', [InstallController::class, 'prepareRun'])->name('prepare.run');
    // E19 — همان کار، ولی در دو فاز جدا برای نوار پیشرفت (JS) + خطای درون‌صفحه‌ای.
    Route::post('prepare/key', [InstallController::class, 'prepareKey'])->name('prepare.key');
    Route::post('prepare/migrate', [InstallController::class, 'prepareMigrate'])->name('prepare.migrate');

    Route::get('app-key', [InstallController::class, 'appKey'])->name('app-key');
    Route::post('app-key', [InstallController::class, 'appKeyGenerate'])->name('app-key.generate');

    Route::get('migrate', [InstallController::class, 'migrate'])->name('migrate');
    Route::post('migrate', [InstallController::class, 'migrateRun'])->name('migrate.run');

    Route::get('superadmin', [InstallController::class, 'superadmin'])->name('superadmin');
    Route::post('superadmin', [InstallController::class, 'superadminSave'])->name('superadmin.save');

    Route::get('finalize', [InstallController::class, 'finalizeShow'])->name('finalize');
    Route::post('finalize', [InstallController::class, 'finalize'])->name('finalize.run');

    Route::get('done', [InstallController::class, 'done'])->name('done');
});
