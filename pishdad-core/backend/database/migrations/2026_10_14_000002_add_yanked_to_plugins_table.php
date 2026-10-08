<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| K8.3 + K0.7 — yanked flag on plugins.
|
| yanked = record stays, fresh installs blocked, existing installs keep working.
| K0.7 yanked-non-deletable: a yanked row must never be deleted. That rule is
| enforced in App\Services\Market\ReviewService::ensureDeletable() (the market
| layer). NOTE: PluginController::uninstall is frozen for K8 and does NOT call
| that guard — see the TODO in ReviewService.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugins', function (Blueprint $table) {
            $table->boolean('yanked')->default(false)->after('review_note');
            $table->timestamp('yanked_at')->nullable()->after('yanked');
            $table->text('yank_reason')->nullable()->after('yanked_at');
            $table->index(['yanked']);
        });
    }

    public function down(): void
    {
        Schema::table('plugins', function (Blueprint $table) {
            $table->dropIndex(['yanked']);
            $table->dropColumn(['yanked', 'yanked_at', 'yank_reason']);
        });
    }
};
