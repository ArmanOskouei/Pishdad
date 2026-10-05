<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| مرحله ۵ (تسک‌های ۵.۳ تا ۵.۷) — جدول‌های عملیاتیِ **هسته**.
| - تیکت‌ها + پیام‌ها (۵.۳)
| - پلاگین‌ها + رجیستری هوک‌ها (۵.۴)
| - notifications استاندارد لارavel (اعلان داخلی تیکت)
|
| ## J5 — این فایل نصفه شد
|
| این فایل قبلاً **دو دستهٔ ناموجود** را با هم می‌ساخت: جدول‌های هسته (تیکت، پلاگین،
| اعلان) و جدول‌های مرکزی (heartbeat نصب‌ها، لاگِ متمرکز، رجیستریِ نسخه‌ها، رول‌اوتِ
| پلکانی و آیتمش، کلیدِ سخت‌افزاری، توکنِ نصب، فعالیتِ اپراتور، kill-switch، به‌همراهِ
| یک `Schema::table` که دو ستونِ `install_token` و `current_version` را به جدولِ
| کلاینت اضافه می‌کرد).
|
| آن بخش به بستهٔ افزونه منتقل شد: سه migration در
| `plugins/central/Laravel/database/migrations/` با نام‌های
| `2026_09_24_000001_create_central_billing_tables`، `2026_09_25_000001_create_central_chabokan_tables`
| و `2026_09_26_000001_create_central_ops_tables` — و همان‌جا دو ستونِ
| `install_token`/`current_version` داخلِ خودِ `CREATE` ادغام شدند تا نصبِ تازه یک
| ستون اضافه نگیرد.
|
| دلیل اینکه «منتقل» شد و نه «کپی»: نصبِ تازهٔ هسته باید **صفر** جدولِ مرکزی داشته
| باشد. تا وقتی این فایل آن‌ها را می‌ساخت، یک مشتریِ عمومی — که
| `plugins/central/` روی دیسکش نیست — دیتابیسی پر از جدولِ افزونه‌ای می‌گرفت که هرگز
| اجرا نمی‌شود. یعنی schema با بسته‌های موجود روی دیسک ناهمخوان بود.
|
| نصب‌های موجود چه می‌شوند: `migrations` این فایل را از قبل دارد، پس حذفِ بخشِ
| مرکزی چیزی را حذف نمی‌کند؛ و `2026_10_05_000001` (هنوز در core) renameِ
| باقی‌مانده را انجام می‌دهد. بعد از آن migrationهای افزونه با guardِ
| `Schema::hasTable` بی‌اثر می‌مانند.
|
| ⚠️ این توضیح عمداً **نامِ کاملِ هیچ جدولِ مرکزی** را با backtick نقل نمی‌کند.
| `PluginBoundaryTableSweepTest` متنِ خامِ فایل را می‌گردد و نامِ جدول را حتی
| داخلِ docblock می‌شمارد — پس نقل‌قولِ دقیق، مرز را بی‌دلیل قرمز می‌کرد و
| راهِ فرارش دستکاریِ توضیح بود، که بدترین راهِ ممکن است.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 200);
            $table->string('status', 20)->default('open'); // open|pending|closed
            $table->string('priority', 20)->default('normal'); // low|normal|high
            $table->string('contact_name', 150)->nullable();
            $table->string('contact_email', 200)->nullable();
            $table->string('source', 20)->default('panel'); // panel|contact
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_type', 20)->default('admin'); // admin|operator|contact
            $table->text('body');
            $table->foreignId('attachment_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->timestamps();
            $table->index(['ticket_id', 'created_at']);
        });

        Schema::create('plugins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('slug', 100);
            $table->string('version', 30)->default('1.0.0');
            $table->string('previous_version', 30)->nullable();
            $table->boolean('active')->default(false);
            $table->boolean('system')->default(false);
            $table->boolean('signature_valid')->default(false);
            $table->string('checksum', 64)->nullable(); // sha256 فایل ZIP
            $table->jsonb('manifest')->nullable();
            $table->string('path')->nullable();
            $table->timestamps();
            $table->unique('slug');
        });

        Schema::create('plugin_hooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plugin_id')->constrained('plugins')->cascadeOnDelete();
            $table->string('hook', 100);
            $table->string('handler', 200)->nullable();
            $table->timestamps();
            $table->index(['plugin_id', 'hook']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('plugin_hooks');
        Schema::dropIfExists('plugins');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
    }
};
