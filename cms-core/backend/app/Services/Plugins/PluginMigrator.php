<?php

namespace App\Services\Plugins;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * K5.6 گام ۳ — اجرای migration پلاگین با prefix اجباری.
 *
 * تفکیک مسئولیت: `PluginMigrationRunner` **تحلیل و بازنویسی** می‌کند (خالص و
 * قابل تست بدون دیتابیس)، و این کلاس **اجرا** می‌کند.
 *
 * ترتیب کار عمداً برعکس چیزی است که به نظر می‌رسد:
 *
 * ۱. هر فایل migration **قبل از اجرا** در پوشهٔ موقت بازنویسی می‌شود
 * ۲. فقط بعد از آن `require` می‌شود
 * ۳. اگر حتی یکی رد شود، **هیچ** فایلی اجرا نشده
 *
 * دلیل: `verify()` باید روی **همان** متنی اجرا شود که بعداً اجرا می‌شود. اول اجرا
 * کنیم و بعد چک کنیم، یک `Schema::dropIfExists('users')` تا آن لحظه رفته و برگشتی
 * نیست.
 *
 * ## چرا تراکنش هم می‌گذاریم اگر کافی نیست
 *
 * در PostgreSQL تراکنش DDL را پوشش می‌دهد، ولی این کد نباید به رفتار یک درایور
 * خاص تکیه کند. تراکنش لایهٔ دفاعی است نه تضمین — به همین دلیل راستی‌آزمایی پیش از
 * اجرا انجام می‌شود، نه به امید برگشتِ تراکنش.
 */
final class PluginMigrator
{
    /**
     * جدول سابقهٔ مرکزی.
     *
     * ثابت است چون نام جدول در سه جا لازم است و رشتهٔ آزاد یعنی یکی از آن‌ها
     * دیرتر عوض شود — و خطایش آن‌وقت «جدول وجود ندارد» است، نه اینکه کدام جدول
     * منظور بود. همین ناهماهنگی یک‌بار واقعاً پیش آمد.
     */
    private const HISTORY_TABLE = 'pishdad_plugin_migrations';

    public function __construct(
        private PluginMigrationRunner $runner,
        private PluginDdlConnection $ddl,
        private PluginDdlProvisioner $provisioner,
    ) {}

