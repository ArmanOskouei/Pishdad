<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4.2.B — ترجیحاتِ اعلان، به تفکیک **گروه × کانال**.
 *
 * ## ⭐ چرا `group_key` و نه `catalog_key`
 *
 * `F4.2.U` پنل را «۵ کارت × ۳ کانال» طراحی کرده — نه ۲۰ کارت. یعنی کاربر با یک
 * کلید «همهٔ اعلان‌های امنیتی را خاموش کن» تصمیم می‌گیرد، نه با بیست‌تا کلید.
 * پس واحدِ تصمیم **گروه** است.
 *
 * ولی `catalog_key` هم nullable هست و این عمدی است: سطر با `catalog_key = null`
 * یعنی «پیش‌فرضِ این گروه»، و سطرهای پر آن استثناهای کاربر روی یک اعلانِ خاص.
 * خواندن همیشه از استثنا شروع می‌کند و به پیش‌فرض می‌افتد — بدون این، خاموش‌کردنِ
 * یک کارت هیچ کاری نمی‌کرد چون ۲۰ سطر باید نوشته می‌شد.
 *
 * ## چرا `unique(user_id, group_key, catalog_key, channel)`
 *
 * بدون قیدِ یکتا، دو تایپِ سریعِ پنل سطرِ تکراری می‌سازند و `first()` بی‌صدا
 * یکی را برمی‌دارد — و کاربر فکر می‌کند خاموش کرده ولی خاموش نشده. توجه کنید
 * که `catalog_key` nullable است و در Postgres چند `NULL` در unique مجازند، پس
 * این قید رکوردهای «پیش‌فرضِ گروه» را **نمی** محافظت می‌کند. برای همین
 * استثنای عمدی و جدا در کد نوشته شده: `ensureDefault()` پیش از نوشتن
 * پیش‌فرض را می‌خواند و در صورت نبود، درج می‌کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_preferences')) {
            return;
        }

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            /** یکی از گروه‌های `Catalog::GROUPS`. */
            $table->string('group_key', 40)->index();

            /**
             * استثنای کاربر روی یک اعلانِ خاص — `NULL` یعنی «پیش‌فرضِ گروه».
             * (ببین توضیحِ بالای migration.)
             */
            $table->string('catalog_key', 80)->nullable();

            /** یکی از `email` / `sms` / `push`. */
            $table->string('channel', 16);

            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'group_key', 'channel'], 'notification_preferences_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
