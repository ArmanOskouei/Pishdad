<?php

namespace Tests\Feature;

use App\Mail\OutboundEmail;
use App\Services\Outbox\Outbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * F1.3 — outbox مشترک + `outbox:drain`.
 *
 * ⭐ چهار ادعا که `F0.6` بند ۴ صریحاً خواسته:
 *  ۱. **claim اتمیک** — دو tick همزمان یک سطر را برنمی‌دارند.
 *  ۲. **idempotent** — enqueue دوبارهٔ همان پیام یک سطر است، نه دو تا.
 *  ۳. **بازپس‌گیریِ سطرِ گیرکرده** — tick مرده نباید پیام را برای همیشه
 *     متوقف کند.
 *  ۴. **تلاشِ بی‌نهایت ممنوع** — بعد از سقف، `failed` و **دیده‌شدنی**.
 */
class OutboxDrainTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // enqueue
    // ------------------------------------------------------------------

    public function test_email_lands_in_the_queue_not_in_the_mailer(): void
    {
        Mail::fake();

        $id = Outbox::email('a@example.com', 'welcome:1', 'خوش آمدید', 'متن');

        $this->assertNotNull($id);
        // `MailFake::sent()` آرگومان اجباری می‌خواهد ⇒ `assertNothingSent` ابزار
        // درستِ «هیچ‌چیز ارسال نشد» است.
        Mail::assertNothingSent();

        $row = DB::table('notification_deliveries')->find($id);

        $this->assertSame('email', $row->channel);
        $this->assertSame('pending', $row->status);
        $this->assertSame(0, (int) $row->attempts);
        $this->assertSame('welcome:1', $row->dedupe_key);
    }

    /** ⭐ idempotency: همان `dedupe_key` ⇒ یک سطر. */
    public function test_the_same_dedupe_key_never_creates_a_second_row(): void
    {
        $first = Outbox::email('a@example.com', 'welcome:1', 's', 'b');
        $second = Outbox::email('a@example.com', 'welcome:1', 's', 'b');
        $third = Outbox::email('b@example.com', 'welcome:2', 's', 'b');

        $this->assertNotNull($first);
        $this->assertNull($second, 'enqueue دوباره باید null بدهد، نه سطر تازه.');
        $this->assertNotNull($third);
        $this->assertSame(2, DB::table('notification_deliveries')->count());
    }

    // ------------------------------------------------------------------
    // ⭐ claim
    // ------------------------------------------------------------------

    /**
     * ⭐⭐ ادعا **دوبار** پشت‌سرهم صدا زده می‌شود، دقیقاً مثل دو tick که پشت‌سرهم
     * آمده‌اند. هیچ‌کدام نباید سطرِ دیگری را بردارند.
     *
     * اگر claim اتمیک نبود، tick دوم همان سطر را می‌دید و کاربر **دو ایمیل**
     * می‌گرفت — و هیچ‌جا هم معلوم نمی‌شد، چون هر دو `sent` می‌شدند.
     */
    public function test_claiming_twice_never_hands_the_same_row_to_two_ticks(): void
    {
        Outbox::email('a@example.com', 'k1', 's', 'b');
        Outbox::email('b@example.com', 'k2', 's', 'b');
        Outbox::email('c@example.com', 'k3', 's', 'b');

        $first = collect(Outbox::claim(10))->pluck('id')->sort()->values()->all();
        $second = collect(Outbox::claim(10))->pluck('id')->all();

        $this->assertCount(3, $first, 'tick اول باید هر سه را بگیرد.');
        $this->assertSame([], $second, 'tick دوم نباید چیزی بگیرد.');
    }

    public function test_claiming_respects_the_batch_size(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            Outbox::email('a@example.com', "k{$i}", 's', 'b');
        }

        $this->assertCount(2, Outbox::claim(2));
        $this->assertCount(2, Outbox::claim(2));
        $this->assertCount(1, Outbox::claim(2));
        $this->assertCount(0, Outbox::claim(2));
    }

    /** `available_at` آینده نباید ادعا شود — retry هنوز نرسیده. */
    public function test_a_row_that_is_not_due_yet_is_not_claimed(): void
    {
        $id = Outbox::email('a@example.com', 'k1', 's', 'b');

        DB::table('notification_deliveries')->where('id', $id)->update([
            'available_at' => now()->addHour(),
        ]);

        $this->assertCount(0, Outbox::claim(10));
    }

    /** ⭐ `attempts` در لحظهٔ ادعا زیاد می‌شود، نه در انتها. */
    public function test_claiming_counts_the_attempt_up_front(): void
    {
        $id = Outbox::email('a@example.com', 'k1', 's', 'b');

        $row = Outbox::claim(1)[0];

        $this->assertSame(1, (int) $row->attempts);
        $this->assertSame(1, (int) DB::table('notification_deliveries')->find($id)->attempts);
        $this->assertNotNull($row->claim_token);
        $this->assertNotNull($row->claimed_at);
    }

    // ------------------------------------------------------------------
    // deliver
    // ------------------------------------------------------------------

    public function test_a_claimed_row_is_sent_and_marked_sent(): void
    {
        Mail::fake();

        Outbox::email('a@example.com', 'k1', 'سلام', 'متن');

        $this->artisan('outbox:drain')->assertSuccessful();

        Mail::assertSent(OutboundEmail::class);

        $row = DB::table('notification_deliveries')->sole();

        $this->assertSame('sent', $row->status);
        $this->assertNotNull($row->sent_at);
        $this->assertNull($row->claim_token);
    }

    /**
     * ⭐⭐ کانالِ بدون provider باید `failed` شود، **نه** `sent`.
     *
     * این مهم‌ترین ادعای این تست است: SMS در این فاز provider ندارد، و اگر
     * بی‌سروصدا `sent` می‌شد، داشبورد می‌گفت «ارسال شد» و کاربر هرگز پیام را
     * نمی‌گرفت. سکوت در صفِ تحویل، بدترین شکلِ سکوت است.
     */
    public function test_a_channel_without_a_provider_fails_visibly(): void
    {
        $id = Outbox::sms('09120000000', 'k1', 'کد شما ۱۲۳۴ است');

        $this->artisan('outbox:drain')->assertSuccessful();

        $row = DB::table('notification_deliveries')->find($id);

        $this->assertSame('pending', $row->status, 'تلاش اول باید قابلِ retry بماند.');
        $this->assertSame(1, (int) $row->attempts);
        $this->assertNotNull($row->last_error);
        $this->assertGreaterThan(now()->timestamp, $row->available_at, 'backoff باید عقب بیندازد.');
    }

    public function test_retry_uses_growing_backoff(): void
    {
        config(['outbox.retry_base_seconds' => 60, 'outbox.retry_cap_seconds' => 3600]);

        $this->assertSame(60, Outbox::backoffSeconds(1));
        $this->assertSame(120, Outbox::backoffSeconds(2));
        $this->assertSame(240, Outbox::backoffSeconds(3));
        $this->assertSame(3600, Outbox::backoffSeconds(20), 'سقف لازم است.');
    }

    // ------------------------------------------------------------------
    // ⭐ بازپس‌گیریِ گیرکرده
    // ------------------------------------------------------------------

    public function test_a_stale_claim_goes_back_to_pending(): void
    {
        $id = Outbox::email('a@example.com', 'k1', 's', 'b');

        Outbox::claim(1);

        $this->assertSame('processing', DB::table('notification_deliveries')->find($id)->status);

        // tick مرده: `claimed_at` قدیمی، ولی هنوز تلاش‌هایش ن تمام نشده.
        DB::table('notification_deliveries')->where('id', $id)->update([
            'claimed_at' => now()->subHours(2),
        ]);

        $this->assertSame(1, Outbox::reapStale(600, 3));
        $this->assertSame('pending', DB::table('notification_deliveries')->find($id)->status);
    }

    /** سطری که تلاش‌هایش تمام شده **برنمی‌گردد** — وگرنه برای همیشه در چرخه می‌ماند. */
    public function test_an_exhausted_row_is_not_reaped(): void
    {
        $id = Outbox::email('a@example.com', 'k1', 's', 'b');

        Outbox::claim(1);
        DB::table('notification_deliveries')->where('id', $id)->update([
            'claimed_at' => now()->subHours(2),
            'attempts' => 3,
        ]);

        $this->assertSame(0, Outbox::reapStale(600, 3));
    }

    /**
     * ⭐ `claim_token` نوشتنِ نتیجه را محدود می‌کند. بدون این، tickِ کندِ اول
     * می‌تواند نتیجهٔ کارِ tickِ دوم را پاک کند.
     */
    public function test_a_stale_tick_cannot_overwrite_a_reclaimed_row(): void
    {
        $id = Outbox::email('a@example.com', 'k1', 's', 'b');

        $stale = Outbox::claim(1)[0];

        DB::table('notification_deliveries')->where('id', $id)->update([
            'status' => 'processing',
            'claim_token' => 'someone-else',
            'claimed_at' => now(),
        ]);

        Outbox::deliver($stale);

        $row = DB::table('notification_deliveries')->find($id);

        $this->assertSame('processing', $row->status, 'tick قدیمی نباید سطرِ بازپس‌گرفته را بندهد.');
        $this->assertSame('someone-else', $row->claim_token);
    }

    // ------------------------------------------------------------------
    // prune
    // ------------------------------------------------------------------

    public function test_prune_removes_only_old_finished_rows(): void
    {
        $sentId = Outbox::email('a@example.com', 'k1', 's', 'b');
        Outbox::deliver(Outbox::claim(1)[0]);

        $pendingId = Outbox::email('a@example.com', 'k2', 's', 'b');

        $this->assertSame('sent', DB::table('notification_deliveries')->find($sentId)->status);

        foreach ([$sentId, $pendingId] as $id) {
            DB::table('notification_deliveries')->where('id', $id)->update([
                'created_at' => now()->subDays(90),
            ]);
        }

        $this->assertSame(1, Outbox::prune(30), 'فقط سطرِ تمام‌شده پاک می‌شود.');

        $this->assertNull(DB::table('notification_deliveries')->find($sentId));
        $this->assertNotNull(
            DB::table('notification_deliveries')->find($pendingId),
            'سطرِ در جریان نباید پاک شود — یعنی پیامی که هرگز نرسیده.',
        );
    }
}
