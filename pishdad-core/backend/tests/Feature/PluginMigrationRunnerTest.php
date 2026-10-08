<?php

namespace Tests\Feature;

use App\Services\Plugins\PluginMigrationRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * K5.6 گام ۲ — راستی‌آزمایی و بازنویسی migration.
 *
 * ارزش این تست‌ها در تست‌کردنِ «قبول» نیست؛ در تست‌کردنِ **رد** است. هر فرمی که
 * این کلاس نشناسد باید نصب را رد کند، چون نشناختن یعنی احتمالاً یک جدول بدون
 * prefix که بی‌صدا به هسته می‌نویسد.
 */
class PluginMigrationRunnerTest extends TestCase
{
    private PluginMigrationRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new PluginMigrationRunner;
    }

    // ── ۱) استخراج ارجاع‌ها ────────────────────────────────────────────

    public function test_it_extracts_tables_from_the_recognised_call_sites(): void
    {
        $source = <<<'PHP'
        <?php
        Schema::create('posts', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users');
        });
        Schema::table('posts', function (Blueprint $table) {
            $table->index('slug')->on('posts');
        });
        $rows = DB::table('comments')->get();
        PHP;

        $tables = $this->runner->extractTables($source);

        foreach (['posts', 'users', 'comments'] as $expected) {
            $this->assertContains($expected, $tables);
        }
    }

    public function test_a_clean_migration_reports_no_unsafe_reasons(): void
    {
        $source = "<?php Schema::create('posts', function (Blueprint \$t) { \$t->string('title'); });";

        $this->assertSame([], $this->runner->unsafeReasons($source));
    }

    // ── ۲) بازنویسی ───────────────────────────────────────────────────

    public function test_rewrite_prefixes_every_recognised_table(): void
    {
        $source = "<?php Schema::create('posts', function () {}); DB::table('comments');";

        $out = $this->runner->rewrite($source, 'demo');

        $this->assertStringContainsString("Schema::create('demo_posts'", $out);
        $this->assertStringContainsString("DB::table('demo_comments')", $out);
    }

    public function test_rewrite_preserves_the_original_quote_style(): void
    {
        $out = $this->runner->rewrite('<?php Schema::table("posts", function () {});', 'demo');

        $this->assertStringContainsString('Schema::table("demo_posts"', $out);
    }

    public function test_rewrite_does_not_touch_non_table_strings(): void
    {
        $source = "<?php \$table->string('title'); \$table->default('draft');";

        $this->assertSame($source, $this->runner->rewrite($source, 'demo'));
    }

    // ── ۳) راستی‌آزمایی: اعلام‌نشده رد می‌شود ──────────────────────────

    public function test_a_table_outside_the_declaration_is_rejected(): void
    {
        $source = "<?php Schema::create('posts', function () {}); DB::table('secrets');";

        $errors = $this->runner->verify($source, ['posts']);

        $this->assertContains('migration.undeclared_table:secrets', $errors);
        $this->assertNotContains('migration.undeclared_table:posts', $errors);
    }

    public function test_a_matching_declaration_passes(): void
    {
        $source = "<?php Schema::create('posts', function (\$t) { \$t->foreignId('user_id')->constrained('authors'); });";

        $this->assertSame([], $this->runner->verify($source, ['posts', 'authors']));
    }

    // ── ۴) fail-closed: فرم‌های ناشناخته ──────────────────────────────

    #[DataProvider('unsafeSources')]
    public function test_unsafe_migrations_are_rejected(string $source, string $because): void
    {
        $errors = $this->runner->verify($source, ['posts', 'users', 'everything']);

        $this->assertNotSame(
            [],
            $errors,
            'این فرم باید رد می‌شد ('.$because.') ولی گذشت: '.substr($source, 0, 80)
        );
    }

    public static function unsafeSources(): array
    {
        return [
            'raw select' => ["<?php DB::select('SELECT * FROM users');", 'SQL خام'],
            'DB::raw' => ["<?php DB::raw('SELECT * FROM users');", 'SQL خام — حتی وقتی select نیست'],
            'DB::cursor' => ["<?php DB::cursor('SELECT 1');", 'SQL خام'],
            'affectingStatement' => ["<?php DB::affectingStatement('DELETE FROM users');", 'SQL خام'],
            'raw statement' => ["<?php DB::statement('DROP TABLE posts');", 'SQL خام'],
            'unprepared' => ["<?php DB::unprepared('DELETE FROM users');", 'SQL خام'],
            'dynamic table name' => ['<?php Schema::create($table, function () {});', 'نام پویا'],
            'dynamic DB::table' => ["<?php DB::table(\$prefix.'users');", 'نام پویا'],
            'concat table name' => ["<?php Schema::create('pre'.\$x, function () {});", 'نام الحاقی'],
            'explicit schema' => ["<?php \$t->setSchema('other');", 'schema صریح از prefix رد می‌شود'],
            'other connection' => ["<?php DB::connection('other')->table('users');", 'اتصال دیگر'],
        ];
    }

    /**
     * گاردِ خودکار: اگر الگویی از `TABLE_CALLS` جا بماند ولی ارجاعی شناسایی نشود،
     * باید ** loud** شکست بخورد نه اینکه بی‌صدا رد شود.
     */
    public function test_call_sites_without_any_recognised_reference_are_rejected(): void
    {
        $errors = $this->runner->verify('<?php Schema::create($t, function () {});', ['posts']);

        $this->assertNotSame([], $errors, 'سایت فراخوانی بدون ارجاعِ قابل‌تشخیص باید رد شود.');
    }

    /**
     * گاردِ call-opener: سایتِ فراخوانی هست ولی هیچ ارجاعِ قابل‌تشخیصی نیست.
     *
     * این با گارد «نام پویا» فرق دارد و عمداً جدا تست می‌شود. آن گارد وقتی
     * آرگومان با `$` شروع شود می‌گیرد؛ اینجا آرگومان **کوتیشن دارد** ولی
     * `TABLE_CALLS` آن را نمی‌شناسد، چون نامش خط تیره دارد. اگر فقط به گارد نام
     * پویا تکیه کنیم، این migration **بی‌صدا** رد می‌شد و یک جدول بدون prefix
     * ساخته می‌شد.
     */
    public function test_quoted_but_unrecognised_table_name_is_rejected(): void
    {
        $errors = $this->runner->verify("<?php Schema::create('my-table', function () {});", ['posts']);

        $this->assertNotSame(
            [],
            $errors,
            'نام کوتیشن‌دار ولی ناشناخته باید رد شود، وگرنه جدولِ بدون prefix ساخته می‌شود.'
        );
    }

    /**
     * `->on('x')` باید **به‌تنهایی** یک ارجاع بشمرد.
     *
     * تستِ استخراج قبلی `->on('posts')` داشت ولی `posts` از `Schema::create` هم
     * می‌آمد، پس حذف `on` از `TABLE_CALLS` چیزی را عوض نمی‌کرد و mutation زنده
     * ماند. اینجا تنها منبعِ ارجاع است.
     */
    public function test_on_clause_alone_counts_as_a_table_reference(): void
    {
        $source = "<?php \$t->index('slug')->on('search_index');";

        $this->assertSame(['search_index'], $this->runner->extractTables($source));
    }

    public function test_constrained_clause_alone_counts_as_a_table_reference(): void
    {
        $source = "<?php \$table->foreignId('team_id')->constrained('teams');";

        $this->assertSame(['teams'], $this->runner->extractTables($source));
    }

    /**
     * هر فرمِ `Schema::` باید **به‌تنهایی** یک ارجاع بشمارد.
     *
     * اگر `hasTable` را از الگو حذف کنیم و جایی نباشد که خودش تنها منبع باشد، هیچ
     * تستی قرمز نمی‌شود — و جدولی که migration فقط وجودش را می‌پرسد بی‌صدا بدون
     * prefix می‌ماند. این تست هر فرم را جدا می‌سنجد تا آن اتفاق نیفتد.
     */
    public function test_every_schema_call_form_is_recognised_on_its_own(): void
    {
        foreach (['create', 'table', 'drop', 'dropIfExists', 'hasTable', 'hasColumn', 'hasColumns'] as $method) {
            $source = "<?php Schema::{$method}('solo', []);";

            $this->assertSame(
                ['solo'],
                $this->runner->extractTables($source),
                "Schema::{$method} باید به‌تنهایی تشخیص داده شود."
            );
        }
    }

    public function test_a_migration_with_no_table_calls_at_all_is_accepted(): void
    {
        // migration خالی (مثلاً فقط یک stub) نه جدول می‌سازد نه ادعایی دارد.
        $source = '<?php return new class extends Migration { public function up(): void {} };';

        $this->assertSame([], $this->runner->verify($source, []));
    }

    /**
     * بازنویسی باید PHP معتبر تولید کند، نه فقط متن درست‌ظاهر.
     *
     * نسخهٔ اول callback فقط سرِ تطبیق را نگه می‌داشت و کوتیشن بسته را می‌انداخت،
     * پس خروجی `Schema::create('demo_posts, function …` بود — که parse نمی‌شود.
     * این تست دقیقاً همان را می‌گیرد.
     */
    public function test_rewritten_output_is_still_valid_php(): void
    {
        $source = "<?php\nSchema::create('posts', function (Blueprint \$table) {\n    \$table->foreignId('user_id')->constrained('users');\n});\n";

        $out = $this->runner->rewrite($source, 'demo');

        $tmp = tempnam(sys_get_temp_dir(), 'mig').'.php';
        file_put_contents($tmp, $out);

        try {
            exec('php -l '.escapeshellarg($tmp).' 2>&1', $outLines, $code);
        } finally {
            @unlink($tmp);
        }

        $this->assertSame(0, $code, 'خروجی بازنویسی‌شده parse نمی‌شود: '.implode("\n", $outLines));
        $this->assertStringContainsString("Schema::create('demo_posts'", $out);
        $this->assertStringContainsString("->constrained('demo_users')", $out);
    }

    // ── ۵) تعامل با اعلام ─────────────────────────────────────────────

    public function test_verify_reports_every_undeclared_table_not_just_the_first(): void
    {
        $source = "<?php DB::table('a'); DB::table('b'); DB::table('c');";

        $errors = $this->runner->verify($source, []);

        $this->assertCount(3, $errors, 'گزارش باید کامل باشد تا نویسنده همه را یک‌جا اصلاح کند.');
    }
}
