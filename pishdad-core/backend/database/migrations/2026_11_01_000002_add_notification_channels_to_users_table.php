<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WF-M15 — تنظیماتِ کانالِ اعلانِ هر کاربر.
 *
 * ## چرا روی `users` و نه `notification_preferences`
 *
 * `notification_preferences` به تفکیک **گروه × کانال** است (۵ کارت). ولی این دو
 * مورد ربطی به گروه ندارند:
 *  - `telegram_chat_id` یک نشانیِ **شخصی** است، نه ترجیحِ یک گروه. گروه‌بندی‌اش
 *    یعنی ۵ بار تکرار و ۵ فرصت برای ناهمگون شدن.
 *  - `notification_daily_digest` یک تصمیمِ **سراسری** است: «همهٔ خوانده‌نشده‌ها
 *    را یکجا بفرست». نه به گروه بستگی دارد، نه به کانال.
 *
 * پس ستونِ کاربر، و قیدِ یکتایی طبیعی (`id`) از قبل هست.
 *
 * ## ⭐ چرا `telegram_chat_id` متن است نه عدد
 *
 * شناسهٔ گفتگو در تلگرام می‌تواند عددِ منفی (گروه/کانال) یا `@username` باشد.
 * `bigint` هر دو را رد می‌کند و `@` را نمی‌شود در `bigint` نگه داشت.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hasChat = Schema::hasColumn('users', 'telegram_chat_id');
        $hasDigest = Schema::hasColumn('users', 'notification_daily_digest');

        if ($hasChat && $hasDigest) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($hasChat, $hasDigest): void {
            if (! $hasChat) {
                $table->string('telegram_chat_id', 64)->nullable();
            }

            if (! $hasDigest) {
                $table->boolean('notification_daily_digest')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['telegram_chat_id', 'notification_daily_digest']);
        });
    }
};
