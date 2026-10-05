<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ⭐ K7.7 — جاروی **کل مخزن** برای ارجاع رشته‌ای به جدول‌های `central_*`.
 *
 * ## چرا اسمِ فایل `Central*` **نیست**
 *
 * `.gitignore` خط ۶۶ الگوی `cms-core/backend/tests/Feature/Central*Test.php` را
 * دارد: تست‌های پنلِ مرکزی **خصوصی**‌اند و نباید در مخزنِ عمومی بروند. ولی این
 * تست دقیقاً برعکس است — نگهبانِ مرزی است که باید در مخزنِ عمومی باشد وگرنه
 * کسی آن را اجرا نمی‌کند. اگر اسمش `Central…` می‌بود، بی‌سروصدا gitignore می‌شد
 * و کل K7.7 در ریپو غایب می‌ماند — یعنی «نگهبانی که فقط روی دیسکِ خودِ من بود».
 *
 * ## چرا `CentralBoundaryTest` کافی نبود
 *
 * `CentralBoundaryTest::test_no_core_file_queries_a_central_table_directly` فقط
 * پوشهٔ `app/` را می‌گرفت. یعنی یک ارجاع تازه در `routes/`، `config/`،
 * `database/migrations/` یا در **افزونهٔ دیگری** بی‌صدا از آن رد می‌شد — و دقیقاً
 * همان چیزی که K7.8 باید از آن جلوگیری می‌کرد.
 *
 * نامِ فایل‌هایی که TASKS.md نام برد (`InvoiceController:31,67`،
 * `UpdateController:55`، `ClientController:73`، `PlanController:60`) همگی امروز
 * داخل `plugins/central/` نشسته‌اند، یعنی مرز. پس «۴۰+ ارجاع» آن زمان با
 * انتقال K7.8 به آن پوشه منتقل شد و چیزی بیرون از مرز نماند — **این تست همان
 * را نگه می‌دارد**.
 *
 * ## فهرستِ جدول‌ها از خودِ دیتابیس
 *
 * نه از مدل‌ها و نه دستی: `pg_tables`. یعنی جدولِ تازهٔ مرکزی خودکار زیر نگهبان
 * می‌رود و نگهبان هرگز نمی‌تواند بی‌سروصدا کوچک شود — همان اشتباهی که نسخهٔ قبلی
 * با سه نامِ مرده و یازده جدولِ غایب کرده بود.
 */
class PluginBoundaryTableSweepTest extends TestCase
{
    use RefreshDatabase;

    /**
     * J5 — این تست به **نامِ جدول‌های مرکزی** نیاز دارد، و آن جدول‌ها دیگر در
     * core نیستند.
     *
     * پس افزونه باید واقعاً نصب شود (بستهٔ روی دیسک + `PluginMigrationRunner`)، وگرنه
     * `centralTables()` خالی می‌شود و `references()` با
     * «هیچ جدول مرکزی پیدا نشد» می‌میرد.
     *
     * ⚠️ این فایل عمداً رشتهٔ مسیرِ API مرکزی را **ندارد**، پس قلابِ خودکارِ
     * `TestCase::setUp()` (که فقط برای تست‌هایی است که آن مسیر را می‌زنند) اینجا
     * صدا نمی‌شود. فراخوانیِ صریح لازم است — و عمداً صریح، چون وابستگی‌اش را در
     * خودِ فایل نشان می‌دهد.
     *
     * (نوشتنِ همان رشته در همین توضیح، قلاب را دوباره روشن می‌کرد: قلاب متنِ
     * فایلِ تست را می‌خواند، نه این متد را. پس اینجا عمداً «پ» و «central» جدا
     * نوشته شده‌اند.)
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->installCentralPluginTables();
    }

    /**
     * پوشه‌هایی که مرز نیستند و اصلاً جارو نمی‌شوند.
     *
     * ⚠️ هر مورد باید دلیل داشته باشد. `plugins/central/` خودِ مرز است؛
     * `tests/` نامِ جدول‌ها را عمداً می‌نویسد (وگرنه نمی‌توانست داده بسازد)؛
     * بقیه هم/vendor و خروجی‌های ساخت‌اند.
     *
     * @var list<string>
     */
    private const OUT_OF_SCOPE = [
        'plugins/central/',
        'plugins/subscription/',
        'plugins/_quarantine/',
        'tests/',
        'vendor/',
        'storage/',
        'node_modules/',
        'bootstrap/cache/',
        '.git/',
    ];

    /** @var list<string> پسوندهایی که متنشان جارو می‌شود. */
    private const SCANNED = ['php', 'yaml', 'yml', 'json'];

