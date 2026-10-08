<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Layouts\LinkItems;
use App\Validation\SafeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * F0.2 — XSS ویجت CTA هدر/فوتر + یکسان‌سازی allowlist نشانی.
 *
 * ریشهٔ باگ: `LayoutController::update()` فقط `nav` و `links` را اعتبارسنجی
 * می‌کرد، پس `cta` بدون هیچ بررسی‌ای ذخیره می‌شد و `Chrome.tsx` هم آن را
 * بدون `safeHref` رندر می‌کرد ⇒ `javascript:` در هدر *همهٔ صفحات* سایت زنده بود.
 *
 * راه‌حل: اعتبارسنجی **از روی رجیستری** (`config/widgets.php`)، نه با
 * `if ($type === 'cta')` — تا ویجت تازهٔ هسته و ویجت اعلام‌شدهٔ پلاگین/قالب
 * خودکار پوشش داده شوند.
 */
class WidgetHrefGuardTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مدیر تست',
            'email' => 'u'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
    }

    private function saveLayout(string $area, array $widgets)
    {
        Http::fake();

        return $this->actingAs($this->user(), 'sanctum')
            ->putJson("/api/v1/admin/layouts/{$area}", ['widgets' => $widgets]);
    }

    // ── تزریق در دو نوع ویجت مختلف ───────────────────────────────────────

    /** ویجت `cta` در هدر — همان باگ اصلی. */
    public function test_cta_widget_rejects_javascript_href(): void
    {
        $res = $this->saveLayout('header', [
            ['type' => 'cta', 'settings' => ['label' => 'خرید', 'href' => 'javascript:alert(document.cookie)']],
        ]);

        $res->assertStatus(422);
        $this->assertDatabaseMissing('settings', ['group' => 'layout']);
    }

    /** نوع دوم: `nav` در هدر — همان allowlist، مسیر متفاوت. */
    public function test_nav_widget_rejects_javascript_href(): void
    {
        $res = $this->saveLayout('header', [
            ['type' => 'nav', 'settings' => ['links' => [
                ['kind' => 'custom', 'label' => 'خروجی', 'href' => 'javascript:alert(1)'],
            ]]],
        ]);

        $res->assertStatus(422);
        $this->assertDatabaseMissing('settings', ['group' => 'layout']);
    }

    /** نوع سوم: `links` در فوتر. */
    public function test_footer_links_widget_rejects_javascript_href(): void
    {
        $res = $this->saveLayout('footer', [
            ['type' => 'links', 'settings' => ['heading' => 'پیوندها', 'links' => [
                ['kind' => 'custom', 'label' => 'بد', 'href' => 'javascript:alert(1)'],
            ]]],
        ]);

        $res->assertStatus(422);
        $this->assertDatabaseMissing('settings', ['group' => 'layout']);
    }

    // ── بردارهای دور زدن ─────────────────────────────────────────────────

    public function test_cta_rejects_protocol_relative_href(): void
    {
        $res = $this->saveLayout('header', [
            ['type' => 'cta', 'settings' => ['label' => 'بیرون', 'href' => '//evil.com']],
        ]);

        $res->assertStatus(422);
    }

    /** `\` بعد از `/` در مرورگر مثل `/` تفسیر می‌شود ⇒ open-redirect. */
    public function test_cta_rejects_backslash_href(): void
    {
        $res = $this->saveLayout('header', [
            ['type' => 'cta', 'settings' => ['label' => 'بیرون', 'href' => '/\evil.com']],
        ]);

        $res->assertStatus(422);
    }

    /** مرورگر TAB/LF را حذف می‌کند ⇒ `java\tscript:` دور زدن الگوی ساده است. */
    public function test_cta_rejects_control_char_obfuscation(): void
    {
        $res = $this->saveLayout('header', [
            ['type' => 'cta', 'settings' => ['label' => 'مخفی', 'href' => "java\tscript:alert(1)"]],
        ]);

        $res->assertStatus(422);
    }

    public function test_cta_rejects_data_uri(): void
    {
        $res = $this->saveLayout('header', [
            ['type' => 'cta', 'settings' => ['label' => 'داده', 'href' => 'data:text/html,<script>alert(1)</script>']],
        ]);

        $res->assertStatus(422);
    }

    // ── ضدّ-رگرسیون: نشانی‌های سالم باید بگذرند ──────────────────────────

    public function test_cta_accepts_valid_hrefs(): void
    {
        foreach (['/contact', '/', '#top', '?page=2', 'https://example.ir/buy', 'mailto:s@example.ir', 'tel:+989121234567'] as $href) {
            $res = $this->saveLayout('header', [
                ['type' => 'cta', 'settings' => ['label' => 'معتبر', 'href' => $href]],
            ]);

            $res->assertStatus(200, "نشانی معتبر «{$href}» باید پذیرفته شود.");
        }
    }

    public function test_cta_without_href_is_allowed(): void
    {
        // نبودن فیلد یعنی «تنظیم نشده»، نه «معتبر نیست».
        $res = $this->saveLayout('header', [
            ['type' => 'cta', 'settings' => ['label' => 'بدون نشانی']],
        ]);

        $res->assertStatus(200);
    }

    // ── اجرای مستقیم allowlist ──────────────────────────────────────────

    public function test_safe_url_allowlist_matrix(): void
    {
        $allowed = ['/about', '/', '#top', '?q=1', 'https://x.ir/p', 'http://x.ir', 'mailto:a@b.ir', 'tel:+989121234567'];
        $denied = [
            'javascript:alert(1)', 'JaVaScRiPt:alert(1)', "java\tscript:alert(1)",
            'data:text/html,x', 'vbscript:m', 'file:///etc/passwd',
            '//evil.com', '/\evil.com', '', '   ',
        ];

        foreach ($allowed as $v) {
            $this->assertTrue(SafeUrl::isAllowedHref($v), "«{$v}» باید مجاز باشد.");
        }
        foreach ($denied as $v) {
            $this->assertFalse(SafeUrl::isAllowedHref($v), "«{$v}» باید رد شود.");
        }
    }

    public function test_safe_src_is_stricter_than_href(): void
    {
        $this->assertTrue(SafeUrl::isAllowedSrc('/i.png'));
        $this->assertTrue(SafeUrl::isAllowedSrc('https://x.ir/i.png'));

        // این‌ها برای href مجازند ولی برای src نه.
        foreach (['#a', 'mailto:a@b.ir', 'data:image/svg+xml,<svg/>'] as $v) {
            $this->assertFalse(SafeUrl::isAllowedSrc($v), "«{$v}» نباید برای src مجاز باشد.");
        }
    }

    /**
     * `LinkItems::isValidHref` قبلاً `/` را بدون lookahead می‌پذیرفت ⇒
     * `//evil.com` را قبول می‌کرد. این تست ثابت می‌کند هر دو به یک منبع حقیقت
     * واگذار شده‌اند و ناهمگونی بین فرانت/بک‌اند برنمی‌گردد.
     */
    public function test_link_items_delegates_to_safe_url(): void
    {
        $this->assertFalse(LinkItems::isValidHref('//evil.com'));
        $this->assertFalse(LinkItems::isValidHref('/\evil.com'));
        $this->assertTrue(LinkItems::isValidHref('/about'));
    }
}
