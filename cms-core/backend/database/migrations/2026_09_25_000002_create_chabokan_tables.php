<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| تسک ۳.۳ — جدول‌های Ops چابکان (فقط مسیرهای /api/central/* با نقش operator).
| - central_chabokan_deployments: رکورد هر redeploy (روی Stub، دیپلوی واقعی TODO مرحله ۵).
| - central_chabokan_logs: ردپای حسابرسی همه اکشن‌ها (start/stop/restart/resize/redeploy).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_chabokan_deployments', function (Blueprint $table) {
            $table->id();
            $table->string('service', 100);
            $table->string('status', 30)->default('queued'); // queued|running|done|failed
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('meta')->nullable();
            $table->timestamps();
            $table->index(['service', 'status']);
        });

        Schema::create('central_chabokan_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 30); // start|stop|restart|resize|redeploy
            $table->string('service', 100);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('payload')->nullable();
            $table->timestamps();
            $table->index(['service', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_chabokan_logs');
        Schema::dropIfExists('central_chabokan_deployments');
    }
};
