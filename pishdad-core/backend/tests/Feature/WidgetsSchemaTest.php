<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** تسک ۶ — DoD: schema ویجت‌ها از رجیستری (هسته + مانیفست پلاگین فعال). */
class WidgetsSchemaTest extends TestCase
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

    public function test_schema_lists_core_widgets_with_real_fields(): void
    {
        $schemas = $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/v1/admin/widgets/schema')->assertOk()->json('data');

        $byKey = collect($schemas)->mapWithKeys(fn (array $s) => ["{$s['area']}.{$s['type']}" => $s]);

        // ویجت logo ساده‌سازی شد: فقط show_title؛ لوگو همیشه از تنظیمات سایت.
        $logo = $byKey['header.logo'];
        $this->assertSame('لوگو', $logo['title']);
        $this->assertArrayHasKey('show_title', $logo['schema']['properties']);
        $this->assertArrayNotHasKey('media_id', $logo['schema']['properties']);
        $this->assertArrayNotHasKey('size', $logo['schema']['properties']);
        $this->assertSame('نمایش عنوان سایت', $logo['ui']['labels']['show_title'] ?? null);
        $this->assertSame('core', $logo['source']);

        // فیلدها واقعی‌اند (همان که رندرر عمومی می‌خواند): nav.links، search.placeholder، newsletter.text.
        $this->assertArrayHasKey('links', $byKey['header.nav']['schema']['properties']);
        $this->assertArrayHasKey('placeholder', $byKey['header.search']['schema']['properties']);
        $this->assertArrayHasKey('label', $byKey['header.cta']['schema']['properties']);
        $this->assertArrayHasKey('text', $byKey['footer.about']['schema']['properties']);
        $this->assertArrayHasKey('heading', $byKey['footer.links']['schema']['properties']);
        $this->assertArrayHasKey('text', $byKey['footer.copyright']['schema']['properties']);
    }

    public function test_plugin_widget_merges_and_is_accepted_in_layout(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        Plugin::query()->create([
            'user_id' => $user->id, 'name' => 'پلاگین نمایشی', 'slug' => 'demo',
            'version' => '1.0.0', 'active' => true,
            'manifest' => [
                'name' => 'پلاگین نمایشی', 'slug' => 'demo', 'version' => '1.0.0',
                'widgets' => [
                    'header' => [
                        'promo' => [
                            'title' => 'بنر تخفیف',
                            'description' => 'نوار promoc.',
                            'schema' => ['type' => 'object', 'properties' => [
                                'text' => ['type' => 'string', 'maxLength' => 120],
                            ]],
                        ],
                    ],
                ],
            ],
        ]);

        $schemas = $auth->getJson('/api/v1/admin/widgets/schema')->assertOk()->json('data');
        $promo = collect($schemas)->firstWhere('type', 'promo');
        $this->assertNotNull($promo);
        $this->assertSame('header', $promo['area']);
        $this->assertSame('بنر تخفیف', $promo['title']);
        $this->assertSame('plugin:demo', $promo['source']);

        // چیدمان همان type پلاگینی را می‌پذیرد (نه فقط هسته).
        $auth->putJson('/api/v1/admin/layouts/header', [
            'widgets' => [['type' => 'promo', 'settings' => ['text' => 'فروش ویژه']]],
        ])->assertOk();

        // type ناشناس همچنان رد می‌شود.
        $auth->putJson('/api/v1/admin/layouts/header', [
            'widgets' => [['type' => 'nope', 'settings' => []]],
        ])->assertStatus(422);
    }
}
