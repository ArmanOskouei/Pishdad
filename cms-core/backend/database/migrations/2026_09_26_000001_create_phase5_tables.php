<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| مرحله ۵ (تسک‌های ۵.۳ تا ۵.۷) — جدول‌های عملیاتیِ **هسته**.
| - تیکت‌ها + پیام‌ها (۵.۳)
| - پلاگین‌ها + رجیستری هوک‌ها (۵.۴)
| - notifications استاندارد لاراول (اعلان داخلی تیکت)
|
| این فایل فقط جدول‌های هسته را می‌سازد. هیچ جدولِ افزونه‌ای اینجا ساخته
| نمی‌شود: جدولِ هر افزونه در مهاجرت‌های خودِ همان بسته می‌آید و با
| `Schema::hasTable` گارد شده است.
|
| ⚠️ نامِ جدول‌های هسته در این docblock نقل نشده تا آزمونِ مرزِ جداول
| (که متنِ خامِ فایل را می‌گردد) بی‌دلیل قرمز نشود.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 200);
            $table->string('status', 20)->default('open'); // open|pending|closed
            $table->string('priority', 20)->default('normal'); // low|normal|high
            $table->string('contact_name', 150)->nullable();
            $table->string('contact_email', 200)->nullable();
            $table->string('source', 20)->default('panel'); // panel|contact
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_type', 20)->default('admin'); // admin|operator|contact
            $table->text('body');
            $table->foreignId('attachment_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->timestamps();
            $table->index(['ticket_id', 'created_at']);
        });

        Schema::create('plugins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('slug', 100);
            $table->string('version', 30)->default('1.0.0');
            $table->string('previous_version', 30)->nullable();
            $table->boolean('active')->default(false);
            $table->boolean('system')->default(false);
            $table->boolean('signature_valid')->default(false);
            $table->string('checksum', 64)->nullable(); // sha256 فایل ZIP
            $table->jsonb('manifest')->nullable();
            $table->string('path')->nullable();
            $table->timestamps();
            $table->unique('slug');
        });

        Schema::create('plugin_hooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plugin_id')->constrained('plugins')->cascadeOnDelete();
            $table->string('hook', 100);
            $table->string('handler', 200)->nullable();
            $table->timestamps();
            $table->index(['plugin_id', 'hook']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('plugin_hooks');
        Schema::dropIfExists('plugins');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
    }
};
