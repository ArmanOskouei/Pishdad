<?php

use App\Models\Plugin;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L-B12 — ثبتِ افزونهٔ «اشتراک من» به‌عنوان یک بستهٔ **خصوصی و جدا**.
 *
 * ## چرا migration و نه seeder
 *
 * همان دلیل مرکز: نبودِ این ردیف، کل پنلِ اشتراک را می‌شکند و UI زمانی
 * پاسخ می‌گیرد که باید «سرویس فعال نیست» را نشان دهد. پس دادهٔ schema است،
 * نه دادهٔ نمونه.
 *
 * ## چرا این migration در repo عمومی می‌ماند ولی بی‌اثر است
 *
 * اگر `plugins/subscription/manifest.json` حاضر باشد — یعنی در محیط خصوصی —
 * ثبت می‌شود. در نصبِ عمومی که پوشه نیست، `readManifest()` برمی‌گرداند `null` و
 * این migration **هیچ ردیفی نمی‌سازد**. یعنی هستهٔ منتشرشده اسمی از افزونهٔ
 * خصوصی نمی‌داند، ولی محیطِ خصوصی بدون کارِ دستی بالا می‌آید.
 *
 * ## `active` چرا `true` است
 *
 * پنلِ مشتری («اشتراک من») خودش یک قابلیتِ محصول است. اگر `active=false` بود،
 * کاربر برای دیدنِ وضعیتش باید از پنلی وارد شود که برای فعال‌کردنِ خودش به همان
 * پنل نیاز دارد — یعنی بن‌بست. فعال‌بودن فقط وقتی معنا دارد که بسته واقعاً
 * حاضر باشد.
 */
return new class extends Migration
{
    private const SLUG = 'subscription';

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
            'name' => $manifest['name'] ?? 'اشتراک من',
            'version' => $manifest['version'] ?? '1.0.0',
            // بستهٔ خصوصیِ دستی ⇒ امضا ندارد. امضا فقط از راه بازار معتبر است.
            'signature_valid' => false,
            'checksum' => null,
            'manifest' => json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'path' => 'plugins/'.self::SLUG,
            'source' => Plugin::SOURCE_LOCAL,
            'review_status' => Plugin::REVIEW_APPROVED,
            'updated_at' => $now,
        ];

        // ⭐ `active` فقط روی **insert** — هرگز روی update.
        //
        // همان قاعدهٔ مرکز (`2026_10_08_…`): اجرای مجددِ migration نباید تصمیمِ
        // آگاهانهٔ اپراتور را برگرداند. ردیف نبود ⇒ نصبِ اول ⇒ فعال از ابتدا
        // (وگرنه خودساختِ رکورد روی `show` هیچ‌وقت رخ نمی‌داد). ردیف بود ⇒ فقط
        // مانیفست/نسخه/مسیر تازه می‌شود و `active` دست‌نخورده می‌ماند.
        $exists = DB::table('plugins')->where('slug', self::SLUG)->exists();

        if ($exists) {
            DB::table('plugins')->where('slug', self::SLUG)->update($row);
        } else {
            DB::table('plugins')->insert(array_merge(
                ['slug' => self::SLUG, 'active' => true, 'created_at' => $now],
                $row,
            ));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('plugins')) {
            return;
        }

        DB::table('plugins')->where('slug', self::SLUG)->delete();
    }

    /**
     * ⚠️ عمداً migration را **نمی‌شکند** وقتی بسته غایب است — همان قاعدهٔ مرکز.
     * یک نصب عمومی که هرگز `plugins/subscription/` را نداشته باید بالا بیاید و
     * پنلِ هسته‌ای خودش را داشته باشد؛ فقط قابلیتِ افزونه‌ای غایب است.
     */
    private function readManifest(): ?array
    {
        // L-B12 — در محیط تست، این migration هیچ ردیفی نمی‌سازد.
        //
        // ⭐ دلیلش دقیقاً همانِ مرکز است (`2026_10_08_…`): `is_file()` پایین پوشهٔ
        // `plugins/subscription/` در نصبِ خصوصی پیدا می‌شود، پس ردیف ساخته
        // می‌شد و تا پایانِ اجرای تست در `plugins` می‌ماند. آن‌وقت هر تستی که
        // انتظارِ «هیچ افزونه‌ای فعال نیست» دارد می‌شکند — و درست می‌گوید،
        // چون واقعاً هیچ تستی «اشتراک» را نخواسته بود.
        //
        // نکتهٔ مهم: این نشت را خودِ ما ساختیم، نه پروژه. مانیفستِ اشتراک
        // `pages` اعلام می‌کند، پس رجیستری صفحات هم لو می‌رفت — و آن تست
        // (`PluginPageRegistryTest`) از اول درست می‌گفت.
        //
        // این guard **فقط** تستی است. در production/dev ثبتِ خودکار دست‌نخورده
        // می‌ماند.
        if (app()->environment('testing')) {
            return null;
        }

        $path = base_path('plugins/'.self::SLUG.'/manifest.json');

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ($decoded['slug'] ?? null) !== self::SLUG) {
            return null;
        }

        return $decoded;
    }
};
