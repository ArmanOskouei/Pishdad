<?php

namespace App\Services\Plugins;

/**
 * K5.6 — راستی‌آزمایی و بازنویسی migration پلاگین.
 *
 * `PluginDbContract` می‌گوید پلاگین **ادعا می‌کند** کدام جدول‌ها را می‌خواهد. این
 * کلاس بررسی می‌کند که آیا migration واقعاً فقط به همان‌ها دست می‌زند یا نه، و اگر
 * بخواند، نام‌ها را با prefix می‌نویسد.
 *
 * ## چرا fail-closed و نه «تا جایی که توانستیم»
 *
 * بازنویسی متن، ذاتاً ناتمام است: هر فرمی که این کلاس نشناسد یعنی یک جدولِ
 * بدون prefix که بی‌صدا به هسته می‌نویسد. غلطِ کم‌هزینه در اینجا وجود ندارد —
 * غلط گران است. پس قاعده این است: **هر چیزی که نتوانیم با اطمینان حساب کنیم،
 * کل نصب را رد می‌کند.**
 *
 * این همان دلیلی است که K5.5 هوک را حذف کرد نه ساخت: پیشنهادی که نیمه‌کاره
 * اجرا شود از نبودش بدتر است، چون «کار می‌کند» به نظر می‌رسد.
 *
 * ## آنچه این کلاس **نمی‌تواند** بکند
 *
 * جداسازی واقعی نیست — کد پلاگین در همان process اجرا می‌شود و می‌تواند هر
 * کاری بکند. این فقط سطح حمله را کم می‌کند. تنها راه اجرای امن کد ناشناس،
 * process جداست و در فاز ۱ انجام نمی‌شود (سند معماری، مقدمه).
 */
final class PluginMigrationRunner
{
    /**
     * جایگاه‌هایی که آرگومانشان **نام جدول** است.
     *
     * عمداً فهرست بسته است: هر الگویی که اضافه می‌شود یک تعهد تازه است که باید
     * تست شود. نبودِ یک الگو یعنی نصب رد می‌شود، که قابل قبول است؛ غلط گرفتن یعنی
     * بازنویسی اشتباه، که نیست.
     */
    private const TABLE_CALLS = [
        // Schema::create('x', …) / ::table / ::drop / ::dropIfExists / ::hasTable / ::hasColumn
        '/\bSchema::(?:create|table|drop|dropIfExists|hasTable|hasColumn|hasColumns)\(\s*([\'"])([A-Za-z0-9_]+)\1/',
        // DB::table('x')
        '/\bDB::table\(\s*([\'"])([A-Za-z0-9_]+)\1/',
        // ->constrained('users')  و  ->index(...)->on('x')
        '/->(?:constrained|on)\(\s*([\'"])([A-Za-z0-9_]+)\1/',
    ];

    /**
     * فرم‌هایی که به هیچوجه قابل بازنویسی نیستند.
     *
     * هر کدام یعنی «نمی‌دانیم این migration چه می‌کند» ⇒ رد. این فهرست عمداً از
     * الگوهای بازنویسی **طولانی‌تر** است، چون در این محصول بازی «نمی‌دانم» به
     * نفع امنیت است.
     */
    private const UNSAFE = [
        // SQL خام — هیچ راهی برای prefix کردن نیست.
        //
        // دقت: `table` اینجا **نیست** چون در `TABLE_CALLS` هست. اگر هر دو جا بیاید،
        // هر `DB::table(...)` هم‌زمان «شناخته‌شده» و «ناامن» می‌شود و تناقض یعنی
        // همیشه رد. نام پویا را گاردِ منفیِ پایین می‌گیرد.
        '/\bDB::(?:raw|statement|unprepared|select|insert|update|delete|affectingStatement|cursor)\s*\(/',
        // نام جدول پویا: Schema::create($table) یا DB::table($prefix.'x')
        '/\bSchema::(?:create|table|drop|dropIfExists|hasTable|hasColumn|hasColumns)\(\s*(?![\'"])/',
        '/\bDB::table\(\s*(?![\'"])/',
        '/->(?:constrained|on)\(\s*(?![\'"])/',
        // اتصال دیگر، یا schema صریح که از prefix ما رد می‌شود
        '/\bDB::connection\(/',
        '/->(?:setSchema|schema)\s*\(/',
    ];

