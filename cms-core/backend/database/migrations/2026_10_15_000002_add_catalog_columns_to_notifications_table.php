<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4.2.B — `notifications` را **افزودن** ستون می‌کند، نه تعویض.
 *
 * ## ⭐ چرا تعویض ممنوع
 *
 * `2026_09_26_000001_create_phase5_tables.php` جدولِ استانداردِ لاراول را
 * ساخته (`K5.8` رویش نوشته: `uuid id` · `type` · `notifiable_type/id` ·
 * `data` · `read_at`) و `PluginNotification` + `NotificationController` روی همین
 * شکل کار می‌کنند. جدید کردنش یعنی `K5.8` (که `done` است) می‌شکند و مسیرِ
 * `POST /v1/admin/notifications` از کار می‌افتد.
 *
 * پس چهار ستون **اضافه** می‌شود که `Notifiable` لازم ندارد ولی ما لازم داریم:
 *
 * | ستون | چرا |
 * |---|---|
 * | `catalog_key` | کلیدِ ورودیِ `App\Notifications\Catalog`. **نمایه** است: رندرِ عنوان/بدنه از کاتالوگ می‌آید نه از دادهٔ ذخیره‌شده — پس متنِ یک اعلانِ ۶ ماه پیش با متنِ امروزِ کاتالوگ عوض می‌شود (یک منبعِ حقیقت)، ولی دادهٔ خام هم می‌ماند که تاریخچه را از دست ندهد. |
 * | `severity` | `data` یک ستونِ `text` است ⇒ فیلتر و شمارش روی آن یعنی `LIKE` روی JSON. ایندکس‌دار کردنِ شدت، صفِ صندوق را قابلِ صف‌بندی می‌کند. |
 * | `action_href` | ⭐ **از پیش از allowlist عبورکرده**. ذخیره‌کردنِ نتیجهٔ اعتبارسنجی یعنی خواندن هم fail-closed است: اگر بعداً کاتالوگ تنگ‌تر شد، سطرهای قدیمی هم ناامن نمی‌شوند چون هنوز اصلاً ذخیره نشده‌اند. |
 * | `action_label` | متنِ دکمه، کنار `action_href`. جدا چون برچسب بدون لینک هم معنا دارد و لینک بدون برچسب نه. |
 *
 * ⚠️ هر چهار **nullable/default** هستند. سطرهای `PluginNotification` (که این
 * چهار تا را نمی‌شناسند) باید بدون تغییر کار کنند — `NotificationController` به
 * `data` نگاه می‌کند و همچنان جواب می‌گیرد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            if (! Schema::hasColumn('notifications', 'catalog_key')) {
                $table->string('catalog_key', 80)->nullable()->after('type')->index();
            }

            if (! Schema::hasColumn('notifications', 'severity')) {
                $table->string('severity', 16)->default('info')->after('catalog_key')->index();
            }

            if (! Schema::hasColumn('notifications', 'action_href')) {
                $table->string('action_href', 200)->nullable()->after('severity');
            }

            if (! Schema::hasColumn('notifications', 'action_label')) {
                $table->string('action_label', 40)->nullable()->after('action_href');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            foreach (['action_label', 'action_href', 'severity', 'catalog_key'] as $column) {
                if (Schema::hasColumn('notifications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
