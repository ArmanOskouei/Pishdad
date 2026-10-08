<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F1.3 — **یک** جدول outbox برای ایمیل + SMS + (آینده) Push.
 *
 * ## چرا یکی و نه سه‌تا
 *
 * یادداشت `F0.6`/`F1.3` می‌گوید «ایمیل داریم، SMS هم داریم، Push هم در راه» — و
 * هر سه **همان مسئله** را دارند: نوشتن باید در تراکنشِ خودِ کاربر بماند، تحویل
 * باید بیرون از تراکنش و با retry باشد، و تحویلِ دوباره نباید پیام را دوباره
 * بفرستد. سه جدول یعنی سه بار پیاده‌سازیِ claim و سه بار یک باگِ یکسان.
 *
 * نامِ جدول `notification_deliveries` است چون سطرِ یکی از اینها دقیقاً یعنی
 * «تحویلِ یک اعلان از یک کانال» — و `F4.2.B` همین جدول را مالکِ تحویلِ اعلان
 * می‌داند. یعنی **یک** جدول، دو ردیفِ TASKS، نه دو جدول.
 *
 * ⚠️ `notification_id` **nullable** است و قیدِ خارجی ندارد عمداً: SMSِ ورود
 * (کد تأیید) و ایمیلِ بازیابی رمز اعلانِ `notifications` نیستند، ولی **همان
 * صف را می‌خواهند**. با قیدِ اجباری، یا این‌ها جدا می‌شدند (دو صف، همان باگ
 * دوباره) یا باید برایشان اعلانِ ساختگی می‌ساختیم.
 *
 * ## ⭐ چرا `status` سه‌مقداری است و نه `sent_at`
 *
 * `pending → processing → sent|failed` با `claim_token` و `claimed_at`:
 *  - **`claim` اتمیک** (`F0.6` بند ۴): سرویس بیرونی ممکن است چند بار پشت‌سرهم
 *    `outbox:drain` را صدا بزند. `SELECT … FOR UPDATE SKIP LOCKED` داخل یک
 *    تراکنش، هر سطر را **فقط به یک** tick می‌دهد. شرطِ `status = 'pending'` در
 *    همان تراکنش یعنی tick دوم اصلاً سطر را نمی‌بیند ⇒ **idempotent**.
 *  - **`claim_token`**: اگر tick درست وسط ارسال بمیرد (kill -9، timeout)، سطر در
 *    `processing` گیر می‌کند و هیچ‌کس پس‌نمی‌گیردش. `reapStale()` سطرهایی را که
 *    `claimed_at`شان از سقف گذشته برمی‌دارد و **فقط اگر** `attempts` هنوز کمتر
 *    از `max_attempts` باشد. بدون این، یک پیامِ گیرکرده یعنی یک اعلانِ هرگز
 *    نرسیده.
 *  - **تلاشِ پنجم** به `failed` می‌رود (نه retry بی‌نهایت): طوفانِ ارسالِ ایمیل
 *    نباید صف را برای همیشه پر نگه دارد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_deliveries')) {
            return;
        }

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();

            /**
             * کانال. بستهٔ بسته است، نه `enum` شناور: هر کانالِ تازه یک مسیرِ
             * تحویلِ جدا در `OutboxSender` لازم دارد و باید عمداً نوشته شود.
             * (ایمیل / SMS / Push)
             */
            $table->string('channel', 16)->index();

            /** گیرنده: نشانی ایمیل یا شمارهٔ موبایل، بسته به کانال. */
            $table->string('recipient', 200);

            /**
             * کلیدِ یکتایِ «این پیامِ منطقی» — **همان چیزی که idempotency را
             * می‌سازد**.
             *
             * چرا در برابر `id`: tick دوم دوباره `enqueue()` می‌زند (مثلاً retry
             * خودِ فراخواننده) و بدون این قید دو سطر می‌ساخت ⇒ دو ایمیل به
             * کاربر. `unique` یعنی دومی بی‌صدا رد می‌شود. مقدارش را فراخواننده
             * می‌دهد و باید **پایدار** باشد (مثلاً `welcome:{userId}`) وگرنه
             * قید هیچ چیزی را dedupe نمی‌کند.
             */
            $table->string('dedupe_key', 191)->unique();

            /**
             * payload کانال. برای ایمیل: نامِ کلاسِ Mailable + آرگومان‌ها.
             * برای SMS: متن. نگه‌داشتنِ نامِ کلاس به‌جای متنِ آماده یعنی
             * قالب می‌تواند بعد از enqueue عوض شود و نسخهٔ درست ارسال می‌شود.
             *
             * `text` نه `jsonb`: شکلش بین کانال‌ها فرق می‌کند و `jsonb` اینجا
             * فقط یک schema‌زدنِ بی‌استفاده است (همان دلیلی که `notifications.data`
             * هم `text` است).
             */
            $table->text('payload')->nullable();

            // پیوند اختیاری به اعلانِ ثبت‌شده (کانالِ `database` این را ندارد —
            // آن یکی اصلاً از outbox رد نمی‌شود).
            $table->uuid('notification_id')->nullable()->index();

            $table->string('status', 16)->default('pending')->index();
            $table->unsignedTinyInteger('attempts')->default(0);

            /**
             * `available_at` — **نه** `sent_at`. سرویسِ بیرونی هر ۵ دقیقه یا
             * بیشتر صدا می‌زند، پس retry با فاصلهٔ نمایی روی همین ستون سوار است
             * و هیچ cronی لازم نمی‌شود (`Q7`: نه کرون، فقط tick).
             */
            $table->timestamp('available_at')->index();

            // claim: چه کسی و کِی این سطر را برداشته.
            $table->string('claim_token', 40)->nullable();
            $table->timestamp('claimed_at')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            /**
             * آخرین خطا. **بُریده** به چند بایت، چون متنِ خطای سرویسِ بیرونی می‌تواند
             * کلِ پاسخِ HTML باشد و جدول رشد می‌کند بی‌آنکه کسی بخواند.
             */
            $table->string('last_error', 500)->nullable();

            $table->timestamps();

            /**
             * ایندکسِ واقعیِ drain. بدون این، هر tick یک seq-scan روی جدولی می‌زند
             * که قرار است ماه‌ها رشد کند — و دقیقاً همان جایی است که کندی خودش
             * باعث عقب‌افتادن صف می‌شود.
             */
            $table->index(['status', 'available_at', 'id'], 'notification_deliveries_drain_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
