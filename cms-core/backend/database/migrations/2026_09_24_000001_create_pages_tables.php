<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| تسک ۱.۱ — مدل تک‌منبع انتشار (SUMMARY.md):
| pages.blocks = پیش‌نویس جاری، page_revisions = اسنپشات‌های append-only،
| pages.published_revision_id = تنها اشاره‌گر نسخه منتشرشده.
| revalidate_logs = ردپای امضاشده هر انتشار (HMAC+nonce via RevalidateSigner).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('status', 20)->default('draft'); // draft | published
            $table->jsonb('blocks')->default('[]');
            $table->jsonb('meta')->nullable(); // seo و ...
            $table->foreignId('published_revision_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('page_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('blocks')->default('[]');
            $table->jsonb('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['page_id', 'version']);
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->foreign('published_revision_id')
                ->references('id')->on('page_revisions')->nullOnDelete();
        });

        Schema::create('revalidate_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->jsonb('tags')->default('[]');
            $table->string('nonce', 64);
            $table->string('signature', 128);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropForeign(['published_revision_id']);
        });
        Schema::dropIfExists('revalidate_logs');
        Schema::dropIfExists('page_revisions');
        Schema::dropIfExists('pages');
    }
};
