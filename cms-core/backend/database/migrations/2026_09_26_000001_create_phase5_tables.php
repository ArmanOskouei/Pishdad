<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| مرحله ۵ (تسک‌های ۵.۳ تا ۵.۷) — جدول‌های عملیاتی.
| - تیکت‌ها + پیام‌ها (۵.۳)
| - پلاگین‌ها + رجیستری هوک‌ها (۵.۴)
| - heartbeat نصب‌ها + لاگ متمرکز (۵.۵)
| - رجیستری نسخه‌ها + رول‌اوت پلکانی (۵.۶)
| - کلید سخت‌افزاری + توکن نصب + فعالیت اپراتور + kill-switch (۵.۷)
| - notifications استاندارد لاراول (اعلان داخلی تیکت)
| - ستون‌های install_token و current_version روی central_clients
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

        Schema::create('install_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('site_id', 100)->unique();
            $table->string('status', 20)->default('online'); // online|degraded|down
            $table->string('version', 30)->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('central_logs', function (Blueprint $table) {
            $table->id();
            $table->string('site_id', 100)->nullable();
            $table->string('level', 20)->default('info'); // debug|info|warning|error|critical
            $table->text('message');
            $table->jsonb('context')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'level']);
            $table->index('created_at');
        });

        Schema::create('core_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version', 30)->unique();
            $table->text('notes')->nullable();
            $table->boolean('is_latest')->default(false);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        Schema::create('update_rollouts', function (Blueprint $table) {
            $table->id();
            $table->string('version', 30);
            $table->string('status', 20)->default('pending'); // pending|in_progress|done|rolled_back
            $table->unsignedInteger('batch_size')->default(10);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('update_rollout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rollout_id')->constrained('update_rollouts')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('central_clients')->cascadeOnDelete();
            $table->string('from_version', 30)->nullable();
            $table->string('to_version', 30);
            $table->string('previous_version', 30)->nullable();
            $table->string('status', 20)->default('pending'); // pending|applied|failed|rolled_back
            $table->unsignedInteger('batch_index')->default(0);
            $table->timestamps();
            $table->unique(['rollout_id', 'client_id']);
        });

        Schema::create('operator_hardware_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('credential'); // سکرت TOTP رمزنگاری‌شده (WebAuthn واقعی TODO)
            $table->timestamps();
        });

        Schema::create('operator_setup_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('purpose', 150)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('operator_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('ip', 45)->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'action']);
        });

        // لاگ غیرقابل حذف kill-switch: هیچ UPDATE/DELETE در سطح مدل مجاز نیست (مدل KillSwitch).
        Schema::create('kill_switches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->boolean('confirmed')->default(false);
            $table->string('scope', 30)->default('all');
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::table('central_clients', function (Blueprint $table) {
            $table->string('install_token', 64)->nullable()->unique()->after('site_id');
            $table->string('current_version', 30)->nullable()->after('install_token');
        });
    }

    public function down(): void
    {
        Schema::table('central_clients', function (Blueprint $table) {
            $table->dropColumn(['install_token', 'current_version']);
        });
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('kill_switches');
        Schema::dropIfExists('operator_activities');
        Schema::dropIfExists('operator_setup_tokens');
        Schema::dropIfExists('operator_hardware_keys');
        Schema::dropIfExists('update_rollout_items');
        Schema::dropIfExists('update_rollouts');
        Schema::dropIfExists('core_versions');
        Schema::dropIfExists('central_logs');
        Schema::dropIfExists('install_heartbeats');
        Schema::dropIfExists('plugin_hooks');
        Schema::dropIfExists('plugins');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
    }
};
