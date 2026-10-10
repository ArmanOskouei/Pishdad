<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S-11 — معافیتِ per-user از اجبارِ ۲FA.
 *
 * چرا ستونِ کاربر و نه تغییرِ فلگِ نقش: `requires_2fa` روی **نقش** است و
 * خاموش‌کردنش برای نجاتِ یک کاربر، اجبار را برای همهٔ دارندگانِ آن نقش
 * برمی‌دارد. این ستون چراغِ قفلِ جراحی است: یک نفر معاف می‌شود، بقیهٔ
 * سیاست دست‌نخورده می‌ماند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('two_factor_exempt')->default(false)->after('google2fa_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('two_factor_exempt');
        });
    }
};
