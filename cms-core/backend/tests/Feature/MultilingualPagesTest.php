<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageRevision;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * F4.5 — محتوای چندزبانهٔ سایت، گزینهٔ (الف): هر زبان ردیفِ صفحهٔ خودش.
 *
 * قراردادها:
 *  - `locale` روی `pages` پیش‌فرض `fa` است ⇒ نبود پارامتر = رفتار قبلی (صفر رگرسیون).
 *  - یکتاییِ slug per-locale است: `fa/about` و `en/about` هم‌زیستی دارند.
 *  - مسیرهای عمومی `?locale=` را می‌خوانند و فقط ردیفِ همان زبان را می‌دهند.
 */
class MultilingualPagesTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'name' => 'Owner', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Owner!1234'), 'role' => 'admin',
        ]);
    }

    private function page(User $owner, string $slug, string $locale, string $body = 'سلام'): Page
    {
        $page = Page::query()->create([
            'user_id' => $owner->id,
            'title' => 'صفحه '.$slug.' '.$locale,
            'slug' => $slug,
            'locale' => $locale,
            'status' => Page::STATUS_DRAFT,
            'blocks' => [['type' => 'text', 'data' => ['body' => '<p>'.$body.'</p>']]],
        ]);
        $rev = PageRevision::query()->create([
            'page_id' => $page->id, 'version' => 1,
            'blocks' => $page->blocks, 'created_by' => $owner->id,
        ]);
        $page->forceFill([
            'status' => Page::STATUS_PUBLISHED,
            'published_revision_id' => $rev->id,
            'published_at' => now(),
        ])->save();

        return $page->fresh();
    }

    public function test_default_locale_is_fa_and_shared_slug_is_per_locale(): void
    {
        $owner = $this->user();
        $fa = $this->page($owner, 'about', 'fa', 'درباره ما فارسی');
        $en = $this->page($owner, 'about', 'en', 'About us English');

        // هر دو ردیف با اسلاگ یکسان هم‌زیستی دارند (unique(slug, locale)).
        $this->assertDatabaseHas('pages', ['slug' => 'about', 'locale' => 'fa']);
        $this->assertDatabaseHas('pages', ['slug' => 'about', 'locale' => 'en']);
        $this->assertSame(Page::LOCALE_DEFAULT, $fa->locale);

        // بدون پارامتر ⇒ fa.
        $this->getJson('/api/v1/site/pages/about')
            ->assertOk()
            ->assertJsonPath('data.title', $fa->title);

        // با locale=en ⇒ ردیفِ انگلیسی.
        $this->getJson('/api/v1/site/pages/about?locale=en')
            ->assertOk()
            ->assertJsonPath('data.title', $en->title);
    }

    public function test_index_and_homepage_are_locale_scoped(): void
    {
        $owner = $this->user();
        $this->page($owner, 'home', 'fa', 'خانه');
        $this->page($owner, 'home', 'en', 'Home');

        // فهرست: fa فقط صفحهٔ fa را می‌دهد.
        $faList = $this->getJson('/api/v1/site/pages')->assertOk()->json('data');
        $this->assertCount(1, $faList);

        $enList = $this->getJson('/api/v1/site/pages?locale=en')->assertOk()->json('data');
        $this->assertCount(1, $enList);

        // صفحه خانه per-locale.
        $this->getJson('/api/v1/site/homepage')->assertOk()->assertJsonPath('data.slug', 'home');
        $this->getJson('/api/v1/site/homepage?locale=en')->assertOk()->assertJsonPath('data.slug', 'home');
    }

    public function test_unknown_locale_falls_back_to_default(): void
    {
        $owner = $this->user();
        $fa = $this->page($owner, 'terms', 'fa');

        // `de` در فهرست نیست ⇒ fa (fail-safe، نه ۴۰۴).
        $this->getJson('/api/v1/site/pages/terms?locale=de')
            ->assertOk()
            ->assertJsonPath('data.title', $fa->title);
    }

    public function test_admin_allows_same_slug_across_locales_but_rejects_within_locale(): void
    {
        $this->seed(RolesPermissionsSeeder::class);
        $owner = $this->user();
        $owner->givePermissionTo('pages.edit');
        $auth = $this->actingAs($owner, 'sanctum');

        $auth->postJson('/api/v1/admin/pages', [
            'title' => 'Contact', 'slug' => 'contact', 'locale' => 'fa',
        ])->assertCreated();

        // همان اسلاگ در زبان دیگر ⇒ مجاز.
        $auth->postJson('/api/v1/admin/pages', [
            'title' => 'Contact EN', 'slug' => 'contact', 'locale' => 'en',
        ])->assertCreated();

        // همان اسلاگ در همان زبان ⇒ رد.
        $auth->postJson('/api/v1/admin/pages', [
            'title' => 'Contact dup', 'slug' => 'contact', 'locale' => 'fa',
        ])->assertStatus(422)->assertJsonValidationErrors('slug');
    }
}
