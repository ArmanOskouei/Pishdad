<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| J5 — جدول‌های صورت‌حساب و کلاینت افزونهٔ مرکزی.
|
| ## چرا این فایل «نام خام» دارد و نه `central_*`
|
| `PluginMigrationRunner` نامِ هر جدول را با prefix اسلاگ می‌نویسد، و اسلاگِ
| این بسته `central` است. پس اینجا `clients` نوشته می‌شود و روی دیسک
| `central_clients` ساخته می‌شود — دقیقاً مثل `PluginDbContract::prefixed()`.
|
| نوشتنِ `central_clients` اینجا دو فاجعه می‌ساخت: `verify()` آن را «جدولِ اعلام‌نشده»
| می‌گرفت (مانیفست نام خام اعلام می‌کند) و `rewrite()` دو بار prefix می‌کرد و
| `central_central_clients` می‌ساخت.
|
| ## چرا `hasTable` guard دارد
|
| دو مسیر به این جدول‌ها می‌رسد و هر دو باید بی‌صدا کار کنند:
|  · **نصب تازه** — جدول‌ها نیست، ساخته می‌شوند.
|  · **نصبِ موجود** — core تا `2026_10_05` این جدول‌ها را خودش ساخته بود (بعضی با
|    نامِ بدون prefix و بعد rename). آن‌وقت `Schema::create` روی جدولِ موجود خطا
|    می‌دهد و فعال‌سازیِ افزونه می‌شکند.
|
| پس guard لازم است و `Schema::hasTable` هم دقیقاً همان چیزی است که نگهبان
| `PluginMigrator` بعد از اجرا دوباره با `missingTables()` می‌سنجد.
|
| ## چرا `FK` به جدولِ **هسته** حذف شد
|
| الگوی بازنویسی، هر فراخوانیِ `constrained` را هم prefix می‌کند — حتی وقتی
| آرگومانش یک جدولِ هسته باشد. نتیجه می‌شد ارجاع به `central_users`: جدولی که وجود
| ندارد و افزونه حق ساختنش را ندارد (deny listِ `PluginDbContract::CORE_TABLES`).
| پس ستون می‌ماند و قیدِ خارجی نمی‌ماند. همین تصمیم را
| `plugins/subscription/.../2026_10_14_000001` هم گرفته (`owner_user_id` بدون
| قید). رابطهٔ «کاربرِ نصب» معنای هسته‌ای دارد و در سطح دیتابیس enforce
| نمی‌شود — که برای مرزِ افزونه هم درست است.
|
| ## ⚠️ این فایل **کامنت‌کور** است — و این یعنی نوشتنِ نامِ جدول در توضیح خطرناک است
|
| `PluginMigrationRunner` متنِ فایل را با regex می‌خواند و **به کامنت‌ها نگاه
| نمی‌کند**. یعنی اگر در این docblock بنویسی `constrained` روی یک نامِ
| مشخص (با کوتیشن)، آن نام «ارجاع» شمرده می‌شود و `verify()` می‌گوید
| `migration.undeclared_table` — و کلِ نصبِ افزونه رد می‌شود، حتی وقتی کد سالم
| است. برای همین اینجا هیچ نامِ جدولی داخلِ کوتیشن نیست و هرجا لازم بوده با
| backtick و بی‌کوتیشن آمده. همین قاعده دلیلِ آن است که `verify()` در تست هم
| اجرا می‌شود: یک توضیحِ بی‌خطر نباید بتواند نصب را بشکند، ولی اگر بشکند بهتر
| است با پیامِ روشن بشکند تا با یک `Undefined table` وسطِ تست.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plans')) {
            Schema::create('plans', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('tier', 20)->default('budget'); // budget|standard|enterprise
                $table->unsignedBigInteger('price_monthly')->default(0);
                $table->unsignedBigInteger('price_yearly')->default(0);
                $table->string('currency', 10)->default('IRT');
                $table->jsonb('features')->default('[]');
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        // ⚠️ `install_token` و `current_version` قبلاً در core
        // (`2026_09_26_000001`) با `Schema::table` اضافه می‌شدند. اینجا در همان
        // `CREATE` آمده‌اند تا نصبِ تازه یک ستون اضافه نگیرد. برای نصبِ موجود
        // هم درست است: آن ستون‌ها از قبل هستند و guard پایین از `CREATE` رد می‌شود.
        if (! Schema::hasTable('clients')) {
            Schema::create('clients', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('domain')->nullable();
                $table->string('tier', 20)->default('budget');
                $table->string('status', 20)->default('active'); // active|suspended|deleted
                $table->unsignedBigInteger('owner_user_id')->nullable();
                $table->string('site_id')->unique();
                $table->string('install_token', 64)->nullable()->unique();
                $table->string('current_version', 30)->nullable();
                $table->jsonb('health')->nullable();
                $table->timestamps();
                $table->index(['tier', 'status']);
            });
        }

        // حالتِ سوم که guardِ بالا نمی‌گیرد: نصبی که `2026_09_26_000001` رویش
        // اجرا شده (پس `central_clients` هست) ولی **قبل** از آن اضافه شدنِ این
        // دو ستون — یعنی نسخه‌ای که migrationِ قدیمی اجرا نشده بود. آن‌وقت جدول
        // هست و `CREATE` رد می‌شود، ولی ستون‌ها جا نمانده‌اند. بدون این شاخه،
        // `central_install_token` نبود و مسیرِ نصب/به‌روزرسانی می‌شکست.
        if (Schema::hasTable('clients') && ! Schema::hasColumn('clients', 'install_token')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->string('install_token', 64)->nullable()->unique();
                $table->string('current_version', 30)->nullable();
            });
        }

        if (! Schema::hasTable('subscriptions')) {
            Schema::create('subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->string('site_id');
                $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
                $table->string('status', 20)->default('active'); // active|past_due|suspended|deleted
                $table->timestamp('due_at')->nullable();
                $table->timestamp('suspended_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->timestamps();
                $table->unique(['client_id']);
                $table->index(['status', 'due_at']);
            });
        }

        if (! Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
                $table->unsignedBigInteger('amount')->default(0);
                $table->string('currency', 10)->default('IRT');
                $table->string('status', 20)->default('pending'); // pending|paid|failed|cancelled
                $table->timestamp('due_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->string('gateway_ref')->nullable()->unique();
                $table->jsonb('meta')->nullable();
                $table->timestamps();
                $table->index(['client_id', 'status']);
            });
        }

        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
                $table->string('gateway', 30)->default('zarinpal-stub');
                $table->string('ref')->unique();
                $table->unsignedBigInteger('amount')->default(0);
                $table->string('status', 20)->default('pending'); // pending|paid|failed
                $table->jsonb('raw')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('billing_events')) {
            Schema::create('billing_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
                $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
                $table->string('type', 30); // remind|suspend|resume|purge|tier_upgrade|payment
                $table->string('message')->nullable();
                $table->timestamps();
                $table->index(['subscription_id', 'type']);
            });
        }

        if (! Schema::hasTable('support_tokens')) {
            Schema::create('support_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->string('token_hash', 64)->unique();
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('backups')) {
            Schema::create('backups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->string('type', 20)->default('manual');
                $table->string('status', 20)->default('done'); // done|restoring|restored|failed
                $table->string('path')->nullable();
                $table->jsonb('meta')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
        Schema::dropIfExists('support_tokens');
        Schema::dropIfExists('billing_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('plans');
    }
};
