<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| K5.5 — حذف کامل `plugin_hooks`.
|
| تصمیم ثبت‌شده: dispatcher ساخته نمی‌شود. جدول فقط نوشتنی بود
| (`PluginController::syncHooks()` ردیف می‌ساخت و هیچ‌جا خوانده نمی‌شد)، پس یک
| وعدهٔ بی‌پشتوانه به نویسندهٔ پلاگین بود: «هوکت ثبت شد» یعنی سکوت.
| آن وعده یا ساخته می‌شد یا از قرارداد حذف؛ حذف انتخاب شد چون dispatcher واقعی
| به `PluginAutoloader` وصل‌نشده و کار جداسازی اجرا می‌خواهد — یعنی فاز ۱ با
| ریسک امنیتی، نه یک حذف ساده که وانمود کنیم حل شد.
|
| فایل سازندهٔ جدول (`2026_09_26_000001`) دست‌نخورده می‌ماند: در batch 3 اجرا
| شده و بازنویسی‌اش تاریخِ اعمال‌شده را تحریف می‌کند. مسیر `migrate:fresh` هم از
| همین دو فایل رد می‌شود، پس نصب تازه جدول را یک لحظه می‌سازد و بعد حذفش
| می‌کند — هزینه‌اش یک CREATE/DROP بی‌اثر است در برابر توان حفظ تاریخچه.
*/
return new class extends Migration
{
    public function up(): void
    {
        // `dropIfExists` نه به‌خاطر حالت نیمه‌مهاجرت، بلکه چون `migrate:fresh`
        // روی دیتابیسی می‌آید که ممکن است مهاجرت سازندهٔ جدول را در تاریخچه
        // داشته باشد ولی خود جدول را نداشته باشد.
        Schema::dropIfExists('plugin_hooks');
    }

    public function down(): void
    {
        // عیناً همان تعریف `2026_09_26_000001_create_phase5_tables`، تا rollback
        // نصب قدیمی را به همان شکلی برگرداند که پیش از این مهاجرت بود — نه به
        // شکلی که با آن رفت‌وبرگشت ناسازگار باشد.
        Schema::create('plugin_hooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plugin_id')->constrained('plugins')->cascadeOnDelete();
            $table->string('hook', 100);
            $table->string('handler', 200)->nullable();
            $table->timestamps();
            $table->index(['plugin_id', 'hook']);
        });
    }
};