    /**
     * @param  array<int, string>  $declaredTables  نام‌های خام از `db.tables`
     * @return array{ok: bool, code?: string, message: string, applied: array<int, string>, skipped: array<int, string>}
     */
    public function run(PluginReleaseManager $releases, string $slug, array $declaredTables): array
    {
        $dir = $releases->currentDir($slug);

        if ($dir === null) {
            // نبودِ نسخهٔ روی دیسک یعنی افزونه کدی روی دیسک ندارد. اگر جدولی هم
            // اعلام نکرده، کاری برای انجام دادن نیست و خطا دادن بی‌مورد است — و
            // این همان حالتی است که fixtureهای تست (رکورد DB بدون نصب واقعی)
            // در آن هستند.
            //
            // اگر جدول اعلام شده باشد، خطاست: نمی‌شود افزونه‌ای را فعال کرد که
            // جدول‌هایش ساخته نشده. آن موقع اولین درخواستش می‌میرد.
            if ($declaredTables === []) {
                return [
                    'ok' => true,
                    'message' => 'نسخه‌ای روی دیسک نیست و جدولی هم اعلام نشده؛ چیزی برای ساختن نبود.',
                    'applied' => [],
                    'skipped' => [],
                ];
            }

            return self::fail(
                'migration.no_release',
                "«{$slug}» جدول اعلام کرده ولی نسخه‌ای روی دیسک نیست؛ فعال‌سازی انجام نشد."
            );
        }

        $files = $this->runner->discover($dir);

        if ($files === []) {
            // بسته migration ندارد. اگر جدولی هم اعلام نکرده، چیزی برای ساختن
            // نبوده و اشکالی ندارد. ولی اگر جدول اعلام کرده، نبودِ migration یعنی
            // آن جدول هرگز ساخته نمی‌شود — مگر از فعال‌سازی قبلی باقی مانده باشد،
            // که همان چیزی است که `missingTables()` می‌سنجد.
            if ($missing = $this->missingTables($slug, $declaredTables)) {
                return self::fail(
                    'migration.tables_missing',
                    'این جدول‌های اعلام‌شده وجود ندارند و بسته هم migrationی برای ساختنشان ندارد: '.implode('، ', $missing)
                        .'. یا migration اضافه کن، یا اعلامشان را از `db.tables` بردار.'
                );
            }

            return ['ok' => true, 'message' => 'این افزونه migration ندارد.', 'applied' => [], 'skipped' => []];
        }

        // ── ۱) بازنویسی هر فایل، پیش از هر اجرا ─────────────────────────
        $stage = $this->stage($slug, $dir, $files, $declaredTables);

        if (isset($stage['code'])) {
            return self::fail($stage['code'], $stage['message'], skipped: $files);
        }

        // ── ۱٫۵) رجیستری و نقش، **پیش** از اجرا ─────────────────────────
        //
        // ترتیب عمدی است. رجیستری باید قبل از migration پر باشد، چون نگهبان در
        // لحظهٔ `CREATE TABLE` نام را می‌پرسد و رجیستریِ خالی یعنی «هیچ چیز
        // مجاز نیست» — یعنی شکست.
        //
        // و اگر نقش در دسترس نباشد، **قبل** از هر DDL رد می‌کنیم. اگر صبر می‌کردیم
        // تا migration شکست بخورد، خطای نگهبان می‌آمد که نویسندهٔ افزونه می‌دید
        // و چیزی از وضعیت نصب نمی‌فهمید.
        if (! $this->ddl->isAvailable()) {
            $this->forget($stage['root']);

            return self::fail(
                'migration.ddl_unavailable',
                'DDL افزونه اجرا نشد چون نقش «'.PluginDdlConnection::ROLE.'» در دسترس نیست.'
                    .' این عمداً fail-closed است: اجرای migration روی اتصال هسته یعنی اجرای بی‌نگهبان.'
            );
        }

        $this->provisioner->registerTables($slug, $declaredTables);

        // ── ۲) اجرا ─────────────────────────────────────────────────────
        $applied = [];
        $skipped = [];
        $history = $this->history($slug);

        // ── ۱٫۶) تاریخِ کهنه: ثبت‌شده ولی جدولش نیست ───────────────
        //
        // `pishdad_plugin_migrations` می‌گوید «اجرا شد» ولی ممکن است جدول
        // نباشد. سه حالت این را می‌سازد که هر سه واقعی‌اند:
        //  · فرایند بین نوشتنِ تاریخ و `commit` مرد؛
        //  · جدول را کسی دستی حذف کرد؛
        //  · دیتابیس از پشتیبان برگشت ولی تاریخ‌ها جلوتر از جدول‌ها.
        //
        // بدون این بررسی، حالت سوم **ساکت** خراب می‌شد: migration «اجرا شده»
        // پنداشته و skip می‌شد، بعد گاردِ پایین می‌گفت «بستهٔ شما migration ندارد»
        // — که غلط است. بسته دارد، اجرا هم شده. کاربر می‌رفت دنبالِ بسته و
        // هرگز پیدایش نمی‌کرد.
        //
        // راه درست پاک کردنِ تاریخِ ناهمخوان است، نه skip کردنش: اگر جدول نیست،
        // migration باید دوباره اجرا شود.
        $this->reconcileHistory($slug, $declaredTables, $history);

        try {
            // ── تراکنش روی **هر دو** اتصال ─────────────────────────────────
            //
            // ⚠️ این مهم‌ترین نکتهٔ این بازنویسی است و با آزمایش کشف شد، نه با
            // بازبینی کد.
            //
            // نسخهٔ اول فقط روی اتصالِ هسته تراکنش باز می‌کرد و DDL را روی اتصالِ
            // افزونه اجرا می‌کرد. نتیجه: `rollBack` هیچ چیزی را برنمی‌گرداند،
            // چون DDL در تراکنشِ دیگری بوده. تستِ
            // `test_a_failure_rolls_back_the_whole_batch` این را لو داد — جدولِ
            // فایل اول **مانده بود** با وجود ادعای rollback.
            //
            // یعنی نصبِ نیمه‌کاره: جدولِ اول ساخته شده، دومی شکست خورده، و کاربر
            // وضعیتی می‌بیند که نه فعال است نه پاک است. این همان چیزی بود که
            // تراکنش برای جلوگیری از آن گذاشته شده.
            //
            // ترتیبِ باز کردن: اول هسته، بعد افزونه. اگر دومی شکست بخورد،
            // اولی باید بسته شود وگرنه تراکنشِ هسته معلق می‌ماند.
            $core = DB::connection($this->coreConnection());
            $plugin = DB::connection(PluginDdlConnection::CONNECTION);

            $this->ddl->run(function () use ($core, $plugin, $stage, &$applied, &$skipped, &$history): void {
                $core->beginTransaction();
                $plugin->beginTransaction();

                try {
                    foreach ($stage['staged'] as $original => $path) {
                        if (in_array($original, $history, true)) {
                            $skipped[] = $original;

                            continue;
                        }

                        // تاریخ **پیش** از اجرا نوشته می‌شود، و روی اتصال **هسته**:
                        // نقش افزونه حق نوشتن روی `pishdad_plugin_migrations` را ندارد
                        // (و نباید داشته باشد).
                        //
                        // ترتیب «اول تاریخ، بعد اجرا» محافظه‌کارانه است: اگر فرایند
                        // بین این دو بمیرد، تاریخ «اجرا شده» ولی جدول نیست — و آن
                        // را گاردِ `missingTables` پایین می‌گیرد و پیام دقیق می‌دهد.
                        // ترتیب برعکس، جدولِ بی‌تاریخ می‌ساخت که نصب دوباره را
                        // می‌شکست.
                        $core->table(self::HISTORY_TABLE)->insert([
                            'slug' => $stage['slug'],
                            'migration' => $original,
                            'applied_at' => now(),
                        ]);

                        $this->requireMigration($path, $original)->up();

                        $history[] = $original;
                        $applied[] = $original;
                    }

                    // ⚠️ **اول** اتصالِ افزونه: `commit` آن جدول‌ها را نهایی
                    // می‌کند. اگر هسته اول commit شود و افزونه شکست بخورد، تاریخ
                    // «اجرا شده» می‌ماند ولی جدول‌ها نه.
                    $plugin->commit();
                    $core->commit();
                } catch (Throwable $e) {
                    // ترتیب برعکسِ باز کردن: اول هر که دیرتر باز شده بسته می‌شود.
                    $plugin->rollBack();
                    $core->rollBack();

                    throw $e;
                }
            });
        } catch (Throwable $e) {
            $this->forget($stage['root']);
            $this->forgetHistory($slug, $applied);

            return self::fail('migration.failed', $e->getMessage(), skipped: $files);
        }

        $this->forget($stage['root']);

        // ── ۳) تضمین بعد از اجرا: هر جدول اعلام‌شده باید واقعاً وجود داشته باشد ──
        //
        // این گارد نبودِ یک شکاف واقعی بود، نه تکرار گاردِ بالا. گاردِ بالا فقط
        // وقتی اجرا می‌شود که اصلاً نسخه‌ای روی دیسک نباشد. ولی اگر نسخه هست و
        // بسته **migration همراه ندارد**، `$files` خالی می‌شود و شاخهٔ
        // «این افزونه migration ندارد» بدون هیچ بررسی‌ای `ok` برمی‌گرداند.
        //
        // نتیجه: افزونه‌ای که `db.tables` اعلام کرده ولی جدولش را نمی‌سازد، فعال
        // می‌شد و در اولین درخواست می‌مرد — دقیقاً همان چیزی که تصمیم K5.6
        // «جدول اعلام‌شده ولی ساخته‌نشده ⇒ فعال‌سازی رد شود» می‌خواست جلویش را
        // بگیرد. اعتبارسنجیِ migration جلوی دست‌کاریِ جدولِ *اعلام‌نشده* را
        // می‌گیرد، ولی جلوی *ساخته‌نشدنِ* جدولِ اعلام‌شده را نمی‌گیرد.
        if ($missing = $this->missingTables($slug, $declaredTables)) {
            return self::fail(
                'migration.tables_missing',
                'این جدول‌های اعلام‌شده بعد از اجرای migration وجود ندارند: '.implode('، ', $missing)
                    .'. یا migration ساختشان کن، یا اعلامشان را از `db.tables` بردار — جدول اعلام‌نشده ولی ساخته‌شده هم به همان اندازه غلط است.'
            );
        }

        return [
            'ok' => true,
            'message' => count($applied).' migration اجرا شد، '.count($skipped).' قبلاً اجرا شده بود.',
            'applied' => $applied,
            'skipped' => $skipped,
        ];
    }

