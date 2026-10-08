<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** تسک ۴.۱ — DoD: چیدمان هدر/فوتر (اعتبارسنجی against رجیستری ویجت‌ها) + بلوک‌های انواع صفحه. */
class LayoutsTest extends TestCase
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

    public function test_header_returns_defaults_then_roundtrips(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $auth->getJson('/api/v1/admin/layouts/header')
            ->assertOk()
            ->assertJsonPath('data.widgets.0.type', 'logo');

        $auth->putJson('/api/v1/admin/layouts/header', [
            'widgets' => [
                ['type' => 'logo', 'settings' => ['show_title' => true]],
                ['type' => 'nav', 'settings' => []],
                ['type' => 'cta', 'settings' => ['label' => 'تماس', 'href' => '/contact']],
            ],
            'layout' => ['columns' => 3],
        ])->assertOk()->assertJsonPath('message', 'چیدمان هدر ذخیره شد.');

        $auth->getJson('/api/v1/admin/layouts/header')
            ->assertOk()
            ->assertJsonCount(3, 'data.widgets')
            ->assertJsonPath('data.layout.columns', 3);
    }

    public function test_footer_rejects_widget_from_other_area(): void
    {
        $user = $this->user();

        // nav ویجت هدر است — در فوتر نامعتبر.
        $this->actingAs($user, 'sanctum')->putJson('/api/v1/admin/layouts/footer', [
            'widgets' => [['type' => 'nav', 'settings' => []]],
        ])->assertStatus(422);
    }

    public function test_unknown_area_is_404(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/layouts/sidebar')
            ->assertNotFound();
    }

    /**
     * E66 — ناحیهٔ ویجتِ فوتر (بالاتر/پایین‌تر از ستون‌ها) ذخیره می‌شود و از
     * کرومِ عمومی هم به فرانت می‌رسد. بدونِ این، انتخابِ کاربر فقط در پنل
     * می‌ماند و سایت همان ترتیبِ قدیمی را رندر می‌کند.
     */
    public function test_footer_widget_place_roundtrips_and_reaches_public_chrome(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson('/api/v1/admin/layouts/footer', [
            'widgets' => [
                ['type' => 'about', 'settings' => [], 'place' => 'above'],
                ['type' => 'links', 'settings' => ['links' => []]],
                ['type' => 'newsletter', 'settings' => [], 'place' => 'below'],
                ['type' => 'copyright', 'settings' => []],
            ],
            'layout' => ['columns' => 2],
        ])->assertOk()
            ->assertJsonPath('data.widgets.0.place', 'above')
            ->assertJsonPath('data.widgets.1.place', null)
            ->assertJsonPath('data.widgets.2.place', 'below');

        // `grid` صریح نباید ذخیره شود: دادهٔ ویجت‌های داخلِ ستون‌ها باید عیناً
        // همان شکلِ قبلی بماند (سازگاری عقب‌رو).
        $auth->putJson('/api/v1/admin/layouts/footer', [
            'widgets' => [['type' => 'about', 'settings' => [], 'place' => 'grid']],
        ])->assertOk()->assertJsonPath('data.widgets.0.place', null);

        // و کرومِ عمومی (بدون احراز هویت) همان ناحیه را می‌دهد.
        $auth->putJson('/api/v1/admin/layouts/footer', [
            'widgets' => [
                ['type' => 'about', 'settings' => [], 'place' => 'above'],
                ['type' => 'copyright', 'settings' => []],
            ],
            'layout' => ['columns' => 1],
        ])->assertOk();

        $this->getJson('/api/v1/site/chrome')->assertOk()
            ->assertJsonPath('data.footer.widgets.0.place', 'above')
            ->assertJsonPath('data.footer.widgets.1.place', null);
    }

    /** E66 — ناحیهٔ ناشناس باید رد شود، نه بی‌صدا به «ستون‌ها» فروبیفتد. */
    public function test_footer_rejects_unknown_place(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/admin/layouts/footer', [
            'widgets' => [['type' => 'about', 'settings' => [], 'place' => 'middle']],
        ])->assertStatus(422);
    }

    public function test_page_types_list_and_blocks_roundtrip(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $types = $auth->getJson('/api/v1/admin/layouts/page-types')->assertOk()->json('data');
        $keys = collect($types)->pluck('type')->all();
        $this->assertContains('home', $keys);
        $this->assertContains('single', $keys);

        $auth->getJson('/api/v1/admin/layouts/page-type/home/blocks')
            ->assertOk()
            ->assertJsonPath('data.type', 'home');

        $auth->putJson('/api/v1/admin/layouts/page-type/home/blocks', [
            'blocks' => [
                ['type' => 'hero', 'data' => ['title' => 'سلام']],
                ['type' => 'cta', 'data' => ['label' => 'شروع', 'href' => '/start']],
            ],
        ])->assertOk()->assertJsonPath('message', 'بلوک‌های نوع صفحه ذخیره شد.');

        $auth->getJson('/api/v1/admin/layouts/page-type/home/blocks')
            ->assertOk()
            ->assertJsonCount(2, 'data.blocks');
    }

    public function test_blocks_reject_unknown_block_and_page_type(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson('/api/v1/admin/layouts/page-type/home/blocks', [
            'blocks' => [['type' => 'nope', 'data' => []]],
        ])->assertStatus(422);

        $auth->getJson('/api/v1/admin/layouts/page-type/nope/blocks')->assertNotFound();
        $auth->putJson('/api/v1/admin/layouts/page-type/nope/blocks', ['blocks' => []])->assertNotFound();
    }

    /** مشترک نصب: چیدمان ذخیره‌شده توسط یک مدیر برای همه مدیران خوانده می‌شود. */
    public function test_layouts_are_shared_across_managers(): void
    {
        $a = $this->user();
        $b = $this->user();

        $this->actingAs($a, 'sanctum')->putJson('/api/v1/admin/layouts/header', [
            'widgets' => [['type' => 'search', 'settings' => []]],
        ])->assertOk();

        $this->actingAs($b, 'sanctum')->getJson('/api/v1/admin/layouts/header')
            ->assertOk()
            ->assertJsonPath('data.widgets.0.type', 'search');
        $this->actingAs($b, 'sanctum')->putJson('/api/v1/admin/layouts/footer', [
            'widgets' => [['type' => 'links', 'settings' => []]],
        ])->assertOk();
        $this->actingAs($a, 'sanctum')->getJson('/api/v1/admin/layouts/footer')
            ->assertOk()
            ->assertJsonPath('data.widgets.0.type', 'links');
    }

    public function test_page_types_hide_blog_without_plugin_and_preserve_saved_override(): void
    {
        $auth = $this->actingAs($this->user(), 'sanctum');
        $override = [['type' => 'text', 'data' => ['body' => 'بلوک قدیمی']]];
        Setting::set('page_blocks', 'global:blog', $override);

        $types = $auth->getJson('/api/v1/admin/layouts/page-types')->assertOk()->json('data');
        $keys = collect($types)->pluck('type')->all();
        $this->assertNotContains('blog', $keys);
        $this->assertContains('home', $keys);
        $this->assertSame('core', collect($types)->firstWhere('type', 'home')['source']);
        $this->assertEquals($override, Setting::get('page_blocks', 'global:blog'));

        $auth->getJson('/api/v1/admin/layouts/page-type/blog/blocks')->assertNotFound();
        $auth->putJson('/api/v1/admin/layouts/page-type/blog/blocks', ['blocks' => []])
            ->assertNotFound();
    }

    /** تسک ۷ — type صفحه از مانیفست پلاگین فعال: تب + خواندن/ذخیره بلوک. */
    public function test_plugin_page_type_merges(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        Plugin::query()->create([
            'user_id' => $user->id, 'name' => 'پلاگین نمایشی', 'slug' => 'demo',
            'version' => '1.0.0', 'active' => true,
            'manifest' => [
                'name' => 'پلاگین نمایشی', 'slug' => 'demo', 'version' => '1.0.0',
                'page_types' => [
                    'faq' => [
                        'title' => 'سوالات پرتکرار',
                        'description' => 'صفحه FAQ پلاگین.',
                        'default_blocks' => [
                            ['type' => 'hero', 'data' => ['title' => 'سوالات پرتکرار']],
                        ],
                    ],
                    'blog' => [
                        'title' => 'وبلاگ پلاگین',
                        'description' => 'فهرست نوشته‌های پلاگین.',
                    ],
                ],
            ],
        ]);

        $types = $auth->getJson('/api/v1/admin/layouts/page-types')->assertOk()->json('data');
        $faq = collect($types)->firstWhere('type', 'faq');
        $this->assertNotNull($faq);
        $this->assertSame('سوالات پرتکرار', $faq['title']);
        $this->assertSame('plugin:demo', $faq['source']);
        $this->assertSame('plugin:demo', collect($types)->firstWhere('type', 'blog')['source']);

        $auth->getJson('/api/v1/admin/layouts/page-type/faq/blocks')->assertOk()
            ->assertJsonPath('data.blocks.0.type', 'hero');

        $auth->putJson('/api/v1/admin/layouts/page-type/faq/blocks', [
            'blocks' => [['type' => 'text', 'data' => ['body' => 'پاسخ…']]],
        ])->assertOk();

        Plugin::query()->where('slug', 'demo')->firstOrFail()->forceFill(['active' => false])->save();
        $keys = collect($auth->getJson('/api/v1/admin/layouts/page-types')->assertOk()->json('data'))
            ->pluck('type')->all();
        $this->assertNotContains('blog', $keys);
    }

    public function test_update_header_dispatches_site_chrome_revalidation(): void
    {
        Http::fake(['*' => Http::response(['message' => 'ok'], 200)]);
        config(['revalidate.url' => 'http://front.local/api/revalidate']);

        $user = $this->user();
        $this->actingAs($user, 'sanctum')->putJson('/api/v1/admin/layouts/header', [
            'widgets' => [['type' => 'logo', 'settings' => []]],
            'layout' => [],
        ])->assertOk();

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/api/revalidate')
            && in_array('site-chrome', $req['tags'], true)
            && in_array('pages', $req['tags'], true));
    }
}
