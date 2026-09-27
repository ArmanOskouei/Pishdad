<?php

namespace App\Services\Plugins;

/**
 * K5.6 — قراردادِ جدول‌های دیتابیس پلاگین.
 *
 * سند معماری (§جداول دیتابیس) می‌گوید prefix باید «در لحظهٔ اجرای migration اعمال
 * شود، نه فقط مستند». ولی اعمالِ prefix بدون یک کانالِ اعلام، یعنی حدس زدنِ
 * جدول‌ها از روی متن کد پلاگین — و حدسِ غلط بدتر از نبودِ قابلیت است، چون
 * بی‌صدا به جدول‌های هسته می‌نویسد. پس پلاگین جدول‌هایش را **اعلام** می‌کند و
 * هسته آن اعلام را در برابرmigration راستی‌آزمایی می‌کند.
 *
 * این کلاس فقط **اعلام** را اعتبارسنجی می‌کند. راستی‌آزماییِ خودِ migration و
 * بازنویسیِ آن کارِ `PluginMigrationRunner` است.
 *
 * قاعده‌ها از سند معماری خط ۱۲۵، عیناً: پیشوند `{slug}_`، حداکثر ۴۰ نویسه،
 * `^[a-z0-9_]+$`، حداکثر ۲۰ جدول و **۱۰ ایندکس در هر جدول**، و بررسی تداخل با
 * جدول‌های هسته.
 */
final class PluginDbContract
{
    /** سقف ۲۰ جدول در هر افزونه. */
    public const MAX_TABLES = 20;

    /**
     * سقف ۱۰ ایندکس **در هر جدول** — نه در کل افزونه.
     *
     * سقفِ کل، یعنی ۱۰ ایندکس برای ۲۰ جدول: افزونه‌ای با ۲۰ جدول فقط می‌توانست
     * نیمی از آن‌ها را ایندکس کند. سند معماری «در هر جدول» می‌گوید و آن هم
     * واقع‌بینانه‌تر است.
     */
    public const MAX_INDEXES_PER_TABLE = 10;

    /** سقف طول نامِ prefixed. */
    public const MAX_PREFIXED_LENGTH = 40;

    /**
     * نام خام جدول، **بدون** prefix. حروف کوچک انگلیسی و زیرخل.
     *
     * عمداً نقطه و خط تیره را رد می‌کنیم (برخلاف `slug`): این نام در نهایت
     * شناسهٔ SQL می‌شود و کوتاه‌کردنش بعداً ناسازگاری می‌سازد.
     */
    public const TABLE_PATTERN = '/^[a-z][a-z0-9_]{0,29}$/';

    public const INDEX_PATTERN = '/^[a-z][a-z0-9_]{0,29}$/';

    /**
     * پیشوند نام جدول‌های مرکزی.
     *
     * عمداً **پیشوند** است نه فهرستِ کامل: فهرست کردن `central_*` یعنی هر جدول
     * مرکزیِ تازه باید دستی به این فایل اضافه شود، و فراموشی‌اش یعنی سوراخ
     * امنیتی خاموش. ضمناً نام بردن از `central_xxx` در کد هسته، آزمون مرز
     * `CentralBoundaryTest` را می‌شکند — چون آن آزمون نمی‌تواند «نام بردن در
     * فهرست ممنوع» را از «صدا زدن جدول» تشخیص دهد. قاعدهٔ پیشوند هر دو problem
     * را حذف می‌کند.
     */
    public const CENTRAL_PREFIX = 'central_';

