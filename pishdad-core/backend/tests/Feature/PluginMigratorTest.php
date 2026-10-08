<?php

namespace Tests\Feature;

use App\Services\Plugins\PluginDdlConnection;
use App\Services\Plugins\PluginDdlProvisioner;
use App\Services\Plugins\PluginMigrationRunner;
use App\Services\Plugins\PluginMigrator;
use App\Services\Plugins\PluginReleaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\PluginDdlNoTransactionCase;

/**
 * K5.6 گام ۳ — اجرای migration با prefix.
 *
 * این تست‌ها روی دیتابیس واقعی اجرا می‌شوند، چون ادعای اصلی «جدول با prefix ساخته
 * می‌شود» فقط با یک جدول واقعی ثابت می‌شود.
 *
 * ## ⚠️ چرا `RefreshDatabase` ندارد — K5.12
 *
 * با K5.12، migration افزونه روی **اتصال جدا** با نقش `pishdad_plugin_ddl`
 * اجرا می‌شود، نه روی اتصالِ هسته.
 *
 * `RefreshDatabase` کل تست را در یک تراکنشِ بازِ روی اتصالِ هسته می‌پیچد. نقشی که
 * در `setUp` با `CREATE ROLE` ساخته می‌شود **داخلِ همان تراکنش** زندگی می‌کند، پس
 * اتصالِ جدا آن را نمی‌بیند و `isAvailable()` می‌گوید «نیست».
 *
 * نتیجهٔ اولیه سه تست قرمز با پیامِ `migration.ddl_unavailable` بود — پیامی که
 * دربارهٔ چیزی حرف می‌زد که این تست اصلاً نمی‌سنجد. این بدترین شکل قرمزی است:
 * تست در جای اشتباه ایستاده.
 *
 * پس نقش بیرون از تراکنش ساخته و در `tearDown` برداشته می‌شود. `RefreshDatabase`
 * هم نمی‌ماند چون تراکنشِ باز، `REVOKE … ON ALL TABLES` را در `grantMinimal()`
 * قفل می‌کند.
 */
class PluginMigratorTest extends PluginDdlNoTransactionCase
{
    private PluginMigrator $migrator;

    private string $release;

    protected function setUp(): void
    {
        parent::setUp();

        // نقش را `PluginDdlTestCase` **بیرون از تراکنش** ساخته — چون
        // `CREATE ROLE` تا `COMMIT` واقعی دیده نمی‌شود.
        $this->migrator = new PluginMigrator(
            new PluginMigrationRunner,
            app(PluginDdlConnection::class),
            app(PluginDdlProvisioner::class),
        );

        $this->release = rtrim(sys_get_temp_dir(), '/').'/pishdad-test-release-'.uniqid();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->release);
        $this->dropTestTables();