    /**
     * ارجاع‌های **مجازِ فعلی** — هر کدام با دلیل.
     *
     * ⚠️ فهرست کوتاه نگه داشته می‌شود. اگر روزی ده مورد شد، یعنی مرز عملاً حذف
     * شده و تستِ کناری (`…_has_not_swallowed_the_boundary`) قرمز می‌شود.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        'database/migrations/2026_10_05_000001_prefix_central_tables.php' => 'J5 — مهاجرتِ **داده برای نصب‌های موجود**: هشت جدولی را که core '
            .'قبلاً بدون prefix می‌ساخت به `central_*` نام‌گذاری می‌کند (به‌همراه قیدها، ایندکس‌ها و '
            .'sequence). روی نصبِ تازه هیچ‌کدام وجود ندارند و هر `renameOne()` با `Schema::hasTable` '
            .'بی‌اثم می‌ماند؛ ساختِ جدول‌ها از J5 به بعد در بستهٔ افزونه است و این فایل هیچ '
            .'`Schema::create`ای روی `central_*` ندارد. تنها DDLِ باقی‌ماندهٔ هسته روی این نام‌هاست و '
            .'بدون آن schemaِ نصب‌های قدیمی نیمه‌کاره می‌ماند.',
    ];

    /** @return list<string> */
    private function centralTables(): array
    {
        $tables = [];
        foreach (DB::select("select tablename from pg_tables where schemaname = current_schema() and tablename like 'central\\_%'") as $row) {
            $tables[] = (string) ($row->tablename ?? '');
        }

        sort($tables);

        return array_values(array_unique($tables));
    }

    /**
     * ریشه‌هایی که جارو می‌شوند — **نه کل مخزن**.
     *
     * ⛔ `vendor/` عملاً ۱۳ هزار فایل دارد و جاروی آن ۶۰ ثانیه وقت می‌گیرد و هیچ
     * چیزی به مرز اضافه نمی‌کند (وابستگیِ شخص‌ثالث، نه کدِ ما). `node_modules/`،
     * `storage/` و `bootstrap/cache/` هم خروجیِ ساخت‌اند.
     *
     * @var list<string>
     */
    private const ROOTS = ['app', 'routes', 'config', 'database', 'bootstrap', 'plugins', 'openapi'];

