<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * جستجوی عمومی سایت: تطابق عنوان/متن، snippet هوشمند، q کوتاه ۴۲۲،
 * throttle سفت، بدون درز draft، نرمال‌سازی فارسی (ي/ك + نیم‌فاصله).
 */
class SiteSearchTest extends TestCase
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

    private function publish(User $owner, string $slug, string $title, array $blocks = [], ?array $meta = null): Page
    {
        $page = Page::query()->create([
            'user_id' => $owner->id, 'title' => $title, 'slug' => $slug,
            'status' => 'draft', 'blocks' => $blocks, 'meta' => $meta,
        ]);
        $rev = PageRevision::query()->create([
            'page_id' => $page->id, 'version' => 1,
            'blocks' => $blocks, 'meta' => $meta, 'created_by' => $owner->id,
        ]);
        $page->forceFill([
            'status' => Page::STATUS_PUBLISHED,
            'published_revision_id' => $rev->id,
            'published_at' => now(),
        ])->save();

        return $page->fresh();
    }

    public function test_title_match_returns_page_shape(): void
    {
        $user = $this->user();
        $this->publish($user, 'about-search', 'درباره شرکت ما');

        $res = $this->getJson('/api/v1/site/search?q='.urlencode('شرکت').'&per_page=10')->assertOk();

        $item = collect($res->json('data'))->firstWhere('slug', 'about-search');
        $this->assertNotNull($item);
        $this->assertSame('درباره شرکت ما', $item['title']);
        $this->assertSame('page', $item['type']);
        $this->assertNotEmpty($item['snippet']);
        $this->assertNotEmpty($item['updated_at']);
    }

    public function test_body_match_and_snippet_around_match(): void
    {
        $user = $this->user();
        $long = str_repeat('متن مقدمه. ', 20).'گارانتی دوساله محصول'.str_repeat(' متن ادامه. ', 20);
        $this->publish($user, 'terms-search', 'شرایط فروش', [
            ['type' => 'text', 'data' => ['body' => $long]],
        ]);

        $res = $this->getJson('/api/v1/site/search?q='.urlencode('گارانتی'))->assertOk();

        $item = collect($res->json('data'))->firstWhere('slug', 'terms-search');
        $this->assertNotNull($item);
        $this->assertStringContainsString('گارانتی', $item['snippet']);
        $this->assertLessThanOrEqual(165, mb_strlen($item['snippet']));
    }

    public function test_short_query_rejected_with_persian_message(): void
    {
        $res = $this->getJson('/api/v1/site/search?q='.urlencode('ا'))->assertStatus(422);
        $this->assertStringContainsString('۲ نویسه', $res->json('errors')['q'][0] ?? $res->json('message'));
    }

    public function test_draft_never_leaks(): void
    {
        $user = $this->user();
        Page::query()->create([
            'user_id' => $user->id, 'title' => 'پیش‌نویس محرمانه رازگونه', 'slug' => 'secret-draft-unique',
            'status' => 'draft',
            'blocks' => [['type' => 'text', 'data' => ['body' => 'رازگونه محرمانه']]],
        ]);

        $res = $this->getJson('/api/v1/site/search?q='.urlencode('رازگونه'))->assertOk();
        $this->assertCount(0, $res->json('data'));
    }

    public function test_persian_normalization_arabic_yeh_and_half_space(): void
    {
        $user = $this->user();
        $this->publish($user, 'norm-search', 'راهنمای می‌شود', [
            ['type' => 'text', 'data' => ['body' => 'نمایندگی یخچال در تهران']],
        ]);

        // ي عربی در کوئری باید ی فارسی متن را پیدا کند.
        $this->getJson('/api/v1/site/search?q='.urlencode('نماېندگی'))->assertOk();
        $res = $this->getJson('/api/v1/site/search?q='.urlencode('نمایندگی'))->assertOk();
        $this->assertNotNull(collect($res->json('data'))->firstWhere('slug', 'norm-search'));

        // «می شود» (با فاصله) باید «می‌شود» (با نیم‌فاصله) را پیدا کند.
        $res = $this->getJson('/api/v1/site/search?q='.urlencode('می شود'))->assertOk();
        $this->assertNotNull(collect($res->json('data'))->firstWhere('slug', 'norm-search'));
    }

    public function test_throttle_blocks_excessive_requests(): void
    {
        $last = null;
        for ($i = 0; $i < 35; $i++) {
            $last = $this->getJson('/api/v1/site/search?q='.urlencode('تست'));
        }

        $last->assertStatus(429);
    }
}
