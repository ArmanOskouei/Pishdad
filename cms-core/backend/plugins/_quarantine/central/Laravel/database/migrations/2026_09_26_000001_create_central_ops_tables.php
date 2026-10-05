<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| J5 — جدول‌های Ops/پایش/به‌روزرسانی افزونهٔ مرکزی (بخشِ جداشده از
| `2026_09_26_000001_create_phase5_tables` در core).
|
| ## چرا نام‌ها اینجا «خام» و بدون `central_` هستند
|
| core تا `2026_10_05` این جدول‌ها را با نامِ بدون prefix می‌ساخت
| (`install_heartbeats`, `core_versions`, `update_rollouts`, `kill_switches`, …) و
| آن migration بعداً rename می‌کرد. اینجا مستقیم با نامِ **نهایی** ساخته می‌شوند،
| فقط به شکل خام — `PluginMigrationRunner` با prefix اسلاگ (`central`) همان نامِ
| نهایی را بازمی‌نویسد و `central_install_heartbeats` و … درست در یک قدم.
|
| یعنی rename دیگر لازم نیست و `2026_10_05` فقط **مهاجرتِ داده برای نصب‌های
| موجود** می‌ماند: روی نصب تازه هیچ جدولِ بدون prefixی وجود ندارد، پس بی‌اثر است.
|
| `down()` ترتیبِ معکوس دارد چون `update_rollout_items` به `update_rollouts` و
| `clients` وابسته است.
|
| دلیلِ guardِ `hasTable` و حذفِ FK به `users` در
| `2026_09_24_000001_create_central_billing_tables.php` آمده.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('install_heartbeats')) {
            Schema::create('install_heartbeats', function (Blueprint $table) {
                $table->id();
                $table->string('site_id', 100)->unique();
                $table->string('status', 20)->default('online'); // online|degraded|down
                $table->string('version', 30)->nullable();
                $table->jsonb('payload')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('logs')) {
            Schema::create('logs', function (Blueprint $table) {
                $table->id();
                $table->string('site_id', 100)->nullable();
                $table->string('level', 20)->default('info'); // debug|info|warning|error|critical
                $table->text('message');
                $table->jsonb('context')->nullable();
                $table->timestamps();
                $table->index(['site_id', 'level']);
                $table->index('created_at');
            });
        }

        if (! Schema::hasTable('core_versions')) {
            Schema::create('core_versions', function (Blueprint $table) {
                $table->id();
                $table->string('version', 30)->unique();
                $table->text('notes')->nullable();
                $table->boolean('is_latest')->default(false);
                $table->timestamp('released_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('update_rollouts')) {
            Schema::create('update_rollouts', function (Blueprint $table) {
                $table->id();
                $table->string('version', 30);
                $table->string('status', 20)->default('pending'); // pending|in_progress|done|rolled_back
                $table->unsignedInteger('batch_size')->default(10);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('update_rollout_items')) {
            Schema::create('update_rollout_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rollout_id')->constrained('update_rollouts')->cascadeOnDelete();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->string('from_version', 30)->nullable();
                $table->string('to_version', 30);
                $table->string('previous_version', 30)->nullable();
                $table->string('status', 20)->default('pending'); // pending|applied|failed|rolled_back
                $table->unsignedInteger('batch_index')->default(0);
                $table->timestamps();
                $table->unique(['rollout_id', 'client_id']);
            });
        }

        if (! Schema::hasTable('operator_hardware_keys')) {
            Schema::create('operator_hardware_keys', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('name', 100);
                $table->text('credential');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('operator_setup_tokens')) {
            Schema::create('operator_setup_tokens', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('token_hash', 64)->unique();
                $table->string('purpose', 150)->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('operator_activities')) {
            Schema::create('operator_activities', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('action', 100);
                $table->string('ip', 45)->nullable();
                $table->jsonb('meta')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'action']);
            });
        }

        if (! Schema::hasTable('kill_switches')) {
            Schema::create('kill_switches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('executed_by')->nullable();
                $table->text('reason');
                $table->boolean('confirmed')->default(false);
                $table->string('scope', 30)->default('all');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kill_switches');
        Schema::dropIfExists('operator_activities');
        Schema::dropIfExists('operator_setup_tokens');
        Schema::dropIfExists('operator_hardware_keys');
        Schema::dropIfExists('update_rollout_items');
        Schema::dropIfExists('update_rollouts');
        Schema::dropIfExists('core_versions');
        Schema::dropIfExists('logs');
        Schema::dropIfExists('install_heartbeats');
    }
};
