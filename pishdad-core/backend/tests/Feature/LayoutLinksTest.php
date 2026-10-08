<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageRevision;
use App\Models\Theme;
use App\Models\User;
use App\Services\Settings\CachedSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * آیتم لینک صفحه‌ای/سفارشی (nav هدر + links فوتر) + ستون‌بندی فوتر:
 * ولیدیشن فارسی، سازگاری عقب‌رو، رزولو زنده در کروم عمومی، رد graceful آیتم خراب.
 */
class LayoutLinksTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مشتری تست',
            'email' => 'u'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
    }

    private function page(User $owner, string $slug, string $title, string $status = 'draft'): Page
    {
        $page = Page::query()->create([
            'user_id' => $owner->id,
            'title' => $title,
            'slug' => $slug.'-'.uniqid(),
            'status' => Page::STATUS_DRAFT,
            'blocks' => [],
        ]);
        if ($status === Page::STATUS_PUBLISHED) {
            $rev = PageRevision::query()->create([
                'page_id' => $page->id, 'version' => 1,
                'blocks' => [], 'created_by' => $owner->id,
            ]);
            $page->forceFill([
                'status' => Page::STATUS_PUBLISHED,
                'published_revision_id' => $rev->id,
                'published_at' => now(),
                'slug' => $slug,
            ])->save();
        } else {
            $page->forceFill(['slug' => $slug])->save();
        }

        return $page->fresh();
    }

    private function forgetChrome(User $owner): void
    {
        CachedSettings::forget('site_chrome', 'chrome-default-global');
    }

    public function test_nav_page_item_resolves_live_title_and_url(): void
    {
        $user = $this->user();
        $page = $this->page($user, 'about-us', 'درباره ما', 'published');
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson('/api/v1/admin/layouts/header', [
            'widgets' => [[
                'type' => 'nav',
                'settings' => ['links' => [
                    ['kind' => 'page', 'page_id' => $page->id],
                    ['kind' => 'custom', 'label' => 'تماس', 'href' => '/contact'],
                ]],
            ]],
        ])->assertOk();

        $chrome = $this->getJson('/api/v1/site/chrome')->assertOk()->json('data.header.widgets.0.settings.links');
        $this->assertSame('page', $chrome[0]['kind']);
        $this->assertSame('درباره ما', $chrome[0]['label']);
        $this->assertSame('/about-us', $chrome[0]['href']);
        $this->assertSame('/about-us', $chrome[0]['url']);
        $this->assertSame('custom', $chrome[1]['kind']);

        // تغییر نام/اسلاگ صفحه خودکار در کروم منعکس می‌شود.
        $page->forceFill(['title' => 'درباره شرکت', 'slug' => 'about-co'])->save();
        $this->forgetChrome($user);

        $chrome = $this->getJson('/api/v1/site/chrome')->assertOk()->json('data.header.widgets.0.settings.links');
        $this->assertSame('درباره شرکت', $chrome[0]['label']);
        $this->assertSame('/about-co', $chrome[0]['href']);
    }

    public function test_old_label_href_shape_accepted_as_custom(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson('/api/v1/admin/layouts/header', [
            'widgets' => [[
                'type' => 'nav',
                'settings' => ['links' => [['label' => 'خانه', 'href' => '/']]],
            ]],
        ])->assertOk();

        $chrome = $this->getJson('/api/v1/site/chrome')->assertOk()->json('data.header.widgets.0.settings.links');
        $this->assertSame('custom', $chrome[0]['kind']);
        $this->assertSame('خانه', $chrome[0]['label']);
        $this->assertSame('/', $chrome[0]['href']);
    }

    /** آیتم page باید منتشرشده و موجود باشد (مشترک نصب: صفحه هر مدیر معتبر است). */
    public function test_page_item_requires_published_page(): void
    {
        $user = $this->user();
        $other = $this->user();
        $draft = $this->page($user, 'draft-x', 'پیش‌نویس');
        $shared = $this->page($other, 'shared-x', 'مشترک', 'published');
        $auth = $this->actingAs($user, 'sanctum');

        $putNav = fn (array $links) => $auth->putJson('/api/v1/admin/layouts/header', [
            'widgets' => [['type' => 'nav', 'settings' => ['links' => $links]]],
        ]);

        $putNav([['kind' => 'page', 'page_id' => $draft->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('widgets.0.settings.links');
        $errors = $putNav([['kind' => 'page', 'page_id' => $draft->id]])->json('errors');
        $this->assertStringContainsString('منتشرشده', $errors['widgets.0.settings.links'][0]);

        // صفحه منتشرشده مدیر دیگر معتبر است (مشترک).
        $putNav([['kind' => 'page', 'page_id' => $shared->id]])->assertOk();
        $putNav([['kind' => 'page', 'page_id' => 999999]])->assertStatus(422);
        $putNav([['kind' => 'page']])->assertStatus(422);

        // custom نامعتبر — پیام فارسی.
        $res = $putNav([['kind' => 'custom', 'label' => 'بدون نشانی']])->assertStatus(422);
        $customErrors = $res->json('errors');
        $this->assertStringContainsString('نشانی', $customErrors['widgets.0.settings.links'][0]);
        $putNav([['kind' => 'custom', 'label' => 'x', 'href' => 'javascript:alert(1)']])->assertStatus(422);
        $putNav([['kind' => 'custom', 'href' => '/x']])->assertStatus(422);
    }

    public function test_broken_page_item_skipped_gracefully_in_chrome(): void
    {
        $user = $this->user();
        $page = $this->page($user, 'temp-x', 'موقت', 'published');
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson('/api/v1/admin/layouts/header', [
            'widgets' => [[
                'type' => 'nav',
                'settings' => ['links' => [
                    ['kind' => 'page', 'page_id' => $page->id],
                    ['kind' => 'custom', 'label' => 'وبلاگ', 'href' => '/blog'],
                ]],
            ]],
        ])->assertOk();

        // صفحه از انتشار خارج شد → آیتم خراب رد می‌شود، بقیه می‌مانند (بدون خطا).
        $page->forceFill(['status' => Page::STATUS_DRAFT, 'published_revision_id' => null])->save();
        $this->forgetChrome($user);

        $links = $this->getJson('/api/v1/site/chrome')->assertOk()->json('data.header.widgets.0.settings.links');
        $this->assertCount(1, $links);
        $this->assertSame('وبلاگ', $links[0]['label']);
    }

    public function test_footer_links_resolve_and_columns_default_and_validate(): void
    {
        $user = $this->user();
        $page = $this->page($user, 'services-x', 'خدمات', 'published');
        $auth = $this->actingAs($user, 'sanctum');

        // دو ستون بدون layout.columns → ستون مؤثر = ۲.
        $auth->putJson('/api/v1/admin/layouts/footer', [
            'widgets' => [
                ['type' => 'links', 'settings' => ['heading' => 'دسترسی', 'links' => [
                    ['kind' => 'page', 'page_id' => $page->id],
                ]]],
                ['type' => 'links', 'settings' => ['heading' => 'تماس', 'links' => [
                    ['label' => 'تلفن', 'href' => 'tel:+9821'],
                ]]],
            ],
        ])->assertOk();

        $footer = $this->getJson('/api/v1/site/chrome')->assertOk()->json('data.footer');
        $this->assertSame(2, $footer['layout']['columns']);
        $this->assertSame('/services-x', $footer['widgets'][0]['settings']['links'][0]['url']);
        $this->assertSame('خدمات', $footer['widgets'][0]['settings']['links'][0]['title']);

        // ستون نامعتبر رد می‌شود — پیام فارسی.
        $res = $auth->putJson('/api/v1/admin/layouts/footer', [
            'widgets' => [['type' => 'about', 'settings' => []]],
            'layout' => ['columns' => 5],
        ])->assertStatus(422);
        $colErrors = $res->json('errors');
        $this->assertStringContainsString('۱ تا ۴', $colErrors['layout.columns'][0]);

        // ستون صریح بر تعداد واقعی غلبه می‌کند.
        $auth->putJson('/api/v1/admin/layouts/footer', [
            'widgets' => [['type' => 'about', 'settings' => []]],
            'layout' => ['columns' => 1],
        ])->assertOk();
        $this->forgetChrome($user);
        $this->getJson('/api/v1/site/chrome')->assertOk()->assertJsonPath('data.footer.layout.columns', 1);
    }

    public function test_theme_footer_defaults_apply_until_user_customizes(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        Theme::query()->create([
            'user_id' => $user->id, 'name' => 'قالب ستونی', 'slug' => 'cols',
            'version' => '1.0.0', 'active' => true,
            'manifest' => [
                'name' => 'قالب ستونی', 'slug' => 'cols', 'version' => '1.0.0',
                'footer' => [
                    'columns' => 3,
                    'widgets' => [
                        ['type' => 'links', 'settings' => ['heading' => 'ستون قالب', 'links' => [
                            ['label' => 'خانه', 'href' => '/'],
                        ]]],
                        // type ناشناس fail-soft نادیده گرفته می‌شود.
                        ['type' => 'nope', 'settings' => []],
                    ],
                ],
            ],
        ]);

        // بدون شخصی‌سازی: مبنای قالب (ستون + ویجت شناخته‌شده).
        $auth->getJson('/api/v1/admin/layouts/footer')
            ->assertOk()
            ->assertJsonPath('data.layout.columns', 3)
            ->assertJsonPath('data.widgets.0.type', 'links');
        $this->assertCount(1, $auth->getJson('/api/v1/admin/layouts/footer')->json('data.widgets'));

        $this->getJson('/api/v1/site/chrome')->assertOk()
            ->assertJsonPath('data.footer.layout.columns', 3)
            ->assertJsonPath('data.footer.widgets.0.settings.heading', 'ستون قالب');

        // شخصی‌سازی کاربر بر مبنای قالب غلبه می‌کند.
        $auth->putJson('/api/v1/admin/layouts/footer', [
            'widgets' => [['type' => 'about', 'settings' => ['text' => 'درباره ما']]],
        ])->assertOk();
        $auth->getJson('/api/v1/admin/layouts/footer')
            ->assertOk()
            ->assertJsonPath('data.widgets.0.type', 'about');
        $this->forgetChrome($user);
        // بدون ستون links: ستون مؤثر = ۱ (fallback تعداد واقعی).
        $this->getJson('/api/v1/site/chrome')->assertOk()
            ->assertJsonPath('data.footer.layout.columns', 1);
    }

    public function test_theme_without_footer_falls_back_to_core_defaults(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        Theme::query()->create([
            'user_id' => $user->id, 'name' => 'قالب ساده', 'slug' => 'plain',
            'version' => '1.0.0', 'active' => true,
            'manifest' => ['name' => 'قالب ساده', 'slug' => 'plain', 'version' => '1.0.0'],
        ]);

        $auth->getJson('/api/v1/admin/layouts/footer')
            ->assertOk()
            ->assertJsonPath('data.widgets.0.type', 'about')
            ->assertJsonPath('data.layout.columns', 3);
    }

    /** ویجت بدون links معتبر است؛ صفحه مدیر دیگر هم برای این مدیر معتبر است (مشترک). */
    public function test_links_settings_optional_and_shared(): void
    {
        $a = $this->user();
        $b = $this->user();
        $pageB = $this->page($b, 'b-only', 'صفحه ب', 'published');

        // ویجت بدون links همچنان معتبر است.
        $this->actingAs($a, 'sanctum')->putJson('/api/v1/admin/layouts/header', [
            'widgets' => [['type' => 'nav', 'settings' => []]],
        ])->assertOk();

        // صفحه مدیر دیگر برای این مدیر معتبر است.
        $this->actingAs($a, 'sanctum')->putJson('/api/v1/admin/layouts/header', [
            'widgets' => [['type' => 'nav', 'settings' => ['links' => [
                ['kind' => 'page', 'page_id' => $pageB->id],
            ]]]],
        ])->assertOk();
    }
}
