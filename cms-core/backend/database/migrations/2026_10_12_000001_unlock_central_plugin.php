<?php

use App\Models\Plugin;
use App\Models\SystemPlugin;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L-B12 — مرکز دیگر «پلاگین پیش‌فرض» نیست.
 *
 * ## چرا این تغییر
 *
 * `2026_10_08` مرکز را هم با `source = core` ثبت می‌کرد و هم در
 * `system_plugins` قفلش می‌کرد. قفل یعنی `PluginController::deactivate` و
 * `uninstall` هر دو ۴۲۲ می‌دادند — یعنی مرکز عملاً بخشی از هسته شده بود، نه یک
 * افزونه. این با «مرکز یک افزونهٔ خصوصیِ جدا است» ناسازگار بود.
 *
 * ## نکتهٔ مهم دربارهٔ `source`
 *
 * برخلاف تصور رایج، ستون `source` **هیچ رفتاری را کنترل نمی‌کند**. grep کل
 * `app/` نشان می‌دهد `SOURCE_LOCAL` فقط یک مقدار پیش‌فرض در ساخت مدل است و
 * هیچ شرطی روی `source` نیست. دروازهٔ واقعیِ غیرفعال‌سازی و حذف، فقط
 * `SystemPlugin::allows()` است. پس آنچه central را «پیش‌فرض» کرده بود، صرفاً
 * ردیف `system_plugins` بود — و همین را اینجا برمی‌داریم.
 *
 * `source` را هم به `local` تغییر می‌دهیم چون برچسب `core` در پنل دروغ می‌گفت:
 * کد مرکز در مخزن نیست، در پوشهٔ افزونه است.
 *
 * ## چرا `active` دست‌نخورده می‌ماند
 *
 * عمداً. مرکز فقط وقتی این ردیف را دارد که پوشه‌اش عمداً روی دیسک باشد — یعنی
 * در محیط خصوصی deploy شده. در نصب عمومی که پوشه نیست، `2026_10_08` اصلاً ردیفی
 * نمی‌سازد (به `readManifest()` نگاه کن) و این migration هم بی‌اثر است. پس
 * فعال‌بودن فقط در جایی معنا دارد که خودِ افزونه حاضر است.
 *
 * خاموش‌کردنش هنوز ممکن است: کاربر می‌تواند از پنل deactivate کند، چون دیگر
 * قفل سیستمی نیست.
 */
return new class extends Migration
{
    private const SLUG = 'central';

    public function up(): void
    {
        if (! Schema::hasTable('plugins')) {
            return;
        }

        // قفل سیستمی را بردار. این تنها چیزی است که مرکز را غیرقابل‌حذف می‌کرد.
        if (Schema::hasTable('system_plugins')) {
            DB::table('system_plugins')->where('slug', self::SLUG)->delete();
            SystemPlugin::flush();
        }

        // برچسب صادقانه. روی نصب‌هایی که ردیف مرکز را ندارند بی‌اثر است.
        DB::table('plugins')
            ->where('slug', self::SLUG)
            ->update(['source' => Plugin::SOURCE_LOCAL, 'updated_at' => now()]);
    }

    /**
     * بازگرداندن حالت قفل‌شده فقط وقتی معنی دارد که بسته هنوز حاضر باشد — وگرنه
     * یک ردیف یتیم در `system_plugins` می‌سازیم که به هیچ افزونه‌ای اشاره
     * نمی‌کند و `Plugin::isSystem()` را برای slugی روشن نگه می‌دارد که وجود ندارد.
     */
    public function down(): void
    {
        if (! Schema::hasTable('plugins')) {
            return;
        }

        if (! is_file(base_path('plugins/'.self::SLUG.'/manifest.json'))) {
            return;
        }

        SystemPlugin::ensureMany([self::SLUG], 'قفل بازگشتی — rollback L-B12');

        DB::table('plugins')
            ->where('slug', self::SLUG)
            ->update(['source' => Plugin::SOURCE_CORE, 'updated_at' => now()]);
    }
};