    /**
     * مسیر migrationهای یک نسخهٔ نصب‌شده، به ترتیب اجرا.
     *
     * مسیر درون بسته `Laravel/database/migrations` است (ثابت `BACKEND_ROOT` در
     * قرارداد) — **نه** `database/migrations` خود اپ که مهاجرت‌های هسته در آن است.
     * اگر این دو جابه‌جا شوند، مهاجرت پلاگین وارد مسیر هسته می‌شود.
     *
     * @return array<int, string> نام فایل‌ها
     */
    public function discover(string $releaseDir): array
    {
        $dir = rtrim($releaseDir, '/').'/'.PluginPackageContract::BACKEND_ROOT.'/database/migrations';

        if (! is_dir($dir)) {
            return [];
        }

        $files = glob($dir.'/*.php') ?: [];

        // شمارهٔ پیشوند نام، ترتیب زمانی را تعیین می‌کند؛ پس ترتیب باید بین اجراها
        // ثابت بماند.
        sort($files, SORT_STRING);

        return array_map('basename', $files);
    }

    /**
     * جدول‌هایی که migration به آن‌ها اشاره می‌کند.
     *
     * @return array<int, string>
     */
    public function extractTables(string $source): array
    {
        $found = [];

        foreach (self::TABLE_CALLS as $pattern) {
            if (preg_match_all($pattern, $source, $m, PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($m as $set) {
                $found[$set[2]] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * آیا این migration چیزی دارد که نتوانیم با اطمینان حساب کنیم؟
     *
     * علاوه بر الگوهای ممنوع، خودِ `Schema::create(` و `DB::table(` شمرده می‌شوند و
     * با تعداد ارجاع‌های شناخته‌شده مقایسه می‌گردند. این گارد لازم است: اگر الگویی
     * در آینده از `TABLE_CALLS` جا بماند، اینجا لو می‌رود نه اینکه بی‌صدا رد شود.
     *
     * @return array<int, string> دلیل‌های ناامنی
     */
    public function unsafeReasons(string $source): array
    {
        $reasons = [];

        foreach (self::UNSAFE as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                $reasons[] = $pattern;
            }
        }

        $calls = $this->countCallOpeners($source);
        $matched = count($this->extractTables($source));

        if ($calls > 0 && $matched === 0) {
            $reasons[] = 'no_recognised_table_reference_despite_'.$calls.'_call_sites';
        }

        return $reasons;
    }

    /**
     * راستی‌آزمایی: آیا این migration فقط به جدول‌های اعلام‌شده دست می‌زند؟
     *
     * @param  array<int, string>  $declared  نام‌های خام از `db.tables`
     * @return array<int, string> خطاها؛ خالی یعنی قابل قبول
     */
    public function verify(string $source, array $declared): array
    {
        $errors = [];

        foreach ($this->unsafeReasons($source) as $reason) {
            $errors[] = 'migration.unsafe:'.$reason;
        }

        $declaredSet = array_flip($declared);

        foreach ($this->extractTables($source) as $table) {
            if (! isset($declaredSet[$table])) {
                $errors[] = 'migration.undeclared_table:'.$table;
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * بازنویسی نام جدول‌ها با prefix افزونه.
     *
     * فقط **بعد از** `verify()` صدا زده می‌شود. اگر migration چیزی ناشناخته داشته
     * باشد، بازنویسی نباید انجام شود — پس این متد عمداً خودش چیزی دربارهٔ ایمنی
     * تصمیم نمی‌گیرد و فقط کاری را می‌کند که بلدم.
     */
    public function rewrite(string $source, string $slug): string
    {
        $prefix = $slug.'_';

        // فقط **نام** را جایگزین می‌کنیم، نه کل تطبیق — وگرنه کوتیشن باز جا می‌ماند
        // و خروجی چیزی مثل `Schema::create('demo_posts` (بدون کوتیشن بسته) می‌شود
        // که اصلاً PHP نیست. پس سر و دم تطبیق هر دو حفظ می‌شوند.
        $replace = static function (array $m) use ($prefix): string {
            $name = $m[2];
            $at = strrpos($m[0], $name);

            return substr($m[0], 0, $at).$prefix.$name.substr($m[0], $at + strlen($name));
        };

        foreach (self::TABLE_CALLS as $pattern) {
            $out = preg_replace_callback($pattern, $replace, $source);

            if ($out !== null) {
                $source = $out;
            }
        }

        return $source;
    }

    /**
     * شمارش محل‌های فراخوانیِ جدول، مستقل از اینکه آرگومانشان شناخته شده یا نه.
     */
    private function countCallOpeners(string $source): int
    {
        $count = 0;

        $count += preg_match_all('/\bSchema::(?:create|table|drop|dropIfExists|hasTable|hasColumn|hasColumns)\s*\(/', $source);
        $count += preg_match_all('/\bDB::table\s*\(/', $source);
        $count += preg_match_all('/->(?:constrained|on)\s*\(/', $source);

        return (int) $count;
    }
}
