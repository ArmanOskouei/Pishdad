<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PageRevision;
use App\Models\User;
use App\Services\Settings\CachedSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * لینک‌های تودرتو (منوی کشویی nav هدر + links فوتر تا عمق ۲):
 * ذخیره تودرتو، رزولو بازگشتی زنده، ۴۲۲ عمق/سقف فرزند، حذف graceful
 * آیتم خراب تودرتو، سازگاری عقب‌رو آیتم‌های بدون children.
 */
class NestedLinksTest extends TestCase
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

    private function page(User $owner, string $slug, string $title): Page
    {
        $page = Page::query()->create([
            'user_id' => $owner->id,
            'title' => $title,
            'slug' => $slug.'-'.uniqid(),
            'status' => Page::STATUS_DRAFT,
            'blocks' => [],
        ]);
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

        return $page->fresh();
    }

    private function forgetChrome(User $owner): void
    {
        CachedSettings::forget('site_chrome', 'chrome-default-global');
    }

    public function test_nested_menu_saves_and_resolves_recursively(): void
    {
        $user = $this->user();
        $childPage = $this->page($user, 'consult', 'مشاوره');
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson('/api/v1/admin/layouts/header', [
            'widgets' => [[
                'type' => 'nav',
                'settings' => ['links' => [
                    [
                        'kind' => 'custom', 'label' => 'خدمات',
                        'children' => [
                            ['kind' => 'page', 'page_id' => $childPage->id],
                            ['kind' => 'custom', 'label' => 'پشتیبانی', 'href' => '/support'],
                        ],
                    ],
                    ['label' => 'خانه', 'href' => '/'],
                ]],
            ]],
        ])->assertOk();

        $links = $this->getJson('/api/v1/site/chrome')->assertOk()->json('data.header.widgets.0.settings.links');
        $this->assertCount(2, $links);
        $this->assertSame('custom', $links[0]['kind']);
        $this->assertSame('خدمات', $links[0]['label']);
        $this->assertCount(2, $links[0]['children']);
        $this->assertSame('page', $links[0]['children'][0]['kind']);
        $this->assertSame('مشاوره', $links[0]['children'][0]['label']);
        $this->assertSame('/consult', $links[0]['children'][0]['url']);
        $this->assertSame('پشتیبانی', $links[0]['children'][1]['label']);
        // آیتم قدیمی بدون children همچنان custom است و children ندارد.
        $this->assertSame('custom', $links[1]['kind']);
        $this->assertArrayNotHasKey('children', $links[1]);
    }

    public function test_exceeding_depth_rejected_with_persian_message(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $deep = null;
        $deep = function (int $level) use (&$deep): array {
            if ($level <= 0) {
                return [['kind' => 'custom', 'label' => 'برگ', 'href' => '/leaf']];
            }

            return [['kind' => 'custom', 'label' => "سطح {$level}", 'children' => $deep($level - 1)]];
        };

        // عمق ۳ (سطح اول + ۳ تودرتویی) غیرمجاز است.
        $res = $auth->putJson('/api/v1/admin/layouts/header', [
            'widgets' => [['type' => 'nav', 'settings' => ['links' => $deep(3)]]],
        ])->assertStatus(422);
        $this->assertStringContainsString('عمق', $res->json('errors')['widgets.0.settings.links'][0]);
    }

    public function test_children_limit_rejected_with_persian_message(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $children = [];
        for ($i = 0; $i < 9; $i++) {
            $children[] = ['kind' => 'custom', 'label' => "آیتم {$i}", 'href' => "/x{$i}"];
        }

        $res = $auth->putJson('/api/v1/admin/layouts/header', [
            'widgets' => [['type' => 'nav', 'settings' => ['links' => [
                ['kind' => 'custom', 'label' => 'والد', 'children' => $children],
            ]]]],
        ])->assertStatus(422);
        $this->assertStringContainsString('فرزند', $res->json('errors')['widgets.0.settings.links'][0]);
    }

    public function test_broken_nested_page_dropped_gracefully_parent_kept(): void
    {
        $user = $this->user();
        $childPage = $this->page($user, 'child-x', 'فرزند');
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson('/api/v1/admin/layouts/header', [
            'widgets' => [[
                'type' => 'nav',
                'settings' => ['links' => [
                    ['kind' => 'custom', 'label' => 'والد', 'children' => [
                        ['kind' => 'page', 'page_id' => $childPage->id],
                        ['kind' => 'custom', 'label' => 'سالم', 'href' => '/ok'],
                    ]],
                ]],
            ]],
        ])->assertOk();

        $childPage->forceFill(['status' => Page::STATUS_DRAFT, 'published_revision_id' => null])->save();
        $this->forgetChrome($user);

        $links = $this->getJson('/api/v1/site/chrome')->assertOk()->json('data.header.widgets.0.settings.links');
        $this->assertCount(1, $links);
        $this->assertCount(1, $links[0]['children']);
        $this->assertSame('سالم', $links[0]['children'][0]['label']);
    }

    public function test_footer_nested_links_resolve(): void
    {
        $user = $this->user();
        $childPage = $this->page($user, 'faq-x', 'سوالات');
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson('/api/v1/admin/layouts/footer', [
            'widgets' => [[
                'type' => 'links',
                'settings' => ['heading' => 'دسترسی', 'links' => [
                    ['kind' => 'custom', 'label' => 'راهنما', 'children' => [
                        ['kind' => 'page', 'page_id' => $childPage->id],
                    ]],
                ]],
            ]],
        ])->assertOk();

        $footer = $this->getJson('/api/v1/site/chrome')->assertOk()->json('data.footer');
        $this->assertSame('/faq-x', $footer['widgets'][0]['settings']['links'][0]['children'][0]['url']);
    }

    /** مشترک نصب: فرزند page منتشرشده هر مدیر معتبر است. */
    public function test_nested_page_child_accepts_shared_published_page(): void
    {
        $user = $this->user();
        $other = $this->user();
        $shared = $this->page($other, 'shared-n', 'مشترک');
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson('/api/v1/admin/layouts/header', [
            'widgets' => [['type' => 'nav', 'settings' => ['links' => [
                ['kind' => 'custom', 'label' => 'والد', 'children' => [
                    ['kind' => 'page', 'page_id' => $shared->id],
                ]],
            ]]]],
        ])->assertOk();
    }
}
