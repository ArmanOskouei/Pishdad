<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| WF-H2 — لینکِ اشتراکِ پیش‌نویس.
| توکن سمتِ سرور بی‌وضعیت (HMAC) است؛ این جدول فقط برای «لغو» و اعتبارسنجی
| باقی می‌ماند. با حذفِ ردیف، توکنِ امضاشده دیگر پذیرفته نمی‌شود.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_share_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->foreignId('revision_id')->constrained('page_revisions')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('page_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_share_links');
    }
};