    /**
     * جدول‌های اعلام‌شده‌ای که بعد از اجرای migration روی دیسکِ پایگاه‌داده نیستند.
     *
     * نام نهایی همان چیزی است که `rewrite()` می‌سازد: `{slug}_{table}` — چون مالکیت
     * جدول با prefix اجباری است و اسلاگِ دارای خط تیره اصلاً اجازهٔ اعلام ندارد
     * (قاعدهٔ `db.tables`)، پس نام معتبر است و نیازی به escape ندارد.
     *
     * @param  array<int, string>  $declaredTables
     * @return list<string>
     */
    private function missingTables(string $slug, array $declaredTables): array
    {
        if ($declaredTables === []) {
            return [];
        }

        $missing = [];

        foreach ($declaredTables as $table) {
            $qualified = $slug.'_'.$table;

            if (! Schema::hasTable($qualified)) {
                $missing[] = $qualified;
            }
        }

        return $missing;
    }

    /**
     * نام‌های خام جدول از `db.tables` مانیفست.
     *
     * @param  array<int, mixed>  $manifest
     * @return array<int, string>
     */
    public function declaredTables(array $manifest): array
    {
        $db = $manifest['db'] ?? null;

        if (! is_array($db) || ! is_array($db['tables'] ?? null)) {
            return [];
        }

        $names = [];

        foreach ($db['tables'] as $entry) {
            if (is_array($entry) && is_string($entry['name'] ?? null)) {
                $names[] = $entry['name'];
            }
        }

        return $names;
    }

