<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            // Simple role label (RBAC itself via spatie/laravel-permission).
            $table->string('role', 50)->default('admin')->after('phone');
            // Hidden superadmin: excluded from listings, non-deletable.
            $table->boolean('is_super_admin')->default(false)->after('role');
            $table->boolean('is_hidden')->default(false)->after('is_super_admin');
            // TOTP two-factor (pragmarx/google2fa) + hashed recovery codes.
            $table->string('google2fa_secret')->nullable()->after('is_hidden');
            $table->boolean('google2fa_enabled')->default(false)->after('google2fa_secret');
            $table->jsonb('recovery_codes')->nullable()->after('google2fa_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone', 'role', 'is_super_admin', 'is_hidden',
                'google2fa_secret', 'google2fa_enabled', 'recovery_codes',
            ]);
        });
    }
};
