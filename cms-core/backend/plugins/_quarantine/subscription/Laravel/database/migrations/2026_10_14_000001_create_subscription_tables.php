<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L-B12 — جدول‌های اختصاصیِ افزونهٔ «اشتراک من».
 *
 * ## چرا دوباره‌کاری با `central_*`؟
 *
 * تا امروز سه جدولِ `central_plans` / `central_subscriptions` / `central_invoices`
 * متعلق به **مرکز** بودند و افزونهٔ اشتراک مستقیم به مدل‌هایشان ایمپورت می‌کرد.
 * این یعنی استقلالی که خواستیم فقط روی کد بود، نه روی داده — و در نصبِ
 * عمومیِ بدون مرکز، افزونهٔ اشتراک اسکیمای خودش را نداشت.
 *
 * این migration سه جدولِ `subscription_*` را می‌سازد و داده‌های موجود را
 * **کپی** می‌کند (نه جابه‌جا) — تا مهاجرت برگشت‌پذیر بماند.
 *
 * ## چرا `central_*` را حذف نمی‌کنیم؟
 *
 * داشبورد مرکز (فهرست مشتریان، فاکتورها، وصولی‌ها) به همین جدول‌ها نگاه می‌کند و
 * آن افزونه در محیط‌های مستقل وجود دارد. حذفشان در این تسک، پروژه‌ای جدا با
 * برنامهٔ مهاجرت داده و بازگشت است. تا آن زمان هر دو ستون خوانده می‌شوند.
 *
 * @see database/migrations/2026_10_13_000001_register_subscription_plugin.php
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createPlans();
        $this->createSubscriptions();
        $this->createInvoices();
        $this->copyFromCentral();
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
        Schema::dropIfExists('subscription_subscriptions');
        Schema::dropIfExists('subscription_plans');
    }

    private function createPlans(): void
    {
        if (Schema::hasTable('subscription_plans')) {
            return;
        }

        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('tier', 20)->default('budget');
            $table->unsignedBigInteger('price_monthly')->default(0); // تومان
            $table->unsignedBigInteger('price_yearly')->default(0);
            $table->string('currency', 10)->default('IRT');
            $table->jsonb('features')->default('[]');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    private function createSubscriptions(): void
    {
        if (Schema::hasTable('subscription_subscriptions')) {
            return;
        }

        Schema::create('subscription_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('site_id')->nullable();
            // «مرکز» اختیاری است: نصبِ مستقل اشتراک نباید به مرکز وابسته باشد.
            $table->unsignedBigInteger('central_client_id')->nullable();
            $table->foreignId('plan_id')->nullable()
                ->constrained('subscription_plans')->nullOnDelete();
            $table->string('status', 20)->default('active'); // active|past_due|suspended|deleted
            $table->timestamp('due_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->unique('owner_user_id');
            $table->index(['status', 'due_at']);
            $table->index('site_id');
        });
    }

    private function createInvoices(): void
    {
        if (Schema::hasTable('subscription_invoices')) {
            return;
        }

        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->nullable()
                ->constrained('subscription_subscriptions')->cascadeOnDelete();
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('currency', 10)->default('IRT');
            $table->string('status', 20)->default('pending'); // pending|paid|failed|cancelled
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('gateway_ref')->nullable()->unique();
            $table->jsonb('meta')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * دادهٔ موجود را کپی می‌کند. اگر مرکز نصب نباشد هیچ اتفاقی نمی‌افتد — و همین
     * مسیرِ «نصبِ مستقلِ اشتراک» است که باید بدون خطا بالا بیاید.
     */
    private function copyFromCentral(): void
    {
        if (! Schema::hasTable('central_plans')) {
            return;
        }

        $now = now();

        // ---- پلن‌ها ----
        foreach (DB::table('central_plans')->get() as $plan) {
            DB::table('subscription_plans')->updateOrInsert(
                ['slug' => $plan->slug],
                [
                    'name' => $plan->name,
                    'tier' => $plan->tier,
                    'price_monthly' => $plan->price_monthly,
                    'price_yearly' => $plan->price_yearly,
                    'currency' => $plan->currency,
                    'features' => $plan->features,
                    'active' => $plan->active,
                    'created_at' => $plan->created_at ?? $now,
                    'updated_at' => $now,
                ],
            );
        }

        // ---- نگاشت id: پلن قدیمی → پلن جدید ----
        $planMap = DB::table('subscription_plans')
            ->pluck('id', 'slug')
            ->all();

        if (! Schema::hasTable('central_subscriptions')) {
            return;
        }

        // ---- اشتراک‌ها ----
        $subscriptionMap = [];

        foreach (DB::table('central_subscriptions')->get() as $sub) {
            $ownerId = Schema::hasTable('central_clients')
                ? DB::table('central_clients')->where('id', $sub->client_id)->value('owner_user_id')
                : null;

            // یک کاربر نمی‌تواند دو اشتراکِ فعال داشته باشد.
            if ($ownerId !== null && DB::table('subscription_subscriptions')
                ->where('owner_user_id', $ownerId)->exists()) {
                continue;
            }

            $oldPlanSlug = $sub->plan_id !== null
                ? DB::table('central_plans')->where('id', $sub->plan_id)->value('slug')
                : null;

            $newId = DB::table('subscription_subscriptions')->insertGetId([
                'owner_user_id' => $ownerId,
                'site_id' => $sub->site_id,
                'central_client_id' => $sub->client_id,
                'plan_id' => ($oldPlanSlug !== null && isset($planMap[$oldPlanSlug]))
                    ? $planMap[$oldPlanSlug]
                    : null,
                'status' => $sub->status,
                'due_at' => $sub->due_at,
                'suspended_at' => $sub->suspended_at,
                'deleted_at' => $sub->deleted_at,
                'created_at' => $sub->created_at ?? $now,
                'updated_at' => $now,
            ]);

            $subscriptionMap[$sub->id] = $newId;
        }

        if (! Schema::hasTable('central_invoices')) {
            return;
        }

        // ---- فاکتورها ----
        foreach (DB::table('central_invoices')->get() as $invoice) {
            DB::table('subscription_invoices')->insert([
                'subscription_id' => $subscriptionMap[$invoice->subscription_id] ?? null,
                'amount' => $invoice->amount,
                'currency' => $invoice->currency,
                'status' => $invoice->status,
                'due_at' => $invoice->due_at,
                'paid_at' => $invoice->paid_at,
                'gateway_ref' => $invoice->gateway_ref,
                'meta' => $invoice->meta,
                'created_at' => $invoice->created_at ?? $now,
                'updated_at' => $now,
            ]);
        }
    }
};
