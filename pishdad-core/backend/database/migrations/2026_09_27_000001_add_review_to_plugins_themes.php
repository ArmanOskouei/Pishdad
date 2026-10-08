<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| گردش تایید پلاگین/قالب (مورد ۶): آپلود مشتری → pending و غیرقابل فعال‌سازی؛
| تایید/رد فقط از پنل مرکزی. ردیف‌های موجود approved می‌مانند تا مسیر مهاجرت نشکند.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugins', function (Blueprint $table) {
            $table->string('review_status', 20)->default('approved')->after('signature_valid');
            $table->text('review_note')->nullable()->after('review_status');
            $table->timestamp('submitted_at')->nullable()->after('review_note');
            $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
            $table->index(['review_status']);
        });

        Schema::table('themes', function (Blueprint $table) {
            $table->string('review_status', 20)->default('approved')->after('signature_valid');
            $table->text('review_note')->nullable()->after('review_status');
            $table->timestamp('submitted_at')->nullable()->after('review_note');
            $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
            $table->index(['review_status']);
        });
    }

    public function down(): void
    {
        Schema::table('plugins', function (Blueprint $table) {
            $table->dropIndex(['review_status']);
            $table->dropColumn(['review_status', 'review_note', 'submitted_at', 'reviewed_at']);
        });
        Schema::table('themes', function (Blueprint $table) {
            $table->dropIndex(['review_status']);
            $table->dropColumn(['review_status', 'review_note', 'submitted_at', 'reviewed_at']);
        });
    }
};
