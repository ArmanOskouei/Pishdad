<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| تسک‌های ۱.۳ / ۱.۴ — جدول‌های Billing و مشتریان مرکزی.
|
| مالکیت (همان Postgres، تفکیک منطقی — مستند در README):
| - پیشوند central_* = مالک ماژول مرکزی؛ فقط از مسیرهای /api/v1/p/central/*
|   با نقش operator (یا سوپرادمین) قابل نوشتن است.
| - نصب مشتری فقط خواندن ردیف خودش را دارد: تطبیق از طریق
|   central_clients.owner_user_id و site_id (ستون site در subscription).
| - هیچ کوئری مشتری بدون فیلتر owner_user_id/site_id به این جدول‌ها نمی‌زند.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('tier', 20)->default('budget'); // budget|standard|enterprise
            $table->unsignedBigInteger('price_monthly')->default(0); // تومان
            $table->unsignedBigInteger('price_yearly')->default(0);
            $table->string('currency', 10)->default('IRT');
            $table->jsonb('features')->default('[]');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('central_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('domain')->nullable();
            $table->string('tier', 20)->default('budget');
            $table->string('status', 20)->default('active'); // active|suspended|deleted
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('site_id')->unique(); // کلید polling نصب مشتری
            $table->jsonb('health')->nullable();
            $table->timestamps();
            $table->index(['tier', 'status']);
        });

        Schema::create('central_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('central_clients')->cascadeOnDelete();
            $table->string('site_id'); // دنرمالایز برای polling سبک
            $table->foreignId('plan_id')->nullable()->constrained('central_plans')->nullOnDelete();
            $table->string('status', 20)->default('active'); // active|past_due|suspended|deleted
            $table->timestamp('due_at')->nullable(); // سررسید (مبنای D-0)
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('deleted_at')->nullable(); // حذف منطقی D+7
            $table->timestamps();
            $table->unique(['client_id']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('central_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('central_clients')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('central_subscriptions')->nullOnDelete();
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('currency', 10)->default('IRT');
            $table->string('status', 20)->default('pending'); // pending|paid|failed|cancelled
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('gateway_ref')->nullable()->unique(); // idempotency درگاه
            $table->jsonb('meta')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'status']);
        });

        Schema::create('central_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('central_invoices')->cascadeOnDelete();
            $table->string('gateway', 30)->default('zarinpal-stub');
            $table->string('ref')->unique(); // authority/ref درگاه
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('status', 20)->default('pending'); // pending|paid|failed
            $table->jsonb('raw')->nullable();
            $table->timestamps();
        });

        // ردپای حسابرسی Cron و اکشن‌ها (یادآوری/تعلیق/حذف/ارتقا) — قابل assert در تست.
        Schema::create('central_billing_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->nullable()->constrained('central_subscriptions')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('central_clients')->nullOnDelete();
            $table->string('type', 30); // remind|suspend|resume|purge|tier_upgrade|payment
            $table->string('message')->nullable();
            $table->timestamps();
            $table->index(['subscription_id', 'type']);
        });

        // توکن یکبارمصرف سوپرادمین: فقط هش ذخیره می‌شود، TTL کوتاه، مصرف یکباره.
        Schema::create('central_support_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('central_clients')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        // بکاپ‌ها (MVP = رکورد Stub؛ pg_dump واقعی TODO مرحله بعد).
        Schema::create('central_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('central_clients')->cascadeOnDelete();
            $table->string('type', 20)->default('manual');
            $table->string('status', 20)->default('done'); // done|restoring|restored|failed
            $table->string('path')->nullable(); // مسیر Stub
            $table->jsonb('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_backups');
        Schema::dropIfExists('central_support_tokens');
        Schema::dropIfExists('central_billing_events');
        Schema::dropIfExists('central_payments');
        Schema::dropIfExists('central_invoices');
        Schema::dropIfExists('central_subscriptions');
        Schema::dropIfExists('central_clients');
        Schema::dropIfExists('central_plans');
    }
};
