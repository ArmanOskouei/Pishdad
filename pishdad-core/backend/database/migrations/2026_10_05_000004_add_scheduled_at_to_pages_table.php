<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WF-C3 — انتشار زمان‌بندی‌شده.
 *
 * `scheduled_at` زمانِ موعدِ انتشار یک صفحهٔ پیش‌نویس است. کامند
 * `pages:publish-scheduled` هر دقیقه ردیف‌هایی را که این ستون را پر دارند و
 * موعدشان رسیده، از همان مسیرِ `publish` منتشر می‌کند.
 *
 * ایندکس چون کوئری کامند روی `scheduled_at` فیلتر می‌زند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('published_at');
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropIndex(['scheduled_at']);
            $table->dropColumn('scheduled_at');
        });
    }
};
