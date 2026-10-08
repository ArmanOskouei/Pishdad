<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WF-H8 — تاریخچهٔ ورود: هر تلاشِ ورود (موفق یا ناموفق) با IP و user-agent خام.
 *
 * مکملِ `personal_access_tokens` است: آن جدول فقط نشست‌های *موفق و فعال* را
 * دارد، پس نه شکستِ رمز عبور/کد ۲FA را نشان می‌دهد و نه نشست‌های بسته‌شده را.
 * اینجا **هر** تلاش ثبت می‌شود تا کارت امنیت بتواند الگوی نفوذ را نشان دهد.
 *
 * `user_id` قابل‌خالی است عمداً: ایمیلِ ناشناس یا تلاش پیش از احراز هویت هم
 * باید ثبت شود، ولی نباید به کاربری وصل شود که وجود ندارد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('login_events')) {
            return;
        }

        Schema::create('login_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('successful')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');
    }
};
