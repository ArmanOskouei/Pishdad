<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L-B3/F0.3 — پین ناشر پلاگین سیستمی.
 *
 * حفره: `upgrade()` مقدار `publisher_key_id` را از مانیفست تازه می‌خواند و
 * بازنویسی می‌کرد، پس پلاگین سیستمی (غیرقابل حذف) با کلیدِ هر ناشرِ تأییدشدهٔ
 * دیگری قابل تصاحب بود ⇒ RCE ماندگار.
 *
 * راه‌حل: ستون `publisher_key_fingerprint` روی `system_plugins`. اولین
 * آپلود/ارتقای امضاشده پین می‌کند (TOFU) و بعد از آن فقط همان ناشر —
 * نه هر کلید معتبر دیگری — می‌تواند نسخهٔ تازه بدهد. گارد در کنترلر است،
 * این مایگریشن فقط ستون را می‌سازد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_plugins', function (Blueprint $table) {
            $table->string('publisher_key_fingerprint', 64)->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('system_plugins', function (Blueprint $table) {
            $table->dropColumn('publisher_key_fingerprint');
        });
    }
};