        parent::tearDown();
    }

    /**
     * جدول‌هایی که این تست‌ها می‌سازند.
     *
     * فهرست صریح، چون دیتابیسِ تست مشترک است و یک الگوی کلی می‌تواند
     * جدولِ تستِ دیگری را ببرد.
     */
    private function dropTestTables(): void
    {
        $rows = DB::select(
            "select tablename from pg_tables where schemaname = current_schema()
             and (tablename like 'demo\_%' or tablename in ('posts', 'tags', 'demo'))"
        );

        foreach ($rows as $row) {
            DB::statement('drop table if exists "'.$row->tablename.'" cascade');
        }

        DB::table('plugin_table_prefixes')->whereIn('slug', ['demo', 'other'])->delete();
        DB::table('pishdad_plugin_migrations')->whereIn('slug', ['demo', 'other'])->delete();
    }

    /**
     * `PluginReleaseManager` واقعی، روی یک ریشهٔ موقت.
     *
     * اول سعی کردم `currentDir` را mock کنم، ولی کلاس `final` است و Mockery ردش
     * می‌کند. راه درست‌تر — و تستِ قوی‌تر — این است که **ساختار واقعی** را
     * بسازیم: `releases/{label}/Laravel/...` به‌علاوهٔ فایل اشاره‌گر `.current`.
     * آن‌وقت مسیری که `PluginMigrator` می‌خواند، همان مسیری است که در تولید
     * می‌خواند، نه یک رشتهٔ ساختگی.
     */
    private function releases(): PluginReleaseManager
    {
        return new PluginReleaseManager($this->release);
    }

    /**
     * یک نسخهٔ نصب‌شدهٔ واقعی می‌سازد و آن را فعال می‌کند.
     *
     * `manifest.json` در ریشهٔ نسخه الزامی است: `PluginReleaseManager::isUsable()`
     * بدون آن نسخه را «ناقص» می‌داند و `currentDir()` مقدار `null` می‌دهد. بدون
     * این فایل، تست‌ها به‌جای سنجیدن migration، فقط داشتن `null` را می‌سنجیدند —
     * که دقیقاً همان تستی است که همه‌چیز را سبز می‌کرد بدون آنکه چیزی بسنجد.
     *
     * @param  array<string, string>  $migrations  نام فایل => محتوا
     */
    private function install(array $migrations, string $slug = 'demo', string $label = '1.0.0-abc123'): void
    {
        $release = $this->releases()->releaseDir($slug).'/'.$label;
        $dir = $release.'/Laravel/database/migrations';

        File::ensureDirectoryExists($dir);

        foreach ($migrations as $name => $body) {
            File::put($dir.'/'.$name, $body);
        }

        File::put($release.'/manifest.json', (string) json_encode([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'version' => '1.0.0',
        ], JSON_UNESCAPED_UNICODE));

        // فایل اشاره‌گر JSON است با کلید `release` — متن خام را `readPointer()`
        // نمی‌خواند و بی‌صدا `null` می‌دهد.
        File::put(
            $this->releases()->pointerPath($slug),
            (string) json_encode(['release' => $label], JSON_UNESCAPED_UNICODE)
        );

    }

    // ── ۱) اجرای واقعی ────────────────────────────────────────────────

    public function test_it_runs_the_migration_and_creates_the_prefixed_table(): void
    {
        $this->install([
            '2026_01_01_000001_create_posts.php' => $this->migration('create', 'posts'),
        ]);

        $result = $this->migrator->run($this->releases(), 'demo', ['posts']);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertTrue(
            Schema::hasTable('demo_posts'),
            'جدول باید با prefix ساخته شده باشد، نه با نام خام.'
        );
        $this->assertFalse(Schema::hasTable('posts'), 'جدول بدون prefix نباید وجود داشته باشد.');
    }

    public function test_it_records_the_migration_so_activation_is_idempotent(): void
    {
        $this->install([
            '2026_01_01_000001_create_posts.php' => $this->migration('create', 'posts'),
        ]);

        $this->migrator->run($this->releases(), 'demo', ['posts']);
        $second = $this->migrator->run($this->releases(), 'demo', ['posts']);

        $this->assertTrue($second['ok']);
        $this->assertSame([], $second['applied'], 'دفعهٔ دوم نباید چیزی اجرا کند.');
        $this->assertCount(1, $second['skipped']);

        $this->assertSame(1, DB::table('pishdad_plugin_migrations')->where('slug', 'demo')->count());
    }

    /**
     * دو افزونه می‌توانند فایل هم‌نام داشته باشند. اگر سابقه به slug محدود نشود،
     * فایل هم‌نامِ افزونهٔ دوم «قبلاً اجرا شده» می‌شد و جدولش ساخته نمی‌شد —
     * بی‌صدا و فقط در تولید.
     */
    public function test_two_plugins_with_the_same_filename_both_run(): void
    {
        $body = $this->migration('create', 'posts');

        $this->install(['2026_01_01_000001_create_posts.php' => $body], 'alpha', '1.0.0-aaa');
        $this->install(['2026_01_01_000001_create_posts.php' => $body], 'beta', '1.0.0-bbb');

        $first = $this->migrator->run($this->releases(), 'alpha', ['posts']);
        $second = $this->migrator->run($this->releases(), 'beta', ['posts']);

        $this->assertTrue($first['ok'], $first['message']);
        $this->assertTrue($second['ok'], $second['message']);
        $this->assertTrue(Schema::hasTable('alpha_posts'), 'افزونهٔ اول.');
        $this->assertTrue(Schema::hasTable('beta_posts'), 'افزونهٔ دوم باید مستقل اجرا شده باشد.');
    }

    // ── ۲) رد کردن، پیش از هر اجرا ─────────────────────────────────────

    public function test_an_undeclared_table_is_rejected_and_nothing_runs(): void
    {
        $this->install([
            '2026_01_01_000001_create_posts.php' => $this->migration('create', 'posts'),
            '2026_01_02_000001_touch_core.php' => $this->migration('drop', 'users'),
        ]);

        $result = $this->migrator->run($this->releases(), 'demo', ['posts']);

        $this->assertFalse($result['ok'], 'باید رد شود.');
        $this->assertStringContainsString('users', $result['message']);

        // تفکیکِ «پیش از اجرا رد شد» از «اجرا شد و تراکنش برگشت».
        //
        // نسخهٔ اول این تست فقط `hasTable('users')` و `hasTable('demo_posts')` را
        // می‌سنجید، و هر دو در هر دو حالت یکی‌اند: اگر `verify()` را خاموش
        // کنیم، `users` حذف می‌شود، خطا می‌دهد و تراکنش برمی‌گردد — پس تست
        // **سبز می‌ماند در حالی که دقیقاً کاری را می‌کند که نباید**. تنها تفاوت
        // قابل تشخیص، کد پاسخ است: `migration.rejected` یعنی هرگز اجرا نشد،
        // `migration.failed` یعنی اجرا شد و برگشت.
        $this->assertSame(
            'migration.rejected',
            $result['code'],
            'باید پیش از هر اجرا رد می‌شد، نه اینکه اجرا شود و تراکنش برگردد.'
        );
        $this->assertSame([], $result['applied']);

        // نکتهٔ اصلی: فایل اول *هم* نباید اجرا شده باشد. ترتیب «بازنویسی همه،
        // بعد اجرا» به همین دلیل است.
        $this->assertFalse(
            Schema::hasTable('demo_posts'),
            'هیچ migration نباید اجرا شده باشد — حتی فایل‌های بی‌خطا قبل از فایل رد‌شده.'
        );
        $this->assertTrue(Schema::hasTable('users'), 'جدول هسته باید سالم مانده باشد.');
    }

    public function test_raw_sql_is_rejected(): void
    {
        $this->install([
            '2026_01_01_000001_evil.php' => "<?php DB::statement('DROP TABLE users');",
        ]);

        $result = $this->migrator->run($this->releases(), 'demo', ['posts']);

        $this->assertFalse($result['ok']);
        $this->assertTrue(Schema::hasTable('users'));
    }

    public function test_a_failure_rolls_back_the_whole_batch(): void
    {
        // فایل اول درست است، فایل دوم یک جدول تکراری می‌سازد ⇒ خطا در میانه.
        $this->install([
            '2026_01_01_000001_create_posts.php' => $this->migration('create', 'posts'),
            '2026_01_02_000001_create_posts_again.php' => $this->migration('create', 'posts'),
        ]);

        $result = $this->migrator->run($this->releases(), 'demo', ['posts']);

        $this->assertFalse($result['ok']);
        $this->assertFalse(
            Schema::hasTable('demo_posts'),
            'اگر تراکنش نبود، جدول فایل اول می‌ماند و نصب نیمه‌کاره می‌شد.'
        );
    }

    /**
     * افزونه‌ای که اصلاً پوشهٔ `database/migrations` ندارد باید سالم رد شود.
     *
     * نسخهٔ اول این تست فقط یک پوشه می‌ساخت و اشاره‌گر نمی‌نوشت، پس
     * `currentDir()` مقدار `null` می‌داد و انتظار `ok` داشت — یعنی به‌جای «بدون
     * migration»، داشت «بدون نسخهٔ نصب‌شده» را می‌سنجید. این تست باید واقعاً یک
     * نسخهٔ معتبر *بدون* migration بسازد.
     */
    public function test_a_release_with_no_migrations_directory_is_fine(): void
    {
        $this->install([]);

        $result = $this->migrator->run($this->releases(), 'demo', []);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame([], $result['applied']);
    }

    /**
     * افزونه‌ای که جدول اعلام کرده ولی نسخه‌ای روی دیسک ندارد باید رد شود.
     *
     * تفکیک این با حالت «نه نسخه داریم نه اعلام» عمداً تست شده: دومی `ok` است
     * (کاری برای کردن نیست) ولی اولی خطاست، چون فعال کردن افزونه‌ای که
     * جدول‌هایش ساخته نشده یعنی افزونه‌ای که در اولین درخواست می‌میرد.
     */
    public function test_declared_tables_without_a_release_is_an_error(): void
    {
        $result = $this->migrator->run($this->releases(), 'ghost', ['posts']);

        $this->assertFalse($result['ok']);
        $this->assertSame('migration.no_release', $result['code']);
    }

    /** نبودِ نسخه **و** نبودِ اعلام ⇒ کاری نیست، پس خطا هم نیست. */
    public function test_no_release_and_no_declaration_is_a_clean_no_op(): void
    {
        $result = $this->migrator->run($this->releases(), 'ghost', []);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame([], $result['applied']);
    }

    // ── ۳) اعلام از مانیفست ───────────────────────────────────────────

    public function test_it_reads_declared_tables_from_the_manifest(): void
    {
        $manifest = [
            'db' => ['tables' => [
                ['name' => 'posts'],
                ['name' => 'comments', 'indexes' => ['post_id']],
                'garbage',
            ]],
        ];

        $this->assertSame(['posts', 'comments'], $this->migrator->declaredTables($manifest));
        $this->assertSame([], $this->migrator->declaredTables([]));
    }

    /** بدنهٔ migration واقعی که framework بتواند اجرایش کند. */
    private function migration(string $verb, string $table): string
    {
        return <<<PHP
        <?php

        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Database\\Schema\\Blueprint;
        use Illuminate\\Support\\Facades\\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::{$verb}('{$table}', function (Blueprint \$table): void {
                    \$table->id();
                    \$table->string('title')->default('');
                });
            }

            public function down(): void
            {
                Schema::dropIfExists('{$table}');
            }
        };
        PHP;
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.'/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeTree($entry) : @unlink($entry);
        }

        @rmdir($dir);
    }
}