    /**
     * @return list<string> مسیرِ نسبیِ فایل → فهرستِ جدول‌هایی که اسمشان داخلش آمده
     */
    private function references(): array
    {
        $tables = $this->centralTables();

        $this->assertNotSame([], $tables, 'هیچ جدول مرکزی پیدا نشد — اگر اتصال DB نیست، این تست نباید بی‌صدا سبز شود.');

        $base = base_path();
        $found = [];

        foreach ($this->sourceFiles() as $relative) {
            $source = @file_get_contents($base.'/'.$relative);
            if ($source === false) {
                $this->fail('فایلِ «'.$relative.'» در فهرست جارو هست ولی خوانده نمی‌شود — جارو ناقص است.');
            }

            foreach ($tables as $table) {
                // ارجاع **رشته‌ای** فقط — `getTable()` یا FQCN با `central_` فرق
                // دارد و `CentralBoundaryTest` آن‌ها را جدا می‌سنجد. اینجا هر چیزی
                // که *نام جدول* را داخل یک رشته بگذارد، یعنی کوئریِ خام یا
                // نامِ ساختگیِ جدول.
                if (preg_match('/[\'"`]'.preg_quote($table, '/').'[\'"`]/', $source) === 1) {
                    $found[$relative][] = $table;
                }
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * @return list<string> مسیرهای نسبیِ قابل جارو
     */
    private function sourceFiles(): array
    {
        return $this->enumerate();
    }

    /**
     * پیاده‌سازیِ دوم (مستقل) — برای نگهبانِ خودِ نگهبان.
     *
     * عمداً **همان** منطقِ فیلتر را دارد، وگرنه مقایسه بی‌معنا می‌شود: نسخهٔ اول
     * فهرستِ فیلترشدهٔ خودمان را با فهرستِ **فیلترنشده**ٔ Finder مقایسه می‌کرد و
     * همهٔ `plugins/central/` را «کم‌فایل» گزارش می‌داد، در حالی که آن‌ها عمداً بیرون
     * از دامنه‌اند. نگهبانی که به‌خاطرِ رفتارِ درست قرمز شود، نگهبانی نیست که کسی
     * به آن گوش بدهد.
     *
     * @return list<string>
     */
    private function enumerate(): array
    {
        $base = base_path();
        $out = [];

        foreach (self::ROOTS as $root) {
            $dir = $base.'/'.$root;

            if (! is_dir($dir)) {
                continue;
            }

            // ⚠️ **Symfony Finder، نه `RecursiveDirectoryIterator`.**
            //
            // نسخهٔ قبل این متد از `RecursiveIteratorIterator` استفاده می‌کرد و روی
            // این mount فقط **۱۷ از ۴۵** فایل `database/` را می‌دید — یعنی همهٔ
            // migrationهای `2026_09_*` و `2026_10_0*` غیب بودند. یعنی این تست
            // دقیقاً روی همان فایل‌هایی کار نمی‌کرد که J5 جابه‌جا کرد: چهار
            // migrationِ سازندهٔ جدولِ مرکزی هرگز جارو نمی‌شدند.
            //
            // این همان شکلِ «نگهبانی که هیچیز را تضمین نمی‌کند» است که `phpunit.xml`
            // دربارهٔ همین mount هشدار داده.
            foreach (Finder::create()->files()->in($dir) as $file) {
                if (! in_array(strtolower($file->getExtension()), self::SCANNED, true)) {
                    continue;
                }

                // ⚠️ `getRealPath()` و نه `getPathname()`: روی این mount، شمارنده
                // گاهی مسیرِ **نسبی** برمی‌گرداند. با مسیر نسبی، `substr(...,
                // strlen($base) + 1)` مسیر را خراب می‌کند، `file_get_contents` شکست
                // می‌خورد و آن فایل **بی‌سروصدا از جارو بیرون می‌ماند** — یعنی سبزیِ
                // تست دربارهٔ چیزی که اصلاً نخوانده شده. تستِ پایین همین را می‌سنجد.
                $real = $file->getRealPath();
                if ($real === false) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($real, strlen($base) + 1));

                foreach (self::OUT_OF_SCOPE as $skip) {
                    if (str_contains($relative, '/'.$skip) || str_starts_with($relative, $skip)) {
                        continue 2;
                    }
                }

                $out[] = $relative;
            }
        }

        sort($out);

        return array_values(array_unique($out));
    }

    /**
     * پیمایشِ بازگشتی با `glob()` — پیاده‌سازیِ سوم و مستقل.
     *
     * `glob()` روی این mount از iterator استفاده نمی‌کند و فهرستِ کامل می‌دهد،
     * پس برای سنجشِ کوتاه‌آمدنِ `RecursiveDirectoryIterator` همان مرجعِ درست است.
     *
     * @return list<string> مسیرهای مطلق
     */
    private function globRecursively(string $dir): array
    {
        $found = [];
        $queue = [$dir];

        while ($queue !== []) {
            $current = array_pop($queue);

            foreach (glob($current.'/*') ?: [] as $entry) {
                if (is_dir($entry)) {
                    $queue[] = $entry;

                    continue;
                }

                $real = realpath($entry);

                if ($real !== false) {
                    $found[] = $real;
                }
            }
        }

        return $found;
    }

    /**
     * ⭐⭐ نگهبانِ خودِ نگهبان: شمارندهٔ ما نباید روی این mount **کوتاه** بیاید.
     *
     * `phpunit.xml` یک رفتار قطعی را مستند کرده: روی mount‌ای که کانتینر
     * استفاده می‌کند، پیاده‌سازیِ `RecursiveDirectoryIterator` در بعضی پوشه‌های
     * پرفایل فقط یک بازهٔ الفباییِ پیوسته را می‌بیند. اگر این اتفاق بیفتد، جاروی
     * ما **کم‌فایل** می‌شود و تست سبز می‌ماند در حالی که دیگر هیچ‌چیز را نمی‌سنجد —
     * دقیقاً همان شکلی از نگهبانی که `CentralBoundaryTest` قبلاً داشت.
     *
     * پس شمارش با پیاده‌سازیِ دوم (Symfony Finder، که خودِ لاراول استفاده می‌کند)
     * مقایسه می‌شود. اختلاف یعنی این تست دیگر قابل اعتماد نیست.
     *
     * ⚠️ J5: این مقایسه قبلاً فقط `app/` را می‌پوشاند — و `app/` دقیقاً همان
     * ریشه‌ای بود که `RecursiveDirectoryIterator` در آن سالم کار می‌کرد. برای
     * `database/` (۱۷ از ۴۵ فایل) هرگز مقایسه‌ای نمی‌شد، پس کوریِ جارو هیچ‌وقت لو
     * نرفت. حالا **همهٔ ریشه‌ها** مقایسه می‌شوند.
     */
    public function test_the_enumerator_does_not_see_fewer_files_than_symfony_finder(): void
    {
        $base = base_path();
        $ours = $this->sourceFiles();

        $theirs = [];
        foreach (self::ROOTS as $root) {
            $dir = $base.'/'.$root;

            if (! is_dir($dir)) {
                continue;
            }

            foreach ($this->globRecursively($dir) as $real) {
                if (! in_array(strtolower(pathinfo($real, PATHINFO_EXTENSION)), self::SCANNED, true)) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($real, strlen($base) + 1));

                foreach (self::OUT_OF_SCOPE as $skip) {
                    if (str_contains($relative, '/'.$skip) || str_starts_with($relative, $skip)) {
                        continue 2;
                    }
                }

                $theirs[] = $relative;
            }
        }
        sort($theirs);

        $missing = array_values(array_diff($theirs, $ours));

        $this->assertSame(
            [],
            $missing,
            "شمارندهٔ این تست کم‌فایل است — یعنی جارو ناقص اجرا می‌شود و سبزیِ آن\n"
            ."بی‌معنی است:\n".implode("\n", array_slice($missing, 0, 20))
        );

        $this->assertGreaterThan(
            100,
            count($ours),
            'ریشه‌های جارو فقط '.count($ours).' فایل دادند — الگوی مسیرها خراب شده است.'
        );
    }

    /**
     * ⭐ شرطِ این تست: ارجاعِ تازه‌ای بیرون از مرز اضافه نشود.
     *
     * فهرست **دقیق** pin می‌شود، نه «خالی بودن» — چون فهرست خالی یعنی هر کدام از
     * سه ارجاعِ مجاز هم باید برود، و آن‌ها سه استثنای نام‌برده‌اند نه تصادفی.
     */
    public function test_no_new_string_reference_to_a_central_table_outside_the_boundary(): void
    {
        $references = $this->references();

        $unexpected = [];
        foreach ($references as $file => $tables) {
            if (! array_key_exists($file, self::ALLOWED)) {
                $unexpected[$file] = array_values(array_unique($tables));
            }
        }

        ksort($unexpected);

        $this->assertSame(
            [],
            $unexpected,
            "این فایل‌ها مستقیم اسمِ جدولِ مرکزی را صدا می‌زنند. یا از رابط عبور کنند،\n"
            ."یا — اگر واقعاً استثنایند — با دلیل به `ALLOWED` اضافه شوند:\n"
            .implode("\n", array_map(
                static fn (string $f, array $t): string => '  '.$f.' → '.implode(', ', $t),
                array_keys($unexpected),
                array_values($unexpected),
            ))
        );
    }

    /**
     * ⭐ تستِ متقابل: هر استثنای فهرست باید **واقعاً** لازم باشد.
     *
     * بدون این، یک ارجاعِ حذف‌شده سال‌ها در `ALLOWED` می‌ماند و مرز عملاً باز
     * است بدون آنکه کسی بفهمد. یعنی هر فایل باید هنوز دست‌کم یک جدول را صدا بزند.
     */
    public function test_every_allowlisted_file_still_really_references_a_central_table(): void
    {
        $references = $this->references();

        foreach (array_keys(self::ALLOWED) as $file) {
            $this->assertArrayHasKey(
                $file,
                $references,
                '«'.$file.'» در `ALLOWED` است ولی دیگر هیچ جدولِ مرکزی را صدا نمی‌زند — استثنا را حذف کن.'
            );
        }
    }

    /**
     * فهرستِ استثنا نباید مرز را بلعیده باشد.
     *
     * سه مورد در برابر ده‌ها فایل. اگر روزی به ۱۰ رسید، تقریباً قطعی است که مرز
     * آب رفته نه اینکه یک موردِ مجازِ تازه اضافه شده باشد.
     */
    public function test_the_reference_allowlist_has_not_swallowed_the_boundary(): void
    {
        $this->assertLessThanOrEqual(
            4,
            count(self::ALLOWED),
            'استثناهای ارجاع زیاد شده‌اند — یعنی مرز عملاً حذف شده است.'
        );

        foreach (self::ALLOWED as $file => $why) {
            $this->assertFileExists(base_path($file), 'فایلِ استثنا «'.$file.'» روی دیسک نیست.');
            $this->assertNotSame('', trim($why), 'استثنای «'.$file.'» دلیل ندارد.');
        }
    }

    /**
     * خودِ تست نباید زیرِ دندهٔ خودش بخزد: فایلِ جارو نباید اسمِ جدول را **به‌عنوان
     * رشته** داشته باشد، وگرنه یک روز ارجاعِ خودش را می‌بیند.
     *
     * (فهرستِ جدول‌ها از `pg_tables` می‌آید نه از رشته، پس این تضمین می‌ماند حتی
     * وقتی کسی فهرست را دستی وارد کند.)
     */
    public function test_the_sweep_does_not_hardcode_a_table_name(): void
    {
        $source = (string) file_get_contents(__FILE__);

        foreach ($this->centralTables() as $table) {
            $this->assertStringNotContainsString(
                "'".$table."'",
                $source,
                'نامِ جدول داخل فایل تست hardcode شده — فهرست باید از دیتابیس بیاید وگرنه کهنه می‌شود.'
            );
        }
    }
}