    /**
     * @param  array<int, string>  $files
     * @param  array<int, string>  $declared
     * @return array{root?: string, staged?: array<string, string>, slug?: string, code?: string, message?: string}
     */
    private function stage(string $slug, string $releaseDir, array $files, array $declared): array
    {
        try {
            $root = $this->tempDir($slug);
        } catch (RuntimeException $e) {
            return self::rejection('migration.no_temp', $e->getMessage());
        }

        $source = '';

        foreach ($files as $file) {
            $source = (string) @file_get_contents($this->migrationsDir($releaseDir).'/'.$file);

            if ($source === '') {
                $this->forget($root);

                return self::rejection('migration.unreadable', 'فایل migration «'.$file.'» خوانده نشد.');
            }

            $errors = $this->runner->verify($source, $declared);

            if ($errors !== []) {
                $this->forget($root);

                return self::rejection(
                    'migration.rejected',
                    'migration «'.$file.'» رد شد: '.implode('، ', $errors)
                );
            }

            // `LOCK_EX`: دو فعال‌سازی هم‌زمان نباید فایل نیمه‌نوشته را require کنند.
            if (@file_put_contents($root.'/'.$file, $this->runner->rewrite($source, $slug), LOCK_EX) === false) {
                $this->forget($root);

                return self::rejection('migration.unwritable', 'بازنویسی نشد: «'.$file.'».');
            }
        }

        $staged = [];

        foreach ($files as $file) {
            $staged[$file] = $root.'/'.$file;
        }

        return ['root' => $root, 'staged' => $staged, 'slug' => $slug];
    }

    /**
     * @return array<int, string>
     */
    private function history(string $slug): array
    {
        try {
            return array_map('strval', DB::table(self::HISTORY_TABLE)->where('slug', $slug)->pluck('migration')->all());
        } catch (Throwable) {
            // جدول هنوز ساخته نشده ⇒ هیچ‌چیز اجرا نشده.
            return [];
        }
    }

    /**
     * فایل migration را `require` می‌کند و شیء `Migration` را برمی‌گرداند.
     *
     * روش درست، گرفتن **مقدار بازگشتی** `require` است — نه پیدا کردن نام کلاس.
     * نسخهٔ اول این متد `get_declared_classes()` را می‌کاوید، ولی سبک امروزی
     * لاراول `return new class extends Migration` است و **کلاس بی‌نام** در
     * `get_declared_classes()` ظاهر نمی‌شود. یعنی هر migration واقعی رد می‌شد
     * با پیامی که هیچ سرنخی به فایل نمی‌داد.
     */
    private function requireMigration(string $path, string $original): Migration
    {
        // `require` نه `require_once`: هر فایل دقیقاً یک‌بار در این اجرا
        // require می‌شود و `require_once` مقدار را بار دوم `true` برمی‌گرداند.
        $migration = require $path;

        if (! $migration instanceof Migration) {
            throw new RuntimeException(
                'migration «'.$original.'» شیء Migration برنگرداند — فایل باید '
                .'`return new class extends Migration` داشته باشد.'
            );
        }

        return $migration;
    }

