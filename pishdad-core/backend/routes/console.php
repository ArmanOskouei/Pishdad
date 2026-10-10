<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// E5 — پشتیبان شبانه. ساعت ۰۳:۱۵ و بیرون از پیک کاربر؛ اگر `schedule:run` در cron
// نباشد هیچ اتفاقی نمی‌افتد و این عمدی است — بکاپ دستیِ خاموش بهتر از بکاپ
// خودکارِ شکسته‌ای است که کسی خبردار نشود.
//
// CP-7 — `withoutOverlapping` اضافه شد: اگر روزی host-cron و کانتینرِ scheduler
// (هر دو `schedule:run`) هم‌زمان فعال شوند، دو pg_dumpِ کامل از یک دیتابیس
// اجرا می‌شود. سرویس scheduler کانتینری منبعِ یگانه است (E76).
Schedule::command('backup:run')->dailyAt('03:15')->withoutOverlapping();

// WF-C3 — انتشار زمان‌بندی‌شده. هر دقیقه؛ `withoutOverlapping` تا دو tickِ
// هم‌زمان یک صفحه را دو بار منتشر نکنند.
Schedule::command('pages:publish-scheduled')->everyMinute()->withoutOverlapping();

// WF-M15 — خلاصهٔ روزانهٔ اعلان‌ها. صبح که کاربر بیدار می‌شود، یک پیام جمع‌بندی
// (ایمیل/تلگرام) که فقط برای کاربرانِ opt-in فرستاده می‌شود. `withoutOverlapping`
// چون اجرای دوباره روی نصبِ کند، دو خلاصه نمی‌سازد (dedupe روزانه هم هست).
Schedule::command('notifications:digest')->dailyAt('08:00')->withoutOverlapping();

// E75 — تخلیهٔ صفِ تحویل. بدونِ این، سطرهای outbox (از جمله تلگرامِ فوریِ
// `ticket.created`) هیچ‌وقت ارسال نمی‌شوند: روی نصبِ زنده هیچ tick بیرونی
// نبود و ایمیل‌های صف‌شده هم می‌ماندند. `withoutOverlapping` مثل بقیه.
Schedule::command('outbox:drain')->everyMinute()->withoutOverlapping();

// P-06 — نگه‌داشتِ جدول‌های تحلیلی (append-heavy). ماهانه کافی است.
Schedule::command('analytics:prune')->monthly()->withoutOverlapping();
