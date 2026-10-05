<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ⭐ K7.6 — داده از per-user به per-installation رفت.
 *
 * ## چرا این تست دستی مهاجرت را اجرا می‌کند
 *
 * `RefreshDatabase` فقط migrationهای **هسته** را اجرا می‌کند. جدول
 * `subscription_subscriptions` مالِ افزونهٔ `subscription` است، پس هر تستی که
 * به آن نیاز دارد خودش می‌سازدش (همان کاری که `ServesPrivatePlugin` می‌کند).
 *
 * وضعیت «قبل از مهاجرت» هم قابل ساختن است: جدول را از اولین migration بافزونه
 * می‌سازیم و مهاجرتِ K7.6 را **دستی** صدا می‌زنیم. برعکسِ
 * `CentralTablePrefixTest` که `down()` می‌زند، اینجا `up()` لازم است چون کارِ
 * مهاجرت در `up()` است.
 *
 * ## چه چیزی را ثابت می‌کند
 *
 *  ۱) تصمیمِ باین‌شدهٔ Q1: **کلید، نصب است** — هر ردیفِ زنده یک `site_id`
 *     غیرخالی دارد و برای هر نصب فقط **یک** ردیفِ زنده می‌ماند.
 *  ۲) **سوپرادمین مستثنی است** — ردیفش soft-delete می‌شود، نه اینکه صورتحساب
 *     مخفی بسازد.
 *  ۳) **idempotent**: `up()` دوم هیچ تغییری نمی‌دهد. بدون این تست، یک نصب که
 *     migration دوباره اجرا می‌شود (نصبِ تازهٔ افزونه روی دیتابیسِ قدیمی) یا ردیف
 *     اضافه soft-delete می‌کند یا خطای قیدِ یکتا می‌خورد.
 *  ۴) **هیچ سطری حذف نمی‌شود** و فاکتورها جابه‌جا نمی‌شوند — تاریخِ مالی باید
 *     دست‌نخورده بماند.
 */
class InstallationLevelSubscriptionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_15_000001_installation_level_subscriptions.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPluginTables();
    }

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION);
    }

    /**
     * فقط جدولِ K7.6 را می‌سازد — یعنی وضعیت «قبل از مهاجرت».
     *
     * کل migrationهای افزونه اجرا نمی‌شوند چون آن‌ها داده را از `central_*` کپی
     * می‌کنند و برای این تست بی‌معنی‌اند. فقط اسکیما لازم است.
     */
    private function createPluginTables(): void
    {
        if (Schema::hasTable('subscription_subscriptions')) {
            return;
        }

        Schema::create('subscription_plans', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->jsonb('features')->default('[]');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscription_subscriptions', function ($table) {
            $table->id();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('site_id')->nullable();
            $table->unsignedBigInteger('central_client_id')->nullable();
            $table->foreignId('plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->unique('owner_user_id');
        });

        Schema::create('subscription_invoices', function ($table) {
            $table->id();
            $table->foreignId('subscription_id')->nullable()
                ->constrained('subscription_subscriptions')->cascadeOnDelete();
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('status', 20)->default('pending');
            $table->string('gateway_ref')->nullable()->unique();
            $table->timestamps();
        });
    }

    private function user(bool $superAdmin = false): User
    {
        return User::query()->create([
            'name' => 'کاربر تست',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Pass!1234'),
            'role' => 'admin',
            'is_super_admin' => $superAdmin,
        ])->fresh();
    }

    /** @param array<string, mixed> $extra */
    private function row(?int $ownerUserId, ?string $siteId, array $extra = []): int
    {
        return DB::table('subscription_subscriptions')->insertGetId(array_merge([
            'owner_user_id' => $ownerUserId,
            'site_id' => $siteId,
            'status' => 'active',
            'due_at' => now()->addMonth(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    /** @return list<array<string, mixed>> */
    private function liveRows(): array
    {
        return DB::table('subscription_subscriptions')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * ⭐ سناریوی واقعی: سه کاربر روی **یک** نصب.
     *
     * قبلاً این یعنی سه ردیفِ صورتحساب برای یک سایت، و `publicStatus($siteId)`
     * همیشه اولین را برمی‌گرداند. بعد: یک ردیف، و بقیه با فاکتورهای خودشان محفوظ.
     */
    public function test_three_users_on_one_installation_collapse_into_one_live_row(): void
    {
        $first = $this->user();
        $second = $this->user();
        $third = $this->user();

        // `ensureFor()` قدیمی برای هر کاربر `site-{id}` جدا می‌ساخت.
        $a = $this->row($first->id, 'site-'.$first->id);
        $b = $this->row($second->id, 'site-'.$second->id);
        $c = $this->row($third->id, 'site-'.$third->id);

        $this->migration()->up();

        // هر سه به یک کلیدِ نصب می‌رسند — این قلبِ تصمیم Q1.
        DB::table('subscription_subscriptions')->where('id', $a)->update(['site_id' => 'install-1']);
        DB::table('subscription_subscriptions')->where('id', $b)->update(['site_id' => 'install-1']);
        DB::table('subscription_subscriptions')->where('id', $c)->update(['site_id' => 'install-1']);

        $this->migration()->up();

        $live = DB::table('subscription_subscriptions')->whereNull('deleted_at')->orderBy('id')->pluck('id')->all();

        $this->assertSame([$a], $live, 'برای یک نصب باید دقیقاً یک ردیفِ زنده بماند — قدیمی‌ترین.');

        // و هیچ سطری گم نشده است.
        $this->assertSame(3, DB::table('subscription_subscriptions')->count());
    }

    /** کلیدِ نصب روی ردیفی که هیچ `site_id` نداشت، از همان قرارداد قدیمی ساخته می‌شود. */
    public function test_a_row_without_a_site_id_gets_a_derived_installation_key(): void
    {
        $owner = $this->user();
        $withOwner = $this->row($owner->id, null);
        $orphan = $this->row(null, null);

        $this->migration()->up();

        $this->assertSame(
            'site-'.$owner->id,
            DB::table('subscription_subscriptions')->where('id', $withOwner)->value('site_id'),
            'کلیدِ قدیمیِ `ensureFor()` باید حفظ شود — `install_token` و polling همان را می‌بینند.'
        );

        $this->assertSame(
            'install-'.$orphan,
            DB::table('subscription_subscriptions')->where('id', $orphan)->value('site_id'),
            'ردیفِ بی‌مالک هم باید کلیدِ نصب بگیرد، وگرنه `publicStatus($siteId)` هرگز آن را نمی‌بیند.'
        );
    }

    /** هیچ ردیفِ زنده‌ای نباید `site_id` خالی یا null داشته باشد. */
    public function test_no_live_row_is_left_without_an_installation_key(): void
    {
        $this->user();
        $this->row(1, '');
        $this->row(null, null);
        $this->row(2, '   ');

        $this->migration()->up();

        $blank = DB::table('subscription_subscriptions')
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', '')->orWhere('site_id', '   '))
            ->count();

        $this->assertSame(0, $blank, 'ردیفِ زندهٔ بدون کلیدِ نصب یعنی نصبی که هرگز صورتحساب نمی‌شود.');
    }

    /**
     * ⭐ قاعدهٔ «سوپرادمین صورتحساب مخفی ندارد».
     *
     * این قاعده از قبل در `ClientSubscriptionResolver::forUser()` بود، ولی آن فقط
     * *جلوی ساخت* را می‌گرفت. دادهٔ ساخته‌شدهٔ قبل از آن تصمیم، هنوز سر جایش بود.
     */
    public function test_a_superadmin_row_is_soft_deleted_not_removed(): void
    {
        $boss = $this->user(true);
        $client = $this->user();

        $bossRow = $this->row($boss->id, 'site-'.$boss->id);
        $clientRow = $this->row($client->id, 'site-'.$client->id);

        $this->migration()->up();

        $this->assertNotNull(
            DB::table('subscription_subscriptions')->where('id', $bossRow)->value('deleted_at'),
            'ردیفِ سوپرادمین باید soft-delete شود.'
        );

        $this->assertNull(
            DB::table('subscription_subscriptions')->where('id', $clientRow)->value('deleted_at'),
            'سطرِ مشتریِ عادی نباید دست بخورد.'
        );

        // «حذف» به‌معنای گم کردن سطر نیست — این قاعدهٔ «هرگز DROP» است.
        $this->assertSame(2, DB::table('subscription_subscriptions')->count());
    }

    /** سطرِ soft-deleteشده باید توسط مدل هم soft-delete دیده شود، نه اینکه گم باشد. */
    public function test_the_soft_deleted_rows_are_still_reachable_through_the_model(): void
    {
        $boss = $this->user(true);

        $this->row($boss->id, 'site-'.$boss->id);

        $this->migration()->up();

        $class = 'Pishdad\\Plugins\\Subscription\\Models\\Subscription';

        if (! class_exists($class)) {
            $this->markTestSkipped('مدل افزونهٔ اشتراک در این نصب حاضر نیست.');
        }

        $this->assertSame(1, $class::withTrashed()->count(), 'سطرِ نرم‌حذف‌شده باید در `withTrashed()` باشد.');
        $this->assertSame(0, $class::count(), 'و نباید در کوئریِ معمولی بیاید.');
    }

    /** ⭐ idempotent — و این تنها چیزی است که K7.6 را قابل اعتماد می‌کند. */
    public function test_running_up_twice_changes_nothing(): void
    {
        $a = $this->user();
        $b = $this->user();
        $boss = $this->user(true);

        $this->row($a->id, 'install-1');
        $this->row($b->id, 'install-1');
        $this->row($boss->id, 'install-1');
        $this->row(null, null);

        $this->migration()->up();

        $before = DB::table('subscription_subscriptions')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->migration()->up();
        $this->migration()->up();

        $after = DB::table('subscription_subscriptions')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->assertSame($before, $after, 'اجرای دوبارهٔ `up()` باید بی‌اثر باشد.');
    }

    /**
     * فاکتورِ کاربرِ بازنده **سرِ جایش** می‌ماند.
     *
     * این مهم‌ترین تصمیمِ این مهاجرت است: جمع کردن ردیف‌ها یعنی یک نصب، یک
     * صورتحساب. اگر فاکتورِ کاربرِ دوم به ردیفِ اول منتقل شود، سابقهٔ مالیِ او پاک
     * می‌شود — و هیچ‌وقت برنمی‌گردد.
     */
    public function test_invoices_stay_attached_to_their_own_row(): void
    {
        $first = $this->user();
        $second = $this->user();

        $kept = $this->row($first->id, 'install-1');
        $lost = $this->row($second->id, 'install-1');

        DB::table('subscription_invoices')->insert([
            ['subscription_id' => $kept, 'amount' => 1000, 'status' => 'paid', 'created_at' => now(), 'updated_at' => now()],
            ['subscription_id' => $lost, 'amount' => 2000, 'status' => 'paid', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->migration()->up();

        $this->assertSame(2, DB::table('subscription_invoices')->count(), 'هیچ فاکتوری نباید حذف یا ادغام شود.');
        $this->assertSame(2000, (int) DB::table('subscription_invoices')->where('subscription_id', $lost)->value('amount'));
        $this->assertNotNull(DB::table('subscription_subscriptions')->where('id', $lost)->value('deleted_at'));
    }

    /** ردیفِ از قبل soft-deleteشده نباید دوباره دست بخورد یا برگردد. */
    public function test_an_already_soft_deleted_row_is_left_alone(): void
    {
        $gone = $this->user();

        $id = DB::table('subscription_subscriptions')->insertGetId([
            'owner_user_id' => $gone->id,
            'site_id' => 'install-9',
            'status' => 'deleted',
            'deleted_at' => now()->subMonth(),
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ]);

        $this->migration()->up();

        $this->assertTrue(
            DB::table('subscription_subscriptions')->where('id', $id)->whereNotNull('deleted_at')->exists(),
            'سطرِ از قبل حذف‌شده باید همان‌جا بماند.'
        );
    }

    /**
     * نصبِ عمومیِ بدون افزونهٔ اشتراک: جدول نیست و مهاجرت باید بی‌سروصدا رد شود.
     *
     * بدون این guard، `php artisan migrate` در هر نصبِ عمومی ۵۰۰ می‌داد.
     */
    public function test_it_is_a_no_op_when_the_plugin_tables_are_absent(): void
    {
        Schema::drop('subscription_invoices');
        Schema::drop('subscription_subscriptions');
        Schema::drop('subscription_plans');

        $this->assertFalse(Schema::hasTable('subscription_subscriptions'));

        $this->migration()->up();
        $this->migration()->down();

        $this->assertFalse(Schema::hasTable('subscription_subscriptions'), 'مهاجرت نباید چیزی بسازد.');
    }

    /**
     * جدولِ افزونه در مسیر واقعی‌اش هست — اگر جابه‌جا شود، این تست دیگر هیچ چیز را
     * ثابت نمی‌کند ولی سبز می‌ماند (چون `createPluginTables()` خودش می‌سازد).
     */
    public function test_the_plugin_really_declares_these_tables(): void
    {
        $manifest = json_decode(
            (string) @file_get_contents($this->pluginDir().'/manifest.json'),
            true
        );

        $this->assertIsArray($manifest, 'مانیفست افزونهٔ اشتراک خوانده نشد.');
        $this->assertContains('subscription_subscriptions', $manifest['db']['tables'] ?? []);

        $source = (string) @file_get_contents($this->pluginDir().'/Laravel/database/migrations/2026_10_14_000001_create_subscription_tables.php');
        $this->assertStringContainsString("Schema::create('subscription_subscriptions'", $source);
    }

    /**
     * J11 — مسیر بستهٔ خصوصی: جای اصلی یا قرنطینه.
     */
    private function pluginDir(): string
    {
        foreach ([
            base_path('plugins/subscription'),
            base_path('plugins/_quarantine/subscription'),
        ] as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }

        $this->fail('بستهٔ اشتراک نه زیر plugins/ است و نه زیر plugins/_quarantine/.');
    }
}
