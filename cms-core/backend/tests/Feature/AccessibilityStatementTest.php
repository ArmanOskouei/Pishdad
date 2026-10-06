<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Admin\SiteSettingsController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * WF-M17 — بیانیهٔ دسترس‌پذیری تولیدشده.
 *
 * DoD: تنظیم `accessibility_statement` مثل `privacy_policy` (WF-M20) کار کند —
 * ذخیره/خواندن، طول‌سنجی، ردِ markup (چون صفحهٔ عمومی متنِ خام را رندر می‌کند)،
 * و در کروم عمومی افشا شود؛ وقتی تنظیمی نیست، پیش‌فرضِ غیرخالی برود که هم هدفِ
 * انطباق WCAG 2.2 AA را نام ببرد و هم راهِ تماس داشته باشد.
 */
class AccessibilityStatementTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مدیر تست',
            'email' => 'a'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
    }

    public function test_accessibility_statement_roundtrips_and_is_publicly_exposed(): void
    {
        $auth = $this->actingAs($this->user(), 'sanctum');

        $auth->putJson('/api/v1/admin/settings/site', [
            'title' => 'سایت دسترس‌پذیر',
            'accessibility_statement' => "بند اول.\n\nبند دوم.",
        ])->assertOk()->assertJsonPath('message', 'تنظیمات سایت ذخیره شد.');

        $auth->getJson('/api/v1/admin/settings/site')
            ->assertOk()
            ->assertJsonPath('data.accessibility_statement', "بند اول.\n\nبند دوم.");

        $this->getJson('/api/v1/site/chrome')
            ->assertOk()
            ->assertJsonPath('data.accessibility_statement', "بند اول.\n\nبند دوم.");
    }

    public function test_accessibility_statement_defaults_when_unset(): void
    {
        $this->getJson('/api/v1/site/chrome')
            ->assertOk()
            ->assertJsonPath('data.accessibility_statement', SiteSettingsController::DEFAULT_ACCESSIBILITY_STATEMENT);
    }

    /** پیش‌فرض باید «هدفِ انطباق» و «راه تماس» را هر دو نام ببرد — وگرنه بیانیه بی‌فایده است. */
    public function test_default_statement_declares_wcag_goal_and_contact_channel(): void
    {
        $res = $this->getJson('/api/v1/site/chrome')->assertOk();
        $text = (string) $res->json('data.accessibility_statement');

        $this->assertNotSame('', trim($text));
        $this->assertStringContainsString('WCAG 2.2', $text);
        $this->assertStringContainsString('AA', $text);
        $this->assertStringContainsString('تماس', $text);
        // پیش‌فرض هم باید متنِ خام باشد (بدون تگ) تا رندرِ متنیِ صفحه امن بماند.
        $this->assertDoesNotMatchRegularExpression('/<[a-z!\/]/i', $text);
    }

    public function test_accessibility_statement_rejects_overlong_input(): void
    {
        $res = $this->actingAs($this->user(), 'sanctum')->putJson('/api/v1/admin/settings/site', [
            'title' => 'تست',
            'accessibility_statement' => str_repeat('ا', 20001),
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['accessibility_statement']);
    }

    /** صفحهٔ عمومی متن را خام رندر می‌کند ⇒ تگ/اسکریپت نباید ذخیره شود. */
    public function test_accessibility_statement_rejects_markup_and_script(): void
    {
        $auth = $this->actingAs($this->user(), 'sanctum');

        $script = $auth->putJson('/api/v1/admin/settings/site', [
            'title' => 'تست',
            'accessibility_statement' => '<script>alert(1)</script>',
        ]);
        $script->assertStatus(422);
        $script->assertJsonValidationErrors(['accessibility_statement']);

        $tag = $auth->putJson('/api/v1/admin/settings/site', [
            'title' => 'تست',
            'accessibility_statement' => 'انطباق با <b>WCAG</b> 2.2 AA داریم.',
        ]);
        $tag->assertStatus(422);
        $tag->assertJsonValidationErrors(['accessibility_statement']);

        // رد شد ⇒ چیزی ذخیره نشده و کروم عمومی همچنان پیش‌فرضِ امن را می‌دهد.
        $this->getJson('/api/v1/site/chrome')
            ->assertOk()
            ->assertJsonPath('data.accessibility_statement', SiteSettingsController::DEFAULT_ACCESSIBILITY_STATEMENT);
    }

    /** خالی‌کردنِ تنظیم ⇒ کروم عمومی به پیش‌فرض برمی‌گردد (مثل حریم خصوصی). */
    public function test_clearing_accessibility_statement_falls_back_to_default(): void
    {
        $auth = $this->actingAs($this->user(), 'sanctum');

        $auth->putJson('/api/v1/admin/settings/site', [
            'title' => 'تست',
            'accessibility_statement' => 'متن دلخواه.',
        ])->assertOk();

        $auth->putJson('/api/v1/admin/settings/site', [
            'title' => 'تست',
            'accessibility_statement' => null,
        ])->assertOk();

        $this->getJson('/api/v1/site/chrome')
            ->assertOk()
            ->assertJsonPath('data.accessibility_statement', SiteSettingsController::DEFAULT_ACCESSIBILITY_STATEMENT);
    }

    public function test_accessibility_statement_requires_authenticated_admin(): void
    {
        $this->putJson('/api/v1/admin/settings/site', [
            'title' => 'تست',
            'accessibility_statement' => 'دست‌کاری.',
        ])->assertStatus(401);
    }
}