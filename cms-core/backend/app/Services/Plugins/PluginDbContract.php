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
 *
 * ## ⚠️ این تنها لایهٔ دفاع است — و این باید آگاهانه پذیرفته شود
 *
 * spikeِ K5.7 (۲۰۲۶-۰۹-۲۸، `docs/spike-K5.7-POSTGRES-ROLES.md`) دو چیز را ثابت
 * کرد:
 *
 *  ۱. اتصال اپ با نقشی است که **superuser** دارد (`Bypass RLS` هم دارد)، پس در
 *     سطح دیتابیس **هیچ** محدودیتی وجود ندارد. prefix نه انتخاب است و نه
 *     محدودیت — اصلاً مرزی در آن سطح نیست.
 *  ۲. `GRANT` با الگو کار نمی‌کند (چون روی شیء است نه الگو، و جدول‌های
 *     `{slug}_*` هنگام نقش‌سازی وجود ندارند). قاعده با `EVENT TRIGGER` قابل
 *     enforce است و در عمل آزمایش شد، ولی هنوز پیاده نشده و سه شکاف باز دارد
 *     (`RENAME TO`، `DROP`، و توزیع policy که با `SET` قابل دور زدن است).
 *
 * یعنی **تا آن روز، همین کلاس و `PluginMigrationRunner` تنها چیزی‌اند که مانع
 * نوشتن روی جدول هسته می‌شوند.** اگر روزی این دو دور زده شوند، هیچ لایهٔ دومی
 * نیست. پس تغییر دادنشان باید با همین وزن انجام شود.
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
     * جدول‌های هسته که **پیشوند ندارند**.
     *
     * این فهرست از روی اسکیمای واقعی دیتابیس استخراج شد، نه از روی حافظه. یک
     * تست (`test_the_deny_list_matches_the_real_schema`) آن را با اسکیمای واقعی
     * مقایسه می‌کند، پس جدول تازه‌ای که کسی اضافه کند و اینجا یادش نرود، تست را
     * قرمز می‌کند.
     *
     * یادآوری: چون prefix اجباری است، از این فهرست **جدول هسته‌ای قابل دسترسی
     * نمی‌شود** — نام اعلام‌شده همیشه به `slug_name` تبدیل می‌شود. این فهرست برای
     * گرفتن خطای نویسنده است، نه برای جلوگیری از برخورد در دیتابیس.
     *
     * `plugin_table_prefixes` هم از همین جنس است: رجیستریِ K5.12 که تعیین می‌کند
     * افزونه اجازهٔ ساخت کدام نام‌ها را دارد. اگر افزونه بتواند آن را اعلام کند،
     * نگهبانِ دیتابیس عملاً بی‌اثر می‌شود — پس باید مسدود باشد.
     */
    public const CORE_TABLES = [
        // F3 — لاگِ فعالیتِ هسته. پلاگین نباید بتواند ردِ ممیزیِ کاربران را
        // بخواند یا خودش رکورد جعلی در آن بنویسد.
        'activity_log',
        'cache',
        'cache_locks',
        // H5 — نگاشتِ import/export محتوا؛ کلیدِ ادامه‌دادنِ یک import است و
        // متعلق به هسته است.
        'content_import_maps',
        'failed_jobs',
        // WF-H10 — فرم‌ساز. تعریف فرم و پاسخ‌های کاربران دادهٔ شخصی است، پس
        // صریحاً در ممنوعِ افزونه‌هاست.
        'form_submissions',
        'forms',
        'job_batches',
        'jobs',
        // B5 — رویدادهای ورود؛ مبنای تشخیصِ ورود مشکوک.
        'login_events',
        // WF-L1/H11 — کتابخانهٔ رسانه. `media_media_tag` جدولِ چسبِ many-to-many
        // است و `media_tags`/`media_folders` دسته‌بندیِ مالک است؛ هر سه هسته‌اند و
        // پلاگین نباید بتواند فایل یا برچسبِ رسانه را از مسیرِ خودش بازنویسی کند.
        'media',
        'media_folders',
        'media_media_tag',
        'media_tags',
        // بازار (سشنِ قبلی — migration `2026_10_14` این‌ها را ساخت ولی فهرستِ
        // ممنوع به‌روز نشد، و نگهبانِ `test_the_deny_list_matches_the_real_schema`
        // قرمز ماند). این‌ها هم جدولِ هسته‌اند: پلاگین نباید بتواند سفارش یا
        // پرداختِ بازارِ نصب را بخواند یا بازنویسی کند.
        'market_ledger_entries',
        'market_licenses',
        'market_notices',
        'market_orders',
        'market_payouts',
        'market_reviews',
        'migrations',
        'model_has_permissions',
        'model_has_roles',
        'notifications',
        // F1.3/F4.2.B — صفِ تحویل و ترجیحاتِ اعلان. هر دو جدولِ **هسته**اند: پلاگین
        // نباید بتواند خودش را در صفِ تحویل جا بزند (اعلانِ جعلی از مسیر
        // پلاگین) یا ترجیحاتِ کاربر را بازنویسی کند. بدون این دو سطر،
        // `test_the_deny_list_matches_the_real_schema` آن‌ها را «جدولِ هستهٔ
        // ثبت‌نشده» اعلام می‌کرد — دقیقاً همان باگی که `F4.1.C` برای سه جدولِ
        // قالب داشت.
        'notification_deliveries',
        'notification_preferences',
        // WF-H10 — قفلِ ویرایش؛ مانعِ تداخلِ دو ویرایشگر روی یک صفحه.
        'page_edit_locks',
        'page_revisions',
        // H9 — لینکِ اشتراکِ امضاشده؛ دسترسیِ عمومی است و نباید دستِ افزونه باشد.
        'page_share_links',
        // WF-H12 — بازدیدِ صفحه. دادهٔ تحلیلیِ هسته است؛ افزونه نباید بتواند آن را
        // جعل کند یا از آن طلاکوب بسازد.
        'page_views',
        'page_vitals',
        'pages',
        'password_reset_tokens',
        'permissions',
        'personal_access_tokens',
        'plugin_table_prefixes',
        'plugins',
        'publisher_keys',
        // P1 — اشتراک‌های Web Push. مالِ هسته است، نه مالِ یک افزونه:
        // اشتراک به «این نصب» تعلق دارد و مقصدش مرورگرِ خودِ بازدیدکننده است.
        // اگر افزونه‌ای بتواند این نام را اعلام کند، به فهرستِ کاملِ
        // endpointهای کاربران دست پیدا می‌کند و می‌تواند از طرف سایت برای
        // همه اعلان بفرستد. پس در فهرستِ نام‌های هسته می‌ماند تا اعلامش رد شود.
        'push_subscriptions',
        // H3 — ریدایرکت؛ مسیر عمومیِ سایت است و دستِ افزونه نیست.
        'redirects',
        'revalidate_logs',
        'role_has_permissions',
        'roles',
        'sessions',
        'settings',
        // WF-M11 — تاریخچه‌ی عبارت‌های جستجو. هسته است، نه مالک: گزارشِ
        // «پرجستجوترین‌ها» از آن می‌خواند و هیچ افزونه‌ای نباید بتواند
        // آن را بخواند یا بنویسد.
        'search_queries',
        'side_presets',
        'site_theme_presets',
        'site_theme_settings',
        'site_themes',
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
     * قاعده: فهرست پیش‌فرض‌های هسته و لاراول.
     */
    public static function isCoreTable(string $name): bool
    {
        return in_array($name, self::CORE_TABLES, true);
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
