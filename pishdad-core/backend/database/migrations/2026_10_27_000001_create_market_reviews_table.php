<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| WF-H18 — امتیاز/نظرات آیتم بازار.
|
| فقط خریدارانِ تأییدشده (لایسنس فعال یا سفارش پرداخت‌شده) می‌توانند امتیاز
| ۱..۵ و نظر ثبت کنند. هر کاربر برای هر افزونه **یک** نظر دارد (unique).
| نظرها پیش از نمایش به‌صورت عمومی باید در بازبینی مرکزی تأیید شوند.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plugin_id')->constrained('plugins')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating'); // ۱ تا ۵
            $table->text('comment')->nullable();
            $table->string('status', 20)->default('pending'); // pending|approved|rejected
            $table->text('moderation_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['plugin_id', 'user_id']);
            $table->index(['plugin_id', 'status']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_reviews');
    }
};