    private function migrationsDir(string $releaseDir): string
    {
        return rtrim($releaseDir, '/').'/'.PluginPackageContract::BACKEND_ROOT.'/database/migrations';
    }

    private function tempDir(string $slug): string
    {
        $safe = preg_replace('/[^a-z0-9-]/i', '', $slug) ?: 'x';
        $root = rtrim(sys_get_temp_dir(), '/').'/pishdad-mig-'.$safe;

        if (! is_dir($root) && ! @mkdir($root, 0o700, true) && ! is_dir($root)) {
            throw new RuntimeException('پوشهٔ موقت migration ساخته نشد.');
        }

        return $root;
    }

    private function forget(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }

        foreach (glob($root.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($root);
    }

    /**
     * حذفِ تاریخِ migrationهایی که جدولشان روی دیسک نیست.
     *
     * اگر همهٔ جدول‌های اعلام‌شده وجود داشته باشند، هیچ کاری نمی‌شود — تاریخ
     * معتبر است.
     *
     * اگر **بعضی** وجود داشته باشند و بعضی نه، هیچ تاریخی پاک نمی‌شود. دلیلش این
     * است که در آن حالت نمی‌دانیم کدام migration ساخته نشده — حدس زدن یعنی
     * اجرای دوبارهٔ یک migration که قبلاً اجرا شده، و `Schema::create` روی
     * جدولِ موجود خطا می‌دهد. در آن حالت `missingTables` پایین پیام دقیق می‌دهد.
     *
     * @param  array<int, string>  $declaredTables
     * @param  array<int, string>  $history  به‌مرجع
     */
    private function reconcileHistory(string $slug, array $declaredTables, array &$history): void
    {
        if ($history === []) {
            return;
        }

        $dangling = $this->provisioner->danglingTables($slug);

        // همهٔ نام‌های ثبت‌شده روی دیسک هستند ⇒ تاریخ معتبر است.
        if ($dangling === []) {
            return;
        }

        // **بعضی** جدول‌ها هستند و بعضی نیستند ⇒ وضعیت نامشخص. دست نمی‌زنیم.
        $registered = DB::table('plugin_table_prefixes')->where('slug', $slug)->pluck('table_name')->all();
        $partial = count($registered) > count($dangling);

        if ($partial || $declaredTables === []) {
            return;
        }

        Log::warning('plugin.migration_history_stale', [
            'slug' => $slug,
            'missing' => $dangling,
        ]);

        DB::table(self::HISTORY_TABLE)->where('slug', $slug)->delete();

        $history = [];
    }

    /**
     * پاک کردن تاریخِ migrationهایی که در این اجرا **ثبت شدند ولی اجرا نشدند**.
     *
     * لازم است چون تاریخ روی اتصال هسته نوشته می‌شود و تراکنشِ DDL روی اتصال
     * افزونه است — این دو تراکنشِ جدا هستند و rollback یکی دیگری را برنمی‌گرداند.
     * بدون این پاک‌سازی، یک شکست در migration سوم باعث می‌شد دو تای قبلی «اجرا
     * شده» بمانند در حالی که جدول‌هاشان هم برگشته — و نصب دوباره آن‌ها را
     * می‌پرید.
     *
     * @param  array<int, string>  $names
     */
    private function forgetHistory(string $slug, array $names): void
    {
        if ($names === []) {
            return;
        }

        try {
            DB::connection($this->coreConnection())
                ->table(self::HISTORY_TABLE)
                ->where('slug', $slug)
                ->whereIn('migration', $names)
                ->delete();
        } catch (Throwable) {
            // اگر این هم شکست بخورد، وضعیت نیمه‌کاره می‌ماند و گاردِ
            // `missingTables` پایین آن را می‌بیند و پیام دقیق می‌دهد. پس این
            // نباید استثنای اصلی را بپوشاند.
        }
    }

    /** اتصال هسته، حتی وقتی پیش‌فرض روی افزونه است. */
    private function coreConnection(): string
    {
        return $this->ddl->coreConnection();
    }

    /** @return array{ok: false, code: string, message: string, applied: array<int, string>, skipped: array<int, string>} */
    private static function fail(string $code, string $message, array $skipped = []): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'applied' => [], 'skipped' => $skipped];
    }

    /** @return array{code: string, message: string} */
    private static function rejection(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}
