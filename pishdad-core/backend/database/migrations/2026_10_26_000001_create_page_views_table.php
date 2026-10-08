<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WF-H12 — بازدیدهای سایت عمومی: خودمیزبان، بدون کوکی، بدون PII.
 *
 * ## چرا این شکل
 *
 * ستون `ip` و `user_agent` خام **عمداً اینجا نیستند**: تحلیل باید بدون کوکی و
 * بدون شناسهٔ پایدار کار کند. «دستگاه» (دسکتاپ/موبایل/تبلت) هنگام ثبت از
 * user-agent مشتق و سپس دور ریخته می‌شود؛ خود رشته هیچ‌وقت ذخیره نمی‌شود.
 *
 * `country` هم فقط از هدری می‌آید که لبه/GEO موسسه می‌دهد (`CF-IPCountry` یا
 * `X-Country`) و اگر نباشد `null` می‌ماند — کشور حدس زده نمی‌شود.
 *
 * `referrer` فقط میزبان (دامنه) است، نه URL کامل؛ ارجاع‌دهندهٔ کامل می‌تواند
 * مسیرِ خصوصی صفحهٔ قبلی کاربر را لو بدهد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('page_views')) {
            return;
        }

        Schema::create('page_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path', 500);
            $table->string('slug', 255)->nullable();
            $table->string('referrer', 255)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('device', 20)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index(['created_at', 'path']);
            $table->index('page_id');
            $table->index('country');
            $table->index('device');
            $table->index('referrer');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_views');
    }
};
