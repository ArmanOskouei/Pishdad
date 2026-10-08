<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WF-C2 — مدیریت ریدایرکت ۳۰۱/۳۰۲.
 *
 * هر ردیف یک مسیر ورودی (`from_path`) را به مقصد (`to_path`) می‌نگردد.
 * `status_code` فقط 301 (دائمی) یا 302 (موقت) است. `hits` شمارش بازدید
 * ریدایرکت را نگه می‌دارد و از سمت سایت عمومی افزایش می‌یابد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_path', 500)->unique();
            $table->string('to_path', 1000);
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('hits')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
    }
};
