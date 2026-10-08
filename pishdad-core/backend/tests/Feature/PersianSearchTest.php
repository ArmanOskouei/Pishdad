<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageRevision;
use App\Models\User;
use App\Search\PersianText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * E85 — جستجوی فارسی‌دوست: چندفرمی‌ها + تحملِ غلطِ املایی.
 *
 * دو مثالِ واقعیِ کاربر که باید سبز شوند:
 *  ۱. «ارمان» (ا) باید «آرمان» (آ) را پیدا کند — همزه‌ها به کرسیِ ساده.
 *  ۲. «غوانین» باید «قوانین» را پیدا کند — فاصلهٔ ویرایشی ۱، نه صفر.
 *
 * و یک منفی که هرگز نباید سبزِ دروغین شود:
 *  ۳. «کتاب» نباید «درخت» را پیدا کند — آستانهٔ فازی مهارشده است.
 */
class PersianSearchTest extends TestCase
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

    private function publish(User $owner, string $slug, string $title, array $blocks = []): Page
    {
        $page = Page::query()->create([
            'user_id' => $owner->id, 'title' => $title, 'slug' => $slug,
            'status' => 'draft', 'blocks' => $blocks, 'meta' => [],
        ]);
        $rev = PageRevision::query()->create([
            'page_id' => $page->id, 'version' => 1,
            'blocks' => $blocks, 'meta' => [], 'created_by' => $owner->id,
        ]);
        $page->forceFill([
            'status' => Page::STATUS_PUBLISHED,
            'published_revision_id' => $rev->id,
            'published_at' => now(),
        ])->save();

        return $page->fresh();
    }

    // ------------------------------------------------------------------
    // نرمال‌سازی
    // ------------------------------------------------------------------

    public function test_alef_forms_unify(): void
    {
        $this->assertSame('ارمان', PersianText::normalize('آرمان'));
        $this->assertSame('ارمان', PersianText::normalize('ارمان'));
        $this->assertSame('ارمان', PersianText::normalize('أرمان'));
        $this->assertSame('ارمان', PersianText::normalize('إرمان'));
    }

    public function test_hamza_forms_unify(): void
    {
        $this->assertSame('موسسه', PersianText::normalize('مؤسسه'));
        $this->assertSame('مسیله', PersianText::normalize('مسئله'));
    }

    public function test_diacritics_tatweel_and_digits_normalize(): void
    {
        // اعراب و تشدید می‌روند، کشیده می‌رود، ارقام لاتین می‌شوند.
        $this->assertSame('قوانین', PersianText::normalize('قَوانین'));
        $this->assertSame('قوانین', PersianText::normalize('قــوانین'));
        $this->assertSame('سال 1403', PersianText::normalize('سال ۱۴۰۳'));
        $this->assertSame('سال 1403', PersianText::normalize('سال ١٤٠٣'));
    }

    public function test_old_forms_still_normalize(): void
    {
        $this->assertSame('می شود', PersianText::normalize('می‌شود'));
        $this->assertSame('نمایندگی', PersianText::normalize('نماېندگی'));
        $this->assertSame('ه', PersianText::normalize('ة'));
    }

    // ------------------------------------------------------------------
    // فازیِ سمت PHP (providerها)
    // ------------------------------------------------------------------

    public function test_fuzzy_tolerates_a_typo_but_not_a_different_word(): void
    {
        $hay = PersianText::normalize('قوانین و مقررات سایت');

        $this->assertTrue(PersianText::fuzzyIncludes($hay, PersianText::normalize('قوانین')), 'تطابق دقیق');
        $this->assertTrue(PersianText::fuzzyIncludes($hay, PersianText::normalize('غوانین')), 'یک حرف اشتباه');
        $this->assertFalse(PersianText::fuzzyIncludes(PersianText::normalize('کتاب درخت'), 'کتابچه'), 'پیشوندِ طولانیِ نامرتبط');
        $this->assertFalse(PersianText::fuzzyIncludes($hay, PersianText::normalize('کتاب')), 'کلمهٔ کاملاً متفاوت');
        $this->assertFalse(PersianText::fuzzyIncludes($hay, ''), 'کوئری خالی هیچ‌وقت');
    }

    // ------------------------------------------------------------------
    // سرتاسری: مثال‌های واقعی کاربر
    // ------------------------------------------------------------------

    public function test_site_search_finds_arman_spelled_with_plain_alef(): void
    {
        $user = $this->user();
        $this->publish($user, 'arman-page', 'زندگینامه آرمان');

        $res = $this->getJson('/api/v1/site/search?q='.urlencode('ارمان'))->assertOk();
        $this->assertNotNull(
            collect($res->json('data'))->firstWhere('slug', 'arman-page'),
            '«ارمان» (ا) باید «آرمان» (آ) را پیدا کند.',
        );
    }

    public function test_site_search_tolerates_a_typo(): void
    {
        $user = $this->user();
        $this->publish($user, 'rules-page', 'قوانین و مقررات');

        $res = $this->getJson('/api/v1/site/search?q='.urlencode('غوانین'))->assertOk();
        $this->assertNotNull(
            collect($res->json('data'))->firstWhere('slug', 'rules-page'),
            '«غوانین» باید «قوانین» را پیدا کند.',
        );
    }

    public function test_site_search_does_not_match_unrelated_words(): void
    {
        $user = $this->user();
        $this->publish($user, 'tree-page', 'درخت سیب');

        $res = $this->getJson('/api/v1/site/search?q='.urlencode('کتاب'))->assertOk();
        $this->assertNull(collect($res->json('data'))->firstWhere('slug', 'tree-page'));
    }

    // ------------------------------------------------------------------
    // فیلترهای لیست (SQL: regex همیشه، trigram اگر نصب بود)
    // ------------------------------------------------------------------

    public function test_list_filter_matches_alef_variants(): void
    {
        $user = $this->user();
        $this->publish($user, 'arman-admin', 'زندگینامه آرمان');

        $found = Page::query();
        PersianText::whereFa($found, 'ارمان', ['title', 'slug']);

        $this->assertTrue(
            $found->where('slug', 'arman-admin')->exists(),
            'فیلتر لیست: «ارمان» باید سطرِ «آرمان» را بدهد.',
        );
    }

    public function test_list_filter_tolerates_a_typo_when_trigram_is_available(): void
    {
        if (! PersianText::trigramAvailable()) {
            $this->markTestSkipped('pg_trgm روی این دیتابیس نصب نیست؛ فقط لایهٔ regex سنجیده می‌شود.');
        }

        $user = $this->user();
        $this->publish($user, 'rules-admin', 'قوانین و مقررات');

        $found = Page::query();
        PersianText::whereFa($found, 'غوانین', ['title', 'slug']);

        $this->assertTrue(
            $found->where('slug', 'rules-admin')->exists(),
            'فیلتر لیست با trigram: «غوانین» باید «قوانین» را بدهد.',
        );
    }

    public function test_regex_pattern_escapes_user_input(): void
    {
        // نویسه‌های خاص regex نباید الگو را بشکنند یا معنای تازه بگیرند:
        // پرانتز escape می‌شود و الف داخل کلاس می‌رود (پس رشتهٔ خامِ
        // «قانون» و «(جدید)» عیناً در الگو نیستند — همین مطلوب است).
        $pattern = PersianText::regexPattern(PersianText::normalize('قانون (جدید)'));

        $this->assertStringNotContainsString('(جدید)', $pattern);
        $this->assertStringContainsString('\(', $pattern);
        $this->assertStringContainsString('[اآأإ]', $pattern);
    }
}
