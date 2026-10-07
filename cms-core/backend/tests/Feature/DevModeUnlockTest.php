<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Plugins\DevMode;
use App\Services\Plugins\DevModeTaps;
use App\Services\Plugins\DevModeUnlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * K4.1 تا K4.5 — دروازهٔ حالت توسعه‌دهنده.
 *
 * این تست‌ها عمداً روی «چه چیزی رد می‌شود» متمرکزند، نه روی مسیر موفق. چون
 * دروازه‌ای که فقط مسیر موفقش تست شود، دروازه نیست.
 */
class DevModeUnlockTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
        $this->user = User::factory()->create(['password' => bcrypt('correct-horse')]);
    }

    private function unlock(): DevModeUnlock
    {
        return app(DevModeUnlock::class);
    }

    /**
     * پنج کلیک معتبر.
     *
     * فاصلهٔ هر کلیک باید هم از `MIN_GAP_SECONDS` بیشتر باشد (وگرنه کلیک‌اسپم رد
     * می‌شود) و کل زنجیره از `WINDOW_SECONDS` نگذرد (وگرنه زنجیره می‌شکند). با
     * ۳ ثانیه فاصله، پنج کلیک دقیقاً ۱۲ ثانیه می‌شود که از پنجره بیرون است — پس
     * فاصله را از روی هر دو قید حساب می‌کنیم نه از روی یک عدد دلخواه.
     */
    private function tapFiveTimes(?DevModeTaps $taps = null, ?User $user = null): void
    {
        $user ??= $this->user;
        $taps ??= app(DevModeTaps::class);
        $gap = (DevModeTaps::WINDOW_SECONDS / DevModeTaps::REQUIRED) + 0.4;
        $base = now();
        foreach (range(1, DevModeTaps::REQUIRED) as $i) {
            Carbon::setTestNow($base->copy()->addSeconds($gap * $i));
            $taps->tap($user, '203.0.113.9', 'POST');
        }
        Carbon::setTestNow();
    }

    /**
     * ردپای ممیزی حالا **کجا** می‌نشیند؟
     *
     * `DevModeAudit` دیگر مدلِ هیچ افزونه‌ای را صدا نمی‌زند؛ فقط
     * `AuditTrail::record()` را می‌زند، و آن به هر `AuditSink` ثبت‌شده و
     * **همیشه** به `Log::info('audit.event', …)` می‌نویسد. روی نصبِ بدون
     * افزونهٔ ممیزی، لاگ تنها مقصد است — پس ادعای «باز کردن ثبت شد» باید
     * همان‌جا سنجیده شود، نه روی یک جدولِ فرضی.
     *
     * اگر روزی مقصدِ دیگری برگردد، این helper چیزی برنمی‌گرداند و تست‌های
     * پایین قرمز می‌شوند — دقیقاً همان کاری که `AuditSink` برای همین
     * ساخته شده: هسته **می‌نویسد**، هرکس خواست **می‌خواند**.
     *
     * @return array{user_id: int|null, action: string, meta: array, ip: string|null, at: string}|null
     */
    private function auditEntry(string $action): ?array
    {
        $found = null;

        // ماچر عمداً همهٔ `info`ها را می‌بیند و فقط رویدادِ خواسته‌شده را برمی‌دارد،
        // پس لاگ‌های دیگرِ همان مسیر مزاحمش نمی‌شوند. خودِ فراخوانی هم یک
        // assertion است: اگر هیچ ردیفی ننشسته باشد، Mockery همین‌جا می‌ترکد.
        Log::shouldHaveReceived('info')->withArgs(
            function ($message, $context = []) use ($action, &$found): bool {
                if ($message !== 'audit.event' || ! is_array($context)) {
                    return false;
                }

                if (($context['action'] ?? null) !== $action) {
                    return false;
                }

                $found ??= $context;

                return true;
            }
        );

        return $found;
    }

    // ------------------------------------------------------------------ K4.1

    public function test_taps_accumulate_per_user_and_reach_ready(): void
    {
        $taps = app(DevModeTaps::class);
        $state = $taps->state($this->user);
        $this->assertSame(0, $state['count']);
        $this->assertFalse($state['ready']);

        $this->tapFiveTimes($taps);

        $state = $taps->state($this->user);
        $this->assertSame(DevModeTaps::REQUIRED, $state['count']);
        $this->assertTrue($state['ready'], 'پنج کلیک معتبر باید آماده باشد.');
    }

    public function test_taps_do_not_leak_between_users(): void
    {
        $taps = app(DevModeTaps::class);
        $other = User::factory()->create();

        $base = now();
        foreach (range(1, 4) as $i) {
            Carbon::setTestNow($base->copy()->addSeconds(3 * $i));
            $taps->tap($this->user, '203.0.113.9', 'POST');
        }
        Carbon::setTestNow();

        $this->assertSame(4, $taps->state($this->user)['count']);
        $this->assertSame(
            0,
            $taps->state($other)['count'],
            'شمارنده باید per-user باشد — وگرنه یک نفر برای همه پنج کلیک می‌کند.'
        );
    }

    // ------------------------------------------------------------------ K4.2

    public function test_taps_too_fast_are_rejected(): void
    {
        $unlock = $this->unlock();
        Carbon::setTestNow(now());
        $unlock->tap($this->user, '203.0.113.9', 'POST');

        try {
            $unlock->tap($this->user, '203.0.113.9', 'POST');
            $this->fail('کلیک دوم بدون فاصلهٔ لازم باید رد شود.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('taps', $e->errors());
            $this->assertStringContainsString('سریع', $e->errors()['taps'][0]);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(
            1,
            app(DevModeTaps::class)->state($this->user)['count'],
            'کلیک ردشده نباید شمرده شود.'
        );
    }

    public function test_a_non_post_request_never_counts(): void
    {
        $taps = app(DevModeTaps::class);

        $this->expectException(\RuntimeException::class);
        $taps->tap($this->user, '203.0.113.9', 'GET');
    }

    public function test_window_expiry_resets_the_chain(): void
    {
        $taps = app(DevModeTaps::class);
        $base = now();
        foreach (range(1, 3) as $i) {
            Carbon::setTestNow($base->copy()->addSeconds(3 * $i));
            $taps->tap($this->user, '203.0.113.9', 'POST');
        }
        $this->assertSame(3, $taps->state($this->user)['count']);

        // بیشتر از پنجرهٔ ۱۰ ثانیه از اولین کلیک گذشته ⇒ زنجیره باید بشکند.
        Carbon::setTestNow($base->copy()->addSeconds(DevModeTaps::WINDOW_SECONDS + 5));

        try {
            $taps->tap($this->user, '203.0.113.9', 'POST');
            $this->fail('کلیک بعد از انقضای پنجره باید رد شود.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('مهلت', $e->getMessage());
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(1, $taps->state($this->user)['count'], 'زنجیره باید از صفر شروع شود.');
    }

    public function test_idle_counter_does_not_accumulate_forever(): void
    {
        $taps = app(DevModeTaps::class);
        $base = now();
        foreach (range(1, 4) as $i) {
            Carbon::setTestNow($base->copy()->addSeconds(3 * $i));
            $taps->tap($this->user, '203.0.113.9', 'POST');
        }
        $this->assertSame(4, $taps->state($this->user)['count']);

        // بعد از سکوتِ بیش از ۵ دقیقه، شمارنده رهاشده نباید به سمت پنج برود.
        Carbon::setTestNow($base->copy()->addSeconds(DevModeTaps::IDLE_RESET_SECONDS + 60));
        $this->assertSame(0, $taps->state($this->user)['count']);
        Carbon::setTestNow();
    }

    public function test_reset_clears_the_counter(): void
    {
        $taps = app(DevModeTaps::class);
        $this->tapFiveTimes($taps);
        $this->assertTrue($taps->state($this->user)['ready']);

        $taps->reset($this->user);

        $this->assertSame(0, $taps->state($this->user)['count']);
    }

    // ------------------------------------------------------------------ K4.3

    public function test_unlock_requires_a_complete_gesture(): void
    {
        $this->expectException(ValidationException::class);
        $this->unlock()->unlock($this->user, '203.0.113.9', 'correct-horse', 'POST');
    }

    public function test_unlock_requires_the_actual_password(): void
    {
        $taps = app(DevModeTaps::class);
        $this->tapFiveTimes($taps);

        try {
            $this->unlock()->unlock($this->user, '203.0.113.9', 'wrong-password', 'POST');
            $this->fail('رمز غلط باید رد شود.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
        }

        $this->assertFalse(app(DevMode::class)->isActive(), 'حالت توسعه‌دهنده نباید باز شده باشد.');
    }

    public function test_unlock_with_gesture_and_password_succeeds_and_issues_a_token(): void
    {
        $taps = app(DevModeTaps::class);
        $this->tapFiveTimes($taps);

        $result = $this->unlock()->unlock($this->user, '203.0.113.9', 'correct-horse', 'POST');

        $this->assertSame(64, strlen($result['unlock_token']));
        $this->assertTrue(app(DevMode::class)->isActive());
        $this->assertTrue(app(DevMode::class)->allows($result['unlock_token']));
    }

    public function test_the_unlock_token_is_single_use_per_gesture(): void
    {
        $taps = app(DevModeTaps::class);
        $this->tapFiveTimes($taps);
        $first = $this->unlock()->unlock($this->user, '203.0.113.9', 'correct-horse', 'POST');

        // ژستور مصرف شده، پس تلاش دوم باید رد شود حتی با همان رمز.
        try {
            $this->unlock()->unlock($this->user, '203.0.113.9', 'correct-horse', 'POST');
            $this->fail('تلاش دوم باید رد شود — ژستور یک‌بارمصرف است.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('taps', $e->errors());
        }

        $this->assertTrue(app(DevMode::class)->allows($first['unlock_token']));
    }

    public function test_a_wrong_token_does_not_open_the_gate(): void
    {
        $taps = app(DevModeTaps::class);
        $this->tapFiveTimes($taps);
        $this->unlock()->unlock($this->user, '203.0.113.9', 'correct-horse', 'POST');

        $this->assertFalse(app(DevMode::class)->allows(str_repeat('a', 64)));
        $this->assertFalse(app(DevMode::class)->allows(null));
        $this->assertFalse(app(DevMode::class)->allows(''));
    }

    public function test_locking_closes_the_gate_and_clears_the_gesture(): void
    {
        $taps = app(DevModeTaps::class);
        $this->tapFiveTimes($taps);
        $result = $this->unlock()->unlock($this->user, '203.0.113.9', 'correct-horse', 'POST');

        $this->unlock()->lock($this->user, '203.0.113.9');

        $this->assertFalse(app(DevMode::class)->isActive());
        $this->assertFalse(app(DevMode::class)->allows($result['unlock_token']));
        $this->assertSame(0, $taps->state($this->user)['count']);
    }

    // ------------------------------------------------------------------ K4.5

    public function test_a_successful_unlock_is_recorded(): void
    {
        $taps = app(DevModeTaps::class);
        $this->tapFiveTimes($taps);
        Log::spy();

        $this->unlock()->unlock($this->user, '203.0.113.9', 'correct-horse', 'POST');

        $entry = $this->auditEntry('devmode.unlocked');
        $this->assertNotNull($entry, 'باز کردن موفق باید ثبت شود.');
        $this->assertSame($this->user->id, $entry['user_id']);
        $this->assertSame('203.0.113.9', $entry['ip']);
    }

    public function test_a_failed_unlock_is_recorded_too(): void
    {
        $taps = app(DevModeTaps::class);
        $this->tapFiveTimes($taps);
        Log::spy();

        try {
            $this->unlock()->unlock($this->user, '203.0.113.9', 'wrong-password', 'POST');
        } catch (ValidationException) {
            // انتظار می‌رود.
        }

        $entry = $this->auditEntry('devmode.unlock_denied');
        $this->assertNotNull(
            $entry,
            'تلاش ناموفق هم باید ثبت شود — وگرنه بعد از یک حملهٔ ناموفق هیچ ردی نمی‌ماند.'
        );
        $this->assertSame('bad_password', $entry['meta']['reason'] ?? null);
    }

    public function test_a_denied_gesture_is_recorded_with_its_reason(): void
    {
        Log::spy();

        try {
            $this->unlock()->unlock($this->user, '203.0.113.9', 'correct-horse', 'POST');
        } catch (ValidationException) {
            // انتظار می‌رود.
        }

        $entry = $this->auditEntry('devmode.unlock_denied');
        $this->assertNotNull($entry);
        $this->assertSame('gesture_incomplete', $entry['meta']['reason'] ?? null);
    }

    public function test_locking_is_recorded(): void
    {
        $taps = app(DevModeTaps::class);
        $this->tapFiveTimes($taps);
        Log::spy();

        $this->unlock()->unlock($this->user, '203.0.113.9', 'correct-horse', 'POST');
        $this->unlock()->lock($this->user, '203.0.113.9');

        $this->assertNotNull($this->auditEntry('devmode.locked'));
    }

    /**
     * توکن نباید در ردپا لو برود.
     *
     * `unlock_token` همان چیزی است که دروازهٔ آپلود را باز می‌کند. اگر در ردپا
     * بنشیند — چه در لاگ، چه در جدولِ افزونه‌ای که `AuditSink` وصل کرده — هر
     * کسی که به آن دسترسی دارد عملاً می‌تواند بستهٔ بدون امضا نصب کند.
     */
    public function test_the_unlock_token_never_reaches_the_audit_log(): void
    {
        $taps = app(DevModeTaps::class);
        $this->tapFiveTimes($taps);
        Log::spy();

        $result = $this->unlock()->unlock($this->user, '203.0.113.9', 'correct-horse', 'POST');

        $serialized = json_encode($entry = $this->auditEntry('devmode.unlocked'));

        // بدون این، یک ردپای خالی هم «توکن در آن نیست» و تست ساکت سبز می‌شد.
        $this->assertNotNull($entry, 'باز کردن موفق باید ثبت شود.');
        $this->assertStringNotContainsString(
            $result['unlock_token'],
            (string) $serialized,
            'توکن بازکننده نباید در ردپا ذخیره شود.'
        );
    }
}
