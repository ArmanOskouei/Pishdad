<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Settings\CachedSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * وضعیت عمومی سایت (تسک ۴.۳) — سبک، بدون احراز هویت.
 *
 * ⚠️ این نصب هیچ لایهٔ صورت‌حسابی ندارد، پس هیچ حالتی جز «فعال» وجود ندارد.
 * نقطهٔ پایانی و شکلِ پاسخش عمداً نگه داشته شده‌اند چون لایهٔ کش و CDN روی
 * همان‌ها حساب کرده‌اند؛ ولی منطقِ وضعیت ساده شده و دیگر به هیچ منبعِ
 * بیرونی وابسته نیست.
 */
class SiteStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // متنِ وضعیت کش می‌شود؛ بین تست‌ها باید پاک شود وگرنه عنوانِ یک تست
        // به تستِ بعدی نشت می‌کند.
        CachedSettings::forget('site_status', 'site-default');
        CachedSettings::forget('site', 'global');
    }

    public function test_public_status_is_active_without_auth(): void
    {
        $res = $this->getJson('/api/v1/site/status');

        $res->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertNotEmpty($res->json('data.message'));
        $this->assertStringContainsString('max-age=60', $res->headers->get('Cache-Control'));
    }

    /**
     * شناسهٔ ناشناس هم ۲۰۰ می‌گیرد.
     *
     * پیش‌تر این مسیر برای `site_id` ناشناس ۴۰۴ می‌داد، چون از جدولِ مشتریان
     * می‌خواند. آن جدول دیگر وجود ندارد و وضعیت به مشتری گره نخورده، پس
     * «سایت پیدا نشد» هم معنایی ندارد.
     */
    public function test_any_site_id_resolves_to_active(): void
    {
        $this->getJson('/api/v1/site/status?site_id=nope')
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_brand_message_uses_the_site_title(): void
    {
        Setting::set('site', 'global', ['title' => 'فروشگاه آرمان']);

        $res = $this->getJson('/api/v1/site/status')->assertOk();

        $this->assertStringContainsString('فروشگاه آرمان', (string) $res->json('data.message'));
    }

    /** بدون عنوانِ سایت، پیام باید جملهٔ کاملی بماند — نه « فعال است.» */
    public function test_brand_message_falls_back_when_there_is_no_title(): void
    {
        Setting::set('site', 'global', []);

        $message = (string) $this->getJson('/api/v1/site/status')->assertOk()->json('data.message');

        $this->assertStringContainsString('وب‌سایت ما', $message);
    }
}
