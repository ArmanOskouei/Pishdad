<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WF-M11 — ثبت عبارت‌های جستجوی عمومی برای گزارش «پرجستجوترین‌ها» و
 * «جستجوهای بی‌نتیجه».
 *
 * بدون هیچ PII: نه IP، نه user-agent خام، نه شناسهٔ پایدار. فقط متن عبارت،
 * شمار نتیجهٔ همان جستجو و زمان — تا بشود فهمید کاربر دنبال چه محتوایی است
 * که هنوز تولید نشده.
 *
 * `results_count` عمداً شمار نتیجهٔ همان پاسخ است (سقفِ صفحه)، نه شمار کلِ
 * تطابق‌ها: تشخیص «بی‌نتیجه» با صفر قطعی است و برای بقیه شمار جستجو مهم است،
 * نه عدد نتیجه.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('search_queries')) {
            return;
        }

        Schema::create('search_queries', function (Blueprint $table): void {
            $table->id();
            $table->string('query', 200);
            $table->unsignedInteger('results_count')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index('query');
            $table->index(['results_count', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_queries');
    }
};
