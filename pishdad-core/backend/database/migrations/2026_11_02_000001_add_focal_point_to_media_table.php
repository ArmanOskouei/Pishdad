<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* WF-L1 — نقطهٔ کانونی تصویر (0..1): برشِ واکنش‌گرا با object-position. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->float('focal_x')->nullable();
            $table->float('focal_y')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn(['focal_x', 'focal_y']);
        });
    }
};
