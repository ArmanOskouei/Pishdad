<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اشتراک‌های Web Push — ذخیرهٔ مقصدِ اعلان‌های مرورگر.
 *
 * ## چرا این جدول لازم است
 *
 * اعلان‌های داخلی (`notifications`) یک **کاربرِ شناخته‌شده** دارند و در
 * پنل می‌مانند. ولی بازدیدکنندهٔ سایت عمومی حساب ندارد، پس جایی برای نگه‌داری
 * مقصدِ push او وجود نداشت.
 *
 * این جدول دقیقاً همان «کاربرِ بی‌نام» است: یک `endpoint` یکتا به‌علاوهٔ کلیدهای
 * رمزنگاری که مرورگر می‌دهد.
 *
 * ## چرا `endpoint` کلیدِ یکاست
 *
 * مرورگر برای هر اشتراک یک endpoint می‌دهد. اگر کاربر روی دو دستگاه باشد
 * دو endpoint دارد و هر دو باید کار کنند — پس یکتایی روی endpoint درست است
 * و روی هیچ چیز دیگر (کاربر نداریم).
 *
 * برای کاربرِ واردشده، `user_id` nullable است و فقط برای این است که بشود
 * اعلانِ اختصاصیِ مدیر را به همان دستگاه‌ها فرستاد. نقش کاربر از جدولِ
 * `users` می‌آید، پس اینجا کپی نمی‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('push_subscriptions')) {
            return;
        }

        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();

            // کلیدِ یکتا — همان چیزی که push واقعی به آن نیاز دارد.
            $table->text('endpoint')->unique();

            // کلیدهای عمومیِ کلاینت. `null` برای مرورگرهای قدیمی‌تر که
            // کلید نمی‌دهند (و مجبور به رمزنگاریِ بدون‌کلید می‌شوند).
            $table->text('p256dh')->nullable();
            $table->text('auth')->nullable();

            // پنجرهٔ VAPID. نبودنش یعنی سرور نتوانسته VAPID بسازد ⇒
            // این اشتراک با درگاه‌های سختگیر رد می‌شود.
            $table->string('vapid_public_key')->nullable();

            // چه کسی درخواست داده: کاربرِ واردشده یا بازدیدکنندهٔ عمومی.
            // nullable چون اکثرِ مشترک‌ها بی‌نام‌اند.
            $table->unsignedBigInteger('user_id')->nullable();

            // برای محدود کردن اعلان به زبان/کانال. اختیاری.
            $table->string('locale', 10)->nullable();
            $table->string('topic', 64)->nullable();

            $table->timestamps();

            $table->index('user_id');
            $table->index('topic');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};