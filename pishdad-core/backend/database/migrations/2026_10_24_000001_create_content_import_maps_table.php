<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WF-C6 — نگاشت شناسهٔ منبعِ درون‌بری به ردیفِ هسته.
 *
 * هر ردیف می‌گوید «این شناسهٔ منبع (مثلاً `wp:post_id=42`) قبلاً به این ردیف
 * مقصد نگاشته شده است». بدون این جدول، درون‌بری دوبارهٔ همان فایل محتوا را
 * تکرار می‌کرد. قید `unique(source, source_id)` تضمین می‌کند هر شناسهٔ منبع
 * دقیقاً یک مقصد دارد ⇒ درون‌بری مکرر idempotent است.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_import_maps', function (Blueprint $table) {
            $table->id();
            $table->string('source', 40);
            $table->string('source_id', 191);
            $table->string('target_type', 40);
            $table->unsignedBigInteger('target_id');
            $table->timestamps();

            $table->unique(['source', 'source_id']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_import_maps');
    }
};
