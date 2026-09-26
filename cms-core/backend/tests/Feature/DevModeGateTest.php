<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Plugins\DevMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * B3 — دروازهٔ سمت سرور حالت توسعه‌دهنده.
 *
 * هدف این تست‌ها یک چیز است: **پیش‌فرض بسته بماند.** حالت توسعه‌دهنده
 * نباید هیچ راهی برای دور زدن بررسی امضا باز کند مگر وقتی واقعاً باز است،
 * توکن معتبر دارد و منقضی نشده.
 */
class DevModeGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function devMode(): DevMode
    {
        return app(DevMode::class);
    }

    public function test_is_off_by_default_when_nothing_is_configured(): void
    {
        // نبودِ تنظیم نباید به‌طور تصادفی حالت توسعه‌دهنده را باز کند.
        $this->assertFalse($this->devMode()->isActive());
        $this->assertFalse($this->devMode()->allows('any-token'));
    }

    public function test_enabled_without_expiry_is_treated_as_off(): void
    {
        // دادهٔ ناقص/دستکاری‌شده نباید دری را باز کند.
        Setting::set(DevMode::GROUP, DevMode::KEY, ['enabled' => true]);
        $this->devMode()->forget();

        $this->assertFalse($this->devMode()->isActive());
    }

    public function test_enable_opens_the_gate_for_eight_hours(): void
    {
        $expires = $this->devMode()->enable('tok-good');

        $this->assertTrue($this->devMode()->isActive());
        $this->assertEqualsWithDelta(
            now()->addHours(8)->timestamp,
            $expires->timestamp,
            5
        );
    }

    public function test_expired_state_is_not_active_even_when_enabled(): void
    {
        Setting::set(DevMode::GROUP, DevMode::KEY, [
            'enabled' => true,
            'expires_at' => now()->subMinute()->toIso8601String(),
            'unlock_token' => 'tok-good',
        ]);
        $this->devMode()->forget();

        $this->assertFalse($this->devMode()->isActive());
        $this->assertFalse($this->devMode()->allows('tok-good'));
    }

    public function test_unparseable_expiry_is_not_active(): void
    {
        Setting::set(DevMode::GROUP, DevMode::KEY, [
            'enabled' => true,
            'expires_at' => 'not-a-date',
            'unlock_token' => 'tok-good',
        ]);
        $this->devMode()->forget();

        $this->assertFalse($this->devMode()->isActive());
    }

    public function test_active_state_still_requires_the_exact_token(): void
    {
        $this->devMode()->enable('tok-good');

        $this->assertTrue($this->devMode()->allows('tok-good'));
        $this->assertFalse($this->devMode()->allows('tok-wrong'));
        $this->assertFalse($this->devMode()->allows(''));
        $this->assertFalse($this->devMode()->allows(null));
    }

    public function test_disable_closes_the_gate_and_drops_the_token(): void
    {
        $this->devMode()->enable('tok-good');
        $this->assertTrue($this->devMode()->allows('tok-good'));

        $this->devMode()->disable();

        $this->assertFalse($this->devMode()->isActive());
        $this->assertFalse($this->devMode()->allows('tok-good'));
    }

    public function test_re_enabling_issues_a_different_token(): void
    {
        $this->devMode()->enable('tok-first');
        $this->devMode()->enable('tok-second');

        $this->assertFalse($this->devMode()->allows('tok-first'));
        $this->assertTrue($this->devMode()->allows('tok-second'));
    }

    public function test_state_is_cached_and_forget_clears_it(): void
    {
        $this->devMode()->enable('tok-good');
        $this->assertTrue($this->devMode()->isActive());

        // کش کوتاه‌عمر است، ولی `forget()` باید فوراً اثر کند.
        $this->devMode()->forget();
        $this->assertTrue($this->devMode()->isActive(), 'خواندن دوباره باید همان وضعیت ذخیره‌شده را بدهد.');

        $this->devMode()->disable();
        $this->devMode()->forget();
        $this->assertFalse($this->devMode()->isActive());
    }
}
