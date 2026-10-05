<?php

use Illuminate\Support\Facades\Schedule;

/*
| J5 — میزبانِ فرمان‌های کنسولیِ افزونهٔ مرکزی.
|
| چرا این فایل در افزونه است و نه در `routes/console.php` هسته
| ------------------------------------------------------------------
| طیفِ Billing مرکزی (یادآوری D-7..D-0، تعلیق D-0، حذف D+7) ماژولِ مرکزی است،
| نه هسته. تا وقتی زمان‌بندی‌اش در `routes/console.php` هسته بود، یک نصبِ
| **عمومی** — یعنی بدونِ `plugins/central/` — سه فرمان را هر روز صبح صدا
| می‌زد که هیچ‌کدام وجود ندارند. `schedule:run` خطا نمی‌داد، فقط بی‌صدا رد می‌شد.
|
| یعنی هسته به افزونه‌ای وابسته بود که ممکن است اصلاً نصب نباشد. این همان
| جهتِ اشتباهِ وابستگی است که J5 می‌خواهد ببندد.
|
| حالا `AppServiceProvider::bootBundledPlugins()` این فایل را **فقط برای
| افزونه‌هایی که واقعاً روی دیسک بستهٔ منبع دارند** `require` می‌کند. نبودِ
| افزونه یعنی نبودِ زمان‌بندی — که دقیقاً همان چیزی است که باید باشد.
|
| خودِ فرمان‌ها (`billing:remind|suspend|purge`) عمداً در هسته می‌مانند: آن‌ها
| در `CentralBoundaryTest::KNOWN_VIOLATIONS` ثبت شده‌اند و جابه‌جا کردنشان
| یعنی بازنویسی آن فهرست بدون آنکه چیزی از مرز کم شود — چون فرمان‌ها به
| جدول‌های `central_*` می‌زنند و این با جابه‌جاییِ فایل حل نمی‌شود.
|
| @see App\Providers\AppServiceProvider::bootBundledPlugins()
| @see App\Services\Plugins\PluginReleaseManager::sourceDir()
*/

// ۰۸:۰۰ یادآوری، ۰۸:۱۵ تعلیق، ۰۸:۳۰ حذف — پشت سر هم و بیرون از پیک.
Schedule::command('billing:remind')->dailyAt('08:00');
Schedule::command('billing:suspend')->dailyAt('08:15');
Schedule::command('billing:purge')->dailyAt('08:30');
