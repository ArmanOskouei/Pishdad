<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| ECO6 — delivery (تحویل ZIP).
|
| تصمیم قفل‌شده: تک‌خرید، بدون لایسنس/اشتراک؛ فایل ZIP دقیقاً یک‌بار پس از خرید
| تحویل داده می‌شود. لایسنسی که در K8 ساخته شد برای «نصب/فعال‌سازی» است، نه
| برای «دانلود». ستون‌های این‌جا خودِ تحویل را ثبت می‌کنند:
|
|   delivered_at         لحظهٔ اولین تحویل (null = هنوز تحویل نشده).
|   delivered_checksum   sha256 فایلِ تحویل‌شده — مدرکِ «همان بایتی که خرید» .
|
| چرا ستون `download_count` نمی‌گذاریم: تحویلِ یک‌باره یعنی «اولین بار» و
| «دوباره» دو حالتِ متفاوت‌اند، نه یک شمارندهٔ بازیگوش. `delivered_at` خودش
| منبعِ حقیقتِ تک‌بودن است و نمی‌شود با منطقِ برنامه چندباره‌اش کرد.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_licenses', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('active');
            $table->string('delivered_checksum', 64)->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('market_licenses', function (Blueprint $table) {
            $table->dropColumn(['delivered_at', 'delivered_checksum']);
        });
    }
};
