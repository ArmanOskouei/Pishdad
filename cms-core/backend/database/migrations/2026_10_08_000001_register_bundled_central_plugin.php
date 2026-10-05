<?php

use App\Models\Plugin;
use App\Models\SystemPlugin;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * K7.8 — ثبت افزونهٔ مرکزی. (از L-B12: دیگر «بستهٔ داخلی» نیست، یک افزونهٔ
 * خصوصیِ جداشدنی است.)
 *
 * ## چرا migration و نه seeder
 *
 * چون نبودِ این ردیف، پنل مرکزی را **کاملاً** از کار می‌اندازد — نه فقط صفحه‌های
 * مرکزی، بلکه heartbeat نصب‌ها و callback درگاه را هم که از همین جدول‌ها
 * می‌خوانند. یک نصب تازه که این migration اجرا نشود، یک سایت بدون پنل مدیریت
 * است. پس این داده **part of schema** است، نه دادهٔ نمونه.
 *
 * ## چرا `idempotent` و نه `updateOrCreate` تن
 *
 * چون `manifest` از روی دیسک خوانده می‌شود و با هر تغییر کد عوض می‌شود. اگر
 * migration فقط یک‌بار در نصب اجرا شود، مانیفست برای همیشه کهنه می‌ماند. پس
 * یک command جدا (`plugin:sync-bundled`) هم هست که این ردیف را با مانیفست
 * تازه همگام می‌کند — این migration فقط نقطهٔ شروع است.
 *
 * ## چرا `source = local` و نه `core` (L-B12)
 *
 * تا `2026_10_12` این مقدار `core` بود و مرکز در `system_plugins` هم قفل می‌شد.
 * حاصلش این بود که مرکز «بخشی از هسته» شد، نه یک افزونه — `deactivate` و
 * `uninstall` هر دو ۴۲۲ می‌دادند. حالا مرکز یک افزونهٔ خصوصیِ معمولی است:
 * قابل غیرفعال‌سازی و قابل حذف.
 *
 * نکتهٔ فنی که این را ممکن می‌کند: **ستون `source` هیچ رفتاری را کنترل نمی‌کند.**
 * در کل `app/` تنها کاربرد `SOURCE_LOCAL` یک مقدار پیش‌فرض در ساخت مدل است و
 * هیچ شرطی روی `source` نیست. دروازهٔ واقعی فقط `SystemPlugin::allows()` است.
 * پس تغییر برچسب، autoload را هم نمی‌شکند: بارگذاری از `sourceAutoloadRoot()`
 * می‌آید که فقط «پوشه وجود دارد» را می‌سنجد، نه `source` را.
 *
 * ## چرا اصلاً در core نیست و در پوشهٔ افزونه
 *
 * مرکز کاملاً خصوصی است و نباید در نسخهٔ منتشرشدهٔ گیت‌هاب باشد. همین
 * `readManifest()` تضمین می‌کند که در نصب عمومی — که `plugins/central/`
 * وجود ندارد — این migration **بی‌اثر** بماند و اصلاً ردیفی ساخته نشود.
 * در محیط خصوصی که بسته عمداً deploy شده، همین‌جا ثبت می‌شود و
 * `plugin:sync-bundled`/آپلود، مانیفست را تازه نگه می‌دارد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plugins')) {
            return;
        }

        $manifest = $this->readManifest();

        if ($manifest === null) {
            return;
        }

        $now = now();

        $row = [
            'name' => $manifest['name'] ?? 'مرکز',
            'version' => $manifest['version'] ?? '1.0.0',
            // دستی نیست ⇒ امضا هم ندارد. امضا فقط از راه بازار معتبر است.
            'signature_valid' => false,
            'checksum' => null,
            'manifest' => json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            // مسیر نسبی نیست: کد در پوشهٔ افزونه است. `PluginReleaseManager`
            // خودش `sourceAutoloadRoot()` را امتحان می‌کند و `storage` را
            // اصلاً نمی‌بیند.
            'path' => 'plugins/central',
            'source' => Plugin::SOURCE_LOCAL,
            'review_status' => Plugin::REVIEW_APPROVED,
            'updated_at' => $now,
        ];

        // ⭐ `active` فقط روی **insert** — هرگز روی update.
        //
        // چرا این‌قدر مهم است: اگر این migration دوباره اجرا شود (رکوردِ
        // `migrations` پاک شده، یا `migrate:fresh` روی یک نصبِ واقعی)،
        // `updateOrInsert` با `'active' => true` افزونه‌ای را که اپراتور **عمداً**
        // غیرفعال کرده بود **زنده می‌کرد**. در DB زندهٔ همین پروژه، مرکز الان
        // `active=false` است — یعنی دقیقاً همین انتخابِ آگاهانه وجود دارد.
        //
        // پس: ردیف نبود ⇒ نصبِ اول است ⇒ فعال از ابتدا (وگرنه سوپرادمین برای
        // فعال‌کردن باید وارد پنلی می‌شد که ۴۰۴ می‌داد — بن‌بست). ردیف بود ⇒
        // فقط مانیفست/نسخه/مسیر تازه می‌شود و تصمیمِ اپراتور دست‌نخورده می‌ماند.
        $exists = DB::table('plugins')->where('slug', 'central')->exists();

        if ($exists) {
            DB::table('plugins')->where('slug', 'central')->update($row);
        } else {
            DB::table('plugins')->insert(array_merge(
                ['slug' => 'central', 'active' => true, 'created_at' => $now],
                $row,
            ));
        }

        // ⚠️ عمداً دیگر در `system_plugins` قفل نمی‌شود (L-B12). قفل یعنی
        // «پلاگین سیستمی» و آن یعنی نه deactivate می‌شود نه uninstall. مرکز یک
        // افزونهٔ خصوصیِ جداشدنی است، نه بخشی از هسته.
    }

    public function down(): void
    {
        if (! Schema::hasTable('plugins')) {
            return;
        }

        DB::table('plugins')->where('slug', 'central')->delete();

        if (Schema::hasTable('system_plugins')) {
            DB::table('system_plugins')->where('slug', 'central')->delete();
            SystemPlugin::flush();
        }
    }

    /**
     * مانیفست را از دیسک می‌خواند، و اگر نبود یا خراب بود `null` می‌دهد.
     *
     * ⚠️ عمداً migration را **نمی‌شکند** وقتی بسته غایب است. یک نصب که هنوز
     * `plugins/central/` را deploy نکرده هنوز باید بالا بیاید و پنل هسته‌ای
     * خودش را داشته باشد — فقط قابلیت افزونه‌ای غایب است. شکستن migration
     * کل نصب را متوقف می‌کرد برای چیزی که یک قابلیتِ اختیاری است.
     */
    private function readManifest(): ?array
    {
        // L-B12 — در محیط تست، این migration هیچ ردیفی نمی‌سازد.
        //
        // ⭐ چرا لازم است، دقیقاً: `is_file()` پایین پوشهٔ `plugins/central/` را
        // در نصبِ خصوصی پیدا می‌کند، پس ردیف ساخته می‌شد — و آن ردیف تا پایانِ
        // کلِ اجرای تست زنده می‌ماند. `RefreshDatabase` جدول `plugins` را خالی
        // می‌کند ولی **بعد** از این migration است، نه قبلش. نتیجه: تست‌هایی که
        // انتظارِ «جدول افزونه‌ها خالی است» دارند می‌شکستند، و آن انتظار **درست**
        // بود — نه اشتباهِ آن‌ها، بلکه نشتِ این migration.
        //
        // راهِ درست این نیست که انتظارِ تست‌ها را شل کنیم (بعضی از آن‌ها واقعاً
        // دارند رفتارِ «هیچ افزونه‌ای نصب نیست» را می‌سنجند). راهِ درست این است که
        // پایهٔ تست، پایهٔ **تمیز** باشد و هر تستی که افزونه می‌خواهد خودش
        // `serveCentralPlugin()` را در `setUp()` صدا بزند.
        //
        // این guard **فقط** تستی است: `testing` یعنی اجرای PHPUnit روی
        // `cms_test_a`، نه نصبِ واقعی. در production/dev مسیرِ ثبتِ خودکار
        // دست‌نخورده می‌ماند — چون `app()->environment('testing')` آنجا false است.
        if (app()->environment('testing')) {
            return null;
        }

        $path = base_path('plugins/central/manifest.json');

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ($decoded['slug'] ?? null) !== 'central') {
            return null;
        }

        return $decoded;
    }
};
