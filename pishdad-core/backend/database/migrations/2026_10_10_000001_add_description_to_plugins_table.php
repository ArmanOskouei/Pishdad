<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B35 — ستون `description` برای افزونه‌ها.
 *
 * ## مسئله
 *
 * `PluginController::present()` فقط `toArray()` و `trust` برمی‌گرداند، و جدول
 * `plugins` ستون نداشت. نتیجه: در فهرست پنل هیچ‌کس نمی‌فهمید یک افزونه اصلاً چه
 * کار می‌کند — «blog B» فقط یک نام است.
 *
 * ## چرا nullable و بدون پیش‌فرض
 *
 * چون `description` در مانیفست **اختیاری** است. افزونه‌ای که آن را ندارد باید
 * `null` بگیرد، نه رشتهٔ خالی: رشتهٔ خالی در UI شبیه «توضیح هست ولی خالی است»
 * دیده می‌شود، ولی `null` یعنی «نویسنده چیزی ننوشته» — و این دو برای کاربر
 * یکی نیستند. هر دو باید در رابط کاربری متفاوت رندر شوند.
 *
 * ## چرا بک‌فیل از مانیفست، نه از نام
 *
 * مانیفست همان جایی است که `description` زندگی می‌کند. ساختن متن از `slug`
 * («blog» ⇒ «افزونهٔ blog») متن ساختگی است که به کاربر دروغ می‌گوید. پس فقط
 * جایی پر می‌شود که مانیفست واقعاً آن را دارد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plugins')) {
            return;
        }

        if (! Schema::hasColumn('plugins', 'description')) {
            Schema::table('plugins', function ($table): void {
                $table->string('description', 400)->nullable()->after('name');
            });
        }

        // بک‌فیل: فقط از مانیفست، و فقط رشتهٔ غیرخالی.
        DB::table('plugins')->whereNotNull('manifest')->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                $manifest = json_decode((string) $row->manifest, true);

                if (! is_array($manifest)) {
                    continue;
                }

                $description = $manifest['description'] ?? null;

                if (! is_string($description) || trim($description) === '') {
                    continue;
                }

                DB::table('plugins')
                    ->where('id', $row->id)
                    ->where(function ($q): void {
                        $q->whereNull('description')->orWhere('description', '');
                    })
                    ->update(['description' => mb_substr(trim($description), 0, 400)]);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('plugins') && Schema::hasColumn('plugins', 'description')) {
            Schema::table('plugins', function ($table): void {
                $table->dropColumn('description');
            });
        }
    }
};
