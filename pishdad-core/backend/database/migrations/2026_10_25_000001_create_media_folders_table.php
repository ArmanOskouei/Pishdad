<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* WF-H6 — پوشهٔ درختی رسانه (parent_id خودارجاع، nullOnDelete ⇒ فرزند به ریشه). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('parent_id')->nullable()->constrained('media_folders')->nullOnDelete();
            $table->timestamps();

            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_folders');
    }
};
