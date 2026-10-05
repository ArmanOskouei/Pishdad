<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * K7.6 — قیدِ یکتاییِ `site_id` روی جدولِ اشتراک.
 *
 * ## چرا این تست خودش جدول را می‌سازد
 *
 * جدولِ `subscription_subscriptions` مالِ افزونهٔ اشتراک است و در مهاجرت‌های
 * هسته ساخته نمی‌شود — یعنی وقتی `php artisan migrate` در یک نصبِ تازه اجرا
 * می‌شود، قیدِ ما با guard رد می‌شود و بعداً افزونه ساخته‌اش می‌کند. پس برای
 * سنجیدنِ خودِ قید، تست هم همان جدول را با همان شکل می‌سازد و بعد مهاجرت را
 * اجرا می‌کند. این عمدی است: تست باید **قید** را بسنجد، نه اینکه به ترتیبِ
 * نصبِ افزونه وابسته باشد.
 *
 * ## سه چیز که اینجا سنجیده می‌شود
 *
 *  ۱. دو ردیفِ **زنده** با یک `site_id` ⇒ دیتابیس رد می‌کند. این خودِ K7.6 است.
 *  ۲. یک ردیفِ **soft-deleteشده** با همان `site_id` ⇒ مجاز. اگر این قرصن
 *     نبود، قید یکتاییِ سراسری استقرارِ واقعی را می‌شکست، چون مهاجرتِ داده
 *     فقط ردیف‌های زنده را جمع کرده و ردیف‌های حذف‌نرم‌شده عمداً می‌مانند.
 *  ۳. نبودِ جدول ⇒ مهاجرت بی‌سروصدا رد می‌شود (نصبِ عمومیِ بدون افزونه).
 */
class UniqueSiteIdSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'subscription_subscriptions';

    protected function tearDown(): void
    {
        // مهاجرتِ هسته در ترتیبِ واقعی **قبل** از ساخته‌شدنِ جدولِ افزونه اجرا
        // می‌شود، پس در پایانِ هر تست این جدول را برمی‌داریم تا تستِ بعدی
        // `ready()` را با داده‌ی تستِ ما نبیند.
        Schema::dropIfExists(self::TABLE);

        parent::tearDown();
    }

    public function test_two_live_rows_for_one_installation_are_refused(): void
    {
        $this->makeTable();
        $this->migration()->up();

        $this->insertRow(siteId: 'site-7', deletedAt: null);

        $rejected = $this->insertExpectingRefusal(
            fn () => $this->insertRow(siteId: 'site-7', deletedAt: null)
        );

        $this->assertTrue($rejected, 'دو ردیفِ زنده برای یک نصب نباید ممکن باشد.');
    }

    public function test_a_soft_deleted_row_may_share_the_same_installation_key(): void
    {
        $this->makeTable();
        $this->migration()->up();

        $this->insertRow(siteId: 'site-7', deletedAt: null);
        $this->insertRow(siteId: 'site-7', deletedAt: now());

        // یک ردیفِ حذف‌نرم + یک ردیفِ زنده = همان چیزی که مهاجرتِ داده تولید
        // می‌کند. اگر این رد می‌شد، K7.6 روی هر نصبِ واقعی می‌شکست.
        $this->assertSame(2, DB::table(self::TABLE)->where('site_id', 'site-7')->count());

        $this->insertRow(siteId: 'site-8', deletedAt: null);

        $this->assertSame(1, DB::table(self::TABLE)->whereNull('deleted_at')->where('site_id', 'site-8')->count());
    }

    public function test_null_site_id_never_breaks_the_constraint(): void
    {
        $this->makeTable();
        $this->migration()->up();

        // PostgreSQL چند NULL را در ایندکسِ یکتا مجاز می‌داند. یک ردیفِ بی‌کلید
        // یعنی نصبی که هنوز کلید نگرفته، نه یک نصبِ دوم.
        $this->insertRow(siteId: null, deletedAt: null);
        $this->insertRow(siteId: null, deletedAt: null);

        $this->assertSame(2, DB::table(self::TABLE)->whereNull('site_id')->count());
    }

    public function test_up_is_idempotent(): void
    {
        $this->makeTable();

        $migration = $this->migration();
        $migration->up();
        $migration->up();

        $this->addToAssertionCount(1);
    }

    public function test_a_missing_table_is_silently_skipped(): void
    {
        Schema::dropIfExists(self::TABLE);

        $this->migration()->up();

        $this->assertFalse(Schema::hasTable(self::TABLE));
    }

    public function test_down_removes_only_our_index(): void
    {
        $this->makeTable();
        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $this->insertRow(siteId: 'site-7', deletedAt: null);
        $this->insertRow(siteId: 'site-7', deletedAt: null);

        $this->assertSame(2, DB::table(self::TABLE)->where('site_id', 'site-7')->count());
    }

    /**
     * یک درج را می‌کوشد و انتظارِ رد شدن دارد، **بدون** کثیف کردنِ تراکنشِ تست.
     *
     * ⚠️ چرا savepoint لازم است: `RefreshDatabase` هر تست را در یک تراکنش
     * می‌پیچد، و در PostgreSQL یک خطای قید کلِ تراکنش را «aborted» می‌کند — یعنی
     * هر فرمانِ بعدی تا پایان بلاک خطا می‌دهد و حتی `tearDown` هم می‌مرد.
     * `ROLLBACK TO SAVEPOINT` دقیقاً برای همین کار است: تراکنشِ بیرونی زنده
     * می‌ماند و فقط اثرِ آن یک درج برمی‌گردد.
     */
    private function insertExpectingRefusal(callable $insert): bool
    {
        DB::unprepared('SAVEPOINT k76_probe');

        try {
            $insert();
            DB::unprepared('RELEASE SAVEPOINT k76_probe');
        } catch (QueryException) {
            DB::unprepared('ROLLBACK TO SAVEPOINT k76_probe');

            return true;
        }

        return false;
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_16_000001_unique_site_id_subscriptions.php');
    }

    private function makeTable(): void
    {
        Schema::dropIfExists(self::TABLE);

        Schema::create(self::TABLE, function ($table): void {
            $table->id();
            $table->string('site_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('deleted_at')->nullable();
        });
    }

    private function insertRow(?string $siteId, mixed $deletedAt): void
    {
        DB::table(self::TABLE)->insert([
            'site_id' => $siteId,
            'status' => 'active',
            'deleted_at' => $deletedAt === null ? null : (string) $deletedAt,
        ]);
    }
}
