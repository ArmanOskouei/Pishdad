<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| تسک ۴.۲ — قالب‌های سایت (مشترک نصب تک‌سایتی؛ user_id فقط سازنده/حسابرسی).
| امضای Ed25519 واقعی TODO مرحله ۵ است: تا آن زمان آپلود با
| signature_valid=false پذیرفته می‌شود (در ThemeController مستند شده).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('themes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('slug', 100);
            $table->string('version', 30)->default('1.0.0');
            $table->boolean('active')->default(false);
            $table->boolean('signature_valid')->default(false);
            $table->jsonb('manifest')->nullable();
            $table->string('path')->nullable(); // محل ذخیره ZIP در storage
            $table->timestamps();
            $table->unique('slug');
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('themes');
    }
};
