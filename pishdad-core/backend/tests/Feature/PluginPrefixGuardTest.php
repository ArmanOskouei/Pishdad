<?php

namespace Tests\Feature;

use App\Services\Plugins\PluginDdlConnection;
use App\Services\Plugins\PluginDdlProvisioner;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\PluginDdlNoTransactionCase;

/**
 * K5.12 — اثباتِ دیتابیسی قاعدهٔ `{slug}_*`.
 *
 * ⚠️ این تست‌ها با دیتابیسِ **واقعی** کار می‌کنند، نه SQLite. قاعده در خودِ
 * PostgreSQL است — یک event trigger و یک ACL — پس روی هیچ موتور دیگری معنا
 * ندارد. `RefreshDatabase` هم **عمداً** استفاده نشده: این تست جدول می‌سازد و
 * نقش می‌سازد، و ساختِ نقش داخل تراکنش بی‌اثر است.
 *
 * ## چرا رمز از پایه می‌آید و نه از اینجا
 *
 * نسخهٔ اول رمزِ خودش را در `setUp` می‌ستاند و نقش را با آن می‌ساخت. ولی
 * `tearDown` نقش را **نمی‌ساخت** — فقط پاک می‌کرد، و اگر جایی خطا می‌داد، نقش با
 * رمزِ این تست باقی می‌ماند و کلِ تست‌های بعدی با `migration.ddl_unavailable`
 * قرمز می‌شدند (۱۴ تست `PluginsTest` همین‌طور قرمز شدند).
 *
 * پس رمز **یکی** است و از `phpunit.agenta.xml` می‌آید و `PluginDdlTestCase`
 * همان را می‌خواند. یک حقیقت، مثل همان قاعدهٔ `isAvailable()`.
 */
final class PluginPrefixGuardTest extends PluginDdlNoTransactionCase
{
    private PluginDdlProvisioner $provisioner;

    private PluginDdlConnection $ddl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ddl = app(PluginDdlConnection::class);
        $this->provisioner = app(PluginDdlProvisioner::class);

