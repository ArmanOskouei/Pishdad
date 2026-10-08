<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WF-M14 — Web Vitals سایت عمومی: خودمیزبان، بدون کوکی، بدون PII.
 *
 * هر ردیف یک **نمونهٔ** خام از یک معیار است (LCP/INP/CLS)؛ صدک‌ها هنگام
 * گزارش ساخته می‌شوند، نه اینجا. عمداً هیچ شناسهٔ نشست، IP یا user-agent
 * ذخیره نمی‌شود — فقط مسیر، معیار و مقدار. پس نمی‌توان یک بازدیدکننده را
 * در طول زمان ردیابی کرد.
 *
 * `value` برای LCP/INP میلی‌ثانیه و برای CLS بی‌بعد است؛ `metric` تعیین
 * می‌کند کدام. ستون را `decimal` نگه داشتیم تا CLS کوچک (۰٫۰۱) دقیق بماند.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('page_vitals')) {
            return;
        }

        Schema::create('page_vitals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path', 500);
            $table->string('slug', 255)->nullable();
            $table->string('metric', 10);
            $table->decimal('value', 12, 3);
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index(['metric', 'created_at']);
            $table->index(['metric', 'created_at', 'path']);
            $table->index('page_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_vitals');
    }
};
