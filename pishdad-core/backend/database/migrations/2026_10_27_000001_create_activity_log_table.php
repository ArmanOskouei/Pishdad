<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WF-H9 — گزارش فعالیت محتوا (audit log).
 *
 * append-only: هیچ ردیفی ویرایش نمی‌شود. `subject_type` نامِ کوتاهِ موضوع است
 * (`page` | `media`)، نه FQCN — چون رابطهٔ morph نداریم و مقایسه در کنترلر و
 * فرانت با رشتهٔ کوتاه صریح‌تر است.
 *
 * `diff` هرگز HTML کاملِ بلوک‌ها را ذخیره نمی‌کند؛ فقط خلاصه (فیلدهای
 * تغییرکرده، تعداد بلوک، شناسهٔ نسخه‌ها) — تا جدول کوچک بماند و محتوای
 * حساس در لاگ تکثیر نشود.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('activity_log')) {
            return;
        }

        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('action', 40);
            $table->text('summary');
            $table->json('diff')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('action');
            $table->index('created_at');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
