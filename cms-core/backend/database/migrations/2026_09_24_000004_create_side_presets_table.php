<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| ستون‌های کناری + پریست‌های مشترک (ارجاع زنده، نه کپی):
| side_presets = واحد استفاده مجدد (نام، side، بلوک‌ها JSON)؛
| pages چهار فیلد: left/right_preset_id (nullable، ارجاع زنده) +
| left/right_enabled (پیش‌فرض true، toggle مستقل هر ستون).
| ویرایش یک پریست همه صفحات استفاده‌کننده را با تنظیمات جدید نشان می‌دهد
| چون خروجی عمومی بلوک‌ها را از روی پریست resolve می‌کند.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('side_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 100);
            $table->string('side', 10); // left | right
            $table->jsonb('blocks')->default('[]');
            $table->timestamps();

            $table->index(['user_id', 'side']);
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->foreignId('left_preset_id')->nullable()->constrained('side_presets')->nullOnDelete();
            $table->foreignId('right_preset_id')->nullable()->constrained('side_presets')->nullOnDelete();
            $table->boolean('left_enabled')->default(true);
            $table->boolean('right_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('left_preset_id');
            $table->dropConstrainedForeignId('right_preset_id');
            $table->dropColumn(['left_enabled', 'right_enabled']);
        });
        Schema::dropIfExists('side_presets');
    }
};
