<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| J5 — جدول‌های چابوکن (Ops) افزونهٔ مرکزی.
|
| `2026_09_25_000002` در core بود؛ اینجا با نامِ خام (بازنویسی‌شونده به
| `central_*` توسط `PluginMigrationRunner`) و با guardِ `hasTable` برای نصبِ
| موجود. دلیلِ guard و حذفِ FK به `users` در
| `2026_09_24_000001_create_central_billing_tables.php` توضیح داده شده.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chabokan_deployments')) {
            Schema::create('chabokan_deployments', function (Blueprint $table) {
                $table->id();
                $table->string('service', 100);
                $table->string('status', 30)->default('queued'); // queued|running|done|failed
                // کاربرِ هسته: قیدِ خارجی ندارد (دلیل در فایلِ صورت‌حساب).
                $table->unsignedBigInteger('triggered_by')->nullable();
                $table->jsonb('meta')->nullable();
                $table->timestamps();
                $table->index(['service', 'status']);
            });
        }

        if (! Schema::hasTable('chabokan_logs')) {
            Schema::create('chabokan_logs', function (Blueprint $table) {
                $table->id();
                $table->string('action', 30); // start|stop|restart|resize|redeploy
                $table->string('service', 100);
                $table->unsignedBigInteger('user_id')->nullable();
                $table->jsonb('payload')->nullable();
                $table->timestamps();
                $table->index(['service', 'action']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chabokan_logs');
        Schema::dropIfExists('chabokan_deployments');
    }
};
