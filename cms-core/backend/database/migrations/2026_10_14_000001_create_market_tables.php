<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| K8 — market tables: orders, licenses, settlement ledger, manual payouts, security notices.
| No gateway (K8.6 deferred): orders are confirmed manually, payouts are executed
| manually by an operator. Every money movement leaves a ledger row.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('plugin_id')->constrained('plugins')->cascadeOnDelete();
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('currency', 10)->default('IRT');
            $table->string('status', 20)->default('pending'); // pending|paid|cancelled
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('market_licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('plugin_id')->constrained('plugins')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('market_orders')->nullOnDelete();
            // K0.9 — license is valid until the end of the current core MAJOR.
            // Minor/patch core upgrades stay free: only the major number is compared.
            $table->unsignedInteger('valid_until_major');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'plugin_id']);
        });

        Schema::create('market_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('market_orders')->nullOnDelete();
            $table->foreignId('plugin_id')->nullable()->constrained('plugins')->nullOnDelete();
            $table->foreignId('publisher_key_id')->nullable()->constrained('publisher_keys')->nullOnDelete();
            $table->string('kind', 30); // sale|platform_fee|publisher_share|payout
            $table->bigInteger('amount'); // signed: payouts leave as positive rows of kind=payout
            $table->string('currency', 10)->default('IRT');
            $table->jsonb('meta')->nullable();
            $table->timestamps();
            $table->index(['kind']);
        });

        Schema::create('market_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publisher_key_id')->constrained('publisher_keys')->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 10)->default('IRT');
            $table->string('status', 20)->default('pending'); // pending|paid
            $table->text('note')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['status']);
        });

        Schema::create('market_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plugin_id')->nullable()->constrained('plugins')->cascadeOnDelete();
            $table->string('type', 30)->default('info'); // yank|unyank|info
            $table->text('message');
            $table->timestamps();
            $table->index(['type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_notices');
        Schema::dropIfExists('market_payouts');
        Schema::dropIfExists('market_ledger_entries');
        Schema::dropIfExists('market_licenses');
        Schema::dropIfExists('market_orders');
    }
};
