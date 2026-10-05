<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * K7.5 — پیشوند central_ روی جدول‌های پنل مرکزی.
 *
 * روی cms_test_a اجرا می‌شود (phpunit.agenta.xml) و با RefreshDatabase در تراکنش
 * می‌چرخد، پس RENAME و INSERT هیچ اثری روی دیتابیس cms نمی‌گذارد.
 */
class CentralTablePrefixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * J5 — این تست دربارهٔ **مهاجرتِ rename** است، پس به وضعیتِ «بعد از rename» نیاز
     * دارد: جدول‌ها با نامِ `central_*`.
     *
     * تا پیش از J5 این وضعیت را core می‌ساخت. الان بستهٔ افزونه می‌سازد — پس اینجا
     * نصب می‌شود. `migration()->down()` در تست‌ها وضعیتِ «قبل» را برمی‌گرداند و بقیه
     * مثل قبل کار می‌کنند.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->installCentralPluginTables();
    }

    private const MIGRATION_PATH = 'database/migrations/2026_10_05_000001_prefix_central_tables.php';

    /** @var array<string, string> */
    private const RENAMES = [
        'core_versions' => 'central_core_versions',
        'install_heartbeats' => 'central_install_heartbeats',
        'kill_switches' => 'central_kill_switches',
        'operator_activities' => 'central_operator_activities',
        'operator_hardware_keys' => 'central_operator_hardware_keys',
        'operator_setup_tokens' => 'central_operator_setup_tokens',
        'update_rollouts' => 'central_update_rollouts',
        'update_rollout_items' => 'central_update_rollout_items',
    ];

    /** @var list<string> جدول‌هایی که از قبل پیشوند داشتند و نباید دست بخورند. */
    private const ALREADY_PREFIXED = [
        'central_payments',
        'central_plans',
        'central_clients',
        'central_subscriptions',
        'central_invoices',
        'central_billing_events',
        'central_support_tokens',
        'central_backups',
        'central_chabokan_deployments',
        'central_chabokan_logs',
        'central_logs',
    ];

    // K5.5: `plugin_hooks` از این فهرست بیرون است — همان فایل سازنده‌اش ساخته بود ولی
    // `2026_10_06_000001` حذفش کرد، و این تست `hasTable` را assert می‌کند.
    /** @var list<string> جدول‌های هسته‌ای که در همان فایل مهاجرت ساخته شده‌اند. */
    private const CORE_TABLES_IN_SAME_MIGRATION = [
        'tickets',
        'ticket_messages',
        'plugins',
        'notifications',
        'side_presets',
        'publisher_keys',
        'pages',
        'users',
    ];

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION_PATH);
    }

    public function test_every_unprefixed_central_table_gained_the_prefix(): void
    {
        foreach (self::RENAMES as $old => $new) {
            $this->assertFalse(Schema::hasTable($old), "«{$old}» نباید بعد از مهاجرت وجود داشته باشد.");
            $this->assertTrue(Schema::hasTable($new), "«{$new}» باید بعد از مهاجرت وجود داشته باشد.");
        }
    }

    public function test_rows_written_before_the_migration_are_still_there_after_it(): void
    {
        $migration = $this->migration();

        // به وضعیت «قبل از مهاجرت» برمی‌گردیم، داده می‌نویسیم، بعد مهاجرت را اجرا می‌کنیم.
        $migration->down();

        $coreVersionId = DB::table('core_versions')->insertGetId([
            'version' => '9.9.9-keep', 'is_latest' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $activityId = DB::table('operator_activities')->insertGetId([
            'action' => 'تست حفظ داده', 'ip' => '127.0.0.1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rolloutId = DB::table('update_rollouts')->insertGetId([
            'version' => '9.9.9-keep', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $clientId = DB::table('central_clients')->insertGetId([
            'name' => 'مشتری تست K7', 'tier' => 'budget', 'status' => 'active',
            'site_id' => 'site-'.uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $itemId = DB::table('update_rollout_items')->insertGetId([
            'rollout_id' => $rolloutId, 'client_id' => $clientId,
            'to_version' => '9.9.9-keep', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // شمارش مطلق به داده‌های زمان اجرا وابسته است؛ مقایسهٔ قبل/بعد دقیق است.
        $itemsBefore = DB::table('update_rollout_items')->count();
        $rolloutIdsBefore = DB::table('update_rollout_items')->pluck('id')->sort()->all();

        $migration->up();

        $this->assertFalse(Schema::hasTable('core_versions'));
        $this->assertTrue(Schema::hasTable('central_core_versions'));

        $this->assertDatabaseHas('central_core_versions', ['id' => $coreVersionId, 'version' => '9.9.9-keep']);
        $this->assertDatabaseHas('central_operator_activities', ['id' => $activityId, 'action' => 'تست حفظ داده']);
        $this->assertDatabaseHas('central_update_rollouts', ['id' => $rolloutId, 'version' => '9.9.9-keep']);
        $this->assertDatabaseHas('central_update_rollout_items', [
            'id' => $itemId, 'rollout_id' => $rolloutId, 'client_id' => $clientId,
        ]);

        $this->assertSame($itemsBefore, DB::table('central_update_rollout_items')->count());
        $this->assertSame($rolloutIdsBefore, DB::table('central_update_rollout_items')->pluck('id')->sort()->all());
    }

    public function test_running_up_twice_does_not_fail(): void
    {
        $migration = $this->migration();

        $migration->up();
        $migration->up();

        foreach (self::RENAMES as $old => $new) {
            $this->assertTrue(Schema::hasTable($new), "«{$new}» باید بعد از اجرای دوباره سر جایش باشد.");
            $this->assertFalse(Schema::hasTable($old));
            $this->assertFalse(Schema::hasTable('central_'.$new), 'پیشوند نباید دوباره اضافه شود.');
        }
    }

    public function test_down_restores_the_original_names_and_keeps_the_rows(): void
    {
        $migration = $this->migration();

        DB::table('central_core_versions')->insert([
            'version' => '8.8.8-rollback', 'is_latest' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->down();

        foreach (self::RENAMES as $old => $new) {
            $this->assertTrue(Schema::hasTable($old), "«{$old}» باید بعد از down() برگردد.");
            $this->assertFalse(Schema::hasTable($new), "«{$new}» نباید بعد از down() باقی بماند.");
        }

        $this->assertDatabaseHas('core_versions', ['version' => '8.8.8-rollback']);
    }

    public function test_already_prefixed_tables_are_not_touched(): void
    {
        $before = $this->objectNamesFor(self::ALREADY_PREFIXED);

        $this->migration()->up();

        $this->assertSame($before, $this->objectNamesFor(self::ALREADY_PREFIXED));
        $this->assertFalse(Schema::hasTable('central_central_payments'));
        $this->assertTrue($this->indexExists('central_payments_pkey'));
    }

    public function test_core_tables_sharing_the_source_migration_are_not_touched(): void
    {
        $before = $this->objectNamesFor(self::CORE_TABLES_IN_SAME_MIGRATION);

        $this->migration()->up();

        $this->assertSame($before, $this->objectNamesFor(self::CORE_TABLES_IN_SAME_MIGRATION));
    }

    public function test_constraints_and_indexes_were_renamed_too(): void
    {
        $this->assertTrue($this->indexExists('central_core_versions_pkey'));
        $this->assertTrue($this->indexExists('central_core_versions_version_unique'));
        $this->assertTrue($this->constraintExists('central_core_versions_pkey'));
        $this->assertTrue($this->constraintExists('central_core_versions_version_unique'));
        $this->assertFalse($this->indexExists('core_versions_pkey'));
        $this->assertFalse($this->constraintExists('core_versions_pkey'));

        $this->assertTrue($this->indexExists('central_operator_activities_user_id_action_index'));
        $this->assertFalse($this->indexExists('operator_activities_user_id_action_index'));

        $this->assertTrue($this->constraintExists('central_update_rollout_items_client_id_foreign'));
        $this->assertTrue($this->constraintExists('central_update_rollout_items_rollout_id_client_id_unique'));
    }

    public function test_serial_sequence_was_renamed_and_still_feeds_the_primary_key(): void
    {
        $this->assertTrue($this->relationExists('central_core_versions_id_seq'));
        $this->assertFalse($this->relationExists('core_versions_id_seq'));
        $this->assertTrue($this->relationExists('central_update_rollout_items_id_seq'));

        $id = DB::table('central_core_versions')->insertGetId([
            'version' => '7.7.7-seq', 'is_latest' => false,
        ]);

        $this->assertGreaterThan(0, $id);
        $this->assertDatabaseHas('central_core_versions', ['id' => $id]);
    }

    public function test_foreign_keys_still_resolve_against_the_renamed_tables(): void
    {
        $defs = $this->foreignKeyDefinitions('central_update_rollout_items');

        $this->assertStringContainsString('central_update_rollouts(id)', $defs['central_update_rollout_items_rollout_id_foreign']);
        $this->assertStringContainsString('central_clients(id)', $defs['central_update_rollout_items_client_id_foreign']);
        $this->assertArrayNotHasKey('update_rollout_items_rollout_id_foreign', $defs);
    }

    /**
     * ⭐ J5 — قاعدهٔ تازه: جدولِ افزونه **به جدولِ هسته** قیدِ خارجی ندارد.
     *
     * این تست قبلاً انتظار داشت `central_update_rollouts_created_by_foreign` به
     * `users(id)` اشاره کند. حالا ندارد — و نداشتنش **عمدی** است، نهRegression:
     *
     * `PluginMigrationRunner` هر فراخوانیِ `constrained` را با prefix اسلاگ
     * بازنویسی می‌کند، حتی وقتی آرگومانش جدولِ هسته باشد. یعنی قیدِ
     * `created_by → users` در بستهٔ افزونه به `central_users` تبدیل می‌شد: جدولی که
     * وجود ندارد و افزونه حق ساختنش را ندارد (`PluginDbContract::CORE_TABLES`).
     * یعنی یا فعال‌سازی می‌شکست، یا FK به جدولِ ناموجود می‌رفت.
     *
     * پس ستون می‌ماند و قید نمی‌ماند — همان تصمیمی که
     * `plugins/subscription/.../2026_10_14_000001` برای `owner_user_id` گرفت.
     *
     * این تست آن را **قفل** می‌کند: هر قیدِ خارجی از یک جدولِ `central_*` به یک
     * جدولِ غیرمرکزی، از این به بعد باگ است، چون یا DDL شکست می‌خورد یا به
     * جدولِ اشتباه اشاره می‌کند.
     */
    public function test_no_central_table_has_a_foreign_key_into_the_core(): void
    {
        $coreTables = array_map(
            static fn (object $row): string => (string) $row->tablename,
            DB::select("select tablename from pg_tables where schemaname = current_schema() and tablename not like 'central\_%'")
        );

        $offenders = [];

        foreach ($this->centralTables() as $table) {
            foreach ($this->foreignKeyDefinitions($table) as $name => $def) {
                foreach ($coreTables as $core) {
                    if (str_contains($def, $core.'(id)')) {
                        $offenders[] = $table.' → '.$name.' → '.$core;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "جدولِ افزونه نباید به جدولِ هسته قیدِ خارجی داشته باشد — بازنویسیِ prefix\n"
            ."آن را به `central_{$core}` تبدیل می‌کند:\n".implode("\n", $offenders)
        );

        // ستون باید مانده باشد؛ فقط قید رفته است.
        $this->assertTrue(
            Schema::hasColumn('central_update_rollouts', 'created_by'),
            'ستون باید بماند — حذفِ قید به معنی حذفِ رابطه نیست، فقط از سطح دیتابیس به سطحِ کد می‌رود.'
        );
    }

    /** @return list<string> */
    private function centralTables(): array
    {
        $rows = DB::select("select tablename from pg_tables where schemaname = current_schema() and tablename like 'central\_%'");

        $names = array_map(static fn (object $row): string => (string) $row->tablename, $rows);

        sort($names);

        return $names;
    }

    public function test_cascade_still_works_through_the_renamed_rollout_fk(): void
    {
        $clientId = DB::table('central_clients')->insertGetId([
            'name' => 'مشتری کشساری', 'tier' => 'budget', 'status' => 'active',
            'site_id' => 'site-'.uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $rolloutId = DB::table('central_update_rollouts')->insertGetId([
            'version' => '6.6.6', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('central_update_rollout_items')->insert([
            'rollout_id' => $rolloutId, 'client_id' => $clientId,
            'to_version' => '6.6.6', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('central_update_rollouts')->where('id', $rolloutId)->delete();

        $this->assertSame(0, DB::table('central_update_rollout_items')->where('rollout_id', $rolloutId)->count());
    }

    /**
     * نام جدول‌ها، ایندکس‌ها، constraintها و دنباله‌های یک مجموعه جدول، مرتب‌شده.
     * برای اثبات «دست‌نخورده ماندن» قبل/بعد مقایسه می‌شود.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function objectNamesFor(array $tables): array
    {
        $names = [];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "جدول «{$table}» باید وجود داشته باشد.");

            $qualified = 'public.'.$table;
            $names[] = 'table:'.$table;

            foreach (DB::select('SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?', [$table]) as $row) {
                $names[] = 'index:'.$row->indexname;
            }

            foreach (DB::select('SELECT conname FROM pg_constraint WHERE connamespace = current_schema()::regnamespace AND conrelid = to_regclass(?)', [$qualified]) as $row) {
                $names[] = 'constraint:'.$row->conname;
            }

            if ($this->relationExists($table.'_id_seq')) {
                $names[] = 'sequence:'.$table.'_id_seq';
            }
        }

        sort($names);

        return $names;
    }

    /** @return array<string, string> */
    private function foreignKeyDefinitions(string $table): array
    {
        $rows = DB::select(
            'SELECT conname, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE connamespace = current_schema()::regnamespace AND conrelid = to_regclass(?) AND contype = ?',
            ['public.'.$table, 'f']
        );

        $defs = [];

        foreach ($rows as $row) {
            $defs[(string) $row->conname] = (string) $row->def;
        }

        return $defs;
    }

    private function indexExists(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 AS present FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ?',
            [$name]
        ) !== null;
    }

    private function constraintExists(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 AS present FROM pg_constraint WHERE connamespace = current_schema()::regnamespace AND conname = ?',
            [$name]
        ) !== null;
    }

    private function relationExists(string $name): bool
    {
        return DB::selectOne('SELECT to_regclass(?) AS reg', ['public.'.$name])?->reg !== null;
    }
}