    /**
     * جدول‌های هسته که **پیشوند ندارند**.
     *
     * جدول‌های `central_*` جداگانه و با `CENTRAL_PREFIX` رد می‌شوند. این فهرست
     * بقیهٔ جدول‌های هسته است — فهرستی که از روی اسکیمای واقعی دیتابیس استخراج
     * شد، نه از روی حافظه. یک تست (`test_the_deny_list_matches_the_real_schema`)
     * آن را با اسکیمای واقعی مقایسه می‌کند، پس جدول تازه‌ای که کسی اضافه کند و
     * اینجا یادش نرود، تست را قرمز می‌کند.
     *
     * یادآوری: چون prefix اجباری است، از این فهرست **جدول هسته‌ای قابل دسترسی
     * نمی‌شود** — نام اعلام‌شده همیشه به `slug_name` تبدیل می‌شود. این فهرست برای
     * گرفتن خطای نویسنده است، نه برای جلوگیری از برخورد در دیتابیس.
     */
    public const CORE_TABLES = [
        'cache',
        'cache_locks',
        'failed_jobs',
        'job_batches',
        'jobs',
        'media',
        'migrations',
        'model_has_permissions',
        'model_has_roles',
        'notifications',
        'page_revisions',
        'pages',
        'password_reset_tokens',
        'permissions',
        'personal_access_tokens',
        'plugins',
        'publisher_keys',
        'revalidate_logs',
        'role_has_permissions',
        'roles',
        'sessions',
        'settings',
        'side_presets',
        'system_plugins',
        'themes',
        'ticket_messages',
        'tickets',
        'users',
    ];

