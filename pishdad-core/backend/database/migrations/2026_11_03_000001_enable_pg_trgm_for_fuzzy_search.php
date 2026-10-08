<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E85 — افزونهٔ pg_trgm برای تحملِ غلطِ املایی در فیلترهای لیست.
 *
 * ## چرا try/catch و نه مستقیم
 *
 * روی هاست‌هایی که کاربرِ دیتابیس superuser نیست، `CREATE EXTENSION` ممکن
 * است رد شود. این نباید نصب را بشکند: کد (`PersianText::whereFa`) اول
 * `pg_extension` را می‌خواند و اگر افزونه نبود فقط لایهٔ regex می‌ماند.
 * پس اینجا شکست یعنی «بدونِ فازی»، نه «نصبِ خراب».
 *
 * (از PG13 به بعد pg_trgm «مورداعتماد» است و با دسترسی CREATE روی دیتابیس
 * هم نصب می‌شود؛ ولی قولش را نمی‌دهیم.)
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function down(): void
    {
        // عمداً حذف نمی‌شود: افزونه ممکن است مالِ نصب‌های دیگرِ همان سرور
        // باشد و `DROP EXTENSION` روی دیتابیسِ مشترک بی‌ادبی است.
    }
};
