<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** تسک ۵ — فیلدهای پروفایل مدیر: آواتار + نام/نام‌خانوادگی + درباره من. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('avatar_media_id')->nullable()->after('phone');
            $table->string('first_name', 60)->nullable()->after('avatar_media_id');
            $table->string('last_name', 60)->nullable()->after('first_name');
            $table->text('bio')->nullable()->after('last_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_media_id', 'first_name', 'last_name', 'bio']);
        });
    }
};
