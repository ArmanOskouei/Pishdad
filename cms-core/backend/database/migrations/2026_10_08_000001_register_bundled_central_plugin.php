<?php

use App\Models\Plugin;
use App\Models\SystemPlugin;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * K7.8 — ثبت افزونهٔ مرکزی، به‌عنوان بستهٔ داخلی.
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
 * ## چرا `source = core` و نه `local`
 *
 * `local` یعنی «کاربر آپلود کرده» و در `PluginController` مسیر غیرفعال‌سازی و
 * حذف دارد. افزونهٔ مرکزی هیچ‌کدام را نباید داشته باشد: قابل غیرفعال‌سازی
 * نیست چون heartbeat و درگاه به مدل‌هایش وابسته‌اند، و قابل حذف هم نیست چون
 * در مخزن است نه در storage.
 *
 * `system = true` را `system_plugins` می‌دهد، نه این ستون — عمداً. آن جدول
 * allowlist است و manifest هرگز نمی‌تواند خودش را داخل آن بگذارد (K5.7).
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

        DB::table('plugins')->updateOrInsert(
            ['slug' => 'central'],
            [
                'name' => $manifest['name'] ?? 'مرکز',
                'version' => $manifest['version'] ?? '1.0.0',
                // فعال از ابتدا: بدون این، پنل مرکزی بعد از نصب ۴۰۴ می‌داد در
                // حالی که سوپرادمین برای فعال‌کردنش باید وارد پنلی می‌شد که
                // ۴۰۴ می‌داد. یعنی بن‌بست.
                'active' => true,
                // دستی نیست ⇒ امضا هم ندارد. امضا فقط از راه بازار معتبر است.
                'signature_valid' => false,
                'checksum' => null,
                'manifest' => json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                // مسیر نسبی نیست: کد در مخزن است. `PluginReleaseManager` خودش
                // `sourceAutoloadRoot()` را امتحان می‌کند و `storage` را اصلاً
                // نمی‌بیند.
                'path' => 'plugins/central',
                'source' => Plugin::SOURCE_CORE,
                'review_status' => Plugin::REVIEW_APPROVED,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        // افزونهٔ سیستمی: غیرقابل غیرفعال‌سازی و غیرقابل حذف.
        SystemPlugin::ensureMany(['central'], 'قالب مرکزی — K7.8');
    }

    public function down(): void
    {
        if (! Schema::hasTable('plugins')) {
            return;
        }

        DB::table('plugins')->where('slug', 'central')->delete();
        DB::table('system_plugins')->where('slug', 'central')->delete();
        SystemPlugin::flush();
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
