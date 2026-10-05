<?php

use App\Services\Plugins\ManifestRegistry;
use App\Services\Plugins\PluginPageContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * K7.18 — مهاجرت افزونه‌های نصب‌شدهٔ قبلی به فیلد `blocks` صفحه‌ها.
 *
 * ## مسئله‌ای که این مهاجرت حل می‌کند
 *
 * `PluginPackageValidator` از این commit به بعد `pages[key].blocks` را هنگام
 * **نصب** fail-closed می‌کند. ولی افزونه‌هایی که *قبلاً* نصب شده‌اند از آن دروازه
 * رد نشده‌اند و `manifest` ذخیره‌شده‌شان در ستون `plugins.manifest` ممکن است:
 *
 *  - `layout` بیرون از allowlist داشته باشد ⇒ `normalizePageRegistryEntry` آن
 *    صفحه را `null` می‌کند ⇒ صفحه در `pageRegistry()` غایب می‌شود.
 *  - `blocks` نداشته باشد ⇒ بی‌ضرر، ولی صفحه خالی می‌ماند.
 *
 * اگر هیچ کاری نکنیم، یک ارتقای کد، صفحات افزونه‌های موجود را **بی‌صدا** حذف
 * می‌کند. حذف بی‌صدا بدترین حالت است: نه خطایی می‌بینیم، نه ادمین.
 *
 * ## چرا این کار «حذف محتوا» نیست
 *
 * عمداً چیزی را پاک نمی‌کنیم. فقط `layout` نامعتبر را به مقدار مجاز می‌نشانیم
 * و `blocks` غیرقانونی را **فیلتر** می‌کنیم (نوع‌های مجاز می‌مانند، بقیه
 * می‌روند). دادهٔ کاربر دست‌نخورده می‌ماند؛ فقط چیزی که هرگز رندر نمی‌شد
 * از مسیر رندر خارج می‌شود. اگر نویسنده بعداً `layout` را درست کند، محتوای
 * بلوک‌هایش همان‌جاست.
 *
 * ## چرا migration و نه فقط `sync`
 *
 * ستون `manifest` یک JSON است و نسخهٔ پشتیبان ندارد. یک بار اسکن کردن در ارتقا
 * تضمین می‌کند وضعیت هر نصب سالم است؛ هر اجرای بعدی ولی باید **بی‌اثر** باشد
 * (idempotent) چون ممکن است نصب تازه، مانیفست کهنه داشته باشد که کاربر بعداً
 * خودش آپلود کرده.
 *
 * ## چرا شکست نمی‌دهیم
 *
 * یک افزونهٔ خراب نباید کل ارتقا را متوقف کند. بدترین حالت این است که یک
 * صفحه نمایش داده نشود — که همان رفتارِ درست و fail-closed است.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plugins')) {
            return;
        }

        $changed = 0;
        $pagesSeen = 0;

        DB::table('plugins')
            ->select('id', 'slug', 'manifest')
            ->whereNotNull('manifest')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$changed, &$pagesSeen): void {
                foreach ($rows as $row) {
                    $decoded = json_decode((string) $row->manifest, true);

                    if (! is_array($decoded)) {
                        continue;
                    }

                    $pages = $decoded['pages'] ?? null;
                    if (! is_array($pages)) {
                        continue;
                    }

                    $pagesSeen++;

                    $rewritten = $this->rewritePages($pages, $changed);

                    if ($rewritten === null) {
                        continue;
                    }

                    $decoded['pages'] = $rewritten;

                    DB::table('plugins')
                        ->where('id', $row->id)
                        ->update([
                            'manifest' => json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                        ]);
                }
            });

        if ($changed > 0) {
            ManifestRegistry::flushCache();
        }
    }

    public function down(): void
    {
        // برگشت‌پذیر نیست و عمداً: اطلاعات از بین رفته (یک `layout` نامعتبر و
        // بلوک‌های نوع‌غیرمجاز) قابل بازسازی نیست چون هرگز رندر نمی‌شدند.
        // برگرداندنشان یعنی برگرداندن وضعیتی که پنل را خراب می‌کرد.
    }

    /**
     * @param  array<mixed, mixed>  $pages
     * @return array<mixed, mixed>|null null یعنی هیچ تغییری لازم نبود
     */
    private function rewritePages(array $pages, int &$changed): ?array
    {
        $allowed = PluginPageContract::allowedBlockTypes();
        $out = $pages;
        $dirty = false;

        foreach ($pages as $key => $def) {
            if (! is_string($key) || ! is_array($def) || array_is_list($def)) {
                continue;
            }

            // --- layout ----------------------------------------------------
            if (array_key_exists('layout', $def)) {
                $layout = $def['layout'];
                if (! in_array($layout, PluginPageContract::LAYOUTS, true)) {
                    $out[$key]['layout'] = PluginPageContract::DEFAULT_LAYOUT;
                    $dirty = true;
                }
            }

            // --- blocks ----------------------------------------------------
            if (! array_key_exists('blocks', $def) || $def['blocks'] === null) {
                continue;
            }

            $blocks = $def['blocks'];
            if (! is_array($blocks) || ! array_is_list($blocks)) {
                $out[$key]['blocks'] = [];
                $dirty = true;

                continue;
            }

            $kept = [];
            foreach ($blocks as $block) {
                if (! is_array($block) || array_is_list($block)) {
                    continue;
                }

                $type = $block['type'] ?? null;
                if (! is_string($type) || ! in_array($type, $allowed, true)) {
                    continue;
                }

                $data = $block['data'] ?? null;
                if (! is_array($data) || (array_is_list($data) && $data !== [])) {
                    continue;
                }

                $entry = ['type' => $type, 'data' => $data];
                if (array_key_exists('_enabled', $block) && $block['_enabled'] === false) {
                    $entry['_enabled'] = false;
                }

                $kept[] = $entry;
            }

            if (count($kept) !== count($blocks) || array_keys($kept) !== range(0, max(count($kept) - 1, 0))) {
                $out[$key]['blocks'] = array_values($kept);
                $dirty = true;
            }
        }

        if (! $dirty) {
            return null;
        }

        $changed++;

        return $out;
    }
};
