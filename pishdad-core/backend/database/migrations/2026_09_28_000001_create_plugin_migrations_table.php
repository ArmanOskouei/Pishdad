<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K5.6 گام ۳ — سابقهٔ اجرای migration پلاگین.
 *
 * جدول **مرکزی** است، نه متعلق به افزونه: خودِ افزونه نباید بتواند سابقه‌اش را
 * پاک یا دستکاری کند، چون رکورد دوباره اجرا نشدن همان چیزی است که دوباره اجرا شدن
 * را جلو می‌گیرد. به همین دلیل مثل بقیهٔ جدول‌های هسته پیشوند `pishdad_` دارد
 * (K7.5) و ساختش در اختیار افزونه نیست.
 *
 * این جدول عمداً به `migrations` لاراول وصل نیست: آن جدول برای migrationهای
 * خودِ اپ است و `migrate:rollback` هسته نباید بتواند دیتای افزونه را برگرداند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pishdad_plugin_migrations', function (Blueprint $table): void {
            $table->id();

            // `(slug, migration)` یکتاست: یک فایل در یک افزونه فقط یک‌بار اجرا
            // می‌شود، ولی دو افزونه می‌توانند فایل هم‌نام داشته باشند — بدون این
            // کلید، فایل هم‌نامِ افزونهٔ دوم به‌اشتباه «قبلاً اجرا شده» می‌شد.
            $table->string('slug', 40);
            $table->string('migration', 191);
            $table->timestamp('applied_at');

            $table->unique(['slug', 'migration']);
            $table->index('applied_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pishdad_plugin_migrations');
    }
};
