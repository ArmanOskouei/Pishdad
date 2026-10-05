<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4.5 — محتوای چندزبانهٔ سایت: هر زبان ردیفِ صفحهٔ خودش.
 *
 * تصمیم معماری (ECO2-س۳ / F4.5 گزینهٔ الف): کمترین تغییر و `published_revision_id`
 * دست‌نخورده. ستون `locale` به `pages` اضافه می‌شود و یکتاییِ slug از سراسری به
 * «per-locale» تغییر می‌کند: `fa/about` و `en/about` دو صفحهٔ مستقل‌اند.
 *
 * پیش‌فرض `fa` است، پس هر دادهٔ موجود بدون تغییر کار می‌کند و فهرست عمومی هم
 * تا وقتی پارامتر `locale` نیاید همان fa را می‌خواند (صفر رگرسیون).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->string('locale', 8)->default('fa')->after('slug');
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->dropUnique('pages_slug_unique');
            $table->unique(['slug', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropUnique(['slug', 'locale']);
            $table->unique('slug');
            $table->dropColumn('locale');
        });
    }
};