        $this->ensureGuardInstalled();
        $this->provisioner->ensureRole();
        $this->provisioner->grantMinimal();
    }

    /**
     * نصبِ نگهبان، اگر نبود.
     *
     * این تست `RefreshDatabase` ندارد، پس به مهاجرتِ اجراشده توسط تست‌های دیگر
     * تکیه نمی‌کند — و نباید، چون در اجرای جداگانه هیچ تست دیگری قبلش نیست.
     */
    private function ensureGuardInstalled(): void
    {
        if (DB::getSchemaBuilder()->hasTable('plugin_table_prefixes')) {
            return;
        }

        $file = database_path('migrations/2026_10_07_000001_guard_plugin_table_prefixes.php');
        $migration = require $file;
        $migration->up();
    }

    protected function tearDown(): void
    {
        $this->dropGuardedTables();

        // پاک کردن رجیستری **بعد** از جدول‌ها. برعکسش یعنی نگهبان در
        // `DROP` گیر می‌کند — که درست است ولی تست را کند می‌کند.
        DB::table('plugin_table_prefixes')->delete();

        $this->ddl->disconnect();

        // ⚠️ نقش **پاک نمی‌شود**. `PluginDdlTestCase` آن را در
        // `setUpBeforeClass` می‌سازد و بین کلاس‌های تست زنده نگه می‌دارد.
        // پاک کردنش در هر `tearDown` باعث می‌شد تستِ بعدی نقش را با رمز خودش
        // دوباره بسازد و ۱۴ تست دیگر را خراب کند.

        parent::tearDown();
    }

    // ── پایه: هویت ──────────────────────────────────────────────────────

    public function test_the_plugin_connection_is_a_real_non_superuser_role(): void
    {
        $row = $this->asPlugin()->selectOne(
            'select session_user, current_user,
                    (select rolsuper from pg_roles where rolname = current_user) as is_super'
        );

        $this->assertSame(PluginDdlConnection::ROLE, $row->session_user);
        $this->assertSame(PluginDdlConnection::ROLE, $row->current_user);
        $this->assertFalse((bool) $row->is_super, 'نقش افزونه نباید superuser باشد.');
    }

    /**
     * یافتهٔ تعیین‌کنندهٔ K5.12.
     *
     * نسخهٔ اول طراحی با `SET LOCAL ROLE` بود و آزمایش نشان داد که بی‌اثر است.
     * این تست **همان مسیر را روی طراحیِ نهایی می‌سنجد** تا کسی بعداً برنگردد و
     * «ساده‌ترش کند».
     */
    public function test_the_plugin_role_cannot_escalate_back_to_the_core_role(): void
    {
        $pdo = $this->asPlugin()->getPdo();

        $threw = false;

        try {
            $pdo->exec('set role '.DB::connection()->getConfig('username'));
        } catch (\PDOException $e) {
            $threw = true;
            $this->assertStringContainsString('permission denied to set role', $e->getMessage());
        }

        $this->assertTrue($threw, 'ارتقای نقش نباید ممکن باشد.');
    }

    // ── مسیر ۱: نام مجاز ───────────────────────────────────────────────

    public function test_a_registered_name_may_be_created(): void
    {
        $this->provisioner->registerTables('acme', ['notes']);

        $this->asPlugin()->statement('create table acme_notes (id serial primary key)');

        $this->assertTrue($this->tableExists('acme_notes'));
    }

    public function test_a_core_table_may_still_be_created_by_the_core_role(): void
    {
        // هسته نباید محدود شود. اگر این قرمز شود یعنی trigger دامنه‌اش را
        // اشتباه پهن کرده و مهاجرت‌های خودِ اپ را می‌شکند.
        DB::statement('create table if not exists core_probe (id int)');

        $this->assertTrue($this->tableExists('core_probe'));
    }

    // ── مسیر ۲: نام اعلام‌نشده ──────────────────────────────────────────

    public function test_an_undeclared_name_is_denied(): void
    {
        $this->provisioner->registerTables('acme', ['notes']);

        $message = $this->expectDenial('create table acme_sneaky (id int)');

        $this->assertStringContainsString('acme_sneaky', $message);
        $this->assertFalse($this->tableExists('acme_sneaky'));
    }

    public function test_the_registry_being_empty_denies_everything(): void
    {
        // رجیستری خالی یعنی «هیچ چیز مجاز نیست»، نه «هر چیز مجاز است».
        $message = $this->expectDenial('create table anything_at_all (id int)');

        $this->assertStringContainsString('anything_at_all', $message);
    }
    // ── مسیر ۳: rename — شکاف دوم spike ────────────────────────────────

    public function test_a_registered_table_cannot_be_renamed_to_an_unregistered_name(): void
    {
        // فقط `notes` ثبت شده. `hidden` **عمداً** ثبت نشده.
        //
        // نسخهٔ اول این تست هر دو را ثبت می‌کرد و انتظار رد داشت، پس قرمز شد —
        // ولی نگهبان درست عمل کرده بود. تست اشتباه بود، نه قاعده. این همان
        // تستی است که اگر «مجبورش کنیم سبز شود»، یک قاعدهٔ غلط را قفل می‌کرد.
        $this->provisioner->registerTables('acme', ['notes']);
        $this->asPlugin()->statement('create table acme_notes (id int)');

        // spike گفته بود `WHEN TAG IN ('CREATE TABLE')` این را نمی‌گیرد.
        $message = $this->expectDenial('alter table acme_notes rename to acme_hidden');

        $this->assertStringContainsString('acme_hidden', $message);
        $this->assertTrue($this->tableExists('acme_notes'), 'نامِ اصلی باید سرِ جایش بماند.');
    }

    public function test_a_rename_between_two_registered_names_is_allowed(): void
    {
        $this->provisioner->registerTables('acme', ['notes', 'memos']);
        $this->asPlugin()->statement('create table acme_notes (id int)');

        $this->asPlugin()->statement('alter table acme_notes rename to acme_memos');

        $this->assertTrue($this->tableExists('acme_memos'));
    }

    // ── مسیر ۴: رجیستری از راه افزونه دست‌کاری نشود ───────────────────

    public function test_the_plugin_cannot_widen_its_own_registry(): void
    {
        $this->provisioner->registerTables('acme', ['notes']);

        $threw = false;

        try {
            $this->asPlugin()->statement("insert into plugin_table_prefixes (slug, table_name) values ('acme', 'backdoor')");
        } catch (\PDOException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'افزونه نباید بتواند رجیستری خودش را گسترش دهد.');

        // و حالا مطمئن شویم که واقعاً رد شد، نه اینکه فقط خطا خورد.
        $message = $this->expectDenial('create table acme_backdoor (id int)');
        $this->assertStringContainsString('acme_backdoor', $message);
    }

    public function test_the_plugin_cannot_replace_the_guard_function(): void
    {
        $threw = false;

        try {
            $this->asPlugin()->statement('create or replace function pishdad_guard_plugin_ddl() returns event_trigger language plpgsql as $$ begin return; end $$');
        } catch (\PDOException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'افزونه نباید بتواند تابع نگهبان را عوض کند.');
    }

    // ── مسیر ۵: جدول هسته — ACL این‌ها را می‌بندد، نه trigger ──────────

    /**
     * @return array<int, array{0: string}>
     */
    public static function coreTableAttackProvider(): array
    {
        return [
            ['drop table users'],
            ['truncate table users'],
            ['delete from users'],
            ['select count(*) from users'],
            ['alter table users add column probe int'],
            ['alter table users rename to users_hacked'],
            ['create index probe_idx on users (id)'],
            ['update users set email = null'],
        ];
    }

    /**
     * شکاف سوم spike بدون trigger بسته شد، چون نقش تازه با صفر امتیاز شروع
     * می‌شود و مالک جدول‌های هسته نیست.
     *
     * @dataProvider coreTableAttackProvider
     */
    public function test_the_plugin_cannot_touch_core_tables(string $sql): void
    {
        $threw = false;

        try {
            $this->asPlugin()->select($sql);
        } catch (\PDOException $e) {
            $threw = true;
        }

        $this->assertTrue(
            $threw,
            'این باید رد می‌شد ولی اجرا شد: '.$sql
        );
    }

    // ── مسیر ۷: خودِ امتیازها ───────────────────────────────────────────

    /**
     * وضعیتِ واقعیِ امتیازها، مستقل از اینکه `setUp` چه کرده.
     *
     * ⚠️ این تست از یک کشف آمده که با mutation پیدا شد، نه از بازبینی کد.
     *
     * `setUp` هر بار `grantMinimal()` را صدا می‌زند، پس اگر کسی امتیاز اضافه
     * بدهد، **قبل از اجرای هر تست ترمیم می‌شود** و هیچ تستی آن را نمی‌بیند.
     * mutation امتیازِ اضافه، ۱۹ تست را سبز نگه داشت.
     *
     * یعنی تست‌ها داشتند «اینکه `grantMinimal()` درست کار می‌کند» را می‌سنجیدند،
     * نه «وضعیتِ دیتابیس درست است». این تست دومی را می‌سنجد و مستقیم از کاتالوگ
     * PostgreSQL می‌خواند.
     */
    public function test_the_role_holds_no_dml_on_core_tables(): void
    {
        $grants = DB::select(
            'select table_name, privilege_type
               from information_schema.role_table_grants
              where grantee = ?
                and table_schema = current_schema()
              order by table_name, privilege_type',
            [PluginDdlConnection::ROLE],
        );

        $summary = [];

        foreach ($grants as $row) {
            $summary[] = $row->table_name.':'.$row->privilege_type;
        }

        $this->assertSame(
            ['plugin_table_prefixes:SELECT'],
            $summary,
            'نقش افزونه فقط باید SELECT روی رجیستری داشته باشد. هر چیز دیگری یعنی '
                .'یا امتیازی اضافه داده شده یا ACL از روی دیتابیس منحرف شده است.'
        );
    }

    /**
     * اینکه `grantMinimal()` واقعاً **ترمیم** می‌کند.
     *
     * تست بالا وضعیت را می‌خواند ولی خودش آن را نمی‌سازد. این یکی عمداً
     * امتیاز اضافه می‌دهد و بعد ترمیم را می‌سنجد — یعنی اگر کسی `grantMinimal()`
     * را طوری تغییر دهد که دیگر چیزی را پس نگیرد، همین‌جا قرمز می‌شود.
     *
     * بدون این، یک حذفِ ساده در `REVOKE` بی‌صدا از کار می‌افتاد.
     */
    public function test_granting_more_than_minimal_is_repaired(): void
    {
        DB::unprepared(
            'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO '.PluginDdlConnection::ROLE
        );

        // تأیید که واقعاً اضافه شد — وگرنه این تست خودش کاذب می‌شود.
        $before = DB::select(
            "select count(*) as n from information_schema.role_table_grants
              where grantee = ? and privilege_type in ('INSERT','UPDATE','DELETE')",
            [PluginDdlConnection::ROLE],
        );
        $this->assertGreaterThan(0, (int) $before[0]->n, 'امتیاز اضافه اعمال نشد؛ تست بی‌معنی است.');

        $this->provisioner->grantMinimal();

        $after = DB::select(
            "select count(*) as n from information_schema.role_table_grants
              where grantee = ? and privilege_type in ('INSERT','UPDATE','DELETE')",
            [PluginDdlConnection::ROLE],
        );
        $this->assertSame(0, (int) $after[0]->n, 'امتیاز اضافه باید ترمیم می‌شد.');
    }

    // ── مسیر ۸: بی‌اثر بودن gate وقتی هسته DDL می‌کند ──────────────────

    public function test_the_guard_ignores_the_core_role_entirely(): void
    {
        // اگر نگهبان روی `cms` هم فعال بود، این رد می‌شد و کل نصب می‌شکست.
        DB::statement('create table if not exists core_unregistered (id int)');
        $this->assertTrue($this->tableExists('core_unregistered'));

        // و جدولی که افزونه می‌سازد هم باید بتواند بی‌نام بماند اگر رجیستری
        // اجازه دهد — یعنی trigger واقعاً دارد به نقش نگاه می‌کند، نه به اینکه
        // «همه‌چیز را رد کند».
        $this->provisioner->registerTables('acme', ['fine']);
        $this->asPlugin()->statement('create table acme_fine (id int)');
        $this->assertTrue($this->tableExists('acme_fine'));
    }

    // ── کمکی ───────────────────────────────────────────────────────────

    /**
     * اتصال با هویتِ نقش افزونه.
     *
     * از resolver لاراول می‌گیرد نه یک PDO خام، چون `PluginDdlConnection` پیش‌فرض
     * را عوض می‌کند و خودِ اتصال از `config/database.php` می‌آید — همان مسیری که
     * کدِ افزونه واقعاً استفاده می‌کند. تستِ جدا با PDO خام فقط یک مسیرِ موازی را
     * می‌سنجید که در عمل وجود ندارد.
     */
    private function asPlugin(): Connection
    {
        return DB::connection(PluginDdlConnection::CONNECTION);
    }

    private function expectDenial(string $sql): string
    {
        try {
            $this->asPlugin()->statement($sql);
        } catch (\PDOException $e) {
            $this->assertSame(
                '42501',
                $e->getCode(),
                'رد باید با insufficient_privilege باشد نه خطای دیگر: '.$e->getMessage()
            );

            return $e->getMessage();
        }

        $this->fail('این باید رد می‌شد ولی اجرا شد: '.$sql);
    }

    private function tableExists(string $name): bool
    {
        $row = DB::selectOne(
            'select 1 as ok from pg_tables where schemaname = current_schema() and tablename = ?',
            [$name],
        );

        return $row !== null;
    }

    /**
     * پاک کردن هر چیزی که این تست‌ها ساخته‌اند.
     *
     * فهرست **صریح** است نه یک الگوی کلی، چون یک الگوی کلی روی دیتابیسِ تستِ
     * مشترک می‌تواند جدولِ تستِ دیگری را ببرد. ضمناً همین فهرست است که ثابت می‌کند
     * هر تست واقعاً از صفر شروع می‌شود — نسخهٔ اول `anything_at_all` را جا انداخت
     * و تست بعدی با `Duplicate table` قرمز شد.
     */
    private function dropGuardedTables(): void
    {
        $names = DB::select(
            "select tablename from pg_tables where schemaname = current_schema() and (
                tablename like 'acme\_%'
                or tablename = 'anything_at_all'
                or tablename = 'core_probe'
                or tablename = 'core_unregistered'
             )"
        );

        foreach ($names as $row) {
            DB::statement('drop table if exists "'.$row->tablename.'" cascade');
        }

        // sequenceهای `serial` جدا می‌افتند و `drop table` آن‌ها را نمی‌برد.
        $sequences = DB::select(
            "select c.relname as name from pg_class c
               join pg_namespace n on n.oid = c.relnamespace
              where c.relkind = 'S' and n.nspname = current_schema()
                and c.relname like 'acme\_%'"
        );

        foreach ($sequences as $row) {
            DB::statement('drop sequence if exists "'.$row->name.'" cascade');
        }
    }
}
