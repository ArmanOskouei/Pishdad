<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * WF-M10 — ستون `labels` روی تیکت‌ها: آرایهٔ JSON از برچسب‌های متنی (nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        if (! Schema::hasColumn('tickets', 'labels')) {
            Schema::table('tickets', function ($table): void {
                $table->json('labels')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tickets') && Schema::hasColumn('tickets', 'labels')) {
            Schema::table('tickets', function ($table): void {
                $table->dropColumn('labels');
            });
        }
    }
};
