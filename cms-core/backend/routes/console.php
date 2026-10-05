<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// J5 — طیف Billing مرکزی (یادآوری D-7..D-0، تعلیق D-0، حذف D+7) از این فایل
// **بیرون رفت** و به `plugins/central/Laravel/routes/console.php` منتقل شد.
//
// دلیل: این سه فرمان ماژولِ مرکزی‌اند، و زمان‌بندی‌شان در هسته یعنی یک نصبِ
// عمومی (بدون `plugins/central/`) هر روز سه فرمانِ ناموجود را صدا می‌زد.
// `schedule:run` در آن حالت خطا نمی‌داد — فقط بی‌صدا رد می‌شد، که بدتر است.
//
// زمان‌بندی حالا فقط وقتی ثبت می‌شود که بستهٔ مرکزی واقعاً روی دیسک باشد:
// @see App\Providers\AppServiceProvider::bootBundledPlugins()

// E5 — پشتیبان شبانه. ساعت ۰۳:۱۵ یعنی بعد از هر سه فرمان بالا و بیرون از پیک
// کاربر؛ اگر `schedule:run` در cron نباشد هیچ اتفاقی نمی‌افتد و این عمدی است —
// بکاپ دستیِ خاموش بهتر از بکاپ خودکارِ شکسته‌ای است که کسی خبردار نشود.
Schedule::command('backup:run')->dailyAt('03:15');