    /**
     * بررسی بخش `db` مانیفست. خروجی: فهرست issueها به همان شکلی که validator
     * بقیهٔ خطاها را تولید می‌کند.
     *
     * `db` اختیاری است — افزونه‌ای که جدول ندارد اصلاً نباید این بخش را بیاورد.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function check(mixed $db, string $slug): array
    {
        if ($db === null) {
            return [];
        }

        if (! is_array($db) || array_is_list($db)) {
            return [self::issue(
                'db.not_object',
                'بخش «db» باید یک آبجکت باشد، مثلاً {"tables": [{"name": "posts"}]}.'
            )];
        }

        $unknown = array_diff(array_keys($db), ['tables']);

        if ($unknown !== []) {
            return [self::issue(
                'db.unknown_key',
                'کلید ناشناخته در «db»: «'.implode('», «', $unknown).'». تنها «tables» پذیرفته می‌شود.'
            )];
        }

        $tables = $db['tables'] ?? null;

        if ($tables === null) {
            return [self::issue('db.no_tables', 'بخش «db» بدون «tables» بی‌معناست؛ اگر جدولی ندارید، «db» را کلاً حذف کنید.')];
        }

        if (! is_array($tables) || ! array_is_list($tables)) {
            return [self::issue('db.tables_not_list', '«db.tables» باید آرایه‌ای از فهرست باشد، نه آبجکت.')];
        }

        if (count($tables) > self::MAX_TABLES) {
            return [self::issue(
                'db.too_many_tables',
                'افزونه '.count($tables).' جدول اعلام کرده ولی سقف '.self::MAX_TABLES.' است.'
            )];
        }

        // پیشوند باید روی نامِ prefixed بنشیند و در `^[a-z0-9_]+$` جا شود.
        // `slug` اجازهٔ نقطه و خط تیره می‌دهد ولی شناسهٔ SQL اجازه ندارد، پس
        // افزونه‌ای با چنین اسلاگی نمی‌تواند جدول داشته باشد.
        if (preg_match('/^[a-z0-9_]+$/', $slug) !== 1) {
            return [self::issue(
                'db.slug_not_table_safe',
                'اسلاگ «'.$slug.'» شامل نویسه‌ای است که در نام جدول معتبر نیست (فقط a-z، 0-9 و زیرخل). یا اسلاگ را اصلاح کنید یا افزونه جدول نداشته باشد.'
            )];
        }

        $issues = [];
        $seen = [];

        foreach ($tables as $index => $entry) {
            foreach (self::checkTable($entry, $index, $slug, $seen) as $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, true>  $seen  نام‌های raw که قبلاً دیده شده‌اند
     * @return array<int, array<string, mixed>>
     */
    private static function checkTable(mixed $entry, int $index, string $slug, array &$seen): array
    {
        $where = 'db.tables['.$index.']';

        if (! is_array($entry) || array_is_list($entry)) {
            return [self::issue('db.table_not_object', $where.' باید آبجکت باشد، مثلاً {"name": "posts"}.')];
        }

        $unknown = array_diff(array_keys($entry), ['name', 'indexes']);

        if ($unknown !== []) {
            return [self::issue('db.table_unknown_key', $where.' کلید ناشناخته دارد: «'.implode('», «', $unknown).'». تنها «name» و «indexes» پذیرفته می‌شود.')];
        }

        $name = $entry['name'] ?? null;

        if (! is_string($name) || $name === '') {
            return [self::issue('db.table_no_name', $where.' فیلد «name» الزامی است.')];
        }

        if (preg_match(self::TABLE_PATTERN, $name) !== 1) {
            return [self::issue(
                'db.table_bad_name',
                'نام جدول «'.$name.'» نامعتبر است. نام **خام** را بنویسید، بدون prefix افزونه: با حرف کوچک انگلیسی شروع شود و بعد فقط a-z، 0-9 و زیرخل بیاید (۱ تا ۳۰ نویسه).'
            )];
        }

        if (str_starts_with($name, $slug.'_')) {
            return [self::issue(
                'db.table_already_prefixed',
                'نام «'.$name.'» از قبل با prefix افزونه شروع می‌شود. نام خام را اعلام کنید — هسته خودش «'.$slug.'_» را اضافه می‌کند وگرنه جدول «'.$slug.'_'.$name.'» ساخته می‌شود.'
            )];
        }

        if (self::isCoreTable($name)) {
            return [self::issue(
                'db.table_collides_core',
                'نام «'.$name.'» نام یک جدول هسته است و افزونه حق ندارد آن را اعلام کند. نام دیگری انتخاب کنید — جدول شما با پیشوند به «'.$slug.'_'.$name.'» تبدیل می‌شود و به هسته دست نمی‌خورد.'
            )];
        }

        if (isset($seen[$name])) {
            return [self::issue('db.table_duplicate', 'جدول «'.$name.'» بیش از یک بار اعلام شده.')];
        }

        $seen[$name] = true;

        $prefixed = self::prefixed($slug, $name);

        if (strlen($prefixed) > self::MAX_PREFIXED_LENGTH) {
            return [self::issue(
                'db.table_name_too_long',
                'نام نهایی «'.$prefixed.'» از سقف '.self::MAX_PREFIXED_LENGTH.' نویسه بلندتر است. نام خام را کوتاه‌تر کنید.'
            )];
        }

        if (in_array($prefixed, self::CORE_TABLES, true)) {
            return [self::issue('db.prefixed_collides_core', 'نام نهایی «'.$prefixed.'» با یک جدول هسته تداخل دارد.')];
        }

        return self::checkIndexes($entry['indexes'] ?? null, $where.'.indexes', $name);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function checkIndexes(mixed $indexes, string $where, string $table): array
    {
        if ($indexes === null) {
            return [];
        }

        if (! is_array($indexes) || ! array_is_list($indexes)) {
            return [self::issue('db.indexes_not_list', $where.' باید فهرستی از نام ستون/ایندکس باشد.')];
        }

        if (count($indexes) > self::MAX_INDEXES_PER_TABLE) {
            return [self::issue(
                'db.too_many_indexes',
                'جدول «'.$table.'» '.count($indexes).' ایندکس اعلام کرده ولی سقف '.self::MAX_INDEXES_PER_TABLE.' ایندکس **در هر جدول** است.'
            )];
        }

        $issues = [];

        foreach ($indexes as $i => $column) {
            if (! is_string($column) || preg_match(self::INDEX_PATTERN, $column) !== 1) {
                $issues[] = self::issue('db.index_bad_name', $where.'['.$i.'] نامعتبر است: «'.(is_string($column) ? $column : gettype($column)).'». با حرف کوچک انگلیسی شروع شود و بعد فقط a-z، 0-9 و زیرخل بیاید.');
            }
        }

        return $issues;
    }

    /** نامِ نهایی جدول در دیتابیس. */
    public static function prefixed(string $slug, string $table): string
    {
        return $slug.'_'.$table;
    }

    /**
     * آیا این نام به هسته تعلق دارد؟
     *
     * دو قاعده: پیشوند `central_` (که جدول‌های مرکزی K7.5 همه با آن شروع می‌شوند)
     * و فهرست پیش‌فرض‌های لاراول.
     */
    public static function isCoreTable(string $name): bool
    {
        return str_starts_with($name, self::CENTRAL_PREFIX)
            || in_array($name, self::CORE_TABLES, true);
    }

    /** @return array<string, string> */
    private static function issue(string $code, string $message): array
    {
        return [
            'severity' => 'error',
            'code' => $code,
            'message' => $message,
            'guide' => 'plugins.db',
        ];
    }
}
