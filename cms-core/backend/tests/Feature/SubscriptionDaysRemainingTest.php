<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * رگرسیونِ تناقضِ کارتِ اشتراک در داشبورد.
 *
 * ## گزارشِ کاربر (و آنچه واقعاً دیده می‌شد)
 *
 * کارت هم‌زمان این‌ها را نشان می‌داد:
 *
 *     وضعیت: active
 *     سررسید: ۱۴۰۵/۰۸/۰۷ (۲۸ روز مانده)
 *     روز باقی‌مانده: ۰          ← تناقض
 *     ۱۰۰٪ از دوره گذشته        ← نتیجهٔ همان صفر
 *
 * ## ریشه
 *
 * `Subscription::daysRemaining()` **برای اشتراکِ معلق** نوشته شده بود: «چند
 * روز تا حذف نهایی». برای هر اشتراکِ `active` عمداً صفر برمی‌گرداند.
 *
 * ولی `DashboardController` همان متد را به‌عنوان «چند روز تا سررسیدِ دورهٔ
 * پرداخت» می‌فرستاد. پس هر مشترکِ فعال همیشه «۰ روز» می‌دید، و چون نوارِ
 * پیشرفت از همین عدد می‌آمد، همیشه «۱۰۰٪ گذشته» هم نشان داده می‌شد.
 *
 * تاریخِ سررسید از یک جای دیگر می‌آمد (`relativeFa`) و درست بود — به همین
 * دلیل دو عددِ متناقض کنار هم دیده می‌شدند.
 *
 * ## راه‌حل
 *
 * دو مفهوم، دو متد:
 *   - `daysUntilDue()`        ⇒ تا سررسیدِ پرداخت (چیزی که داشبورد می‌خواهد)
 *   - `purgeDaysRemaining()`  ⇒ تا حذف نهاییِ اشتراکِ معلق
 *
 * نامِ مبهمِ قبلی حذف شد تا مصرف‌کنندهٔ قدیمی **خطای کامپایل** بدهد نه
 * رفتارِ غلطِ خاموش.
 */
class SubscriptionDaysRemainingTest extends TestCase
{
    /**
     * ساختِ یک اشتراک با سررسیدِ مشخص، مستقیم در جدول.
     *
     * `RefreshDatabase` استفاده نمی‌کنیم چون جدول `central_subscriptions` مالِ
     * افزونهٔ مرکز است و در نصبِ عمومی وجود ندارد — پس این تست خودش جدول را
     * می\u200cسازد و در پایان پاکش می\u200cکند. این یعنی روی نصبی که افزونه
     * ندارد، تست skip می\u200cشود و نمی\u200cشکند.
     */
    private function ensureTable(): bool
    {
        if (! DB::getSchemaBuilder()->hasTable('central_subscriptions')) {
            $this->markTestSkipped('افزونهٔ مرکز نصب نیست؛ جدول central_subscriptions وجود ندارد.');
        }

        return true;
    }

    private function makeSubscription(string $status, ?int $dueInDays, ?int $suspendedDaysAgo = null): int
    {
        $this->ensureTable();

        $id = DB::table('central_subscriptions')->insertGetId([
            'client_id' => $this->clientId,
            'site_id' => 0,
            'plan_id' => $this->planId,
            'status' => $status,
            'due_at' => $dueInDays === null ? null : now()->addDays($dueInDays),
            'suspended_at' => $suspendedDaysAgo === null ? null : now()->subDays($suspendedDaysAgo),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTable();

// کلیدِ خارجیِ اجباری: `client_id` باید مشتریِ واقعی باشد و خودِ مشتری هم
// `site_id` اجباری دارد. یک مشتریِ موقت می‌سازیم و در `tearDown` پاکش می‌کنیم
// — وگرنه هر تست یک ردیفِ یتیم از خودش به جا می‌گذارد.
$this->clientId = DB::table('central_clients')->insertGetId([
    'name' => 'probe-client',
    'site_id' => 'probe.local',
    'tier' => 'probe',
    'status' => 'active',
'created_at' => now(),
            'updated_at' => now(),
        ]);

        // `plan_id` هم کلید خارجیِ اجباری است و `daysUntilDue()` به آن
        // دست نمی\u200cزند، ولی درج بدون\u200cش شکست می\u200cخورد.
        $this->planId = DB::table('central_plans')->insertGetId([
            'name' => 'probe-plan',
            'slug' => 'probe-plan-'.uniqid(),
            'price_monthly' => 0,
            'price_yearly' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        if (DB::getSchemaBuilder()->hasTable('central_subscriptions')) {
            DB::table('central_subscriptions')->where('client_id', $this->clientId)->delete();
        }

        if (DB::getSchemaBuilder()->hasTable('central_plans')) {
            DB::table('central_plans')->where('id', $this->planId)->delete();
        }

        if (DB::getSchemaBuilder()->hasTable('central_clients')) {
            DB::table('central_clients')->where('id', $this->clientId)->delete();
        }

        parent::tearDown();
    }

    private int $clientId = 0;

    private int $planId = 0;

    private function sub(int $id): object
    {
        $model = new \Pishdad\Plugins\Central\Models\Subscription();

        $found = $model->newQuery()->find($id);

        $this->assertNotNull($found, 'رکورد اشتراک باید خوانده شود');

        return $found;
    }

    public function test_an_active_subscription_reports_days_until_due(): void
    {
        $id = $this->makeSubscription('active', 28);

        // ⭐ همین بود که صفر می‌داد.
        $this->assertSame(28, $this->sub($id)->daysUntilDue());
    }

    public function test_days_until_due_counts_down(): void
    {
        $id = $this->makeSubscription('active', 3);
        $this->assertSame(3, $this->sub($id)->daysUntilDue());
    }

    public function test_a_pass_due_date_never_returns_a_negative(): void
    {
        $id = $this->makeSubscription('past_due', -5);

        $this->assertSame(0, $this->sub($id)->daysUntilDue());
    }

    public function test_a_missing_due_date_is_zero_not_negative(): void
    {
        $id = $this->makeSubscription('active', null);

        $this->assertSame(0, $this->sub($id)->daysUntilDue());
    }

    public function test_purge_days_ignores_an_active_subscription(): void
    {
        // ⭐ این رفتارِ قدیمی و **درست** است: پاک‌سازی فقط برای معلق معنی دارد.
        $id = $this->makeSubscription('active', 28);

        $this->assertSame(0, $this->sub($id)->purgeDaysRemaining());
    }

    public function test_purge_days_counts_down_for_a_suspended_subscription(): void
    {
        config(['billing.purge_after_suspended_days' => 7]);

        $id = $this->makeSubscription('suspended', null, 3);

        $this->assertSame(4, $this->sub($id)->purgeDaysRemaining());
    }

    public function test_the_two_questions_never_collide(): void
    {
        $config = config(['billing.purge_after_suspended_days' => 7]);

        $active = $this->sub($this->makeSubscription('active', 28));
        $this->assertSame(28, $active->daysUntilDue());
        $this->assertSame(0, $active->purgeDaysRemaining());
    }
}